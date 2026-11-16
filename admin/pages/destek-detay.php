<?php
/**
 * Admin Panel - Destek Talebi Detay / Yeni Talep
 * Örnek Yazılım Destek Sistemi entegrasyonu
 *
 * Kullanım:
 *   destek-detay.php?id=5    → Talep detayı + mesajlar + yanıt formu
 *   destek-detay.php?yeni=1  → Yeni talep oluşturma formu
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/TicketApi.php';
requireAuth();

$user = Auth::user();
$db = Database::getInstance();

// Bu sayfa yetki kısıtlamasına tabi değildir — giriş yapan tüm kullanıcılara açıktır

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

$menuAdi = 'Destek';

// ─── API ───
$api = new TicketApi($user['kullanici_id']);
$kullaniciNesnesi = $api->kullaniciNesnesi($user['kullanici_id']);

$ticketId = (int)($_GET['id'] ?? 0);
$yeniMi   = isset($_GET['yeni']);
$ticket   = null;
$mesajlar = [];
$hata     = '';
$hataObj  = [];
$basarili = '';

$kategoriler = [];
$oncelikler  = [];

// Düz metni güvenli HTML'e çevirir: önce kaçış, sonra URL'leri link yapar, satır sonlarını korur
function metinHtml($metin) {
    $html = htmlspecialchars((string)$metin, ENT_QUOTES, 'UTF-8');
    $html = preg_replace_callback('~\b((?:https?://|www\.)[^\s<]+)~iu', function ($m) {
        $url = $m[1];
        $kuyruk = '';
        // Cümle sonundaki noktalama linke dahil edilmez
        if (preg_match('~[.,;:!?)\]]+$~', $url, $s)) {
            $kuyruk = $s[0];
            $url = substr($url, 0, -strlen($kuyruk));
        }
        $href = preg_match('~^www\.~i', $url) ? 'https://' . $url : $url;
        return '<a href="' . $href . '" target="_blank" rel="noopener noreferrer">' . $url . '</a>' . $kuyruk;
    }, $html);
    return nl2br($html);
}

// Mükerrer gönderim koruması: form anahtarı yalnız bir kez kullanılabilir.
// PHP session kilidi (auth.php session_start) aynı oturumdaki eşzamanlı istekleri sıraya sokar;
// ikinci istek, ilki bittikten sonra anahtarı dolu bulur.
function formAnahtarKaydi($anahtar) {
    return $_SESSION['destek_form_anahtarlar'][$anahtar] ?? null;
}
function formAnahtarKaydet($anahtar, $yonlendirme) {
    $liste = $_SESSION['destek_form_anahtarlar'] ?? [];
    $liste[$anahtar] = $yonlendirme;
    $_SESSION['destek_form_anahtarlar'] = array_slice($liste, -20, null, true);
}

if (!$api->hazirMi()) {
    $hata = $api->getHata();
} else {
    // Kategori/öncelik listeleri API'den gelir (create formu ve badge'ler için)
    $metaResult = $api->call(['action' => 'ticket_meta']);
    if ($metaResult['success'] ?? false) {
        $kategoriler = $metaResult['data']['kategoriler'] ?? [];
        $oncelikler  = $metaResult['data']['oncelikler'] ?? [];
    }

    // ─── POST İşlemleri ───
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $kullaniciNesnesi) {
        $postAction  = $_POST['post_action'] ?? '';
        $formAnahtar = (string)($_POST['form_anahtar'] ?? '');

        if (in_array($postAction, ['create_ticket', 'reply_ticket'], true)) {
            if (!preg_match('/^[a-f0-9]{32}$/', $formAnahtar)) {
                $hata = 'Geçersiz form anahtarı. Sayfayı yenileyip tekrar deneyin.';
                $yeniMi = ($postAction === 'create_ticket') ? true : $yeniMi;
                $postAction = '';
            } elseif ($oncekiYonlendirme = formAnahtarKaydi($formAnahtar)) {
                // Aynı form ikinci kez gönderildi: yeni kayıt açmadan ilk sonucun sayfasına dön
                header('Location: ' . $oncekiYonlendirme);
                exit;
            }
        }

        if ($postAction === 'create_ticket') {
            $payload = [
                'action'      => 'create_ticket',
                'kullanici'   => $kullaniciNesnesi,
                'konu'        => trim($_POST['konu'] ?? ''),
                'mesaj'       => trim($_POST['mesaj'] ?? ''),
                'kategori_id' => (int)($_POST['kategori_id'] ?? 0),
                'oncelik_id'  => (int)($_POST['oncelik_id'] ?? 0),
            ];
            $files = !empty($_FILES['dosyalar']['name'][0]) ? $_FILES['dosyalar'] : [];

            $result = $api->call($payload, $files);
            if ($result['success'] ?? false) {
                $yeniTicketId = $result['data']['ticket_id'] ?? 0;
                formAnahtarKaydet($formAnahtar, '/admin/pages/destek-detay.php?id=' . $yeniTicketId);
                header('Location: /admin/pages/destek-detay.php?id=' . $yeniTicketId . '&ok=1');
                exit;
            } else {
                $hata = $result['message'] ?? 'Talep oluşturulamadı.';
                $hataObj = $result['errors'] ?? [];
                $yeniMi = true;
            }
        }

        if ($postAction === 'reply_ticket' && $ticketId > 0) {
            $payload = [
                'action'    => 'reply_ticket',
                'ticket_id' => $ticketId,
                'eposta'    => $user['kullanici_email'],
                'kullanici' => $kullaniciNesnesi,
                'mesaj'     => trim($_POST['mesaj'] ?? ''),
            ];
            $files = !empty($_FILES['dosyalar']['name'][0]) ? $_FILES['dosyalar'] : [];

            $result = $api->call($payload, $files);
            if ($result['success'] ?? false) {
                formAnahtarKaydet($formAnahtar, '/admin/pages/destek-detay.php?id=' . $ticketId);
                header('Location: /admin/pages/destek-detay.php?id=' . $ticketId . '&ok=2');
                exit;
            } else {
                $hata = $result['message'] ?? 'Yanıt gönderilemedi.';
                $hataObj = $result['errors'] ?? [];
            }
        }
    }

    if (isset($_GET['ok'])) {
        $basarili = ($_GET['ok'] == '1') ? 'Talep başarıyla oluşturuldu.' : 'Yanıtınız gönderildi.';
    }

    // ─── Talep Detayı ───
    if ($ticketId > 0 && !$yeniMi && $kullaniciNesnesi) {
        $detayResult = $api->call([
            'action'    => 'ticket_detail',
            'ticket_id' => $ticketId,
            'eposta'    => $user['kullanici_email'],
            'kullanici' => $kullaniciNesnesi
        ]);

        if ($detayResult['success'] ?? false) {
            $ticket   = $detayResult['data'] ?? [];
            $mesajlar = $ticket['mesajlar'] ?? [];
        } else {
            $hata = $detayResult['message'] ?? 'Talep bulunamadı.';
            $hataObj = $detayResult['errors'] ?? [];
        }
    }
}

$pageTitle = $yeniMi ? 'Yeni Destek Talebi' : ('Talep Detayı' . ($ticket ? ' — #' . ($ticket['Tickets_no'] ?? $ticketId) : ''));
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> - <?= htmlspecialchars($siteTitle) ?></title>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous">
    <link rel="stylesheet" href="/Admin/assets/css/adminlte.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/Admin/assets/css/custom.css">

    <style>
        .mesaj-listesi { min-height: 300px; max-height: 500px; overflow-y: auto; }
        .mesaj-kutu {
            border: 1px solid var(--bs-border-color);
            border-radius: .5rem;
            padding: .75rem 1rem;
            margin-bottom: .75rem;
        }
        .mesaj-kutu:last-child { margin-bottom: 0; }
        .mesaj-metni { overflow-wrap: anywhere; }
        .mesaj-metni a { text-decoration: underline; }
    </style>
</head>
<body class="layout-fixed sidebar-expand-lg sidebar-open bg-body-tertiary">
    <div class="app-wrapper">
        <?php include __DIR__ . '/../includes/header.php'; ?>
        <?php include __DIR__ . '/../includes/sidebar.php'; ?>

        <main class="app-main">
            <div class="app-content-header">
                <div class="container-fluid">
                    <div class="row">
                        <div class="col-sm-6">
                            <h3 class="mb-0"><?= htmlspecialchars($pageTitle) ?></h3>
                        </div>
                        <div class="col-sm-6">
                            <ol class="breadcrumb float-sm-end">
                                <li class="breadcrumb-item"><?= htmlspecialchars($menuAdi) ?></li>
                                <li class="breadcrumb-item"><a href="/admin/pages/destek.php">Destek Talepleri</a></li>
                                <li class="breadcrumb-item active"><?= $yeniMi ? 'Yeni Talep' : 'Detay' ?></li>
                            </ol>
                        </div>
                    </div>
                </div>
            </div>

            <div class="app-content">
                <div class="container-fluid">

                    <?php if ($hata): ?>
                    <div class="alert alert-danger">
                        <i class="bi bi-exclamation-triangle me-1"></i><?= htmlspecialchars($hata) ?>
                        <?php if (!empty($hataObj) && is_array($hataObj)): ?>
                        <ul class="mb-0 mt-2">
                            <?php foreach ($hataObj as $err): ?>
                            <li><?= htmlspecialchars($err) ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <?php if ($yeniMi || !$ticket): ?>
                    <div class="mb-3">
                        <a href="/admin/pages/destek.php" class="btn btn-primary">
                            <i class="bi bi-arrow-left me-1"></i> Taleplere Dön
                        </a>
                    </div>
                    <?php endif; ?>

                    <?php if ($yeniMi): ?>
                    <!-- ═══ YENİ TALEP FORMU ═══ -->
                    <div class="card card-primary card-outline">
                        <div class="card-header">
                            <h3 class="card-title"><i class="bi bi-pencil-square me-1"></i> Yeni Talep Oluştur</h3>
                        </div>
                        <div class="card-body">
                            <form method="post" enctype="multipart/form-data">
                                <input type="hidden" name="post_action" value="create_ticket">
                                <input type="hidden" name="form_anahtar" value="<?= bin2hex(random_bytes(16)) ?>">

                                <div class="mb-3">
                                    <label class="form-label">Konu <span class="text-danger">*</span></label>
                                    <input type="text" name="konu" class="form-control" required maxlength="300" value="<?= htmlspecialchars($_POST['konu'] ?? '') ?>">
                                </div>

                                <div class="row g-3 mb-3">
                                    <div class="col-md-6">
                                        <label class="form-label">Kategori <span class="text-danger">*</span></label>
                                        <select name="kategori_id" class="form-select select2" required>
                                            <option value="">Seçiniz...</option>
                                            <?php foreach ($kategoriler as $k): ?>
                                            <option value="<?= (int)$k['id'] ?>" <?= (($_POST['kategori_id'] ?? '') == $k['id']) ? 'selected' : '' ?>><?= htmlspecialchars($k['ad']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Öncelik <span class="text-danger">*</span></label>
                                        <select name="oncelik_id" class="form-select select2" required>
                                            <option value="">Seçiniz...</option>
                                            <?php foreach ($oncelikler as $o): ?>
                                            <option value="<?= (int)$o['id'] ?>" <?= (($_POST['oncelik_id'] ?? '') == $o['id']) ? 'selected' : '' ?>><?= htmlspecialchars($o['ad']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Açıklama <span class="text-danger">*</span></label>
                                    <textarea name="mesaj" class="form-control" rows="5" required><?= htmlspecialchars($_POST['mesaj'] ?? '') ?></textarea>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Dosya Ekle</label>
                                    <input type="file" class="form-control" name="dosyalar[]" multiple accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.xls,.xlsx,.txt">
                                    <div class="form-text">Maks. 10MB / dosya. Birden fazla dosya seçebilirsiniz.</div>
                                </div>

                                <div class="d-flex gap-2">
                                    <button type="submit" class="btn btn-success"><i class="bi bi-send me-1"></i> Gönder</button>
                                    <a href="/admin/pages/destek.php" class="btn btn-secondary">Vazgeç</a>
                                </div>
                            </form>
                        </div>
                    </div>

                    <?php elseif ($ticket): ?>
                    <!-- ═══ TALEP DETAY ═══ -->
                    <div class="row">

                        <!-- Sol: Mesajlar -->
                        <div class="col-lg-8">
                            <div class="card mb-3">
                                <div class="card-header">
                                    <i class="bi bi-chat-dots me-1"></i>
                                    <strong><?= htmlspecialchars($ticket['Tickets_konu'] ?? '') ?></strong>
                                </div>
                                <div class="card-body mesaj-listesi" id="mesajListesi">
                                    <?php if (empty($mesajlar)): ?>
                                    <div class="text-center text-muted py-4">Henüz mesaj yok.</div>
                                    <?php else: ?>
                                        <?php foreach ($mesajlar as $m): ?>
                                        <div class="mesaj-kutu">
                                            <div class="d-flex justify-content-between align-items-start mb-1">
                                                <strong><?= htmlspecialchars(trim(($m['yazan_ad'] ?? '') . ' ' . ($m['yazan_soyad'] ?? ''))) ?></strong>
                                                <small class="text-muted ms-3 flex-shrink-0"><?= htmlspecialchars($m['tarih'] ?? '') ?></small>
                                            </div>
                                            <div class="mesaj-metni"><?= metinHtml($m['mesaj'] ?? '') ?></div>
                                            <?php if (!empty($m['ekler'])): ?>
                                            <div class="mt-2">
                                                <?php foreach ($m['ekler'] as $ek): ?>
                                                <a href="<?= htmlspecialchars($ek['dosya_url'] ?? '#') ?>" target="_blank" class="badge bg-light text-dark border me-1">
                                                    <i class="bi bi-paperclip"></i> <?= htmlspecialchars($ek['dosya_adi'] ?? 'Dosya') ?>
                                                </a>
                                                <?php endforeach; ?>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Yanıt Formu -->
                            <?php if (empty($ticket['Ticket_Durumlar_kapatma_durumu'])): ?>
                            <div class="card">
                                <div class="card-header"><i class="bi bi-reply me-1"></i>Yanıt Yaz</div>
                                <div class="card-body">
                                    <form method="post" enctype="multipart/form-data">
                                        <input type="hidden" name="post_action" value="reply_ticket">
                                        <input type="hidden" name="form_anahtar" value="<?= bin2hex(random_bytes(16)) ?>">
                                        <div class="mb-3">
                                            <textarea name="mesaj" class="form-control" rows="4" required placeholder="Mesajınızı yazın..."></textarea>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Dosya Ekle (opsiyonel)</label>
                                            <input type="file" class="form-control" name="dosyalar[]" multiple accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.xls,.xlsx,.txt">
                                        </div>
                                        <button type="submit" class="btn btn-primary"><i class="bi bi-send me-1"></i>Gönder</button>
                                    </form>
                                </div>
                            </div>
                            <?php else: ?>
                            <div class="alert alert-warning">
                                <i class="bi bi-lock me-1"></i> Bu talep kapatılmıştır. Yanıt gönderemezsiniz.
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- Sağ: Talep Bilgileri -->
                        <div class="col-lg-4">
                            <div class="mb-3">
                                <a href="/admin/pages/destek.php" class="btn btn-primary">
                                    <i class="bi bi-arrow-left me-1"></i>Taleplere Dön
                                </a>
                            </div>
                            <div class="card mb-3">
                                <div class="card-header"><i class="bi bi-info-circle me-1"></i>Talep Bilgileri</div>
                                <div class="card-body">
                                    <table class="table table-sm table-borderless mb-0">
                                        <tr>
                                            <th class="text-muted" style="width:40%">Talep No</th>
                                            <td><code><?= htmlspecialchars($ticket['Tickets_no'] ?? '-') ?></code></td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Kategori</th>
                                            <td><?= htmlspecialchars($ticket['kategori_ad'] ?? '-') ?></td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Öncelik</th>
                                            <td><span class="badge" style="background:<?= htmlspecialchars($ticket['oncelik_renk'] ?? '#6c757d') ?>"><?= htmlspecialchars($ticket['oncelik_ad'] ?? '-') ?></span></td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Durum</th>
                                            <td><span class="badge" style="background:<?= htmlspecialchars($ticket['durum_renk'] ?? '#6c757d') ?>"><?= htmlspecialchars($ticket['durum_ad'] ?? '-') ?></span></td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Kullanıcı</th>
                                            <td>
                                                <?php $olusturan = trim(($ticket['olusturan_ad'] ?? '') . ' ' . ($ticket['olusturan_soyad'] ?? '')); ?>
                                                <?= $olusturan !== '' ? htmlspecialchars($olusturan) : '-' ?>
                                                <?php if (!empty($ticket['cc_mi'])): ?>
                                                <i class="bi bi-people-fill text-secondary ms-1" title="Bu talebe bilgilendirme (CC) amacıyla eklendiniz"></i>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Atanan</th>
                                            <td>
                                                <?php $atanan = trim(($ticket['atanan_ad'] ?? '') . ' ' . ($ticket['atanan_soyad'] ?? '')); ?>
                                                <?= $atanan !== '' ? htmlspecialchars($atanan) : '-' ?>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Açılma</th>
                                            <td><?= htmlspecialchars($ticket['acilis_tarihi'] ?? '-') ?></td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Son Yanıt</th>
                                            <td><?= htmlspecialchars($ticket['son_yanit_tarihi'] ?? '-') ?></td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                        </div>

                    </div>

                    <?php else: ?>
                    <div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-1"></i> Gösterilecek içerik bulunamadı.</div>
                    <?php endif; ?>
                </div>
            </div>
        </main>

        <?php include __DIR__ . '/../includes/footer.php'; ?>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
    <script src="/Admin/assets/js/adminlte.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script src="/Admin/assets/js/custom.js"></script>

    <script>
    $(document).ready(function() {
        // Select2 (aranabilir dropdown)
        $('.select2').select2({
            theme: 'bootstrap-5',
            width: '100%'
        });

        // Çift tıklamada formun ikinci kez gönderilmesini engelle
        $('form[method="post"]').on('submit', function (e) {
            var $form = $(this);
            if ($form.data('gonderiliyor')) { e.preventDefault(); return; }
            $form.data('gonderiliyor', true);
            $form.find('button[type="submit"]').prop('disabled', true)
                 .html('<i class="bi bi-hourglass-split me-1"></i> Gönderiliyor...');
        });

        // Mesajlar okundu → son kontrol zamanını sunucu tarihiyle güncelle (String kıyas, timezone bağımsız)
        // Mesaj listesini en alta kaydır
        var mesajListesi = document.getElementById('mesajListesi');
        if (mesajListesi) mesajListesi.scrollTop = mesajListesi.scrollHeight;

        localStorage.setItem('destek_son_kontrol', <?= json_encode(date('Y-m-d H:i:s')) ?>);

        <?php if ($basarili): ?>
        // Standart toast bildirimi
        Swal.fire({
            toast: true,
            position: 'top-end',
            icon: 'success',
            title: <?= json_encode($basarili, JSON_UNESCAPED_UNICODE) ?>,
            showConfirmButton: false,
            timer: 3000,
            timerProgressBar: true
        });
        <?php endif; ?>
    });
    </script>
</body>
</html>
