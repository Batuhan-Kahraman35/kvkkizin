<?php
/**
 * Bildirim API — Bildirimler tablosundan beslenir (kendi + genel)
 * Ek olarak: personel-yonetimi yetkisi olanlara türetilmiş "Yeni Başvuru" uyarıları.
 * Aksiyonlar: get_notifications | get_count | mark_read
 */
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
require_once __DIR__ . '/../includes/Bildirim.php';
requireAuth();

header('Content-Type: application/json; charset=utf-8');

$db = Database::getInstance();
$user = Auth::user();
$kullaniciId = (int)$user['kullanici_id'];

// Tip → ikon/renk eşlemesi
function bildirimGorunum(string $tip): array
{
    switch ($tip) {
        case 'basari': return ['icon' => 'bi-check-circle', 'renk' => 'success'];
        case 'uyari':  return ['icon' => 'bi-exclamation-triangle', 'renk' => 'warning'];
        case 'hata':   return ['icon' => 'bi-x-circle', 'renk' => 'danger'];
        default:       return ['icon' => 'bi-info-circle', 'renk' => 'info'];
    }
}

function zamanFarki(string $tarih): string
{
    $dakika = (int)floor((time() - strtotime($tarih)) / 60);
    if ($dakika < 1) return 'şimdi';
    if ($dakika < 60) return $dakika . ' dakika önce';
    if ($dakika < 1440) return floor($dakika / 60) . ' saat önce';
    return floor($dakika / 1440) . ' gün önce';
}

// Türetilmiş "Yeni Başvuru" uyarıları (sadece yetkisi olanlara)
function yeniBasvurular(Database $db, array $user): array
{
    $izin = PageAuth::checkPagePermissions(
        $user['kullanici_id'],
        $user['departman_id'],
        'personel-yonetimi.php'
    );
    if (empty($izin['has_access'])) {
        return [];
    }

    $basvurular = $db->fetchAll("
        SELECT kullanici_id, kullanici_ad, kullanici_soyad, kullanici_email,
               CONVERT(VARCHAR(19), kullanici_olusturma_tarihi, 120) AS olusturma_tarihi
        FROM kullanicilar
        WHERE kullanici_durum IS NULL
          AND kullanici_olusturma_tarihi >= DATEADD(DAY, -30, GETDATE())
        ORDER BY kullanici_olusturma_tarihi DESC
    ");

    $liste = [];
    foreach ($basvurular as $b) {
        $adSoyad = trim(($b['kullanici_ad'] ?? '') . ' ' . ($b['kullanici_soyad'] ?? ''));
        if ($adSoyad === '') {
            $adSoyad = $b['kullanici_email'];
        }
        $liste[] = [
            'id'     => 0, // türetilmiş — mark_read uygulanmaz
            'tip'    => 'basvuru',
            'baslik' => 'Yeni Başvuru',
            'mesaj'  => $adSoyad . ' başvuruda bulundu',
            'zaman'  => zamanFarki($b['olusturma_tarihi']),
            'link'   => '/admin/pages/personel-yonetimi.php',
            'icon'   => 'bi-person-plus',
            'renk'   => 'warning',
            'okundu' => 0,
        ];
    }
    return $liste;
}

try {
    $action = $_GET['action'] ?? $_POST['action'] ?? '';

    if ($action === 'get_notifications') {
        $liste = [];

        // 1) Türetilmiş uyarılar (yetkiliyse)
        $basvurular = yeniBasvurular($db, $user);
        $liste = array_merge($liste, $basvurular);

        // 2) Bildirimler tablosu (kendi + genel)
        foreach (Bildirim::listele($db, $kullaniciId, 20) as $b) {
            $gorunum = bildirimGorunum($b['bildirim_tip'] ?? 'info');
            $liste[] = [
                'id'     => (int)$b['bildirim_id'],
                'tip'    => $b['bildirim_tip'],
                'baslik' => $b['bildirim_baslik'],
                'mesaj'  => $b['bildirim_govde'] ?? '',
                'zaman'  => zamanFarki($b['OlusturmaTarihi']),
                'link'   => $b['bildirim_url'] ?: '#',
                'icon'   => $gorunum['icon'],
                'renk'   => $gorunum['renk'],
                'okundu' => (int)$b['bildirim_okundu'],
            ];
        }

        // Okunmamış sayısı: türetilmiş uyarılar + tablodaki okunmamışlar
        $okunmamis = count($basvurular) + Bildirim::okunmamisSayisi($db, $kullaniciId);

        echo json_encode([
            'success' => true,
            'data'    => $liste,
            'count'   => $okunmamis,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'get_count') {
        $okunmamis = count(yeniBasvurular($db, $user)) + Bildirim::okunmamisSayisi($db, $kullaniciId);
        echo json_encode(['success' => true, 'count' => $okunmamis]);
        exit;
    }

    if ($action === 'mark_read') {
        $bildirimId = (int)($_POST['bildirim_id'] ?? $_GET['bildirim_id'] ?? 0);
        Bildirim::okunduYap($db, $kullaniciId, $bildirimId > 0 ? $bildirimId : null);
        echo json_encode(['success' => true]);
        exit;
    }

    throw new Exception('Geçersiz işlem');
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
