<?php
/**
 * Veritabanında şifreli tutulan alanlar için AES-256-GCM
 *
 * Anahtarlar config/database.php → 'sifreleme' bloğundadır (git'e ve public kopyaya girmez).
 * Çıktı biçimi:  v<sürüm>:<base64(iv[12] + tag[16] + şifreli metin)>
 * Sürüm numarası sayesinde anahtar yenilendiğinde eski kayıtlar eski anahtarla çözülmeye devam eder.
 *
 * $baglam (AAD) şifreli değeri bir kayda bağlar: aynı değer başka bir satıra
 * kopyalanırsa çözülmez. Şifrelerken ve çözerken aynı bağlam verilmelidir.
 *
 *   $s = Sifreleme::sifrele($json, 'bekleyen:' . $dogrulamaHash);
 *   $json = Sifreleme::coz($s, 'bekleyen:' . $dogrulamaHash);
 */

class Sifreleme
{
    const ALGORITMA = 'aes-256-gcm';
    const IV_UZUNLUK = 12;
    const TAG_UZUNLUK = 16;

    /** @var array|null ['aktif' => '1', 'anahtarlar' => ['1' => ham 32 bayt]] */
    private static $ayar = null;

    public static function sifrele($duzMetin, $baglam = '')
    {
        $ayar = self::ayar();
        $surum = $ayar['aktif'];
        $iv = random_bytes(self::IV_UZUNLUK);
        $tag = '';
        $sifreli = openssl_encrypt((string)$duzMetin, self::ALGORITMA, $ayar['anahtarlar'][$surum],
            OPENSSL_RAW_DATA, $iv, $tag, (string)$baglam, self::TAG_UZUNLUK);
        if ($sifreli === false) {
            throw new RuntimeException('Şifreleme başarısız.');
        }
        return 'v' . $surum . ':' . base64_encode($iv . $tag . $sifreli);
    }

    public static function coz($sifreliMetin, $baglam = '')
    {
        if (!preg_match('/^v([0-9]+):([A-Za-z0-9+\/=]+)$/', (string)$sifreliMetin, $m)) {
            throw new RuntimeException('Şifreli değer biçimi tanınmadı.');
        }
        $ayar = self::ayar();
        if (!isset($ayar['anahtarlar'][$m[1]])) {
            throw new RuntimeException("Şifreleme anahtarı v{$m[1]} config/database.php içinde yok.");
        }
        $ham = base64_decode($m[2], true);
        if ($ham === false || strlen($ham) <= self::IV_UZUNLUK + self::TAG_UZUNLUK) {
            throw new RuntimeException('Şifreli değer bozuk.');
        }
        $iv = substr($ham, 0, self::IV_UZUNLUK);
        $tag = substr($ham, self::IV_UZUNLUK, self::TAG_UZUNLUK);
        $govde = substr($ham, self::IV_UZUNLUK + self::TAG_UZUNLUK);

        $duz = openssl_decrypt($govde, self::ALGORITMA, $ayar['anahtarlar'][$m[1]],
            OPENSSL_RAW_DATA, $iv, $tag, (string)$baglam);
        if ($duz === false) {
            throw new RuntimeException('Şifreli değer çözülemedi (anahtar veya bağlam uyuşmuyor).');
        }
        return $duz;
    }

    /** Değer şifreli biçimde mi (geçiş dönemi kontrolleri için) */
    public static function sifreliMi($deger)
    {
        return is_string($deger) && preg_match('/^v[0-9]+:[A-Za-z0-9+\/=]+$/', $deger) === 1;
    }

    private static function ayar()
    {
        if (self::$ayar !== null) {
            return self::$ayar;
        }
        $config = require __DIR__ . '/../config/database.php';
        $s = $config['sifreleme'] ?? null;
        if (!$s || empty($s['aktif']) || empty($s['anahtarlar'][$s['aktif']])) {
            throw new RuntimeException("config/database.php içinde 'sifreleme' bloğu eksik.");
        }
        $anahtarlar = [];
        foreach ($s['anahtarlar'] as $surum => $b64) {
            $ham = base64_decode((string)$b64, true);
            if ($ham === false || strlen($ham) !== 32) {
                throw new RuntimeException("Şifreleme anahtarı v$surum 32 bayt değil.");
            }
            $anahtarlar[(string)$surum] = $ham;
        }
        return self::$ayar = ['aktif' => (string)$s['aktif'], 'anahtarlar' => $anahtarlar];
    }
}
