<?php
/**
 * Test bildirimi — kendi kullanıcına çan kaydı + push gönderir
 */
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/Bildirim.php';
requireAuth();

header('Content-Type: application/json; charset=utf-8');

$db = Database::getInstance();
$kullaniciId = (int)($_SESSION['user_id'] ?? 0);

// Aktif cihaz kontrolü
$cihaz = $db->fetchOne("SELECT COUNT(*) AS adet FROM Bildirim_Abonelikleri WHERE abonelik_kullanici_id = ? AND Durum = 1", [$kullaniciId]);
$cihazAdet = (int)($cihaz['adet'] ?? 0);

$bildirimId = Bildirim::olustur($db, [
    'kullanici_id' => $kullaniciId,
    'baslik'       => '🔔 Test Bildirimi',
    'govde'        => 'Push bildirim sistemi çalışıyor! (' . date('H:i:s') . ')',
    'url'          => '/admin/',
    'tip'          => 'basari',
    'push'         => true,
    'olusturan'    => $kullaniciId,
]);

if ($bildirimId) {
    $mesaj = 'Bildirim oluşturuldu (#' . $bildirimId . ').';
    if ($cihazAdet === 0) {
        $mesaj .= ' Ancak gönderilecek aktif cihaz yok — önce "🔔 Bildirimleri Aç" ile abone olun.';
    } else {
        $mesaj .= ' ' . $cihazAdet . ' cihaza push gönderildi.';
    }
    echo json_encode(['success' => true, 'message' => $mesaj, 'cihaz' => $cihazAdet], JSON_UNESCAPED_UNICODE);
} else {
    echo json_encode(['success' => false, 'message' => 'Bildirim oluşturulamadı'], JSON_UNESCAPED_UNICODE);
}
