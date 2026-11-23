<?php
/**
 * Genel form (ornek-kvkk.com/form/<uniqid>) servisi
 *
 * - Form, alan, seçenek ve il/ilçe tanımları veritabanından okunur.
 * - Gönderilen veri doğrulanır; formda SMS onayı açıksa doğrulama kodu firmanın
 *   SMS hesabıyla gönderilir ve kod SmsLog üzerinde (hash, geçerlilik, deneme) tutulur.
 * - Kod doğrulanınca başvuru Form_Basvurulari + Form_Basvuru_Degerleri'ne yazılır;
 *   aynı telefonun aynı formdaki önceki onaylı kaydı "yenilendi" durumuna alınır.
 * - Onaylanan metinlerin anlık görüntüsü ve kanıt hash'i aynı transaction'da yazılır (OnayKanit).
 *   Müşterinin gördüğü metin kayıt anına kadar değiştiyse kayıt yapılmaz (MetinDegistiException).
 */

require_once __DIR__ . '/../admin/db.php';
require_once __DIR__ . '/../admin/includes/SmsApi.php';
require_once __DIR__ . '/../admin/includes/TelefonHelper.php';
require_once __DIR__ . '/OnayKanit.php';
require_once __DIR__ . '/Ayarlar.php';
require_once __DIR__ . '/Webhook.php';

/** Müşteriye gösterilen onay metni kayıt anına kadar değişti */
class MetinDegistiException extends Exception
{
}

class FormBasvuru
{
    const SMS_AMAC            = 'form_dogrulama';

    /** Bu sürümde formda gösterilmeyen alanlar (firma bazlı metin / departman listesi gerekir) */
    const DESTEKLENMEYEN_ALANLAR = ['is_guvenligi', 'hasta_onam', 'gorusulecek_departman'];

    /** Onay metinleri: anahtar => [Formlar kolonu, zorunlu kolonu, başlık, Form_Basvurulari kolonu] */
    const METINLER = [
        'aydinlatma' => ['form_aydinlatma_metni', null,               'KVKK Aydınlatma Metni', 'form_basvuru_aydinlatma_onay'],
        'acik-riza'  => ['form_acik_riza_metni',  null,               'Açık Rıza Metni',       'form_basvuru_acik_riza_onay'],
        'etk'        => ['form_etk_metni',        'form_etk_zorunlu', 'ETK Metni',             'form_basvuru_etk_onay'],
        'gps'        => ['form_gps_metni',        'form_gps_zorunlu', 'GPS Metni',             'form_basvuru_gps_onay'],
    ];

    /** @var Database */
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * SMS doğrulama ayarları (tanim_Ayarlar, firmaya özel değer varsa o).
     * @return array hane, gecerlilik_sn, max_deneme, tekrar_sn, pencere_sn, telefon_limit, ip_limit
     */
    public static function otpAyarlari($firmaId)
    {
        $f = (int)$firmaId ?: null;
        return [
            'hane'          => Ayarlar::sayi('otp_hane', $f),
            'gecerlilik_sn' => Ayarlar::sayi('otp_gecerlilik_sn', $f),
            'max_deneme'    => Ayarlar::sayi('otp_max_deneme', $f),
            'tekrar_sn'     => Ayarlar::sayi('otp_tekrar_sn', $f),
            'pencere_sn'    => Ayarlar::sayi('otp_pencere_sn', $f),
            'telefon_limit' => Ayarlar::sayi('otp_telefon_limit', $f),
            'ip_limit'      => Ayarlar::sayi('otp_ip_limit', $f),
        ];
    }

    /* ---------------------------------------------------------------------
     * Okuma
     * ------------------------------------------------------------------- */

