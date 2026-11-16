<?php
/**
 * Cron Worker — manuel/tekil tetik
 *
 * Web:
 *   worker.php?gorev=KODU&key=KEY[&param=deger...]
 *   worker.php?zamanlama=ID&key=KEY
 * CLI:
 *   php worker.php gorev=KODU param=deger
 *   php worker.php zamanlama=ID
 */

set_time_limit(600);
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once __DIR__ . '/tasks.php';

header('Content-Type: text/plain; charset=utf-8');

$db = Database::getInstance();

if (!cronKeyDogrula($db)) {
    http_response_code(403);
    echo "Geçersiz anahtar\n";
    exit;
}

// CLI argümanlarını $_GET'e çevir (gorev=x param=y)
if (PHP_SAPI === 'cli') {
    foreach (array_slice($argv ?? [], 1) as $arg) {
        if (str_contains($arg, '=')) {
            [$k, $v] = explode('=', $arg, 2);
            $_GET[$k] = $v;
        }
    }
}

$gorevKodu   = trim($_GET['gorev'] ?? '');
$zamanlamaId = (int)($_GET['zamanlama'] ?? 0);

if ($gorevKodu === '' && $zamanlamaId <= 0) {
    echo "Kullanım: worker.php?gorev=KODU veya worker.php?zamanlama=ID\n";
    exit;
}

$params = [];
$gorevId = 0;

if ($zamanlamaId > 0) {
    // Zamanlama tetiği: parametreler DB'den
    $z = $db->fetchOne("
        SELECT z.CronZamanlamalar_GorevId, z.CronZamanlamalar_SabitParametreler, g.CronGorevler_GorevKodu
        FROM CronZamanlamalar z
        INNER JOIN CronGorevler g ON g.CronGorevler_Id = z.CronZamanlamalar_GorevId
        WHERE z.CronZamanlamalar_Id = ?
    ", [$zamanlamaId]);

    if (!$z) {
        echo "Zamanlama bulunamadı: $zamanlamaId\n";
        exit;
    }

    $gorevId   = (int)$z['CronZamanlamalar_GorevId'];
    $gorevKodu = $z['CronGorevler_GorevKodu'];
    $params    = json_decode($z['CronZamanlamalar_SabitParametreler'] ?? '', true) ?: [];
} else {
    // Görev tetiği: parametreler URL/CLI'dan (gorev+key hariç hepsi)
    $g = $db->fetchOne("SELECT CronGorevler_Id FROM CronGorevler WHERE CronGorevler_GorevKodu = ? AND Durum = 1", [$gorevKodu]);
    if (!$g) {
        echo "Görev bulunamadı: $gorevKodu\n";
        exit;
    }
    $gorevId = (int)$g['CronGorevler_Id'];

    foreach ($_GET as $k => $v) {
        if (!in_array($k, ['gorev', 'zamanlama', 'key'], true)) {
            $params[$k] = $v;
        }
    }
}

$basZaman = microtime(true);
$logId = cronLogOlustur($db, $gorevId, $zamanlamaId > 0 ? $zamanlamaId : null, $params, 1);

$sonuc = gorevCalistir($gorevKodu, $params, $db);

cronLogBitir($db, $logId, (int)$sonuc['durum'], $sonuc['sonuc'], $basZaman);

echo "Görev : $gorevKodu\n";
echo "Durum : " . ($sonuc['durum'] === 1 ? 'BAŞARILI' : 'HATA') . "\n";
echo "Sonuç : {$sonuc['sonuc']}\n";
echo "Süre  : " . round(microtime(true) - $basZaman, 2) . " sn\n";
echo "----------------------------------------\n";
echo $sonuc['cikti'];
