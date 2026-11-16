<?php
/**
 * Cihaz push aboneliği kaydet/sil (Bildirim_Abonelikleri)
 */
require_once __DIR__ . '/../auth.php';
requireAuth();

header('Content-Type: application/json; charset=utf-8');

$db = Database::getInstance();
$kullaniciId = (int)($_SESSION['user_id'] ?? 0);

$girdi = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $girdi['action'] ?? '';

try {
    switch ($action) {
        case 'kaydet':
            $endpoint = trim($girdi['endpoint'] ?? '');
            $p256dh   = trim($girdi['p256dh'] ?? '');
            $auth     = trim($girdi['auth'] ?? '');
            $tarayici = mb_substr(trim($girdi['tarayici'] ?? ''), 0, 500);

            if ($endpoint === '' || $p256dh === '' || $auth === '') {
                echo json_encode(['success' => false, 'message' => 'Eksik abonelik verisi']);
                exit;
            }

            // Aynı endpoint varsa güncelle (cihaz başka kullanıcıya geçmiş olabilir)
            $mevcut = $db->fetchOne("SELECT abonelik_id FROM Bildirim_Abonelikleri WHERE abonelik_endpoint = ?", [$endpoint]);

            if ($mevcut) {
                $db->update('Bildirim_Abonelikleri', [
                    'abonelik_kullanici_id' => $kullaniciId,
                    'abonelik_p256dh'       => $p256dh,
                    'abonelik_auth'         => $auth,
                    'abonelik_tarayici'     => $tarayici,
                    'GuncelleyenKullanici'  => $kullaniciId,
                    'GuncellemeTarihi'      => date('Y-m-d H:i:s'),
                    'Durum'                 => 1,
                ], ['abonelik_id' => $mevcut['abonelik_id']]);
            } else {
                $db->insert('Bildirim_Abonelikleri', [
                    'abonelik_kullanici_id' => $kullaniciId,
                    'abonelik_endpoint'     => $endpoint,
                    'abonelik_p256dh'       => $p256dh,
                    'abonelik_auth'         => $auth,
                    'abonelik_tarayici'     => $tarayici,
                    'OlusturanKullanici'    => $kullaniciId,
                    'OlusturmaTarihi'       => date('Y-m-d H:i:s'),
                    'Durum'                 => 1,
                ]);
            }

            echo json_encode(['success' => true, 'message' => 'Abonelik kaydedildi']);
            break;

        case 'sil':
            $endpoint = trim($girdi['endpoint'] ?? '');
            if ($endpoint === '') {
                echo json_encode(['success' => false, 'message' => 'Endpoint gerekli']);
                exit;
            }
            $db->execute("UPDATE Bildirim_Abonelikleri SET Durum = 0, GuncelleyenKullanici = ?, GuncellemeTarihi = GETDATE() WHERE abonelik_endpoint = ?", [$kullaniciId, $endpoint]);
            echo json_encode(['success' => true, 'message' => 'Abonelik silindi']);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Geçersiz işlem']);
    }
} catch (Exception $e) {
    error_log('pwa-abone hata: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'İşlem sırasında hata oluştu']);
}
