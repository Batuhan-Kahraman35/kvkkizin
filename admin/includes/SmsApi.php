<?php
/**
 * SMS API Sürücüsü
 *
 * SmsOperatorleri tablosundaki uç nokta tanımlarını (JSON) okuyup
 * SmsHesaplari tablosundaki kimlik bilgileriyle istek atar.
 * Uç nokta yolları, metotları ve gövde şablonları koda gömülü değildir.
 */

class SmsApi {

    /**
     * Hesabı operatör bilgileriyle birlikte getir
     */
    public static function hesapGetir($hesapId) {
        $db = Database::getInstance();
        return $db->fetchOne("
            SELECT
                h.*,
                o.sms_operator_adi,
                o.sms_operator_kod,
                o.sms_operator_surucu,
                o.sms_operator_protokol,
                o.sms_operator_host,
                o.sms_operator_port,
                o.sms_operator_yetki_tipi,
                o.sms_operator_icerik_tipi,
                o.sms_operator_zaman_asimi,
                o.sms_operator_vars_encoding,
                o.sms_operator_vars_gecerlilik,
                o.sms_operator_gonderici_zorunlu,
                o.sms_operator_uc_noktalar,
                o.Durum as operator_durum
            FROM SmsHesaplari h
            INNER JOIN SmsOperatorleri o ON h.sms_hesap_operator_id = o.sms_operator_id
            WHERE h.sms_hesap_id = ?
        ", [(int)$hesapId]);
    }

    /**
     * Firmanın varsayılan (yoksa ilk aktif) hesabını getir
     */
    public static function firmaHesabi($firmaId) {
        $db = Database::getInstance();
        $row = $db->fetchOne("
            SELECT TOP 1 sms_hesap_id
            FROM SmsHesaplari
            WHERE sms_hesap_firma_id = ? AND Durum = 1
            ORDER BY sms_hesap_varsayilan DESC, sms_hesap_id
        ", [(int)$firmaId]);

        return $row ? self::hesapGetir($row['sms_hesap_id']) : null;
    }

    /**
     * Operatörün uç nokta tanımını çöz
     */
    public static function ucNokta($hesap, $kod) {
        $tanimlar = json_decode($hesap['sms_operator_uc_noktalar'] ?? '{}', true) ?: [];
        return $tanimlar[$kod] ?? null;
    }

    /**
     * Operatörün taban adresi
     */
    public static function tabanAdres($hesap) {
        $adres = ($hesap['sms_operator_protokol'] ?: 'http') . '://' . $hesap['sms_operator_host'];
        if (!empty($hesap['sms_operator_port'])) {
            $adres .= ':' . (int)$hesap['sms_operator_port'];
        }
        return $adres;
    }

    /**
     * Şablondaki {yer_tutucu} değerlerini doldur
     */
    private static function sablonDoldur($deger, array $degiskenler) {
        if (is_array($deger)) {
            $sonuc = [];
            foreach ($deger as $k => $v) {
                $sonuc[$k] = self::sablonDoldur($v, $degiskenler);
            }
            return $sonuc;
        }

        if (!is_string($deger)) {
            return $deger;
        }

        // Tam eşleşme: değeri olduğu gibi (dizi/sayı/null) yerleştir
        if (preg_match('/^\{([a-z_]+)\}$/', $deger, $m)) {
            if (!array_key_exists($m[1], $degiskenler)) {
                return null;
            }
            return $degiskenler[$m[1]];
        }

        // Kısmi eşleşme: metin içine göm
        return preg_replace_callback('/\{([a-z_]+)\}/', function ($m) use ($degiskenler) {
            return array_key_exists($m[1], $degiskenler) ? (string)$degiskenler[$m[1]] : '';
        }, $deger);
    }

    /**
     * Uç noktaya istek at
     *
     * @return array ['success' => bool, 'http_kod' => int, 'data' => mixed, 'ham' => string, 'message' => string]
     */
    public static function istek($hesap, $ucNoktaKod, array $degiskenler = [], array $ekGovde = []) {
        if (!$hesap) {
            return ['success' => false, 'http_kod' => 0, 'data' => null, 'ham' => '', 'message' => 'Hesap bulunamadı'];
        }

        $tanim = self::ucNokta($hesap, $ucNoktaKod);
        if (!$tanim || empty($tanim['yol'])) {
            return ['success' => false, 'http_kod' => 0, 'data' => null, 'ham' => '', 'message' => 'Uç nokta tanımlı değil: ' . $ucNoktaKod];
        }

        $metot = strtoupper($tanim['metot'] ?? 'POST');
        $url   = self::tabanAdres($hesap) . $tanim['yol'];

        $govde = null;
        if ($metot !== 'GET') {
            $sablon = $tanim['govde'] ?? [];
            $govde  = self::sablonDoldur($sablon, $degiskenler);
            if (!is_array($govde)) {
                $govde = [];
            }
            foreach ($ekGovde as $k => $v) {
                $govde[$k] = $v;
            }
        }

        $ch = curl_init($url);
        $basliklar = ['Accept: application/json'];
        if ($metot !== 'GET') {
            $basliklar[] = 'Content-Type: ' . ($hesap['sms_operator_icerik_tipi'] ?: 'application/json');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $metot,
            CURLOPT_HTTPHEADER     => $basliklar,
            CURLOPT_TIMEOUT        => (int)($hesap['sms_operator_zaman_asimi'] ?: 30),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT      => 'OrnekYazilim-SmsApi/1.0',
        ]);

        if (($hesap['sms_operator_yetki_tipi'] ?: 'basic') === 'basic') {
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            curl_setopt($ch, CURLOPT_USERPWD, $hesap['sms_hesap_kullanici_adi'] . ':' . $hesap['sms_hesap_sifre']);
        }

        if ($metot !== 'GET' && $govde !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($govde, JSON_UNESCAPED_UNICODE));
        }

        $ham     = curl_exec($ch);
        $httpKod = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hata    = curl_error($ch);
        curl_close($ch);

        if ($ham === false) {
            return ['success' => false, 'http_kod' => 0, 'data' => null, 'ham' => '', 'message' => 'Bağlantı hatası: ' . $hata];
        }

        $data = json_decode($ham, true);
        $basarili = ($httpKod >= 200 && $httpKod < 300);
        $mesaj = $basarili ? 'İstek başarılı' : ('HTTP ' . $httpKod . ' yanıtı alındı');

        // Operatör HTTP 200 dönüp gövdede hata bildirebilir (ör. ERR_INVALID_SMS_SENDER)
        $govdeHatasi = self::govdeHatasi($data);
        if ($govdeHatasi !== null) {
            $basarili = false;
            $mesaj = $govdeHatasi;
        }

        return [
            'success'  => $basarili,
            'http_kod' => $httpKod,
            'data'     => $data === null ? $ham : $data,
            'ham'      => $ham,
            'message'  => $mesaj,
        ];
    }