    /** Aktif form + aktif firma; yoksa null */
    public function formGetir($uniqid)
    {
        $uniqid = trim((string)$uniqid);
        if ($uniqid === '' || !preg_match('/^[A-Za-z0-9]{1,32}$/', $uniqid)) {
            return null;
        }
        return $this->db->fetchOne("
            SELECT f.*, fi.firma_adi, fi.firma_ticari_unvan
            FROM dbo.Formlar f
            INNER JOIN dbo.Firmalar fi ON fi.firma_id = f.firma_id
            WHERE f.form_uniqid = ? AND f.form_durum = 1 AND fi.firma_durum = 1
        ", [$uniqid]) ?: null;
    }

    /** Formun görünen alanları (sıra numarasına göre), seçenekleriyle birlikte */
    public function alanlar($formId)
    {
        $rows = $this->db->fetchAll("
            SELECT fa.form_alan_id, fa.form_alani_zorunlu, fa.form_alani_etiket,
                   t.form_alan_kod, t.form_alan_adi, t.form_alan_tipi, t.form_alan_sabit
            FROM dbo.Form_Alanlari fa
            INNER JOIN dbo.tanim_FormAlanlari t ON t.form_alan_id = fa.form_alan_id AND t.form_alan_durum = 1
            WHERE fa.form_id = ? AND fa.form_alani_durum = 1
            ORDER BY fa.form_alani_sira_no, t.form_alan_sira_no
        ", [(int)$formId]) ?: [];

        $rows = array_values(array_filter($rows, fn($r) => !in_array($r['form_alan_kod'], self::DESTEKLENMEYEN_ALANLAR, true)));

        // Seçenekli alanlar
        $secenekAlan = array_map(fn($r) => (int)$r['form_alan_id'],
            array_filter($rows, fn($r) => $r['form_alan_tipi'] === 'select' && !in_array($r['form_alan_kod'], ['il', 'ilce'], true)));
        $secenekler = [];
        if ($secenekAlan) {
            foreach ($this->db->fetchAll("
                SELECT form_alan_secenek_id, form_alan_id, form_alan_secenek_ust_id,
                       form_alan_secenek_adi, form_alan_secenek_alt_baslik
                FROM dbo.tanim_FormAlanSecenekleri
                WHERE form_alan_secenek_durum = 1 AND form_alan_id IN (" . implode(',', $secenekAlan) . ")
                ORDER BY form_alan_secenek_sira_no, form_alan_secenek_adi
            ") ?: [] as $s) {
                $secenekler[(int)$s['form_alan_id']][] = $s;
            }
        }

        foreach ($rows as &$r) {
            $r['zorunlu'] = (int)$r['form_alani_zorunlu'] === 1 || (int)$r['form_alan_sabit'] === 1;
            $r['etiket']  = trim((string)$r['form_alani_etiket']) !== '' ? trim($r['form_alani_etiket']) : $r['form_alan_adi'];
            $hepsi = $secenekler[(int)$r['form_alan_id']] ?? [];
            $r['secenekler'] = array_values(array_filter($hepsi, fn($s) => $s['form_alan_secenek_ust_id'] === null));
            $r['alt_secenekler'] = [];
            foreach ($hepsi as $s) {
                if ($s['form_alan_secenek_ust_id'] !== null) {
                    $r['alt_secenekler'][(int)$s['form_alan_secenek_ust_id']][] = $s;
                }
            }
        }
        unset($r);
        return $rows;
    }

    public function iller()
    {
        return $this->db->fetchAll("SELECT il_id, il_adi FROM dbo.tanim_Iller WHERE il_durum = 1 ORDER BY il_adi") ?: [];
    }

    public function ilceler($ilId)
    {
        return $this->db->fetchAll("
            SELECT ilce_id, ilce_adi FROM dbo.tanim_Ilceler
            WHERE il_id = ? AND ilce_durum = 1 ORDER BY ilce_adi
        ", [(int)$ilId]) ?: [];
    }

    /** Formda dolu olan onay metinleri: [anahtar => ['baslik', 'metin', 'hash', 'zorunlu', 'kolon']] */
    public static function onayMetinleri(array $form)
    {
        $sonuc = [];
        foreach (self::METINLER as $anahtar => [$kolon, $zorunluKolon, $baslik, $hedef]) {
            $metin = trim((string)($form[$kolon] ?? ''));
            if ($metin !== '') {
                $sonuc[$anahtar] = [
                    'baslik'  => $baslik,
                    'metin'   => $metin,
                    'hash'    => OnayKanit::metinHash($metin),
                    'zorunlu' => $zorunluKolon ? (int)($form[$zorunluKolon] ?? 0) === 1 : true,
                    'kolon'   => $hedef,
                ];
            }
        }
        return $sonuc;
    }

    /** Yönetici girişli metni güvenli HTML'e çevirir (izinli etiketler, öznitelik yok) */
    public static function metinHtml($metin)
    {
        return OnayKanit::metinHtml($metin);
    }

    /* ---------------------------------------------------------------------
     * Doğrulama
     * ------------------------------------------------------------------- */

    private static function tcGecerli($tc)
    {
        if (!preg_match('/^[1-9][0-9]{10}$/', $tc)) {
            return false;
        }
        $d = array_map('intval', str_split($tc));
        $tek = $d[0] + $d[2] + $d[4] + $d[6] + $d[8];
        $cift = $d[1] + $d[3] + $d[5] + $d[7];
        if ((($tek * 7) - $cift) % 10 !== $d[9]) {
            return false;
        }
        return (array_sum(array_slice($d, 0, 10)) % 10) === $d[10];
    }

    /**
     * Gönderilen formu doğrular.
     * @return array ['hatalar' => [alan => mesaj], 'veri' => [...] ] — veri: başlık kolonları + alan değerleri
     */
    public function dogrula(array $form, array $alanlar, array $post)
    {
        $hatalar = [];
        $degerler = [];   // form_alan_id => metin
        $baslik = ['ad' => null, 'soyad' => null, 'telefon' => null, 'eposta' => null];
        $al = fn($k) => trim((string)($post['alan'][$k] ?? ''));

        $ilAdi = null;
        $ilId = 0;

        foreach ($alanlar as $a) {
            $kod = $a['form_alan_kod'];
            $id = (int)$a['form_alan_id'];
            $deger = $al($kod);
            $etiket = $a['etiket'];

            if ($deger === '') {
                if ($a['zorunlu']) {
                    $hatalar[$kod] = $etiket . ' zorunludur.';
                }
                continue;
            }

            switch (true) {
                case $kod === 'cep_telefonu' || $a['form_alan_tipi'] === 'tel':
                    $tel = telefonNormalizeEtGuvenli($deger, $hata);
                    if (!$tel) {
                        $hatalar[$kod] = 'Geçerli bir cep telefonu giriniz (örn. 0532 123 45 67).';
                        continue 2;
                    }
                    $deger = $tel;
                    if ($kod === 'cep_telefonu') {
                        $baslik['telefon'] = $tel;
                    }
                    break;

                case $kod === 'eposta' || $a['form_alan_tipi'] === 'email':
                    $deger = mb_strtolower($deger);
                    if (!filter_var($deger, FILTER_VALIDATE_EMAIL) || mb_strlen($deger) > 255) {
                        $hatalar[$kod] = 'Geçerli bir e-posta adresi giriniz.';
                        continue 2;
                    }
                    $baslik['eposta'] = $deger;
                    break;

                case $kod === 'tc_kimlik':
                    if (!self::tcGecerli($deger)) {
                        $hatalar[$kod] = 'T.C. Kimlik Numarası hatalı.';
                        continue 2;
                    }
                    break;

                case $kod === 'vergi_no':
                    if (!preg_match('/^[0-9]{10,11}$/', $deger)) {
                        $hatalar[$kod] = 'Vergi numarası 10 veya 11 haneli olmalıdır.';
                        continue 2;
                    }
                    break;

                case $a['form_alan_tipi'] === 'date':
                    $t = DateTime::createFromFormat('Y-m-d', $deger);
                    if (!$t || $t->format('Y-m-d') !== $deger || $deger > date('Y-m-d') || $deger < '1900-01-01') {
                        $hatalar[$kod] = 'Geçerli bir tarih seçiniz.';
                        continue 2;
                    }
                    $deger = $t->format('d.m.Y');
                    break;

                case $kod === 'il':
                    $il = $this->db->fetchOne("SELECT il_id, il_adi FROM dbo.tanim_Iller WHERE il_id = ? AND il_durum = 1", [(int)$deger]);
                    if (!$il) {
                        $hatalar[$kod] = 'Geçerli bir il seçiniz.';
                        continue 2;
                    }
                    $ilId = (int)$il['il_id'];
                    $deger = $ilAdi = $il['il_adi'];
                    break;

                case $kod === 'ilce':
                    // İl seçimi alan sırasında ilçeden önce gelir; yine de doğrudan post'tan okunur
                    $ilSecim = $ilId ?: (int)$al('il');
                    $ilce = $this->db->fetchOne("SELECT ilce_adi FROM dbo.tanim_Ilceler WHERE ilce_id = ? AND il_id = ? AND ilce_durum = 1",
                        [(int)$deger, $ilSecim]);
                    if (!$ilce) {
                        $hatalar[$kod] = 'Geçerli bir ilçe seçiniz.';
                        continue 2;
                    }
                    $deger = $ilce['ilce_adi'];
                    break;

                case $a['form_alan_tipi'] === 'select':
                    $secim = null;
                    foreach ($a['secenekler'] as $s) {
                        if ((int)$s['form_alan_secenek_id'] === (int)$deger) {
                            $secim = $s;
                        }
                    }
                    if (!$secim) {
                        $hatalar[$kod] = 'Geçerli bir seçim yapınız.';
                        continue 2;
                    }
                    $deger = $secim['form_alan_secenek_adi'];
                    $altlar = $a['alt_secenekler'][(int)$secim['form_alan_secenek_id']] ?? [];
                    if ($altlar) {
                        $altDeger = trim((string)($post['alan'][$kod . '_alt'] ?? ''));
                        $alt = null;
                        foreach ($altlar as $s) {
                            if ((int)$s['form_alan_secenek_id'] === (int)$altDeger) {
                                $alt = $s;
                            }
                        }
                        if (!$alt) {
                            $hatalar[$kod . '_alt'] = ($secim['form_alan_secenek_alt_baslik'] ?: 'Alt seçim') . ' seçiniz.';
                            continue 2;
                        }
                        $deger .= ' - ' . $alt['form_alan_secenek_adi'];
                    }
                    break;

                default:
                    $deger = mb_substr(preg_replace('/\s+/u', ' ', $deger), 0, $a['form_alan_tipi'] === 'textarea' ? 1000 : 250);
            }

            if (in_array($kod, ['ad', 'soyad'], true)) {
                $baslik[$kod] = mb_substr($deger, 0, 150);
            }
            $degerler[$id] = $deger;
        }

        // Onay metinleri: her metin için kabul / ret cevabı
        // Sayfadaki metin hash'i güncel metinle aynı olmalı (müşteri formu doldururken metin değişmiş olabilir)
        $onaylar = [];
        $metinHashleri = [];
        $metinDegisti = false;
        foreach (self::onayMetinleri($form) as $anahtar => $m) {
            if (!hash_equals($m['hash'], (string)($post['metin_hash'][$anahtar] ?? ''))) {
                $metinDegisti = true;
            }
            $metinHashleri[$anahtar] = $m['hash'];
            $cevap = (string)($post['onay'][$anahtar] ?? '');
            if ($cevap !== '1' && $cevap !== '0') {
                if ($m['zorunlu']) {
                    $hatalar['onay_' . $anahtar] = $m['baslik'] . ' için seçim yapınız.';
                }
                $onaylar[$m['kolon']] = 0;
                continue;
            }
            $onaylar[$m['kolon']] = (int)$cevap;
        }

        if (!$baslik['telefon'] && (int)$form['form_sms_onay'] === 1 && !isset($hatalar['cep_telefonu'])) {
            $hatalar['cep_telefonu'] = 'SMS doğrulaması için cep telefonu gereklidir.';
        }

        return [
            'hatalar'       => $hatalar,
            'metin_degisti' => $metinDegisti,
            'veri'          => ['baslik' => $baslik, 'degerler' => $degerler, 'onaylar' => $onaylar, 'metin_hashleri' => $metinHashleri],
        ];
    }

    /* ---------------------------------------------------------------------
     * SMS doğrulama
     * ------------------------------------------------------------------- */

    public static function telefonMaskele($tel)
    {
        $tel = preg_replace('/\D/', '', (string)$tel);
        return strlen($tel) >= 12 ? '0' . substr($tel, 2, 3) . ' *** ** ' . substr($tel, -2) : $tel;
    }

    /** Linklerin kökü (https://alan-adi) */
    public static function kokAdres()
    {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'ornek-kvkk.com');
    }

    /**
     * Doğrulama kodunu gönderir.
     * $api: API'den çağrıldığında ['anahtar_id' => int, 'tekrar' => bool]. Bu durumda IP sınırı yerine
     *       anahtar sınırı uygulanır (entegratörün tüm istekleri tek sunucu IP'sinden gelir);
     *       'tekrar' (kod yeniden gönderimi) anahtar sınırına sayılmaz, telefon sınırı her durumda geçerlidir.
     * @return array ['success', 'message', 'smslog_id', 'kod' (hata: COK_FAZLA_ISTEK | SMS_GONDERILEMEDI)]
     */
    public function kodGonder(array $form, $telefon, ?array $api = null)
    {
        $hesap = SmsApi::firmaHesabi((int)$form['firma_id']);
        if (!$hesap || (int)$hesap['operator_durum'] !== 1) {
            error_log('Form ' . $form['form_uniqid'] . ': firmanın aktif SMS hesabı yok (firma #' . $form['firma_id'] . ')');
            return ['success' => false, 'kod' => 'SMS_GONDERILEMEDI', 'message' => 'Doğrulama SMS\'i şu anda gönderilemiyor. Lütfen daha sonra tekrar deneyiniz.'];
        }

        $otp = self::otpAyarlari($form['firma_id']);
        $limitMesaj = 'Çok fazla doğrulama isteği yapıldı. Lütfen ' . (int)ceil($otp['pencere_sn'] / 60) . ' dakika sonra tekrar deneyiniz.';

        // Kötüye kullanım sınırı: telefon her zaman; web formunda IP, API'de anahtar
        $pencere = date('Y-m-d H:i:s', time() - $otp['pencere_sn']);
        $say = $this->db->fetchOne("
            SELECT
                SUM(CASE WHEN smslog_telefon = ? THEN 1 ELSE 0 END) AS telefon,
                SUM(CASE WHEN smslog_ip = ? THEN 1 ELSE 0 END)      AS ip
            FROM dbo.SmsLog
            WHERE smslog_amac = ? AND smslog_gonderim_tarihi >= ?
        ", [$telefon, $_SERVER['REMOTE_ADDR'] ?? '', self::SMS_AMAC, $pencere]);
        if ((int)($say['telefon'] ?? 0) >= $otp['telefon_limit'] || (!$api && (int)($say['ip'] ?? 0) >= $otp['ip_limit'])) {
            return ['success' => false, 'kod' => 'COK_FAZLA_ISTEK', 'message' => $limitMesaj];
        }
        if ($api && empty($api['tekrar'])) {
            $anahtarSay = (int)($this->db->fetchOne("
                SELECT COUNT(*) AS n FROM dbo.Api_Bekleyen_Basvurular
                WHERE bekleyen_anahtar_id = ? AND bekleyen_smslog_id IS NOT NULL
                  AND OlusturmaTarihi >= DATEADD(SECOND, -?, GETDATE())
            ", [(int)$api['anahtar_id'], $otp['pencere_sn']])['n'] ?? 0);
            if ($anahtarSay >= Ayarlar::sayi('otp_anahtar_limit', (int)$form['firma_id'])) {
                return ['success' => false, 'kod' => 'COK_FAZLA_ISTEK', 'message' => 'Bu anahtar için kısa sürede çok fazla doğrulama SMS\'i istendi. Lütfen ' . (int)ceil($otp['pencere_sn'] / 60) . ' dakika sonra tekrar deneyiniz.'];
            }
        }

        // Bu telefonun bu form için bekleyen eski kodları geçersizleşir
        $this->db->execute("
            UPDATE dbo.SmsLog SET smslog_kod_kullanildi = 1, GuncellemeTarihi = GETDATE()
            WHERE smslog_amac = ? AND smslog_telefon = ? AND smslog_firma_id = ? AND smslog_kod_kullanildi = 0
        ", [self::SMS_AMAC, $telefon, (int)$form['firma_id']]);

        $kod = str_pad((string)random_int(0, (10 ** $otp['hane']) - 1), $otp['hane'], '0', STR_PAD_LEFT);
        $kok = self::kokAdres() . '/form/' . $form['form_uniqid'];
        $metinler = self::onayMetinleri($form);

        $mesaj = $kod . ' doğrulama kodu ile';
        $parcalar = [];
        if (isset($metinler['acik-riza']) || isset($metinler['aydinlatma'])) {
            $parcalar[] = ' Açık Rıza ve KVKK aydınlatma metnine onay vermektesiniz.';
        }
        $mesaj .= $parcalar ? $parcalar[0] : ' başvurunuzu onaylamaktasınız.';
        foreach ($metinler as $anahtar => $m) {
            $mesaj .= "\n" . $m['baslik'] . ': ' . $kok . '/' . $anahtar;
        }

        $sonuc = SmsApi::otpGonder($hesap, $telefon, $mesaj, [
            'smslog_amac'       => self::SMS_AMAC,
            'smslog_kod_hash'   => password_hash($kod, PASSWORD_DEFAULT),
            'smslog_gecerlilik' => date('Y-m-d H:i:s', time() + $otp['gecerlilik_sn']),
        ]);

        if (empty($sonuc['success']) || empty($sonuc['log_id'])) {
            error_log('Form ' . $form['form_uniqid'] . ' SMS gönderilemedi: ' . ($sonuc['message'] ?? ''));
            return ['success' => false, 'kod' => 'SMS_GONDERILEMEDI', 'message' => 'Doğrulama SMS\'i şu anda gönderilemedi. Lütfen birkaç dakika sonra tekrar deneyiniz.'];
        }
        return ['success' => true, 'smslog_id' => (int)$sonuc['log_id'], 'message' => 'Doğrulama kodu gönderildi.'];
    }

    /**
     * Kodu SmsLog kaydına göre doğrular.
     * @return array ['success', 'message', 'bitti' (yeniden başlamak gerekir)]
     */
    public function kodDogrula($smslogId, $telefon, $girilen)
    {
        $kayit = $this->db->fetchOne("
            SELECT smslog_id, smslog_firma_id, smslog_kod_hash, smslog_gecerlilik, smslog_deneme, smslog_kod_kullanildi
            FROM dbo.SmsLog WHERE smslog_id = ? AND smslog_amac = ? AND smslog_telefon = ?
        ", [(int)$smslogId, self::SMS_AMAC, $telefon]);

        if (!$kayit || (int)$kayit['smslog_kod_kullanildi'] === 1 || empty($kayit['smslog_kod_hash'])) {
            return ['success' => false, 'bitti' => true, 'message' => 'Doğrulama kodu geçersiz. Lütfen yeni kod isteyiniz.'];
        }
        if (strtotime($kayit['smslog_gecerlilik']) < time()) {
            $this->db->execute("UPDATE dbo.SmsLog SET smslog_kod_kullanildi = 1, GuncellemeTarihi = GETDATE() WHERE smslog_id = ?", [$kayit['smslog_id']]);
            return ['success' => false, 'bitti' => true, 'message' => 'Kodun süresi doldu. Lütfen yeni kod isteyiniz.'];
        }

        $maxDeneme = Ayarlar::sayi('otp_max_deneme', (int)$kayit['smslog_firma_id'] ?: null);
        $deneme = (int)$kayit['smslog_deneme'] + 1;
        // Hane sayısı kod gönderildikten sonra değişmiş olabilir; doğruluğu hash karşılaştırması belirler
        if (!preg_match('/^[0-9]{1,10}$/', $girilen) || !password_verify($girilen, $kayit['smslog_kod_hash'])) {
            $tukendi = $deneme >= $maxDeneme;
            $this->db->execute("
                UPDATE dbo.SmsLog SET smslog_deneme = ?, smslog_kod_kullanildi = ?, GuncellemeTarihi = GETDATE()
                WHERE smslog_id = ?
            ", [$deneme, $tukendi ? 1 : 0, $kayit['smslog_id']]);
            return $tukendi
                ? ['success' => false, 'bitti' => true, 'message' => 'Deneme hakkınız doldu. Lütfen yeni kod isteyiniz.']
                : ['success' => false, 'bitti' => false, 'message' => 'Kod hatalı. Kalan deneme hakkı: ' . ($maxDeneme - $deneme)];
        }

        $this->db->execute("
            UPDATE dbo.SmsLog
            SET smslog_deneme = ?, smslog_kod_kullanildi = 1, smslog_dogrulandi = 1,
                smslog_dogrulama_tarihi = GETDATE(), GuncellemeTarihi = GETDATE()
            WHERE smslog_id = ?
        ", [$deneme, $kayit['smslog_id']]);
        return ['success' => true, 'bitti' => false, 'message' => 'Doğrulandı'];
    }

    /* ---------------------------------------------------------------------
     * Kayıt
     * ------------------------------------------------------------------- */

    private function durumId($kod)
    {
        $r = $this->db->fetchOne("SELECT form_dogrulama_durum_id FROM dbo.tanim_FormDogrulamaDurumlari
                                  WHERE form_dogrulama_durum_kod = ? AND form_dogrulama_durum_durum = 1", [$kod]);
        if (!$r) {
            throw new Exception("tanim_FormDogrulamaDurumlari içinde '$kod' tanımı yok.");
        }
        return (int)$r['form_dogrulama_durum_id'];
    }

    /**
     * Başvuruyu yazar (tek transaction).
     * $api: API'den gelen başvuruda ['anahtar_id', 'bekleyen_id', 'musteri_ip', 'musteri_tarayici'].
     *       IP / tarayıcı kolonlarına müşterinin beyan edilen bilgisi yazılır (yoksa çağıran IP);
     *       çağıran IP bekleyen kayıtta kalır ve kanıta eklenir (OnayKanit::veriUret).
     * @return int form_basvuru_id
     */
    public function kaydet(array $form, array $veri, $smslogId = null, ?array $api = null)
    {
        // Kayıt anındaki metinler müşteriye gösterilenlerle aynı olmalı
        $metinler = self::onayMetinleri($form);
        $gosterilen = $veri['metin_hashleri'] ?? null;
        if (!is_array($gosterilen) || array_keys($gosterilen) !== array_keys($metinler)) {
            throw new MetinDegistiException('Onay metinleri değişti.');
        }
        foreach ($metinler as $anahtar => $m) {
            if (!hash_equals($m['hash'], (string)$gosterilen[$anahtar])) {
                throw new MetinDegistiException('Onay metinleri değişti.');
            }
        }

        $onaylandi = $this->durumId('onaylandi');
        $yenilendi = $this->durumId('yenilendi');
        $simdi = date('Y-m-d H:i:s');
        $b = $veri['baslik'];
        $o = $veri['onaylar'];

        // KVKK onayı: formdaki aydınlatma ve açık rıza metinlerinin ikisi de kabul edildiyse
        $kvkkKolonlar = array_filter([
            isset(self::onayMetinleri($form)['aydinlatma']) ? 'form_basvuru_aydinlatma_onay' : null,
            isset(self::onayMetinleri($form)['acik-riza']) ? 'form_basvuru_acik_riza_onay' : null,
        ]);
        $kvkk = $kvkkKolonlar ? (int)!in_array(0, array_map(fn($k) => (int)($o[$k] ?? 0), $kvkkKolonlar), true) : null;

        $conn = $this->db->getConnection();
        sqlsrv_begin_transaction($conn);
        try {
            $yenilenenler = [];
            if ($b['telefon']) {
                $stmt = $this->sorgu("
                    UPDATE dbo.Form_Basvurulari
                    SET form_basvuru_dogrulama_durumu = ?, form_basvuru_guncelleme_tarihi = ?
                    OUTPUT INSERTED.form_basvuru_id
                    WHERE form_id = ? AND form_basvuru_telefon = ? AND form_basvuru_dogrulama_durumu = ?
                ", [$yenilendi, $simdi, (int)$form['form_id'], $b['telefon'], $onaylandi]);
                while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_NUMERIC)) {
                    $yenilenenler[] = (int)$r[0];
                }
                sqlsrv_free_stmt($stmt);
            }

            $stmt = $this->sorgu("
                INSERT INTO dbo.Form_Basvurulari
                    (form_id, firma_id, form_basvuru_ad, form_basvuru_soyad, form_basvuru_telefon, form_basvuru_eposta,
                     form_basvuru_aydinlatma_onay, form_basvuru_acik_riza_onay, form_basvuru_etk_onay, form_basvuru_gps_onay,
                     form_basvuru_kvkk_onay, form_basvuru_dogrulama_durumu, form_basvuru_sms_referans, form_basvuru_sms_dogrulama_tarihi,
                     form_basvuru_ip_adresi, form_basvuru_tarayici, form_basvuru_tarihi, form_basvuru_durum, form_basvuru_olusturma_tarihi,
                     form_basvuru_api_anahtar_id)
                OUTPUT INSERTED.form_basvuru_id
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)
            ", [
                (int)$form['form_id'], (int)$form['firma_id'], $b['ad'], $b['soyad'], $b['telefon'], $b['eposta'],
                (int)($o['form_basvuru_aydinlatma_onay'] ?? 0), (int)($o['form_basvuru_acik_riza_onay'] ?? 0),
                (int)($o['form_basvuru_etk_onay'] ?? 0), (int)($o['form_basvuru_gps_onay'] ?? 0),
                $kvkk, $onaylandi, $smslogId ? (string)$smslogId : null, $smslogId ? $simdi : null,
                mb_substr((string)($api['musteri_ip'] ?? '') ?: (string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
                $api ? (mb_substr((string)($api['musteri_tarayici'] ?? ''), 0, 500) ?: null)
                     : (mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500) ?: null),
                $simdi, $simdi,
                $api ? (int)$api['anahtar_id'] : null,
            ]);
            $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_NUMERIC);
            sqlsrv_free_stmt($stmt);
            $basvuruId = (int)$row[0];

            // API: bekleyen kaydı tamamlanır (şifreli veri silinir). Aynı doğrulama iki kez kaydedilemez.
            if ($api) {
                $stmt = $this->sorgu("
                    UPDATE dbo.Api_Bekleyen_Basvurular
                    SET bekleyen_basvuru_id = ?, bekleyen_veri = NULL, GuncellemeTarihi = GETDATE()
                    WHERE bekleyen_id = ? AND bekleyen_basvuru_id IS NULL
                ", [$basvuruId, (int)$api['bekleyen_id']]);
                $etkilenen = sqlsrv_rows_affected($stmt);
                sqlsrv_free_stmt($stmt);
                if ($etkilenen !== 1) {
                    throw new Exception('Bekleyen başvuru zaten tamamlanmış (#' . (int)$api['bekleyen_id'] . ').');
                }
            }

            if ($veri['degerler']) {
                $parametre = [];
                $yer = [];
                foreach ($veri['degerler'] as $alanId => $deger) {
                    $yer[] = '(?, ?, ?, ?)';
                    array_push($parametre, $basvuruId, (int)$alanId, mb_substr((string)$deger, 0, 1000), $simdi);
                }
                sqlsrv_free_stmt($this->sorgu("
                    INSERT INTO dbo.Form_Basvuru_Degerleri (form_basvuru_id, form_alan_id, form_basvuru_deger, form_basvuru_deger_olusturma_tarihi)
                    VALUES " . implode(', ', $yer), $parametre));
            }

            // Onaylanan metinlerin anlık görüntüsü (form + tür + hash tekil) ve cevaplar
            foreach ($metinler as $anahtar => $m) {
                $metinId = $this->tekDeger("
                    SELECT form_onay_metin_id FROM dbo.Form_Onay_Metinleri WITH (UPDLOCK, HOLDLOCK)
                    WHERE form_id = ? AND form_onay_metin_tur = ? AND form_onay_metin_hash = ?
                ", [(int)$form['form_id'], $anahtar, $m['hash']]);
                if (!$metinId) {
                    $metinId = $this->tekDeger("
                        INSERT INTO dbo.Form_Onay_Metinleri
                            (form_id, form_onay_metin_tur, form_onay_metin_baslik, form_onay_metin_icerik, form_onay_metin_hash, form_onay_metin_olusturma_tarihi)
                        OUTPUT INSERTED.form_onay_metin_id
                        VALUES (?, ?, ?, ?, ?, ?)
                    ", [(int)$form['form_id'], $anahtar, $m['baslik'], $m['metin'], $m['hash'], $simdi]);
                }
                sqlsrv_free_stmt($this->sorgu("
                    INSERT INTO dbo.Form_Basvuru_Onaylari (form_basvuru_id, form_onay_metin_id, form_basvuru_onay_kabul, form_basvuru_onay_olusturma_tarihi)
                    VALUES (?, ?, ?, ?)
                ", [$basvuruId, (int)$metinId, (int)($o[$m['kolon']] ?? 0), $simdi]));
            }

            // Kanıt: DB'deki haliyle üretilen JSON + SHA-256
            $kanit = OnayKanit::veriUret($this->db, $basvuruId);
            if ($kanit === null) {
                throw new Exception('Kanıt verisi üretilemedi.');
            }
            sqlsrv_free_stmt($this->sorgu("
                UPDATE dbo.Form_Basvurulari
                SET form_basvuru_kanit_surum = ?, form_basvuru_kanit_verisi = ?, form_basvuru_kanit_hash = ?
                WHERE form_basvuru_id = ?
            ", [OnayKanit::SURUM, $kanit, OnayKanit::metinHash($kanit), $basvuruId]));

            sqlsrv_commit($conn);
        } catch (Throwable $e) {
            sqlsrv_rollback($conn);
            throw $e;
        }

        // Bildirim: önceki onaylı kayıt yenilendiyse basvuru.yenilendi, yoksa basvuru.onaylandi (tek olay)
        sort($yenilenenler);
        Webhook::olay($yenilenenler ? 'basvuru.yenilendi' : 'basvuru.onaylandi', (int)$form['firma_id'], $basvuruId,
            ['form' => $form['form_uniqid'], 'durum' => 'onaylandi', 'kaynak' => $api ? 'api' : 'form']
            + ($yenilenenler ? ['onceki_basvuru_idleri' => $yenilenenler] : []));

        return $basvuruId;
    }

    /** Tek satır tek kolon döndüren sorgu (yoksa null) */
    private function tekDeger($sql, array $params)
    {
        $stmt = $this->sorgu($sql, $params);
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_NUMERIC);
        sqlsrv_free_stmt($stmt);
        return $row ? $row[0] : null;
    }

    private function sorgu($sql, array $params)
    {
        $stmt = sqlsrv_query($this->db->getConnection(), $sql, $params);
        if ($stmt === false) {
            $e = sqlsrv_errors(SQLSRV_ERR_ERRORS);
            throw new Exception('Veritabanı hatası: ' . ($e[0]['message'] ?? 'bilinmeyen'));
        }
        return $stmt;
    }
}
