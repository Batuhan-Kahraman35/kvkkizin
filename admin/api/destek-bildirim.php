<?php
/**
 * Destek Bildirim Proxy Endpoint
 *
 * Header'daki destek çanı tarafından 60 sn'de bir AJAX ile çağrılır.
 * API Key sunucu tarafında (EntegrasyonKanallari) tutulur, client'a asla sızmaz.
 *
 * ÇAĞRI:
 *   GET /admin/api/destek-bildirim.php?son_kontrol=2026-07-22 18:30:00
 *
 * YANIT:
 *   { "success": true, "yeni_sayisi": 2, "toplam": 5, "ticketler": [ { id, no, konu, son_yanit } ] }
 *
 * TARİH KIYASI:
 *   son_kontrol sunucu tarihi formatında (Y-m-d H:i:s) localStorage'da tutulur.
 *   Kıyaslama Integer'a çevrilmeden doğrudan String olarak yapılır
 *   ('2026-05-20 18:45' > '2026-05-20 17:00') → timezone farkları tamamen devre dışı.
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/TicketApi.php';

header('Content-Type: application/json; charset=utf-8');

// ─── Oturum kontrolü ───
if (!Auth::check()) {
    echo json_encode(['success' => false, 'message' => 'Oturum bulunamadı.']);
    exit;
}

$user = Auth::user();

// ─── API çağrısı ───
$api = new TicketApi($user['kullanici_id']);

if (!$api->hazirMi()) {
    echo json_encode(['success' => false, 'message' => $api->getHata()], JSON_UNESCAPED_UNICODE);
    exit;
}

$result = $api->call([
    'action' => 'list_tickets',
    'eposta' => $user['kullanici_email']
]);

if (!($result['success'] ?? false)) {
    echo json_encode(['success' => false, 'message' => $result['message'] ?? 'API hatası.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$tickets = $result['data'] ?? [];
$toplam  = count($tickets);

// ─── Yeni yanıt filtresi (String kıyas) ───
$sonKontrol = trim($_GET['son_kontrol'] ?? '');

$yeniSayisi    = 0;
$yeniTicketler = [];

foreach ($tickets as $t) {
    $sonYanitStr = $t['son_yanit_tarihi'] ?? null;
    if (!$sonYanitStr) continue;

    // Doğrudan alfabetik String karşılaştırması (Y-m-d H:i:s formatı sıralanabilir)
    if ($sonKontrol !== '' && $sonYanitStr > $sonKontrol) {
        $yeniSayisi++;
        $yeniTicketler[] = [
            'id'        => (int)($t['Tickets_id'] ?? 0),
            'no'        => $t['Tickets_no'] ?? '',
            'konu'      => $t['Tickets_konu'] ?? '',
            'son_yanit' => $sonYanitStr,
        ];
    }
}

echo json_encode([
    'success'     => true,
    'yeni_sayisi' => $yeniSayisi,
    'toplam'      => $toplam,
    'ticketler'   => $yeniTicketler,
], JSON_UNESCAPED_UNICODE);