    /**
     * Yanıt gövdesindeki hata bildirimini metne çevirir (yoksa null)
     */
    private static function govdeHatasi($data) {
        if (!is_array($data)) {
            return null;
        }

        foreach (['err', 'error', 'hata'] as $anahtar) {
            if (empty($data[$anahtar])) {
                continue;
            }

            $hata = $data[$anahtar];
            if (is_string($hata)) {
                return $hata;
            }

            if (is_array($hata)) {
                $kod  = $hata['code'] ?? $hata['kod'] ?? '';
                $aciklama = $hata['message'] ?? $hata['mesaj'] ?? '';
                $metin = trim($kod . ($kod && $aciklama && $kod !== $aciklama ? ' - ' : '') . ($kod === $aciklama ? '' : $aciklama));
                return $metin !== '' ? $metin : 'Operatör hata döndürdü';
            }
        }

        return null;
    }

    /**
     * Kredi sorgula (bağlantı testi olarak da kullanılır)
     */
    public static function kredi($hesap) {
        $sonuc = self::istek($hesap, 'kredi');
        $sonuc['kredi'] = self::krediAyikla($sonuc['data']);
        return $sonuc;
    }

    /**
     * Yanıttan sayısal kredi değerini bul
     */
    private static function krediAyikla($data) {
        if (is_numeric($data)) {
            return (float)$data;
        }
        if (!is_array($data)) {
            return null;
        }

        $anahtarlar = ['credit', 'kredi', 'balance', 'amount', 'value'];
        foreach ($anahtarlar as $anahtar) {
            foreach ($data as $k => $v) {
                if (strcasecmp((string)$k, $anahtar) === 0 && is_numeric($v)) {
                    return (float)$v;
                }
            }
        }

        foreach ($data as $v) {
            if (is_array($v)) {
                $bulunan = self::krediAyikla($v);
                if ($bulunan !== null) {
                    return $bulunan;
                }
            }
        }

        return null;
    }

    /**
     * Gönderici başlıklarını listele
     */
    public static function gondericiListesi($hesap) {
        $sonuc = self::istek($hesap, 'gonderici_liste');
        $sonuc['gondericiler'] = self::gondericiAyikla($sonuc['data']);
        $sonuc['gondericiDetay'] = self::gondericiDetayAyikla($sonuc['data']);
        return $sonuc;
    }

