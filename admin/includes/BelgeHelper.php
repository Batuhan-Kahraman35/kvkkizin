<?php
/**
 * Firma Belgeleri Yardımcı Sınıfı
 *
 * Belgeler web kökünün dışında (httpdocs dışı) saklanır, yalnızca
 * yetki kontrolünden geçen belge-indir.php üzerinden okunur.
 */

class BelgeHelper {

    /**
     * Belge deposunun kök dizini
     * admin/includes -> admin -> httpdocs -> ornek-kvkk.com/belgeler
     */
    public static function kokDizin() {
        return dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'belgeler';
    }

    /**
     * Göreli yoldan (2026/07/abc.pdf) fiziksel tam yolu üretir
     * Dizin dışına çıkma denemelerini engeller.
     *
     * @param string $goreliYol
     * @return string|null
     */
    public static function tamYol($goreliYol) {
        $goreliYol = str_replace('\\', '/', (string)$goreliYol);
        $goreliYol = ltrim($goreliYol, '/');

        // .. ve mutlak yol denemelerini reddet
        if ($goreliYol === '' || strpos($goreliYol, '..') !== false || preg_match('#^[a-z]:#i', $goreliYol)) {
            return null;
        }

        $kok = self::kokDizin();
        $tam = $kok . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $goreliYol);

        if (!is_file($tam)) {
            return null;
        }

        // Gerçek yol kök dizinin altında mı?
        $gercek = realpath($tam);
        $kokGercek = realpath($kok);
        if ($gercek === false || $kokGercek === false || strpos($gercek, $kokGercek) !== 0) {
            return null;
        }

        return $gercek;
    }

    /**
     * Yükleme için yıl/ay klasörünü hazırlar
     *
     * @return array [goreliKlasor, fizikselKlasor]
     * @throws Exception
     */
    public static function klasorHazirla() {
        $goreli = date('Y') . '/' . date('m');
        $fiziksel = self::kokDizin() . DIRECTORY_SEPARATOR . date('Y') . DIRECTORY_SEPARATOR . date('m');

        if (!is_dir($fiziksel) && !@mkdir($fiziksel, 0775, true) && !is_dir($fiziksel)) {
            throw new Exception('Belge klasörü oluşturulamadı. Dizin yazma izinlerini kontrol edin.');
        }

        return [$goreli, $fiziksel];
    }

    /**
     * Çakışmayan rastgele dosya adı üretir
     */
    public static function dosyaAdiUret($uzanti) {
        return bin2hex(random_bytes(16)) . '.' . strtolower($uzanti);
    }

    /**
     * Yüklenen dosyanın gerçek MIME tipini döndürür
     */
    public static function mimeTipi($tmpYol) {
        if (!function_exists('finfo_open')) {
            return null;
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return null;
        }
        $mime = finfo_file($finfo, $tmpYol);
        finfo_close($finfo);
        return $mime ?: null;
    }

    /**
     * PHP upload hata kodunu okunabilir mesaja çevirir
     */
    public static function yuklemeHatasi($kod) {
        switch ($kod) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return 'Dosya boyutu sunucu limitini aşıyor.';
            case UPLOAD_ERR_PARTIAL:
                return 'Dosya yalnızca kısmen yüklendi, tekrar deneyin.';
            case UPLOAD_ERR_NO_FILE:
                return 'Dosya seçilmedi.';
            case UPLOAD_ERR_NO_TMP_DIR:
                return 'Sunucuda geçici klasör bulunamadı.';
            case UPLOAD_ERR_CANT_WRITE:
                return 'Dosya diske yazılamadı.';
            case UPLOAD_ERR_EXTENSION:
                return 'Bir PHP eklentisi yüklemeyi durdurdu.';
            default:
                return 'Dosya yüklenirken bilinmeyen bir hata oluştu.';
        }
    }

    /**
     * Dosyayı diskten siler (kayıt silinirken)
     */
    public static function dosyaSil($goreliYol) {
        $tam = self::tamYol($goreliYol);
        if ($tam !== null) {
            return @unlink($tam);
        }
        return false;
    }

    /**
     * Byte değerini okunabilir biçime çevirir
     */
    public static function boyutFormatla($byte) {
        $byte = (float)$byte;
        $birimler = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($byte >= 1024 && $i < count($birimler) - 1) {
            $byte /= 1024;
            $i++;
        }
        return round($byte, $i === 0 ? 0 : 1) . ' ' . $birimler[$i];
    }
}
