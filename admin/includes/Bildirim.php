<?php
/**
 * Bildirim — merkezi bildirim yönetimi
 * Çan (Bildirimler tablosu) + push (PwaHelper) tek API'den.
 */
require_once __DIR__ . '/PwaHelper.php';

class Bildirim
{
    /**
     * Tek bildirim oluşturur; push=true ise anında push da gönderir.
     * $veri: kullanici_id (null=genel), baslik, govde, url, tip (info|basari|uyari|hata), push (bool), olusturan (int)
     * @return int|false bildirim_id
     */
    public static function olustur(Database $db, array $veri)
    {
        $pushIstendi = !empty($veri['push']);
        $kullaniciId = $veri['kullanici_id'] ?? null;

        $bildirimId = $db->insert('Bildirimler', [
            'bildirim_kullanici_id'    => $kullaniciId,
            'bildirim_baslik'          => $veri['baslik'] ?? 'Bildirim',
            'bildirim_govde'           => $veri['govde'] ?? null,
            'bildirim_url'             => $veri['url'] ?? null,
            'bildirim_tip'             => $veri['tip'] ?? 'info',
            'bildirim_okundu'          => 0,
            'bildirim_push_gonder'     => $pushIstendi ? 1 : 0,
            'bildirim_push_gonderildi' => 0,
            'OlusturanKullanici'       => $veri['olusturan'] ?? null,
            'OlusturmaTarihi'          => date('Y-m-d H:i:s'),
            'Durum'                    => 1,
        ]);

        if (!$bildirimId) {
            return false;
        }

        // İnline push gönderimi (outbox işaretçisi 1 yapılır → cron çift göndermez)
        if ($pushIstendi) {
            $pushVeri = [
                'baslik' => $veri['baslik'] ?? 'Bildirim',
                'govde'  => $veri['govde'] ?? '',
                'url'    => $veri['url'] ?? '/admin/',
            ];

            if ($kullaniciId !== null) {
                PwaHelper::kullaniciyaGonder($db, (int)$kullaniciId, $pushVeri);
            } else {
                PwaHelper::tumCihazlaraGonder($db, $pushVeri);
            }

            $db->execute("UPDATE Bildirimler SET bildirim_push_gonderildi = 1 WHERE bildirim_id = ?", [$bildirimId]);
        }

        return $bildirimId;
    }

    /**
     * Birden çok kullanıcıya aynı bildirimi oluşturur.
     * @return int[] oluşan bildirim_id listesi
     */
    public static function olusturCoklu(Database $db, array $kullaniciIdler, array $veri): array
    {
        $idler = [];
        foreach (array_unique(array_map('intval', $kullaniciIdler)) as $kid) {
            $veri['kullanici_id'] = $kid;
            $id = self::olustur($db, $veri);
            if ($id) {
                $idler[] = $id;
            }
        }
        return $idler;
    }

    /**
     * Genel duyuru: herkese tek kayıt (kullanici_id NULL) + tüm cihazlara push.
     */
    public static function genelDuyuru(Database $db, array $veri)
    {
        $veri['kullanici_id'] = null;
        return self::olustur($db, $veri);
    }

    /**
     * Kullanıcının bildirimlerini listeler (kendi + genel).
     */
    public static function listele(Database $db, int $kullaniciId, int $adet = 20): array
    {
        return $db->fetchAll("
            SELECT TOP (?) bildirim_id, bildirim_baslik, bildirim_govde, bildirim_url,
                   bildirim_tip, bildirim_okundu, bildirim_kullanici_id, OlusturmaTarihi
            FROM Bildirimler
            WHERE Durum = 1
              AND (bildirim_kullanici_id = ? OR bildirim_kullanici_id IS NULL)
            ORDER BY bildirim_id DESC
        ", [$adet, $kullaniciId]);
    }

    /**
     * Okunmamış bildirim sayısı (genel duyurular dahil).
     */
    public static function okunmamisSayisi(Database $db, int $kullaniciId): int
    {
        $row = $db->fetchOne("
            SELECT COUNT(*) AS adet
            FROM Bildirimler
            WHERE Durum = 1
              AND bildirim_okundu = 0
              AND (bildirim_kullanici_id = ? OR bildirim_kullanici_id IS NULL)
        ", [$kullaniciId]);
        return (int)($row['adet'] ?? 0);
    }

    /**
     * Bildirimi okundu yapar. $bildirimId null ise kullanıcının tümü okundu olur.
     * Genel duyurularda (kullanici_id NULL) kayıt herkes için tek olduğundan
     * okundu işareti ilk okuyanla düşer — basit tutulmuştur.
     */
    public static function okunduYap(Database $db, int $kullaniciId, ?int $bildirimId = null): bool
    {
        if ($bildirimId !== null) {
            return $db->execute("
                UPDATE Bildirimler
                SET bildirim_okundu = 1, bildirim_okunma_tarihi = GETDATE(),
                    GuncelleyenKullanici = ?, GuncellemeTarihi = GETDATE()
                WHERE bildirim_id = ?
                  AND (bildirim_kullanici_id = ? OR bildirim_kullanici_id IS NULL)
            ", [$kullaniciId, $bildirimId, $kullaniciId]);
        }

        return $db->execute("
            UPDATE Bildirimler
            SET bildirim_okundu = 1, bildirim_okunma_tarihi = GETDATE(),
                GuncelleyenKullanici = ?, GuncellemeTarihi = GETDATE()
            WHERE bildirim_okundu = 0
              AND (bildirim_kullanici_id = ? OR bildirim_kullanici_id IS NULL)
        ", [$kullaniciId, $kullaniciId]);
    }
}
