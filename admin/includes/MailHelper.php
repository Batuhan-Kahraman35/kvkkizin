<?php
/**
 * Mail / Mesaj Gönderim Yardımcıları
 * Gönderim ayarları Entegrasyonlar / EntegrasyonKanallari tablolarından okunur.
 * Kullanım: sifre-sifirla.php, register.php, email-dogrula.php
 */

/**
 * Aktif entegrasyon kanalını getirir; entegrasyon + kanal JSON ayarlarını birleştirir.
 */
function entegrasyonConfigGetir(Database $db, string $kod): ?array {
    $row = $db->fetchOne("
        SELECT TOP 1 e.entegrasyon_ayarlar, k.kanal_ayarlar
        FROM EntegrasyonKanallari k
        INNER JOIN Entegrasyonlar e ON k.kanal_entegrasyon_id = e.entegrasyon_id
        WHERE e.entegrasyon_kod = ? AND e.entegrasyon_durum = 1 AND k.kanal_durum = 1
        ORDER BY k.kanal_varsayilan DESC, k.kanal_id
    ", [$kod]);

    if (!$row) return null;

    $ayarlar      = json_decode($row['entegrasyon_ayarlar'] ?? '{}', true) ?: [];
    $kanalAyarlar = json_decode($row['kanal_ayarlar'] ?? '{}', true) ?: [];
    return array_merge($ayarlar, $kanalAyarlar);
}

/**
 * SMTP ile mail gönder (SSL)
 * $cc: isteğe bağlı bilgi (CC) alıcıları; geçersiz/boş adresler atlanır
 */
function smtpMailGonder(array $cfg, string $toEmail, string $subject, string $body, array $cc = []): bool {
    $cc = array_values(array_unique(array_filter(array_map('trim', $cc), function ($a) use ($toEmail) {
        return $a !== '' && strcasecmp($a, $toEmail) !== 0 && filter_var($a, FILTER_VALIDATE_EMAIL);
    })));

    $host = $cfg['Host']     ?? '';
    $port = (int)($cfg['Port'] ?? 465);
    $user = $cfg['Username'] ?? '';
    $pass = $cfg['Password'] ?? '';
    $from = $cfg['FromEmail'] ?? $user;
    $fromName = $cfg['FromName'] ?? '';

    if (!$host || !$user || !$pass) return false;

    $smtp = @stream_socket_client('ssl://' . $host . ':' . $port, $errno, $errstr, 15);
    if (!$smtp) return false;

    stream_set_timeout($smtp, 10);

    $read = function () use ($smtp) { return fgets($smtp, 1024); };
    $send = function (string $cmd) use ($smtp) { fwrite($smtp, $cmd . "\r\n"); };

    $read(); // 220 greeting
    $send("EHLO localhost");
    while ($line = $read()) { if ($line[3] === ' ') break; }

    $send("AUTH LOGIN");
    $read(); // 334 Username
    $send(base64_encode($user));
    $read(); // 334 Password
    $send(base64_encode($pass));
    $authResp = $read();
    if (strpos($authResp, '235') === false) { fclose($smtp); return false; }

    $send("MAIL FROM:<" . $from . ">");
    $read();
    $send("RCPT TO:<" . $toEmail . ">");
    $read();
    foreach ($cc as $ccAdres) {
        $send("RCPT TO:<" . $ccAdres . ">");
        $read();
    }
    $send("DATA");
    $read(); // 354

    $fromHeader = $fromName !== ''
        ? '=?UTF-8?B?' . base64_encode($fromName) . '?= <' . $from . '>'
        : $from;

    $headers  = "From: " . $fromHeader . "\r\n";
    $headers .= "To: " . $toEmail . "\r\n";
    if ($cc) $headers .= "Cc: " . implode(', ', $cc) . "\r\n";
    $headers .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $headers .= "Content-Transfer-Encoding: base64\r\n";

    fwrite($smtp, $headers . "\r\n" . chunk_split(base64_encode($body)) . "\r\n.\r\n");
    $dataResp = $read();

    $send("QUIT");
    fclose($smtp);

    return strpos($dataResp, '250') !== false;
}

/**
 * WhatsApp Evolution API ile mesaj gönder
 */
function whatsappGonder(array $cfg, string $telefon, string $mesaj): bool {
    $baseUrl  = rtrim($cfg['BaseURL'] ?? '', '/');
    $apiKey   = $cfg['GlobalApiKey'] ?? $cfg['ApiKey'] ?? '';
    $instance = $cfg['Instance'] ?? '';

    if (!$baseUrl || !$apiKey || !$instance) return false;

    $url = $baseUrl . '/message/sendText/' . rawurlencode($instance);
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'apikey: ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS     => json_encode([
            'number' => $telefon,
            'text'   => $mesaj,
        ], JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $res  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code >= 200 && $code < 300;
}
