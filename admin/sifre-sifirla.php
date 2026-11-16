<?php
/**
 * Admin Panel - Şifremi Unuttum
 * Email veya telefon ile sıfırlama linki gönderir.
 * Gönderim ayarları Entegrasyonlar / EntegrasyonKanallari tablolarından okunur.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/MailHelper.php';

if (Auth::check()) {
    redirect('index.php');
}

$db = Database::getInstance();

$siteAyarlari = $db->fetchOne("
    SELECT TOP 1 site_ayarlari_footer_yazi, site_ayarlari_site_title
    FROM dbo.tanim_site_ayarlari
    ORDER BY site_ayarlari_id DESC
");
$siteTitle  = $siteAyarlari['site_ayarlari_site_title']  ?? 'Örnek Yazılım Portal';
$footerYazi = $siteAyarlari['site_ayarlari_footer_yazi'] ?? '© ' . date('Y') . ' Örnek Yazılım. Tüm hakları saklıdır.';

// entegrasyonConfigGetir, smtpMailGonder, whatsappGonder → includes/MailHelper.php

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $giris = trim($_POST['giris'] ?? '');

    if (!$giris) {
        $error = 'Lütfen e-posta adresinizi veya telefon numaranızı girin.';
    } else {
        // E-posta mı, telefon mu?
        $isEmail   = filter_var($giris, FILTER_VALIDATE_EMAIL);
        $isTelefon = preg_match('/^905[0-9]{9}$/', $giris);

        if (!$isEmail && !$isTelefon) {
            $error = 'Geçerli bir e-posta adresi veya 905XXXXXXXXX formatında telefon girin.';
        } else {
            // Kullanıcıyı ara
            if ($isEmail) {
                $kullanici = $db->fetchOne(
                    "SELECT kullanici_id, kullanici_ad, kullanici_soyad, kullanici_email, kullanici_telefon
                     FROM kullanicilar WHERE kullanici_email = ? AND kullanici_durum = 1",
                    [$giris]
                );
            } else {
                $kullanici = $db->fetchOne(
                    "SELECT kullanici_id, kullanici_ad, kullanici_soyad, kullanici_email, kullanici_telefon
                     FROM kullanicilar WHERE kullanici_telefon = ? AND kullanici_durum = 1",
                    [$giris]
                );
            }

            // Güvenlik: kullanıcı bulunmasa da aynı mesajı göster
            if ($kullanici) {
                $token  = bin2hex(random_bytes(32));
                $expire = date('Y-m-d H:i:s', strtotime('+1 hour'));

                $db->execute(
                    "UPDATE kullanicilar SET kullanici_sifre_sifirlama_token = ?, kullanici_sifre_sifirlama_expire = ? WHERE kullanici_id = ?",
                    [$token, $expire, $kullanici['kullanici_id']]
                );

                $siteUrl  = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'www.ornek-kvkk.com');
                $link     = $siteUrl . '/admin/sifre-yenile.php?token=' . $token;
                $adSoyad  = trim(($kullanici['kullanici_ad'] ?? '') . ' ' . ($kullanici['kullanici_soyad'] ?? ''));

                $mesajMetni = "Merhaba $adSoyad,\n\nŞifre sıfırlama talebiniz alındı.\n\nAşağıdaki bağlantıya tıklayarak yeni şifrenizi belirleyebilirsiniz:\n$link\n\nBu bağlantı 1 saat geçerlidir.\n\nEğer bu talebi siz yapmadıysanız bu mesajı görmezden gelebilirsiniz.";

                // Aktif kanalların ayarlarını çek
                $emailCfg = entegrasyonConfigGetir($db, 'email');
                $waCfg    = entegrasyonConfigGetir($db, 'whatsapp');

                $gonderildi = false;

                // E-posta ile gönder
                if ($emailCfg && $kullanici['kullanici_email']) {
                    $ok = smtpMailGonder(
                        $emailCfg,
                        $kullanici['kullanici_email'],
                        $siteTitle . ' - Şifre Sıfırlama',
                        $mesajMetni
                    );
                    if ($ok) $gonderildi = true;
                }

                // WhatsApp ile gönder
                if ($waCfg && $kullanici['kullanici_telefon']) {
                    $ok = whatsappGonder(
                        $waCfg,
                        $kullanici['kullanici_telefon'],
                        $mesajMetni
                    );
                    if ($ok) $gonderildi = true;
                }

                // Log kaydı
                Auth::kullaniciLogKaydet($db, 'sifre_sifirlama', $kullanici['kullanici_id'], $kullanici['kullanici_email'],
                    $gonderildi ? 'Sıfırlama bağlantısı gönderildi' : 'Sıfırlama bağlantısı gönderilemedi');
            }

            // Kullanıcı bulunsun ya da bulunmasın aynı mesajı göster
            $success = 'Girilen bilgi sistemde kayıtlıysa şifre sıfırlama bağlantısı gönderildi. Lütfen e-postanızı veya WhatsApp mesajlarınızı kontrol edin.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Şifremi Unuttum - <?= htmlspecialchars($siteTitle) ?></title>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous">
    <link rel="stylesheet" href="assets/css/adminlte.min.css">
</head>
<body class="login-page bg-body-secondary">
<div class="login-box">
    <div class="login-logo">
        <a href="login.php"><b><?= htmlspecialchars($siteTitle) ?></b></a>
    </div>

    <div class="card">
        <div class="card-body login-card-body">
            <p class="login-box-msg">Şifremi Unuttum</p>

            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible">
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    <i class="bi bi-exclamation-triangle-fill"></i> <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="bi bi-check-circle-fill"></i> <?= htmlspecialchars($success) ?>
                </div>
                <div class="text-center mt-3">
                    <a href="login.php" class="btn btn-primary">
                        <i class="bi bi-box-arrow-in-right"></i> Giriş Sayfasına Dön
                    </a>
                </div>
            <?php else: ?>

            <p class="text-muted text-center" style="font-size:.875rem;">
                Sisteme kayıtlı e-posta adresinizi veya WhatsApp telefon numaranızı girin.
            </p>

            <form method="POST" action="">
                <div class="input-group mb-3">
                    <input type="text" class="form-control" name="giris" id="girisInput"
                           placeholder="E-posta veya 905XXXXXXXXX" required autofocus
                           value="<?= htmlspecialchars($_POST['giris'] ?? '') ?>">
                    <div class="input-group-text"><i class="bi bi-search"></i></div>
                </div>

                <div class="d-grid mb-3">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-send"></i> Sıfırlama Bağlantısı Gönder
                    </button>
                </div>
            </form>

            <?php endif; ?>

            <p class="mt-2 mb-0 text-center">
                <a href="login.php"><i class="bi bi-arrow-left"></i> Giriş sayfasına dön</a>
            </p>

            <p class="mt-3 mb-1 text-center">
                <small class="text-muted"><?= htmlspecialchars($footerYazi) ?></small>
            </p>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
<script src="assets/js/adminlte.min.js"></script>
<script>
    // Telefon formatını otomatik düzelt
    document.getElementById('girisInput')?.addEventListener('input', function () {
        const v = this.value;
        // Sadece rakam girildiyse telefon formatına normalize et
        if (/^[0-9]+$/.test(v)) {
            let t = v.replace(/\D/g, '');
            if (t.startsWith('0')) t = '9' + t;
            if (t.startsWith('5')) t = '90' + t;
            if (t.length > 12) t = t.slice(0, 12);
            this.value = t;
        }
    });
</script>
</body>
</html>
