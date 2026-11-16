<?php
/**
 * PWA Manifest (dinamik)
 * Başlık ve tema DB'den (tanim_site_ayarlari) okunur.
 */
require_once __DIR__ . '/db.php';

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=3600');

$siteBaslik = 'KVKK İzin Portal';
try {
    $db = Database::getInstance();
    $ayar = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
    if ($ayar && !empty($ayar['site_ayarlari_site_title'])) {
        $siteBaslik = $ayar['site_ayarlari_site_title'];
    }
} catch (Exception $e) {
    // varsayılan başlık kullanılır
}

$kisaAd = mb_strlen($siteBaslik) > 12 ? mb_substr($siteBaslik, 0, 12) : $siteBaslik;

echo json_encode([
    'name'             => $siteBaslik,
    'short_name'       => $kisaAd,
    'description'      => $siteBaslik . ' yönetim paneli',
    'id'               => '/admin/',
    'start_url'        => '/admin/',
    'scope'            => '/admin/',
    'display'          => 'standalone',
    'orientation'      => 'any',
    'background_color' => '#f8f9fa',
    'theme_color'      => '#0d6efd',
    'lang'             => 'tr',
    'icons'            => [
        ['src' => '/admin/icon.php?size=192', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => '/admin/icon.php?size=512', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => '/admin/icon.php?size=192&maskable=1', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'maskable'],
        ['src' => '/admin/icon.php?size=512&maskable=1', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
