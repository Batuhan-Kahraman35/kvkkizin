<?php
/**
 * /api/formlar uç noktaları: liste, form deseni, başvuru gönderimi
 */

require_once __DIR__ . '/Api.php';
require_once __DIR__ . '/../FormBasvuru.php';
require_once __DIR__ . '/../Sifreleme.php';

class ApiFormlar
{
    /** GET /api/formlar: anahtarın firmasına ait aktif formlar */
    public static function liste(Api $api, array $p)
    {
        $satirlar = Database::getInstance()->fetchAll("
            SELECT form_uniqid, form_adi, form_sms_onay,
                   CONVERT(VARCHAR(19), form_olusturma_tarihi, 120) AS form_olusturma_tarihi,
                   CONVERT(VARCHAR(19), form_guncelleme_tarihi, 120) AS form_guncelleme_tarihi
            FROM dbo.Formlar
            WHERE firma_id = ? AND form_durum = 1
            ORDER BY form_adi
        ", [$api->firmaId()]) ?: [];

        $kok = FormBasvuru::kokAdres();
        return array_map(fn($r) => [
            'uniqid'            => $r['form_uniqid'],
            'adi'               => $r['form_adi'],
            'sms_dogrulama'     => (int)$r['form_sms_onay'] === 1,
            'adres'             => $kok . '/form/' . $r['form_uniqid'],
            'olusturma_tarihi'  => $r['form_olusturma_tarihi'],
            'guncelleme_tarihi' => $r['form_guncelleme_tarihi'],
        ], $satirlar);
    }

    /** Anahtarın firmasına ait aktif form; yoksa FORM_BULUNAMADI */
    public static function formBul(Api $api, $uniqid)
    {
        $form = (new FormBasvuru())->formGetir($uniqid);
        if (!$form || (int)$form['firma_id'] !== $api->firmaId()) {
            throw new ApiHata('FORM_BULUNAMADI', 'Form bulunamadı veya bu anahtarın firmasına ait değil.');
        }
        return $form;
    }

    /** GET /api/formlar/{uniqid}: alanlar, seçenekler, onay metinleri ve hash'leri */
    public static function desen(Api $api, array $p)
    {
        $form = self::formBul($api, $p['uniqid']);
        $servis = new FormBasvuru();
        $otp = FormBasvuru::otpAyarlari($form['firma_id']);
        $kok = FormBasvuru::kokAdres() . '/form/' . $form['form_uniqid'];

        $alanlar = [];
        foreach ($servis->alanlar($form['form_id']) as $a) {
            $alan = [
                'kod'     => $a['form_alan_kod'],
                'etiket'  => $a['etiket'],
                'tip'     => $a['form_alan_tipi'],
                'zorunlu' => (bool)$a['zorunlu'],
            ];
            if ($a['form_alan_kod'] === 'il') {
                $alan['deger'] = 'il id';
                $alan['kaynak'] = '/api/iller';
            } elseif ($a['form_alan_kod'] === 'ilce') {
                $alan['deger'] = 'ilçe id';
                $alan['kaynak'] = '/api/iller/{il_id}/ilceler';
            } elseif ($a['form_alan_tipi'] === 'select') {
                $alan['deger'] = 'seçenek id';
                $alan['secenekler'] = array_map(function ($s) use ($a) {
                    $altlar = $a['alt_secenekler'][(int)$s['form_alan_secenek_id']] ?? [];
                    $o = ['id' => (int)$s['form_alan_secenek_id'], 'adi' => $s['form_alan_secenek_adi']];
                    if ($altlar) {
                        $o['alt_baslik'] = $s['form_alan_secenek_alt_baslik'];
                        $o['alt_secenekler'] = array_map(fn($x) => ['id' => (int)$x['form_alan_secenek_id'], 'adi' => $x['form_alan_secenek_adi']], $altlar);
                    }
                    return $o;
                }, $a['secenekler']);
                if ($a['alt_secenekler']) {
                    $alan['alt_alan'] = $a['form_alan_kod'] . '_alt';
                }
            } elseif ($a['form_alan_tipi'] === 'date') {
                $alan['deger'] = 'YYYY-AA-GG';
            }
            $alanlar[] = $alan;
        }

        $onaylar = [];
        foreach (FormBasvuru::onayMetinleri($form) as $anahtar => $m) {
            $onaylar[] = [
                'anahtar' => $anahtar,
                'baslik'  => $m['baslik'],
                'zorunlu' => (bool)$m['zorunlu'],
                'hash'    => $m['hash'],
                'adres'   => $kok . '/' . $anahtar,
                'metin'   => $m['metin'],
            ];
        }

        return [
            'uniqid'        => $form['form_uniqid'],
            'adi'           => $form['form_adi'],
            'firma'         => $form['firma_adi'],
            'adres'         => $kok,
            'sms_dogrulama' => (int)$form['form_sms_onay'] === 1,
            'dogrulama'     => (int)$form['form_sms_onay'] === 1 ? [
                'kod_hane'       => (int)$otp['hane'],
                'gecerlilik_sn'  => (int)$otp['gecerlilik_sn'],
                'tekrar_sn'      => (int)$otp['tekrar_sn'],
                'deneme_hakki'   => (int)$otp['max_deneme'],
            ] : null,
            'alanlar'       => $alanlar,
            'onaylar'       => $onaylar,
        ];
    }

    /**
     * POST /api/formlar/{uniqid}/basvuru
     * SMS doğrulamalı formda kod gönderilir → 202 dogrulama_id; doğrulamasız formda doğrudan kayıt → 201 basvuru_id.
     */
    public static function basvuru(Api $api, array $p)
    {
        $form = self::formBul($api, $p['uniqid']);
        $govde = $api->govde();
        $servis = new FormBasvuru();
        $db = Database::getInstance();

        [$post, $musteri] = self::postHazirla($govde);

        $sonuc = $servis->dogrula($form, $servis->alanlar($form['form_id']), $post);
        if ($sonuc['hatalar']) {
            throw new ApiHata('ALAN_HATASI', 'Gönderilen bilgilerde hata var.', ['alanlar' => $sonuc['hatalar']]);
        }
        if ($sonuc['metin_degisti']) {
            throw new ApiHata('METIN_DEGISTI', 'Onay metinlerinden biri değişti veya hash gönderilmedi. Form desenini yeniden alıp müşteriye güncel metni gösterin.',
                ['guncel_hashler' => array_map(fn($m) => $m['hash'], FormBasvuru::onayMetinleri($form))]);
        }
        $veri = $sonuc['veri'];
        $telefon = (string)$veri['baslik']['telefon'];
        $smsli = (int)$form['form_sms_onay'] === 1;

        $dogrulamaId = 'dgr_' . bin2hex(random_bytes(20));
        $hash = hash('sha256', $dogrulamaId);

        $smslogId = null;
        if ($smsli) {
            $kod = $servis->kodGonder($form, $telefon, ['anahtar_id' => $api->anahtarId()]);
            if (empty($kod['success'])) {
                throw new ApiHata(($kod['kod'] ?? '') === 'COK_FAZLA_ISTEK' ? 'COK_FAZLA_ISTEK' : 'SMS_GONDERILEMEDI', $kod['message']);
            }
            $smslogId = (int)$kod['smslog_id'];
        }

        $bekleyen = $db->fetchOne("
            INSERT INTO dbo.Api_Bekleyen_Basvurular
                (bekleyen_dogrulama_hash, bekleyen_anahtar_id, bekleyen_form_id, bekleyen_smslog_id, bekleyen_telefon,
                 bekleyen_veri, bekleyen_cagiran_ip, bekleyen_musteri_ip, bekleyen_musteri_tarayici, bekleyen_gecerlilik)
            OUTPUT INSERTED.bekleyen_id, CONVERT(VARCHAR(19), INSERTED.bekleyen_gecerlilik, 120) AS gecerlilik
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, DATEADD(HOUR, ?, GETDATE()))
        ", [
            $hash, $api->anahtarId(), (int)$form['form_id'], $smslogId, $telefon,
            $smsli ? Sifreleme::sifrele(json_encode($veri, JSON_UNESCAPED_UNICODE), 'bekleyen:' . $hash) : null,
            Api::istemciIp(), $musteri['ip'], $musteri['tarayici'],
            Ayarlar::sayi('api_bekleyen_saat'),
        ]);
        if (!$bekleyen) {
            throw new Exception('Bekleyen başvuru yazılamadı.');
        }

