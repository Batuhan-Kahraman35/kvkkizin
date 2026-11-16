<?php
/**
 * Favicon Helper
 *
 * Site ayarlarından yüklenen görseli kök dizine favicon.ico olarak yazar.
 * Admin sayfalarında ortak <head> bulunmadığı için tarayıcının otomatik
 * istediği /favicon.ico dosyası güncellenir.
 */
class FaviconHelper
{
    /** Üretilecek ikon boyutu (px) */
    private const BOYUT = 32;

    /**
     * Kök dizindeki favicon.ico tam yolu
     */
    public static function icoYolu()
    {
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'favicon.ico';
    }

    /**
     * Yüklenen görselden kök dizindeki favicon.ico dosyasını üretir.
     *
     * @param string $kaynakYol Yüklenen dosyanın sunucudaki tam yolu
     * @param string $uzanti    Dosya uzantısı (ico, png, jpg, jpeg, gif, webp, svg)
     * @return array{basarili: bool, mesaj: string}
     */
    public static function guncelle($kaynakYol, $uzanti)
    {
        $uzanti = strtolower($uzanti);
        $hedef  = self::icoYolu();

        // ICO zaten hedef formatta, doğrudan kopyalanır
        if ($uzanti === 'ico') {
            if (!@copy($kaynakYol, $hedef)) {
                return self::hata('favicon.ico kök dizine yazılamadı (yazma izni yok).');
            }
            return ['basarili' => true, 'mesaj' => ''];
        }

        if ($uzanti === 'svg') {
            return self::hata('SVG dosyasından favicon.ico üretilemez. PNG veya ICO yükleyin.');
        }

        if (!function_exists('imagecreatetruecolor')) {
            return self::hata('GD eklentisi bulunamadı, favicon.ico güncellenemedi.');
        }

        $kaynak = self::gorselAc($kaynakYol, $uzanti);
        if (!$kaynak) {
            return self::hata('Görsel okunamadı, favicon.ico güncellenemedi.');
        }

        $ikon = imagecreatetruecolor(self::BOYUT, self::BOYUT);
        imagealphablending($ikon, false);
        imagesavealpha($ikon, true);
        $seffaf = imagecolorallocatealpha($ikon, 0, 0, 0, 127);
        imagefilledrectangle($ikon, 0, 0, self::BOYUT, self::BOYUT, $seffaf);

        imagecopyresampled(
            $ikon, $kaynak,
            0, 0, 0, 0,
            self::BOYUT, self::BOYUT,
            imagesx($kaynak), imagesy($kaynak)
        );
        imagedestroy($kaynak);

        ob_start();
        imagepng($ikon);
        $pngVeri = ob_get_clean();
        imagedestroy($ikon);

        if (@file_put_contents($hedef, self::icoPaketle($pngVeri)) === false) {
            return self::hata('favicon.ico kök dizine yazılamadı (yazma izni yok).');
        }

        return ['basarili' => true, 'mesaj' => ''];
    }

    /**
     * Uzantıya göre GD kaynağı oluşturur
     */
    private static function gorselAc($yol, $uzanti)
    {
        switch ($uzanti) {
            case 'png':
                return @imagecreatefrompng($yol);
            case 'jpg':
            case 'jpeg':
                return @imagecreatefromjpeg($yol);
            case 'gif':
                return @imagecreatefromgif($yol);
            case 'webp':
                return function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($yol) : false;
        }
        return false;
    }

    /**
     * PNG verisini ICO kabına sarar.
     * ICO içinde PNG saklama Vista sonrası tüm tarayıcılarca desteklenir.
     */
    private static function icoPaketle($pngVeri)
    {
        $baslik = pack('vvv', 0, 1, 1);   // reserved, tip = ikon, görsel sayısı

        $kayit = pack(
            'CCCCvvVV',
            self::BOYUT,          // genişlik
            self::BOYUT,          // yükseklik
            0,                    // palet rengi yok
            0,                    // reserved
            1,                    // renk düzlemi
            32,                   // bit derinliği
            strlen($pngVeri),     // veri boyutu
            22                    // veri başlangıç konumu (6 + 16)
        );

        return $baslik . $kayit . $pngVeri;
    }

    private static function hata($mesaj)
    {
        return ['basarili' => false, 'mesaj' => $mesaj];
    }
}
