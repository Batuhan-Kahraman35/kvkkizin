<?php
/**
 * Kimlik Doğrulama Fonksiyonları
 * Portal Örnek Yazılım
 */

// session_start() oncesi DB erisimi gerekir: oturum omru tanim_site_ayarlari'ndan okunur
require_once __DIR__ . '/db.php';

/**
 * Oturum timeout suresi (saniye)
 * Kaynak: dbo.tanim_site_ayarlari.site_ayarlari_session_timeout_min
 * Ayar kaydi yoksa veya okunamazsa varsayilan 120 dk kullanilir.
 */
function oturumTimeoutSaniye(): int {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $dakika = 120; // varsayilan

    try {
        $row = Database::getInstance()->fetchOne(
            "SELECT TOP 1 site_ayarlari_session_timeout_min
             FROM dbo.tanim_site_ayarlari
             ORDER BY site_ayarlari_id DESC"
        );
        if ($row && (int)$row['site_ayarlari_session_timeout_min'] > 0) {
            $dakika = (int)$row['site_ayarlari_session_timeout_min'];
        }
    } catch (Exception $e) {
        // DB erisilemezse varsayilan ile devam et
    }

    $cache = $dakika * 60;
    return $cache;
}

// PHP'nin kendi oturum omru de ayni degere baglanir.
// Aksi halde gc_maxlifetime (varsayilan 1440 sn = 24 dk) uygulama timeout'undan
// once devreye girip oturumu erkenden dusurur.
ini_set('session.gc_maxlifetime', (string)oturumTimeoutSaniye());
session_set_cookie_params(['lifetime' => 0, 'path' => '/']);

session_start();
require_once __DIR__ . '/includes/TelefonHelper.php';
require_once __DIR__ . '/includes/SmsApi.php';
require_once __DIR__ . '/includes/SozlesmeHelper.php';

class Auth {
    private $db;

    /** Oturum sonlanma nedeni (aynı istek içinde requireAuth okur) */
    public static $sonHata = null;

    /** Aktif oturumda firma durumu kaç saniyede bir DB'den kontrol edilsin */
    const FIRMA_KONTROL_ARALIGI = 60;

    /** İki faktörlü doğrulama politikası */
    const IKI_FAKTOR_HANE          = 6;
    const IKI_FAKTOR_GECERLILIK_SN = 180;  // 3 dakika
    const IKI_FAKTOR_MAX_DENEME    = 5;
    const IKI_FAKTOR_TEKRAR_SN     = 60;   // "yeniden gönder" bekleme süresi

