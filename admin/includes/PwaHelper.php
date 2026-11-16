<?php
/**
 * PwaHelper — VAPID okuma + push gönderim yardımcıları
 * VAPID anahtarları: Entegrasyonlar (kod 'webpush') → EntegrasyonKanallari (kanal_adi 'vapid')
 *   kanal_ayarlar JSON: {"public":"...", "private":"-----BEGIN EC PRIVATE KEY-----...", "subject":"mailto:..."}
 */
require_once __DIR__ . '/WebPush.php';

class PwaHelper
{
    private static ?array $vapidCache = null;

    /**
     * VAPID ayarlarını DB'den okur.
     * @return array{public:string, private:string, subject:string}|null
     */
    public static function vapid(Database $db): ?array
    {
        if (self::$vapidCache !== null) {
            return self::$vapidCache;
        }

        $row = $db->fetchOne("
            SELECT TOP 1 k.kanal_ayarlar
            FROM Entegrasyonlar e
            INNER JOIN EntegrasyonKanallari k ON k.kanal_entegrasyon_id = e.entegrasyon_id
            WHERE e.entegrasyon_kod = 'webpush'
              AND e.entegrasyon_durum = 1
              AND k.kanal_durum = 1
            ORDER BY k.kanal_varsayilan DESC, k.kanal_id ASC
        ");

        if (!$row || empty($row['kanal_ayarlar'])) {
            return null;
        }

        $ayar = json_decode($row['kanal_ayarlar'], true);
        if (!is_array($ayar) || empty($ayar['public']) || empty($ayar['private'])) {
            return null;
        }

        self::$vapidCache = [
            'public'  => $ayar['public'],
            'private' => $ayar['private'],
            'subject' => $ayar['subject'] ?? 'mailto:gelistirici@example.com',
        ];
        return self::$vapidCache;
    }

    /**
     * Bir kullanıcının tüm aktif cihazlarına push gönderir.
     * @return array{gonderilen:int, hatali:int, cihaz:int}
     */
    public static function kullaniciyaGonder(Database $db, int $kullaniciId, array $veri): array
    {
        $abonelikler = $db->fetchAll("
            SELECT abonelik_id, abonelik_endpoint, abonelik_p256dh, abonelik_auth
            FROM Bildirim_Abonelikleri
            WHERE abonelik_kullanici_id = ? AND Durum = 1
        ", [$kullaniciId]);

        return self::topluGonder($db, $abonelikler ?: [], $veri);
    }

    /**
     * Tüm aktif cihazlara push gönderir (genel duyuru).
     */
    public static function tumCihazlaraGonder(Database $db, array $veri): array
    {
        $abonelikler = $db->fetchAll("
            SELECT abonelik_id, abonelik_endpoint, abonelik_p256dh, abonelik_auth
            FROM Bildirim_Abonelikleri
            WHERE Durum = 1
        ");

        return self::topluGonder($db, $abonelikler ?: [], $veri);
    }

    /**
     * Abonelik listesine gönderim; 404/410 dönen (silinmiş) abonelikleri pasife çeker.
     */
    private static function topluGonder(Database $db, array $abonelikler, array $veri): array
    {
        $sonuc = ['gonderilen' => 0, 'hatali' => 0, 'cihaz' => count($abonelikler)];

        if (empty($abonelikler)) {
            return $sonuc;
        }

        $vapid = self::vapid($db);
        if (!$vapid) {
            $sonuc['hatali'] = count($abonelikler);
            error_log('PwaHelper: VAPID ayarları bulunamadı (webpush/vapid kanalı)');
            return $sonuc;
        }

        $wp = new WebPush($vapid['public'], $vapid['private'], $vapid['subject']);

        foreach ($abonelikler as $abone) {
            $r = $wp->gonder(
                $abone['abonelik_endpoint'],
                $abone['abonelik_p256dh'],
                $abone['abonelik_auth'],
                $veri
            );

            if ($r['success']) {
                $sonuc['gonderilen']++;
            } else {
                $sonuc['hatali']++;
                // Abonelik artık geçersiz → pasife çek
                if (in_array($r['http_code'], [404, 410], true)) {
                    $db->execute("UPDATE Bildirim_Abonelikleri SET Durum = 0, GuncellemeTarihi = GETDATE() WHERE abonelik_id = ?", [$abone['abonelik_id']]);
                } else {
                    error_log('Push hatası [' . $r['http_code'] . ']: ' . $r['message']);
                }
            }
        }

        return $sonuc;
    }
}
