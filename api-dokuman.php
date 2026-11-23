<?php
/**
 * İzin API dokümantasyonu (herkese açık; gizli bilgi içermez)
 *   /api-dokuman              → Scalar arayüzü
 *   /api-dokuman?format=json  → OpenAPI 3.1 belgesi (Postman / Insomnia / kod üreticiler için içe aktarılabilir)
 */

require_once __DIR__ . '/includes/Api/OpenApi.php';

$kok = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'ornek-kvkk.com');
$site = Database::getInstance()->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteAdi = $site['site_ayarlari_site_title'] ?? 'KVKK İzin';

if (($_GET['format'] ?? '') === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: public, max-age=300');
    header('X-Content-Type-Options: nosniff');
    if (isset($_GET['indir'])) {
        header('Content-Disposition: attachment; filename="izin-api.openapi.json"');
    }
    echo json_encode(ApiOpenApi::belge($kok, $siteAdi), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($siteAdi) ?> İzin API Dokümantasyonu</title>
    <meta name="description" content="<?= htmlspecialchars($siteAdi) ?> İzin API: KVKK, açık rıza ve ETK izni toplama, sorgulama ve iptal uç noktaları.">
    <style>body { margin: 0; }</style>
</head>
<body>
    <div id="dokuman"></div>
    <script src="https://cdn.jsdelivr.net/npm/@scalar/api-reference@1.76.0"></script>
    <script>
        Scalar.createApiReference('#dokuman', {
            url: '/api-dokuman?format=json',
            hideClientButton: false,
            hideModels: false,
            defaultHttpClient: { targetKey: 'php', clientKey: 'curl' },
            documentDownloadType: 'json',
            metaData: { title: <?= json_encode($siteAdi . ' İzin API', JSON_UNESCAPED_UNICODE) ?> }
        });
    </script>
</body>
</html>