    /**
     * Firma erişimi kapalı olan kullanıcıya gösterilen mesaj (neden belirtilmez).
     * İletişim bilgisi site ayarlarından okunur, tanımlı değilse genel metin döner.
     */
    public static function erisimKapaliMesaj() {
        try {
            $ayar = Database::getInstance()->fetchOne("
                SELECT TOP 1 site_ayarlari_iletisim_telefon, site_ayarlari_iletisim_email
                FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC
            ");
        } catch (Throwable $e) {
            $ayar = null;
        }

        $telefon = trim($ayar['site_ayarlari_iletisim_telefon'] ?? '');
        if ($telefon !== '') {
            return 'Giriş yapamıyorsunuz. Lütfen ' . $telefon . ' numaralı hattımızı arayın.';
        }

        $eposta = trim($ayar['site_ayarlari_iletisim_email'] ?? '');
        if ($eposta !== '') {
            return 'Giriş yapamıyorsunuz. Lütfen ' . $eposta . ' adresinden bizimle iletişime geçin.';
        }

        return 'Giriş yapamıyorsunuz. Lütfen yönetici ile iletişime geçin.';
    }

    /**
     * Kullanıcının firma sözleşmesi girişe izin veriyor mu?
     * Administrator departmanı (1) kilitlenmemesi için kontrolden muaftır.
     */
    public static function sozlesmeErisimiVar($db, array $user) {
        if ((int)($user['kullanici_departman_id'] ?? 0) === 1) {
            return true;
        }
        return SozlesmeHelper::erisimVarMi($db, $user['kullanici_firma_id'] ?? 0);
    }

    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    /**
     * Kullanıcı girişi
     */
    public function login($email, $password) {
        // Güvenlik ayarları (site yönetimi)
        $ayar = $this->db->fetchOne("
            SELECT TOP 1 site_ayarlari_login_attempt_limit, site_ayarlari_login_block_duration_min,
                   site_ayarlari_session_timeout_min, site_ayarlari_email_verification_required,
                   site_ayarlari_two_factor_enabled, site_ayarlari_two_factor_sms_hesap_id
            FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC
        ");
        $denemeLimiti  = max(1, (int)($ayar['site_ayarlari_login_attempt_limit'] ?? 5));
        $blokeSuresiDk = max(1, (int)($ayar['site_ayarlari_login_block_duration_min'] ?? 15));
        $timeoutDk     = max(5, (int)($ayar['site_ayarlari_session_timeout_min'] ?? 120));
        $ip            = $_SERVER['REMOTE_ADDR'] ?? '';

        // Bloke kontrolü: bloke penceresi içinde, son başarılı girişten sonraki
        // başarısız denemeler (aynı e-posta VEYA aynı IP) limiti aştıysa engelle
        $basarisizAdet = (int)($this->db->fetchOne("
            SELECT COUNT(*) AS adet
            FROM KullanicilarLog l
            INNER JOIN tanim_KullanicilarLogTipleri t ON t.KullanicilarLogTipleri_Id = l.KullanicilarLog_TipId
            WHERE t.KullanicilarLogTipleri_Kod = 'giris_basarisiz'
              AND l.OlusturmaTarihi > DATEADD(MINUTE, -?, GETDATE())
              AND (l.KullanicilarLog_Email = ? OR l.KullanicilarLog_IP = ?)
              AND l.OlusturmaTarihi > ISNULL((
                  SELECT MAX(l2.OlusturmaTarihi)
                  FROM KullanicilarLog l2
                  INNER JOIN tanim_KullanicilarLogTipleri t2 ON t2.KullanicilarLogTipleri_Id = l2.KullanicilarLog_TipId
                  WHERE t2.KullanicilarLogTipleri_Kod = 'giris_basarili'
                    AND l2.KullanicilarLog_Email = ?
              ), '1900-01-01')
        ", [$blokeSuresiDk, $email, $ip, $email])['adet'] ?? 0);

        if ($basarisizAdet >= $denemeLimiti) {
            return [
                'success' => false,
                'message' => "Çok fazla başarısız deneme! Lütfen $blokeSuresiDk dakika sonra tekrar deneyin."
            ];
        }

        $sql = "SELECT k.*, f.firma_adi, f.firma_durum
                FROM kullanicilar k
                LEFT JOIN Firmalar f ON f.firma_id = k.kullanici_firma_id
                WHERE k.kullanici_email = ? AND k.kullanici_durum = 1";
        $user = $this->db->fetchOne($sql, [$email]);

        if (!$user) {
            self::kullaniciLogKaydet($this->db, 'giris_basarisiz', null, $email, 'E-posta bulunamadı veya pasif');
            return [
                'success' => false,
                'message' => 'E-posta veya şifre hatalı!'
            ];
        }

        if (!password_verify($password, $user['kullanici_sifre_hash'])) {
            self::kullaniciLogKaydet($this->db, 'giris_basarisiz', $user['kullanici_id'], $email, 'Hatalı şifre');
            return [
                'success' => false,
                'message' => 'E-posta veya şifre hatalı!'
            ];
        }

        // Firma durum kontrolü: kullanıcı bir firmaya bağlı ve firma aktif olmalı
        if (empty($user['kullanici_firma_id'])) {
            self::kullaniciLogKaydet($this->db, 'giris_basarisiz', $user['kullanici_id'], $email, 'Kullanıcı hiçbir firmaya bağlı değil');
            return [
                'success' => false,
                'message' => self::erisimKapaliMesaj()
            ];
        }

        if ((int)($user['firma_durum'] ?? 0) !== 1) {
            self::kullaniciLogKaydet($this->db, 'giris_basarisiz', $user['kullanici_id'], $email, 'Bağlı firma pasif (Firma ID: ' . $user['kullanici_firma_id'] . ')');
            return [
                'success' => false,
                'message' => self::erisimKapaliMesaj()
            ];
        }

        // Sözleşme kontrolü: firma (veya üst firma zinciri) sözleşmesi bitmişse giriş kapalı
        if (!self::sozlesmeErisimiVar($this->db, $user)) {
            self::kullaniciLogKaydet($this->db, 'giris_basarisiz', $user['kullanici_id'], $email, 'Firma sözleşmesi geçerli değil (Firma ID: ' . $user['kullanici_firma_id'] . ')');
            return [
                'success' => false,
                'message' => self::erisimKapaliMesaj()
            ];
        }

        // E-posta doğrulama kontrolü (site ayarı aktifse)
        if ($ayar && (int)($ayar['site_ayarlari_email_verification_required'] ?? 0) === 1
            && (int)($user['kullanici_email_dogrulandi'] ?? 0) !== 1) {
            self::kullaniciLogKaydet($this->db, 'giris_basarisiz', $user['kullanici_id'], $email, 'E-posta doğrulanmamış');
            return [
                'success' => false,
                'message' => 'E-posta adresiniz henüz doğrulanmamış! Lütfen e-postanıza gönderilen doğrulama bağlantısına tıklayın.'
            ];
        }

        // İki faktörlü doğrulama aktifse oturum henüz açılmaz; SMS kodu istenir
        if ($ayar && (int)($ayar['site_ayarlari_two_factor_enabled'] ?? 0) === 1) {
            $gonderim = self::ikiFaktorKodGonder(
                $this->db,
                $user,
                (int)($ayar['site_ayarlari_two_factor_sms_hesap_id'] ?? 0)
            );

            if (!$gonderim['success']) {
                // Gerçek neden yalnızca log'a yazılır, kullanıcıya genel mesaj gösterilir
                self::kullaniciLogKaydet($this->db, 'giris_basarisiz', $user['kullanici_id'], $email, '2FA: ' . $gonderim['log']);
                return [
                    'success' => false,
                    'message' => self::erisimKapaliMesaj()
                ];
            }

            $_SESSION['2fa_bekleyen'] = [
                'kullanici_id' => (int)$user['kullanici_id'],
                'email'        => $user['kullanici_email'],
                'dogrulama_id' => $gonderim['dogrulama_id'],
                'telefon_mask' => $gonderim['telefon_mask'],
                'timeout_dk'   => $timeoutDk,
                'gonderim'     => time(),
                'baslangic'    => time(),
            ];

            self::kullaniciLogKaydet($this->db, '2fa_gonderildi', $user['kullanici_id'], $email, 'Kod gönderildi: ' . $gonderim['telefon_mask']);

            return [
                'success'     => false,
                'iki_faktor'  => true,
                'message'     => 'Doğrulama kodu telefonunuza gönderildi.'
            ];
        }

        self::oturumBaslat($this->db, $user, $timeoutDk);

        return [
            'success' => true,
            'message' => 'Giriş başarılı!',
            'user' => [
                'id' => $user['kullanici_id'],
                'email' => $user['kullanici_email'],
                'name' => $user['kullanici_ad'] . ' ' . $user['kullanici_soyad']
            ]
        ];
    }

    /**
     * Oturumu açar (şifre ve — aktifse — 2FA doğrulandıktan sonra çağrılır)
     */
    public static function oturumBaslat($db, array $user, int $timeoutDk) {
        $db->execute("UPDATE kullanicilar SET kullanici_son_giris_tarihi = GETDATE() WHERE kullanici_id = ?", [$user['kullanici_id']]);

        $_SESSION['user_id'] = $user['kullanici_id'];
        $_SESSION['user_email'] = $user['kullanici_email'];
        $_SESSION['user_name'] = $user['kullanici_ad'] . ' ' . $user['kullanici_soyad'];
        $_SESSION['user_departman_id'] = $user['kullanici_departman_id'];
        $_SESSION['user_firma_id'] = $user['kullanici_firma_id'] ?? null;
        $_SESSION['logged_in'] = true;
        $_SESSION['login_time'] = time();
        $_SESSION['timeout_saniye'] = $timeoutDk * 60; // Oturum timeout (site ayarı)
        $_SESSION['sifre_degistirmeli'] = $user['kullanici_sifre_degistirmeli'] ?? 0;
        $_SESSION['firma_aktif'] = 1;
        $_SESSION['firma_kontrol_zamani'] = time();

        unset($_SESSION['2fa_bekleyen']);

        self::kullaniciLogKaydet($db, 'giris_basarili', $user['kullanici_id'], $user['kullanici_email']);
    }

    /**
     * 2FA doğrulama kodu üretir ve site ayarında seçili SMS hesabıyla gönderir.
     *
     * @return array ['success' => bool, 'log' => string, 'dogrulama_id' => int, 'telefon_mask' => string]
     */
    public static function ikiFaktorKodGonder($db, array $user, int $smsHesapId) {
        $telefon = telefonNormalizeEt($user['kullanici_telefon'] ?? '');
        if ($telefon === null || $telefon === '') {
            return ['success' => false, 'log' => 'Kullanıcının telefon numarası tanımlı değil'];
        }

        if ($smsHesapId <= 0) {
            return ['success' => false, 'log' => 'Site ayarlarında 2FA için SMS hesabı seçilmemiş'];
        }

        $hesap = SmsApi::hesapGetir($smsHesapId);
        if (!$hesap || (int)$hesap['Durum'] !== 1 || (int)$hesap['operator_durum'] !== 1) {
            return ['success' => false, 'log' => 'Seçili SMS hesabı veya operatörü pasif (Hesap ID: ' . $smsHesapId . ')'];
        }

        // Önceki bekleyen kodlar geçersizleşir
        $db->execute("
            UPDATE SmsLog SET smslog_kod_kullanildi = 1, GuncellemeTarihi = GETDATE()
            WHERE smslog_kullanici_id = ? AND smslog_amac = 'giris_dogrulama' AND smslog_kod_kullanildi = 0
        ", [$user['kullanici_id']]);

        $kod = str_pad((string)random_int(0, (10 ** self::IKI_FAKTOR_HANE) - 1), self::IKI_FAKTOR_HANE, '0', STR_PAD_LEFT);
        $dakika = (int)ceil(self::IKI_FAKTOR_GECERLILIK_SN / 60);
        $mesaj  = 'Giris dogrulama kodunuz: ' . $kod . ' (' . $dakika . ' dakika gecerlidir)';

        // Gönderim ve doğrulama kaydı tek SmsLog satırında tutulur
        $sonuc = SmsApi::otpGonder($hesap, $telefon, $mesaj, [
            'smslog_kullanici_id' => (int)$user['kullanici_id'],
            'smslog_amac'         => 'giris_dogrulama',
            'smslog_kod_hash'     => password_hash($kod, PASSWORD_DEFAULT),
            'smslog_gecerlilik'   => date('Y-m-d H:i:s', time() + self::IKI_FAKTOR_GECERLILIK_SN),
        ]);

        if (!$sonuc['success']) {
            return ['success' => false, 'log' => 'SMS gönderilemedi: ' . $sonuc['message']];
        }

        if (empty($sonuc['log_id'])) {
            return ['success' => false, 'log' => 'Doğrulama kaydı yazılamadı (SmsLog)'];
        }

        return [
            'success'      => true,
            'log'          => 'Kod gönderildi',
            'dogrulama_id' => (int)$sonuc['log_id'],
            'telefon_mask' => self::telefonMaskele($telefon),
        ];
    }

    /**
     * Girilen kodu doğrular; başarılıysa oturumu açar.
     *
     * @return array ['success' => bool, 'message' => string, 'bitti' => bool]
     *   bitti = true ise doğrulama oturumu sonlandı, giriş ekranına dönülmeli
     */
    public static function ikiFaktorDogrula($db, string $girilenKod) {
        $bekleyen = $_SESSION['2fa_bekleyen'] ?? null;
        if (!$bekleyen) {
            return ['success' => false, 'bitti' => true, 'message' => 'Doğrulama oturumu bulunamadı. Lütfen tekrar giriş yapın.'];
        }

        $kayit = $db->fetchOne("
            SELECT * FROM SmsLog
            WHERE smslog_id = ? AND smslog_kullanici_id = ? AND smslog_amac = 'giris_dogrulama'
        ", [$bekleyen['dogrulama_id'], $bekleyen['kullanici_id']]);

        if (!$kayit || (int)$kayit['smslog_kod_kullanildi'] === 1 || empty($kayit['smslog_kod_hash'])) {
            unset($_SESSION['2fa_bekleyen']);
            return ['success' => false, 'bitti' => true, 'message' => 'Doğrulama kodu geçersiz. Lütfen tekrar giriş yapın.'];
        }

        if (strtotime($kayit['smslog_gecerlilik']) < time()) {
            $db->execute("UPDATE SmsLog SET smslog_kod_kullanildi = 1, GuncellemeTarihi = GETDATE() WHERE smslog_id = ?", [$kayit['smslog_id']]);
            unset($_SESSION['2fa_bekleyen']);
            self::kullaniciLogKaydet($db, '2fa_basarisiz', $bekleyen['kullanici_id'], $bekleyen['email'], 'Kodun süresi doldu');
            return ['success' => false, 'bitti' => true, 'message' => 'Kodun süresi doldu. Lütfen tekrar giriş yapın.'];
        }

        $deneme = (int)$kayit['smslog_deneme'] + 1;

        if (!password_verify($girilenKod, $kayit['smslog_kod_hash'])) {
            $tukendi = $deneme >= self::IKI_FAKTOR_MAX_DENEME;

            $db->execute("
                UPDATE SmsLog
                SET smslog_deneme = ?, smslog_kod_kullanildi = ?, GuncellemeTarihi = GETDATE()
                WHERE smslog_id = ?
            ", [$deneme, $tukendi ? 1 : 0, $kayit['smslog_id']]);

            self::kullaniciLogKaydet($db, '2fa_basarisiz', $bekleyen['kullanici_id'], $bekleyen['email'], 'Hatalı kod (deneme ' . $deneme . '/' . self::IKI_FAKTOR_MAX_DENEME . ')');

            if ($tukendi) {
                unset($_SESSION['2fa_bekleyen']);
                return ['success' => false, 'bitti' => true, 'message' => 'Deneme hakkınız doldu. Lütfen tekrar giriş yapın.'];
            }

            $kalan = self::IKI_FAKTOR_MAX_DENEME - $deneme;
            return ['success' => false, 'bitti' => false, 'message' => 'Kod hatalı! Kalan deneme hakkı: ' . $kalan];
        }

        // Kod doğru — kullanıcı ve firma durumu yeniden denetlenir
        $user = $db->fetchOne("
            SELECT k.*, f.firma_durum
            FROM kullanicilar k
            LEFT JOIN Firmalar f ON f.firma_id = k.kullanici_firma_id
            WHERE k.kullanici_id = ? AND k.kullanici_durum = 1
        ", [$bekleyen['kullanici_id']]);

        $db->execute("
            UPDATE SmsLog
            SET smslog_deneme = ?, smslog_kod_kullanildi = 1, smslog_dogrulandi = 1,
                smslog_dogrulama_tarihi = GETDATE(), GuncellemeTarihi = GETDATE()
            WHERE smslog_id = ?
        ", [$deneme, $kayit['smslog_id']]);

        if (!$user || empty($user['kullanici_firma_id']) || (int)($user['firma_durum'] ?? 0) !== 1) {
            unset($_SESSION['2fa_bekleyen']);
            self::kullaniciLogKaydet($db, 'giris_basarisiz', $bekleyen['kullanici_id'], $bekleyen['email'], '2FA sonrası kullanıcı veya firma pasif');
            return ['success' => false, 'bitti' => true, 'message' => self::erisimKapaliMesaj()];
        }

        if (!self::sozlesmeErisimiVar($db, $user)) {
            unset($_SESSION['2fa_bekleyen']);
            self::kullaniciLogKaydet($db, 'giris_basarisiz', $bekleyen['kullanici_id'], $bekleyen['email'], '2FA sonrası firma sözleşmesi geçerli değil (Firma ID: ' . $user['kullanici_firma_id'] . ')');
            return ['success' => false, 'bitti' => true, 'message' => self::erisimKapaliMesaj()];
        }

        self::kullaniciLogKaydet($db, '2fa_basarili', $user['kullanici_id'], $user['kullanici_email']);
        self::oturumBaslat($db, $user, (int)($bekleyen['timeout_dk'] ?? 120));

        return ['success' => true, 'bitti' => false, 'message' => 'Giriş başarılı!'];
    }

    /**
     * Bekleyen doğrulama için kodu yeniden gönderir (IKI_FAKTOR_TEKRAR_SN bekleme süreli)
     */
    public static function ikiFaktorTekrarGonder($db) {
        $bekleyen = $_SESSION['2fa_bekleyen'] ?? null;
        if (!$bekleyen) {
            return ['success' => false, 'bitti' => true, 'message' => 'Doğrulama oturumu bulunamadı. Lütfen tekrar giriş yapın.'];
        }

        $gecen = time() - (int)($bekleyen['gonderim'] ?? 0);
        if ($gecen < self::IKI_FAKTOR_TEKRAR_SN) {
            return [
                'success' => false,
                'bitti'   => false,
                'message' => 'Yeni kod isteyebilmek için ' . (self::IKI_FAKTOR_TEKRAR_SN - $gecen) . ' saniye beklemelisiniz.'
            ];
        }

        $ayar = $db->fetchOne("
            SELECT TOP 1 site_ayarlari_two_factor_sms_hesap_id
            FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC
        ");

        $user = $db->fetchOne("
            SELECT * FROM kullanicilar WHERE kullanici_id = ? AND kullanici_durum = 1
        ", [$bekleyen['kullanici_id']]);

        if (!$user) {
            unset($_SESSION['2fa_bekleyen']);
            return ['success' => false, 'bitti' => true, 'message' => self::erisimKapaliMesaj()];
        }

        $gonderim = self::ikiFaktorKodGonder($db, $user, (int)($ayar['site_ayarlari_two_factor_sms_hesap_id'] ?? 0));

        if (!$gonderim['success']) {
            self::kullaniciLogKaydet($db, '2fa_basarisiz', $user['kullanici_id'], $user['kullanici_email'], 'Tekrar gönderim başarısız: ' . $gonderim['log']);
            return ['success' => false, 'bitti' => false, 'message' => 'Kod gönderilemedi. Lütfen daha sonra tekrar deneyin.'];
        }

        $_SESSION['2fa_bekleyen']['dogrulama_id'] = $gonderim['dogrulama_id'];
        $_SESSION['2fa_bekleyen']['telefon_mask'] = $gonderim['telefon_mask'];
        $_SESSION['2fa_bekleyen']['gonderim']     = time();

        self::kullaniciLogKaydet($db, '2fa_gonderildi', $user['kullanici_id'], $user['kullanici_email'], 'Kod yeniden gönderildi');

        return ['success' => true, 'bitti' => false, 'message' => 'Yeni doğrulama kodu gönderildi.'];
    }

    /**
     * 905551234567 → 0555 *** 45 67 biçiminde maskeler
     */
    public static function telefonMaskele($telefon) {
        $t = preg_replace('/\D/', '', (string)$telefon);
        if (strlen($t) < 6) {
            return '***';
        }
        return substr($t, 0, 5) . str_repeat('*', max(0, strlen($t) - 7)) . substr($t, -2);
    }

    /**
     * KullanicilarLog tablosuna kayıt atar
     * IP, tarayıcı, cihaz otomatik; PWA ve konum bilgisi login formundaki
     * istemci_pwa / istemci_konum gizli alanlarından okunur.
     */
    public static function kullaniciLogKaydet($db, $tipKod, $kullaniciId, $email, $aciklama = null) {
        try {
            $tip = $db->fetchOne("
                SELECT KullanicilarLogTipleri_Id FROM tanim_KullanicilarLogTipleri
                WHERE KullanicilarLogTipleri_Kod = ? AND Durum = 1
            ", [$tipKod]);
            if (!$tip) return false;

            $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
            $simdi = date('Y-m-d H:i:s');

            $db->insert('KullanicilarLog', [
                'KullanicilarLog_KullaniciId' => $kullaniciId,
                'KullanicilarLog_Email'       => $email !== null && $email !== '' ? mb_substr($email, 0, 255) : null,
                'KullanicilarLog_TipId'       => $tip['KullanicilarLogTipleri_Id'],
                'KullanicilarLog_IP'          => $_SERVER['REMOTE_ADDR'] ?? null,
                'KullanicilarLog_Tarayici'    => $ua !== '' ? mb_substr($ua, 0, 500) : null,
                'KullanicilarLog_Cihaz'       => self::cihazBilgisi($ua),
                'KullanicilarLog_PWA'         => (($_POST['istemci_pwa'] ?? '0') === '1') ? 1 : 0,
                'KullanicilarLog_Konum'       => trim($_POST['istemci_konum'] ?? '') !== '' ? mb_substr(trim($_POST['istemci_konum']), 0, 255) : null,
                'KullanicilarLog_Aciklama'    => $aciklama,
                'OlusturanKullanici'          => $kullaniciId,
                'OlusturmaTarihi'             => $simdi,
                'GuncelleyenKullanici'        => $kullaniciId,
                'GuncellemeTarihi'            => $simdi,
                'Durum'                       => 1,
            ]);
            return true;
        } catch (Exception $e) {
            // Log hatası ana işlemi engellemez
            error_log('KullanicilarLog Hatası: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * User-Agent'tan cihaz tipi + işletim sistemi çıkarır (örn: "Mobil / Android")
     */
    private static function cihazBilgisi($ua) {
        if (!$ua) return null;

        if (preg_match('/Windows NT/i', $ua))            $os = 'Windows';
        elseif (preg_match('/Android/i', $ua))           $os = 'Android';
        elseif (preg_match('/iPhone|iPad|iPod/i', $ua))  $os = 'iOS';
        elseif (preg_match('/Mac OS X/i', $ua))          $os = 'macOS';
        elseif (preg_match('/Linux/i', $ua))             $os = 'Linux';
        else                                             $os = 'Bilinmiyor';

        if (preg_match('/iPad|Tablet/i', $ua))                  $tip = 'Tablet';
        elseif (preg_match('/Mobile|Android|iPhone/i', $ua))    $tip = 'Mobil';
        else                                                    $tip = 'Masaüstü';

        return $tip . ' / ' . $os;
    }
    
    /**
     * Oturum kontrolü
     */
    public static function check() {
        if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
            return false;
        }
        
        // Oturum timeout kontrolü (süre her istekte tanim_site_ayarlari tablosundan okunur)
        $timeoutSaniye = oturumTimeoutSaniye();
        if (isset($_SESSION['login_time']) && (time() - $_SESSION['login_time'] > $timeoutSaniye)) {
            self::logout();
            return false;
        }

        // Firma durum kontrolü: oturum sırasında firma pasife çekilirse oturumu sonlandır
        if (!self::firmaAktifMi()) {
            self::$sonHata = 'firma_pasif';
            self::logout();
            return false;
        }

        return true;
    }

    /**
     * Oturumdaki kullanıcının bağlı olduğu firma hâlâ aktif mi?
     * Sonuç FIRMA_KONTROL_ARALIGI saniye boyunca session'da önbelleklenir.
     */
    private static function firmaAktifMi() {
        $sonKontrol = (int)($_SESSION['firma_kontrol_zamani'] ?? 0);
        if (time() - $sonKontrol < self::FIRMA_KONTROL_ARALIGI) {
            return (int)($_SESSION['firma_aktif'] ?? 0) === 1;
        }

        $firmaId = $_SESSION['user_firma_id'] ?? null;
        if (empty($firmaId)) {
            $_SESSION['firma_aktif'] = 0;
            $_SESSION['firma_kontrol_zamani'] = time();
            return false;
        }

        try {
            $db = Database::getInstance();
            $firma = $db->fetchOne(
                "SELECT firma_durum FROM Firmalar WHERE firma_id = ?",
                [$firmaId]
            );
            $aktif = ($firma && (int)$firma['firma_durum'] === 1) ? 1 : 0;

            // Sözleşme süresi oturum açıkken dolarsa da oturum kapanır.
            // Administrator'ın impersonate ettiği hesapta destek amaçlı uygulanmaz.
            if ($aktif === 1 && !isset($_SESSION['_impersonator'])
                && !self::sozlesmeErisimiVar($db, [
                    'kullanici_firma_id'     => $firmaId,
                    'kullanici_departman_id' => $_SESSION['user_departman_id'] ?? null,
                ])) {
                $aktif = 0;
            }
        } catch (Throwable $e) {
            // DB hatası oturumu düşürmesin, bir sonraki kontrolde tekrar denenir
            error_log('Firma durum kontrolü hatası: ' . $e->getMessage());
            return (int)($_SESSION['firma_aktif'] ?? 1) === 1;
        }

        $_SESSION['firma_aktif'] = $aktif;
        $_SESSION['firma_kontrol_zamani'] = time();
        return $aktif === 1;
    }

    /**
     * Çıkış işlemi
     */
    public static function logout() {
        if (!empty($_SESSION['user_id'])) {
            try {
                self::kullaniciLogKaydet(Database::getInstance(), 'cikis', $_SESSION['user_id'], $_SESSION['user_email'] ?? null);
            } catch (Throwable $e) {
                // Log hatası çıkışı engellemez
            }
        }
        session_unset();
        session_destroy();
        return true;
    }
    
    /**
     * Kullanıcı bilgisi
     */
    public static function user() {
        if (!self::check()) {
            return null;
        }
        
        return [
            'kullanici_id' => $_SESSION['user_id'] ?? null,
            'kullanici_email' => $_SESSION['user_email'] ?? null,
            'departman_id' => $_SESSION['user_departman_id'] ?? null,
            'firma_id' => $_SESSION['user_firma_id'] ?? null,
            // Geriye dönük uyumluluk
            'id' => $_SESSION['user_id'] ?? null,
            'email' => $_SESSION['user_email'] ?? null,
            'name' => $_SESSION['user_name'] ?? null
        ];
    }
    
    /**
     * Başka bir kullanıcı olarak giriş yap (Impersonate)
     * Sadece Administrator departmanı (departman_id = 1) kullanabilir.
     */
    public static function impersonate($hedefKullaniciId) {
        // 1. Oturum açık mı?
        if (!self::check()) {
            return false;
        }

        // 2. Yetkili departman mı? (1 = Administrator)
        if (($_SESSION['user_departman_id'] ?? null) != 1) {
            return false;
        }

        // 3. Zaten impersonate modunda mı?
        if (isset($_SESSION['_impersonator'])) {
            return false;
        }

        // 4. Kendi hesabına impersonate yasak
        if ($hedefKullaniciId == ($_SESSION['user_id'] ?? 0)) {
            return false;
        }

        // 5. Hedef kullanıcıyı DB'den çek (aktif olmalı)
        $db = Database::getInstance();
        $hedef = $db->fetchOne("
            SELECT k.kullanici_id, k.kullanici_email, k.kullanici_ad, k.kullanici_soyad,
                   k.kullanici_departman_id, k.kullanici_firma_id
            FROM kullanicilar k
            INNER JOIN Firmalar f ON f.firma_id = k.kullanici_firma_id AND f.firma_durum = 1
            WHERE k.kullanici_id = ? AND k.kullanici_durum = 1
        ", [$hedefKullaniciId]);

        if (!$hedef) {
            return false;
        }

        // 6. Mevcut admin session'ını yedekle
        $_SESSION['_impersonator'] = [
            'user_id'            => $_SESSION['user_id'],
            'user_email'         => $_SESSION['user_email'],
            'user_name'          => $_SESSION['user_name'],
            'user_departman_id'  => $_SESSION['user_departman_id'],
            'user_firma_id'      => $_SESSION['user_firma_id'] ?? null,
            'login_time'         => $_SESSION['login_time'],
            'sifre_degistirmeli' => $_SESSION['sifre_degistirmeli'] ?? 0
        ];

        // 7. Hedef kullanıcıyı session'a yaz
        $_SESSION['user_id'] = $hedef['kullanici_id'];
        $_SESSION['user_email'] = $hedef['kullanici_email'];
        $_SESSION['user_name'] = trim($hedef['kullanici_ad'] . ' ' . $hedef['kullanici_soyad']);
        $_SESSION['user_departman_id'] = $hedef['kullanici_departman_id'];
        $_SESSION['user_firma_id'] = $hedef['kullanici_firma_id'] ?? null;
        $_SESSION['login_time'] = time();
        $_SESSION['sifre_degistirmeli'] = 0; // Şifre değiştirme ekranını atla
        $_SESSION['firma_aktif'] = 1;
        $_SESSION['firma_kontrol_zamani'] = time();

        // Log kaydı
        self::kullaniciLogKaydet($db, 'impersonate_bas', $_SESSION['_impersonator']['user_id'], $_SESSION['_impersonator']['user_email'], 'Hedef Kullanıcı ID: ' . $hedef['kullanici_id']);

        return true;
    }

    /**
     * Impersonate modundan çık, orijinal admin hesabına dön.
     */
    public static function stopImpersonate() {
        if (!isset($_SESSION['_impersonator'])) {
            return false;
        }

        $imp = $_SESSION['_impersonator'];
        $impersonatedId = $_SESSION['user_id'] ?? 0;
        unset($_SESSION['_impersonator']);

        $_SESSION['user_id'] = $imp['user_id'];
        $_SESSION['user_email'] = $imp['user_email'];
        $_SESSION['user_name'] = $imp['user_name'];
        $_SESSION['user_departman_id'] = $imp['user_departman_id'];
        $_SESSION['user_firma_id'] = $imp['user_firma_id'] ?? null;
        $_SESSION['login_time'] = $imp['login_time'];
        $_SESSION['sifre_degistirmeli'] = $imp['sifre_degistirmeli'] ?? 0;
        $_SESSION['firma_kontrol_zamani'] = 0; // Dönülen hesap için firma durumu yeniden kontrol edilsin

        // Log kaydı
        self::kullaniciLogKaydet(Database::getInstance(), 'impersonate_son', $imp['user_id'], $imp['user_email'], 'Kullanıcı ID: ' . $impersonatedId);

        return true;
    }

    /**
     * Şu an impersonate modunda mı?
     */
    public static function isImpersonating() {
        return isset($_SESSION['_impersonator']);
    }

    /**
     * Orijinal admin bilgisini döndür.
     */
    public static function impersonator() {
        return $_SESSION['_impersonator'] ?? null;
    }

}

/**
 * Yönlendirme fonksiyonu
 */
function redirect($url) {
    header("Location: $url");
    exit;
}

/**
 * Giriş tamamlandıktan sonra hedef sayfaya yönlendirir.
 * Hedef yoksa veya kendi /admin/ yolumuzun dışındaysa varsayılana gider.
 * İki faktörlü doğrulama nedeniyle hem login.php hem dogrulama.php buradan geçer.
 */
function girisSonrasiYonlendir($varsayilan = '/admin/index.php') {
    $hedef = $_SESSION['giris_sonrasi_url'] ?? null;
    unset($_SESSION['giris_sonrasi_url']);

    // Açık yönlendirme koruması: yalnızca kendi /admin/ yolumuza izin ver
    if ($hedef && preg_match('#^/admin/(?!login\.php|logout\.php|dogrulama\.php)[\w\-./]*(\?[\w\-./=&%+]*)?$#', $hedef)) {
        redirect($hedef);
    }

    redirect($varsayilan);
}

/**
 * Oturum kontrolü ve yönlendirme
 */
/**
 * Istek AJAX / API cagrisi mi?
 *
 * Boyle bir istege login.php'ye 302 donduruluse tarayici yonlendirmeyi izler,
 * JS'e JSON yerine login sayfasinin HTML'i ulasir ve sayfada anlamsiz bir
 * "sunucu hatasi" belirir. Bu istekler 302 yerine 401 JSON almalidir.
 *
 * Normal form gonderimleri (Sec-Fetch-Dest: document) kapsam disidir.
 */
function istekAjaxMi(): bool {
    // jQuery $.ajax bu basligi kendiliginden ekler
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        return true;
    }

    // Gercek sayfa gezintisi asla AJAX sayilmaz. PWA service worker gezintiyi kendi
    // fetch'i uzerinden gecirdiginde Sec-Fetch-Dest 'document' yerine 'empty' gelebiliyor;
    // bu kontrol olmadan sayfa istegine login yerine JSON donuyordu.
    if (($_SERVER['HTTP_SEC_FETCH_MODE'] ?? '') === 'navigate') {
        return false;
    }
    if (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'text/html') !== false) {
        return false;
    }

    // fetch() / XHR: tarayici bu basligi 'empty' gonderir, sayfa gezintisinde 'document'
    if (($_SERVER['HTTP_SEC_FETCH_DEST'] ?? '') === 'empty') {
        return true;
    }

    // JSON bekleyen istemciler
    if (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false) {
        return true;
    }

    // API uc noktalari
    if (strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false) {
        return true;
    }

    return false;
}

function requireAuth() {
    if (!Auth::check()) {
        // AJAX / API isteklerine yonlendirme yerine 401 JSON donulur
        if (istekAjaxMi()) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success'      => false,
                'oturum_bitti' => true,
                'message'      => 'Oturum sureniz doldu. Lutfen tekrar giris yapin.'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        // Giriş sonrası kullanıcının gitmek istediği sayfaya dönebilmek için hedefi sakla.
        // Sadece normal sayfa görüntülemeleri saklanır: POST, AJAX ve API istekleri hariç.
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'
            && empty($_SERVER['HTTP_X_REQUESTED_WITH'])
            && strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') === false) {
            // Oturum zaman aşımında Auth::check() session'ı yok eder; yeniden başlat
            if (session_status() !== PHP_SESSION_ACTIVE) {
                session_start();
            }
            $_SESSION['giris_sonrasi_url'] = $_SERVER['REQUEST_URI'] ?? '';
        }
        // Absolute URL kullan (SEO rewrite sorununu önlemek için)
        $ek = Auth::$sonHata ? '?hata=' . urlencode(Auth::$sonHata) : '';
        redirect('/admin/login.php' . $ek);
    }
    
    // İlk girişte şifre değiştirme zorunluluğu kontrolü
    if (!empty($_SESSION['sifre_degistirmeli'])) {
        $currentPage = basename($_SERVER['SCRIPT_FILENAME'], '.php');
        // Profil sayfası ve logout hariç her sayfada yönlendir
        if ($currentPage !== 'profil' && $currentPage !== 'logout') {
            // Absolute URL kullan (SEO rewrite sorununu önlemek için)
            redirect('/admin/pages/profil.php?sifre_degistir=1');
        }
    }
}
