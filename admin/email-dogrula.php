<?php
/**
 * Admin Panel - E-posta Doğrulama
 * register.php'den gönderilen token ile e-posta adresini doğrular.
 */

require_once __DIR__ . '/auth.php';

$db = Database::getInstance();

$siteAyarlari = $db->fetchOne("
    SELECT TOP 1 site_ayarlari_footer_yazi, site_ayarlari_site_title
    FROM dbo.tanim_site_ayarlari
    ORDER BY site_ayarlari_id DESC
");
$siteTitle  = htmlspecialchars($siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal');
$footerYazi = htmlspecialchars($siteAyarlari['site_ayarlari_footer_yazi'] ?? '© ' . date('Y') . ' Örnek Yazılım. Tüm hakları saklıdır.');

$error   = '';
$success = '';

$token = trim($_GET['token'] ?? '');

if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
    $error = 'Geçersiz doğrulama bağlantısı!';
} else {
    $kullanici = $db->fetchOne("
        SELECT kullanici_id, kullanici_email, kullanici_email_dogrulandi,
               CONVERT(VARCHAR(19), kullanici_email_dogrulama_expire, 120) AS expire
        FROM kullanicilar
        WHERE kullanici_email_dogrulama_token = ?
    ", [$token]);

    if (!$kullanici) {
        $error = 'Doğrulama bağlantısı bulunamadı veya daha önce kullanılmış!';
    } elseif ((int)$kullanici['kullanici_email_dogrulandi'] === 1) {
        $success = 'E-posta adresiniz zaten doğrulanmış. Giriş yapabilirsiniz.';
    } elseif ($kullanici['expire'] && $kullanici['expire'] < date('Y-m-d H:i:s')) {
        $error = 'Doğrulama bağlantısının süresi dolmuş! Lütfen yönetici ile iletişime geçin.';
    } else {
        $db->execute("
            UPDATE kullanicilar
            SET kullanici_email_dogrulandi = 1,
                kullanici_email_dogrulama_token = NULL,
                kullanici_email_dogrulama_expire = NULL
            WHERE kullanici_id = ?
        ", [$kullanici['kullanici_id']]);

        // Log kaydı
        Auth::kullaniciLogKaydet($db, 'email_dogrulama', $kullanici['kullanici_id'], $kullanici['kullanici_email'], 'E-posta doğrulandı');

        $success = 'E-posta adresiniz başarıyla doğrulandı! Yönetici onayından sonra giriş yapabileceksiniz.';
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>E-posta Doğrulama - <?= $siteTitle ?></title>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous">
    <link rel="stylesheet" href="assets/css/adminlte.min.css">
</head>
<body class="login-page bg-body-secondary">
<div class="login-box">
    <div class="login-logo">
        <a href="login.php"><b><?= $siteTitle ?></b></a>
    </div>

    <div class="card">
        <div class="card-body login-card-body">
            <p class="login-box-msg">E-posta Doğrulama</p>

            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-triangle-fill"></i> <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="bi bi-check-circle-fill"></i> <?= htmlspecialchars($success) ?>
                </div>
            <?php endif; ?>

            <div class="text-center mt-3">
                <a href="login.php" class="btn btn-primary">
                    <i class="bi bi-box-arrow-in-right"></i> Giriş Sayfasına Dön
                </a>
            </div>

            <p class="mt-3 mb-1 text-center">
                <small class="text-muted"><?= $footerYazi ?></small>
            </p>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
<script src="assets/js/adminlte.min.js"></script>
</body>
</html>