    /**
     * Yanıttaki başlık nesnelerinden onay bilgisini çıkarır.
     * Operatör 'gateways' alanı döndürüyorsa boş olması GSM operatörü tanımı
     * olmadığı (gönderime kapalı) anlamına gelir; alan yoksa onay bilinmez (null).
     *
     * @return array [['ad' => string, 'onayli' => bool|null, 'durum' => mixed], ...]
     */
    private static function gondericiDetayAyikla($data, $derinlik = 0) {
        if (!is_array($data) || $derinlik > 5) {
            return [];
        }

        $bulunan = [];
        $adAnahtarlari = ['sender', 'name', 'title', 'baslik', 'gonderici'];

        foreach ($data as $v) {
            if (!is_array($v)) {
                continue;
            }

            $ad = null;
            foreach ($v as $k => $deger) {
                if (is_string($deger) && in_array(strtolower((string)$k), $adAnahtarlari, true)) {
                    $ad = $deger;
                    break;
                }
            }

            if ($ad !== null) {
                $bulunan[$ad] = [
                    'ad'     => $ad,
                    'onayli' => array_key_exists('gateways', $v) ? !empty($v['gateways']) : null,
                    'durum'  => $v['status'] ?? null,
                ];
            } else {
                foreach (self::gondericiDetayAyikla($v, $derinlik + 1) as $d) {
                    $bulunan[$d['ad']] = $d;
                }
            }
        }

        return array_values($bulunan);
    }

    /**
     * Hesabın gönderici başlığını operatördeki listeyle karşılaştırır.
     * Gönderim hatasında kullanıcıya anlaşılır açıklama üretmek için kullanılır.
     *
     * @return string|null Sorun varsa açıklama, yoksa/bilinemiyorsa null
     */
    public static function gondericiSorunu($hesap) {
        $baslik = (string)($hesap['sms_hesap_gonderici'] ?? '');
        if ($baslik === '') {
            return null;
        }

        $liste = self::gondericiListesi($hesap);
        if (empty($liste['success']) || empty($liste['gondericiDetay'])) {
            return null;
        }

        foreach ($liste['gondericiDetay'] as $d) {
            if ($d['ad'] === $baslik) {
                return $d['onayli'] === false
                    ? "Gönderici başlığı '$baslik' operatörde tanımlı ama GSM operatörlerine bağlanmamış (onaysız). Operatörden başlık onayını tamamlamasını isteyin."
                    : null;
            }
        }

        return "Gönderici başlığı '$baslik' operatörün başlık listesinde yok. Doğru başlığı operatörden çekip seçin.";
    }

    /**
     * Yanıttan gönderici başlıklarını topla
     */
    private static function gondericiAyikla($data, $derinlik = 0) {
        if (!is_array($data) || $derinlik > 5) {
            return [];
        }

        $bulunan = [];
        foreach ($data as $k => $v) {
            if (is_string($v) && in_array(strtolower((string)$k), ['sender', 'name', 'title', 'baslik', 'gonderici'], true)) {
                $bulunan[] = $v;
            } elseif (is_array($v)) {
                $bulunan = array_merge($bulunan, self::gondericiAyikla($v, $derinlik + 1));
            }
        }

        return array_values(array_unique($bulunan));
    }

