<?php
/**
 * Kayıt okuma ve iptal: /api/formlar/{uniqid}/basvurular, /api/basvurular/{id}, /api/izin-durumu, iptal
 *
 * İptal edilen kayıtta onay kolonları DEĞİŞTİRİLMEZ (kanıt JSON'u onlardan üretilir; değişirse kanıt
 * doğrulaması "sonradan değiştirilmiş" der). İptal durumu dogrulama_durumu = 'iptal' ve
 * Form_Basvuru_Iptalleri kaydıyla tutulur; API'nin döndürdüğü "izinler" iptalde açık rıza / KVKK / ETK
 * için 0'dır, ilk verilen hali "verilen_izinler"de görünür.
 */

require_once __DIR__ . '/Api.php';
require_once __DIR__ . '/Formlar.php';
require_once __DIR__ . '/../FormBasvuru.php';
require_once __DIR__ . '/../Webhook.php';
require_once __DIR__ . '/../../admin/includes/TelefonHelper.php';

class ApiBasvurular
{
    /** İptalde geri alınan izinler (aydınlatma bilgilendirmedir, geri alınmaz) */
    const IPTALDE_SIFIRLANAN = ['acik_riza', 'kvkk', 'etk'];

    const OZET_KOLONLAR = "
        b.form_basvuru_id, b.form_id, b.form_basvuru_ad, b.form_basvuru_soyad, b.form_basvuru_telefon, b.form_basvuru_eposta,
        b.form_basvuru_aydinlatma_onay, b.form_basvuru_acik_riza_onay, b.form_basvuru_kvkk_onay,
        b.form_basvuru_etk_onay, b.form_basvuru_gps_onay, b.form_basvuru_api_anahtar_id,
        CONVERT(VARCHAR(19), b.form_basvuru_tarihi, 120) AS tarih,
        CONVERT(VARCHAR(19), b.form_basvuru_sms_dogrulama_tarihi, 120) AS sms_tarih,
        d.form_dogrulama_durum_kod AS durum_kod, d.form_dogrulama_durum_adi AS durum_adi,
        d.form_dogrulama_durum_gecerli AS durum_gecerli,
        f.form_uniqid, f.form_adi";

