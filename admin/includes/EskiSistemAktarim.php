<?php
/**
 * Eski Sistem Aktarım Servisi
 *
 * Eski sistemden (portal.ornek-kvkk.com → MariaDB portal_db) yeni sisteme (MSSQL ornek_kvkk_DB)
 * firma, kullanıcı, form tanımı ve doldurulmuş form (başvuru / onaylayan) kayıtlarını seçimli taşır.
 *
 * - Eski DB'ye yalnız SELECT atılır. Eski tablolarda PRIMARY dışında indeks yoktur; bu yüzden
 *   sorgular PK aralığı ve küçük IN listeleriyle sınırlıdır, büyük tablolar JOIN edilmez.
 * - Her taşınan kayıt Aktarim_Kayitlari'na yazılır; aynı kayıt ikinci kez taşınmaz.
 * - Yalnız başarılı sonuçlar (eklendi / eslesti) kalıcı yazılır; atlandı / hata sadece raporlanır,
 *   böylece eksik bilgi tamamlanınca kayıt yeniden denenebilir.
 */

require_once __DIR__ . '/TelefonHelper.php';

class EskiSistemAktarim
{
    const KAYNAK_DB = 'portal_db';
    const BASVURU_PARTI = 1000;

    /** Eski forms tablosundaki "<önek>_form / <önek>_zorunlu" kolonları → tanim_FormAlanlari.form_alan_kod */
    const FORM_ALAN_ESLEME = [
        'ad'                    => 'ad',
        'soyad'                 => 'soyad',
        'firmaadi'              => 'firma_adi',
        'cinsiyet'              => 'cinsiyet',
        'adres'                 => 'adres',
        'ceptelefonu'           => 'cep_telefonu',
        'eposta'                => 'eposta',
        'tckimlik'              => 'tc_kimlik',
        'vergino'               => 'vergi_no',
        'referans'              => 'referans',
        'dogumtarihi'           => 'dogum_tarihi',
        'il'                    => 'il',
        'ilce'                  => 'ilce',
        'isguvenligi'           => 'is_guvenligi',
        'hastaonam'             => 'hasta_onam',
        'gelis_nedeni'          => 'gelis_nedeni',
        'gorusulecek_departman' => 'gorusulecek_departman',
        'ek1'                   => 'ek_alan_1',
        'ek2'                   => 'ek_alan_2',
        'ek3'                   => 'ek_alan_3',
    ];

    /** Eski form_users kolonları → alan kodu (değer olduğu gibi taşınır) */
    const BASVURU_ALAN_ESLEME = [
        'fu_ad'          => 'ad',
        'fu_soyad'       => 'soyad',
        'fu_firmaadi'    => 'firma_adi',
        'fu_adres'       => 'adres',
        'fu_ceptelefonu' => 'cep_telefonu',
        'fu_eposta'      => 'eposta',
        'fu_tckimlik'    => 'tc_kimlik',
        'fu_vergino'     => 'vergi_no',
        'fu_referans'    => 'referans',
        'fu_dogumtarihi' => 'dogum_tarihi',
        'fu_isGuvenligi' => 'is_guvenligi',
        'fu_hastaOnam'   => 'hasta_onam',
        'fu_ek1'         => 'ek_alan_1',
        'fu_ek2'         => 'ek_alan_2',
        'fu_ek3'         => 'ek_alan_3',
    ];

    /**
     * Eski sistemde veritabanında değil kaynak kodda sabit tanımlı seçenekler
     * (portal.ornek-kvkk.com/application/views/kvkk_izin/form.php). Kodlar metne çevrilerek taşınır.
     */
    const ESKI_CINSIYET     = [1 => 'Erkek', 2 => 'Kadın'];
    const ESKI_GELIS_NEDENI = [1 => 'İptal', 2 => 'Değişim', 3 => 'Satış'];
    const ESKI_IPTAL_NEDENI = [1 => 'Bağlantı Yapılamadı', 2 => 'Fiyat Pahalı', 3 => 'Taahüt Bitti',
                               4 => 'Hatalı Satış', 5 => 'Taşınma', 6 => 'Boşanma'];

    /** Eski yetki (auths.auth_id) → önerilen departman kodu. Bilgi İşlem (tam yetki) otomatik önerilmez. */
    const YETKI_DEPARTMAN = [1 => 'yonetici', 2 => 'yonetici', 3 => 'personel'];

    /** @var Database */
    private $db;
    /** @var mysqli */
    private $my;
    private $kullaniciId;
    private $sonuclar = null;   // kod => [id, adi, renk]
    private $alanlar = null;    // kod => [id, sabit]
    private $diller = null;     // kod => id
    private $smsPanelleri = null;
    private $hedefFirmalar = null;
    private $epostaAlanAdi = null;

    public function __construct(Database $db, $kullaniciId)
    {
        $this->db = $db;
        $this->kullaniciId = (int)$kullaniciId;

        $config = require __DIR__ . '/../../config/database.php';
        $p = $config['connections']['portal'] ?? null;
        if (!$p) {
            throw new Exception('Eski sistem bağlantısı (config/database.php → portal) tanımlı değil.');
        }

        mysqli_report(MYSQLI_REPORT_OFF);
        $this->my = @new mysqli($p['host'], $p['username'], $p['password'], $p['database'], (int)($p['port'] ?? 3306));
        if ($this->my->connect_errno) {
            throw new Exception('Eski sistem veritabanına bağlanılamadı: ' . $this->my->connect_error);
        }
        $this->my->set_charset($p['charset'] ?? 'utf8mb4');
    }

    /* =========================================================================
     * Yardımcılar
     * ========================================================================= */

    /** Eski DB'de parametreli SELECT */
    private function my($sql, array $params = [])
    {
        $stmt = $this->my->prepare($sql);
        if (!$stmt) {
            throw new Exception('Eski sistem sorgu hatası: ' . $this->my->error);
        }
        if ($params) {
            $types = '';
            foreach ($params as $v) {
                $types .= is_int($v) ? 'i' : 's';
            }
            $stmt->bind_param($types, ...$params);
        }
        if (!$stmt->execute()) {
            throw new Exception('Eski sistem sorgu hatası: ' . $stmt->error);
        }
        $res = $stmt->get_result();
        $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();
        return $rows;
    }

    /** int listesini güvenli IN ifadesine çevirir */
    private static function inListe(array $idler)
    {
        $idler = array_values(array_unique(array_filter(array_map('intval', $idler), fn($v) => $v > 0)));
        return $idler ? implode(',', $idler) : '0';
    }

    private static function metin($deger, $uzunluk)
    {
        $deger = trim((string)$deger);
        return $deger === '' ? null : mb_substr($deger, 0, $uzunluk);
    }

    private static function tarih($deger, $sadeceGun = false)
    {
        $deger = trim((string)$deger);
        if ($deger === '' || strpos($deger, '0000') === 0) {
            return null;
        }
        $ts = strtotime($deger);
        if ($ts === false || $ts < strtotime('1900-01-01')) {
            return null;
        }
        return date($sadeceGun ? 'Y-m-d' : 'Y-m-d H:i:s', $ts);
    }

    private static function acik($deger)
    {
        $deger = strtolower(trim((string)$deger));
        return ($deger === 'on' || $deger === '1') ? 1 : 0;
    }

    private static function adSoyadAyir($adSoyad)
    {
        $adSoyad = trim(preg_replace('/\s+/u', ' ', (string)$adSoyad));
        if ($adSoyad === '') {
            return [null, null];
        }
        $parca = explode(' ', $adSoyad);
        if (count($parca) === 1) {
            return [mb_substr($parca[0], 0, 100), null];
        }
        $soyad = array_pop($parca);
        return [mb_substr(implode(' ', $parca), 0, 100), mb_substr($soyad, 0, 100)];
    }

    /** MSSQL sorgusu; hata durumunda istisna */
    private function ms($sql, array $params = [])
    {
        $stmt = sqlsrv_query($this->db->getConnection(), $sql, $params);
        if ($stmt === false) {
            $e = sqlsrv_errors(SQLSRV_ERR_ERRORS);
            throw new Exception('Veritabanı hatası: ' . ($e[0]['message'] ?? 'bilinmeyen'));
        }
        return $stmt;
    }

