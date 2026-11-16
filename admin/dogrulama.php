<?php
/**
 * Admin Panel - İki Faktörlü Doğrulama (SMS)
 *
 * login.php şifreyi doğruladıktan sonra buraya yönlendirir.
 * Kod ve geçerlilik bilgisi SmsLog tablosunda tutulur.
 */

require_once __DIR__ . '/auth.php';

// Oturum zaten açıksa panele gönder
if (Auth::check()) {
    redirect('/admin/index.php');
}

// Bekleyen doğrulama yoksa giriş ekranına dön
$bekleyen = $_SESSION['2fa_bekleyen'] ?? null;
if (!$bekleyen) {
    redirect('/admin/login.php');
}

$db = Database::getInstance();

$siteAyarlari = $db->fetchOne("
    SELECT TOP 1 site_ayarlari_footer_yazi, site_ayarlari_site_title
    FROM dbo.tanim_site_ayarlari
    ORDER BY site_ayarlari_id DESC
");

$siteTitle  = !empty($siteAyarlari['site_ayarlari_site_title'])
    ? htmlspecialchars($siteAyarlari['site_ayarlari_site_title'])
    : 'Örnek Yazılım Portal';
$footerYazi = !empty($siteAyarlari['site_ayarlari_footer_yazi'])
    ? htmlspecialchars($siteAyarlari['site_ayarlari_footer_yazi'])
    : '© ' . date('Y') . ' Örnek Yazılım. Tüm hakları saklıdır.';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $islem = $_POST['islem'] ?? 'dogrula';

    if ($islem === 'tekrar') {
        $sonuc = Auth::ikiFaktorTekrarGonder($db);

        if (!empty($sonuc['bitti'])) {
            $_SESSION['2fa_mesaj'] = $sonuc['message'];
            redirect('/admin/login.php?hata=2fa');
        }

        if ($sonuc['success']) {
            $success = $sonuc['message'];
            $bekleyen = $_SESSION['2fa_bekleyen'];
        } else {
            $error = $sonuc['message'];
        }
    } else {
        $kod = preg_replace('/\D/', '', $_POST['kod'] ?? '');

        if ($kod === '') {
            $error = 'Lütfen size gönderilen doğrulama kodunu girin.';
        } else {
            $sonuc = Auth::ikiFaktorDogrula($db, $kod);

            if ($sonuc['success']) {
                if (!empty($_SESSION['sifre_degistirmeli'])) {
                    redirect('/admin/pages/profil.php?sifre_degistir=1');
                }
                // Giriş öncesi girilmek istenen sayfa varsa oraya dön
                girisSonrasiYonlendir('/admin/index.php');
            }

            if (!empty($sonuc['bitti'])) {
                $_SESSION['2fa_mesaj'] = $sonuc['message'];
                redirect('/admin/login.php?hata=2fa');
            }

            $error = $sonuc['message'];
        }
    }
}

// Kalan geçerlilik süresi (saniye)
$kalanSaniye = max(0, Auth::IKI_FAKTOR_GECERLILIK_SN - (time() - (int)($bekleyen['gonderim'] ?? time())));
$tekrarKalan = max(0, Auth::IKI_FAKTOR_TEKRAR_SN - (time() - (int)($bekleyen['gonderim'] ?? time())));
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Doğrulama - <?= $siteTitle ?></title>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous">
    <link rel="stylesheet" href="assets/css/adminlte.min.css">

    <style>
        #kod {
            letter-spacing: 0.6rem;
            font-size: 1.6rem;
            text-align: center;
            font-weight: 600;
        }
    </style>