    /** GET /api/formlar/{uniqid}/basvurular?baslangic=YYYY-AA-GG&bitis=YYYY-AA-GG&sayfa=1&limit=100 */
    public static function liste(Api $api, array $p)
    {
        $form = ApiFormlar::formBul($api, $p['uniqid']);
        $db = Database::getInstance();

        $hatalar = [];
        $kosul = 'b.form_id = ? AND b.firma_id = ? AND b.form_basvuru_durum = 1';
        $param = [(int)$form['form_id'], $api->firmaId()];
        foreach (['baslangic' => '>=', 'bitis' => '<'] as $ad => $op) {
            $t = $api->sorgu($ad);
            if ($t === null) {
                continue;
            }
            $d = DateTime::createFromFormat('!Y-m-d', $t);
            if (!$d || $d->format('Y-m-d') !== $t) {
                $hatalar[$ad] = 'Biçim: YYYY-AA-GG';
                continue;
            }
            $kosul .= $ad === 'bitis' ? " AND b.form_basvuru_tarihi < DATEADD(DAY, 1, ?)" : " AND b.form_basvuru_tarihi >= ?";
            $param[] = $t;
        }
        $maks = Ayarlar::sayi('api_liste_limit');
        $limit = (int)$api->sorgu('limit', '100');
        $sayfa = (int)$api->sorgu('sayfa', '1');
        if ($limit < 1 || $limit > $maks) {
            $hatalar['limit'] = "1 ile $maks arasında olmalı.";
        }
        if ($sayfa < 1) {
            $hatalar['sayfa'] = '1 veya büyük olmalı.';
        }
        if ($hatalar) {
            throw new ApiHata('ALAN_HATASI', 'Sorgu parametrelerinde hata var.', ['alanlar' => $hatalar]);
        }

        $toplam = (int)($db->fetchOne("SELECT COUNT(*) AS n FROM dbo.Form_Basvurulari b WHERE $kosul", $param)['n'] ?? 0);

        // 1. aşama: sayfanın ID'leri  2. aşama: ayrıntılar yalnız bu satırlara
        $satirlar = $db->fetchAll("
            WITH Sayfa AS (
                SELECT b.form_basvuru_id
                FROM dbo.Form_Basvurulari b
                WHERE $kosul
                ORDER BY b.form_basvuru_id DESC
                OFFSET ? ROWS FETCH NEXT ? ROWS ONLY
            )
            SELECT " . self::OZET_KOLONLAR . ", i.iptal_id
            FROM Sayfa s
            INNER JOIN dbo.Form_Basvurulari b ON b.form_basvuru_id = s.form_basvuru_id
            INNER JOIN dbo.Formlar f ON f.form_id = b.form_id
            LEFT JOIN dbo.tanim_FormDogrulamaDurumlari d ON d.form_dogrulama_durum_id = b.form_basvuru_dogrulama_durumu
            LEFT JOIN dbo.Form_Basvuru_Iptalleri i ON i.iptal_form_basvuru_id = b.form_basvuru_id AND i.Durum = 1
            ORDER BY b.form_basvuru_id DESC
        ", array_merge($param, [($sayfa - 1) * $limit, $limit])) ?: [];

        return [
            'kayitlar'  => array_map([self::class, 'ozet'], $satirlar),
            'sayfalama' => ['sayfa' => $sayfa, 'limit' => $limit, 'toplam' => $toplam, 'sayfa_sayisi' => (int)ceil($toplam / $limit)],
        ];
    }

    /** GET /api/basvurular/{id} */
    public static function detay(Api $api, array $p)
    {
        $api->logBasvuru((int)$p['id']);
        return self::detayGetir($api, (int)$p['id']);
    }

    /** GET /api/izin-durumu?telefon=05xx: her formdaki güncel kayıt (yenilenenler hariç) */
    public static function izinDurumu(Api $api, array $p)
    {
        $tel = telefonNormalizeEtGuvenli((string)$api->sorgu('telefon', ''));
        if (!$tel) {
            throw new ApiHata('ALAN_HATASI', 'Geçerli bir cep telefonu gönderilmedi.', ['alanlar' => ['telefon' => 'Örn. 05321234567']]);
        }
        $satirlar = Database::getInstance()->fetchAll("
            WITH Guncel AS (
                SELECT b.form_basvuru_id,
                       ROW_NUMBER() OVER (PARTITION BY b.form_id ORDER BY b.form_basvuru_id DESC) AS sira
                FROM dbo.Form_Basvurulari b
                INNER JOIN dbo.tanim_FormDogrulamaDurumlari d ON d.form_dogrulama_durum_id = b.form_basvuru_dogrulama_durumu
                LEFT JOIN dbo.Form_Basvuru_Iptalleri i ON i.iptal_form_basvuru_id = b.form_basvuru_id AND i.Durum = 1
                WHERE b.firma_id = ? AND b.form_basvuru_telefon = ? AND b.form_basvuru_durum = 1
                  AND (d.form_dogrulama_durum_gecerli = 1 OR i.iptal_id IS NOT NULL)
            )
            SELECT " . self::OZET_KOLONLAR . ", i.iptal_id
            FROM Guncel g
            INNER JOIN dbo.Form_Basvurulari b ON b.form_basvuru_id = g.form_basvuru_id
            INNER JOIN dbo.Formlar f ON f.form_id = b.form_id
            LEFT JOIN dbo.tanim_FormDogrulamaDurumlari d ON d.form_dogrulama_durum_id = b.form_basvuru_dogrulama_durumu
            LEFT JOIN dbo.Form_Basvuru_Iptalleri i ON i.iptal_form_basvuru_id = b.form_basvuru_id AND i.Durum = 1
            WHERE g.sira = 1
            ORDER BY f.form_adi
        ", [$api->firmaId(), $tel]) ?: [];

        $kayitlar = array_map([self::class, 'ozet'], $satirlar);
        $ozet = [];
        foreach (['aydinlatma', 'acik_riza', 'kvkk', 'etk', 'gps'] as $izin) {
            $ozet[$izin] = (bool)array_filter($kayitlar, fn($k) => $k['izinler'][$izin] === 1);
        }
        return ['telefon' => $tel, 'izinler' => $ozet, 'formlar' => $kayitlar];
    }

    /** POST /api/basvurular/{id}/iptal  {"neden": "...", "musteri": {"ip": "..."}} */
    public static function iptal(Api $api, array $p)
    {
        $id = (int)$p['id'];
        $api->logBasvuru($id);
        $govde = $api->govde();
        $neden = trim(preg_replace('/\s+/u', ' ', (string)($govde['neden'] ?? '')));
        $musteriIp = is_array($govde['musteri'] ?? null) ? trim((string)($govde['musteri']['ip'] ?? '')) : '';
        $hatalar = [];
        if ($neden === '' || mb_strlen($neden) > 500) {
            $hatalar['neden'] = 'Zorunlu, en fazla 500 karakter.';
        }
        if ($musteriIp !== '' && !filter_var($musteriIp, FILTER_VALIDATE_IP)) {
            $hatalar['musteri.ip'] = 'Geçerli bir IP adresi değil.';
        }
        if ($hatalar) {
            throw new ApiHata('ALAN_HATASI', 'Gönderilen bilgilerde hata var.', ['alanlar' => $hatalar]);
        }

        $db = Database::getInstance();
        $conn = $db->getConnection();
        sqlsrv_begin_transaction($conn);
        try {
            $b = $db->fetchOne("
                SELECT b.form_basvuru_id, d.form_dogrulama_durum_gecerli AS gecerli, d.form_dogrulama_durum_adi AS durum_adi
                FROM dbo.Form_Basvurulari b WITH (UPDLOCK, HOLDLOCK)
                LEFT JOIN dbo.tanim_FormDogrulamaDurumlari d ON d.form_dogrulama_durum_id = b.form_basvuru_dogrulama_durumu
                WHERE b.form_basvuru_id = ? AND b.firma_id = ? AND b.form_basvuru_durum = 1
            ", [$id, $api->firmaId()]);
            if (!$b) {
                throw new ApiHata('BASVURU_BULUNAMADI', 'Başvuru bulunamadı.');
            }
            if ((int)$b['gecerli'] !== 1) {
                throw new ApiHata('IPTAL_EDILEMEZ', 'Yalnız geçerli kayıt iptal edilebilir; bu kaydın durumu: ' . $b['durum_adi'] . '.');
            }
            $iptalDurum = $db->fetchOne("
                SELECT form_dogrulama_durum_id FROM dbo.tanim_FormDogrulamaDurumlari
                WHERE form_dogrulama_durum_kod = 'iptal' AND form_dogrulama_durum_durum = 1
            ");
            if (!$iptalDurum) {
                throw new Exception("tanim_FormDogrulamaDurumlari içinde 'iptal' tanımı yok.");
            }

            $ok = $db->execute("
                INSERT INTO dbo.Form_Basvuru_Iptalleri (iptal_form_basvuru_id, iptal_neden, iptal_api_anahtar_id, iptal_ip, iptal_musteri_ip)
                VALUES (?, ?, ?, ?, ?)
            ", [$id, $neden, $api->anahtarId(), Api::istemciIp(), $musteriIp !== '' ? $musteriIp : null])
                && $db->execute("
                UPDATE dbo.Form_Basvurulari
                SET form_basvuru_dogrulama_durumu = ?, form_basvuru_guncelleme_tarihi = GETDATE()
                WHERE form_basvuru_id = ?
            ", [(int)$iptalDurum['form_dogrulama_durum_id'], $id]);
            if (!$ok) {
                throw new Exception('İptal kaydı yazılamadı (#' . $id . ').');
            }
            sqlsrv_commit($conn);
        } catch (Throwable $e) {
            sqlsrv_rollback($conn);
            throw $e;
        }
        $detay = self::detayGetir($api, $id);
        Webhook::olay('basvuru.iptal_edildi', $api->firmaId(), $id,
            ['form' => $detay['form']['uniqid'], 'durum' => 'iptal', 'kaynak' => 'api']);

        return $detay;
    }

    /* ------------------------------------------------------------------- */

    private static function detayGetir(Api $api, $id)
    {
        $db = Database::getInstance();
        $b = $db->fetchOne("
            SELECT " . self::OZET_KOLONLAR . ", i.iptal_id,
                   b.form_basvuru_ip_adresi, b.form_basvuru_kanit_hash, b.form_basvuru_kanit_surum,
                   i.iptal_neden, i.iptal_api_anahtar_id, CONVERT(VARCHAR(19), i.OlusturmaTarihi, 120) AS iptal_tarih
            FROM dbo.Form_Basvurulari b
            INNER JOIN dbo.Formlar f ON f.form_id = b.form_id
            LEFT JOIN dbo.tanim_FormDogrulamaDurumlari d ON d.form_dogrulama_durum_id = b.form_basvuru_dogrulama_durumu
            LEFT JOIN dbo.Form_Basvuru_Iptalleri i ON i.iptal_form_basvuru_id = b.form_basvuru_id AND i.Durum = 1
            WHERE b.form_basvuru_id = ? AND b.firma_id = ? AND b.form_basvuru_durum = 1
        ", [(int)$id, $api->firmaId()]);
        if (!$b) {
            throw new ApiHata('BASVURU_BULUNAMADI', 'Başvuru bulunamadı.');
        }

        $alanlar = $db->fetchAll("
            SELECT t.form_alan_kod, COALESCE(NULLIF(LTRIM(RTRIM(fa.form_alani_etiket)), ''), t.form_alan_adi) AS etiket, v.form_basvuru_deger
            FROM dbo.Form_Basvuru_Degerleri v
            INNER JOIN dbo.tanim_FormAlanlari t ON t.form_alan_id = v.form_alan_id
            LEFT JOIN dbo.Form_Alanlari fa ON fa.form_alan_id = v.form_alan_id AND fa.form_id = ?
            WHERE v.form_basvuru_id = ?
            ORDER BY fa.form_alani_sira_no, t.form_alan_sira_no
        ", [(int)$b['form_id'], (int)$id]) ?: [];

        $onaylar = $db->fetchAll("
            SELECT m.form_onay_metin_tur, m.form_onay_metin_baslik, m.form_onay_metin_hash, o.form_basvuru_onay_kabul
            FROM dbo.Form_Basvuru_Onaylari o
            INNER JOIN dbo.Form_Onay_Metinleri m ON m.form_onay_metin_id = o.form_onay_metin_id
            WHERE o.form_basvuru_id = ?
            ORDER BY m.form_onay_metin_tur
        ", [(int)$id]) ?: [];

        $sonuc = self::ozet($b);
        $sonuc['ip'] = $b['form_basvuru_ip_adresi'];
        $sonuc['alanlar'] = array_map(fn($a) => ['kod' => $a['form_alan_kod'], 'etiket' => $a['etiket'], 'deger' => $a['form_basvuru_deger']], $alanlar);
        $sonuc['onaylar'] = array_map(fn($o) => [
            'anahtar' => $o['form_onay_metin_tur'],
            'baslik'  => $o['form_onay_metin_baslik'],
            'kabul'   => (int)$o['form_basvuru_onay_kabul'] === 1,
            'hash'    => trim((string)$o['form_onay_metin_hash']),
        ], $onaylar);
        $sonuc['kanit'] = $b['form_basvuru_kanit_hash'] !== null
            ? ['surum' => (int)$b['form_basvuru_kanit_surum'], 'hash' => trim((string)$b['form_basvuru_kanit_hash'])]
            : null;
        $sonuc['iptal'] = $b['iptal_id'] !== null ? [
            'tarih'  => $b['iptal_tarih'],
            'neden'  => $b['iptal_neden'],
            'kaynak' => $b['iptal_api_anahtar_id'] !== null ? 'api' : 'panel',
        ] : null;
        return $sonuc;
    }

    /** Liste / izin durumu / detay için ortak özet */
    public static function ozet(array $b)
    {
        $bit = fn($v) => $v === null ? null : (int)$v;
        $verilen = [
            'aydinlatma' => $bit($b['form_basvuru_aydinlatma_onay']),
            'acik_riza'  => $bit($b['form_basvuru_acik_riza_onay']),
            'kvkk'       => $bit($b['form_basvuru_kvkk_onay']),
            'etk'        => $bit($b['form_basvuru_etk_onay']),
            'gps'        => $bit($b['form_basvuru_gps_onay']),
        ];
        $iptal = $b['iptal_id'] !== null;
        $guncel = $verilen;
        if ($iptal) {
            foreach (self::IPTALDE_SIFIRLANAN as $k) {
                if ($guncel[$k] !== null) {
                    $guncel[$k] = 0;
                }
            }
        }

        if ($b['form_basvuru_api_anahtar_id'] !== null) {
            $kaynak = 'api';
        } elseif ($b['durum_kod'] === 'excel') {
            $kaynak = 'excel';
        } else {
            $kaynak = 'form';
        }

        $o = [
            'id'                   => (int)$b['form_basvuru_id'],
            'form'                 => ['uniqid' => $b['form_uniqid'], 'adi' => $b['form_adi']],
            'durum'                => $b['durum_kod'],
            'durum_adi'            => $b['durum_adi'],
            'gecerli'              => (int)$b['durum_gecerli'] === 1,
            'kaynak'               => $kaynak,
            'ad'                   => $b['form_basvuru_ad'],
            'soyad'                => $b['form_basvuru_soyad'],
            'telefon'              => $b['form_basvuru_telefon'],
            'eposta'               => $b['form_basvuru_eposta'],
            'izinler'              => $guncel,
            'tarih'                => $b['tarih'],
            'sms_dogrulama_tarihi' => $b['sms_tarih'],
        ];
        if ($iptal) {
            $o['verilen_izinler'] = $verilen;
        }
        return $o;
    }
}
