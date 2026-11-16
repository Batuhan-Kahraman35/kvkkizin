<?php
/**
 * Admin Panel - SMS Operatör Yönetimi
 *
 * SMS sağlayıcılarının bağlantı bilgilerini ve uç nokta tanımlarını yönetir.
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

$pageTitle = $pageinfo['sayfalar_sayfa_adi'] ?? 'SMS Operatör Yönetimi';
$menuAdi = $pageinfo['menu_adi'] ?? null;

// Sayfa yetkilerini kontrol et
$permissions = PageAuth::checkPagePermissions($user['kullanici_id'], $user['departman_id'], $currentPagefile);

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
                $search   = $_POST['search'] ?? '';
                $status   = $_POST['status'] ?? '';
                $surucu   = $_POST['surucu'] ?? '';

                $whereConditions = ["1=1"];
                $params = [];

                if ($search) {
                    $whereConditions[] = "(o.sms_operator_adi LIKE ? OR o.sms_operator_kod LIKE ? OR o.sms_operator_host LIKE ?)";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                }
                if ($status !== '') {
                    $whereConditions[] = "o.Durum = ?";
                    $params[] = (int)$status;
                }
                if ($surucu !== '') {
                    $whereConditions[] = "o.sms_operator_surucu = ?";
                    $params[] = $surucu;
                }

                $whereClause = implode(" AND ", $whereConditions);

                $operatorler = $db->fetchAll("
                    SELECT
                        o.sms_operator_id,
                        o.sms_operator_adi,
                        o.sms_operator_kod,
                        o.sms_operator_surucu,
                        o.sms_operator_protokol,
                        o.sms_operator_host,
                        o.sms_operator_port,
                        o.sms_operator_yetki_tipi,
                        o.sms_operator_zaman_asimi,
                        o.sms_operator_vars_encoding,
                        o.sms_operator_vars_gecerlilik,
                        o.sms_operator_gonderici_zorunlu,
                        o.sms_operator_webhook_destek,
                        o.sms_operator_otp_destek,
                        o.sms_operator_uc_noktalar,
                        o.sms_operator_aciklama,
                        o.sms_operator_sira,
                        o.Durum,
                        CONVERT(VARCHAR(19), o.OlusturmaTarihi, 120) as OlusturmaTarihi,
                        (SELECT count(*) FROM SmsHesaplari h WHERE h.sms_hesap_operator_id = o.sms_operator_id AND h.Durum = 1) as hesap_sayisi
                    FROM SmsOperatorleri o
                    WHERE $whereClause
                    ORDER BY o.sms_operator_sira, o.sms_operator_adi
                ", $params);

                // Uç nokta sayısını JSON'dan hesapla
                foreach ($operatorler as &$op) {
                    $ucNoktalar = json_decode($op['sms_operator_uc_noktalar'] ?? '{}', true);
                    $op['uc_nokta_sayisi'] = is_array($ucNoktalar) ? count($ucNoktalar) : 0;
                }
                unset($op);

                echo json_encode(['success' => true, 'data' => $operatorler]);
                break;

            case 'surucu_list':
                $surucular = $db->fetchAll("
                    SELECT DISTINCT sms_operator_surucu
                    FROM SmsOperatorleri
                    WHERE sms_operator_surucu IS NOT NULL
                    ORDER BY sms_operator_surucu
                ");
                echo json_encode(['success' => true, 'data' => $surucular]);
                break;

            case 'get':
                $id = (int)($_POST['sms_operator_id'] ?? 0);
                $operator = $db->fetchOne("SELECT * FROM SmsOperatorleri WHERE sms_operator_id = ?", [$id]);
                echo json_encode(['success' => true, 'data' => $operator]);
                break;

            case 'save':
                $id = (int)($_POST['sms_operator_id'] ?? 0);

                if ($id > 0 && !$permissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Düzenleme yetkiniz yok!']);
                    break;
                }
                if ($id == 0 && !$permissions['can_add']) {
                    echo json_encode(['success' => false, 'message' => 'Ekleme yetkiniz yok!']);
                    break;
                }

                $adi = trim($_POST['sms_operator_adi'] ?? '');
                if ($adi === '') {
                    echo json_encode(['success' => false, 'message' => 'Operatör adı boş olamaz!']);
                    break;
                }

                $kod = strtolower(trim($_POST['sms_operator_kod'] ?? ''));
                if ($kod === '' || !preg_match('/^[a-z0-9_]+$/', $kod)) {
                    echo json_encode(['success' => false, 'message' => 'Kod yalnızca küçük harf, rakam ve alt çizgi içerebilir!']);
                    break;
                }

                $kodVar = $db->fetchOne(
                    "SELECT count(*) as count FROM SmsOperatorleri WHERE sms_operator_kod = ? AND sms_operator_id <> ?",
                    [$kod, $id]
                )['count'] ?? 0;
                if ($kodVar > 0) {
                    echo json_encode(['success' => false, 'message' => 'Bu operatör kodu zaten kullanılıyor!']);
                    break;
                }

                $host = trim($_POST['sms_operator_host'] ?? '');
                if ($host === '') {
                    echo json_encode(['success' => false, 'message' => 'Sunucu adresi boş olamaz!']);
                    break;
                }
                // Kullanıcı protokol veya yol yapıştırırsa temizle
                $hostPort = '';
                $host = preg_replace('#^https?://#i', '', $host);
                $host = explode('/', $host)[0];
                if (strpos($host, ':') !== false) {
                    [$host, $hostPort] = explode(':', $host, 2);
                }

                $port = trim($_POST['sms_operator_port'] ?? '');
                if ($port === '' && !empty($hostPort)) {
                    $port = $hostPort;
                }
                $port = ($port === '' ? null : (int)$port);
                if ($port !== null && ($port < 1 || $port > 65535)) {
                    echo json_encode(['success' => false, 'message' => 'Port 1-65535 aralığında olmalıdır!']);
                    break;
                }

                // Uç nokta tanımları JSON olmalı
                $ucNoktalarJson = trim($_POST['sms_operator_uc_noktalar'] ?? '');
                if ($ucNoktalarJson !== '') {
                    $cozulen = json_decode($ucNoktalarJson, true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        echo json_encode(['success' => false, 'message' => 'Uç nokta tanımı geçerli bir JSON değil: ' . json_last_error_msg()]);
                        break;
                    }
                    if (!is_array($cozulen)) {
                        echo json_encode(['success' => false, 'message' => 'Uç nokta tanımı nesne biçiminde olmalıdır!']);
                        break;
                    }
                    foreach ($cozulen as $ucKod => $tanim) {
                        if (!is_array($tanim) || empty($tanim['yol'])) {
                            echo json_encode(['success' => false, 'message' => "\"$ucKod\" uç noktasında \"yol\" alanı zorunludur!"]);
                            break 2;
                        }
                    }
                    $ucNoktalarJson = json_encode($cozulen, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                } else {
                    $ucNoktalarJson = null;
                }

                $zamanAsimi = (int)($_POST['sms_operator_zaman_asimi'] ?? 30);
                if ($zamanAsimi < 5 || $zamanAsimi > 300) {
                    echo json_encode(['success' => false, 'message' => 'Zaman aşımı 5-300 saniye aralığında olmalıdır!']);
                    break;
                }

                $data = [
                    'sms_operator_adi'               => $adi,
                    'sms_operator_kod'               => $kod,
                    'sms_operator_surucu'            => strtolower(trim($_POST['sms_operator_surucu'] ?? '')) ?: $kod,
                    'sms_operator_protokol'          => in_array($_POST['sms_operator_protokol'] ?? 'http', ['http', 'https'], true) ? $_POST['sms_operator_protokol'] : 'http',
                    'sms_operator_host'              => $host,
                    'sms_operator_port'              => $port,
                    'sms_operator_yetki_tipi'        => trim($_POST['sms_operator_yetki_tipi'] ?? 'basic') ?: 'basic',
                    'sms_operator_icerik_tipi'       => trim($_POST['sms_operator_icerik_tipi'] ?? '') ?: 'application/json',
                    'sms_operator_zaman_asimi'       => $zamanAsimi,
                    'sms_operator_vars_encoding'     => (int)($_POST['sms_operator_vars_encoding'] ?? 1),
                    'sms_operator_vars_gecerlilik'   => (int)($_POST['sms_operator_vars_gecerlilik'] ?? 60),
                    'sms_operator_gonderici_zorunlu' => isset($_POST['sms_operator_gonderici_zorunlu']) ? 1 : 0,
                    'sms_operator_webhook_destek'    => isset($_POST['sms_operator_webhook_destek']) ? 1 : 0,
                    'sms_operator_otp_destek'        => isset($_POST['sms_operator_otp_destek']) ? 1 : 0,
                    'sms_operator_uc_noktalar'       => $ucNoktalarJson,
                    'sms_operator_aciklama'          => trim($_POST['sms_operator_aciklama'] ?? '') ?: null,
                    'sms_operator_sira'              => (int)($_POST['sms_operator_sira'] ?? 0),
                    'Durum'                          => isset($_POST['Durum']) ? 1 : 0,
                ];

                if ($id > 0) {
                    $data['GuncellemeTarihi'] = date('Y-m-d H:i:s');
                    $data['GuncelleyenKullanici'] = $user['kullanici_id'];

                    $result = $db->update('SmsOperatorleri', $data, ['sms_operator_id' => $id]);
                    echo json_encode(['success' => $result, 'message' => $result ? 'Operatör başarıyla güncellendi' : 'Güncelleme hatası']);
                } else {
                    $data['OlusturmaTarihi'] = date('Y-m-d H:i:s');
                    $data['OlusturanKullanici'] = $user['kullanici_id'];

                    $result = $db->insert('SmsOperatorleri', $data);
                    echo json_encode(['success' => (bool)$result, 'message' => $result ? 'Operatör başarıyla eklendi' : 'Ekleme hatası']);
                }
                break;

            case 'toggle_status':
                if (!$permissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Düzenleme yetkiniz yok!']);
                    break;
                }

                $id = (int)($_POST['sms_operator_id'] ?? 0);
                $mevcut = $db->fetchOne("SELECT Durum FROM SmsOperatorleri WHERE sms_operator_id = ?", [$id]);
                if (!$mevcut) {
                    echo json_encode(['success' => false, 'message' => 'Operatör bulunamadı!']);
                    break;
                }

                $yeniDurum = $mevcut['Durum'] ? 0 : 1;
                $db->update('SmsOperatorleri', [
                    'Durum' => $yeniDurum,
                    'GuncellemeTarihi' => date('Y-m-d H:i:s'),
                    'GuncelleyenKullanici' => $user['kullanici_id'],
                ], ['sms_operator_id' => $id]);

                echo json_encode(['success' => true, 'message' => $yeniDurum ? 'Operatör aktif edildi' : 'Operatör pasife alındı']);
                break;

            case 'delete':
                if (!$permissions['can_delete']) {
                    echo json_encode(['success' => false, 'message' => 'Silme yetkiniz yok!']);
                    break;
                }

                $id = (int)($_POST['sms_operator_id'] ?? 0);

                $hesapSayisi = $db->fetchOne(
                    "SELECT count(*) as count FROM SmsHesaplari WHERE sms_hesap_operator_id = ?",
                    [$id]
                )['count'] ?? 0;

                if ($hesapSayisi > 0) {
                    echo json_encode([
                        'success' => false,
                        'message' => "Bu operatör silinemez! $hesapSayisi SMS hesabında kullanılıyor."
                    ]);
                    break;
                }

                $result = $db->delete('SmsOperatorleri', ['sms_operator_id' => $id]);
                echo json_encode(['success' => true, 'message' => $result ? 'Operatör başarıyla silindi' : 'Silme hatası']);
                break;

            case 'stats':
                $stats = [
                    'toplam' => $db->fetchOne("SELECT count(*) as count FROM SmsOperatorleri")['count'] ?? 0,
                    'aktif'  => $db->fetchOne("SELECT count(*) as count FROM SmsOperatorleri WHERE Durum = 1")['count'] ?? 0,
                    'pasif'  => $db->fetchOne("SELECT count(*) as count FROM SmsOperatorleri WHERE Durum = 0")['count'] ?? 0,
                    'hesap'  => $db->fetchOne("SELECT count(*) as count FROM SmsHesaplari WHERE Durum = 1")['count'] ?? 0,
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
        .status-badge { padding: 0.25rem 0.5rem; border-radius: 0.25rem; font-size: 0.875rem; }
        .status-active { background-color: #d4edda; color: #155724; }
        .status-inactive { background-color: #f8d7da; color: #721c24; }
        #sms_operator_uc_noktalar { font-family: Consolas, Monaco, monospace; font-size: 0.8rem; }
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
                                <span class="info-box-icon"><i class="bi bi-hdd-network"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Toplam Operatör</span>
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
                            <div class="info-box text-bg-info">
                                <span class="info-box-icon"><i class="bi bi-person-badge"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Tanımlı Hesap</span>
                                    <span class="info-box-number" id="stat-hesap">0</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Filtre Karti -->
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
                                    <div class="col-md-5">
                                        <label class="form-label">Ara</label>
                                        <input type="text" class="form-control" name="search" id="filter_search" placeholder="Operatör adı, kodu veya sunucu adresi...">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Sürücü</label>
                                        <select class="form-select select2-filter" name="surucu" id="filter_surucu" style="width: 100%;">
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
                                        <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Filtrele</button>
                                        <button type="button" class="btn btn-secondary" id="clearfilters"><i class="bi bi-x-circle"></i> Temizle</button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Ana içerik -->
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">Operatör Listesi</h3>
                            <div class="card-tools">
                                <?php if ($permissions['can_add']): ?>
                                <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#operatorModal" onclick="resetForm()">
                                    <i class="bi bi-plus-circle"></i> Yeni Operatör Ekle
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="card-body">
                            <table id="operatorTable" class="table table-bordered table-striped table-hover">
                                <thead>
                                    <tr>
                                        <th>Sıra</th>
                                        <th>Operatör</th>
                                        <th>Kod</th>
                                        <th>Sunucu</th>
                                        <th>Sürücü</th>
                                        <th>Uç Nokta</th>
                                        <th>Hesap</th>
                                        <th>Özellikler</th>
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

    <!-- Operatör Modal -->
    <div class="modal fade" id="operatorModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="operatorModalLabel">Yeni Operatör Ekle</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                </div>
                <form id="operatorForm">
                    <div class="modal-body">
                        <input type="hidden" id="sms_operator_id" name="sms_operator_id" value="">

                        <ul class="nav nav-tabs mb-3" role="tablist">
                            <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-genel" type="button">Genel</button></li>
                            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-ucnokta" type="button">Uç Noktalar</button></li>
                        </ul>

                        <div class="tab-content">
                            <div class="tab-pane fade show active" id="tab-genel">
                                <div class="row g-3">
                                    <div class="col-md-5">
                                        <label for="sms_operator_adi" class="form-label">Operatör Adı <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" id="sms_operator_adi" name="sms_operator_adi" required maxlength="100">
                                    </div>
                                    <div class="col-md-3">
                                        <label for="sms_operator_kod" class="form-label">Kod <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" id="sms_operator_kod" name="sms_operator_kod" required maxlength="50" placeholder="ekomesaj">
                                        <small class="text-muted">Benzersiz, küçük harf</small>
                                    </div>
                                    <div class="col-md-4">
                                        <label for="sms_operator_surucu" class="form-label">Sürücü</label>
                                        <input type="text" class="form-control" id="sms_operator_surucu" name="sms_operator_surucu" maxlength="50" placeholder="panel9587">
                                        <small class="text-muted">Aynı API yapısını paylaşan operatörler aynı sürücüyü kullanır</small>
                                    </div>

                                    <div class="col-md-2">
                                        <label for="sms_operator_protokol" class="form-label">Protokol</label>
                                        <select class="form-select" id="sms_operator_protokol" name="sms_operator_protokol" style="width: 100%;">
                                            <option value="http">http</option>
                                            <option value="https">https</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="sms_operator_host" class="form-label">Sunucu Adresi <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" id="sms_operator_host" name="sms_operator_host" required maxlength="255" placeholder="panel.iletibilgi.com.tr">
                                    </div>
                                    <div class="col-md-2">
                                        <label for="sms_operator_port" class="form-label">Port</label>
                                        <input type="number" class="form-control" id="sms_operator_port" name="sms_operator_port" min="1" max="65535" placeholder="9587">
                                    </div>
                                    <div class="col-md-2">
                                        <label for="sms_operator_zaman_asimi" class="form-label">Zaman Aşımı (sn)</label>
                                        <input type="number" class="form-control" id="sms_operator_zaman_asimi" name="sms_operator_zaman_asimi" min="5" max="300" value="30">
                                    </div>

                                    <div class="col-md-3">
                                        <label for="sms_operator_yetki_tipi" class="form-label">Yetkilendirme</label>
                                        <select class="form-select select2-modal" id="sms_operator_yetki_tipi" name="sms_operator_yetki_tipi" style="width: 100%;">
                                            <option value="basic">Basic Auth</option>
                                            <option value="bearer">Bearer Token</option>
                                            <option value="body">Gövde İçinde</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label for="sms_operator_icerik_tipi" class="form-label">İçerik Tipi</label>
                                        <input type="text" class="form-control" id="sms_operator_icerik_tipi" name="sms_operator_icerik_tipi" maxlength="50" value="application/json">
                                    </div>
                                    <div class="col-md-2">
                                        <label for="sms_operator_vars_encoding" class="form-label">Varsayılan Encoding</label>
                                        <select class="form-select select2-modal" id="sms_operator_vars_encoding" name="sms_operator_vars_encoding" style="width: 100%;">
                                            <option value="0">0 - GSM</option>
                                            <option value="1" selected>1 - Türkçe</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label for="sms_operator_vars_gecerlilik" class="form-label">Geçerlilik (dk)</label>
                                        <input type="number" class="form-control" id="sms_operator_vars_gecerlilik" name="sms_operator_vars_gecerlilik" min="1" max="10080" value="60">
                                    </div>
                                    <div class="col-md-2">
                                        <label for="sms_operator_sira" class="form-label">Sıra No</label>
                                        <input type="number" class="form-control" id="sms_operator_sira" name="sms_operator_sira" min="0" value="0">
                                    </div>

                                    <div class="col-md-12">
                                        <label for="sms_operator_aciklama" class="form-label">Açıklama</label>
                                        <textarea class="form-control" id="sms_operator_aciklama" name="sms_operator_aciklama" rows="2" maxlength="500"></textarea>
                                    </div>

                                    <div class="col-md-3">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" role="switch" id="sms_operator_gonderici_zorunlu" name="sms_operator_gonderici_zorunlu" value="1" checked>
                                            <label class="form-check-label" for="sms_operator_gonderici_zorunlu">Gönderici Başlığı Zorunlu</label>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" role="switch" id="sms_operator_webhook_destek" name="sms_operator_webhook_destek" value="1" checked>
                                            <label class="form-check-label" for="sms_operator_webhook_destek">Webhook Desteği</label>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" role="switch" id="sms_operator_otp_destek" name="sms_operator_otp_destek" value="1" checked>
                                            <label class="form-check-label" for="sms_operator_otp_destek">OTP Desteği</label>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" role="switch" id="Durum" name="Durum" value="1" checked>
                                            <label class="form-check-label" for="Durum">Aktif</label>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="tab-pane fade" id="tab-ucnokta">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <label for="sms_operator_uc_noktalar" class="form-label mb-0">Uç Nokta Tanımları (JSON)</label>
                                    <div>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" id="btnJsonBicimle">
                                            <i class="bi bi-braces"></i> Biçimlendir
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-primary" id="btnJsonSablon">
                                            <i class="bi bi-clipboard-plus"></i> Örnek Şablon
                                        </button>
                                    </div>
                                </div>
                                <textarea class="form-control" id="sms_operator_uc_noktalar" name="sms_operator_uc_noktalar" rows="18" spellcheck="false"></textarea>
                                <small class="text-muted d-block mt-2">
                                    Her uç nokta için <code>yol</code> zorunludur; <code>metot</code> (varsayılan POST), <code>adi</code> ve <code>govde</code> isteğe bağlıdır.
                                    Gövde içinde <code>{numara}</code>, <code>{icerik}</code>, <code>{baslik}</code>, <code>{gonderici}</code>, <code>{paket_id}</code> yer tutucuları kullanılabilir.
                                    Bilinen kodlar: <code>kredi</code>, <code>gonderici_liste</code>, <code>otp_gonder</code>, <code>gonder</code>, <code>iptal</code>, <code>paket_liste</code>, <code>paket_detay</code>
                                </small>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="bi bi-x-circle"></i> İptal</button>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Kaydet</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Uç Nokta Görüntüleme Modal -->
    <div class="modal fade" id="ucNoktaModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="ucNoktaModalLabel">Uç Noktalar</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                </div>
                <div class="modal-body">
                    <table class="table table-sm table-bordered mb-0">
                        <thead>
                            <tr><th>Kod</th><th>Adı</th><th>Metot</th><th>Yol</th></tr>
                        </thead>
                        <tbody id="ucNoktaListe"></tbody>
                    </table>
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
    <script src="/Admin/assets/js/custom.js"></script>

    <script>
        let operatorModal, ucNoktaModal, dataTable;
        let currentfilters = {};
        let operatorListesi = [];

        const permissions = {
            canAdd: <?= $permissions['can_add'] ? 'true' : 'false' ?>,
            canEdit: <?= $permissions['can_edit'] ? 'true' : 'false' ?>,
            canDelete: <?= $permissions['can_delete'] ? 'true' : 'false' ?>
        };

        const ORNEK_SABLON = {
            "kredi": { "adi": "Kredi Sorgulama", "metot": "GET", "yol": "/user/credit" },
            "gonderici_liste": { "adi": "Gönderici Başlıkları", "metot": "POST", "yol": "/sms/list-sender", "govde": { "keyword": null, "status": null, "pageIndex": null, "pageSize": null } },
            "otp_gonder": { "adi": "OTP Gönderimi", "metot": "POST", "yol": "/sms/create-otp", "govde": { "number": "{numara}", "sender": "{gonderici}", "content": "{icerik}", "encoding": 1 } },
            "gonder": { "adi": "SMS Gönderimi", "metot": "POST", "yol": "/sms/create", "govde": { "type": 1, "sendingType": 0, "title": "{baslik}", "content": "{icerik}", "number": "{numara}", "encoding": 1, "sender": "{gonderici}", "periodicSettings": null, "sendingDate": null, "validity": 60, "pushSettings": null } },
            "iptal": { "adi": "Paket İptali", "metot": "POST", "yol": "/sms/cancel", "govde": { "id": "{paket_id}" } },
            "paket_liste": { "adi": "Paket Listesi", "metot": "POST", "yol": "/sms/list", "govde": { "ids": null, "status": null, "keyword": null, "sender": null, "pageIndex": 0, "pageSize": 100 } },
            "paket_detay": { "adi": "Paket İçeriği", "metot": "POST", "yol": "/sms/list-item", "govde": { "pkgID": "{paket_id}", "uuid": null, "target": null, "operator": null, "state": null, "startDate": null, "finishDate": null, "pageIndex": 0, "pageSize": 100 } }
        };

        function escapeHtml(text) {
            if (text === null || text === undefined) return '';
            return String(text)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
        }

        $(document).ready(function() {
            operatorModal = new bootstrap.Modal(document.getElementById('operatorModal'));
            ucNoktaModal = new bootstrap.Modal(document.getElementById('ucNoktaModal'));

            dataTable = $('#operatorTable').DataTable({
                language: { url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/tr.json' },
                order: [[0, 'asc']],
                columnDefs: [{ orderable: false, targets: [7, 9] }],
                pageLength: 25,
                lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "Tümü"]]
            });

            $('.select2-filter').select2({ theme: 'bootstrap-5', width: '100%', placeholder: 'Seçiniz...', allowClear: true });
            $('.select2-modal, #sms_operator_protokol').select2({
                theme: 'bootstrap-5', width: '100%', minimumResultsForSearch: 0,
                dropdownParent: $('#operatorModal')
            });

            $('#sms_operator_kod').on('input', function() {
                this.value = this.value.toLowerCase().replace(/[^a-z0-9_]/g, '');
            });

            $('#btnJsonBicimle').on('click', function() {
                const el = document.getElementById('sms_operator_uc_noktalar');
                if (!el.value.trim()) return;
                try {
                    el.value = JSON.stringify(JSON.parse(el.value), null, 4);
                    showToast('JSON biçimlendirildi', 'success');
                } catch (e) {
                    showToast('Geçersiz JSON: ' + e.message, 'error');
                }
            });

            $('#btnJsonSablon').on('click', function() {
                document.getElementById('sms_operator_uc_noktalar').value = JSON.stringify(ORNEK_SABLON, null, 4);
                showToast('Örnek şablon dolduruldu', 'info');
            });

            loadSurucular();
            loadOperatorList();
            loadStats();

            $('#operatorForm').on('submit', function(e) {
                e.preventDefault();
                saveOperator();
            });

            $('#filterForm').on('submit', function(e) {
                e.preventDefault();
                currentfilters = {
                    search: $('#filter_search').val(),
                    surucu: $('#filter_surucu').val(),
                    status: $('#filter_status').val()
                };
                Object.keys(currentfilters).forEach(k => { if (!currentfilters[k]) delete currentfilters[k]; });
                loadOperatorList();
                showToast('Filtre uygulandı', 'info');
            });

            $('#clearfilters').on('click', function() {
                $('#filterForm')[0].reset();
                $('#filter_surucu, #filter_status').val('').trigger('change.select2');
                currentfilters = {};
                loadOperatorList();
                showToast('Filtreler temizlendi', 'info');
            });
        });

        function loadSurucular() {
            $.post('', { action: 'surucu_list' }, function(response) {
                if (response.success) {
                    const $f = $('#filter_surucu');
                    response.data.forEach(s => $f.append(new Option(s.sms_operator_surucu, s.sms_operator_surucu, false, false)));
                    $f.trigger('change.select2');
                }
            }, 'json');
        }

        function loadStats() {
            $.post('', { action: 'stats' }, function(response) {
                if (response.success) {
                    $('#stat-toplam').text(response.data.toplam);
                    $('#stat-aktif').text(response.data.aktif);
                    $('#stat-pasif').text(response.data.pasif);
                    $('#stat-hesap').text(response.data.hesap);
                }
            }, 'json');
        }

        function loadOperatorList() {
            $.ajax({
                url: '', method: 'POST', dataType: 'json',
                data: { action: 'list', ...currentfilters },
                success: function(data) {
                    if (data.success) {
                        operatorListesi = data.data;
                        renderTable(data.data);
                    } else {
                        showToast('Liste yüklenirken hata oluştu', 'error');
                    }
                },
                error: function() { showToast('Sunucu hatası oluştu', 'error'); }
            });
        }

        function renderTable(operatorler) {
            dataTable.clear();

            operatorler.forEach(o => {
                const durumBadge = o.Durum
                    ? '<span class="status-badge status-active">Aktif</span>'
                    : '<span class="status-badge status-inactive">Pasif</span>';

                const aciklama = o.sms_operator_aciklama
                    ? `<br><small class="text-muted">${escapeHtml(o.sms_operator_aciklama.length > 60 ? o.sms_operator_aciklama.substring(0, 60) + '...' : o.sms_operator_aciklama)}</small>`
                    : '';

                const sunucu = `${escapeHtml(o.sms_operator_protokol)}://${escapeHtml(o.sms_operator_host)}` +
                    (o.sms_operator_port ? ':' + o.sms_operator_port : '');

                const ozellikler = [
                    o.sms_operator_otp_destek ? '<span class="badge text-bg-info me-1">OTP</span>' : '',
                    o.sms_operator_webhook_destek ? '<span class="badge text-bg-secondary me-1">Webhook</span>' : '',
                    o.sms_operator_gonderici_zorunlu ? '<span class="badge text-bg-warning">Başlık</span>' : ''
                ].join('') || '<span class="text-muted">-</span>';

                const ucNokta = o.uc_nokta_sayisi > 0
                    ? `<a href="#" onclick="showUcNoktalar(${o.sms_operator_id}); return false;" class="badge text-bg-primary text-decoration-none">${o.uc_nokta_sayisi} tanım</a>`
                    : '<span class="badge text-bg-danger">Tanımsız</span>';

                let islemler = '';
                if (permissions.canEdit) {
                    islemler += `
                        <button class="btn btn-sm btn-warning" onclick="editOperator(${o.sms_operator_id})" title="Düzenle"><i class="bi bi-pencil"></i></button>
                        <button class="btn btn-sm btn-secondary" onclick="toggleStatus(${o.sms_operator_id})" title="${o.Durum ? 'Pasife Al' : 'Aktif Et'}"><i class="bi ${o.Durum ? 'bi-toggle-on' : 'bi-toggle-off'}"></i></button>`;
                }
                if (permissions.canDelete) {
                    islemler += `<button class="btn btn-sm btn-danger" onclick="deleteOperator(${o.sms_operator_id})" title="Sil"><i class="bi bi-trash"></i></button>`;
                }
                if (!islemler) islemler = '<span class="text-muted">-</span>';

                dataTable.row.add([
                    o.sms_operator_sira,
                    '<strong>' + escapeHtml(o.sms_operator_adi) + '</strong>' + aciklama,
                    `<code>${escapeHtml(o.sms_operator_kod)}</code>`,
                    `<small>${sunucu}</small>`,
                    `<span class="badge text-bg-light">${escapeHtml(o.sms_operator_surucu)}</span>`,
                    ucNokta,
                    o.hesap_sayisi > 0 ? `<span class="badge text-bg-success">${o.hesap_sayisi}</span>` : '<span class="text-muted">0</span>',
                    ozellikler,
                    durumBadge,
                    islemler
                ]);
            });

            dataTable.draw();
        }

        function showUcNoktalar(id) {
            const op = operatorListesi.find(o => o.sms_operator_id == id);
            if (!op) return;

            let tanimlar = {};
            try { tanimlar = JSON.parse(op.sms_operator_uc_noktalar || '{}'); } catch (e) {}

            const satirlar = Object.keys(tanimlar).map(kod => {
                const t = tanimlar[kod] || {};
                return `<tr>
                    <td><code>${escapeHtml(kod)}</code></td>
                    <td>${escapeHtml(t.adi || '-')}</td>
                    <td><span class="badge text-bg-secondary">${escapeHtml(t.metot || 'POST')}</span></td>
                    <td><small>${escapeHtml(t.yol || '')}</small></td>
                </tr>`;
            }).join('') || '<tr><td colspan="4" class="text-center text-muted">Tanım yok</td></tr>';

            $('#ucNoktaModalLabel').text(op.sms_operator_adi + ' - Uç Noktalar');
            $('#ucNoktaListe').html(satirlar);
            ucNoktaModal.show();
        }

        function resetForm() {
            document.getElementById('operatorForm').reset();
            document.getElementById('sms_operator_id').value = '';
            document.getElementById('sms_operator_uc_noktalar').value = JSON.stringify(ORNEK_SABLON, null, 4);
            document.getElementById('operatorModalLabel').textContent = 'Yeni Operatör Ekle';
            $('#sms_operator_protokol').val('http').trigger('change.select2');
            $('#sms_operator_yetki_tipi').val('basic').trigger('change.select2');
            $('#sms_operator_vars_encoding').val('1').trigger('change.select2');
        }

        function saveOperator() {
            const formData = new FormData(document.getElementById('operatorForm'));
            formData.append('action', 'save');

            $.ajax({
                url: '', method: 'POST', data: formData,
                processData: false, contentType: false, dataType: 'json',
                success: function(data) {
                    if (data.success) {
                        showToast(data.message, 'success');
                        operatorModal.hide();
                        loadOperatorList();
                        loadStats();
                    } else {
                        showToast(data.message, 'error');
                    }
                },
                error: function() { showToast('Kayıt sırasında hata oluştu', 'error'); }
            });
        }

        function editOperator(id) {
            $.ajax({
                url: '', method: 'POST', dataType: 'json',
                data: { action: 'get', sms_operator_id: id },
                success: function(data) {
                    if (data.success && data.data) {
                        const o = data.data;

                        document.getElementById('sms_operator_id').value = o.sms_operator_id;
                        document.getElementById('sms_operator_adi').value = o.sms_operator_adi || '';
                        document.getElementById('sms_operator_kod').value = o.sms_operator_kod || '';
                        document.getElementById('sms_operator_surucu').value = o.sms_operator_surucu || '';
                        document.getElementById('sms_operator_host').value = o.sms_operator_host || '';
                        document.getElementById('sms_operator_port').value = o.sms_operator_port || '';
                        document.getElementById('sms_operator_icerik_tipi').value = o.sms_operator_icerik_tipi || 'application/json';
                        document.getElementById('sms_operator_zaman_asimi').value = o.sms_operator_zaman_asimi || 30;
                        document.getElementById('sms_operator_vars_gecerlilik').value = o.sms_operator_vars_gecerlilik || 60;
                        document.getElementById('sms_operator_sira').value = o.sms_operator_sira || 0;
                        document.getElementById('sms_operator_aciklama').value = o.sms_operator_aciklama || '';

                        document.getElementById('sms_operator_gonderici_zorunlu').checked = o.sms_operator_gonderici_zorunlu == 1;
                        document.getElementById('sms_operator_webhook_destek').checked = o.sms_operator_webhook_destek == 1;
                        document.getElementById('sms_operator_otp_destek').checked = o.sms_operator_otp_destek == 1;
                        document.getElementById('Durum').checked = o.Durum == 1;

                        $('#sms_operator_protokol').val(o.sms_operator_protokol || 'http').trigger('change.select2');
                        $('#sms_operator_yetki_tipi').val(o.sms_operator_yetki_tipi || 'basic').trigger('change.select2');
                        $('#sms_operator_vars_encoding').val(String(o.sms_operator_vars_encoding ?? 1)).trigger('change.select2');

                        let json = o.sms_operator_uc_noktalar || '';
                        try { json = JSON.stringify(JSON.parse(json), null, 4); } catch (e) {}
                        document.getElementById('sms_operator_uc_noktalar').value = json;

                        document.getElementById('operatorModalLabel').textContent = 'Operatör Düzenle';
                        operatorModal.show();
                    }
                },
                error: function() { showToast('Kayıt yüklenirken hata oluştu', 'error'); }
            });
        }

        function toggleStatus(id) {
            $.ajax({
                url: '', method: 'POST', dataType: 'json',
                data: { action: 'toggle_status', sms_operator_id: id },
                success: function(data) {
                    if (data.success) {
                        showToast(data.message, 'success');
                        loadOperatorList();
                        loadStats();
                    } else {
                        showToast(data.message, 'error');
                    }
                },
                error: function() { showToast('İşlem sırasında hata oluştu', 'error'); }
            });
        }

        function deleteOperator(id) {
            confirmAction(
                'Bu operatörü silmek istediğinize emin misiniz?',
                'Bu işlem geri alınamaz!',
                function() {
                    $.ajax({
                        url: '', method: 'POST', dataType: 'json',
                        data: { action: 'delete', sms_operator_id: id },
                        success: function(data) {
                            if (data.success) {
                                showSuccess('Silindi!', data.message);
                                loadOperatorList();
                                loadStats();
                            } else {
                                showError('Hata!', data.message);
                            }
                        },
                        error: function() { showError('Bağlantı Hatası!', 'Sunucuya ulaşılamıyor.'); }
                    });
                }
            );
        }
    </script>
</body>
</html>