        if (!$smsli) {
            $basvuruId = $servis->kaydet($form, $veri, null, self::apiBaglam($api, $bekleyen['bekleyen_id'], $musteri));
            $api->logBasvuru($basvuruId);   // bildirim kaydet() içinde kuyruğa yazılır
            return Api::durum(['basvuru_id' => $basvuruId, 'durum' => 'onaylandi', 'sms_dogrulama' => false], 201);
        }

        $otp = FormBasvuru::otpAyarlari($form['firma_id']);
        return Api::durum([
            'dogrulama_id'  => $dogrulamaId,
            'telefon'       => FormBasvuru::telefonMaskele($telefon),
            'kod_hane'      => (int)$otp['hane'],
            'gecerlilik_sn' => (int)$otp['gecerlilik_sn'],
            'tekrar_sn'     => (int)$otp['tekrar_sn'],
            'son_tarih'     => $bekleyen['gecerlilik'],
        ], 202);
    }

    /** kaydet() için API bağlamı */
    public static function apiBaglam(Api $api, $bekleyenId, array $musteri)
    {
        return [
            'anahtar_id'       => $api->anahtarId(),
            'bekleyen_id'      => (int)$bekleyenId,
            'musteri_ip'       => $musteri['ip'],
            'musteri_tarayici' => $musteri['tarayici'],
        ];
    }

    /**
     * API gövdesini FormBasvuru::dogrula() biçimine çevirir.
     *   {"alanlar": {kod: değer}, "onaylar": {anahtar: {"kabul": bool, "hash": "..."}}, "musteri": {"ip", "tarayici"}}
     */
    private static function postHazirla(array $govde)
    {
        $hatalar = [];
        $alanlar = $govde['alanlar'] ?? null;
        $onaylar = $govde['onaylar'] ?? [];
        if (!is_array($alanlar) || array_is_list($alanlar) && $alanlar) {
            throw new ApiHata('ALAN_HATASI', '"alanlar" nesnesi gönderilmedi.', ['alanlar' => ['alanlar' => 'Zorunlu nesne: {"alan_kodu": "değer"}']]);
        }
        if (!is_array($onaylar)) {
            throw new ApiHata('ALAN_HATASI', '"onaylar" bir nesne olmalı.', ['alanlar' => ['onaylar' => 'Biçim: {"aydinlatma": {"kabul": true, "hash": "..."}}']]);
        }

        $post = ['alan' => [], 'onay' => [], 'metin_hash' => []];
        foreach ($alanlar as $kod => $deger) {
            if (is_bool($deger)) {
                $deger = $deger ? '1' : '0';
            }
            if (!is_scalar($deger) && $deger !== null) {
                $hatalar[$kod] = 'Değer metin veya sayı olmalı.';
                continue;
            }
            $post['alan'][(string)$kod] = (string)$deger;
        }
        foreach ($onaylar as $anahtar => $o) {
            if (!is_array($o)) {
                $hatalar['onay_' . $anahtar] = 'Biçim: {"kabul": true|false, "hash": "..."}';
                continue;
            }
            if (array_key_exists('kabul', $o) && $o['kabul'] !== null) {
                $post['onay'][$anahtar] = in_array($o['kabul'], [true, 1, '1'], true) ? '1' : '0';
            }
            $post['metin_hash'][$anahtar] = (string)($o['hash'] ?? '');
        }

        $m = is_array($govde['musteri'] ?? null) ? $govde['musteri'] : [];
        $ip = trim((string)($m['ip'] ?? ''));
        if ($ip !== '' && !filter_var($ip, FILTER_VALIDATE_IP)) {
            $hatalar['musteri.ip'] = 'Geçerli bir IP adresi değil.';
        }
        $tarayici = trim((string)($m['tarayici'] ?? ''));

        if ($hatalar) {
            throw new ApiHata('ALAN_HATASI', 'Gönderilen bilgilerde hata var.', ['alanlar' => $hatalar]);
        }
        return [$post, ['ip' => $ip !== '' ? $ip : null, 'tarayici' => $tarayici !== '' ? mb_substr($tarayici, 0, 300) : null]];
    }
}
