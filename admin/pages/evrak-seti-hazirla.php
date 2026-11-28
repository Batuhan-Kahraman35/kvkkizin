<?php
/**
 * Admin Panel - Evrak Seti Hazırla
 *
 * Firma Yönetimi'ndeki firma bilgileriyle DOCX evrak şablonlarını (KVKK Politikası vb.)
 * doldurur. Tek evrak DOCX, birden fazla evrak ZIP olarak indirilir; her üretim geçmişe kaydedilir.
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
require_once __DIR__ . '/../includes/EvrakSetServis.php';
requireAuth();

$user = Auth::user();
$db = Database::getInstance();

$currentPagefile = basename($_SERVER['PHP_SELF']);
$pageinfo = $db->fetchOne("
    SELECT s.sayfalar_sayfa_adi, s.sayfalar_aciklama, m.menuler_menu_adi AS menu_adi
    FROM Menu_Sayfalar s
    LEFT JOIN Menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1
", ['%' . $currentPagefile]);

$pageTitle = $pageinfo['sayfalar_sayfa_adi'] ?? 'Evrak Seti Hazırla';
$menuAdi = $pageinfo['menu_adi'] ?? null;

$permissions = PageAuth::checkPagePermissions($user['kullanici_id'], $user['departman_id'], $currentPagefile);
if (!$permissions['has_access']) {
    PageAuth::accessDenied($permissions['error'] ?? 'Bu sayfaya erişim yetkiniz yok!');
}

$firmaScopeIds = PageAuth::firmaScopeIds($permissions, $user['firma_id'] ?? null);
$sablonYonetimi = !empty($permissions['is_admin']) || !empty($permissions['can_edit']);

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

// İndirme işlemleri (GET)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && (isset($_GET['indir']) || isset($_GET['sablon_indir']))) {
    if (isset($_GET['indir'])) {
        $kayit = EvrakSetServis::getir($db, $firmaScopeIds, $_GET['indir']);
        $tam = $kayit ? BelgeHelper::tamYol($kayit['evrak_set_dosya_yolu']) : null;
        $ad = $kayit['evrak_set_dosya_adi'] ?? '';
    } else {
        $sablon = $sablonYonetimi ? EvrakSetServis::sablonGetir($db, $_GET['sablon_indir']) : null;
        $tam = ($sablon && $sablon['evrak_sablon_dosya_yolu']) ? BelgeHelper::tamYol($sablon['evrak_sablon_dosya_yolu']) : null;
        $ad = $sablon ? EvrakSablonHelper::dosyaAdi($sablon['evrak_sablon_adi'] . ' (Şablon)', []) . '.docx' : '';
    }
    if ($tam === null) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        exit('Dosya bulunamadı veya erişim yetkiniz yok.');
    }
    $mime = strtolower(pathinfo($ad, PATHINFO_EXTENSION)) === 'zip'
        ? 'application/zip'
        : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    EvrakSetServis::gonder($tam, $ad, $mime);
}

// AJAX işlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {
            case 'firma_list':
                [$scopeSql, $scopeParams] = PageAuth::firmaScopeWhere($firmaScopeIds, 'firma_id');
                echo json_encode(['success' => true, 'data' => $db->fetchAll("
                    SELECT firma_id, firma_adi, firma_vkn_tc
                    FROM dbo.Firmalar
                    WHERE firma_durum = 1 AND $scopeSql
                    ORDER BY firma_adi
                ", $scopeParams)]);
                break;

            case 'tanimlar':
                echo json_encode(['success' => true, 'data' => [
                    'degiskenler' => EvrakSetServis::degiskenler($db),
                    'sablonlar'   => EvrakSetServis::sablonlar($db, !$sablonYonetimi),
                    'olusturanlar'=> EvrakSetServis::olusturanlar($db, $firmaScopeIds),
                ]]);
                break;

            case 'firma_bilgi':
                $firmaId = (int)($_POST['firma_id'] ?? 0);
                if (!EvrakSetServis::kapsamda($firmaScopeIds, $firmaId)) {
                    echo json_encode(['success' => false, 'message' => 'Firma kapsamınız dışında.']);
                    break;
                }
                $cozum = EvrakSetServis::firmaDegerleri($db, $firmaId);
                echo json_encode(['success' => true, 'data' => ['firma' => $cozum['firma'], 'detay' => $cozum['detay']]]);
                break;

            case 'hazirla':
                echo json_encode(EvrakSetServis::hazirla($db, $firmaScopeIds, $permissions, $user['kullanici_id'], $_POST));
                break;

            case 'list':
                echo json_encode(EvrakSetServis::listele($db, $firmaScopeIds, $_POST));
                break;

            case 'stats':
                echo json_encode(['success' => true, 'data' => EvrakSetServis::istatistik($db, $firmaScopeIds)]);
                break;

            case 'delete':
                echo json_encode(EvrakSetServis::sil($db, $firmaScopeIds, $permissions, $user['kullanici_id'], $_POST['evrak_set_id'] ?? 0));
                break;

            case 'sablon_get':
                $s = $sablonYonetimi ? EvrakSetServis::sablonGetir($db, $_POST['evrak_sablon_id'] ?? 0) : null;
                if ($s) {
                    unset($s['evrak_sablon_dosya_yolu']);
                }
                echo json_encode($s ? ['success' => true, 'data' => $s] : ['success' => false, 'message' => 'Şablon bulunamadı.']);
                break;

            case 'sablon_save':
                echo json_encode($sablonYonetimi
                    ? EvrakSetServis::sablonKaydet($db, $permissions, $user['kullanici_id'], $_POST, $_FILES)
                    : ['success' => false, 'message' => 'Şablon yönetimi yetkiniz yok.']);
                break;

            case 'sablon_delete':
                echo json_encode($sablonYonetimi
                    ? EvrakSetServis::sablonSil($db, $permissions, $user['kullanici_id'], $_POST['evrak_sablon_id'] ?? 0)
                    : ['success' => false, 'message' => 'Şablon yönetimi yetkiniz yok.']);
                break;

            default:
                echo json_encode(['success' => false, 'message' => 'Geçersiz işlem']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Hata: ' . $e->getMessage()]);
    }
    exit;
}
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
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/Admin/assets/css/custom.css">

    <style>
        .sablon-kart { border: 1px solid var(--bs-border-color); border-radius: .5rem; padding: .75rem 1rem; height: 100%; }
        .sablon-kart.pasif { opacity: .55; }
        .degisken-rozet { font-family: var(--bs-font-monospace); font-size: .75rem; }
        #firmaBilgiTablo td:first-child { width: 35%; }
        .deger-bos { color: var(--bs-secondary-color); font-style: italic; }
        tr.satir-eksik { --bs-table-bg: rgba(var(--bs-danger-rgb), .08); }
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
                        <div class="col-sm-6"><h3 class="mb-0"><?= htmlspecialchars($pageTitle) ?></h3></div>
                        <div class="col-sm-6">
                            <ol class="breadcrumb float-sm-end">
                                <?php if ($menuAdi): ?><li class="breadcrumb-item"><?= htmlspecialchars($menuAdi) ?></li><?php endif; ?>
                                <li class="breadcrumb-item active"><?= htmlspecialchars($pageTitle) ?></li>
                            </ol>
                        </div>
                    </div>
                </div>
            </div>

            <div class="app-content">
                <div class="container-fluid">
                    <!-- Info Boxes -->
                    <div class="row mb-3">
                        <div class="col-md-3">
                            <div class="info-box text-bg-primary">
                                <span class="info-box-icon"><i class="bi bi-files"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Hazırlanan Set</span>
                                    <span class="info-box-number" id="stat-toplam">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-box text-bg-success">
                                <span class="info-box-icon"><i class="bi bi-calendar-check"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Bu Ay</span>
                                    <span class="info-box-number" id="stat-bu-ay">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-box text-bg-info">
                                <span class="info-box-icon"><i class="bi bi-building-check"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Evrakı Hazırlanan Firma</span>
                                    <span class="info-box-number" id="stat-firma">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-box text-bg-warning">
                                <span class="info-box-icon"><i class="bi bi-file-earmark-word"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Aktif Şablon</span>
                                    <span class="info-box-number" id="stat-sablon">0</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <ul class="nav nav-tabs mb-3" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabHazirla" type="button" role="tab">
                                <i class="bi bi-magic"></i> Evrak Hazırla
                            </button>
                        </li>
                        <?php if ($sablonYonetimi): ?>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabSablonlar" type="button" role="tab">
                                <i class="bi bi-file-earmark-word"></i> Şablonlar
                            </button>
                        </li>
                        <?php endif; ?>
                    </ul>

                    <div class="tab-content">
                        <!-- ===================== Evrak Hazırla ===================== -->
                        <div class="tab-pane fade show active" id="tabHazirla" role="tabpanel">
                            <?php if ($permissions['can_add']): ?>
                            <div class="card card-primary card-outline mb-3">
                                <div class="card-header"><h3 class="card-title"><i class="bi bi-magic"></i> Yeni Evrak Seti</h3></div>
                                <form id="hazirlaForm">
                                    <div class="card-body">
                                        <div class="row g-3">
                                            <div class="col-lg-5">
                                                <label for="firma_id" class="form-label">Firma <span class="text-danger">*</span></label>
                                                <select class="form-select" id="firma_id" name="firma_id" style="width: 100%;" required></select>
                                                <div id="formDegiskenleri" class="row g-3 mt-0"></div>
                                            </div>
                                            <div class="col-lg-7">
                                                <label class="form-label d-flex justify-content-between">
                                                    <span>Firma Bilgileri</span>
                                                    <a href="/Admin/firma-yonetimi" target="_blank" class="small" id="firmaDuzenleLink">
                                                        <i class="bi bi-box-arrow-up-right"></i> Firma Yönetimi'nde düzenle
                                                    </a>
                                                </label>
                                                <div id="firmaBilgiBos" class="text-muted border rounded p-3">
                                                    <i class="bi bi-info-circle"></i> Evraklara yazılacak bilgileri görmek için firma seçin.
                                                </div>
                                                <table class="table table-sm table-bordered mb-0 d-none" id="firmaBilgiTablo"><tbody></tbody></table>
                                            </div>

                                            <div class="col-12">
                                                <label class="form-label d-flex justify-content-between">
                                                    <span>Evraklar <span class="text-danger">*</span></span>
                                                    <span>
                                                        <a href="#" class="small me-2" id="tumSablonlariSec">Tümünü seç</a>
                                                        <a href="#" class="small" id="sablonSecimiKaldir">Seçimi kaldır</a>
                                                    </span>
                                                </label>
                                                <div class="row g-2" id="sablonSecimListesi"></div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="card-footer d-flex align-items-center gap-3">
                                        <button type="submit" class="btn btn-primary" id="hazirlaBtn">
                                            <i class="bi bi-download"></i> Hazırla ve İndir
                                        </button>
                                        <small class="text-muted" id="hazirlaBilgi">Tek evrak DOCX, birden fazla evrak ZIP olarak iner.</small>
                                    </div>
                                </form>
                            </div>
                            <?php endif; ?>

                            <!-- Filtre -->
                            <div class="card card-primary card-outline mb-3">
                                <div class="card-header">
                                    <h3 class="card-title"><i class="bi bi-funnel"></i> Filtrele</h3>
                                    <div class="card-tools">
                                        <button type="button" class="btn btn-tool" data-bs-toggle="collapse" data-bs-target="#filterCard" aria-expanded="false">
                                            <i class="bi bi-chevron-down"></i>
                                        </button>
                                    </div>
                                </div>
                                <div class="card-body collapse" id="filterCard">
                                    <form id="filterForm">
                                        <div class="row g-3">
                                            <div class="col-md-4">
                                                <label class="form-label">Ara</label>
                                                <input type="text" class="form-control" id="filter_search" placeholder="Firma, VKN veya dosya adı...">
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label">Firma</label>
                                                <select class="form-select select2-filter" id="filter_firma_id" style="width: 100%;"><option value="">Tümü</option></select>
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label">Evrak</label>
                                                <select class="form-select select2-filter" id="filter_sablon_kod" style="width: 100%;"><option value="">Tümü</option></select>
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label">Hazırlayan</label>
                                                <select class="form-select select2-filter" id="filter_olusturan_id" style="width: 100%;"><option value="">Tümü</option></select>
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label">Tarih (başlangıç)</label>
                                                <input type="date" class="form-control" id="filter_tarih_bas">
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label">Tarih (bitiş)</label>
                                                <input type="date" class="form-control" id="filter_tarih_bit">
                                            </div>
                                            <div class="col-md-12">
                                                <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Filtrele</button>
                                                <button type="button" class="btn btn-secondary" id="clearfilters"><i class="bi bi-x-circle"></i> Temizle</button>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            </div>

                            <div class="card">
                                <div class="card-header"><h3 class="card-title">Hazırlanan Evrak Setleri</h3></div>
                                <div class="card-body">
                                    <table id="setTable" class="table table-bordered table-striped table-hover w-100">
                                        <thead>
                                            <tr>
                                                <th>Firma</th>
                                                <th>Evraklar</th>
                                                <th>Dosya</th>
                                                <th>Hazırlayan</th>
                                                <th>Tarih</th>
                                                <th>İşlemler</th>
                                            </tr>
                                        </thead>
                                        <tbody></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <?php if ($sablonYonetimi): ?>
                        <!-- ===================== Şablonlar ===================== -->
                        <div class="tab-pane fade" id="tabSablonlar" role="tabpanel">
                            <div class="card mb-3">
                                <div class="card-header">
                                    <h3 class="card-title">Evrak Şablonları</h3>
                                    <div class="card-tools">
                                        <?php if ($permissions['can_add']): ?>
                                        <button type="button" class="btn btn-primary btn-sm" onclick="sablonYeni()">
                                            <i class="bi bi-plus-lg"></i> Yeni Şablon
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <table id="sablonTable" class="table table-bordered table-striped table-hover w-100">
                                        <thead>
                                            <tr>
                                                <th>Sıra</th>
                                                <th>Şablon</th>
                                                <th>Kullanılan Değişkenler</th>
                                                <th>Çıktı Adı</th>
                                                <th>Kullanım</th>
                                                <th>Durum</th>
                                                <th>İşlemler</th>
                                            </tr>
                                        </thead>
                                        <tbody></tbody>
                                    </table>
                                </div>
                            </div>

                            <div class="card">
                                <div class="card-header"><h3 class="card-title"><i class="bi bi-braces"></i> Kullanılabilir Değişkenler</h3></div>
                                <div class="card-body">
                                    <p class="text-muted mb-2">
                                        Word şablonunda değerin geleceği yere <code>{{kod}}</code> yazın. Biçim (kalın, yazı tipi vb.)
                                        yer tutucunun biçiminden alınır. Değişkenler <code>tanim_EvrakDegiskenleri</code> tablosundan gelir.
                                    </p>
                                    <table class="table table-sm table-bordered mb-0" id="degiskenTablo">
                                        <thead><tr><th>Yer Tutucu</th><th>Açıklama</th><th>Kaynak</th><th>Zorunlu</th></tr></thead>
                                        <tbody></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>

        <?php include __DIR__ . '/../includes/footer.php'; ?>
    </div>

    <?php if ($sablonYonetimi): ?>
    <!-- Şablon Modal -->
    <div class="modal fade" id="sablonModal" tabindex="-1" aria-labelledby="sablonModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="sablonModalLabel">Yeni Şablon</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                </div>
                <form id="sablonForm" enctype="multipart/form-data">
                    <div class="modal-body">
                        <input type="hidden" id="evrak_sablon_id" name="evrak_sablon_id" value="">
                        <div class="row g-3">
                            <div class="col-md-8">
                                <label for="evrak_sablon_adi" class="form-label">Şablon Adı <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="evrak_sablon_adi" name="evrak_sablon_adi" maxlength="150" required>
                            </div>
                            <div class="col-md-4">
                                <label for="evrak_sablon_kod" class="form-label">Kod <span class="text-danger">*</span></label>
                                <input type="text" class="form-control text-uppercase" id="evrak_sablon_kod" name="evrak_sablon_kod" maxlength="50" pattern="[A-Za-z0-9_]{2,50}" placeholder="KVKK_POLITIKASI" required>
                            </div>
                            <div class="col-md-12">
                                <label for="evrak_sablon_aciklama" class="form-label">Açıklama</label>
                                <input type="text" class="form-control" id="evrak_sablon_aciklama" name="evrak_sablon_aciklama" maxlength="500">
                            </div>
                            <div class="col-md-9">
                                <label for="evrak_sablon_cikti_adi" class="form-label">Çıktı Dosya Adı <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="evrak_sablon_cikti_adi" name="evrak_sablon_cikti_adi" maxlength="200" placeholder="{{firma_adi}} - KVKK Politikası" required>
                                <small class="text-muted">Değişken kullanılabilir; .docx uzantısı otomatik eklenir.</small>
                            </div>
                            <div class="col-md-3">
                                <label for="evrak_sablon_sira_no" class="form-label">Sıra</label>
                                <input type="number" class="form-control" id="evrak_sablon_sira_no" name="evrak_sablon_sira_no" value="0" min="0" max="9999">
                            </div>
                            <div class="col-md-12">
                                <label for="sablon_dosya" class="form-label">Şablon Dosyası (DOCX) <span class="text-danger" id="sablonDosyaZorunlu">*</span></label>
                                <input type="file" class="form-control" id="sablon_dosya" name="sablon_dosya" accept=".docx">
                                <small class="text-muted" id="sablonDosyaYardim">En fazla <?= EvrakSetServis::SABLON_MAX_MB ?> MB. Tanımsız <code>{{değişken}}</code> içeren dosya kabul edilmez.</small>
                            </div>
                            <div class="col-md-12">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" role="switch" id="sablon_durum" name="Durum" value="1" checked>
                                    <label class="form-check-label" for="sablon_durum">Aktif</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="bi bi-x-circle"></i> İptal</button>
                        <button type="submit" class="btn btn-primary" id="sablonKaydetBtn"><i class="bi bi-save"></i> Kaydet</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
    <script src="/Admin/assets/js/adminlte.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script src="/Admin/assets/js/custom.js"></script>

    <script>
        const permissions = {
            canAdd: <?= $permissions['can_add'] ? 'true' : 'false' ?>,
            canEdit: <?= $permissions['can_edit'] ? 'true' : 'false' ?>,
            canDelete: <?= $permissions['can_delete'] ? 'true' : 'false' ?>,
            sablon: <?= $sablonYonetimi ? 'true' : 'false' ?>
        };
        const KAYNAK_ETIKET = { firma: 'Firma Yönetimi', form: 'Bu sayfada girilir' };

        let dataTable, sablonTable, sablonModal;
        let degiskenler = [], sablonlar = [], firmaDetay = [];
        let currentfilters = {};

        function escapeHtml(text) {
            if (text === null || text === undefined) return '';
            return String(text).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
        }
        function formatDate(s) {
            if (!s) return '-';
            const d = new Date(String(s).replace(' ', 'T'));
            return isNaN(d.getTime()) ? '-' : d.toLocaleDateString('tr-TR', { year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit' });
        }
        function formatBoyut(b) {
            b = parseFloat(b || 0);
            const u = ['B', 'KB', 'MB', 'GB']; let i = 0;
            while (b >= 1024 && i < u.length - 1) { b /= 1024; i++; }
            return (i === 0 ? Math.round(b) : b.toFixed(1)) + ' ' + u[i];
        }
        function bugun() {
            const d = new Date();
            return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
        }
        function degiskenRozet(kod) {
            return `<span class="badge text-bg-light border degisken-rozet">{{${escapeHtml(kod)}}}</span>`;
        }

        $(document).ready(function() {
            $('.select2-filter').select2({ theme: 'bootstrap-5', width: '100%', placeholder: 'Seçiniz...', allowClear: true });
            $('#firma_id').select2({ theme: 'bootstrap-5', width: '100%', placeholder: 'Firma seçin...', allowClear: true });

            dataTable = $('#setTable').DataTable({
                language: { url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/tr.json' },
                serverSide: true,
                processing: true,
                dom: 'lrtip',
                scrollX: true,
                pageLength: 25,
                lengthMenu: [[10, 25, 50, 100, 250], [10, 25, 50, 100, 250]],
                order: [[4, 'desc']],
                ajax: {
                    url: '',
                    type: 'POST',
                    data: function(d) { d.action = 'list'; Object.assign(d, currentfilters); },
                    error: function() { showToast('Liste yüklenirken hata oluştu', 'error'); }
                },
                columns: [
                    { data: 'firma_adi', render: (v, t, r) => `<strong>${escapeHtml(v)}</strong>` + (r.firma_vkn_tc ? `<br><small class="text-muted">${escapeHtml(r.firma_vkn_tc)}</small>` : '') },
                    { data: 'sablonlar', orderable: false, render: v => (v || []).map(s => `<span class="badge text-bg-secondary me-1">${escapeHtml(s.adi)}</span>`).join('') },
                    { data: 'evrak_set_dosya_boyut', render: (v, t, r) => {
                        const zip = /\.zip$/i.test(r.evrak_set_dosya_adi);
                        return `<i class="bi ${zip ? 'bi-file-earmark-zip' : 'bi-file-earmark-word'} me-1"></i>${escapeHtml(r.evrak_set_dosya_adi)}<br><small class="text-muted">${formatBoyut(v)}</small>`;
                    }},
                    { data: 'olusturan', orderable: false, render: v => escapeHtml(v || '-') },
                    { data: 'tarih', render: v => formatDate(v) },
                    { data: 'evrak_set_id', orderable: false, className: 'text-nowrap', render: (v, t, r) => {
                        let h = `<a class="btn btn-sm btn-success" href="?indir=${v}" title="İndir"><i class="bi bi-download"></i></a> `;
                        if (permissions.canAdd) {
                            h += `<button class="btn btn-sm btn-primary" onclick="yenidenHazirla(${r.firma_id}, ${escapeHtml(JSON.stringify((r.sablonlar || []).map(s => s.id)))})" title="Aynı evraklarla yeniden hazırla"><i class="bi bi-arrow-repeat"></i></button> `;
                        }
                        if (permissions.canDelete) {
                            h += `<button class="btn btn-sm btn-danger" onclick="setSil(${v})" title="Sil"><i class="bi bi-trash"></i></button>`;
                        }
                        return h;
                    }}
                ]
            });

            if (permissions.sablon) {
                sablonModal = new bootstrap.Modal(document.getElementById('sablonModal'));
                sablonTable = $('#sablonTable').DataTable({
                    language: { url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/tr.json' },
                    order: [[0, 'asc']],
                    columnDefs: [{ orderable: false, targets: [2, 6] }],
                    pageLength: 25
                });
                $('button[data-bs-target="#tabSablonlar"]').on('shown.bs.tab', () => sablonTable.columns.adjust());
                $('#sablonForm').on('submit', function(e) { e.preventDefault(); sablonKaydet(); });
            }
            $('button[data-bs-target="#tabHazirla"]').on('shown.bs.tab', () => dataTable.columns.adjust());

            loadFirmalar();
            loadTanimlar();
            loadStats();

            $('#firma_id').on('change', function() { firmaBilgiYukle($(this).val()); });
            $('#sablonSecimListesi').on('change', '.sablon-sec', eksikleriIsaretle);
            $('#tumSablonlariSec').on('click', e => { e.preventDefault(); $('.sablon-sec:not(:disabled)').prop('checked', true); eksikleriIsaretle(); });
            $('#sablonSecimiKaldir').on('click', e => { e.preventDefault(); $('.sablon-sec').prop('checked', false); eksikleriIsaretle(); });
            $('#hazirlaForm').on('submit', function(e) { e.preventDefault(); hazirla(); });

            $('#filterForm').on('submit', function(e) {
                e.preventDefault();
                currentfilters = {
                    search: $('#filter_search').val(),
                    firma_id: $('#filter_firma_id').val(),
                    sablon_kod: $('#filter_sablon_kod').val(),
                    olusturan_id: $('#filter_olusturan_id').val(),
                    tarih_bas: $('#filter_tarih_bas').val(),
                    tarih_bit: $('#filter_tarih_bit').val()
                };
                Object.keys(currentfilters).forEach(k => { if (!currentfilters[k]) delete currentfilters[k]; });
                dataTable.ajax.reload();
                showToast('Filtre uygulandı', 'info');
            });
            $('#clearfilters').on('click', function() {
                $('#filterForm')[0].reset();
                $('.select2-filter').val('').trigger('change.select2');
                currentfilters = {};
                dataTable.ajax.reload();
                showToast('Filtreler temizlendi', 'info');
            });
        });

        function loadStats() {
            $.post('', { action: 'stats' }, function(r) {
                if (!r.success) return;
                $('#stat-toplam').text(r.data.toplam);
                $('#stat-bu-ay').text(r.data.bu_ay);
                $('#stat-firma').text(r.data.firma);
                $('#stat-sablon').text(r.data.sablon);
            }, 'json');
        }

        function loadFirmalar() {
            $.post('', { action: 'firma_list' }, function(r) {
                if (!r.success) return;
                const $f = $('#firma_id').empty().append(new Option('', '', false, false));
                const $filtre = $('#filter_firma_id');
                r.data.forEach(f => {
                    const etiket = f.firma_vkn_tc ? `${f.firma_adi} (${f.firma_vkn_tc})` : f.firma_adi;
                    $f.append(new Option(etiket, f.firma_id, false, false));
                    $filtre.append(new Option(etiket, f.firma_id, false, false));
                });
                // Firma Yönetimi'nden geliş: ?firma_id=
                const urlFirma = new URLSearchParams(window.location.search).get('firma_id');
                if (urlFirma && $f.find(`option[value="${parseInt(urlFirma, 10)}"]`).length) {
                    $f.val(urlFirma).trigger('change');
                } else {
                    $f.trigger('change.select2');
                }
                $filtre.trigger('change.select2');
            }, 'json');
        }

        function loadTanimlar() {
            $.post('', { action: 'tanimlar' }, function(r) {
                if (!r.success) { showToast(r.message || 'Tanımlar yüklenemedi', 'error'); return; }
                degiskenler = r.data.degiskenler;
                sablonlar = r.data.sablonlar;

                // Formdan girilen değişkenler (ör. yürürlük tarihi)
                const $fd = $('#formDegiskenleri').empty();
                degiskenler.filter(d => d.evrak_degisken_kaynak === 'form').forEach(d => {
                    const tip = d.evrak_degisken_bicim === 'tarih' ? 'date' : 'text';
                    $fd.append(`
                        <div class="col-md-6">
                            <label class="form-label" for="deger_${escapeHtml(d.evrak_degisken_kod)}">${escapeHtml(d.evrak_degisken_etiket)}${d.evrak_degisken_zorunlu == 1 ? ' <span class="text-danger">*</span>' : ''}</label>
                            <input type="${tip}" class="form-control form-degisken" id="deger_${escapeHtml(d.evrak_degisken_kod)}" name="deger[${escapeHtml(d.evrak_degisken_kod)}]"
                                   data-kod="${escapeHtml(d.evrak_degisken_kod)}" value="${tip === 'date' ? bugun() : ''}">
                        </div>`);
                });

                // Evrak seçimi
                const $liste = $('#sablonSecimListesi').empty();
                const aktifler = sablonlar.filter(s => s.Durum == 1);
                if (!aktifler.length) {
                    $liste.append('<div class="col-12 text-muted">Tanımlı aktif şablon yok.</div>');
                }
                aktifler.forEach(s => {
                    const dosyaYok = s.dosya_var != 1;
                    $liste.append(`
                        <div class="col-md-6 col-xl-4">
                            <div class="sablon-kart ${dosyaYok ? 'pasif' : ''}">
                                <div class="form-check form-switch">
                                    <input class="form-check-input sablon-sec" type="checkbox" role="switch" id="sablon_${s.evrak_sablon_id}" name="sablon_ids[]" value="${s.evrak_sablon_id}" ${dosyaYok ? 'disabled' : 'checked'}>
                                    <label class="form-check-label fw-semibold" for="sablon_${s.evrak_sablon_id}">${escapeHtml(s.evrak_sablon_adi)}</label>
                                </div>
                                ${s.evrak_sablon_aciklama ? `<small class="text-muted d-block">${escapeHtml(s.evrak_sablon_aciklama)}</small>` : ''}
                                ${dosyaYok ? '<small class="text-danger"><i class="bi bi-exclamation-triangle"></i> Şablon dosyası yok</small>' : ''}
                            </div>
                        </div>`);
                });

                // Filtre: evrak ve hazırlayan
                const $fs = $('#filter_sablon_kod').find('option:not([value=""])').remove().end();
                sablonlar.forEach(s => $fs.append(new Option(s.evrak_sablon_adi, s.evrak_sablon_kod, false, false)));
                $fs.trigger('change.select2');
                const $fo = $('#filter_olusturan_id').find('option:not([value=""])').remove().end();
                (r.data.olusturanlar || []).forEach(o => $fo.append(new Option(o.ad, o.kullanici_id, false, false)));
                $fo.trigger('change.select2');

                if (permissions.sablon) {
                    sablonTabloDoldur();
                    $('#degiskenTablo tbody').html(degiskenler.map(d => `
                        <tr>
                            <td>${degiskenRozet(d.evrak_degisken_kod)}</td>
                            <td>${escapeHtml(d.evrak_degisken_etiket)}</td>
                            <td>${escapeHtml(KAYNAK_ETIKET[d.evrak_degisken_kaynak] || d.evrak_degisken_kaynak)}${d.evrak_degisken_kaynak_alan ? ` <small class="text-muted">(${escapeHtml(d.evrak_degisken_kaynak_alan)}${d.evrak_degisken_yedek_alan ? ' → ' + escapeHtml(d.evrak_degisken_yedek_alan) : ''})</small>` : ''}</td>
                            <td>${d.evrak_degisken_zorunlu == 1 ? '<span class="badge text-bg-danger">Zorunlu</span>' : '<span class="badge text-bg-light border">İsteğe bağlı</span>'}</td>
                        </tr>`).join(''));
                }
                eksikleriIsaretle();
            }, 'json');
        }

        function firmaBilgiYukle(firmaId) {
            firmaDetay = [];
            if (!firmaId) {
                $('#firmaBilgiTablo').addClass('d-none');
                $('#firmaBilgiBos').removeClass('d-none');
                eksikleriIsaretle();
                return;
            }
            $.post('', { action: 'firma_bilgi', firma_id: firmaId }, function(r) {
                if (!r.success) { showToast(r.message, 'error'); return; }
                firmaDetay = r.data.detay.filter(d => d.kaynak === 'firma');
                $('#firmaBilgiTablo tbody').html(firmaDetay.map(d => `
                    <tr data-kod="${escapeHtml(d.kod)}">
                        <td>${escapeHtml(d.etiket)}</td>
                        <td>${d.deger ? escapeHtml(d.deger) : '<span class="deger-bos">Boş</span>'} <span class="durum-rozet"></span></td>
                    </tr>`).join(''));
                $('#firmaBilgiBos').addClass('d-none');
                $('#firmaBilgiTablo').removeClass('d-none');
                eksikleriIsaretle();
            }, 'json');
        }

        // Seçili şablonlarda kullanılan zorunlu ama boş alanları işaretler
        function eksikleriIsaretle() {
            const seciliIdler = $('.sablon-sec:checked').map((i, el) => String(el.value)).get();
            const kullanilan = new Set();
            sablonlar.filter(s => seciliIdler.includes(String(s.evrak_sablon_id))).forEach(s => (s.degiskenler || []).forEach(k => kullanilan.add(k)));

            let eksik = 0;
            $('#firmaBilgiTablo tbody tr').each(function() {
                const d = firmaDetay.find(x => x.kod === $(this).data('kod'));
                const $rozet = $(this).find('.durum-rozet').empty();
                $(this).removeClass('satir-eksik');
                if (!d) return;
                if (!kullanilan.has(d.kod)) {
                    $rozet.html('<span class="badge text-bg-light border">Kullanılmıyor</span>');
                } else if (!d.deger && d.zorunlu == 1) {
                    $rozet.html('<span class="badge text-bg-danger">Eksik</span>');
                    $(this).addClass('satir-eksik');
                    eksik++;
                } else if (d.deger) {
                    $rozet.html('<i class="bi bi-check-circle-fill text-success"></i>');
                }
            });

            const sayi = seciliIdler.length;
            let bilgi = sayi === 0 ? 'Evrak seçin.' : (sayi === 1 ? '1 evrak DOCX olarak iner.' : `${sayi} evrak ZIP olarak iner.`);
            if (eksik) bilgi = `<span class="text-danger"><i class="bi bi-exclamation-triangle"></i> ${eksik} zorunlu bilgi eksik. Firma Yönetimi'nden tamamlayın.</span>`;
            $('#hazirlaBilgi').html(bilgi);
            $('#hazirlaBtn').prop('disabled', sayi === 0 || eksik > 0 || !$('#firma_id').val());
        }

        function hazirla() {
            const fd = new FormData(document.getElementById('hazirlaForm'));
            fd.append('action', 'hazirla');
            const $btn = $('#hazirlaBtn');
            $btn.prop('disabled', true).html('<i class="bi bi-hourglass-split"></i> Hazırlanıyor...');
            $.ajax({
                url: '', method: 'POST', data: fd, processData: false, contentType: false, dataType: 'json',
                success: function(r) {
                    if (r.success) {
                        showToast(r.message, 'success');
                        window.location.href = '?indir=' + r.evrak_set_id;
                        dataTable.ajax.reload(null, false);
                        loadStats();
                        loadTanimlar();
                    } else {
                        showToast(r.message, 'error');
                    }
                },
                error: function() { showToast('Evrak hazırlanırken hata oluştu', 'error'); },
                complete: function() {
                    $btn.html('<i class="bi bi-download"></i> Hazırla ve İndir');
                    eksikleriIsaretle();
                }
            });
        }

        function yenidenHazirla(firmaId, sablonIdler) {
            $('#firma_id').val(firmaId).trigger('change');
            $('.sablon-sec').each(function() { this.checked = !this.disabled && sablonIdler.includes(parseInt(this.value, 10)); });
            eksikleriIsaretle();
            document.getElementById('hazirlaForm').scrollIntoView({ behavior: 'smooth' });
            showToast('Firma ve evraklar seçildi, bilgileri kontrol edip hazırlayın', 'info');
        }

        function setSil(id) {
            confirmAction('Bu evrak setini silmek istediğinize emin misiniz?', 'Üretilen dosya sunucudan silinecek.', function() {
                $.post('', { action: 'delete', evrak_set_id: id }, function(r) {
                    if (r.success) {
                        showToast(r.message, 'success');
                        dataTable.ajax.reload(null, false);
                        loadStats();
                    } else {
                        showToast(r.message, 'error');
                    }
                }, 'json').fail(() => showToast('Sunucuya ulaşılamıyor', 'error'));
            });
        }

        /* ---------------- Şablon yönetimi ---------------- */
        function sablonTabloDoldur() {
            sablonTable.clear();
            sablonlar.forEach(s => {
                let islem = s.dosya_var == 1
                    ? `<a class="btn btn-sm btn-success" href="?sablon_indir=${s.evrak_sablon_id}" title="Şablonu indir"><i class="bi bi-download"></i></a> ` : '';
                if (permissions.canEdit) islem += `<button class="btn btn-sm btn-warning" onclick="sablonDuzenle(${s.evrak_sablon_id})" title="Düzenle"><i class="bi bi-pencil"></i></button> `;
                if (permissions.canDelete && s.Durum == 1) islem += `<button class="btn btn-sm btn-danger" onclick="sablonSil(${s.evrak_sablon_id})" title="Pasife al"><i class="bi bi-slash-circle"></i></button>`;
                sablonTable.row.add([
                    s.evrak_sablon_sira_no,
                    `<strong>${escapeHtml(s.evrak_sablon_adi)}</strong><br><small class="text-muted">${escapeHtml(s.evrak_sablon_kod)}${s.dosya_var == 1 ? ' · ' + formatBoyut(s.dosya_boyut) : ' · <span class="text-danger">dosya yok</span>'}</small>`,
                    (s.degiskenler || []).map(degiskenRozet).join(' ') || '<span class="text-muted">-</span>',
                    escapeHtml(s.evrak_sablon_cikti_adi),
                    `${s.kullanim} set`,
                    s.Durum == 1 ? '<span class="badge text-bg-success">Aktif</span>' : '<span class="badge text-bg-secondary">Pasif</span>',
                    islem
                ]);
            });
            sablonTable.draw();
        }

        function sablonYeni() {
            document.getElementById('sablonForm').reset();
            $('#evrak_sablon_id').val('');
            $('#sablonModalLabel').text('Yeni Şablon');
            $('#sablonDosyaZorunlu').removeClass('d-none');
            sablonModal.show();
        }

        function sablonDuzenle(id) {
            $.post('', { action: 'sablon_get', evrak_sablon_id: id }, function(r) {
                if (!r.success) { showToast(r.message, 'error'); return; }
                const s = r.data;
                document.getElementById('sablonForm').reset();
                $('#evrak_sablon_id').val(s.evrak_sablon_id);
                $('#evrak_sablon_adi').val(s.evrak_sablon_adi);
                $('#evrak_sablon_kod').val(s.evrak_sablon_kod);
                $('#evrak_sablon_aciklama').val(s.evrak_sablon_aciklama || '');
                $('#evrak_sablon_cikti_adi').val(s.evrak_sablon_cikti_adi);
                $('#evrak_sablon_sira_no').val(s.evrak_sablon_sira_no);
                $('#sablon_durum').prop('checked', s.Durum == 1);
                $('#sablonDosyaZorunlu').addClass('d-none');
                $('#sablonModalLabel').text('Şablon Düzenle');
                sablonModal.show();
            }, 'json');
        }

        function sablonKaydet() {
            const fd = new FormData(document.getElementById('sablonForm'));
            fd.append('action', 'sablon_save');
            const $btn = $('#sablonKaydetBtn').prop('disabled', true);
            $.ajax({
                url: '', method: 'POST', data: fd, processData: false, contentType: false, dataType: 'json',
                success: function(r) {
                    if (r.success) {
                        showToast(r.message, 'success');
                        sablonModal.hide();
                        loadTanimlar();
                        loadStats();
                    } else {
                        showToast(r.message, 'error');
                    }
                },
                error: function() { showToast('Kayıt sırasında hata oluştu', 'error'); },
                complete: function() { $btn.prop('disabled', false); }
            });
        }

        function sablonSil(id) {
            confirmAction('Şablon pasife alınsın mı?', 'Geçmiş evrak setleri etkilenmez; şablon yeniden aktif edilebilir.', function() {
                $.post('', { action: 'sablon_delete', evrak_sablon_id: id }, function(r) {
                    showToast(r.message, r.success ? 'success' : 'error');
                    if (r.success) { loadTanimlar(); loadStats(); }
                }, 'json');
            });
        }
    </script>
</body>
</html>
