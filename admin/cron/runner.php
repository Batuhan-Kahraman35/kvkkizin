<?php
/**
 * Cron Runner — tek giriş noktası
 *
 * Plesk Cron her dakika çağırır:
 *   https://www.ornek-kvkk.com/admin/cron/runner.php?key=KEY
 *   veya CLI: php admin/cron/runner.php
 *
 * Akış:
 *   1. Aktif zamanlamaları çek (görev aktif + tarih aralığında)
 *   2. cronEslesiyor() + SonCalisma dakika kontrolü (çift tetik kilidi)
 *   3. Overlap koruması: aynı görevin son 10 dk içinde başlamış ve hâlâ
 *      çalışan (CalismaDurum=0) kaydı varsa atla
 *   4. Eşleşenleri sıralı çalıştır, CronCalismaLog'a yaz
 */

set_time_limit(600);
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once __DIR__ . '/tasks.php';

header('Content-Type: application/json; charset=utf-8');

$db = Database::getInstance();

if (!cronKeyDogrula($db)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Geçersiz anahtar']);
    exit;
}

$simdi = new DateTime();
$dakikaBaslangic = $simdi->format('Y-m-d H:i:00');

// 1) Aktif zamanlamalar
$zamanlamalar = $db->fetchAll("
    SELECT z.CronZamanlamalar_Id, z.CronZamanlamalar_GorevId, z.CronZamanlamalar_Ad,
           z.CronZamanlamalar_CronIfadesi, z.CronZamanlamalar_SabitParametreler,
           CONVERT(VARCHAR(19), z.CronZamanlamalar_SonCalisma, 120) AS son_calisma,
           g.CronGorevler_GorevKodu, g.CronGorevler_Ad AS gorev_adi
    FROM CronZamanlamalar z
    INNER JOIN CronGorevler g ON g.CronGorevler_Id = z.CronZamanlamalar_GorevId
    WHERE z.Durum = 1
      AND g.Durum = 1
      AND z.CronZamanlamalar_BaslangicTarihi <= GETDATE()
      AND (z.CronZamanlamalar_BitisTarihi IS NULL OR z.CronZamanlamalar_BitisTarihi >= GETDATE())
");

$ozet = [];

foreach ($zamanlamalar as $z) {
    // 2) Cron ifadesi eşleşmesi
    if (!cronEslesiyor($z['CronZamanlamalar_CronIfadesi'], $simdi)) {
        continue;
    }

    // Çift tetik kilidi: bu dakika zaten çalıştıysa atla
    if ($z['son_calisma'] !== null && $z['son_calisma'] >= $dakikaBaslangic) {
        continue;
    }

    // 3) Overlap koruması: aynı görev hâlâ çalışıyorsa atla (son 10 dk penceresi)
    $calisan = $db->fetchOne("
        SELECT TOP 1 CronCalismaLog_Id
        FROM CronCalismaLog
        WHERE CronCalismaLog_GorevId = ?
          AND CronCalismaLog_CalismaDurum = 0
          AND CronCalismaLog_BaslangicTarihi > DATEADD(MINUTE, -10, GETDATE())
    ", [$z['CronZamanlamalar_GorevId']]);

    if ($calisan) {
        $ozet[] = ['zamanlama' => $z['CronZamanlamalar_Ad'], 'durum' => 'atlandi (calisiyor)'];
        continue;
    }

    // 4) Çalıştır: kilit → log aç → görev → log kapat
    $db->update('CronZamanlamalar', [
        'CronZamanlamalar_SonCalisma' => date('Y-m-d H:i:s'),
        'GuncelleyenKullanici'        => 0,
        'GuncellemeTarihi'            => date('Y-m-d H:i:s'),
    ], ['CronZamanlamalar_Id' => $z['CronZamanlamalar_Id']]);

    $params = json_decode($z['CronZamanlamalar_SabitParametreler'] ?? '', true) ?: [];

    $basZaman = microtime(true);
    $logId = cronLogOlustur($db, (int)$z['CronZamanlamalar_GorevId'], (int)$z['CronZamanlamalar_Id'], $params, 1);

    $sonuc = gorevCalistir($z['CronGorevler_GorevKodu'], $params, $db);

    cronLogBitir($db, $logId, (int)$sonuc['durum'], $sonuc['sonuc'], $basZaman);

    $ozet[] = [
        'zamanlama' => $z['CronZamanlamalar_Ad'],
        'gorev'     => $z['CronGorevler_GorevKodu'],
        'durum'     => $sonuc['durum'] === 1 ? 'basarili' : 'hata',
        'sonuc'     => $sonuc['sonuc'],
    ];
}

echo json_encode([
    'success'  => true,
    'zaman'    => $simdi->format('Y-m-d H:i:s'),
    'kontrol'  => count($zamanlamalar),
    'calisan'  => count($ozet),
    'gorevler' => $ozet,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
