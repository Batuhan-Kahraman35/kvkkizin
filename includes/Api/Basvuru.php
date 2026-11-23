<?php
/**
 * /api/basvuru/... : SMS kodu doğrulama ve yeniden gönderim
 */

require_once __DIR__ . '/Api.php';
require_once __DIR__ . '/Formlar.php';
require_once __DIR__ . '/../FormBasvuru.php';
require_once __DIR__ . '/../Sifreleme.php';

class ApiBasvuru
{
    const DOGRULAMA_DESENI = '/^dgr_[a-f0-9]{40}$/';

    /** POST /api/basvuru/dogrula  {"dogrulama_id", "kod"} → 201 basvuru_id */
    public static function dogrula(Api $api, array $p)
    {
        $govde = $api->govde();
        $kod = trim((string)($govde['kod'] ?? ''));
        if ($kod === '') {
            throw new ApiHata('ALAN_HATASI', 'Doğrulama kodu gönderilmedi.', ['alanlar' => ['kod' => 'Zorunlu.']]);
        }
        [$bekleyen, $form] = self::bekleyenBul($api, $govde);

        $servis = new FormBasvuru();
        $sonuc = $servis->kodDogrula($bekleyen['bekleyen_smslog_id'], $bekleyen['bekleyen_telefon'], $kod);
        if (empty($sonuc['success'])) {
            throw !empty($sonuc['bitti'])
                ? new ApiHata('KOD_GECERSIZ', $sonuc['message'] . ' (POST /api/basvuru/kod-tekrar)')
                : new ApiHata('KOD_HATALI', $sonuc['message']);
        }

        $hash = $bekleyen['bekleyen_dogrulama_hash'];
        $veri = json_decode(Sifreleme::coz($bekleyen['bekleyen_veri'], 'bekleyen:' . $hash), true);
        if (!is_array($veri)) {
            throw new Exception('Bekleyen başvuru verisi çözülemedi (#' . $bekleyen['bekleyen_id'] . ').');
        }

        $basvuruId = $servis->kaydet($form, $veri, (int)$bekleyen['bekleyen_smslog_id'], ApiFormlar::apiBaglam($api, $bekleyen['bekleyen_id'], [
            'ip'       => $bekleyen['bekleyen_musteri_ip'],
            'tarayici' => $bekleyen['bekleyen_musteri_tarayici'],
        ]));
        $api->logBasvuru($basvuruId);   // bildirim (onaylandi / yenilendi) kaydet() içinde kuyruğa yazılır

        return Api::durum(['basvuru_id' => $basvuruId, 'durum' => 'onaylandi'], 201);
    }

    /** POST /api/basvuru/kod-tekrar  {"dogrulama_id"} */
    public static function kodTekrar(Api $api, array $p)
    {
        [$bekleyen, $form] = self::bekleyenBul($api, $api->govde());
        $otp = FormBasvuru::otpAyarlari($form['firma_id']);

        $son = Database::getInstance()->fetchOne("
            SELECT CONVERT(VARCHAR(19), smslog_gonderim_tarihi, 120) AS tarih FROM dbo.SmsLog WHERE smslog_id = ?
        ", [(int)$bekleyen['bekleyen_smslog_id']]);
        $gecen = $son ? time() - strtotime($son['tarih']) : PHP_INT_MAX;
        if ($gecen < $otp['tekrar_sn']) {
            $kalan = $otp['tekrar_sn'] - $gecen;
            throw new ApiHata('COK_FAZLA_ISTEK', "Yeni kod $kalan saniye sonra istenebilir.", ['bekleme_sn' => $kalan], ['Retry-After' => (string)$kalan]);
        }

        $sonuc = (new FormBasvuru())->kodGonder($form, $bekleyen['bekleyen_telefon'], ['anahtar_id' => $api->anahtarId(), 'tekrar' => true]);
        if (empty($sonuc['success'])) {
            throw new ApiHata(($sonuc['kod'] ?? '') === 'COK_FAZLA_ISTEK' ? 'COK_FAZLA_ISTEK' : 'SMS_GONDERILEMEDI', $sonuc['message']);
        }
        Database::getInstance()->execute("
            UPDATE dbo.Api_Bekleyen_Basvurular SET bekleyen_smslog_id = ?, GuncellemeTarihi = GETDATE() WHERE bekleyen_id = ?
        ", [(int)$sonuc['smslog_id'], (int)$bekleyen['bekleyen_id']]);

        return [
            'telefon'       => FormBasvuru::telefonMaskele($bekleyen['bekleyen_telefon']),
            'gecerlilik_sn' => (int)$otp['gecerlilik_sn'],
            'tekrar_sn'     => (int)$otp['tekrar_sn'],
        ];
    }

    /** Firmaya ait, süresi geçmemiş, tamamlanmamış bekleyen başvuru + formu */
    private static function bekleyenBul(Api $api, array $govde)
    {
        $id = trim((string)($govde['dogrulama_id'] ?? ''));
        if (!preg_match(self::DOGRULAMA_DESENI, $id)) {
            throw new ApiHata('ALAN_HATASI', 'Geçerli bir dogrulama_id gönderilmedi.', ['alanlar' => ['dogrulama_id' => 'Başvuru yanıtındaki değer (dgr_...).']]);
        }
        $b = Database::getInstance()->fetchOne("
            SELECT b.bekleyen_id, b.bekleyen_dogrulama_hash, b.bekleyen_form_id, b.bekleyen_smslog_id, b.bekleyen_telefon,
                   b.bekleyen_veri, b.bekleyen_musteri_ip, b.bekleyen_musteri_tarayici, b.bekleyen_basvuru_id,
                   CASE WHEN b.bekleyen_gecerlilik < GETDATE() THEN 1 ELSE 0 END AS suresi_doldu,
                   f.form_uniqid
            FROM dbo.Api_Bekleyen_Basvurular b
            INNER JOIN dbo.Api_Anahtarlari a ON a.api_anahtar_id = b.bekleyen_anahtar_id
            INNER JOIN dbo.Formlar f ON f.form_id = b.bekleyen_form_id
            WHERE b.bekleyen_dogrulama_hash = ? AND a.api_anahtar_firma_id = ? AND b.Durum = 1
        ", [hash('sha256', $id), $api->firmaId()]);

        if (!$b) {
            throw new ApiHata('KOD_GECERSIZ', 'Doğrulama bulunamadı. Başvuruyu yeniden gönderin.');
        }
        if ($b['bekleyen_basvuru_id'] !== null) {
            throw new ApiHata('KOD_GECERSIZ', 'Bu başvuru zaten tamamlandı.', ['basvuru_id' => (int)$b['bekleyen_basvuru_id']]);
        }
        if ((int)$b['suresi_doldu'] === 1 || $b['bekleyen_smslog_id'] === null || $b['bekleyen_veri'] === null) {
            throw new ApiHata('KOD_GECERSIZ', 'Başvurunun süresi doldu. Başvuruyu yeniden gönderin.');
        }
        return [$b, ApiFormlar::formBul($api, $b['form_uniqid'])];
    }
}
