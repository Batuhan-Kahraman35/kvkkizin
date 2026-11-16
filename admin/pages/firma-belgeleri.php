<?php
/**
 * Admin Panel - Firma Belgeleri
 *
 * Firmalara ait belgeleri (sözleşme, vergi levhası, imza sirküleri vb.) yükler,
 * listeler, onaylar ve yönetir. Dosyalar web kökü dışında saklanır.
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
require_once __DIR__ . '/../includes/BelgeHelper.php';
require_once __DIR__ . '/../includes/BelgeServis.php';
requireAuth();

$user = Auth::user();
$db = Database::getInstance();

// Mevcut sayfanın bilgilerini al
$currentPagefile = basename($_SERVER['PHP_SELF']);
$pageinfo = $db->fetchOne("
    SELECT
        s.sayfalar_sayfa_adi,
        s.sayfalar_aciklama,
        m.menuler_menu_adi as menu_adi
    FROM Menu_Sayfalar s
    LEFT JOIN Menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1
", ['%' . $currentPagefile]);

$pageTitle = $pageinfo['sayfalar_sayfa_adi'] ?? 'Firma Belgeleri';
$pageDescription = $pageinfo['sayfalar_aciklama'] ?? '';
$menuAdi = $pageinfo['menu_adi'] ?? null;

// Sayfa yetkilerini kontrol et
$permissions = PageAuth::checkPagePermissions($user['kullanici_id'], $user['departman_id'], $currentPagefile);

if (!$permissions['has_access']) {
    PageAuth::accessDenied($permissions['error'] ?? 'Bu sayfaya erişim yetkiniz yok!');
}

// Kullanıcının görebileceği firmalar
$firmaScopeIds = PageAuth::firmaScopeIds($permissions, $user['firma_id'] ?? null);

$firmaKapsamdaMi = function ($firmaId) use ($firmaScopeIds) {
    if ($firmaScopeIds === null) {
        return true;
    }
    return in_array((int)$firmaId, $firmaScopeIds, true);
};

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

// AJAX işlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {
            case 'list':
                $belgeler = BelgeServis::listele($db, $firmaScopeIds, [
                    'search'         => $_POST['search'] ?? '',
                    'firma_id'       => $_POST['firma_id'] ?? '',
                    'belge_tipi_id'  => $_POST['belge_tipi_id'] ?? '',
                    'onay_durumu_id' => $_POST['onay_durumu_id'] ?? '',
                    'gecerlilik'     => $_POST['gecerlilik'] ?? '',
                    'status'         => $_POST['status'] ?? '',
                ]);
                echo json_encode(['success' => true, 'data' => $belgeler]);
                break;

            case 'firma_list':
                [$scopeSql, $scopeParams] = PageAuth::firmaScopeWhere($firmaScopeIds, 'firma_id');
                $firmalar = $db->fetchAll("
                    SELECT firma_id, firma_adi, firma_vkn_tc
                    FROM dbo.Firmalar
                    WHERE firma_durum = 1 AND $scopeSql
                    ORDER BY firma_adi
                ", $scopeParams);
                echo json_encode(['success' => true, 'data' => $firmalar]);
                break;

            case 'belge_tipi_list':
                echo json_encode(['success' => true, 'data' => BelgeServis::belgeTipleri($db)]);
                break;

            case 'onay_durumu_list':
                echo json_encode(['success' => true, 'data' => BelgeServis::onayDurumlari($db)]);
                break;

            case 'get':
                echo json_encode(BelgeServis::getir($db, $firmaScopeIds, $_POST['firma_belge_id'] ?? 0));
                break;

            case 'save':
                echo json_encode(BelgeServis::kaydet($db, $firmaScopeIds, $permissions, $user['kullanici_id'], $_POST, $_FILES));
                break;

            case 'onay_kaydet':
                echo json_encode(BelgeServis::onayKaydet($db, $firmaScopeIds, $permissions, $user['kullanici_id'], $_POST));
                break;

            case 'delete':
                echo json_encode(BelgeServis::sil($db, $firmaScopeIds, $permissions, $_POST['firma_belge_id'] ?? 0));
                break;

            case 'stats':
                echo json_encode(['success' => true, 'data' => BelgeServis::istatistik($db, $firmaScopeIds)]);
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
        .status-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 0.25rem;
            font-size: 0.875rem;
        }
        .status-active { background-color: #d4edda; color: #155724; }
        .status-inactive { background-color: #f8d7da; color: #721c24; }
        .dosya-adi { word-break: break-all; }
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
                                <?php if ($menuAdi): ?>
                                <li class="breadcrumb-item"><?= htmlspecialchars($menuAdi) ?></li>
                                <?php endif; ?>
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
                                    <span class="info-box-text">Toplam Belge</span>
                                    <span class="info-box-number" id="stat-toplam">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-box text-bg-warning">
                                <span class="info-box-icon"><i class="bi bi-hourglass-split"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Onay Bekleyen</span>
                                    <span class="info-box-number" id="stat-bekleyen">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-box text-bg-success">
                                <span class="info-box-icon"><i class="bi bi-check-circle"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Onaylanan</span>
                                    <span class="info-box-number" id="stat-onayli">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-box text-bg-danger">
                                <span class="info-box-icon"><i class="bi bi-calendar-x"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Süresi Dolan / Dolacak</span>
                                    <span class="info-box-number" id="stat-suresi-dolan">0</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Filtre Karti -->
                    <div class="card card-primary card-outline mb-3">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="bi bi-funnel"></i> Filtrele
                            </h3>
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
                                        <input type="text" class="form-control" name="search" id="filter_search" placeholder="Firma, başlık veya dosya adı...">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Firma</label>
                                        <select class="form-select select2-filter" name="firma_id" id="filter_firma_id" style="width: 100%;">
                                            <option value="">Tümü</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Belge Tipi</label>
                                        <select class="form-select select2-filter" name="belge_tipi_id" id="filter_belge_tipi_id" style="width: 100%;">
                                            <option value="">Tümü</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Onay Durumu</label>
                                        <select class="form-select select2-filter" name="onay_durumu_id" id="filter_onay_durumu_id" style="width: 100%;">
                                            <option value="">Tümü</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Geçerlilik</label>
                                        <select class="form-select select2-filter" name="gecerlilik" id="filter_gecerlilik" style="width: 100%;">
                                            <option value="">Tümü</option>
                                            <option value="dolmus">Süresi Dolmuş</option>
                                            <option value="yaklasan">30 Gün İçinde Dolacak</option>
                                            <option value="gecerli">Geçerli</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Durum</label>
                                        <select class="form-select select2-filter" name="status" id="filter_status" style="width: 100%;">
                                            <option value="">Tümü</option>
                                            <option value="1">Aktif</option>
                                            <option value="0">Pasif</option>
                                        </select>
                                    </div>
                                    <div class="col-md-12">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="bi bi-search"></i> Filtrele
                                        </button>
                                        <button type="button" class="btn btn-secondary" id="clearfilters">
                                            <i class="bi bi-x-circle"></i> Temizle
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Ana içerik -->
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">Belge Listesi</h3>
                            <div class="card-tools">
                                <?php if ($permissions['can_add']): ?>
                                <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#belgeModal" onclick="resetForm()">
                                    <i class="bi bi-cloud-upload"></i> Yeni Belge Yükle
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="card-body">
                            <table id="belgeTable" class="table table-bordered table-striped table-hover">
                                <thead>
                                    <tr>
                                        <th>Firma</th>
                                        <th>Belge Tipi</th>
                                        <th>Belge</th>
                                        <th>Geçerlilik</th>
                                        <th>Onay Durumu</th>
                                        <th>Yükleyen</th>
                                        <th>Yükleme Tarihi</th>
                                        <th>Durum</th>
                                        <th>İşlemler</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </main>

        <?php include __DIR__ . '/../includes/footer.php'; ?>
    </div>

    <!-- Belge Modal -->
    <div class="modal fade" id="belgeModal" tabindex="-1" aria-labelledby="belgeModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="belgeModalLabel">Yeni Belge Yükle</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="belgeForm" enctype="multipart/form-data">
                    <div class="modal-body">
                        <input type="hidden" id="firma_belge_id" name="firma_belge_id" value="">

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="firma_id" class="form-label">Firma <span class="text-danger">*</span></label>
                                <select class="form-select" id="firma_id" name="firma_id" style="width: 100%;" required></select>
                            </div>

                            <div class="col-md-6">
                                <label for="belge_tipi_id" class="form-label">Belge Tipi <span class="text-danger">*</span></label>
                                <select class="form-select" id="belge_tipi_id" name="belge_tipi_id" style="width: 100%;" required></select>
                                <small class="text-muted" id="belge_tipi_bilgi"></small>
                            </div>

                            <div class="col-md-12">
                                <label for="belge_dosya" class="form-label">Dosya <span class="text-danger" id="dosya_zorunlu">*</span></label>
                                <input type="file" class="form-control" id="belge_dosya" name="belge_dosya[]">
                                <small class="text-muted" id="dosya_yardim">Önce belge tipini seçin.</small>
                                <div id="mevcut_dosya" class="mt-2 d-none">
                                    <span class="badge text-bg-secondary">
                                        <i class="bi bi-paperclip"></i> Mevcut dosya: <span id="mevcut_dosya_adi"></span>
                                    </span>
                                    <small class="text-muted d-block">Yeni dosya seçerseniz eskisi silinir ve belge yeniden onaya düşer.</small>
                                </div>
                            </div>

                            <div class="col-md-12">
                                <label for="firma_belge_baslik" class="form-label">Başlık</label>
                                <input type="text" class="form-control" id="firma_belge_baslik" name="firma_belge_baslik" maxlength="200" placeholder="Boş bırakılırsa dosya adı kullanılır">
                            </div>

                            <div class="col-md-6">
                                <label for="firma_belge_gecerlilik_baslangic" class="form-label">Geçerlilik Başlangıç</label>
                                <input type="date" class="form-control" id="firma_belge_gecerlilik_baslangic" name="firma_belge_gecerlilik_baslangic">
                            </div>

                            <div class="col-md-6">
                                <label for="firma_belge_gecerlilik_bitis" class="form-label">Geçerlilik Bitiş</label>
                                <input type="date" class="form-control" id="firma_belge_gecerlilik_bitis" name="firma_belge_gecerlilik_bitis">
                                <small class="text-muted">Boş bırakılırsa belge tipinin süresine göre hesaplanır</small>
                            </div>

                            <div class="col-md-12">
                                <label for="firma_belge_aciklama" class="form-label">Açıklama</label>
                                <textarea class="form-control" id="firma_belge_aciklama" name="firma_belge_aciklama" rows="2" maxlength="500"></textarea>
                            </div>

                            <div class="col-md-12">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="firma_belge_durum" name="firma_belge_durum" checked>
                                    <label class="form-check-label" for="firma_belge_durum">Aktif</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="bi bi-x-circle"></i> İptal
                        </button>
                        <button type="submit" class="btn btn-primary" id="kaydetBtn">
                            <i class="bi bi-save"></i> Kaydet
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Onay Modal -->
    <div class="modal fade" id="onayModal" tabindex="-1" aria-labelledby="onayModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="onayModalLabel">Onay Durumu</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="onayForm">
                    <div class="modal-body">
                        <input type="hidden" id="onay_firma_belge_id" name="firma_belge_id" value="">

                        <div class="mb-3">
                            <label for="firma_belge_onay_durumu_id" class="form-label">Durum <span class="text-danger">*</span></label>
                            <select class="form-select" id="firma_belge_onay_durumu_id" name="firma_belge_onay_durumu_id" style="width: 100%;" required></select>
                        </div>

                        <div class="mb-3">
                            <label for="firma_belge_onay_aciklama" class="form-label">Açıklama</label>
                            <textarea class="form-control" id="firma_belge_onay_aciklama" name="firma_belge_onay_aciklama" rows="3" maxlength="500"></textarea>
                            <small class="text-muted">Reddedildi ve Revizyon İstendi durumlarında zorunludur.</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="bi bi-x-circle"></i> İptal
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-save"></i> Kaydet
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

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
        let belgeModal, onayModal;
        let dataTable;
        let belgeTipleri = [];
        let onayDurumlari = [];

        const permissions = {
            canAdd: <?= $permissions['can_add'] ? 'true' : 'false' ?>,
            canEdit: <?= $permissions['can_edit'] ? 'true' : 'false' ?>,
            canDelete: <?= $permissions['can_delete'] ? 'true' : 'false' ?>
        };

        let currentfilters = {};

        function escapeHtml(text) {
            if (text === null || text === undefined) return '';
            return String(text)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function formatDate(datestring) {
            if (!datestring) return '-';
            try {
                const date = new Date(String(datestring).replace(' ', 'T'));
                if (isNaN(date.getTime())) return '-';
                return date.toLocaleDateString('tr-TR', {
                    year: 'numeric', month: '2-digit', day: '2-digit',
                    hour: '2-digit', minute: '2-digit'
                });
            } catch (e) {
                return '-';
            }
        }

        function formatDateOnly(datestring) {
            if (!datestring) return null;
            const parcalar = String(datestring).substring(0, 10).split('-');
            if (parcalar.length !== 3) return null;
            return parcalar[2] + '.' + parcalar[1] + '.' + parcalar[0];
        }

        function formatBoyut(byte) {
            byte = parseFloat(byte || 0);
            const birimler = ['B', 'KB', 'MB', 'GB'];
            let i = 0;
            while (byte >= 1024 && i < birimler.length - 1) {
                byte /= 1024;
                i++;
            }
            return (i === 0 ? Math.round(byte) : byte.toFixed(1)) + ' ' + birimler[i];
        }

        $(document).ready(function() {
            belgeModal = new bootstrap.Modal(document.getElementById('belgeModal'));
            onayModal = new bootstrap.Modal(document.getElementById('onayModal'));

            dataTable = $('#belgeTable').DataTable({
                language: { url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/tr.json' },
                order: [[6, 'desc']],
                columnDefs: [{ orderable: false, targets: [2, 8] }],
                pageLength: 25,
                lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "Tümü"]]
            });

            $('.select2-filter').select2({
                theme: 'bootstrap-5',
                width: '100%',
                placeholder: 'Seçiniz...',
                allowClear: true
            });

            $('#firma_id, #belge_tipi_id').select2({
                theme: 'bootstrap-5',
                width: '100%',
                placeholder: 'Seçiniz...',
                dropdownParent: $('#belgeModal')
            });

            $('#firma_belge_onay_durumu_id').select2({
                theme: 'bootstrap-5',
                width: '100%',
                placeholder: 'Seçiniz...',
                dropdownParent: $('#onayModal')
            });

            // Belge tipi seçilince izinli formatlar ve boyut bilgisi gösterilir
            $('#belge_tipi_id').on('change', function() {
                belgeTipiBilgisiGoster($(this).val());
                gecerlilikBitisHesapla();
            });

            $('#firma_belge_gecerlilik_baslangic').on('change', gecerlilikBitisHesapla);

            loadFirmalar();
            loadBelgeTipleri();
            loadOnayDurumlari();

            // URL'den gelen firma filtresi (Firma Yönetimi'nden geliş)
            const urlParams = new URLSearchParams(window.location.search);
            const urlFirmaId = urlParams.get('firma_id');
            if (urlFirmaId) {
                currentfilters.firma_id = urlFirmaId;
                $('#filterCard').addClass('show');
            }

            loadBelgeList();
            loadStats();

            $('#belgeForm').on('submit', function(e) {
                e.preventDefault();
                saveBelge();
            });

            $('#onayForm').on('submit', function(e) {
                e.preventDefault();
                saveOnay();
            });

            $('#filterForm').on('submit', function(e) {
                e.preventDefault();

                currentfilters = {
                    search: $('#filter_search').val(),
                    firma_id: $('#filter_firma_id').val(),
                    belge_tipi_id: $('#filter_belge_tipi_id').val(),
                    onay_durumu_id: $('#filter_onay_durumu_id').val(),
                    gecerlilik: $('#filter_gecerlilik').val(),
                    status: $('#filter_status').val()
                };

                Object.keys(currentfilters).forEach(key => {
                    if (!currentfilters[key]) delete currentfilters[key];
                });

                loadBelgeList();
                showToast('Filtre uygulandı', 'info');
            });

            $('#clearfilters').on('click', function() {
                $('#filterForm')[0].reset();
                $('.select2-filter').val('').trigger('change.select2');
                currentfilters = {};
                loadBelgeList();
                showToast('Filtreler temizlendi', 'info');
            });
        });

        function belgeTipiBilgisiGoster(belgeTipiId) {
            const tip = belgeTipleri.find(t => String(t.belge_tipi_id) === String(belgeTipiId));
            if (!tip) {
                $('#belge_tipi_bilgi').text('');
                $('#dosya_yardim').text('Önce belge tipini seçin.');
                $('#belge_dosya').attr('accept', '').prop('multiple', false);
                return;
            }

            // Çoklu seçim tip izin veriyorsa; düzenlemede ilk dosya mevcudun yerine geçer
            const coklu = tip.belge_tipi_coklu_yukleme == 1;
            const duzenleme = !!$('#firma_belge_id').val();
            $('#belge_dosya').prop('multiple', coklu);

            const formatlar = (tip.belge_tipi_izinli_formatlar || '').split(',').map(f => f.trim()).filter(f => f);

            let bilgi = [];
            if (tip.belge_tipi_zorunlu == 1) bilgi.push('Zorunlu belge');
            if (tip.belge_tipi_coklu_yukleme == 1) {
                bilgi.push('Çoklu yükleme açık');
            } else {
                bilgi.push('Firma başına tek aktif belge');
            }
            if (tip.belge_tipi_gecerlilik_ay) bilgi.push(tip.belge_tipi_gecerlilik_ay + ' ay geçerli');
            $('#belge_tipi_bilgi').text(bilgi.join(' • '));

            $('#dosya_yardim').text(
                'İzinli formatlar: ' + formatlar.join(', ').toUpperCase() +
                ' • Maksimum ' + tip.belge_tipi_max_boyut_mb + ' MB' +
                (coklu ? ' • Birden fazla dosya seçebilirsiniz' : '') +
                (coklu && duzenleme ? ' (ilk dosya mevcut dosyanın yerine geçer, diğerleri yeni belge olarak eklenir)' : '')
            );
            $('#belge_dosya').attr('accept', formatlar.map(f => '.' + f).join(','));
        }

        // Belge tipinin geçerlilik süresine göre bitiş tarihini önerir
        function gecerlilikBitisHesapla() {
            const tip = belgeTipleri.find(t => String(t.belge_tipi_id) === String($('#belge_tipi_id').val()));
            const baslangic = $('#firma_belge_gecerlilik_baslangic').val();

            if (!tip || !tip.belge_tipi_gecerlilik_ay || !baslangic) return;
            if ($('#firma_belge_gecerlilik_bitis').val()) return;

            const d = new Date(baslangic);
            if (isNaN(d.getTime())) return;

            d.setMonth(d.getMonth() + parseInt(tip.belge_tipi_gecerlilik_ay, 10));
            $('#firma_belge_gecerlilik_bitis').val(d.toISOString().substring(0, 10));
        }

        function loadFirmalar() {
            $.post('', { action: 'firma_list' }, function(response) {
                if (response.success) {
                    const $filtre = $('#filter_firma_id');
                    const $modal = $('#firma_id');
                    $modal.empty().append(new Option('Seçiniz...', '', false, false));

                    response.data.forEach(f => {
                        const etiket = f.firma_vkn_tc ? `${f.firma_adi} (${f.firma_vkn_tc})` : f.firma_adi;
                        $filtre.append(new Option(etiket, f.firma_id, false, false));
                        $modal.append(new Option(etiket, f.firma_id, false, false));
                    });

                    if (currentfilters.firma_id) {
                        $filtre.val(currentfilters.firma_id);
                    }
                    $filtre.trigger('change.select2');
                    $modal.trigger('change.select2');
                }
            }, 'json');
        }

        function loadBelgeTipleri() {
            $.post('', { action: 'belge_tipi_list' }, function(response) {
                if (response.success) {
                    belgeTipleri = response.data;

                    const $filtre = $('#filter_belge_tipi_id');
                    const $modal = $('#belge_tipi_id');
                    $modal.empty().append(new Option('Seçiniz...', '', false, false));

                    belgeTipleri.forEach(t => {
                        $filtre.append(new Option(t.belge_tipi_adi, t.belge_tipi_id, false, false));
                        $modal.append(new Option(t.belge_tipi_adi, t.belge_tipi_id, false, false));
                    });

                    $filtre.trigger('change.select2');
                    $modal.trigger('change.select2');
                }
            }, 'json');
        }

        function loadOnayDurumlari() {
            $.post('', { action: 'onay_durumu_list' }, function(response) {
                if (response.success) {
                    onayDurumlari = response.data;

                    const $filtre = $('#filter_onay_durumu_id');
                    const $modal = $('#firma_belge_onay_durumu_id');
                    $modal.empty();

                    onayDurumlari.forEach(d => {
                        $filtre.append(new Option(d.onay_durumu_adi, d.onay_durumu_id, false, false));
                        $modal.append(new Option(d.onay_durumu_adi, d.onay_durumu_id, false, false));
                    });

                    $filtre.trigger('change.select2');
                    $modal.trigger('change.select2');
                }
            }, 'json');
        }

        function loadStats() {
            $.post('', { action: 'stats' }, function(response) {
                if (response.success) {
                    $('#stat-toplam').text(response.data.toplam);
                    $('#stat-bekleyen').text(response.data.bekleyen);
                    $('#stat-onayli').text(response.data.onayli);
                    $('#stat-suresi-dolan').text(response.data.suresi_dolan);
                }
            }, 'json');
        }

        function loadBelgeList() {
            $.ajax({
                url: '',
                method: 'POST',
                data: { action: 'list', ...currentfilters },
                dataType: 'json',
                success: function(data) {
                    if (data.success) {
                        renderBelgeTable(data.data);
                    } else {
                        showToast('Liste yüklenirken hata oluştu', 'error');
                    }
                },
                error: function() {
                    showToast('Sunucu hatası oluştu', 'error');
                }
            });
        }

        function renderBelgeTable(belgeler) {
            dataTable.clear();

            belgeler.forEach(b => {
                const ikon = b.belge_tipi_ikon
                    ? `<i class="${escapeHtml(b.belge_tipi_ikon)} me-1"></i>`
                    : '<i class="bi bi-file-earmark me-1"></i>';

                const baslik = b.firma_belge_baslik || b.firma_belge_dosya_adi;
                const belgeHucre = `
                    <div><strong>${escapeHtml(baslik)}</strong></div>
                    <small class="text-muted dosya-adi">
                        <span class="badge text-bg-secondary">${escapeHtml((b.firma_belge_dosya_uzanti || '').toUpperCase())}</span>
                        ${escapeHtml(b.firma_belge_dosya_adi)} · ${formatBoyut(b.firma_belge_dosya_boyut)}
                    </small>
                `;

                // Geçerlilik
                let gecerlilik;
                const bitis = formatDateOnly(b.firma_belge_gecerlilik_bitis);
                if (!bitis) {
                    gecerlilik = '<span class="text-muted">Süresiz</span>';
                } else {
                    const kalan = b.kalan_gun === null ? null : parseInt(b.kalan_gun, 10);
                    let renk = 'success';
                    let ek = '';
                    if (kalan !== null && kalan < 0) {
                        renk = 'danger';
                        ek = `<br><small>${Math.abs(kalan)} gün önce doldu</small>`;
                    } else if (kalan !== null && kalan <= 30) {
                        renk = 'warning';
                        ek = `<br><small>${kalan} gün kaldı</small>`;
                    }
                    gecerlilik = `<span class="badge text-bg-${renk}">${bitis}</span>${ek}`;
                }

                // Onay durumu
                let onay = `<span class="badge text-bg-${escapeHtml(b.onay_durumu_renk || 'secondary')}">
                        <i class="${escapeHtml(b.onay_durumu_ikon || 'bi bi-dot')}"></i> ${escapeHtml(b.onay_durumu_adi)}
                    </span>`;
                if (b.firma_belge_onay_aciklama) {
                    onay += `<br><small class="text-muted" title="${escapeHtml(b.firma_belge_onay_aciklama)}">${escapeHtml(b.firma_belge_onay_aciklama.length > 40 ? b.firma_belge_onay_aciklama.substring(0, 40) + '...' : b.firma_belge_onay_aciklama)}</small>`;
                }
                if (b.onaylayan_kullanici) {
                    onay += `<br><small class="text-muted">${escapeHtml(b.onaylayan_kullanici)}</small>`;
                }

                const durumBadge = b.firma_belge_durum
                    ? '<span class="status-badge status-active">Aktif</span>'
                    : '<span class="status-badge status-inactive">Pasif</span>';

                let islemler = `
                    <a class="btn btn-sm btn-info" href="/admin/belge-indir.php?id=${b.firma_belge_id}&onizle=1" target="_blank" title="Önizle">
                        <i class="bi bi-eye"></i>
                    </a>
                    <a class="btn btn-sm btn-success" href="/admin/belge-indir.php?id=${b.firma_belge_id}" title="İndir">
                        <i class="bi bi-download"></i>
                    </a>
                `;
                if (permissions.canEdit) {
                    islemler += `
                        <button class="btn btn-sm btn-primary" onclick="onayAc(${b.firma_belge_id}, ${b.firma_belge_onay_durumu_id})" title="Onay Durumu">
                            <i class="bi bi-clipboard-check"></i>
                        </button>
                        <button class="btn btn-sm btn-warning" onclick="editBelge(${b.firma_belge_id})" title="Düzenle">
                            <i class="bi bi-pencil"></i>
                        </button>
                    `;
                }
                if (permissions.canDelete) {
                    islemler += `
                        <button class="btn btn-sm btn-danger" onclick="deleteBelge(${b.firma_belge_id})" title="Sil">
                            <i class="bi bi-trash"></i>
                        </button>
                    `;
                }

                dataTable.row.add([
                    escapeHtml(b.firma_adi),
                    ikon + escapeHtml(b.belge_tipi_adi),
                    belgeHucre,
                    gecerlilik,
                    onay,
                    escapeHtml(b.yukleyen_kullanici || '-'),
                    formatDate(b.firma_belge_olusturma_tarihi),
                    durumBadge,
                    islemler
                ]);
            });

            dataTable.draw();
        }

        function resetForm() {
            document.getElementById('belgeForm').reset();
            document.getElementById('firma_belge_id').value = '';
            document.getElementById('belgeModalLabel').textContent = 'Yeni Belge Yükle';
            $('#firma_id').val('').trigger('change.select2');
            $('#belge_tipi_id').val('').trigger('change.select2');
            $('#mevcut_dosya').addClass('d-none');
            $('#dosya_zorunlu').removeClass('d-none');
            $('#belge_tipi_bilgi').text('');
            $('#dosya_yardim').text('Önce belge tipini seçin.');
            $('#belge_dosya').prop('multiple', false);

            // Yeni kayıtta filtredeki firma varsayılan gelsin
            if (currentfilters.firma_id) {
                $('#firma_id').val(currentfilters.firma_id).trigger('change.select2');
            }
        }

        function saveBelge() {
            const formData = new FormData(document.getElementById('belgeForm'));
            formData.append('action', 'save');

            const $btn = $('#kaydetBtn');
            $btn.prop('disabled', true).html('<i class="bi bi-hourglass-split"></i> Yükleniyor...');

            $.ajax({
                url: '',
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function(data) {
                    if (data.success) {
                        showToast(data.message, 'success');
                        belgeModal.hide();
                        loadBelgeList();
                        loadStats();
                    } else {
                        showToast(data.message, 'error');
                    }
                },
                error: function() {
                    showToast('Kayıt sırasında hata oluştu', 'error');
                },
                complete: function() {
                    $btn.prop('disabled', false).html('<i class="bi bi-save"></i> Kaydet');
                }
            });
        }

        function editBelge(belgeId) {
            $.ajax({
                url: '',
                method: 'POST',
                data: { action: 'get', firma_belge_id: belgeId },
                dataType: 'json',
                success: function(data) {
                    if (data.success && data.data) {
                        const b = data.data;

                        document.getElementById('firma_belge_id').value = b.firma_belge_id;
                        document.getElementById('firma_belge_baslik').value = b.firma_belge_baslik || '';
                        document.getElementById('firma_belge_aciklama').value = b.firma_belge_aciklama || '';
                        document.getElementById('firma_belge_gecerlilik_baslangic').value = b.firma_belge_gecerlilik_baslangic || '';
                        document.getElementById('firma_belge_gecerlilik_bitis').value = b.firma_belge_gecerlilik_bitis || '';
                        document.getElementById('firma_belge_durum').checked = b.firma_belge_durum == 1;
                        document.getElementById('belge_dosya').value = '';

                        $('#firma_id').val(b.firma_id).trigger('change.select2');
                        $('#belge_tipi_id').val(b.belge_tipi_id).trigger('change.select2');
                        belgeTipiBilgisiGoster(b.belge_tipi_id);

                        $('#mevcut_dosya_adi').text(b.firma_belge_dosya_adi || '-');
                        $('#mevcut_dosya').removeClass('d-none');
                        $('#dosya_zorunlu').addClass('d-none');

                        document.getElementById('belgeModalLabel').textContent = 'Belge Düzenle';
                        belgeModal.show();
                    } else {
                        showToast(data.message || 'Belge yüklenemedi', 'error');
                    }
                },
                error: function() {
                    showToast('Kayıt yüklenirken hata oluştu', 'error');
                }
            });
        }

        function onayAc(belgeId, mevcutDurumId) {
            document.getElementById('onay_firma_belge_id').value = belgeId;
            document.getElementById('firma_belge_onay_aciklama').value = '';
            $('#firma_belge_onay_durumu_id').val(mevcutDurumId).trigger('change.select2');
            onayModal.show();
        }

        function saveOnay() {
            const formData = new FormData(document.getElementById('onayForm'));
            formData.append('action', 'onay_kaydet');

            $.ajax({
                url: '',
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function(data) {
                    if (data.success) {
                        showToast(data.message, 'success');
                        onayModal.hide();
                        loadBelgeList();
                        loadStats();
                    } else {
                        showToast(data.message, 'error');
                    }
                },
                error: function() {
                    showToast('İşlem sırasında hata oluştu', 'error');
                }
            });
        }

        function deleteBelge(belgeId) {
            confirmAction(
                'Bu belgeyi silmek istediğinize emin misiniz?',
                'Dosya sunucudan da silinecek, bu işlem geri alınamaz!',
                function() {
                    $.ajax({
                        url: '',
                        method: 'POST',
                        data: { action: 'delete', firma_belge_id: belgeId },
                        dataType: 'json',
                        success: function(data) {
                            if (data.success) {
                                showSuccess('Silindi!', data.message);
                                loadBelgeList();
                                loadStats();
                            } else {
                                showError('Hata!', data.message);
                            }
                        },
                        error: function() {
                            showError('Bağlantı Hatası!', 'Sunucuya ulaşılamıyor.');
                        }
                    });
                }
            );
        }
    </script>
</body>
</html>