</head>
<body class="login-page bg-body-secondary">
    <div class="login-box">
        <div class="login-logo">
            <a href="/admin/login.php"><b><?= $siteTitle ?></b></a>
        </div>

        <div class="card">
            <div class="card-body login-card-body">
                <p class="login-box-msg">
                    <i class="bi bi-shield-lock"></i> İki Faktörlü Doğrulama<br>
                    <small class="text-muted">
                        <?= htmlspecialchars($bekleyen['telefon_mask'] ?? '') ?> numarasına gönderilen
                        <?= Auth::IKI_FAKTOR_HANE ?> haneli kodu girin.
                    </small>
                </p>

                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible">
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Kapat"></button>
                        <i class="bi bi-exclamation-triangle-fill"></i> <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="alert alert-success alert-dismissible">
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Kapat"></button>
                        <i class="bi bi-check-circle-fill"></i> <?= htmlspecialchars($success) ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="" id="dogrulamaForm">
                    <input type="hidden" name="islem" value="dogrula">

                    <div class="mb-3">
                        <input
                            type="text"
                            class="form-control"
                            id="kod"
                            name="kod"
                            inputmode="numeric"
                            autocomplete="one-time-code"
                            maxlength="<?= Auth::IKI_FAKTOR_HANE ?>"
                            placeholder="<?= str_repeat('•', Auth::IKI_FAKTOR_HANE) ?>"
                            required
                            autofocus
                        >
                    </div>

                    <div class="text-center mb-3">
                        <small class="text-muted">
                            Kalan süre: <span id="sayac" class="fw-bold"><?= gmdate('i:s', $kalanSaniye) ?></span>
                        </small>
                    </div>

                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary" id="btnDogrula">
                            <i class="bi bi-check2-circle"></i> Doğrula
                        </button>
                    </div>
                </form>

                <form method="POST" action="" class="d-grid gap-2 mt-2">
                    <input type="hidden" name="islem" value="tekrar">
                    <button type="submit" class="btn btn-outline-secondary btn-sm" id="btnTekrar" <?= $tekrarKalan > 0 ? 'disabled' : '' ?>>
                        <i class="bi bi-arrow-clockwise"></i>
                        Kodu yeniden gönder<span id="tekrarSayac"><?= $tekrarKalan > 0 ? ' (' . $tekrarKalan . ')' : '' ?></span>
                    </button>
                </form>

                <div class="text-center mt-3 mb-0">
                    <a href="/admin/logout.php"><i class="bi bi-arrow-left"></i> Giriş ekranına dön</a>
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

    <script>
        // Sadece rakam kabul et, tam uzunlukta otomatik gönder
        const kodAlani = document.getElementById('kod');
        kodAlani.addEventListener('input', function() {
            this.value = this.value.replace(/\D/g, '');
            if (this.value.length === <?= Auth::IKI_FAKTOR_HANE ?>) {
                document.getElementById('dogrulamaForm').submit();
            }
        });

        // Geçerlilik geri sayımı
        let kalan = <?= (int)$kalanSaniye ?>;
        const sayac = document.getElementById('sayac');
        const geriSayim = setInterval(function() {
            kalan--;
            if (kalan <= 0) {
                clearInterval(geriSayim);
                sayac.textContent = '00:00';
                sayac.classList.add('text-danger');
                document.getElementById('btnDogrula').disabled = true;
                kodAlani.disabled = true;
                return;
            }
            const dk = String(Math.floor(kalan / 60)).padStart(2, '0');
            const sn = String(kalan % 60).padStart(2, '0');
            sayac.textContent = dk + ':' + sn;
        }, 1000);

        // Yeniden gönder bekleme süresi
        let tekrar = <?= (int)$tekrarKalan ?>;
        const btnTekrar = document.getElementById('btnTekrar');
        const tekrarSayac = document.getElementById('tekrarSayac');
        if (tekrar > 0) {
            const tekrarSayim = setInterval(function() {
                tekrar--;
                if (tekrar <= 0) {
                    clearInterval(tekrarSayim);
                    btnTekrar.disabled = false;
                    tekrarSayac.textContent = '';
                    return;
                }
                tekrarSayac.textContent = ' (' + tekrar + ')';
            }, 1000);
        }
    </script>
</body>
</html>
