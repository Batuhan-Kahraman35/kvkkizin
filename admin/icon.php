<?php
/**
 * PWA İkon Üretici
 * DB'deki logoyu (site_ayarlari_logo_url) GD ile kare PNG ikona çevirir.
 * Sonuç diskte cache'lenir: assets/images/pwa-cache/
 */
require_once __DIR__ . '/db.php';

$size     = (int)($_GET['size'] ?? 192);
$maskable = isset($_GET['maskable']) && $_GET['maskable'] == '1';

if (!in_array($size, [96, 144, 192, 256, 384, 512], true)) {
    $size = 192;
}

$cacheDir  = __DIR__ . '/assets/images/pwa-cache';
$cacheFile = $cacheDir . '/icon-' . $size . ($maskable ? '-maskable' : '') . '.png';

// Cache varsa doğrudan dön
if (is_file($cacheFile)) {
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=86400');
    readfile($cacheFile);
    exit;
}

if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0775, true);
}

// Logo yolunu DB'den al
$logoYolu = null;
try {
    $db = Database::getInstance();
    $ayar = $db->fetchOne("SELECT TOP 1 site_ayarlari_logo_url FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
    if ($ayar && !empty($ayar['site_ayarlari_logo_url'])) {
        $logoYolu = $ayar['site_ayarlari_logo_url'];
    }
} catch (Exception $e) {
    // logo yoksa yazı bazlı ikon üretilir
}

// Logo dosyasını çözümle (URL veya yerel yol)
$logoImg = null;
if ($logoYolu) {
    if (preg_match('#^https?://#i', $logoYolu)) {
        $veri = @file_get_contents($logoYolu);
        if ($veri !== false) {
            $logoImg = @imagecreatefromstring($veri);
        }
    } else {
        // "\Admin\assets\images\logo.png" gibi yerel yol → httpdocs köküne göre
        $yerel = str_replace('\\', '/', $logoYolu);
        $yerel = ltrim($yerel, '/');
        $tam   = dirname(__DIR__) . '/' . $yerel;
        if (is_file($tam)) {
            $logoImg = @imagecreatefromstring(file_get_contents($tam));
        }
    }
}

// Tuval oluştur
$tuval = imagecreatetruecolor($size, $size);
imagesavealpha($tuval, true);

if ($maskable) {
    // Maskable: opak arka plan + %10 güvenli boşluk
    $arka = imagecolorallocate($tuval, 13, 110, 253); // #0d6efd
    imagefill($tuval, 0, 0, $arka);
    $bosluk = (int)($size * 0.10);
} else {
    $seffaf = imagecolorallocatealpha($tuval, 0, 0, 0, 127);
    imagefill($tuval, 0, 0, $seffaf);
    $bosluk = 0;
}

if ($logoImg) {
    $lw = imagesx($logoImg);
    $lh = imagesy($logoImg);
    $alan = $size - 2 * $bosluk;
    $oran = min($alan / $lw, $alan / $lh);
    $yw = (int)($lw * $oran);
    $yh = (int)($lh * $oran);
    $x  = (int)(($size - $yw) / 2);
    $y  = (int)(($size - $yh) / 2);
    imagecopyresampled($tuval, $logoImg, $x, $y, 0, 0, $yw, $yh, $lw, $lh);
    imagedestroy($logoImg);
} else {
    // Logo yoksa: baş harf ile yazı bazlı ikon
    if (!$maskable) {
        $arka = imagecolorallocate($tuval, 13, 110, 253);
        imagefilledrectangle($tuval, 0, 0, $size, $size, $arka);
    }
    $beyaz = imagecolorallocate($tuval, 255, 255, 255);
    $harf  = 'T';
    $fontBoyut = 5;
    $fw = imagefontwidth($fontBoyut);
    $fh = imagefontheight($fontBoyut);
    // GD dahili font küçük — ölçekli çizim
    $tmp = imagecreatetruecolor($fw + 4, $fh + 4);
    $tArka = imagecolorallocate($tmp, 13, 110, 253);
    imagefill($tmp, 0, 0, $tArka);
    imagestring($tmp, $fontBoyut, 2, 2, $harf, imagecolorallocate($tmp, 255, 255, 255));
    $hedef = (int)($size * 0.5);
    imagecopyresized($tuval, $tmp, (int)(($size - $hedef) / 2), (int)(($size - $hedef) / 2), 0, 0, $hedef, $hedef, $fw + 4, $fh + 4);
    imagedestroy($tmp);
}

// Cache'e yaz + çıktı
imagepng($tuval, $cacheFile);
imagedestroy($tuval);

header('Content-Type: image/png');
header('Cache-Control: public, max-age=86400');
readfile($cacheFile);
