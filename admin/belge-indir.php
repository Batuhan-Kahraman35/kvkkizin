<?php
/**
 * Firma Belgesi İndirme / Önizleme
 *
 * Belgeler web kökü dışında saklandığı için tek erişim noktası burasıdır.
 * Sayfa yetkisi ve firma kapsamı doğrulanmadan hiçbir dosya sunulmaz.
 *
 * Kullanım: /admin/belge-indir.php?id=12          -> indirir
 *           /admin/belge-indir.php?id=12&onizle=1 -> tarayıcıda gösterir
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/PageAuth.php';
require_once __DIR__ . '/includes/BelgeHelper.php';
requireAuth();

$user = Auth::user();
$db = Database::getInstance();

// Yetki, belge sayfası üzerinden değerlendirilir
$permissions = PageAuth::checkPagePermissions($user['kullanici_id'], $user['departman_id'], 'firma-belgeleri.php');

function belgeHatasi($kod, $mesaj) {
    http_response_code($kod);
    header('Content-Type: text/plain; charset=UTF-8');
    echo $mesaj;
    exit;
}

if (!$permissions['has_access']) {
    belgeHatasi(403, 'Bu belgeye erişim yetkiniz yok.');
}

$belgeId = (int)($_GET['id'] ?? 0);
if ($belgeId <= 0) {
    belgeHatasi(400, 'Geçersiz belge kimliği.');
}

$belge = $db->fetchOne("
    SELECT
        fb.firma_belge_id,
        fb.firma_id,
        fb.firma_belge_dosya_adi,
        fb.firma_belge_dosya_yolu,
        fb.firma_belge_dosya_uzanti,
        fb.firma_belge_dosya_mime
    FROM dbo.Firma_Belgeleri fb
    WHERE fb.firma_belge_id = ?
", [$belgeId]);

if (!$belge) {
    belgeHatasi(404, 'Belge bulunamadı.');
}

// Firma kapsamı kontrolü
$firmaScopeIds = PageAuth::firmaScopeIds($permissions, $user['firma_id'] ?? null);
if ($firmaScopeIds !== null && !in_array((int)$belge['firma_id'], $firmaScopeIds, true)) {
    belgeHatasi(403, 'Bu belgeye erişim yetkiniz yok.');
}

$tamYol = BelgeHelper::tamYol($belge['firma_belge_dosya_yolu']);
if ($tamYol === null) {
    belgeHatasi(404, 'Belge dosyası sunucuda bulunamadı.');
}

$onizle = isset($_GET['onizle']) && $_GET['onizle'] == '1';
$mime = $belge['firma_belge_dosya_mime'] ?: 'application/octet-stream';

// Yalnızca güvenli tiplerde tarayıcı içi gösterime izin ver
$onizlenebilir = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'image/gif'];
$disposition = ($onizle && in_array($mime, $onizlenebilir, true)) ? 'inline' : 'attachment';

$indirmeAdi = $belge['firma_belge_dosya_adi'] ?: ('belge.' . $belge['firma_belge_dosya_uzanti']);
$indirmeAdi = str_replace(['"', "\r", "\n"], '', $indirmeAdi);

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($tamYol));
header('Content-Disposition: ' . $disposition . '; filename="' . rawurlencode($indirmeAdi) . '"; filename*=UTF-8\'\'' . rawurlencode($indirmeAdi));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0, no-cache');
header('Pragma: no-cache');

readfile($tamYol);
exit;
