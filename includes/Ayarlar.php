<?php
/**
 * Sistem ayarları (tanim_Ayarlar + Firma_Ayarlari)
 *
 * Değer şu sırayla bulunur: firmanın kendi değeri → üst firmanın değeri → genel değer.
 * Firmaya özel değer yalnız ayar_firma_ozel = 1 olan ayarlarda dikkate alınır.
 * Sayı tipindeki değerler ayar_min / ayar_max aralığına çekilir.
 * Aynı istek içinde her firma için en fazla bir sorgu atılır.
 *
 *   Ayarlar::sayi('otp_gecerlilik_sn', $firmaId)   → 300
 *   Ayarlar::liste('webhook_tekrar_dk')             → [1, 10, 60]
 */

require_once __DIR__ . '/../admin/db.php';

class Ayarlar
{
    /** Üst firma zincirinde en fazla bu kadar yukarı çıkılır */
    const MAX_DERINLIK = 5;

    /** @var array|null kod => tanım satırı */
    private static $tanimlar = null;

    /** @var array firma_id => [kod => değer] */
    private static $firmaDegerleri = [];

    /** Ham değer (string). Tanımsız kodda hata verir. */
    public static function al($kod, $firmaId = null)
    {
        $tanim = self::tanim($kod);
        $deger = $tanim['ayar_deger'];

        if ($firmaId && (int)$tanim['ayar_firma_ozel'] === 1) {
            $firma = self::firmaDegerleri((int)$firmaId);
            if (isset($firma[$kod])) {
                $deger = $firma[$kod];
            }
        }
        return (string)$deger;
    }

    /** Sayı; aralık dışındaysa sınıra çekilir, sayı değilse genel değer kullanılır */
    public static function sayi($kod, $firmaId = null)
    {
        $tanim = self::tanim($kod);
        $deger = self::al($kod, $firmaId);
        if (!preg_match('/^-?\d+$/', trim($deger))) {
            $deger = $tanim['ayar_deger'];
        }
        return self::aralik((int)$deger, $tanim);
    }

    /** Virgülle ayrılmış sayı listesi */
    public static function liste($kod, $firmaId = null)
    {
        $tanim = self::tanim($kod);
        $parcalar = array_filter(array_map('trim', explode(',', self::al($kod, $firmaId))), fn($p) => preg_match('/^\d+$/', $p));
        if (!$parcalar) {
            $parcalar = array_map('trim', explode(',', $tanim['ayar_deger']));
        }
        return array_values(array_map(fn($p) => self::aralik((int)$p, $tanim), $parcalar));
    }

    /** Ayar tanımlarının tamamı (Ayarlar modalı için), sıra numarasına göre */
    public static function tanimlar()
    {
        self::tanimlariYukle();
        return self::$tanimlar;
    }

    /** Kayıt sonrası önbelleği boşaltır */
    public static function sifirla()
    {
        self::$tanimlar = null;
        self::$firmaDegerleri = [];
    }

    /* ------------------------------------------------------------------- */

    private static function tanim($kod)
    {
        self::tanimlariYukle();
        if (!isset(self::$tanimlar[$kod])) {
            throw new Exception("tanim_Ayarlar içinde '$kod' ayarı yok veya pasif.");
        }
        return self::$tanimlar[$kod];
    }

    private static function tanimlariYukle()
    {
        if (self::$tanimlar !== null) {
            return;
        }
        $satirlar = Database::getInstance()->fetchAll("
            SELECT ayar_id, ayar_grup, ayar_kod, ayar_adi, ayar_aciklama, ayar_tip, ayar_deger,
                   ayar_min, ayar_max, ayar_firma_ozel, ayar_sira_no
            FROM dbo.tanim_Ayarlar
            WHERE Durum = 1
            ORDER BY ayar_sira_no, ayar_id
        ") ?: [];
        self::$tanimlar = [];
        foreach ($satirlar as $s) {
            self::$tanimlar[$s['ayar_kod']] = $s;
        }
    }

    /** Firmanın ve üst firmalarının özel değerleri; yakın olan uzak olanı ezer */
    private static function firmaDegerleri($firmaId)
    {
        if (isset(self::$firmaDegerleri[$firmaId])) {
            return self::$firmaDegerleri[$firmaId];
        }
        $satirlar = Database::getInstance()->fetchAll("
            WITH zincir AS (
                SELECT firma_id, firma_ust_firma_id, 0 AS derinlik
                FROM dbo.Firmalar WHERE firma_id = ?
                UNION ALL
                SELECT f.firma_id, f.firma_ust_firma_id, z.derinlik + 1
                FROM dbo.Firmalar f
                INNER JOIN zincir z ON f.firma_id = z.firma_ust_firma_id
                WHERE z.derinlik < ? AND f.firma_id <> z.firma_id
            )
            SELECT a.ayar_kod, fa.firma_ayar_deger, z.derinlik
            FROM zincir z
            INNER JOIN dbo.Firma_Ayarlari fa ON fa.firma_ayar_firma_id = z.firma_id AND fa.Durum = 1
            INNER JOIN dbo.tanim_Ayarlar a  ON a.ayar_id = fa.firma_ayar_ayar_id AND a.Durum = 1 AND a.ayar_firma_ozel = 1
            ORDER BY z.derinlik DESC
        ", [$firmaId, self::MAX_DERINLIK]) ?: [];

        $degerler = [];
        foreach ($satirlar as $s) {
            $degerler[$s['ayar_kod']] = $s['firma_ayar_deger'];   // derinlik azaldıkça (firmaya yaklaştıkça) üzerine yazar
        }
        return self::$firmaDegerleri[$firmaId] = $degerler;
    }

    private static function aralik($deger, array $tanim)
    {
        if ($tanim['ayar_min'] !== null && $deger < (int)$tanim['ayar_min']) {
            $deger = (int)$tanim['ayar_min'];
        }
        if ($tanim['ayar_max'] !== null && $deger > (int)$tanim['ayar_max']) {
            $deger = (int)$tanim['ayar_max'];
        }
        return $deger;
    }
}
