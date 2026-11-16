<?php
/**
 * Firma sözleşmesi erişim kuralları
 *
 * Kural:
 *  - Firmanın kendisi ve üst firma zinciri birlikte değerlendirilir.
 *  - Zincirde hiç aktif sözleşme kaydı yoksa firma kontrole tabi değildir (serbest).
 *  - Kayıt varsa, zincirdeki firmalardan en az birinin bugünü kapsayan aktif
 *    sözleşmesi olmalıdır; yoksa erişim kapalıdır.
 *  - Ödeme durumu erişimi etkilemez, yalnız tarih aralığına bakılır.
 *  - Tarih DB saatinden (GETDATE) alınır.
 */
class SozlesmeHelper {

    /** Üst firma zincirinde çıkılacak en fazla seviye (döngüye karşı) */
    const MAX_SEVIYE = 20;

    /** "Yaklaşan bitiş" uyarısı için gün sayısı */
    const YAKLASAN_GUN = 30;

    /**
     * Firmanın sözleşme durumunu döndürür.
     *
     * @return array|null ['kayit_var' => bool, 'gecerli_var' => bool] — sorgu hatasında null
     */
    public static function firmaDurumu($db, $firmaId) {
        $firmaId = (int)$firmaId;
        if ($firmaId <= 0) {
            return ['kayit_var' => false, 'gecerli_var' => false];
        }

        $row = $db->fetchOne("
            WITH Zincir AS (
                SELECT firma_id, firma_ust_firma_id, 0 AS seviye
                FROM dbo.Firmalar WHERE firma_id = ?
                UNION ALL
                SELECT f.firma_id, f.firma_ust_firma_id, z.seviye + 1
                FROM dbo.Firmalar f
                INNER JOIN Zincir z ON f.firma_id = z.firma_ust_firma_id
                WHERE z.seviye < ?
            )
            SELECT
                COUNT(s.firma_sozlesme_id) AS kayit_sayisi,
                SUM(CASE WHEN CAST(GETDATE() AS DATE) BETWEEN s.firma_sozlesme_baslangic_tarihi
                                                         AND s.firma_sozlesme_bitis_tarihi
                         THEN 1 ELSE 0 END) AS gecerli_sayisi
            FROM Zincir z
            LEFT JOIN dbo.Firma_Sozlesmeleri s
                   ON s.firma_id = z.firma_id AND s.firma_sozlesme_durum = 1
        ", [$firmaId, self::MAX_SEVIYE]);

        if (!$row) {
            return null;
        }

        return [
            'kayit_var'   => (int)$row['kayit_sayisi'] > 0,
            'gecerli_var' => (int)($row['gecerli_sayisi'] ?? 0) > 0,
        ];
    }

    /**
     * Firmanın kullanıcıları giriş yapabilir mi?
     * Sorgu hatasında (ör. tablo henüz yok) erişim kapatılmaz.
     */
    public static function erisimVarMi($db, $firmaId) {
        try {
            $durum = self::firmaDurumu($db, $firmaId);
        } catch (Throwable $e) {
            error_log('Sözleşme kontrolü hatası: ' . $e->getMessage());
            return true;
        }

        if ($durum === null) {
            error_log('Sözleşme kontrolü yapılamadı (Firma ID: ' . (int)$firmaId . ')');
            return true;
        }

        return !$durum['kayit_var'] || $durum['gecerli_var'];
    }

    /**
     * Kapsamdaki aktif firmalardan sözleşme nedeniyle erişimi kapalı olanların sayısı.
     *
     * @param string $scopeSql  PageAuth::firmaScopeWhere çıktısı (kolon: firma_id)
     */
    public static function erisimiKapaliFirmaSayisi($db, $scopeSql, array $scopeParams) {
        $row = $db->fetchOne("
            WITH Zincir AS (
                SELECT firma_id AS kok_id, firma_id, firma_ust_firma_id, 0 AS seviye
                FROM dbo.Firmalar
                WHERE firma_durum = 1 AND $scopeSql
                UNION ALL
                SELECT z.kok_id, f.firma_id, f.firma_ust_firma_id, z.seviye + 1
                FROM dbo.Firmalar f
                INNER JOIN Zincir z ON f.firma_id = z.firma_ust_firma_id
                WHERE z.seviye < ?
            ),
            Ozet AS (
                SELECT z.kok_id,
                       COUNT(s.firma_sozlesme_id) AS kayit_sayisi,
                       SUM(CASE WHEN CAST(GETDATE() AS DATE) BETWEEN s.firma_sozlesme_baslangic_tarihi
                                                                AND s.firma_sozlesme_bitis_tarihi
                                THEN 1 ELSE 0 END) AS gecerli_sayisi
                FROM Zincir z
                LEFT JOIN dbo.Firma_Sozlesmeleri s
                       ON s.firma_id = z.firma_id AND s.firma_sozlesme_durum = 1
                GROUP BY z.kok_id
            )
            SELECT COUNT(*) AS sayi
            FROM Ozet
            WHERE kayit_sayisi > 0 AND ISNULL(gecerli_sayisi, 0) = 0
            OPTION (MAXRECURSION 100)
        ", array_merge($scopeParams, [self::MAX_SEVIYE]));

        return (int)($row['sayi'] ?? 0);
    }
}