    /**
     * Gönderim kaydını SmsLog tablosuna yazar.
     * OTP gönderimlerinde $ek ile kod hash'i, geçerlilik ve amaç aktarılır.
     *
     * @return int Eklenen kaydın id'si (0 = yazılamadı)
     */
    public static function logKaydet($hesap, $telefon, $mesaj, array $sonuc, array $ek = []) {
        try {
            $db = Database::getInstance();
            $simdi = date('Y-m-d H:i:s');

            $veri = array_merge([
                'smslog_hesap_id'         => $hesap['sms_hesap_id'] ?? null,
                'smslog_firma_id'         => $hesap['sms_hesap_firma_id'] ?? null,
                'smslog_kullanici_id'     => null,
                'smslog_amac'             => 'bilgilendirme',
                'smslog_telefon'          => preg_replace('/\D/', '', (string)$telefon),
                'smslog_gonderici'        => $hesap['sms_hesap_gonderici'] ?? null,
                'smslog_mesaj'            => $mesaj,
                'smslog_gonderim_tarihi'  => $simdi,
                'smslog_basarili'         => !empty($sonuc['success']) ? 1 : 0,
                'smslog_http_kod'         => $sonuc['http_kod'] ?? null,
                'smslog_hata'             => !empty($sonuc['success']) ? null : mb_substr((string)($sonuc['message'] ?? ''), 0, 500, 'UTF-8'),
                'smslog_yanit'            => isset($sonuc['ham']) ? mb_substr((string)$sonuc['ham'], 0, 4000, 'UTF-8') : null,
                'smslog_ip'               => $_SERVER['REMOTE_ADDR'] ?? null,
                'OlusturanKullanici'      => $ek['smslog_kullanici_id'] ?? 0,
                'OlusturmaTarihi'         => $simdi,
                'GuncelleyenKullanici'    => $ek['smslog_kullanici_id'] ?? 0,
                'GuncellemeTarihi'        => $simdi,
                'Durum'                   => 1,
            ], $ek);

            return (int)$db->insert('SmsLog', $veri);
        } catch (Throwable $e) {
            error_log('SmsLog kaydı yazılamadı: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Tekil SMS gönder
     */
    public static function gonder($hesap, $numara, $icerik, $baslik = null, array $logEk = []) {
        $degiskenler = [
            'numara'    => preg_replace('/\D/', '', (string)$numara),
            'icerik'    => $icerik,
            'baslik'    => $baslik ?: mb_substr($icerik, 0, 50, 'UTF-8'),
            'gonderici' => $hesap['sms_hesap_gonderici'],
        ];

        $ekGovde = [
            'encoding' => (int)($hesap['sms_hesap_encoding'] ?? $hesap['sms_operator_vars_encoding']),
            'validity' => (int)($hesap['sms_hesap_gecerlilik'] ?? $hesap['sms_operator_vars_gecerlilik']),
        ];

        if (!empty($hesap['sms_hesap_webhook_url'])) {
            $ekGovde['pushSettings'] = ['url' => $hesap['sms_hesap_webhook_url']];
        }
        if (!empty($hesap['sms_hesap_ticari'])) {
            $ekGovde['commercial'] = true;
        }

        $sonuc = self::istek($hesap, 'gonder', $degiskenler, $ekGovde);
        $sonuc['log_id'] = self::logKaydet($hesap, $numara, $icerik, $sonuc, $logEk);

        return $sonuc;
    }

    /**
     * OTP (doğrulama kodu) gönder
     *
     * $logEk ile SmsLog kaydına kod hash'i, geçerlilik ve amaç eklenebilir.
     */
    public static function otpGonder($hesap, $numara, $icerik, array $logEk = []) {
        // Hesapta veya operatörde OTP servisi kapalıysa normal gönderim uç noktası kullanılır
        $otpKapali = (isset($hesap['sms_hesap_otp_kullan']) && !$hesap['sms_hesap_otp_kullan'])
            || (isset($hesap['sms_operator_otp_destek']) && !$hesap['sms_operator_otp_destek']);

        if ($otpKapali) {
            return self::gonder($hesap, $numara, $icerik, 'Dogrulama', array_merge(['smslog_amac' => 'dogrulama'], $logEk));
        }

        $degiskenler = [
            'numara'    => preg_replace('/\D/', '', (string)$numara),
            'icerik'    => $icerik,
            'gonderici' => $hesap['sms_hesap_gonderici'],
        ];

        $ekGovde = [
            'encoding' => (int)($hesap['sms_hesap_encoding'] ?? $hesap['sms_operator_vars_encoding']),
        ];

        if (!empty($hesap['sms_hesap_webhook_url'])) {
            $ekGovde['pushSettings'] = ['url' => $hesap['sms_hesap_webhook_url']];
        }

        $sonuc = self::istek($hesap, 'otp_gonder', $degiskenler, $ekGovde);
        $sonuc['log_id'] = self::logKaydet($hesap, $numara, $icerik, $sonuc, array_merge(['smslog_amac' => 'dogrulama'], $logEk));

        // Gönderici başlığı OTP servisinde tanımlı değilse (ERR_INVALID_SMS_SENDER) normal gönderimle tekrar denenir;
        // başarısız OTP kaydındaki kod geçersiz sayılır, doğrulama yeni kayıt üzerinden yapılır.
        if (empty($sonuc['success']) && stripos((string)($sonuc['message'] ?? '') . (string)($sonuc['ham'] ?? ''), 'INVALID_SMS_SENDER') !== false) {
            if (!empty($sonuc['log_id'])) {
                Database::getInstance()->execute("UPDATE SmsLog SET smslog_kod_kullanildi = 1, GuncellemeTarihi = GETDATE() WHERE smslog_id = ?", [$sonuc['log_id']]);
            }
            return self::gonder($hesap, $numara, $icerik, 'Dogrulama', array_merge(['smslog_amac' => 'dogrulama'], $logEk));
        }

        return $sonuc;
    }

    /**
     * Test sonucunu hesaba yaz
     */
    public static function testKaydet($hesapId, $basarili, $mesaj, $kredi = null) {
        $db = Database::getInstance();

        $data = [
            'sms_hesap_son_test_durum'  => $basarili ? 1 : 0,
            'sms_hesap_son_test_tarihi' => date('Y-m-d H:i:s'),
            'sms_hesap_son_test_mesaj'  => mb_substr((string)$mesaj, 0, 500, 'UTF-8'),
        ];

        if ($kredi !== null) {
            $data['sms_hesap_kredi'] = $kredi;
            $data['sms_hesap_kredi_tarihi'] = date('Y-m-d H:i:s');
        }

        return $db->update('SmsHesaplari', $data, ['sms_hesap_id' => (int)$hesapId]);
    }
}
