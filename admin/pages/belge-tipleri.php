<?php
/**
 * Admin Panel - Belge Tipleri
 *
 * Firma belgelerinin tip tanımlarını yönetir (Sözleşme, Vergi Levhası, İmza Sirküleri vb.)
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
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

$pageTitle = $pageinfo['sayfalar_sayfa_adi'] ?? 'Belge Tipleri';
$pageDescription = $pageinfo['sayfalar_aciklama'] ?? '';
$menuAdi = $pageinfo['menu_adi'] ?? null;

// Sayfa yetkilerini kontrol et
$permissions = PageAuth::checkPagePermissions($user['kullanici_id'], $user['departman_id'], $currentPagefile);

// Erişim yetkisi yoksa hata sayfası göster
if (!$permissions['has_access']) {
    PageAuth::accessDenied($permissions['error'] ?? 'Bu sayfaya erişim yetkiniz yok!');
}

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

// AJAX işlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {
            case 'list':
                $search = $_POST['search'] ?? '';
                $status = $_POST['status'] ?? '';
                $zorunlu = $_POST['zorunlu'] ?? '';
                $format = $_POST['format'] ?? '';

                $whereConditions = ["1=1"];
                $params = [];

                if ($search) {
                    $whereConditions[] = "(b.belge_tipi_adi LIKE ? OR b.belge_tipi_kod LIKE ? OR b.belge_tipi_aciklama LIKE ?)";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                }

                if ($status !== '') {
                    $whereConditions[] = "b.belge_tipi_durum = ?";
                    $params[] = (int)$status;
                }

                if ($zorunlu !== '') {
                    $whereConditions[] = "b.belge_tipi_zorunlu = ?";
                    $params[] = (int)$zorunlu;
                }

                if ($format !== '') {
                    // Virgüllü listede tam uzantı eşleşmesi
                    $whereConditions[] = "',' + REPLACE(b.belge_tipi_izinli_formatlar, ' ', '') + ',' LIKE ?";
                    $params[] = '%,' . $format . ',%';
                }

                $whereClause = implode(" AND ", $whereConditions);

                $belgeTipleri = $db->fetchAll("
                    SELECT
                        b.belge_tipi_id,
                        b.belge_tipi_adi,
                        b.belge_tipi_kod,
                        b.belge_tipi_aciklama,
                        b.belge_tipi_ikon,
                        b.belge_tipi_zorunlu,
                        b.belge_tipi_coklu_yukleme,
                        b.belge_tipi_dosya_zorunlu,
                        b.belge_tipi_gecerlilik_ay,
                        b.belge_tipi_izinli_formatlar,
                        b.belge_tipi_max_boyut_mb,
                        b.belge_tipi_sira_no,
                        b.belge_tipi_durum,
                        CONVERT(VARCHAR(19), b.belge_tipi_olusturma_tarihi, 120) as belge_tipi_olusturma_tarihi,
                        ko.kullanici_ad + ' ' + ko.kullanici_soyad as olusturan_kullanici,
                        CONVERT(VARCHAR(19), b.belge_tipi_guncelleme_tarihi, 120) as belge_tipi_guncelleme_tarihi
                    FROM dbo.tanim_BelgeTipleri b
                    LEFT JOIN kullanicilar ko ON b.belge_tipi_olusturan_kullanici_id = ko.kullanici_id
                    WHERE $whereClause
                    ORDER BY b.belge_tipi_sira_no, b.belge_tipi_adi
                ", $params);
                echo json_encode(['success' => true, 'data' => $belgeTipleri]);
                break;

            case 'format_list':
                $formatlar = $db->fetchAll("
                    SELECT dosya_formati_id, dosya_formati_uzanti, dosya_formati_adi, dosya_formati_ikon
                    FROM dbo.tanim_DosyaFormatlari
                    WHERE dosya_formati_durum = 1
                    ORDER BY dosya_formati_sira_no, dosya_formati_uzanti
                ");
                echo json_encode(['success' => true, 'data' => $formatlar]);
                break;

            case 'get':
                $belgeTipiId = (int)($_POST['belge_tipi_id'] ?? 0);
                $belgeTipi = $db->fetchOne("SELECT * FROM dbo.tanim_BelgeTipleri WHERE belge_tipi_id = ?", [$belgeTipiId]);
                echo json_encode(['success' => true, 'data' => $belgeTipi]);
                break;

            case 'save':
                $belgeTipiId = (int)($_POST['belge_tipi_id'] ?? 0);

                // Yetki kontrolü
                if ($belgeTipiId > 0 && !$permissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Düzenleme yetkiniz yok!']);
                    break;
                }
                if ($belgeTipiId == 0 && !$permissions['can_add']) {
                    echo json_encode(['success' => false, 'message' => 'Ekleme yetkiniz yok!']);
                    break;
                }

                $adi = trim($_POST['belge_tipi_adi'] ?? '');
                if ($adi === '') {
                    echo json_encode(['success' => false, 'message' => 'Belge tipi adı boş olamaz!']);
                    break;
                }

                $kod = trim($_POST['belge_tipi_kod'] ?? '');
                $kod = $kod !== '' ? mb_strtoupper($kod, 'UTF-8') : null;

                // Kod benzersiz olmalı
                if ($kod !== null) {
                    $kodVar = $db->fetchOne(
                        "SELECT count(*) as count FROM dbo.tanim_BelgeTipleri WHERE belge_tipi_kod = ? AND belge_tipi_id <> ?",
                        [$kod, $belgeTipiId]
                    )['count'] ?? 0;
                    if ($kodVar > 0) {
                        echo json_encode(['success' => false, 'message' => 'Bu belge tipi kodu zaten kullanılıyor!']);
                        break;
                    }
                }

                // Aynı ad ile ikinci kayıt olmasın
                $adVar = $db->fetchOne(
                    "SELECT count(*) as count FROM dbo.tanim_BelgeTipleri WHERE belge_tipi_adi = ? AND belge_tipi_id <> ?",
                    [$adi, $belgeTipiId]
                )['count'] ?? 0;
                if ($adVar > 0) {
                    echo json_encode(['success' => false, 'message' => 'Bu isimde bir belge tipi zaten var!']);
                    break;
                }

                // İzinli formatlar yalnızca tanımlı ve aktif uzantılardan seçilebilir.
                // Boş bırakılırsa sistemde tanımlı tüm aktif formatlar kabul edilir.
                $secilenFormatlar = $_POST['belge_tipi_izinli_formatlar'] ?? [];
                if (!is_array($secilenFormatlar)) {
                    $secilenFormatlar = explode(',', (string)$secilenFormatlar);
                }
                $secilenFormatlar = array_values(array_unique(array_filter(array_map(
                    fn($f) => strtolower(trim((string)$f)),
                    $secilenFormatlar
                ))));

                $gecerliFormatlar = array_column($db->fetchAll("
                    SELECT dosya_formati_uzanti
                    FROM dbo.tanim_DosyaFormatlari
                    WHERE dosya_formati_durum = 1
                "), 'dosya_formati_uzanti');
                $gecerliFormatlar = array_map('strtolower', $gecerliFormatlar);

                $tanimsiz = array_diff($secilenFormatlar, $gecerliFormatlar);
                if (!empty($tanimsiz)) {
                    echo json_encode(['success' => false, 'message' => 'Tanımlı olmayan dosya formatı: ' . implode(', ', $tanimsiz)]);
                    break;
                }

                $maxBoyut = (int)($_POST['belge_tipi_max_boyut_mb'] ?? 5);
                if ($maxBoyut < 1 || $maxBoyut > 100) {
                    echo json_encode(['success' => false, 'message' => 'Maksimum dosya boyutu 1-100 MB aralığında olmalıdır!']);
                    break;
                }

                $gecerlilikAy = $_POST['belge_tipi_gecerlilik_ay'] ?? '';
                $gecerlilikAy = ($gecerlilikAy === '' ? null : (int)$gecerlilikAy);
                if ($gecerlilikAy !== null && ($gecerlilikAy < 1 || $gecerlilikAy > 600)) {
                    echo json_encode(['success' => false, 'message' => 'Geçerlilik süresi 1-600 ay aralığında olmalıdır!']);
                    break;
                }

                $data = [
                    'belge_tipi_adi'              => $adi,
                    'belge_tipi_kod'              => $kod,
                    'belge_tipi_aciklama'         => trim($_POST['belge_tipi_aciklama'] ?? '') ?: null,
                    'belge_tipi_ikon'             => trim($_POST['belge_tipi_ikon'] ?? '') ?: null,
                    'belge_tipi_zorunlu'          => isset($_POST['belge_tipi_zorunlu']) ? 1 : 0,
                    'belge_tipi_coklu_yukleme'    => isset($_POST['belge_tipi_coklu_yukleme']) ? 1 : 0,
                    'belge_tipi_dosya_zorunlu'    => isset($_POST['belge_tipi_dosya_zorunlu']) ? 1 : 0,
                    'belge_tipi_gecerlilik_ay'    => $gecerlilikAy,
                    'belge_tipi_izinli_formatlar' => implode(',', $secilenFormatlar),
                    'belge_tipi_max_boyut_mb'     => $maxBoyut,
                    'belge_tipi_sira_no'          => (int)($_POST['belge_tipi_sira_no'] ?? 0),
                    'belge_tipi_durum'            => isset($_POST['belge_tipi_durum']) ? 1 : 0,
                ];

                if ($belgeTipiId > 0) {
                    $data['belge_tipi_guncelleme_tarihi'] = date('Y-m-d H:i:s');
                    $data['belge_tipi_guncelleyen_kullanici_id'] = $user['kullanici_id'];

                    $result = $db->update('dbo.tanim_BelgeTipleri', $data, ['belge_tipi_id' => $belgeTipiId]);
                    echo json_encode(['success' => $result, 'message' => $result ? 'Belge tipi başarıyla güncellendi' : 'Güncelleme hatası']);
                } else {
                    $data['belge_tipi_olusturma_tarihi'] = date('Y-m-d H:i:s');
                    $data['belge_tipi_olusturan_kullanici_id'] = $user['kullanici_id'];

                    $result = $db->insert('dbo.tanim_BelgeTipleri', $data);
                    echo json_encode(['success' => (bool)$result, 'message' => $result ? 'Belge tipi başarıyla eklendi' : 'Ekleme hatası']);
                }
                break;

            case 'toggle_status':
                if (!$permissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Düzenleme yetkiniz yok!']);
                    break;
                }

                $belgeTipiId = (int)($_POST['belge_tipi_id'] ?? 0);
                $mevcut = $db->fetchOne("SELECT belge_tipi_durum FROM dbo.tanim_BelgeTipleri WHERE belge_tipi_id = ?", [$belgeTipiId]);
                if (!$mevcut) {
                    echo json_encode(['success' => false, 'message' => 'Belge tipi bulunamadı!']);
                    break;
                }

                $yeniDurum = $mevcut['belge_tipi_durum'] ? 0 : 1;
                $db->update('dbo.tanim_BelgeTipleri', [
                    'belge_tipi_durum' => $yeniDurum,
                    'belge_tipi_guncelleme_tarihi' => date('Y-m-d H:i:s'),
                    'belge_tipi_guncelleyen_kullanici_id' => $user['kullanici_id'],
                ], ['belge_tipi_id' => $belgeTipiId]);

                echo json_encode(['success' => true, 'message' => $yeniDurum ? 'Belge tipi aktif edildi' : 'Belge tipi pasife alındı']);
                break;

            case 'delete':
                if (!$permissions['can_delete']) {
                    echo json_encode(['success' => false, 'message' => 'Silme yetkiniz yok!']);
                    break;
                }

                $belgeTipiId = (int)($_POST['belge_tipi_id'] ?? 0);

                // Firma belgeleri ara tablosu kurulduğunda kullanım kontrolü devreye girer
                $checks = [];
                $araTabloVar = $db->fetchOne("
                    SELECT count(*) as count
                    FROM INFORMATION_SCHEMA.TABLES
                    WHERE TABLE_SCHEMA = 'dbo' AND TABLE_NAME = 'Firma_Belgeleri'
                ")['count'] ?? 0;

                if ($araTabloVar > 0) {
                    $belgeSayisi = $db->fetchOne(
                        "SELECT count(*) as count FROM dbo.Firma_Belgeleri WHERE belge_tipi_id = ?",
                        [$belgeTipiId]
                    )['count'] ?? 0;
                    if ($belgeSayisi > 0) {
                        $checks[] = "$belgeSayisi firma belgesi";
                    }
                }

                if (!empty($checks)) {
                    echo json_encode([
                        'success' => false,
                        'message' => 'Bu belge tipi silinemez! Şu kayıtlarda kullanılıyor: ' . implode(', ', $checks)
                    ]);
                    break;
                }

                $result = $db->delete('dbo.tanim_BelgeTipleri', ['belge_tipi_id' => $belgeTipiId]);
                echo json_encode(['success' => true, 'message' => $result ? 'Belge tipi başarıyla silindi' : 'Silme hatası']);
                break;

            case 'stats':
                $stats = [
                    'toplam'  => $db->fetchOne("SELECT count(*) as count FROM dbo.tanim_BelgeTipleri")['count'] ?? 0,
                    'aktif'   => $db->fetchOne("SELECT count(*) as count FROM dbo.tanim_BelgeTipleri WHERE belge_tipi_durum = 1")['count'] ?? 0,
                    'pasif'   => $db->fetchOne("SELECT count(*) as count FROM dbo.tanim_BelgeTipleri WHERE belge_tipi_durum = 0")['count'] ?? 0,
                    'zorunlu' => $db->fetchOne("SELECT count(*) as count FROM dbo.tanim_BelgeTipleri WHERE belge_tipi_zorunlu = 1 AND belge_tipi_durum = 1")['count'] ?? 0,
                ];
                echo json_encode(['success' => true, 'data' => $stats]);
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
        .format-badge { margin-right: 0.15rem; }
        #ikon_onizleme { font-size: 1.25rem; }
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
                                <span class="info-box-icon"><i class="bi bi-folder2-open"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Toplam Belge Tipi</span>
                                    <span class="info-box-number" id="stat-toplam">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-box text-bg-success">
                                <span class="info-box-icon"><i class="bi bi-check-circle"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Aktif</span>
                                    <span class="info-box-number" id="stat-aktif">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-box text-bg-danger">
                                <span class="info-box-icon"><i class="bi bi-x-circle"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Pasif</span>
                                    <span class="info-box-number" id="stat-pasif">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-box text-bg-warning">
                                <span class="info-box-icon"><i class="bi bi-exclamation-triangle"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Zorunlu Belge</span>
                                    <span class="info-box-number" id="stat-zorunlu">0</span>
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
                                        <input type="text" class="form-control" name="search" id="filter_search" placeholder="Belge tipi adı, kodu veya açıklaması...">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Dosya Formatı</label>
                                        <select class="form-select select2-filter" name="format" id="filter_format" style="width: 100%;">
                                            <option value="">Tümü</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Zorunluluk</label>
                                        <select class="form-select select2-filter" name="zorunlu" id="filter_zorunlu" style="width: 100%;">
                                            <option value="">Tümü</option>
                                            <option value="1">Zorunlu</option>
                                            <option value="0">İsteğe Bağlı</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
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
                            <h3 class="card-title">Belge Tipi Listesi</h3>
                            <div class="card-tools">
                                <?php if ($permissions['can_add']): ?>
                                <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#belgeTipiModal" onclick="resetForm()">
                                    <i class="bi bi-plus-circle"></i> Yeni Belge Tipi Ekle
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="card-body">
                            <table id="belgeTipiTable" class="table table-bordered table-striped table-hover">
                                <thead>
                                    <tr>
                                        <th>Sıra</th>
                                        <th>Belge Tipi</th>
                                        <th>Kod</th>
                                        <th>İzinli Formatlar</th>
                                        <th>Max Boyut</th>
                                        <th>Geçerlilik</th>
                                        <th>Zorunlu</th>
                                        <th>Çoklu</th>
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

    <!-- Belge Tipi Modal -->
    <div class="modal fade" id="belgeTipiModal" tabindex="-1" aria-labelledby="belgeTipiModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="belgeTipiModalLabel">Yeni Belge Tipi Ekle</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="belgeTipiForm">
                    <div class="modal-body">
                        <input type="hidden" id="belge_tipi_id" name="belge_tipi_id" value="">

                        <div class="row g-3">
                            <div class="col-md-8">
                                <label for="belge_tipi_adi" class="form-label">Belge Tipi Adı <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="belge_tipi_adi" name="belge_tipi_adi" required maxlength="150">
                            </div>

                            <div class="col-md-4">
                                <label for="belge_tipi_kod" class="form-label">Kod</label>
                                <input type="text" class="form-control text-uppercase" id="belge_tipi_kod" name="belge_tipi_kod" maxlength="50" placeholder="SOZLESME">
                                <small class="text-muted">Benzersiz olmalıdır</small>
                            </div>

                            <div class="col-md-12">
                                <label for="belge_tipi_aciklama" class="form-label">Açıklama</label>
                                <textarea class="form-control" id="belge_tipi_aciklama" name="belge_tipi_aciklama" rows="2" maxlength="500"></textarea>
                            </div>

                            <div class="col-md-12">
                                <label for="belge_tipi_izinli_formatlar" class="form-label">İzinli Dosya Formatları</label>
                                <select class="form-select" id="belge_tipi_izinli_formatlar" name="belge_tipi_izinli_formatlar[]" multiple style="width: 100%;"></select>
                                <small class="text-muted">Bu belge tipi için yüklenebilecek dosya uzantıları. Boş bırakılırsa sistemde tanımlı tüm formatlar kabul edilir.</small>
                            </div>

                            <div class="col-md-4">
                                <label for="belge_tipi_max_boyut_mb" class="form-label">Max Dosya Boyutu (MB) <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" id="belge_tipi_max_boyut_mb" name="belge_tipi_max_boyut_mb" min="1" max="100" value="5" required>
                            </div>

                            <div class="col-md-4">
                                <label for="belge_tipi_gecerlilik_ay" class="form-label">Geçerlilik Süresi (Ay)</label>
                                <input type="number" class="form-control" id="belge_tipi_gecerlilik_ay" name="belge_tipi_gecerlilik_ay" min="1" max="600" placeholder="Süresiz">
                                <small class="text-muted">Boş bırakılırsa süresiz</small>
                            </div>

                            <div class="col-md-4">
                                <label for="belge_tipi_sira_no" class="form-label">Sıra No</label>
                                <input type="number" class="form-control" id="belge_tipi_sira_no" name="belge_tipi_sira_no" min="0" value="0">
                            </div>

                            <div class="col-md-12">
                                <label for="belge_tipi_ikon" class="form-label">İkon</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i id="ikon_onizleme" class="bi bi-question-circle"></i></span>
                                    <input type="text" class="form-control" id="belge_tipi_ikon" name="belge_tipi_ikon" maxlength="50" placeholder="bi bi-file-earmark-text">
                                </div>
                                <small class="text-muted">Bootstrap Icons sınıfı (örn: <code>bi bi-receipt</code>)</small>
                            </div>

                            <div class="col-md-6">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" role="switch" id="belge_tipi_zorunlu" name="belge_tipi_zorunlu" value="1">
                                    <label class="form-check-label" for="belge_tipi_zorunlu">Zorunlu Belge</label>
                                </div>
                                <small class="text-muted">Her firmada bu belge bulunmalı (eksik belge takibi)</small>
                            </div>

                            <div class="col-md-6">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" role="switch" id="belge_tipi_dosya_zorunlu" name="belge_tipi_dosya_zorunlu" value="1" checked>
                                    <label class="form-check-label" for="belge_tipi_dosya_zorunlu">Dosya Gerekli</label>
                                </div>
                                <small class="text-muted">Kapalıysa kayıt dosya yüklenmeden açılabilir</small>
                            </div>

                            <div class="col-md-6">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" role="switch" id="belge_tipi_coklu_yukleme" name="belge_tipi_coklu_yukleme" value="1">
                                    <label class="form-check-label" for="belge_tipi_coklu_yukleme">Çoklu Yükleme</label>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" role="switch" id="belge_tipi_durum" name="belge_tipi_durum" value="1" checked>
                                    <label class="form-check-label" for="belge_tipi_durum">Aktif</label>
                                </div>
                            </div>
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
        let belgeTipiModal;
        let dataTable;
        let formatListesi = [];

        // Sayfa yetkileri (PHP'den)
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

        $(document).ready(function() {
            belgeTipiModal = new bootstrap.Modal(document.getElementById('belgeTipiModal'));

            dataTable = $('#belgeTipiTable').DataTable({
                language: {
                    url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/tr.json'
                },
                order: [[0, 'asc']],
                columnDefs: [
                    { orderable: false, targets: [3, 9] }
                ],
                pageLength: 25,
                lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "Tümü"]]
            });

            // Aranabilir dropdown'lar
            $('.select2-filter').select2({
                theme: 'bootstrap-5',
                width: '100%',
                placeholder: 'Seçiniz...',
                allowClear: true
            });

            $('#belge_tipi_izinli_formatlar').select2({
                theme: 'bootstrap-5',
                width: '100%',
                placeholder: 'Dosya formatlarını seçin...',
                closeOnSelect: false,
                dropdownParent: $('#belgeTipiModal')
            });

            // İkon canlı önizleme
            $('#belge_tipi_ikon').on('input', function() {
                const sinif = $(this).val().trim() || 'bi bi-question-circle';
                $('#ikon_onizleme').attr('class', sinif);
            });

            // Kod alanını büyük harfe çevir
            $('#belge_tipi_kod').on('input', function() {
                this.value = this.value.toLocaleUpperCase('tr-TR');
            });

            loadFormatlar(function() {
                loadBelgeTipiList();
            });
            loadStats();

            $('#belgeTipiForm').on('submit', function(e) {
                e.preventDefault();
                saveBelgeTipi();
            });

            // Filtre form submit
            $('#filterForm').on('submit', function(e) {
                e.preventDefault();

                currentfilters = {
                    search: $('#filter_search').val(),
                    status: $('#filter_status').val(),
                    zorunlu: $('#filter_zorunlu').val(),
                    format: $('#filter_format').val()
                };

                Object.keys(currentfilters).forEach(key => {
                    if (!currentfilters[key]) delete currentfilters[key];
                });

                loadBelgeTipiList();
                showToast('Filtre uygulandı', 'info');
            });

            // Filtreleri temizle
            $('#clearfilters').on('click', function() {
                $('#filterForm')[0].reset();
                $('#filter_status').val('').trigger('change.select2');
                $('#filter_zorunlu').val('').trigger('change.select2');
                $('#filter_format').val('').trigger('change.select2');
                currentfilters = {};
                loadBelgeTipiList();
                showToast('Filtreler temizlendi', 'info');
            });
        });

        // Tanımlı dosya formatlarını yükle (filtre + modal)
        function loadFormatlar(callback) {
            $.post('', { action: 'format_list' }, function(response) {
                if (response.success) {
                    formatListesi = response.data;

                    const $filtre = $('#filter_format');
                    const $modal = $('#belge_tipi_izinli_formatlar');
                    $modal.empty();

                    formatListesi.forEach(f => {
                        const etiket = f.dosya_formati_uzanti.toUpperCase() + ' - ' + f.dosya_formati_adi;
                        $filtre.append(new Option(etiket, f.dosya_formati_uzanti, false, false));
                        $modal.append(new Option(etiket, f.dosya_formati_uzanti, false, false));
                    });

                    $filtre.trigger('change.select2');
                    $modal.trigger('change.select2');
                }
                if (typeof callback === 'function') callback();
            }, 'json');
        }

        function loadStats() {
            $.post('', { action: 'stats' }, function(response) {
                if (response.success) {
                    $('#stat-toplam').text(response.data.toplam);
                    $('#stat-aktif').text(response.data.aktif);
                    $('#stat-pasif').text(response.data.pasif);
                    $('#stat-zorunlu').text(response.data.zorunlu);
                }
            }, 'json');
        }

        function loadBelgeTipiList() {
            $.ajax({
                url: '',
                method: 'POST',
                data: {
                    action: 'list',
                    ...currentfilters
                },
                dataType: 'json',
                success: function(data) {
                    if (data.success) {
                        renderBelgeTipiTable(data.data);
                    } else {
                        showToast('Liste yüklenirken hata oluştu', 'error');
                    }
                },
                error: function() {
                    showToast('Sunucu hatası oluştu', 'error');
                }
            });
        }

        function renderBelgeTipiTable(belgeTipleri) {
            dataTable.clear();

            belgeTipleri.forEach(bt => {
                const durumBadge = bt.belge_tipi_durum
                    ? '<span class="status-badge status-active">Aktif</span>'
                    : '<span class="status-badge status-inactive">Pasif</span>';

                const ikon = bt.belge_tipi_ikon
                    ? `<i class="${escapeHtml(bt.belge_tipi_ikon)} me-1"></i>`
                    : '';

                const aciklama = bt.belge_tipi_aciklama
                    ? `<br><small class="text-muted">${escapeHtml(bt.belge_tipi_aciklama.length > 60 ? bt.belge_tipi_aciklama.substring(0, 60) + '...' : bt.belge_tipi_aciklama)}</small>`
                    : '';

                const formatlar = (bt.belge_tipi_izinli_formatlar || '')
                    .split(',')
                    .filter(f => f.trim() !== '')
                    .map(f => `<span class="badge text-bg-secondary format-badge">${escapeHtml(f.trim().toUpperCase())}</span>`)
                    .join('') || '<span class="badge text-bg-light border">Tüm formatlar</span>';

                const gecerlilik = bt.belge_tipi_gecerlilik_ay
                    ? `<span class="badge text-bg-info">${bt.belge_tipi_gecerlilik_ay} ay</span>`
                    : '<span class="text-muted">Süresiz</span>';

                let zorunlu = bt.belge_tipi_zorunlu == 1
                    ? '<span class="badge text-bg-warning">Zorunlu</span>'
                    : '<span class="text-muted">İsteğe bağlı</span>';
                if (bt.belge_tipi_dosya_zorunlu == 0) {
                    zorunlu += '<br><span class="badge text-bg-light border"><i class="bi bi-file-earmark-x"></i> Dosyasız</span>';
                }

                const coklu = bt.belge_tipi_coklu_yukleme == 1
                    ? '<i class="bi bi-check-lg text-success"></i>'
                    : '<i class="bi bi-dash text-muted"></i>';

                let islemler = '';
                if (permissions.canEdit) {
                    islemler += `
                        <button class="btn btn-sm btn-warning" onclick="editBelgeTipi(${bt.belge_tipi_id})" title="Düzenle">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <button class="btn btn-sm btn-secondary" onclick="toggleStatus(${bt.belge_tipi_id})" title="${bt.belge_tipi_durum ? 'Pasife Al' : 'Aktif Et'}">
                            <i class="bi ${bt.belge_tipi_durum ? 'bi-toggle-on' : 'bi-toggle-off'}"></i>
                        </button>
                    `;
                }
                if (permissions.canDelete) {
                    islemler += `
                        <button class="btn btn-sm btn-danger" onclick="deleteBelgeTipi(${bt.belge_tipi_id})" title="Sil">
                            <i class="bi bi-trash"></i>
                        </button>
                    `;
                }
                if (!islemler) {
                    islemler = '<span class="text-muted">-</span>';
                }

                dataTable.row.add([
                    bt.belge_tipi_sira_no,
                    ikon + '<strong>' + escapeHtml(bt.belge_tipi_adi) + '</strong>' + aciklama,
                    bt.belge_tipi_kod ? `<code>${escapeHtml(bt.belge_tipi_kod)}</code>` : '-',
                    formatlar,
                    bt.belge_tipi_max_boyut_mb + ' MB',
                    gecerlilik,
                    zorunlu,
                    coklu,
                    durumBadge,
                    islemler
                ]);
            });

            dataTable.draw();
        }

        function resetForm() {
            document.getElementById('belgeTipiForm').reset();
            document.getElementById('belge_tipi_id').value = '';
            document.getElementById('belge_tipi_max_boyut_mb').value = 5;
            document.getElementById('belge_tipi_sira_no').value = 0;
            document.getElementById('belgeTipiModalLabel').textContent = 'Yeni Belge Tipi Ekle';
            $('#ikon_onizleme').attr('class', 'bi bi-question-circle');
            $('#belge_tipi_izinli_formatlar').val(null).trigger('change.select2');
        }

        function saveBelgeTipi() {
            const formData = new FormData(document.getElementById('belgeTipiForm'));
            formData.append('action', 'save');

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
                        belgeTipiModal.hide();
                        loadBelgeTipiList();
                        loadStats();
                    } else {
                        showToast(data.message, 'error');
                    }
                },
                error: function() {
                    showToast('Kayıt sırasında hata oluştu', 'error');
                }
            });
        }

        function editBelgeTipi(belgeTipiId) {
            $.ajax({
                url: '',
                method: 'POST',
                data: { action: 'get', belge_tipi_id: belgeTipiId },
                dataType: 'json',
                success: function(data) {
                    if (data.success && data.data) {
                        const bt = data.data;

                        document.getElementById('belge_tipi_id').value = bt.belge_tipi_id;
                        document.getElementById('belge_tipi_adi').value = bt.belge_tipi_adi || '';
                        document.getElementById('belge_tipi_kod').value = bt.belge_tipi_kod || '';
                        document.getElementById('belge_tipi_aciklama').value = bt.belge_tipi_aciklama || '';
                        document.getElementById('belge_tipi_ikon').value = bt.belge_tipi_ikon || '';
                        document.getElementById('belge_tipi_max_boyut_mb').value = bt.belge_tipi_max_boyut_mb || 5;
                        document.getElementById('belge_tipi_gecerlilik_ay').value = bt.belge_tipi_gecerlilik_ay || '';
                        document.getElementById('belge_tipi_sira_no').value = bt.belge_tipi_sira_no || 0;
                        document.getElementById('belge_tipi_zorunlu').checked = bt.belge_tipi_zorunlu == 1;
                        document.getElementById('belge_tipi_coklu_yukleme').checked = bt.belge_tipi_coklu_yukleme == 1;
                        document.getElementById('belge_tipi_dosya_zorunlu').checked = bt.belge_tipi_dosya_zorunlu != 0;
                        document.getElementById('belge_tipi_durum').checked = bt.belge_tipi_durum == 1;

                        $('#ikon_onizleme').attr('class', bt.belge_tipi_ikon || 'bi bi-question-circle');

                        const secili = (bt.belge_tipi_izinli_formatlar || '')
                            .split(',')
                            .map(f => f.trim().toLowerCase())
                            .filter(f => f !== '');
                        $('#belge_tipi_izinli_formatlar').val(secili).trigger('change.select2');

                        document.getElementById('belgeTipiModalLabel').textContent = 'Belge Tipi Düzenle';
                        belgeTipiModal.show();
                    }
                },
                error: function() {
                    showToast('Kayıt yüklenirken hata oluştu', 'error');
                }
            });
        }

        function toggleStatus(belgeTipiId) {
            $.ajax({
                url: '',
                method: 'POST',
                data: { action: 'toggle_status', belge_tipi_id: belgeTipiId },
                dataType: 'json',
                success: function(data) {
                    if (data.success) {
                        showToast(data.message, 'success');
                        loadBelgeTipiList();
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

        function deleteBelgeTipi(belgeTipiId) {
            confirmAction(
                'Bu belge tipini silmek istediğinize emin misiniz?',
                'Bu işlem geri alınamaz!',
                function() {
                    $.ajax({
                        url: '',
                        method: 'POST',
                        data: { action: 'delete', belge_tipi_id: belgeTipiId },
                        dataType: 'json',
                        success: function(data) {
                            if (data.success) {
                                showSuccess('Silindi!', data.message);
                                loadBelgeTipiList();
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