    /** INSERT ... OUTPUT INSERTED.<pk> ile tek turda ekleyip id döner */
    private function msEkle($tablo, $pk, array $veri)
    {
        $kolonlar = array_keys($veri);
        $sql = "INSERT INTO {$tablo} (" . implode(', ', $kolonlar) . ") OUTPUT INSERTED.{$pk} VALUES ("
             . implode(', ', array_fill(0, count($kolonlar), '?')) . ")";
        $stmt = $this->ms($sql, array_values($veri));
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_NUMERIC);
        sqlsrv_free_stmt($stmt);
        return (int)$row[0];
    }

    /** Çok satırlı INSERT (2100 parametre sınırına göre parçalanır) */
    private function msTopluEkle($tablo, array $kolonlar, array $satirlar)
    {
        if (!$satirlar) {
            return;
        }
        $parca = max(1, intdiv(2000, count($kolonlar)));
        $yerTutucu = '(' . implode(', ', array_fill(0, count($kolonlar), '?')) . ')';
        foreach (array_chunk($satirlar, $parca) as $grup) {
            $params = [];
            foreach ($grup as $s) {
                foreach ($s as $v) {
                    $params[] = $v;
                }
            }
            $sql = "INSERT INTO {$tablo} (" . implode(', ', $kolonlar) . ") VALUES "
                 . implode(', ', array_fill(0, count($grup), $yerTutucu));
            sqlsrv_free_stmt($this->ms($sql, $params));
        }
    }

    public function sonuclar()
    {
        if ($this->sonuclar === null) {
            $this->sonuclar = [];
            foreach ($this->db->fetchAll("SELECT aktarim_sonuc_id, aktarim_sonuc_kod, aktarim_sonuc_adi, aktarim_sonuc_renk
                                          FROM dbo.tanim_AktarimSonuclari WHERE aktarim_sonuc_durum = 1") as $r) {
                $this->sonuclar[$r['aktarim_sonuc_kod']] = [
                    'id'   => (int)$r['aktarim_sonuc_id'],
                    'adi'  => $r['aktarim_sonuc_adi'],
                    'renk' => $r['aktarim_sonuc_renk'] ?: 'secondary',
                ];
            }
        }
        return $this->sonuclar;
    }

    private function sonucId($kod)
    {
        $s = $this->sonuclar();
        if (!isset($s[$kod])) {
            throw new Exception("tanim_AktarimSonuclari içinde '$kod' tanımı yok.");
        }
        return $s[$kod]['id'];
    }

    private function alanlar()
    {
        if ($this->alanlar === null) {
            $this->alanlar = [];
            foreach ($this->db->fetchAll("SELECT form_alan_id, form_alan_kod, form_alan_sabit, form_alan_sira_no
                                          FROM dbo.tanim_FormAlanlari WHERE form_alan_durum = 1") as $r) {
                $this->alanlar[$r['form_alan_kod']] = [
                    'id'    => (int)$r['form_alan_id'],
                    'sabit' => (int)$r['form_alan_sabit'],
                    'sira'  => (int)$r['form_alan_sira_no'],
                ];
            }
        }
        return $this->alanlar;
    }

    private function dilId($kod)
    {
        if ($this->diller === null) {
            $this->diller = [];
            foreach ($this->db->fetchAll("SELECT form_dil_id, form_dil_kod FROM dbo.tanim_FormDilleri WHERE form_dil_durum = 1") as $r) {
                $this->diller[strtolower($r['form_dil_kod'])] = (int)$r['form_dil_id'];
            }
        }
        return $this->diller[strtolower(trim((string)$kod))] ?? null;
    }

    /** Eski SMS paneli → yeni operatör: [panel_no => ['adi', 'operator_id', 'operator_adi']] */
    private function smsPanelleri()
    {
        if ($this->smsPanelleri === null) {
            $this->smsPanelleri = [];
            foreach ($this->db->fetchAll("
                SELECT p.aktarim_sms_panel_no, p.aktarim_sms_panel_adi, p.sms_operator_id, o.sms_operator_adi
                FROM dbo.tanim_AktarimSmsPanelleri p
                LEFT JOIN dbo.SmsOperatorleri o ON o.sms_operator_id = p.sms_operator_id AND o.Durum = 1
                WHERE p.aktarim_sms_panel_durum = 1
            ") as $r) {
                $this->smsPanelleri[(int)$r['aktarim_sms_panel_no']] = [
                    'adi'          => $r['aktarim_sms_panel_adi'],
                    'operator_id'  => $r['sms_operator_adi'] !== null ? (int)$r['sms_operator_id'] : null,
                    'operator_adi' => $r['sms_operator_adi'],
                ];
            }
        }
        return $this->smsPanelleri;
    }

    /** Firma adı karşılaştırması için: Türkçe büyük harf, yalnız harf/rakam, tek boşluk */
    private static function adNormal($ad)
    {
        $ad = str_replace(['i', 'ı'], ['İ', 'I'], trim((string)$ad));
        $ad = mb_strtoupper($ad, 'UTF-8');
        $ad = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $ad);
        return trim(preg_replace('/\s+/u', ' ', $ad));
    }

    /** Otomatik e-posta alan adı: site ayarlarındaki site adresinden (www. olmadan) */
    private function epostaAlanAdi()
    {
        if ($this->epostaAlanAdi === null) {
            $ayar = $this->db->fetchOne("SELECT TOP 1 site_ayarlari_site_url FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
            $host = parse_url(trim((string)($ayar['site_ayarlari_site_url'] ?? '')), PHP_URL_HOST) ?: '';
            $this->epostaAlanAdi = preg_replace('/^www\./i', '', strtolower($host));
        }
        if ($this->epostaAlanAdi === '') {
            throw new Exception('Site ayarlarında site adresi tanımlı değil; otomatik e-posta üretilemez.');
        }
        return $this->epostaAlanAdi;
    }

    /**
     * E-postası olmayan eski kullanıcı için <kullanıcı adı>@<site alan adı> üretir.
     * Türkçe karakterler sadeleşir; adres yeni sistemde başka kullanıcıdaysa sonuna -<eski id> eklenir.
     */
    private function otomatikEposta($kullaniciAdi, $eskiId)
    {
        $ad = strtr(mb_strtolower(trim((string)$kullaniciAdi), 'UTF-8'),
            ['ç' => 'c', 'ğ' => 'g', 'ı' => 'i', 'i̇' => 'i', 'ö' => 'o', 'ş' => 's', 'ü' => 'u', ' ' => '.']);
        $ad = trim(preg_replace('/\.{2,}/', '.', preg_replace('/[^a-z0-9._-]/', '', $ad)), '.-_');
        if ($ad === '') {
            $ad = 'kullanici' . (int)$eskiId;
        }
        $alan = $this->epostaAlanAdi();
        $eposta = mb_substr($ad, 0, 100) . '@' . $alan;
        if ($this->db->fetchOne("SELECT TOP 1 kullanici_id FROM dbo.Kullanicilar WHERE kullanici_email = ?", [$eposta])) {
            $eposta = mb_substr($ad, 0, 90) . '-' . (int)$eskiId . '@' . $alan;
        }
        return $eposta;
    }

    /** Yeni sistemdeki tüm firmalar (hedef seçimi dropdown'u) */
    private function hedefFirmalar()
    {
        if ($this->hedefFirmalar === null) {
            $this->hedefFirmalar = $this->db->fetchAll("
                SELECT firma_id, firma_adi, firma_ust_firma_id, firma_durum
                FROM dbo.Firmalar ORDER BY firma_adi
            ");
        }
        return $this->hedefFirmalar;
    }

    /**
     * Eşlemesi olmayan eski firma için hedef önerisi.
     * Önce birebir aynı ad, yoksa biri diğerini kapsayan tek benzer ad ("DOKTOR GÜLCAN ÇELİK" ↔ "GÜLCAN ÇELİK").
     * @return array|null ['firma_id', 'tip' => 'ayni'|'benzer']
     */
    private function hedefOner($eskiAd)
    {
        $eski = self::adNormal($eskiAd);
        if ($eski === '') {
            return null;
        }
        $benzer = [];
        foreach ($this->hedefFirmalar() as $h) {
            $yeni = self::adNormal($h['firma_adi']);
            if ($yeni === $eski) {
                return ['firma_id' => (int)$h['firma_id'], 'tip' => 'ayni'];
            }
            $kisa = mb_strlen($yeni) < mb_strlen($eski) ? $yeni : $eski;
            $uzun = $kisa === $yeni ? $eski : $yeni;
            if (mb_strlen($kisa) >= 6 && strpos(" $uzun ", " $kisa ") !== false) {
                $benzer[] = (int)$h['firma_id'];
            }
        }
        return count($benzer) === 1 ? ['firma_id' => $benzer[0], 'tip' => 'benzer'] : null;
    }

    /** Kaynak id'lerin başarılı eşlemeleri: [kaynak_id => ['hedef_id', 'sonuc_kod']] */
    public function eslemeler($kaynakTablo, array $kaynakIdler)
    {
        $sonuc = [];
        $idler = array_values(array_unique(array_filter(array_map('intval', $kaynakIdler))));
        foreach (array_chunk($idler, 1000) as $grup) {
            $rows = $this->db->fetchAll("
                SELECT a.aktarim_kaynak_id, a.aktarim_hedef_id, s.aktarim_sonuc_kod
                FROM dbo.Aktarim_Kayitlari a
                INNER JOIN dbo.tanim_AktarimSonuclari s ON s.aktarim_sonuc_id = a.aktarim_sonuc_id
                WHERE a.aktarim_kaynak_db = ? AND a.aktarim_kaynak_tablo = ? AND a.aktarim_durum = 1
                  AND a.aktarim_kaynak_id IN (" . self::inListe($grup) . ")
            ", [self::KAYNAK_DB, $kaynakTablo]);
            foreach ($rows as $r) {
                $sonuc[(int)$r['aktarim_kaynak_id']] = [
                    'hedef_id'  => (int)$r['aktarim_hedef_id'],
                    'sonuc_kod' => $r['aktarim_sonuc_kod'],
                ];
            }
        }
        return $sonuc;
    }

    /** Bir tablo için taşınmış tüm kaynak id'ler (liste filtresi için; firma sayısı < 1000) */
    private function tasinanIdler($kaynakTablo)
    {
        $rows = $this->db->fetchAll("
            SELECT aktarim_kaynak_id FROM dbo.Aktarim_Kayitlari
            WHERE aktarim_kaynak_db = ? AND aktarim_kaynak_tablo = ? AND aktarim_durum = 1
        ", [self::KAYNAK_DB, $kaynakTablo]);
        return array_map('intval', array_column($rows, 'aktarim_kaynak_id'));
    }

    private function eslemeYaz($kaynakTablo, $kaynakId, $hedefTablo, $hedefId, $sonucKod, $mesaj = null)
    {
        $this->db->insert('dbo.Aktarim_Kayitlari', [
            'aktarim_kaynak_db'              => self::KAYNAK_DB,
            'aktarim_kaynak_tablo'           => $kaynakTablo,
            'aktarim_kaynak_id'              => (int)$kaynakId,
            'aktarim_hedef_tablo'            => $hedefTablo,
            'aktarim_hedef_id'               => $hedefId,
            'aktarim_sonuc_id'               => $this->sonucId($sonucKod),
            'aktarim_mesaj'                  => $mesaj ? mb_substr($mesaj, 0, 500) : null,
            'aktarim_olusturan_kullanici_id' => $this->kullaniciId,
            'aktarim_olusturma_tarihi'       => date('Y-m-d H:i:s'),
        ]);
    }

    /** Eski formdaki açık alanların sayısı (önizleme için) */
    private static function acikAlanSayisi(array $form)
    {
        $n = 0;
        foreach (array_keys(self::FORM_ALAN_ESLEME) as $onek) {
            if (self::acik($form[$onek . '_form'] ?? null)) {
                $n++;
            }
        }
        return $n;
    }

    /* =========================================================================
     * İstatistik (InfoBox)
     * ========================================================================= */

    public function istatistik()
    {
        $kaynak = $this->my("SELECT
                (SELECT COUNT(*) FROM companies)  AS firma,
                (SELECT COUNT(*) FROM users)      AS kullanici,
                (SELECT COUNT(*) FROM forms)      AS form,
                (SELECT COUNT(*) FROM form_users) AS basvuru")[0];

        $tasinan = ['companies' => 0, 'users' => 0, 'forms' => 0, 'form_users' => 0];
        foreach ($this->db->fetchAll("
            SELECT aktarim_kaynak_tablo, COUNT(*) AS sayi
            FROM dbo.Aktarim_Kayitlari
            WHERE aktarim_kaynak_db = ? AND aktarim_durum = 1
            GROUP BY aktarim_kaynak_tablo
        ", [self::KAYNAK_DB]) as $r) {
            $tasinan[$r['aktarim_kaynak_tablo']] = (int)$r['sayi'];
        }

        return [
            'firma'     => ['kaynak' => (int)$kaynak['firma'],     'tasinan' => $tasinan['companies']],
            'kullanici' => ['kaynak' => (int)$kaynak['kullanici'], 'tasinan' => $tasinan['users']],
            'form'      => ['kaynak' => (int)$kaynak['form'],      'tasinan' => $tasinan['forms']],
            'basvuru'   => ['kaynak' => (int)$kaynak['basvuru'],   'tasinan' => $tasinan['form_users']],
        ];
    }

    /* =========================================================================
     * Firma listesi (DataTables server-side)
     * ========================================================================= */

    public function firmaListe(array $istek)
    {
        $start  = max(0, (int)($istek['start'] ?? 0));
        $length = (int)($istek['length'] ?? 25);
        $length = ($length < 1 || $length > 200) ? 25 : $length;

        // Sıralama: kolon index → kolon adı whitelist
        $siralama = [0 => 'c.co_id', 1 => "TRIM(REPLACE(c.co_name, '\\t', ''))", 2 => 'ust_ad'];
        $kolonIdx = (int)($istek['order'][0]['column'] ?? 0);
        $kolon = $siralama[$kolonIdx] ?? 'c.co_id';
        $yon = strtolower($istek['order'][0]['dir'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';

        $where = ['1=1'];
        $params = [];

        $arama = trim((string)($istek['arama'] ?? ''));
        if ($arama !== '') {
            $where[] = '(c.co_name LIKE ? OR c.co_title LIKE ? OR c.co_id = ?)';
            $params[] = "%$arama%";
            $params[] = "%$arama%";
            $params[] = ctype_digit($arama) ? (int)$arama : 0;
        }

        $tip = $istek['tip'] ?? '';
        if ($tip === 'ana') {
            $where[] = '(c.co_maincompany = 0 OR c.co_maincompany = c.co_id)';
        } elseif ($tip === 'alt') {
            $where[] = '(c.co_maincompany <> 0 AND c.co_maincompany <> c.co_id)';
        }

        $durum = $istek['durum'] ?? '';
        if ($durum === 'tasindi' || $durum === 'tasinmadi') {
            $tasinan = $this->tasinanIdler('companies');
            if ($durum === 'tasindi') {
                $where[] = 'c.co_id IN (' . self::inListe($tasinan) . ')';
            } elseif ($tasinan) {
                $where[] = 'c.co_id NOT IN (' . self::inListe($tasinan) . ')';
            }
        }

        $whereSql = implode(' AND ', $where);

        $toplam = (int)$this->my("SELECT COUNT(*) AS n FROM companies")[0]['n'];
        $filtreli = (int)$this->my("SELECT COUNT(*) AS n FROM companies c WHERE $whereSql", $params)[0]['n'];

        // 1. aşama: yalnız sayfanın id'leri
        $sayfa = $this->my("
            SELECT c.co_id
            FROM companies c
            LEFT JOIN companies p ON p.co_id = c.co_maincompany AND c.co_maincompany <> c.co_id
            WHERE $whereSql
            ORDER BY " . ($kolon === 'ust_ad' ? "TRIM(REPLACE(p.co_name, '\\t', ''))" : $kolon) . " $yon, c.co_id $yon
            LIMIT $start, $length
        ", $params);
        $idler = array_map('intval', array_column($sayfa, 'co_id'));

        if (!$idler) {
            return ['recordsTotal' => $toplam, 'recordsFiltered' => $filtreli, 'data' => []];
        }
        $in = self::inListe($idler);

        // 2. aşama: yalnız bu satırlar için ayrıntı ve sayımlar
        $detay = [];
        foreach ($this->my("
            SELECT c.co_id, c.co_name, c.co_title, c.co_maincompany, p.co_name AS ust_ad
            FROM companies c
            LEFT JOIN companies p ON p.co_id = c.co_maincompany AND c.co_maincompany <> c.co_id
            WHERE c.co_id IN ($in)
        ") as $r) {
            $detay[(int)$r['co_id']] = $r;
        }

        $altSayi = [];
        foreach ($this->my("SELECT co_maincompany AS ust, COUNT(*) AS n FROM companies
                            WHERE co_maincompany IN ($in) AND co_maincompany <> co_id GROUP BY co_maincompany") as $r) {
            $altSayi[(int)$r['ust']] = (int)$r['n'];
        }

        $firmaKullanicilari = [];
        foreach ($this->my("SELECT uco_coid, uco_uid FROM user_companies WHERE uco_coid IN ($in)") as $r) {
            $firmaKullanicilari[(int)$r['uco_coid']][] = (int)$r['uco_uid'];
        }

        $firmaFormlari = [];
        foreach ($this->my("SELECT form_id, form_coid FROM forms WHERE form_coid IN ($in)") as $r) {
            $firmaFormlari[(int)$r['form_coid']][] = (int)$r['form_id'];
        }
        $tumFormlar = $firmaFormlari ? array_merge(...array_values($firmaFormlari)) : [];

        $formBasvuru = [];
        if ($tumFormlar) {
            foreach ($this->my("SELECT fu_formid, COUNT(*) AS n FROM form_users
                                WHERE fu_formid IN (" . self::inListe($tumFormlar) . ") GROUP BY fu_formid") as $r) {
                $formBasvuru[(int)$r['fu_formid']] = (int)$r['n'];
            }
        }

        $tumKullanicilar = $firmaKullanicilari ? array_merge(...array_values($firmaKullanicilari)) : [];
        $firmaEsleme     = $this->eslemeler('companies', $idler);
        $kullaniciEsleme = $this->eslemeler('users', $tumKullanicilar);
        $formEsleme      = $this->eslemeler('forms', $tumFormlar);
        $tasinanBasvuru  = $this->tasinanBasvuruSayilari($formEsleme);
        $sonuclar        = $this->sonuclar();

        $data = [];
        foreach ($idler as $id) {
            $d = $detay[$id] ?? null;
            if (!$d) {
                continue;
            }
            $kul = $firmaKullanicilari[$id] ?? [];
            $frm = $firmaFormlari[$id] ?? [];
            $basvuruToplam = 0;
            $basvuruTasinan = 0;
            foreach ($frm as $fid) {
                $basvuruToplam += $formBasvuru[$fid] ?? 0;
                $basvuruTasinan += $tasinanBasvuru[$fid] ?? 0;
            }
            $es = $firmaEsleme[$id] ?? null;
            $sonuc = $es ? ($sonuclar[$es['sonuc_kod']] ?? null) : null;

            $data[] = [
                'co_id'             => $id,
                'co_name'           => trim($d['co_name']),
                'co_title'          => trim((string)$d['co_title']),
                'ust_ad'            => $d['ust_ad'] !== null ? trim($d['ust_ad']) : null,
                'alt_sayi'          => $altSayi[$id] ?? 0,
                'kullanici_toplam'  => count($kul),
                'kullanici_tasinan' => count(array_intersect_key($kullaniciEsleme, array_flip($kul))),
                'form_toplam'       => count($frm),
                'form_tasinan'      => count(array_intersect_key($formEsleme, array_flip($frm))),
                'basvuru_toplam'    => $basvuruToplam,
                'basvuru_tasinan'   => $basvuruTasinan,
                'hedef_firma_id'    => $es['hedef_id'] ?? null,
                'durum_adi'         => $sonuc['adi'] ?? null,
                'durum_renk'        => $sonuc['renk'] ?? null,
            ];
        }

        return ['recordsTotal' => $toplam, 'recordsFiltered' => $filtreli, 'data' => $data];
    }

    /** [kaynak_form_id => hedefte bulunan başvuru sayısı] */
    private function tasinanBasvuruSayilari(array $formEsleme)
    {
        $hedefKaynak = [];
        foreach ($formEsleme as $kaynakId => $es) {
            if ($es['hedef_id']) {
                $hedefKaynak[$es['hedef_id']] = $kaynakId;
            }
        }
        if (!$hedefKaynak) {
            return [];
        }
        $sonuc = [];
        foreach ($this->db->fetchAll("
            SELECT form_id, COUNT(*) AS sayi FROM dbo.Form_Basvurulari
            WHERE form_id IN (" . self::inListe(array_keys($hedefKaynak)) . ")
            GROUP BY form_id
        ") as $r) {
            $sonuc[$hedefKaynak[(int)$r['form_id']]] = (int)$r['sayi'];
        }
        return $sonuc;
    }

    /* =========================================================================
     * Önizleme: firma + alt firmaları, kullanıcıları, formları
     * ========================================================================= */

    public function onizle($coId)
    {
        $coId = (int)$coId;
        $firmalar = $this->my("
            SELECT c.co_id, c.co_name, c.co_title, c.co_maincompany, c.co_phone, c.co_address, c.co_website,
                   p.co_name AS ust_ad
            FROM companies c
            LEFT JOIN companies p ON p.co_id = c.co_maincompany AND c.co_maincompany <> c.co_id
            WHERE c.co_id = ? OR (c.co_maincompany = ? AND c.co_id <> ?)
            ORDER BY (c.co_id = ?) DESC, c.co_name
        ", [$coId, $coId, $coId, $coId]);

        if (!$firmalar) {
            throw new Exception('Eski sistemde firma bulunamadı.');
        }

        $coIdler = array_map('intval', array_column($firmalar, 'co_id'));
        $in = self::inListe($coIdler);
        $firmaAdlari = array_column($firmalar, 'co_name', 'co_id');

        // Hedef eşlemeleri ve aynı adla mevcut firmalar
        $firmaEsleme = $this->eslemeler('companies', $coIdler);
        $ustIdler = array_filter(array_map(fn($f) => (int)$f['co_maincompany'] !== (int)$f['co_id'] ? (int)$f['co_maincompany'] : 0, $firmalar));
        $ustEsleme = $this->eslemeler('companies', $ustIdler);
        $sonuclar = $this->sonuclar();

        $firmaSonuc = [];
        foreach ($firmalar as $f) {
            $id = (int)$f['co_id'];
            $ustId = ((int)$f['co_maincompany'] !== $id) ? (int)$f['co_maincompany'] : 0;
            $es = $firmaEsleme[$id] ?? null;
            $oneri = $es ? null : $this->hedefOner($f['co_name']);
            $uyari = null;
            if ($ustId && !in_array($ustId, $coIdler, true) && !isset($ustEsleme[$ustId])) {
                $uyari = 'Üst firma (' . ($f['ust_ad'] ?: "#$ustId") . ') henüz taşınmadı; ana firma olarak eklenecek.';
            }
            $firmaSonuc[] = [
                'co_id'          => $id,
                'co_name'        => $f['co_name'],
                'co_title'       => $f['co_title'],
                'ust_id'         => $ustId,
                'ust_ad'         => $f['ust_ad'],
                'tasindi'        => (bool)$es,
                'hedef_id'       => $es['hedef_id'] ?? ($oneri['firma_id'] ?? null),
                'durum_adi'      => $es ? ($sonuclar[$es['sonuc_kod']]['adi'] ?? '') : null,
                'durum_renk'     => $es ? ($sonuclar[$es['sonuc_kod']]['renk'] ?? 'secondary') : null,
                'oneri_tip'      => $oneri['tip'] ?? null,
                'uyari'          => $uyari,
            ];
        }
        $firmaHedef = array_column($firmaSonuc, 'hedef_id', 'co_id');

        // SMS hesapları (eski sistemde firma başına tek kayıt)
        $smsPanel = $this->smsPanelleri();
        $smsRows = $this->my("SELECT sms_id, sms_coid, sms_username, sms_title, sms_panel FROM sms WHERE sms_coid IN ($in) ORDER BY sms_id");
        $smsEsleme = $this->eslemeler('sms', array_column($smsRows, 'sms_id'));
        $smsHesaplar = [];
        foreach ($smsRows as $s) {
            $sid = (int)$s['sms_id'];
            $coid = (int)$s['sms_coid'];
            $es = $smsEsleme[$sid] ?? null;
            $panel = $smsPanel[(int)$s['sms_panel']] ?? null;
            $kullaniciAdi = trim((string)$s['sms_username']);
            $mevcut = null;
            if (!$es && $panel && $panel['operator_id'] && $kullaniciAdi !== '' && !empty($firmaHedef[$coid])) {
                $mevcut = $this->db->fetchOne("
                    SELECT TOP 1 sms_hesap_id FROM dbo.SmsHesaplari
                    WHERE sms_hesap_firma_id = ? AND sms_hesap_operator_id = ? AND sms_hesap_kullanici_adi = ?
                ", [(int)$firmaHedef[$coid], $panel['operator_id'], $kullaniciAdi]);
            }
            $sorun = null;
            if ($kullaniciAdi === '') {
                $sorun = 'Kullanıcı adı boş';
            } elseif (!$panel) {
                $sorun = 'Eski panel tanımsız (' . ($s['sms_panel'] === null ? 'boş' : '#' . $s['sms_panel']) . ')';
            } elseif (!$panel['operator_id']) {
                $sorun = 'Bu panel için yeni sistemde operatör yok';
            }
            $smsHesaplar[] = [
                'sms_id'        => $sid,
                'co_id'         => $coid,
                'firma_adi'     => $firmaAdlari[$coid] ?? '',
                'kullanici_adi' => $kullaniciAdi,
                'gonderici'     => trim((string)$s['sms_title']),
                'panel_adi'     => $panel['adi'] ?? ($s['sms_panel'] === null ? '-' : '#' . $s['sms_panel']),
                'operator_adi'  => $panel['operator_adi'] ?? null,
                'sorun'         => $sorun,
                'tasindi'       => (bool)$es,
                'hedef_id'      => $es['hedef_id'] ?? ($mevcut['sms_hesap_id'] ?? null),
                'hesap_mevcut'  => (bool)$mevcut,
                'durum_adi'     => $es ? ($sonuclar[$es['sonuc_kod']]['adi'] ?? '') : null,
                'durum_renk'    => $es ? ($sonuclar[$es['sonuc_kod']]['renk'] ?? 'secondary') : null,
            ];
        }

        // Departmanlar (dropdown) ve yetki → departman önerisi
        $departmanlar = $this->db->fetchAll("
            SELECT departman_id, departman_adi, departman_kod
            FROM dbo.Kullanici_Departmanlar WHERE departman_durum = 1 ORDER BY departman_adi
        ");
        $kodDepartman = [];
        foreach ($departmanlar as $d) {
            if ($d['departman_kod']) {
                $kodDepartman[$d['departman_kod']] = (int)$d['departman_id'];
            }
        }

        // Kullanıcılar
        $bag = $this->my("SELECT uco_uid, uco_coid FROM user_companies WHERE uco_coid IN ($in)");
        $kullaniciFirma = array_column($bag, 'uco_coid', 'uco_uid');
        $kullanicilar = [];
        if ($kullaniciFirma) {
            $yetkiler = array_column($this->my("SELECT auth_id, auth_name FROM auths"), 'auth_name', 'auth_id');
            $rows = $this->my("
                SELECT u_id, u_username, u_name, u_email, u_phone, u_auth, u_lastLoginDate,
                       (u_password = 'silindi') AS silindi
                FROM users WHERE u_id IN (" . self::inListe(array_keys($kullaniciFirma)) . ")
                ORDER BY u_name
            ");
            $kulEsleme = $this->eslemeler('users', array_column($rows, 'u_id'));
            foreach ($rows as $u) {
                $id = (int)$u['u_id'];
                $es = $kulEsleme[$id] ?? null;
                $email = trim((string)$u['u_email']);
                if ($email === '' && filter_var($u['u_username'], FILTER_VALIDATE_EMAIL)) {
                    $email = $u['u_username'];
                }
                // E-posta yoksa kullanıcı adından üretilir (mevcut kullanıcıyla eşleşmez, çakışırsa sonuna id eklenir)
                $otomatik = !$es && $email === '';
                if ($otomatik) {
                    $email = $this->otomatikEposta($u['u_username'] ?: $u['u_name'], $u['u_id']);
                }
                $mevcut = (!$es && !$otomatik && $email !== '')
                    ? $this->db->fetchOne("SELECT TOP 1 kullanici_id FROM dbo.Kullanicilar WHERE kullanici_email = ?", [$email])
                    : null;
                $coid = (int)$kullaniciFirma[$id];
                $kullanicilar[] = [
                    'u_id'          => $id,
                    'u_username'    => $u['u_username'],
                    'u_name'        => $u['u_name'],
                    'u_email'       => $email,
                    'u_phone'       => $u['u_phone'],
                    'yetki'         => $yetkiler[$u['u_auth']] ?? ('#' . $u['u_auth']),
                    'son_giris'     => self::tarih($u['u_lastLoginDate']),
                    'silindi'       => (int)$u['silindi'] === 1,
                    'co_id'         => $coid,
                    'firma_adi'     => $firmaAdlari[$coid] ?? '',
                    'departman_id'  => $kodDepartman[self::YETKI_DEPARTMAN[(int)$u['u_auth']] ?? ''] ?? null,
                    'tasindi'       => (bool)$es,
                    'hedef_id'      => $es['hedef_id'] ?? ($mevcut['kullanici_id'] ?? null),
                    'durum_adi'     => $es ? ($sonuclar[$es['sonuc_kod']]['adi'] ?? '') : null,
                    'durum_renk'    => $es ? ($sonuclar[$es['sonuc_kod']]['renk'] ?? 'secondary') : null,
                    'email_mevcut'  => (bool)$mevcut,
                    'email_otomatik'=> $otomatik,
                ];
            }
        }

        // Formlar
        $formRows = $this->my("SELECT * FROM forms WHERE form_coid IN ($in) ORDER BY form_tarih DESC");
        $formIdler = array_map('intval', array_column($formRows, 'form_id'));
        $basvuruSayi = [];
        if ($formIdler) {
            foreach ($this->my("SELECT fu_formid, COUNT(*) AS n FROM form_users
                                WHERE fu_formid IN (" . self::inListe($formIdler) . ") GROUP BY fu_formid") as $r) {
                $basvuruSayi[(int)$r['fu_formid']] = (int)$r['n'];
            }
        }
        $formEsleme = $this->eslemeler('forms', $formIdler);
        $tasinanBasvuru = $this->tasinanBasvuruSayilari($formEsleme);

        $formlar = [];
        foreach ($formRows as $f) {
            $id = (int)$f['form_id'];
            $es = $formEsleme[$id] ?? null;
            $formlar[] = [
                'form_id'         => $id,
                'form_ad'         => $f['form_ad'],
                'dil'             => $f['dil'],
                'tarih'           => self::tarih($f['form_tarih']),
                'co_id'           => (int)$f['form_coid'],
                'firma_adi'       => $firmaAdlari[(int)$f['form_coid']] ?? '',
                'alan_sayisi'     => self::acikAlanSayisi($f),
                'sms_onay'        => self::acik($f['sms_onay']),
                'basvuru_toplam'  => $basvuruSayi[$id] ?? 0,
                'basvuru_tasinan' => $tasinanBasvuru[$id] ?? 0,
                'tasindi'         => (bool)$es,
                'hedef_id'        => $es['hedef_id'] ?? null,
                'durum_adi'       => $es ? ($sonuclar[$es['sonuc_kod']]['adi'] ?? '') : null,
                'durum_renk'      => $es ? ($sonuclar[$es['sonuc_kod']]['renk'] ?? 'secondary') : null,
            ];
        }

        return [
            'firmalar'        => $firmaSonuc,
            'hedef_firmalar'  => array_map(fn($h) => [
                'id'    => (int)$h['firma_id'],
                'adi'   => trim($h['firma_adi']),
                'durum' => (int)$h['firma_durum'],
            ], $this->hedefFirmalar()),
            'sms'             => $smsHesaplar,
            'kullanicilar'    => $kullanicilar,
            'formlar'         => $formlar,
            'departmanlar'    => $departmanlar,
        ];
    }

    /* =========================================================================
     * Yapı aktarımı: firma → kullanıcı → form (tek transaction)
     * ========================================================================= */

    /**
     * @param int[]  $firmaIdler   Taşınacak eski firma id'leri
     * @param array  $kullanicilar [['id'=>, 'email'=>, 'departman_id'=>], ...]
     * @param int[]  $formIdler    Taşınacak eski form id'leri
     * @param array  $firmaHedefler [eski co_id => yeni firma_id]; 0 / yok = yeni firma oluştur
     * @param int[]  $smsIdler     Taşınacak eski sms id'leri
     */
    public function aktarYapi(array $firmaIdler, array $kullanicilar, array $formIdler, array $firmaHedefler = [], array $smsIdler = [])
    {
        $rapor = [];
        $ekle = function ($tur, $kaynakId, $ad, $kod, $mesaj = null, $hedefId = null) use (&$rapor) {
            $s = $this->sonuclar()[$kod] ?? ['adi' => $kod, 'renk' => 'secondary'];
            $rapor[] = ['tur' => $tur, 'kaynak_id' => $kaynakId, 'ad' => $ad, 'sonuc_adi' => $s['adi'],
                        'sonuc_renk' => $s['renk'], 'mesaj' => $mesaj, 'hedef_id' => $hedefId];
        };

        $firmaIdler = array_values(array_unique(array_filter(array_map('intval', $firmaIdler))));
        $formIdler  = array_values(array_unique(array_filter(array_map('intval', $formIdler))));
        $kulSecim = [];
        foreach ($kullanicilar as $k) {
            $id = (int)($k['id'] ?? 0);
            if ($id > 0) {
                $kulSecim[$id] = ['email' => trim((string)($k['email'] ?? '')), 'departman_id' => (int)($k['departman_id'] ?? 0)];
            }
        }

        $departmanlar = array_map('intval', array_column(
            $this->db->fetchAll("SELECT departman_id FROM dbo.Kullanici_Departmanlar WHERE departman_durum = 1"), 'departman_id'));
        $simdi = date('Y-m-d H:i:s');

        $conn = $this->db->getConnection();
        sqlsrv_begin_transaction($conn);

        try {
            /* ---------- Firmalar ---------- */
            $firmaRows = $firmaIdler ? $this->my("SELECT * FROM companies WHERE co_id IN (" . self::inListe($firmaIdler) . ")") : [];
            // Üst firma önce eklensin
            usort($firmaRows, function ($a, $b) {
                $aAlt = (int)$a['co_maincompany'] !== 0 && (int)$a['co_maincompany'] !== (int)$a['co_id'];
                $bAlt = (int)$b['co_maincompany'] !== 0 && (int)$b['co_maincompany'] !== (int)$b['co_id'];
                return $aAlt <=> $bAlt;
            });

            $ustIdler = array_map(fn($f) => (int)$f['co_maincompany'], $firmaRows);
            $firmaEsleme = $this->eslemeler('companies', array_merge($firmaIdler, $ustIdler));

            foreach ($firmaRows as $f) {
                $id = (int)$f['co_id'];
                if (isset($firmaEsleme[$id])) {
                    $ekle('Firma', $id, $f['co_name'], $firmaEsleme[$id]['sonuc_kod'], 'Daha önce taşınmış', $firmaEsleme[$id]['hedef_id']);
                    continue;
                }
                // Önizlemede seçilen mevcut firmaya eşle (seçim yoksa yeni firma açılır)
                $secilen = (int)($firmaHedefler[$id] ?? 0);
                if ($secilen > 0) {
                    $hedef = $this->db->fetchOne("SELECT firma_id, firma_adi FROM dbo.Firmalar WHERE firma_id = ?", [$secilen]);
                    if (!$hedef) {
                        throw new Exception("Seçilen hedef firma bulunamadı (#$secilen).");
                    }
                    $mesaj = 'Mevcut firmaya eşlendi: ' . trim($hedef['firma_adi']);
                    $this->eslemeYaz('companies', $id, 'Firmalar', $secilen, 'eslesti', $mesaj);
                    $firmaEsleme[$id] = ['hedef_id' => $secilen, 'sonuc_kod' => 'eslesti'];
                    $ekle('Firma', $id, $f['co_name'], 'eslesti', $mesaj, $secilen);
                    continue;
                }

                $ustId = (int)$f['co_maincompany'];
                $hedefUst = ($ustId && $ustId !== $id) ? ($firmaEsleme[$ustId]['hedef_id'] ?? null) : null;

                $hedefId = $this->msEkle('dbo.Firmalar', 'firma_id', [
                    'firma_ust_firma_id'           => $hedefUst,
                    'firma_adi'                    => mb_substr(trim($f['co_name']), 0, 200),
                    'firma_ticari_unvan'           => self::metin($f['co_title'], 300),
                    'firma_adres'                  => self::metin($f['co_address'], 500),
                    'firma_telefon'                => self::metin($f['co_phone'], 20),
                    'firma_internet_sitesi'        => self::metin($f['co_website'], 255),
                    'firma_max_alt_firma_sayisi'   => 0,
                    'firma_max_kullanici_sayisi'   => 0,
                    'firma_durum'                  => 1,
                    'firma_olusturan_kullanici_id' => $this->kullaniciId,
                    'firma_olusturma_tarihi'       => $simdi,
                ]);
                $mesaj = ($ustId && $ustId !== $id && !$hedefUst) ? 'Üst firma taşınmadığı için ana firma olarak eklendi' : null;
                $this->eslemeYaz('companies', $id, 'Firmalar', $hedefId, 'eklendi', $mesaj);
                $firmaEsleme[$id] = ['hedef_id' => $hedefId, 'sonuc_kod' => 'eklendi'];
                $ekle('Firma', $id, $f['co_name'], 'eklendi', $mesaj, $hedefId);
            }

            /* ---------- SMS hesapları ---------- */
            $smsIdler = array_values(array_unique(array_filter(array_map('intval', $smsIdler))));
            if ($smsIdler) {
                $rows = $this->my("SELECT * FROM sms WHERE sms_id IN (" . self::inListe($smsIdler) . ")");
                $firmaEsleme += $this->eslemeler('companies', array_column($rows, 'sms_coid'));
                $smsEsleme = $this->eslemeler('sms', $smsIdler);
                $smsPanel = $this->smsPanelleri();


                foreach ($rows as $s) {
                    $sid = (int)$s['sms_id'];
                    $coid = (int)$s['sms_coid'];
                    $kullaniciAdi = trim((string)$s['sms_username']);
                    $ad = $kullaniciAdi !== '' ? $kullaniciAdi : "SMS #$sid";

                    if (isset($smsEsleme[$sid])) {
                        $ekle('SMS', $sid, $ad, $smsEsleme[$sid]['sonuc_kod'], 'Daha önce taşınmış', $smsEsleme[$sid]['hedef_id']);
                        continue;
                    }
                    $hedefFirma = $firmaEsleme[$coid]['hedef_id'] ?? null;
                    if (!$hedefFirma) {
                        $ekle('SMS', $sid, $ad, 'atlandi', 'Bağlı olduğu firma taşınmadı');
                        continue;
                    }
                    $sifre = (string)$s['sms_password'];
                    if ($kullaniciAdi === '' || $sifre === '') {
                        $ekle('SMS', $sid, $ad, 'atlandi', 'Kullanıcı adı veya şifre boş');
                        continue;
                    }
                    $panel = $smsPanel[(int)$s['sms_panel']] ?? null;
                    if (!$panel || !$panel['operator_id']) {
                        $ekle('SMS', $sid, $ad, 'atlandi', ($panel['adi'] ?? 'Panel #' . $s['sms_panel']) . ' için yeni sistemde operatör eşlemesi yok');
                        continue;
                    }

                    $mevcut = $this->db->fetchOne("
                        SELECT TOP 1 sms_hesap_id FROM dbo.SmsHesaplari
                        WHERE sms_hesap_firma_id = ? AND sms_hesap_operator_id = ? AND sms_hesap_kullanici_adi = ?
                    ", [$hedefFirma, $panel['operator_id'], $kullaniciAdi]);
                    if ($mevcut) {
                        $hedefId = (int)$mevcut['sms_hesap_id'];
                        $this->eslemeYaz('sms', $sid, 'SmsHesaplari', $hedefId, 'eslesti', 'Hedef firmada aynı hesap mevcut');
                        $ekle('SMS', $sid, $ad, 'eslesti', 'Hedef firmada aynı hesap mevcut', $hedefId);
                        continue;
                    }

                    // Firmada aktif varsayılan hesap yoksa bu hesap varsayılan olur (filtreli benzersiz indeks)
                    $varsayilanVar = $this->db->fetchOne("
                        SELECT TOP 1 sms_hesap_id FROM dbo.SmsHesaplari
                        WHERE sms_hesap_firma_id = ? AND sms_hesap_varsayilan = 1 AND Durum = 1
                    ", [$hedefFirma]);

                    $hedefId = $this->msEkle('dbo.SmsHesaplari', 'sms_hesap_id', [
                        'sms_hesap_firma_id'      => $hedefFirma,
                        'sms_hesap_operator_id'   => $panel['operator_id'],
                        'sms_hesap_kullanici_adi' => mb_substr($kullaniciAdi, 0, 150),
                        'sms_hesap_sifre'         => $sifre,
                        'sms_hesap_gonderici'     => self::metin($s['sms_title'], 20),
                        'sms_hesap_varsayilan'    => $varsayilanVar ? 0 : 1,
                        'sms_hesap_ticari'        => 0,
                        // Eski sistem doğrulama kodunu da normal gönderimle yollardı; başlıklar OTP servisinde tanımlı değil
                        'sms_hesap_otp_kullan'    => 0,
                        'sms_hesap_aciklama'      => 'Eski sistemden aktarıldı (' . $panel['adi'] . ')',
                        'Durum'                   => 1,
                        'OlusturanKullanici'      => $this->kullaniciId,
                        'OlusturmaTarihi'         => $simdi,
                    ]);
                    $mesaj = $panel['operator_adi'] . ($varsayilanVar ? '' : ', varsayılan hesap');
                    $this->eslemeYaz('sms', $sid, 'SmsHesaplari', $hedefId, 'eklendi', $mesaj);
                    $ekle('SMS', $sid, $ad, 'eklendi', $mesaj, $hedefId);
                }
            }

            /* ---------- Kullanıcılar ---------- */
            if ($kulSecim) {
                $kulIdler = array_keys($kulSecim);
                $rows = $this->my("SELECT * FROM users WHERE u_id IN (" . self::inListe($kulIdler) . ")");
                $kulFirma = array_column($this->my("SELECT uco_uid, uco_coid FROM user_companies
                                                     WHERE uco_uid IN (" . self::inListe($kulIdler) . ")"), 'uco_coid', 'uco_uid');
                $firmaEsleme += $this->eslemeler('companies', array_values($kulFirma));
                $kulEsleme = $this->eslemeler('users', $kulIdler);

                foreach ($rows as $u) {
                    $id = (int)$u['u_id'];
                    $ad = $u['u_name'] ?: $u['u_username'];
                    $secim = $kulSecim[$id];

                    if (isset($kulEsleme[$id])) {
                        $ekle('Kullanıcı', $id, $ad, $kulEsleme[$id]['sonuc_kod'], 'Daha önce taşınmış', $kulEsleme[$id]['hedef_id']);
                        continue;
                    }
                    $coid = (int)($kulFirma[$id] ?? 0);
                    $hedefFirma = $firmaEsleme[$coid]['hedef_id'] ?? null;
                    if (!$hedefFirma) {
                        $ekle('Kullanıcı', $id, $ad, 'atlandi', 'Bağlı olduğu firma taşınmadı');
                        continue;
                    }
                    $email = mb_strtolower($secim['email']);
                    if ($email === '') {
                        // Önizlemede alan boşaltıldıysa da kullanıcı adından üretilir
                        $email = $this->otomatikEposta($u['u_username'] ?: $u['u_name'], $id);
                    }
                    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150) {
                        $ekle('Kullanıcı', $id, $ad, 'atlandi', 'Geçerli e-posta adresi yok');
                        continue;
                    }
                    if (!in_array($secim['departman_id'], $departmanlar, true)) {
                        $ekle('Kullanıcı', $id, $ad, 'atlandi', 'Departman seçilmedi');
                        continue;
                    }

                    $mevcut = $this->db->fetchOne("SELECT TOP 1 kullanici_id FROM dbo.Kullanicilar WHERE kullanici_email = ?", [$email]);
                    if ($mevcut) {
                        $hedefId = (int)$mevcut['kullanici_id'];
                        $this->eslemeYaz('users', $id, 'Kullanicilar', $hedefId, 'eslesti', 'Aynı e-posta ile kullanıcı mevcut');
                        $ekle('Kullanıcı', $id, $ad, 'eslesti', 'Aynı e-posta ile kullanıcı mevcut', $hedefId);
                        continue;
                    }

                    [$kAd, $kSoyad] = self::adSoyadAyir($u['u_name'] ?: $u['u_username']);
                    $silindi = $u['u_password'] === 'silindi';

                    // Eski şifreler MD5; dönüştürülemez. Rastgele şifre + ilk girişte değiştirme zorunlu.
                    $hedefId = $this->msEkle('dbo.Kullanicilar', 'kullanici_id', [
                        'kullanici_email'              => $email,
                        'kullanici_sifre_hash'         => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
                        'kullanici_ad'                 => $kAd,
                        'kullanici_soyad'              => $kSoyad,
                        'kullanici_telefon'            => telefonNormalizeEtGuvenli($u['u_phone']),
                        'kullanici_durum'              => $silindi ? 0 : 1,
                        'kullanici_olusturma_tarihi'   => $simdi,
                        'kullanici_son_giris_tarihi'   => self::tarih($u['u_lastLoginDate']),
                        'kullanici_departman_id'       => $secim['departman_id'],
                        'kullanici_dogum_tarihi'       => self::tarih($u['u_birthday'], true),
                        'kullanici_olusturan_id'       => $this->kullaniciId,
                        'kullanici_sifre_degistirmeli' => 1,
                        'kullanici_email_dogrulandi'   => 0,
                        'kullanici_firma_id'           => $hedefFirma,
                    ]);
                    $mesaj = $silindi ? 'Eski sistemde silinmiş; pasif eklendi' : 'Şifre sıfırlanmalı';
                    $this->eslemeYaz('users', $id, 'Kullanicilar', $hedefId, 'eklendi', $mesaj);
                    $ekle('Kullanıcı', $id, $ad, 'eklendi', $mesaj, $hedefId);
                }
            }

            /* ---------- Formlar + alanlar ---------- */
            $basvuruFormlari = [];
            if ($formIdler) {
                $rows = $this->my("SELECT * FROM forms WHERE form_id IN (" . self::inListe($formIdler) . ")");
                $firmaEsleme += $this->eslemeler('companies', array_column($rows, 'form_coid'));
                $formEsleme = $this->eslemeler('forms', $formIdler);

                // Ek alan etiketleri
                $ekIdler = array_filter(array_map('intval', array_column($rows, 'ek_eid')));
                $etiketler = [];
                if ($ekIdler) {
                    foreach ($this->my("SELECT fe_id, fe_x, fe_y, fe_z FROM form_elements WHERE fe_id IN (" . self::inListe($ekIdler) . ")") as $e) {
                        $etiketler[(int)$e['fe_id']] = ['ek1' => $e['fe_x'], 'ek2' => $e['fe_y'], 'ek3' => $e['fe_z']];
                    }
                }
                $alanlar = $this->alanlar();

                foreach ($rows as $f) {
                    $id = (int)$f['form_id'];
                    if (isset($formEsleme[$id])) {
                        $ekle('Form', $id, $f['form_ad'], $formEsleme[$id]['sonuc_kod'], 'Daha önce taşınmış', $formEsleme[$id]['hedef_id']);
                        $basvuruFormlari[] = $id;
                        continue;
                    }
                    $hedefFirma = $firmaEsleme[(int)$f['form_coid']]['hedef_id'] ?? null;
                    if (!$hedefFirma) {
                        $ekle('Form', $id, $f['form_ad'], 'atlandi', 'Bağlı olduğu firma taşınmadı');
                        continue;
                    }

                    // Eski uniqid korunur (QR yönlendirmesi için); çakışırsa yenisi üretilir
                    $uniqid = trim((string)$f['form_uniqid']);
                    if ($uniqid === '' || strlen($uniqid) > 32
                        || $this->db->fetchOne("SELECT TOP 1 form_id FROM dbo.Formlar WHERE form_uniqid = ?", [$uniqid])) {
                        $uniqid = bin2hex(random_bytes(8));
                    }

                    $hedefId = $this->msEkle('dbo.Formlar', 'form_id', [
                        'firma_id'                    => $hedefFirma,
                        'form_adi'                    => mb_substr(trim($f['form_ad']), 0, 200),
                        'form_uniqid'                 => $uniqid,
                        'form_dil_id'                 => $this->dilId($f['dil']),
                        'form_aydinlatma_metni'       => self::metin($f['aydinlatma_metni'], 1000000),
                        'form_acik_riza_metni'        => self::metin($f['acikriza_metni'], 1000000),
                        'form_etk_metni'              => self::metin($f['etk_metni'], 1000000),
                        'form_etk_zorunlu'            => self::acik($f['etk_zorunlu']),
                        'form_gps_metni'              => self::metin($f['gps_metin'], 1000000),
                        'form_gps_zorunlu'            => self::acik($f['gps_zorunlu']),
                        'form_tesekkur_metni'         => self::metin($f['tesekkur_metni'], 1000000),
                        'form_sms_onay'               => self::acik($f['sms_onay']),
                        'form_durum'                  => 1,
                        'form_olusturan_kullanici_id' => $this->kullaniciId,
                        'form_olusturma_tarihi'       => self::tarih($f['form_tarih']) ?? $simdi,
                    ]);

                    $etiket = $etiketler[(int)$f['ek_eid']] ?? [];
                    $alanSatirlari = [];
                    $sira = 0;
                    foreach (self::FORM_ALAN_ESLEME as $onek => $kod) {
                        $alan = $alanlar[$kod] ?? null;
                        if (!$alan) {
                            continue;
                        }
                        $acik = self::acik($f[$onek . '_form'] ?? null);
                        if (!$acik && !$alan['sabit']) {
                            continue;
                        }
                        $sira++;
                        $alanSatirlari[] = [
                            $hedefId,
                            $alan['id'],
                            $alan['sabit'] ? 1 : self::acik($f[$onek . '_zorunlu'] ?? null),
                            $sira,
                            1,
                            self::metin($etiket[$onek] ?? null, 150),
                            $this->kullaniciId,
                            $simdi,
                        ];
                    }
                    $this->msTopluEkle('dbo.Form_Alanlari', [
                        'form_id', 'form_alan_id', 'form_alani_zorunlu', 'form_alani_sira_no', 'form_alani_durum',
                        'form_alani_etiket', 'form_alani_olusturan_kullanici_id', 'form_alani_olusturma_tarihi',
                    ], $alanSatirlari);

                    $this->eslemeYaz('forms', $id, 'Formlar', $hedefId, 'eklendi', count($alanSatirlari) . ' alan');
                    $ekle('Form', $id, $f['form_ad'], 'eklendi', count($alanSatirlari) . ' alan', $hedefId);
                    $basvuruFormlari[] = $id;
                }
            }

            sqlsrv_commit($conn);
        } catch (Throwable $e) {
            sqlsrv_rollback($conn);
            throw $e;
        }

        return ['rapor' => $rapor, 'basvuru_formlari' => $basvuruFormlari];
    }

    /* =========================================================================
     * Başvuru (doldurulmuş form / onaylayan) aktarımı — parti parti
     * ========================================================================= */

    public function aktarBasvuru($kaynakFormId, $sonId)
    {
        $kaynakFormId = (int)$kaynakFormId;
        $sonId = max(0, (int)$sonId);

        $es = $this->eslemeler('forms', [$kaynakFormId])[$kaynakFormId] ?? null;
        if (!$es || !$es['hedef_id']) {
            throw new Exception('Form henüz taşınmamış; önce form taşınmalı.');
        }
        $hedefForm = $this->db->fetchOne("SELECT form_id, firma_id FROM dbo.Formlar WHERE form_id = ?", [$es['hedef_id']]);
        if (!$hedefForm) {
            throw new Exception('Hedef form bulunamadı (#' . $es['hedef_id'] . ').');
        }

        $rows = $this->my("SELECT * FROM form_users WHERE fu_formid = ? AND fu_id > ? ORDER BY fu_id LIMIT " . self::BASVURU_PARTI,
                          [$kaynakFormId, $sonId]);
        if (!$rows) {
            return ['aktarilan' => 0, 'mevcut' => 0, 'son_id' => $sonId, 'bitti' => true];
        }

        $bitti = count($rows) < self::BASVURU_PARTI;
        $yeniSonId = (int)end($rows)['fu_id'];
        $mevcut = $this->eslemeler('form_users', array_column($rows, 'fu_id'));
        $rows = array_values(array_filter($rows, fn($r) => !isset($mevcut[(int)$r['fu_id']])));

        $aktarilan = 0;
        if ($rows) {
            // Kod → ad çözümlemeleri (yalnız bu partideki id'ler)
            $iller = array_column($this->my("SELECT id, il FROM iller WHERE id IN (" . self::inListe(array_column($rows, 'fu_il')) . ")"), 'il', 'id');
            $ilceler = array_column($this->my("SELECT id, ilce FROM ilceler WHERE id IN (" . self::inListe(array_column($rows, 'fu_ilce')) . ")"), 'ilce', 'id');
            $departmanlar = array_column($this->my("SELECT departman_id, departman_adi FROM departmanlar WHERE departman_id IN ("
                . self::inListe(array_column($rows, 'fu_gorusulecekdepartman')) . ")"), 'departman_adi', 'departman_id');
            $kisiler = array_column($this->my("SELECT u_id, u_name FROM users WHERE u_id IN ("
                . self::inListe(array_column($rows, 'fu_gorusulecekkisi')) . ")"), 'u_name', 'u_id');
            $otp = [];
            $refler = array_filter(array_map('intval', array_column($rows, 'fu_refKodu')));
            if ($refler) {
                foreach ($this->my("SELECT refID, isVerified, verificationTime FROM otp_check WHERE refID IN (" . self::inListe($refler) . ")") as $o) {
                    if ((int)$o['isVerified'] === 1) {
                        $otp[(int)$o['refID']] = self::tarih($o['verificationTime']);
                    }
                }
            }

            $alanlar = $this->alanlar();
            $simdi = date('Y-m-d H:i:s');
            $onay = fn($v) => ((string)$v === '1') ? 1 : 0;
            $tamSayi = fn($v) => ($v === null || $v === '') ? null : (int)$v;

            $conn = $this->db->getConnection();
            sqlsrv_begin_transaction($conn);
            try {
                $degerSatirlari = [];
                $eslemeSatirlari = [];
                $eklendiId = $this->sonucId('eklendi');

                foreach ($rows as $r) {
                    $fuId = (int)$r['fu_id'];
                    $telefonHam = trim((string)$r['fu_ceptelefonu']);
                    $telefon = telefonNormalizeEtGuvenli($telefonHam) ?: self::metin(preg_replace('/[^0-9+]/', '', $telefonHam), 20);
                    $ref = (int)$r['fu_refKodu'];
                    $kvkk = (string)$r['fu_kvkk'];

                    $basvuruId = $this->msEkle('dbo.Form_Basvurulari', 'form_basvuru_id', [
                        'form_id'                            => (int)$hedefForm['form_id'],
                        'firma_id'                           => (int)$hedefForm['firma_id'],
                        'form_basvuru_ad'                    => self::metin($r['fu_ad'], 150),
                        'form_basvuru_soyad'                 => self::metin($r['fu_soyad'], 150),
                        'form_basvuru_telefon'               => $telefon,
                        'form_basvuru_eposta'                => self::metin($r['fu_eposta'], 255),
                        'form_basvuru_kvkk_onay'             => $kvkk === '1' ? 1 : ($kvkk === '2' ? 0 : null),
                        'form_basvuru_aydinlatma_onay'       => $onay($r['fu_aydinlatma']),
                        'form_basvuru_acik_riza_onay'        => $onay($r['fu_acikriza']),
                        'form_basvuru_etk_onay'              => $onay($r['fu_etk']),
                        'form_basvuru_gps_onay'              => $onay($r['fu_gps']),
                        'form_basvuru_dogrulama_durumu'      => $tamSayi($r['fu_verificationStatus']),
                        'form_basvuru_sms_referans'          => $ref > 0 ? (string)$ref : null,
                        'form_basvuru_sms_dogrulama_tarihi'  => $ref > 0 ? ($otp[$ref] ?? null) : null,
                        'form_basvuru_iys_arama'             => $tamSayi($r['fu_IYScallPermission']),
                        'form_basvuru_iys_sms'               => $tamSayi($r['fu_IYSsmsPermission']),
                        'form_basvuru_iys_eposta'            => $tamSayi($r['fu_IYSemailPermission']),
                        'form_basvuru_iys_guncelleme_tarihi' => self::tarih($r['fu_IYSPermissionUpdateDate'], true),
                        'form_basvuru_ip_adresi'             => self::metin($r['fu_ipadresi'], 45),
                        'form_basvuru_tarihi'                => self::tarih($r['fu_tarih']) ?? $simdi,
                        'form_basvuru_durum'                 => 1,
                        'form_basvuru_olusturan_kullanici_id'=> $this->kullaniciId,
                        'form_basvuru_olusturma_tarihi'      => $simdi,
                        'form_basvuru_guncelleme_tarihi'     => self::tarih($r['fu_guncellemeTarih']),
                    ]);

                    // Alan değerleri
                    $degerler = [];
                    foreach (self::BASVURU_ALAN_ESLEME as $kolon => $kod) {
                        $degerler[$kod] = $r[$kolon] ?? null;
                    }
                    $degerler['cinsiyet'] = self::ESKI_CINSIYET[(int)$r['fu_cinsiyet']] ?? $r['fu_cinsiyet'];
                    $degerler['il']       = $iller[(int)$r['fu_il']] ?? $r['fu_il'];
                    $degerler['ilce']     = $ilceler[(int)$r['fu_ilce']] ?? $r['fu_ilce'];

                    $gelis = self::ESKI_GELIS_NEDENI[(int)$r['fu_gelis_nedeni']] ?? null;
                    if ($gelis && ($iptal = self::ESKI_IPTAL_NEDENI[(int)$r['fu_iptal_nedeni']] ?? null)) {
                        $gelis .= ' - ' . $iptal;
                    }
                    $degerler['gelis_nedeni'] = $gelis;

                    $dep = $departmanlar[(int)$r['fu_gorusulecekdepartman']] ?? null;
                    $kisi = $kisiler[(int)$r['fu_gorusulecekkisi']] ?? null;
                    $degerler['gorusulecek_departman'] = $dep ? ($kisi ? "$dep / $kisi" : $dep) : $kisi;

                    foreach ($degerler as $kod => $deger) {
                        $deger = self::metin($deger, 1000);
                        if ($deger === null || !isset($alanlar[$kod])) {
                            continue;
                        }
                        $degerSatirlari[] = [$basvuruId, $alanlar[$kod]['id'], $deger, $this->kullaniciId];
                    }

                    $eslemeSatirlari[] = [self::KAYNAK_DB, 'form_users', $fuId, 'Form_Basvurulari', $basvuruId, $eklendiId, $this->kullaniciId];
                    $aktarilan++;
                }

                $this->msTopluEkle('dbo.Form_Basvuru_Degerleri',
                    ['form_basvuru_id', 'form_alan_id', 'form_basvuru_deger', 'form_basvuru_deger_olusturan_kullanici_id'],
                    $degerSatirlari);
                $this->msTopluEkle('dbo.Aktarim_Kayitlari',
                    ['aktarim_kaynak_db', 'aktarim_kaynak_tablo', 'aktarim_kaynak_id', 'aktarim_hedef_tablo',
                     'aktarim_hedef_id', 'aktarim_sonuc_id', 'aktarim_olusturan_kullanici_id'],
                    $eslemeSatirlari);

                sqlsrv_commit($conn);
            } catch (Throwable $e) {
                sqlsrv_rollback($conn);
                throw $e;
            }
        }

        return [
            'aktarilan' => $aktarilan,
            'mevcut'    => count($mevcut),
            'son_id'    => $yeniSonId,
            'bitti'     => $bitti,
        ];
    }
}
