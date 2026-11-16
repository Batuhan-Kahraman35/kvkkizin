<?php
/**
 * Admin Panel - Bildirim Gönder
 * Manuel bildirim gönderimi (kullanıcı / departman / herkes) + gönderim geçmişi
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
require_once __DIR__ . '/../includes/Bildirim.php';
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

$pageTitle = $pageinfo['sayfalar_sayfa_adi'] ?? 'Bildirim Gönder';
$pageDescription = $pageinfo['sayfalar_aciklama'] ?? '';
$menuAdi = $pageinfo['menu_adi'] ?? null;

// Sayfa yetkilerini kontrol et
$permissions = PageAuth::checkPagePermissions($user['kullanici_id'], $user['departman_id'], $currentPagefile);

if (!$permissions['has_access']) {
    PageAuth::accessDenied($permissions['error'] ?? 'Bu sayfaya erişim Yetkiniz yok!');
}

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

// AJAX işlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {
            case 'stats':
                $toplam = $db->fetchOne("SELECT COUNT(*) AS a FROM Bildirimler WHERE Durum = 1")['a'] ?? 0;
                $bugun = $db->fetchOne("SELECT COUNT(*) AS a FROM Bildirimler WHERE Durum = 1 AND OlusturmaTarihi >= CAST(GETDATE() AS DATE)")['a'] ?? 0;
                $push = $db->fetchOne("SELECT COUNT(*) AS a FROM Bildirimler WHERE Durum = 1 AND bildirim_push_gonderildi = 1")['a'] ?? 0;
                $okunmamis = $db->fetchOne("SELECT COUNT(*) AS a FROM Bildirimler WHERE Durum = 1 AND bildirim_okundu = 0")['a'] ?? 0;
                echo json_encode(['success' => true, 'data' => [
                    'toplam' => $toplam, 'bugun' => $bugun, 'push' => $push, 'okunmamis' => $okunmamis,
                ]]);
                break;

            case 'list':
                $search = $_POST['search'] ?? '';
                $tip    = $_POST['tip'] ?? '';
                $hedef  = $_POST['hedef'] ?? '';
                $push   = $_POST['push'] ?? '';

                $whereConditions = ["b.Durum = 1"];
                $params = [];

                if ($search) {
                    $whereConditions[] = "(b.bildirim_baslik LIKE ? OR b.bildirim_govde LIKE ?)";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                }
                if ($tip !== '') {
                    $whereConditions[] = "b.bildirim_tip = ?";
                    $params[] = $tip;
                }
                if ($hedef === 'genel') {
                    $whereConditions[] = "b.bildirim_kullanici_id IS NULL";
                } elseif ($hedef === 'kullanici') {
                    $whereConditions[] = "b.bildirim_kullanici_id IS NOT NULL";
                }
                if ($push !== '') {
                    $whereConditions[] = "b.bildirim_push_gonderildi = ?";
                    $params[] = $push;
                }

                $whereClause = implode(" AND ", $whereConditions);

                $liste = $db->fetchAll("
                    SELECT TOP 500
                        b.bildirim_id,
                        b.bildirim_baslik,
                        b.bildirim_govde,
                        b.bildirim_url,
                        b.bildirim_tip,
                        b.bildirim_okundu,
                        b.bildirim_push_gonderildi,
                        CONVERT(VARCHAR(19), b.OlusturmaTarihi, 120) AS olusturma_tarihi,
                        CASE WHEN b.bildirim_kullanici_id IS NULL THEN N'Herkes'
                             ELSE ISNULL(k.kullanici_ad + ' ' + k.kullanici_soyad, N'Silinmiş kullanıcı') END AS hedef_kullanici,
                        ISNULL(g.kullanici_ad + ' ' + g.kullanici_soyad, '-') AS gonderen
                    FROM Bildirimler b
                    LEFT JOIN kullanicilar k ON b.bildirim_kullanici_id = k.kullanici_id
                    LEFT JOIN kullanicilar g ON b.OlusturanKullanici = g.kullanici_id
                    WHERE $whereClause
                    ORDER BY b.bildirim_id DESC
                ", $params);
                echo json_encode(['success' => true, 'data' => $liste], JSON_UNESCAPED_UNICODE);
                break;

            case 'kullanici_listesi':
                $liste = $db->fetchAll("
                    SELECT kullanici_id, kullanici_ad + ' ' + kullanici_soyad AS ad_soyad
                    FROM kullanicilar
                    WHERE kullanici_durum = 1
                    ORDER BY kullanici_ad, kullanici_soyad
                ");
                echo json_encode(['success' => true, 'data' => $liste], JSON_UNESCAPED_UNICODE);
                break;

            case 'departman_listesi':
                $liste = $db->fetchAll("
                    SELECT departman_id, departman_adi
                    FROM kullanici_Departmanlar
                    WHERE departman_durum = 1
                    ORDER BY departman_adi
                ");
                echo json_encode(['success' => true, 'data' => $liste], JSON_UNESCAPED_UNICODE);
                break;

            case 'gonder':
                if (!$permissions['can_add']) {
                    echo json_encode(['success' => false, 'message' => 'Bildirim gönderme yetkiniz yok!']);
                    break;
                }

                $baslik = trim($_POST['baslik'] ?? '');
                $govde  = trim($_POST['govde'] ?? '');
                $url    = trim($_POST['url'] ?? '');
                $tip    = $_POST['tip'] ?? 'info';
                $pushGonder = isset($_POST['push_gonder']);
                $hedefTip = $_POST['hedef_tip'] ?? '';

                if ($baslik === '') {
                    echo json_encode(['success' => false, 'message' => 'Başlık zorunludur!']);
                    break;
                }
                if (!in_array($tip, ['info', 'basari', 'uyari', 'hata'], true)) {
                    $tip = 'info';
                }

                $veri = [
                    'baslik'    => $baslik,
                    'govde'     => $govde ?: null,
                    'url'       => $url ?: null,
                    'tip'       => $tip,
                    'push'      => $pushGonder,
                    'olusturan' => $user['kullanici_id'],
                ];

                if ($hedefTip === 'kullanici') {
                    $idler = array_filter(array_map('intval', (array)($_POST['kullanici_idler'] ?? [])));
                    if (empty($idler)) {
                        echo json_encode(['success' => false, 'message' => 'En az bir kullanıcı seçmelisiniz!']);
                        break;
                    }
                    $olusan = Bildirim::olusturCoklu($db, $idler, $veri);
                    echo json_encode(['success' => true, 'message' => count($olusan) . ' kullanıcıya bildirim gönderildi.'], JSON_UNESCAPED_UNICODE);
                } elseif ($hedefTip === 'departman') {
                    $departmanId = (int)($_POST['departman_id'] ?? 0);
                    if ($departmanId <= 0) {
                        echo json_encode(['success' => false, 'message' => 'Departman seçmelisiniz!']);
                        break;
                    }
                    $uyeler = $db->fetchAll("SELECT kullanici_id FROM kullanicilar WHERE kullanici_departman_id = ? AND kullanici_durum = 1", [$departmanId]);
                    $idler = array_column($uyeler, 'kullanici_id');
                    if (empty($idler)) {
                        echo json_encode(['success' => false, 'message' => 'Bu departmanda aktif kullanıcı yok!']);
                        break;
                    }
                    $olusan = Bildirim::olusturCoklu($db, $idler, $veri);
                    echo json_encode(['success' => true, 'message' => count($olusan) . ' departman üyesine bildirim gönderildi.'], JSON_UNESCAPED_UNICODE);
                } elseif ($hedefTip === 'herkes') {
                    $id = Bildirim::genelDuyuru($db, $veri);
                    echo json_encode(['success' => (bool)$id, 'message' => $id ? 'Genel duyuru gönderildi (tüm cihazlara push dahil).' : 'Duyuru oluşturulamadı!'], JSON_UNESCAPED_UNICODE);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Hedef seçmelisiniz!']);
                }
                break;

            case 'sil':
                if (!$permissions['can_delete']) {
                    echo json_encode(['success' => false, 'message' => 'Silme yetkiniz yok!']);
                    break;
                }
                $id = (int)($_POST['bildirim_id'] ?? 0);
                $db->execute("UPDATE Bildirimler SET Durum = 0, GuncelleyenKullanici = ?, GuncellemeTarihi = GETDATE() WHERE bildirim_id = ?", [$user['kullanici_id'], $id]);
                echo json_encode(['success' => true, 'message' => 'Bildirim silindi.'], JSON_UNESCAPED_UNICODE);
                break;

            default:
                echo json_encode(['success' => false, 'message' => 'Geçersiz işlem']);
        }
    } catch (Exception $e) {
        error_log('bildirim-gonder hata: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'İşlem sırasında hata oluştu'], JSON_UNESCAPED_UNICODE);
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
                                <span class="info-box-icon"><i class="bi bi-bell"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Toplam Bildirim</span>
                                    <span class="info-box-number" id="stat-toplam">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-box text-bg-success">
                                <span class="info-box-icon"><i class="bi bi-calendar-day"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Bugün Gönderilen</span>
                                    <span class="info-box-number" id="stat-bugun">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-box text-bg-info">
                                <span class="info-box-icon"><i class="bi bi-broadcast"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Push Gönderilen</span>
                                    <span class="info-box-number" id="stat-push">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-box text-bg-warning">
                                <span class="info-box-icon"><i class="bi bi-envelope"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Okunmamış</span>
                                    <span class="info-box-number" id="stat-okunmamis">0</span>
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
                                    <div class="col-md-4">
                                        <label class="form-label">Ara</label>
                                        <input type="text" class="form-control" name="search" id="filter_search" placeholder="Başlık veya mesaj...">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Tip</label>
                                        <select class="form-select" name="tip" id="filter_tip">
                                            <option value="">Tümü</option>
                                            <option value="info">Bilgi</option>
                                            <option value="basari">Başarı</option>
                                            <option value="uyari">Uyarı</option>
                                            <option value="hata">Hata</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Hedef</label>
                                        <select class="form-select" name="hedef" id="filter_hedef">
                                            <option value="">Tümü</option>
                                            <option value="kullanici">Kullanıcı</option>
                                            <option value="genel">Herkes</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Push</label>
                                        <select class="form-select" name="push" id="filter_push">
                                            <option value="">Tümü</option>
                                            <option value="1">Gönderildi</option>
                                            <option value="0">Gönderilmedi</option>
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
                            <h3 class="card-title">Gönderilen Bildirimler</h3>
                            <div class="card-tools">
                                <?php if ($permissions['can_add']): ?>
                                <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#bildirimModal" onclick="resetForm()">
                                    <i class="bi bi-send"></i> Yeni Bildirim Gönder
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="card-body">
                            <table id="bildirimTable" class="table table-bordered table-striped table-hover">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Başlık</th>
                                        <th>Mesaj</th>
                                        <th>Hedef</th>
                                        <th>Tip</th>
                                        <th>Push</th>
                                        <th>Okundu</th>
                                        <th>Gönderen</th>
                                        <th>Tarih</th>
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

    <!-- Bildirim Gönder Modal -->
    <div class="modal fade" id="bildirimModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-send"></i> Yeni Bildirim Gönder</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="bildirimForm">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Hedef <span class="text-danger">*</span></label>
                            <div class="btn-group w-100" role="group">
                                <input type="radio" class="btn-check" name="hedef_tip" id="hedef_kullanici" value="kullanici" checked>
                                <label class="btn btn-outline-primary" for="hedef_kullanici"><i class="bi bi-person"></i> Kullanıcı(lar)</label>

                                <input type="radio" class="btn-check" name="hedef_tip" id="hedef_departman" value="departman">
                                <label class="btn btn-outline-primary" for="hedef_departman"><i class="bi bi-building"></i> Departman</label>

                                <input type="radio" class="btn-check" name="hedef_tip" id="hedef_herkes" value="herkes">
                                <label class="btn btn-outline-primary" for="hedef_herkes"><i class="bi bi-globe"></i> Herkes</label>
                            </div>
                        </div>

                        <div class="mb-3" id="grupKullanici">
                            <label class="form-label">Kullanıcılar <span class="text-danger">*</span></label>
                            <select class="form-select" id="kullanici_idler" name="kullanici_idler[]" multiple style="width:100%"></select>
                        </div>

                        <div class="mb-3 d-none" id="grupDepartman">
                            <label class="form-label">Departman <span class="text-danger">*</span></label>
                            <select class="form-select" id="departman_id" name="departman_id" style="width:100%"></select>
                        </div>

                        <div class="mb-3">
                            <label for="baslik" class="form-label">Başlık <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="baslik" name="baslik" maxlength="200" required>
                        </div>

                        <div class="mb-3">
                            <label for="govde" class="form-label">Mesaj</label>
                            <textarea class="form-control" id="govde" name="govde" rows="3" maxlength="1000"></textarea>
                        </div>

                        <div class="row">
                            <div class="col-md-8 mb-3">
                                <label for="url" class="form-label">Bağlantı (tıklanınca açılır)</label>
                                <input type="text" class="form-control" id="url" name="url" placeholder="/admin/pages/...">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="tip" class="form-label">Tip</label>
                                <select class="form-select" id="tip" name="tip">
                                    <option value="info">Bilgi</option>
                                    <option value="basari">Başarı</option>
                                    <option value="uyari">Uyarı</option>
                                    <option value="hata">Hata</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="push_gonder" name="push_gonder" checked>
                            <label class="form-check-label" for="push_gonder">Push bildirimi olarak da gönder</label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="bi bi-x-circle"></i> İptal</button>
                        <button type="submit" class="btn btn-primary" id="gonderBtn"><i class="bi bi-send"></i> Gönder</button>
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
        let dataTable;
        const canDelete = <?= $permissions['can_delete'] ? 'true' : 'false' ?>;

        const tipBadge = {
            info:   '<span class="badge text-bg-info">Bilgi</span>',
            basari: '<span class="badge text-bg-success">Başarı</span>',
            uyari:  '<span class="badge text-bg-warning">Uyarı</span>',
            hata:   '<span class="badge text-bg-danger">Hata</span>'
        };

        function formatDate(s) {
            if (!s) return '-';
            const d = new Date(String(s).replace(' ', 'T'));
            return isNaN(d.getTime()) ? '-' : d.toLocaleDateString('tr-TR', {year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit'});
        }

        function escapeHtml(t) {
            return $('<div>').text(t ?? '').html();
        }

        function loadStats() {
            $.post('', {action: 'stats'}, function(r) {
                if (r.success) {
                    $('#stat-toplam').text(r.data.toplam);
                    $('#stat-bugun').text(r.data.bugun);
                    $('#stat-push').text(r.data.push);
                    $('#stat-okunmamis').text(r.data.okunmamis);
                }
            });
        }

        function loadList() {
            const filtre = {
                action: 'list',
                search: $('#filter_search').val(),
                tip: $('#filter_tip').val(),
                hedef: $('#filter_hedef').val(),
                push: $('#filter_push').val()
            };
            $.post('', filtre, function(r) {
                if (!r.success) { showToast('Liste yüklenemedi', 'error'); return; }
                dataTable.clear();
                r.data.forEach(b => {
                    let islemler = '';
                    if (canDelete) {
                        islemler = `<button class="btn btn-sm btn-danger" onclick="silBildirim(${b.bildirim_id})"><i class="bi bi-trash"></i></button>`;
                    }
                    dataTable.row.add([
                        b.bildirim_id,
                        escapeHtml(b.bildirim_baslik),
                        escapeHtml(b.bildirim_govde || '-'),
                        escapeHtml(b.hedef_kullanici),
                        tipBadge[b.bildirim_tip] || tipBadge.info,
                        b.bildirim_push_gonderildi == 1 ? '<span class="badge text-bg-success"><i class="bi bi-check"></i></span>' : '<span class="badge text-bg-secondary">-</span>',
                        b.bildirim_okundu == 1 ? '<span class="badge text-bg-success"><i class="bi bi-check"></i></span>' : '<span class="badge text-bg-warning">Hayır</span>',
                        escapeHtml(b.gonderen),
                        formatDate(b.olusturma_tarihi),
                        islemler
                    ]);
                });
                dataTable.draw();
            });
        }

        function silBildirim(id) {
            Swal.fire({
                title: 'Bildirimi sil?',
                text: '#' + id + ' numaralı bildirim silinecek.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Evet, sil',
                cancelButtonText: 'İptal'
            }).then(res => {
                if (res.isConfirmed) {
                    $.post('', {action: 'sil', bildirim_id: id}, function(r) {
                        showToast(r.message, r.success ? 'success' : 'error');
                        if (r.success) { loadList(); loadStats(); }
                    });
                }
            });
        }

        function resetForm() {
            $('#bildirimForm')[0].reset();
            $('#kullanici_idler').val(null).trigger('change');
            $('#departman_id').val(null).trigger('change');
            $('#hedef_kullanici').prop('checked', true).trigger('change');
        }

        $(document).ready(function() {
            dataTable = $('#bildirimTable').DataTable({
                language: { url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/tr.json' },
                order: [[0, 'desc']],
                columnDefs: [{ targets: [4, 5, 6, 9], orderable: false }]
            });

            // Select2 (aramalı)
            $('#kullanici_idler').select2({
                theme: 'bootstrap-5',
                dropdownParent: $('#bildirimModal'),
                placeholder: 'Kullanıcı seçin...',
                width: '100%'
            });
            $('#departman_id').select2({
                theme: 'bootstrap-5',
                dropdownParent: $('#bildirimModal'),
                placeholder: 'Departman seçin...',
                width: '100%'
            });
            $('#filter_tip, #filter_hedef, #filter_push').select2({
                theme: 'bootstrap-5',
                minimumResultsForSearch: 5,
                width: '100%'
            });

            // Kullanıcı ve departman listelerini yükle
            $.post('', {action: 'kullanici_listesi'}, function(r) {
                if (r.success) {
                    r.data.forEach(k => $('#kullanici_idler').append(new Option(k.ad_soyad, k.kullanici_id)));
                }
            });
            $.post('', {action: 'departman_listesi'}, function(r) {
                if (r.success) {
                    $('#departman_id').append(new Option('', '', true, true));
                    r.data.forEach(d => $('#departman_id').append(new Option(d.departman_adi, d.departman_id)));
                }
            });

            // Hedef değişince alanları göster/gizle
            $('input[name="hedef_tip"]').on('change', function() {
                const tip = $('input[name="hedef_tip"]:checked').val();
                $('#grupKullanici').toggleClass('d-none', tip !== 'kullanici');
                $('#grupDepartman').toggleClass('d-none', tip !== 'departman');
            });

            // Gönder
            $('#bildirimForm').on('submit', function(e) {
                e.preventDefault();
                const btn = $('#gonderBtn');
                btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Gönderiliyor...');

                const veri = $(this).serialize() + '&action=gonder';
                $.post('', veri, function(r) {
                    showToast(r.message, r.success ? 'success' : 'error');
                    if (r.success) {
                        $('#bildirimModal').modal('hide');
                        loadList();
                        loadStats();
                    }
                }).always(function() {
                    btn.prop('disabled', false).html('<i class="bi bi-send"></i> Gönder');
                });
            });

            // Filtre
            $('#filterForm').on('submit', function(e) {
                e.preventDefault();
                loadList();
                showToast('Filtre uygulandı', 'info');
            });
            $('#clearfilters').on('click', function() {
                $('#filterForm')[0].reset();
                $('#filter_tip, #filter_hedef, #filter_push').val('').trigger('change.select2');
                loadList();
                showToast('Filtreler temizlendi', 'info');
            });

            loadStats();
            loadList();
        });
    </script>
</body>
</html>
