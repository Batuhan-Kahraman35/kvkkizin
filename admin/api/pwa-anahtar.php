<?php
/**
 * VAPID public key → istemci (applicationServerKey)
 */
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PwaHelper.php';
requireAuth();

header('Content-Type: application/json; charset=utf-8');

$db = Database::getInstance();
$vapid = PwaHelper::vapid($db);

if (!$vapid) {
    echo json_encode(['success' => false, 'message' => 'VAPID anahtarı tanımlı değil']);
    exit;
}

echo json_encode(['success' => true, 'public_key' => $vapid['public']]);
