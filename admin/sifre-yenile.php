<?php
/**
 * Admin Panel - Şifre Yenileme
 * Token ile şifre sıfırlama sayfası.
 */

require_once __DIR__ . '/auth.php';

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

$token = trim($_GET['token'] ?? '');

$error      = '';
$success    = '';
$tokenGecerli = false;
$kullanici    = null;

// Token doğrula
if (!$token) {
    $error = 'Geçersiz veya eksik bağlantı. Lütfen e-postanızdaki bağlantıyı kullanın.';
} else {
    $kullanici = $db->fetchOne(
        "SELECT kullanici_id, kullanici_ad, kullanici_soyad, kullanici_email
         FROM kullanicilar
         WHERE kullanici_sifre_sifirlama_token = ?
           AND kullanici_sifre_sifirlama_expire > GETDATE()
           AND kullanici_durum = 1",
        [$token]
    );

    if (!$kullanici) {
        $error = 'Bu bağlantı geçersiz veya süresi dolmuş. Lütfen şifre sıfırlama işlemini yeniden başlatın.';
    } else {
        $tokenGecerli = true;
    }
}

// Şifre güncelleme
if ($tokenGecerli && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $yeniSifre      = $_POST['yeni_sifre']      ?? '';
    $yeniSifreTekrar = $_POST['yeni_sifre_tekrar'] ?? '';

    if (strlen($yeniSifre) < 6) {
        $error = 'Şifre en az 6 karakter olmalıdır.';
    } elseif ($yeniSifre !== $yeniSifreTekrar) {
        $error = 'Şifreler eşleşmiyor.';
    } else {
        $hash = password_hash($yeniSifre, PASSWORD_DEFAULT);

        $db->execute(
            "UPDATE kullanicilar
             SET kullanici_sifre_hash = ?,
                 kullanici_sifre_sifirlama_token = NULL,
                 kullanici_sifre_sifirlama_expire = NULL,
                 kullanici_sifre_degistirmeli = 0
             WHERE kullanici_id = ?",
            [$hash, $kullanici['kullanici_id']]
        );

        $success    = 'Şifreniz başarıyla güncellendi. Artık giriş yapabilirsiniz.';
        $tokenGecerli = false;
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Şifre Yenile - <?= htmlspecialchars($siteTitle) ?></title>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous">
    <link rel="stylesheet" href="assets/css/adminlte.min.css">

    <style>
        .strength-bar { height: 4px; border-radius: 2px; transition: all .3s; }
    </style>
</head>
<body class="login-page bg-body-secondary">
<div class="login-box">
    <div class="login-logo">
        <a href="login.php"><b><?= htmlspecialchars($siteTitle) ?></b></a>
    </div>

    <div class="card">
        <div class="card-body login-card-body">
            <p class="login-box-msg">Şifre Yenile</p>

            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-triangle-fill"></i> <?= htmlspecialchars($error) ?>
                </div>
                <div class="text-center mt-3">
                    <a href="sifre-sifirla.php" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-arrow-repeat"></i> Yeniden Dene
                    </a>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="bi bi-check-circle-fill"></i> <?= htmlspecialchars($success) ?>
                </div>
                <div class="text-center mt-3">
                    <a href="login.php" class="btn btn-primary">
                        <i class="bi bi-box-arrow-in-right"></i> Giriş Yap
                    </a>
                </div>
            <?php endif; ?>

            <?php if ($tokenGecerli): ?>
                <p class="text-muted text-center" style="font-size:.875rem;">
                    Merhaba <strong><?= htmlspecialchars(trim(($kullanici['kullanici_ad'] ?? '') . ' ' . ($kullanici['kullanici_soyad'] ?? ''))) ?></strong>,
                    lütfen yeni şifrenizi belirleyin.
                </p>

                <form method="POST" action="sifre-yenile.php?token=<?= htmlspecialchars(urlencode($token)) ?>" id="sifreForm">

                    <div class="input-group mb-1">
                        <input type="password" class="form-control" name="yeni_sifre" id="yeniSifre"
                               placeholder="Yeni Şifre (en az 6 karakter)" required minlength="6" autofocus>
                        <button type="button" class="input-group-text" onclick="toggleSifre('yeniSifre','goz1')">
                            <i class="bi bi-eye" id="goz1"></i>
                        </button>
                    </div>
                    <!-- Güç göstergesi -->
                    <div class="mb-3">
                        <div class="strength-bar bg-secondary w-100" id="strengthBar"></div>
                        <small class="text-muted" id="strengthText"></small>
                    </div>

                    <div class="input-group mb-3">
                        <input type="password" class="form-control" name="yeni_sifre_tekrar" id="sifreTekrar"
                               placeholder="Şifre Tekrar" required minlength="6">
                        <button type="button" class="input-group-text" onclick="toggleSifre('sifreTekrar','goz2')">
                            <i class="bi bi-eye" id="goz2"></i>
                        </button>
                    </div>

                    <div class="d-grid mb-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-shield-check"></i> Şifreyi Güncelle
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
    function toggleSifre(inputId, iconId) {
        const input = document.getElementById(inputId);
        const icon  = document.getElementById(iconId);
        if (input.type === 'password') {
            input.type = 'text';
            icon.className = 'bi bi-eye-slash';
        } else {
            input.type = 'password';
            icon.className = 'bi bi-eye';
        }
    }

    // Şifre güç göstergesi
    const yeniSifre   = document.getElementById('yeniSifre');
    const strengthBar = document.getElementById('strengthBar');
    const strengthTxt = document.getElementById('strengthText');

    if (yeniSifre) {
        yeniSifre.addEventListener('input', function () {
            const v = this.value;
            let puan = 0;
            if (v.length >= 6)  puan++;
            if (v.length >= 10) puan++;
            if (/[A-Z]/.test(v)) puan++;
            if (/[0-9]/.test(v)) puan++;
            if (/[^A-Za-z0-9]/.test(v)) puan++;

            const renkler = ['bg-danger','bg-warning','bg-warning','bg-info','bg-success','bg-success'];
            const etiketler = ['','Çok Zayıf','Zayıf','Orta','Güçlü','Çok Güçlü'];
            strengthBar.className = 'strength-bar ' + (renkler[puan] || 'bg-secondary');
            strengthBar.style.width = (puan * 20) + '%';
            strengthTxt.textContent = etiketler[puan] || '';
        });
    }

    // Form submit: şifre eşleşme kontrolü
    const sifreForm = document.getElementById('sifreForm');
    if (sifreForm) {
        sifreForm.addEventListener('submit', function (e) {
            const s1 = document.getElementById('yeniSifre').value;
            const s2 = document.getElementById('sifreTekrar').value;
            if (s1 !== s2) {
                e.preventDefault();
                alert('Şifreler eşleşmiyor!');
            }
        });
    }
</script>
</body>
</html>
