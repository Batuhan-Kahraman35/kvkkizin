<?php
/**
 * Admin Panel - Form Yönetimi
 *
 * Firmalara ait KVKK izin formlarını oluşturur, düzenler ve listeler.
 * Form alanları tanim_FormAlanlari tablosundan gelir; forma eklenenler Form_Alanlari'nda tutulur.
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

$pageTitle = $pageinfo['sayfalar_sayfa_adi'] ?? 'Form Yönetimi';
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

// Public form bağlantısının kökü (müşterinin dolduracağı sayfa)
$formLinkKoku = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/form/';

/**
 * Tek bir formu firma kapsamı kontrolüyle getirir
 */
function formGetir($db, $firmaKapsamdaMi, $formId) {
    $form = $db->fetchOne("SELECT * FROM dbo.Formlar WHERE form_id = ?", [(int)$formId]);
    if (!$form || !$firmaKapsamdaMi($form['firma_id'])) {
        return null;
    }
    return $form;
}

// AJAX işlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {
            case 'list':
                [$scopeSql, $scopeParams] = PageAuth::firmaScopeWhere($firmaScopeIds, 'f.firma_id');
                $where = [$scopeSql];
                $params = $scopeParams;

                $search = trim($_POST['search'] ?? '');
                if ($search !== '') {
                    $where[] = "(f.form_adi LIKE ? OR fi.firma_adi LIKE ? OR f.form_uniqid LIKE ?)";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                }
                if (($_POST['firma_id'] ?? '') !== '') {
                    $where[] = "f.firma_id = ?";
                    $params[] = (int)$_POST['firma_id'];
                }
                if (($_POST['form_dil_id'] ?? '') !== '') {
                    $where[] = "f.form_dil_id = ?";
                    $params[] = (int)$_POST['form_dil_id'];
                }
                if (($_POST['status'] ?? '') !== '') {
                    $where[] = "f.form_durum = ?";
                    $params[] = (int)$_POST['status'];
                }

                $formlar = $db->fetchAll("
                    SELECT
                        f.form_id, f.firma_id, f.form_adi, f.form_uniqid, f.form_sms_onay, f.form_durum,
                        f.form_olusturma_tarihi, f.form_guncelleme_tarihi,
                        fi.firma_adi,
                        d.form_dil_adi,
                        ISNULL(k.kullanici_ad + ' ' + k.kullanici_soyad, '') AS olusturan_kullanici,
                        ISNULL(a.alan_sayisi, 0) AS alan_sayisi,
                        ISNULL(a.zorunlu_sayisi, 0) AS zorunlu_sayisi
                    FROM dbo.Formlar f
                    INNER JOIN dbo.Firmalar fi ON fi.firma_id = f.firma_id
                    LEFT JOIN dbo.tanim_FormDilleri d ON d.form_dil_id = f.form_dil_id
                    LEFT JOIN dbo.Kullanicilar k ON k.kullanici_id = f.form_olusturan_kullanici_id
                    OUTER APPLY (
                        SELECT COUNT(*) AS alan_sayisi,
                               SUM(CASE WHEN fa.form_alani_zorunlu = 1 THEN 1 ELSE 0 END) AS zorunlu_sayisi
                        FROM dbo.Form_Alanlari fa
                        WHERE fa.form_id = f.form_id AND fa.form_alani_durum = 1
                    ) a
                    WHERE " . implode(' AND ', $where) . "
                    ORDER BY f.form_id DESC
                ", $params);

                echo json_encode(['success' => true, 'data' => $formlar]);
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

            case 'tanim_list':
                $diller = $db->fetchAll("
                    SELECT form_dil_id, form_dil_kod, form_dil_adi
                    FROM dbo.tanim_FormDilleri
                    WHERE form_dil_durum = 1
                    ORDER BY form_dil_sira_no, form_dil_adi
                ");
                $alanlar = $db->fetchAll("
                    SELECT form_alan_id, form_alan_kod, form_alan_adi, form_alan_tipi, form_alan_aciklama,
                           form_alan_sabit, form_alan_varsayilan_eklensin, form_alan_varsayilan_zorunlu, form_alan_sira_no
                    FROM dbo.tanim_FormAlanlari
                    WHERE form_alan_durum = 1
                    ORDER BY form_alan_sira_no, form_alan_adi
                ");
                echo json_encode(['success' => true, 'data' => ['diller' => $diller, 'alanlar' => $alanlar]]);
                break;

            case 'get':
                $form = formGetir($db, $firmaKapsamdaMi, $_POST['form_id'] ?? 0);
                if (!$form) {
                    echo json_encode(['success' => false, 'message' => 'Form bulunamadı veya erişim yetkiniz yok']);
                    break;
                }
                $form['alanlar'] = $db->fetchAll("
                    SELECT form_alan_id, form_alani_zorunlu
                    FROM dbo.Form_Alanlari
                    WHERE form_id = ? AND form_alani_durum = 1
                ", [$form['form_id']]);
                echo json_encode(['success' => true, 'data' => $form]);
                break;

            case 'save':
                $formId = (int)($_POST['form_id'] ?? 0);

                if ($formId > 0 && !$permissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Düzenleme yetkiniz yok']);
                    break;
                }
                if ($formId === 0 && !$permissions['can_add']) {
                    echo json_encode(['success' => false, 'message' => 'Ekleme yetkiniz yok']);
                    break;
                }

                $firmaId = (int)($_POST['firma_id'] ?? 0);
                $formAdi = trim($_POST['form_adi'] ?? '');

                if ($firmaId <= 0 || !$firmaKapsamdaMi($firmaId)) {
                    echo json_encode(['success' => false, 'message' => 'Geçerli bir firma seçin']);
                    break;
                }
                if ($formAdi === '' || mb_strlen($formAdi) > 200) {
                    echo json_encode(['success' => false, 'message' => 'Form adı zorunludur (en fazla 200 karakter)']);
                    break;
                }

                $mevcut = null;
                if ($formId > 0) {
                    $mevcut = formGetir($db, $firmaKapsamdaMi, $formId);
                    if (!$mevcut) {
                        echo json_encode(['success' => false, 'message' => 'Form bulunamadı veya erişim yetkiniz yok']);
                        break;
                    }
                }

                // Alan seçimleri: tanımlı alanlar üzerinden doğrulanır
                $tanimliAlanlar = $db->fetchAll("SELECT form_alan_id, form_alan_sabit FROM dbo.tanim_FormAlanlari WHERE form_alan_durum = 1");
                $eklenenler = array_map('intval', (array)($_POST['alan_eklensin'] ?? []));
                $zorunlular = array_map('intval', (array)($_POST['alan_zorunlu'] ?? []));
                $secilenAlanlar = [];
                foreach ($tanimliAlanlar as $alan) {
                    $alanId = (int)$alan['form_alan_id'];
                    if ((int)$alan['form_alan_sabit'] === 1) {
                        $secilenAlanlar[$alanId] = 1; // sabit alan her zaman eklenir ve zorunludur
                    } elseif (in_array($alanId, $eklenenler, true)) {
                        $secilenAlanlar[$alanId] = in_array($alanId, $zorunlular, true) ? 1 : 0;
                    }
                }

                $dilId = (int)($_POST['form_dil_id'] ?? 0);
                $metin = function ($anahtar) {
                    $deger = trim($_POST[$anahtar] ?? '');
                    return $deger === '' ? null : $deger;
                };

                $veri = [
                    'firma_id'             => $firmaId,
                    'form_adi'             => $formAdi,
                    'form_dil_id'          => $dilId > 0 ? $dilId : null,
                    'form_aydinlatma_metni'=> $metin('form_aydinlatma_metni'),
                    'form_acik_riza_metni' => $metin('form_acik_riza_metni'),
                    'form_etk_metni'       => $metin('form_etk_metni'),
                    'form_etk_zorunlu'     => isset($_POST['form_etk_zorunlu']) ? 1 : 0,
                    'form_gps_metni'       => $metin('form_gps_metni'),
                    'form_gps_zorunlu'     => isset($_POST['form_gps_zorunlu']) ? 1 : 0,
                    'form_tesekkur_metni'  => $metin('form_tesekkur_metni'),
                    'form_sms_onay'        => isset($_POST['form_sms_onay']) ? 1 : 0,
                    'form_durum'           => isset($_POST['form_durum']) ? 1 : 0,
                ];

                $conn = $db->getConnection();
                sqlsrv_begin_transaction($conn);

                try {
                    if ($formId > 0) {
                        $veri['form_guncelleyen_kullanici_id'] = $user['kullanici_id'];
                        $veri['form_guncelleme_tarihi'] = date('Y-m-d H:i:s');
                        $db->update('dbo.Formlar', $veri, ['form_id' => $formId]);
                    } else {
                        $veri['form_uniqid'] = bin2hex(random_bytes(8));
                        $veri['form_olusturan_kullanici_id'] = $user['kullanici_id'];
                        $veri['form_olusturma_tarihi'] = date('Y-m-d H:i:s');
                        $formId = (int)$db->insert('dbo.Formlar', $veri);
                    }

                    // Alanlar: listeden çıkanlar silinir, kalanlar güncellenir, yeniler eklenir
                    $eskiAlanlar = $db->fetchAll("SELECT form_alan_id FROM dbo.Form_Alanlari WHERE form_id = ?", [$formId]);
                    $eskiIdler = array_map('intval', array_column($eskiAlanlar, 'form_alan_id'));

                    foreach ($eskiIdler as $eskiId) {
                        if (!isset($secilenAlanlar[$eskiId])) {
                            $db->delete('dbo.Form_Alanlari', ['form_id' => $formId, 'form_alan_id' => $eskiId]);
                        }
                    }

                    $sira = 0;
                    foreach ($secilenAlanlar as $alanId => $zorunlu) {
                        $sira++;
                        if (in_array($alanId, $eskiIdler, true)) {
                            $db->update('dbo.Form_Alanlari', [
                                'form_alani_zorunlu'                  => $zorunlu,
                                'form_alani_sira_no'                  => $sira,
                                'form_alani_durum'                    => 1,
                                'form_alani_guncelleyen_kullanici_id' => $user['kullanici_id'],
                                'form_alani_guncelleme_tarihi'        => date('Y-m-d H:i:s'),
                            ], ['form_id' => $formId, 'form_alan_id' => $alanId]);
                        } else {
                            $db->insert('dbo.Form_Alanlari', [
                                'form_id'                           => $formId,
                                'form_alan_id'                      => $alanId,
                                'form_alani_zorunlu'                => $zorunlu,
                                'form_alani_sira_no'                => $sira,
                                'form_alani_olusturan_kullanici_id' => $user['kullanici_id'],
                                'form_alani_olusturma_tarihi'       => date('Y-m-d H:i:s'),
                            ]);
                        }
                    }

                    sqlsrv_commit($conn);
                } catch (Exception $e) {
                    sqlsrv_rollback($conn);
                    throw $e;
                }

                echo json_encode([
                    'success' => true,
                    'message' => $mevcut ? 'Form güncellendi' : 'Form oluşturuldu',
                    'form_id' => $formId,
                ]);
                break;

            case 'toggle_status':
                if (!$permissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Düzenleme yetkiniz yok']);
                    break;
                }
                $form = formGetir($db, $firmaKapsamdaMi, $_POST['form_id'] ?? 0);
                if (!$form) {
                    echo json_encode(['success' => false, 'message' => 'Form bulunamadı veya erişim yetkiniz yok']);
                    break;
                }
                $yeniDurum = (int)$form['form_durum'] === 1 ? 0 : 1;
                $db->update('dbo.Formlar', [
                    'form_durum'                    => $yeniDurum,
                    'form_guncelleyen_kullanici_id' => $user['kullanici_id'],
                    'form_guncelleme_tarihi'        => date('Y-m-d H:i:s'),
                ], ['form_id' => $form['form_id']]);
                echo json_encode(['success' => true, 'message' => $yeniDurum ? 'Form aktif edildi' : 'Form pasife alındı']);
                break;

            case 'delete':
                if (!$permissions['can_delete']) {
                    echo json_encode(['success' => false, 'message' => 'Silme yetkiniz yok']);
                    break;
                }
                $form = formGetir($db, $firmaKapsamdaMi, $_POST['form_id'] ?? 0);
                if (!$form) {
                    echo json_encode(['success' => false, 'message' => 'Form bulunamadı veya erişim yetkiniz yok']);
                    break;
                }
                // Form_Alanlari ON DELETE CASCADE ile birlikte silinir
                $db->delete('dbo.Formlar', ['form_id' => $form['form_id']]);
                echo json_encode(['success' => true, 'message' => 'Form silindi']);
                break;

            case 'stats':
                [$scopeSql, $scopeParams] = PageAuth::firmaScopeWhere($firmaScopeIds, 'firma_id');
                $stats = $db->fetchOne("
                    SELECT
                        COUNT(*) AS toplam,
                        SUM(CASE WHEN form_durum = 1 THEN 1 ELSE 0 END) AS aktif,
                        SUM(CASE WHEN form_durum = 0 THEN 1 ELSE 0 END) AS pasif,
                        COUNT(DISTINCT firma_id) AS firma
                    FROM dbo.Formlar
                    WHERE $scopeSql
                ", $scopeParams);
                echo json_encode(['success' => true, 'data' => [
                    'toplam' => (int)($stats['toplam'] ?? 0),
                    'aktif'  => (int)($stats['aktif'] ?? 0),
                    'pasif'  => (int)($stats['pasif'] ?? 0),
                    'firma'  => (int)($stats['firma'] ?? 0),
                ]]);
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
        .alan-tablo td, .alan-tablo th { vertical-align: middle; }
        .alan-tablo tr.alan-kapali td:not(:first-child) { opacity: .5; }
        #qrKutu { display: inline-block; padding: 12px; background: #fff; border-radius: .5rem; }
        .form-link { font-family: monospace; font-size: .85rem; word-break: break-all; }
        .d-contents { display: contents; } /* modal-dialog-scrollable içinde form etiketi flex düzenini bozmasın */
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
                                <span class="info-box-icon"><i class="bi bi-ui-checks"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Toplam Form</span>
                                    <span class="info-box-number" id="stat-toplam">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-box text-bg-success">
                                <span class="info-box-icon"><i class="bi bi-check-circle"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Aktif Form</span>
                                    <span class="info-box-number" id="stat-aktif">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-box text-bg-danger">
                                <span class="info-box-icon"><i class="bi bi-pause-circle"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Pasif Form</span>
                                    <span class="info-box-number" id="stat-pasif">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-box text-bg-info">
                                <span class="info-box-icon"><i class="bi bi-building"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Formu Olan Firma</span>
                                    <span class="info-box-number" id="stat-firma">0</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Filtre Kartı -->
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
                                    <div class="col-md-3">
                                        <label class="form-label">Ara</label>
                                        <input type="text" class="form-control" name="search" id="filter_search" placeholder="Form adı, firma veya form kodu...">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Firma</label>
                                        <select class="form-select select2-filter" name="firma_id" id="filter_firma_id" style="width: 100%;">
                                            <option value="">Tümü</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Dil</label>
                                        <select class="form-select select2-filter" name="form_dil_id" id="filter_form_dil_id" style="width: 100%;">
                                            <option value="">Tümü</option>
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
                            <h3 class="card-title">Form Listesi</h3>
                            <div class="card-tools">
                                <?php if ($permissions['can_add']): ?>
                                <button type="button" class="btn btn-primary btn-sm" onclick="yeniForm()">
                                    <i class="bi bi-plus-circle"></i> Yeni Form
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="card-body">
                            <table id="formTable" class="table table-bordered table-striped table-hover" style="width:100%">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Firma</th>
                                        <th>Form Adı</th>
                                        <th>Dil</th>
                                        <th>Alan</th>
                                        <th>Oluşturan</th>
                                        <th>Oluşturma Tarihi</th>
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

    <!-- Form Modal -->
    <div class="modal fade" id="formModal" tabindex="-1" aria-labelledby="formModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="formModalLabel">Yeni Form</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                </div>
                <form id="formForm" class="d-contents">
                    <div class="modal-body">
                        <input type="hidden" id="form_id" name="form_id" value="">

                        <ul class="nav nav-tabs mb-3" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabGenel" type="button" role="tab">
                                    <i class="bi bi-info-circle"></i> Genel
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabAlanlar" type="button" role="tab">
                                    <i class="bi bi-list-check"></i> Form Alanları <span class="badge text-bg-secondary" id="alanSayaci">0</span>
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabMetinler" type="button" role="tab">
                                    <i class="bi bi-file-text"></i> Metinler
                                </button>
                            </li>
                        </ul>

                        <div class="tab-content">
                            <!-- Genel -->
                            <div class="tab-pane fade show active" id="tabGenel" role="tabpanel">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label for="firma_id" class="form-label">Firma <span class="text-danger">*</span></label>
                                        <select class="form-select" id="firma_id" name="firma_id" style="width: 100%;" required></select>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="form_adi" class="form-label">Form Adı <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" id="form_adi" name="form_adi" maxlength="200" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="form_dil_id" class="form-label">Form Dili</label>
                                        <select class="form-select" id="form_dil_id" name="form_dil_id" style="width: 100%;"></select>
                                    </div>
                                    <div class="col-md-6 d-flex align-items-end gap-4 flex-wrap">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" role="switch" id="form_sms_onay" name="form_sms_onay" value="1" checked>
                                            <label class="form-check-label" for="form_sms_onay">SMS ile doğrulama</label>
                                        </div>
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" role="switch" id="form_durum" name="form_durum" value="1" checked>
                                            <label class="form-check-label" for="form_durum">Aktif</label>
                                        </div>
                                    </div>
                                    <div class="col-md-12 d-none" id="formLinkKutu">
                                        <label class="form-label">Form Bağlantısı</label>
                                        <div class="input-group">
                                            <input type="text" class="form-control form-link" id="form_link" readonly>
                                            <button class="btn btn-outline-secondary" type="button" onclick="linkKopyala($('#form_link').val())" title="Kopyala">
                                                <i class="bi bi-clipboard"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Form Alanları -->
                            <div class="tab-pane fade" id="tabAlanlar" role="tabpanel">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <small class="text-muted">Forma eklenecek alanları ve zorunluluk durumlarını seçin. Kilitli alanlar her formda bulunur.</small>
                                    <div class="btn-group btn-group-sm">
                                        <button type="button" class="btn btn-outline-primary" onclick="tumAlanlar(true)">Tümünü Ekle</button>
                                        <button type="button" class="btn btn-outline-secondary" onclick="tumAlanlar(false)">Tümünü Kaldır</button>
                                    </div>
                                </div>
                                <table class="table table-sm table-hover alan-tablo mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th class="text-center" style="width: 130px;">Forma Eklensin</th>
                                            <th>Form Alanı</th>
                                            <th style="width: 120px;">Tip</th>
                                            <th class="text-center" style="width: 130px;">Zorunlu</th>
                                        </tr>
                                    </thead>
                                    <tbody id="alanListesi"></tbody>
                                </table>
                            </div>

                            <!-- Metinler -->
                            <div class="tab-pane fade" id="tabMetinler" role="tabpanel">
                                <div class="row g-3">
                                    <div class="col-md-12">
                                        <label for="form_aydinlatma_metni" class="form-label">Aydınlatma Metni</label>
                                        <textarea class="form-control" id="form_aydinlatma_metni" name="form_aydinlatma_metni" rows="5"></textarea>
                                    </div>
                                    <div class="col-md-12">
                                        <label for="form_acik_riza_metni" class="form-label">Açık Rıza Metni</label>
                                        <textarea class="form-control" id="form_acik_riza_metni" name="form_acik_riza_metni" rows="5"></textarea>
                                    </div>
                                    <div class="col-md-12">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <label for="form_etk_metni" class="form-label mb-1">ETK (Ticari Elektronik İleti) Metni</label>
                                            <div class="form-check form-switch">
                                                <input class="form-check-input" type="checkbox" role="switch" id="form_etk_zorunlu" name="form_etk_zorunlu" value="1">
                                                <label class="form-check-label" for="form_etk_zorunlu">Onay zorunlu</label>
                                            </div>
                                        </div>
                                        <textarea class="form-control" id="form_etk_metni" name="form_etk_metni" rows="4"></textarea>
                                    </div>
                                    <div class="col-md-12">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <label for="form_gps_metni" class="form-label mb-1">Konum (GPS) Metni</label>
                                            <div class="form-check form-switch">
                                                <input class="form-check-input" type="checkbox" role="switch" id="form_gps_zorunlu" name="form_gps_zorunlu" value="1">
                                                <label class="form-check-label" for="form_gps_zorunlu">Onay zorunlu</label>
                                            </div>
                                        </div>
                                        <textarea class="form-control" id="form_gps_metni" name="form_gps_metni" rows="3"></textarea>
                                    </div>
                                    <div class="col-md-12">
                                        <label for="form_tesekkur_metni" class="form-label">Teşekkür Metni</label>
                                        <textarea class="form-control" id="form_tesekkur_metni" name="form_tesekkur_metni" rows="3" placeholder="Form gönderildikten sonra gösterilir"></textarea>
                                    </div>
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

    <!-- QR Modal -->
    <div class="modal fade" id="qrModal" tabindex="-1" aria-labelledby="qrModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="qrModalLabel">QR Kod</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                </div>
                <div class="modal-body text-center">
                    <div id="qrKutu" class="border mb-3"></div>
                    <div class="input-group">
                        <input type="text" class="form-control form-link" id="qr_link" readonly>
                        <button class="btn btn-outline-secondary" type="button" onclick="linkKopyala($('#qr_link').val())" title="Kopyala">
                            <i class="bi bi-clipboard"></i>
                        </button>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
                    <button type="button" class="btn btn-success" onclick="qrIndir()">
                        <i class="bi bi-download"></i> PNG İndir
                    </button>
                </div>
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
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <script src="/Admin/assets/js/custom.js"></script>

    <script>
        let formModal, qrModal;
        let dataTable;
        let alanTanimlari = [];
        let qrDosyaAdi = 'form-qr.png';

        const formLinkKoku = <?= json_encode($formLinkKoku) ?>;

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
            const date = new Date(String(datestring).replace(' ', 'T'));
            if (isNaN(date.getTime())) return '-';
            return date.toLocaleDateString('tr-TR', {
                year: 'numeric', month: '2-digit', day: '2-digit',
                hour: '2-digit', minute: '2-digit'
            });
        }

        function formLinki(uniqid) {
            return formLinkKoku + uniqid;
        }

        $(document).ready(function() {
            formModal = new bootstrap.Modal(document.getElementById('formModal'));
            qrModal = new bootstrap.Modal(document.getElementById('qrModal'));

            dataTable = $('#formTable').DataTable({
                language: { url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/tr.json' },
                order: [[0, 'desc']],
                columnDefs: [{ orderable: false, targets: [8] }],
                pageLength: 25,
                lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "Tümü"]]
            });

            $('.select2-filter').select2({
                theme: 'bootstrap-5',
                width: '100%',
                placeholder: 'Seçiniz...',
                allowClear: true
            });

            $('#firma_id, #form_dil_id').select2({
                theme: 'bootstrap-5',
                width: '100%',
                placeholder: 'Seçiniz...',
                dropdownParent: $('#formModal')
            });

            // URL'den gelen firma filtresi (Firma Yönetimi'nden geliş)
            const urlParams = new URLSearchParams(window.location.search);
            const urlFirmaId = urlParams.get('firma_id');
            if (urlFirmaId) {
                currentfilters.firma_id = urlFirmaId;
                $('#filterCard').addClass('show');
            }

            loadFirmalar();
            loadTanimlar();
            loadFormList();
            loadStats();

            $('#formForm').on('submit', function(e) {
                e.preventDefault();
                saveForm();
            });

            // Alan satırlarında "Eklensin" kapanınca "Zorunlu" da kapanır
            $('#alanListesi').on('change', '.alan-eklensin', function() {
                const $satir = $(this).closest('tr');
                const acik = this.checked;
                $satir.toggleClass('alan-kapali', !acik);
                $satir.find('.alan-zorunlu').prop('disabled', !acik);
                if (!acik) $satir.find('.alan-zorunlu').prop('checked', false);
                alanSayaciGuncelle();
            });

            $('#filterForm').on('submit', function(e) {
                e.preventDefault();

                currentfilters = {
                    search: $('#filter_search').val(),
                    firma_id: $('#filter_firma_id').val(),
                    form_dil_id: $('#filter_form_dil_id').val(),
                    status: $('#filter_status').val()
                };

                Object.keys(currentfilters).forEach(key => {
                    if (!currentfilters[key]) delete currentfilters[key];
                });

                loadFormList();
                showToast('Filtre uygulandı', 'info');
            });

            $('#clearfilters').on('click', function() {
                $('#filterForm')[0].reset();
                $('.select2-filter').val('').trigger('change.select2');
                currentfilters = {};
                loadFormList();
                showToast('Filtreler temizlendi', 'info');
            });
        });

        function loadFirmalar() {
            $.post('', { action: 'firma_list' }, function(response) {
                if (!response.success) return;
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
            }, 'json');
        }

        function loadTanimlar() {
            $.post('', { action: 'tanim_list' }, function(response) {
                if (!response.success) return;

                const $filtre = $('#filter_form_dil_id');
                const $modal = $('#form_dil_id');
                $modal.empty();
                response.data.diller.forEach(d => {
                    $filtre.append(new Option(d.form_dil_adi, d.form_dil_id, false, false));
                    $modal.append(new Option(d.form_dil_adi, d.form_dil_id, false, false));
                });
                $filtre.trigger('change.select2');
                $modal.trigger('change.select2');

                alanTanimlari = response.data.alanlar;
            }, 'json');
        }

        function loadStats() {
            $.post('', { action: 'stats' }, function(response) {
                if (!response.success) return;
                $('#stat-toplam').text(response.data.toplam);
                $('#stat-aktif').text(response.data.aktif);
                $('#stat-pasif').text(response.data.pasif);
                $('#stat-firma').text(response.data.firma);
            }, 'json');
        }

        function loadFormList() {
            $.ajax({
                url: '',
                method: 'POST',
                data: { action: 'list', ...currentfilters },
                dataType: 'json',
                success: function(data) {
                    if (data.success) {
                        renderFormTable(data.data);
                    } else {
                        showToast(data.message || 'Liste yüklenirken hata oluştu', 'error');
                    }
                },
                error: function() {
                    showToast('Sunucu hatası oluştu', 'error');
                }
            });
        }

        function renderFormTable(formlar) {
            dataTable.clear();

            formlar.forEach(f => {
                const link = formLinki(f.form_uniqid);
                const adHucre = `
                    <div><strong>${escapeHtml(f.form_adi)}</strong></div>
                    <small class="text-muted form-link">${escapeHtml(f.form_uniqid)}</small>
                    ${f.form_sms_onay == 1 ? '<span class="badge text-bg-light border ms-1" title="SMS doğrulama"><i class="bi bi-phone"></i> SMS</span>' : ''}
                `;

                const alanHucre = `<span class="badge text-bg-primary">${f.alan_sayisi} alan</span>
                    <span class="badge text-bg-warning">${f.zorunlu_sayisi} zorunlu</span>`;

                const durumBadge = f.form_durum == 1
                    ? '<span class="status-badge status-active">Aktif</span>'
                    : '<span class="status-badge status-inactive">Pasif</span>';

                let islemler = `
                    <button class="btn btn-sm btn-info" onclick="qrAc('${escapeHtml(f.form_uniqid)}', '${escapeHtml(String(f.form_adi).replace(/'/g, ''))}')" title="QR Kod">
                        <i class="bi bi-qr-code"></i>
                    </button>
                    <button class="btn btn-sm btn-secondary" onclick="linkKopyala('${escapeHtml(link)}')" title="Bağlantıyı Kopyala">
                        <i class="bi bi-link-45deg"></i>
                    </button>
                `;
                if (permissions.canEdit) {
                    islemler += `
                        <button class="btn btn-sm btn-warning" onclick="editForm(${f.form_id})" title="Düzenle">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <button class="btn btn-sm ${f.form_durum == 1 ? 'btn-outline-danger' : 'btn-outline-success'}" onclick="toggleForm(${f.form_id})" title="${f.form_durum == 1 ? 'Pasife Al' : 'Aktif Et'}">
                            <i class="bi ${f.form_durum == 1 ? 'bi-pause-circle' : 'bi-play-circle'}"></i>
                        </button>
                    `;
                }
                if (permissions.canDelete) {
                    islemler += `
                        <button class="btn btn-sm btn-danger" onclick="deleteForm(${f.form_id})" title="Sil">
                            <i class="bi bi-trash"></i>
                        </button>
                    `;
                }

                dataTable.row.add([
                    f.form_id,
                    escapeHtml(f.firma_adi),
                    adHucre,
                    escapeHtml(f.form_dil_adi || '-'),
                    alanHucre,
                    escapeHtml(f.olusturan_kullanici || '-'),
                    formatDate(f.form_olusturma_tarihi),
                    durumBadge,
                    islemler
                ]);
            });

            dataTable.draw();
        }

        // Alan tablosunu tanımlardan çizer; secimler: {alan_id: zorunlu} (null ise varsayılanlar kullanılır)
        function alanTablosuCiz(secimler) {
            const $govde = $('#alanListesi').empty();

            alanTanimlari.forEach(a => {
                const id = a.form_alan_id;
                const sabit = a.form_alan_sabit == 1;
                let eklensin, zorunlu;

                if (sabit) {
                    eklensin = true; zorunlu = true;
                } else if (secimler === null) {
                    eklensin = a.form_alan_varsayilan_eklensin == 1;
                    zorunlu = eklensin && a.form_alan_varsayilan_zorunlu == 1;
                } else {
                    eklensin = Object.prototype.hasOwnProperty.call(secimler, id);
                    zorunlu = eklensin && secimler[id] == 1;
                }

                const kilit = sabit ? 'disabled' : '';
                // Sabit alanlar disabled olduğu için POST'a gitmez; sunucu bunları zaten ekler
                $govde.append(`
                    <tr class="${eklensin ? '' : 'alan-kapali'}">
                        <td>
                            <div class="form-check form-switch d-flex justify-content-center">
                                <input class="form-check-input alan-eklensin" type="checkbox" role="switch" id="alan_e_${id}" name="alan_eklensin[]" value="${id}" ${eklensin ? 'checked' : ''} ${kilit}>
                                <label class="form-check-label" for="alan_e_${id}"></label>
                            </div>
                        </td>
                        <td>
                            <label for="alan_e_${id}" class="mb-0"><strong>${escapeHtml(a.form_alan_adi)}</strong></label>
                            ${sabit ? '<i class="bi bi-lock-fill text-muted ms-1" title="Her formda zorunlu"></i>' : ''}
                            ${a.form_alan_aciklama ? `<br><small class="text-muted">${escapeHtml(a.form_alan_aciklama)}</small>` : ''}
                        </td>
                        <td><span class="badge text-bg-light border">${escapeHtml(a.form_alan_tipi)}</span></td>
                        <td>
                            <div class="form-check form-switch d-flex justify-content-center">
                                <input class="form-check-input alan-zorunlu" type="checkbox" role="switch" id="alan_z_${id}" name="alan_zorunlu[]" value="${id}" ${zorunlu ? 'checked' : ''} ${(sabit || !eklensin) ? 'disabled' : ''}>
                                <label class="form-check-label" for="alan_z_${id}"></label>
                            </div>
                        </td>
                    </tr>
                `);
            });

            alanSayaciGuncelle();
        }

        function alanSayaciGuncelle() {
            $('#alanSayaci').text($('#alanListesi .alan-eklensin:checked').length);
        }

        function tumAlanlar(ac) {
            $('#alanListesi .alan-eklensin:not(:disabled)').each(function() {
                $(this).prop('checked', ac).trigger('change');
            });
        }

        function formuSifirla() {
            document.getElementById('formForm').reset();
            $('#form_id').val('');
            $('#firma_id').val(currentfilters.firma_id || '').trigger('change.select2');
            $('#form_dil_id').val($('#form_dil_id option:first').val()).trigger('change.select2');
            $('#formLinkKutu').addClass('d-none');
            bootstrap.Tab.getOrCreateInstance(document.querySelector('[data-bs-target="#tabGenel"]')).show();
        }

        function yeniForm() {
            formuSifirla();
            $('#formModalLabel').text('Yeni Form');
            alanTablosuCiz(null);
            formModal.show();
        }

        function editForm(formId) {
            $.post('', { action: 'get', form_id: formId }, function(response) {
                if (!response.success) {
                    showToast(response.message || 'Form yüklenemedi', 'error');
                    return;
                }
                const f = response.data;
                formuSifirla();

                $('#form_id').val(f.form_id);
                $('#form_adi').val(f.form_adi);
                $('#firma_id').val(f.firma_id).trigger('change.select2');
                $('#form_dil_id').val(f.form_dil_id || '').trigger('change.select2');
                $('#form_sms_onay').prop('checked', f.form_sms_onay == 1);
                $('#form_durum').prop('checked', f.form_durum == 1);
                $('#form_aydinlatma_metni').val(f.form_aydinlatma_metni || '');
                $('#form_acik_riza_metni').val(f.form_acik_riza_metni || '');
                $('#form_etk_metni').val(f.form_etk_metni || '');
                $('#form_etk_zorunlu').prop('checked', f.form_etk_zorunlu == 1);
                $('#form_gps_metni').val(f.form_gps_metni || '');
                $('#form_gps_zorunlu').prop('checked', f.form_gps_zorunlu == 1);
                $('#form_tesekkur_metni').val(f.form_tesekkur_metni || '');

                $('#form_link').val(formLinki(f.form_uniqid));
                $('#formLinkKutu').removeClass('d-none');

                const secimler = {};
                (f.alanlar || []).forEach(a => { secimler[a.form_alan_id] = a.form_alani_zorunlu; });
                alanTablosuCiz(secimler);

                $('#formModalLabel').text('Form Düzenle: ' + f.form_adi);
                formModal.show();
            }, 'json').fail(function() {
                showToast('Kayıt yüklenirken hata oluştu', 'error');
            });
        }

        function saveForm() {
            if (!$('#firma_id').val() || !$('#form_adi').val().trim()) {
                bootstrap.Tab.getOrCreateInstance(document.querySelector('[data-bs-target="#tabGenel"]')).show();
                showToast('Firma ve form adı zorunludur', 'warning');
                return;
            }

            const formData = new FormData(document.getElementById('formForm'));
            formData.append('action', 'save');

            const $btn = $('#kaydetBtn');
            $btn.prop('disabled', true).html('<i class="bi bi-hourglass-split"></i> Kaydediliyor...');

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
                        formModal.hide();
                        loadFormList();
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

        function toggleForm(formId) {
            $.post('', { action: 'toggle_status', form_id: formId }, function(data) {
                if (data.success) {
                    showToast(data.message, 'success');
                    loadFormList();
                    loadStats();
                } else {
                    showToast(data.message, 'error');
                }
            }, 'json').fail(function() {
                showToast('İşlem sırasında hata oluştu', 'error');
            });
        }

        function deleteForm(formId) {
            confirmAction(
                'Bu formu silmek istediğinize emin misiniz?',
                'Form ve alan ayarları kalıcı olarak silinir. Paylaşılan bağlantı ve QR kod çalışmaz hale gelir.',
                function() {
                    $.post('', { action: 'delete', form_id: formId }, function(data) {
                        if (data.success) {
                            showSuccess('Silindi!', data.message);
                            loadFormList();
                            loadStats();
                        } else {
                            showError('Hata!', data.message);
                        }
                    }, 'json').fail(function() {
                        showError('Bağlantı Hatası!', 'Sunucuya ulaşılamıyor.');
                    });
                }
            );
        }

        function linkKopyala(link) {
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(link).then(() => showToast('Bağlantı kopyalandı', 'success'));
                return;
            }
            const $gecici = $('<textarea>').val(link).appendTo('body').select();
            document.execCommand('copy');
            $gecici.remove();
            showToast('Bağlantı kopyalandı', 'success');
        }

        function qrAc(uniqid, formAdi) {
            const link = formLinki(uniqid);
            const $kutu = $('#qrKutu').empty();
            new QRCode($kutu[0], {
                text: link,
                width: 256,
                height: 256,
                correctLevel: QRCode.CorrectLevel.H
            });
            $('#qr_link').val(link);
            $('#qrModalLabel').text('QR Kod: ' + formAdi);
            qrDosyaAdi = 'QR-' + uniqid + '.png';
            qrModal.show();
        }

        function qrIndir() {
            const canvas = $('#qrKutu canvas')[0];
            const img = $('#qrKutu img')[0];
            const kaynak = canvas ? canvas.toDataURL('image/png') : (img ? img.src : null);
            if (!kaynak) {
                showToast('QR kod oluşturulamadı', 'error');
                return;
            }
            const a = document.createElement('a');
            a.href = kaynak;
            a.download = qrDosyaAdi;
            document.body.appendChild(a);
            a.click();
            a.remove();
        }
    </script>
</body>
</html>
