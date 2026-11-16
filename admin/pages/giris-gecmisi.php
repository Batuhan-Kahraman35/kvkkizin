<?php
/**
 * Admin Panel - Giriş Geçmişi
 *
 * KullanicilarLog tablosundaki giriş/çıkış ve güvenlik olaylarını listeler.
 * Administrator (departman_id = 1) tüm kayıtları, diğer kullanıcılar sadece kendi kayıtlarını görür.
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
requireAuth();

$user = Auth::user();
$db = Database::getInstance();

// Sayfa Yetki kontrolü
$currentPagefile = basename($_SERVER['PHP_SELF']);
$pagePermissions = PageAuth::checkPagePermissions(
    $user['kullanici_id'],
    $user['departman_id'],
    $currentPagefile
);

// Sayfa erişim kontrolü
if (!$pagePermissions['has_access']) {
    PageAuth::accessDenied($pagePermissions['error'] ?? 'Bu sayfaya erişim Yetkiniz bulunmamaktadir.');
}

// Administrator tüm kayıtları görür, diğerleri sadece kendi kayıtlarını
$isAdmin = ($user['departman_id'] == 1);

// Mevcut sayfanin bilgilerini al
$pageinfo = $db->fetchOne("
    SELECT
        s.sayfalar_sayfa_adi,
        s.sayfalar_aciklama,
        m.menuler_menu_adi as menu_adi
    FROM Menu_Sayfalar s
    LEFT JOIN Menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1
", ['%' . $currentPagefile]);

$pageTitle = $pageinfo['sayfalar_sayfa_adi'] ?? 'Giriş Geçmişi';
$pageDescription = $pageinfo['sayfalar_aciklama'] ?? '';
$menuAdi = $pageinfo['menu_adi'] ?? null;

// site title'i çek
$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

// Log tipleri (filtre dropdown + badge için DB'den)
$logTipleri = $db->fetchAll("
    SELECT KullanicilarLogTipleri_Id, KullanicilarLogTipleri_Kod, KullanicilarLogTipleri_Ad
    FROM tanim_KullanicilarLogTipleri
    WHERE Durum = 1
    ORDER BY KullanicilarLogTipleri_Id
");

// Kullanıcı listesi (sadece admin filtresi için)
$kullaniciListesi = $isAdmin ? $db->fetchAll("
    SELECT kullanici_id, kullanici_ad + ' ' + kullanici_soyad AS kullanici_adi, kullanici_email
    FROM kullanicilar
    WHERE kullanici_durum = 1
    ORDER BY kullanici_ad, kullanici_soyad
") : [];

// AJAX işlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    try {
        $action = $_POST['action'] ?? '';

        switch ($action) {
            case 'list':
                $startDate   = $_POST['start_date'] ?? '';
                $endDate     = $_POST['end_date'] ?? '';
                $tipId       = (int)($_POST['tip_id'] ?? 0);
                $kullaniciId = (int)($_POST['kullanici_id'] ?? 0);
                $search      = trim($_POST['search'] ?? '');

                $whereConditions = ["l.Durum = 1"];
                $params = [];

                // Admin olmayan sadece kendi kayıtlarını görür
                if (!$isAdmin) {
                    $whereConditions[] = "l.KullanicilarLog_KullaniciId = ?";
                    $params[] = $user['kullanici_id'];
                } elseif ($kullaniciId > 0) {
                    $whereConditions[] = "l.KullanicilarLog_KullaniciId = ?";
                    $params[] = $kullaniciId;
                }

                if ($startDate) {
                    $whereConditions[] = "CONVERT(date, l.OlusturmaTarihi) >= ?";
                    $params[] = $startDate;
                }
                if ($endDate) {
                    $whereConditions[] = "CONVERT(date, l.OlusturmaTarihi) <= ?";
                    $params[] = $endDate;
                }
                if ($tipId > 0) {
                    $whereConditions[] = "l.KullanicilarLog_TipId = ?";
                    $params[] = $tipId;
                }
                if ($search !== '') {
                    $whereConditions[] = "(l.KullanicilarLog_Email LIKE ? OR l.KullanicilarLog_IP LIKE ? OR l.KullanicilarLog_Cihaz LIKE ? OR l.KullanicilarLog_Aciklama LIKE ?)";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                }

                $whereClause = implode(" AND ", $whereConditions);

                $kayitlar = $db->fetchAll("
                    SELECT TOP 1000
                        l.KullanicilarLog_Id,
                        CONVERT(VARCHAR(19), l.OlusturmaTarihi, 120) AS log_tarih,
                        l.KullanicilarLog_Email,
                        k.kullanici_ad + ' ' + k.kullanici_soyad AS kullanici_adi,
                        t.KullanicilarLogTipleri_Kod AS tip_kod,
                        t.KullanicilarLogTipleri_Ad AS tip_adi,
                        l.KullanicilarLog_IP,
                        l.KullanicilarLog_Cihaz,
                        l.KullanicilarLog_PWA,
                        l.KullanicilarLog_Konum,
                        l.KullanicilarLog_Tarayici,
                        l.KullanicilarLog_Aciklama
                    FROM KullanicilarLog l
                    INNER JOIN tanim_KullanicilarLogTipleri t ON t.KullanicilarLogTipleri_Id = l.KullanicilarLog_TipId
                    LEFT JOIN kullanicilar k ON k.kullanici_id = l.KullanicilarLog_KullaniciId
                    WHERE $whereClause
                    ORDER BY l.KullanicilarLog_Id DESC
                ", $params);

                echo json_encode(['success' => true, 'data' => $kayitlar]);
                break;

            case 'stats':
                $kullaniciKosul = $isAdmin ? "" : " AND l.KullanicilarLog_KullaniciId = " . (int)$user['kullanici_id'];

                $stats = [
                    'toplam' => $db->fetchOne("
                        SELECT COUNT(*) AS sayi FROM KullanicilarLog l WHERE l.Durum = 1 $kullaniciKosul
                    ")['sayi'] ?? 0,
                    'bugun_giris' => $db->fetchOne("
                        SELECT COUNT(*) AS sayi
                        FROM KullanicilarLog l
                        INNER JOIN tanim_KullanicilarLogTipleri t ON t.KullanicilarLogTipleri_Id = l.KullanicilarLog_TipId
                        WHERE l.Durum = 1 AND t.KullanicilarLogTipleri_Kod = 'giris_basarili'
                          AND CONVERT(date, l.OlusturmaTarihi) = CONVERT(date, GETDATE()) $kullaniciKosul
                    ")['sayi'] ?? 0,
                    'bugun_basarisiz' => $db->fetchOne("
                        SELECT COUNT(*) AS sayi
                        FROM KullanicilarLog l
                        INNER JOIN tanim_KullanicilarLogTipleri t ON t.KullanicilarLogTipleri_Id = l.KullanicilarLog_TipId
                        WHERE l.Durum = 1 AND t.KullanicilarLogTipleri_Kod = 'giris_basarisiz'
                          AND CONVERT(date, l.OlusturmaTarihi) = CONVERT(date, GETDATE()) $kullaniciKosul
                    ")['sayi'] ?? 0,
                    'pwa_giris' => $db->fetchOne("
                        SELECT COUNT(*) AS sayi
                        FROM KullanicilarLog l
                        INNER JOIN tanim_KullanicilarLogTipleri t ON t.KullanicilarLogTipleri_Id = l.KullanicilarLog_TipId
                        WHERE l.Durum = 1 AND t.KullanicilarLogTipleri_Kod = 'giris_basarili'
                          AND l.KullanicilarLog_PWA = 1 $kullaniciKosul
                    ")['sayi'] ?? 0,
                ];
                echo json_encode(['success' => true, 'data' => $stats]);
                break;

            default:
                echo json_encode(['success' => false, 'message' => 'Geçersiz işlem!']);
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
    <link rel="stylesheet" href="/Admin/assets/css/Adminlte.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="/Admin/assets/css/custom.css">

    <style>
        .tarayici-hucre {
            max-width: 200px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            display: inline-block;
        }
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
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-primary shadow-sm">
                                    <i class="bi bi-journal-text"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Toplam Kayıt</span>
                                    <span class="info-box-number" id="stat-toplam">0</span>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-success shadow-sm">
                                    <i class="bi bi-box-arrow-in-right"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Bugünkü Giriş</span>
                                    <span class="info-box-number" id="stat-bugun-giris">0</span>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-danger shadow-sm">
                                    <i class="bi bi-shield-exclamation"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Bugünkü Başarısız Deneme</span>
                                    <span class="info-box-number" id="stat-bugun-basarisiz">0</span>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-info shadow-sm">
                                    <i class="bi bi-phone"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">PWA Girişleri</span>
                                    <span class="info-box-number" id="stat-pwa">0</span>
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
                                    <div class="col-md-2">
                                        <label class="form-label">Başlangıç Tarihi</label>
                                        <input type="date" class="form-control" name="start_date" id="filter_start_date">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Bitiş Tarihi</label>
                                        <input type="date" class="form-control" name="end_date" id="filter_end_date">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">İşlem Tipi</label>
                                        <select class="form-select" name="tip_id" id="filter_tip">
                                            <option value="">Tümü</option>
                                            <?php foreach ($logTipleri as $tip): ?>
                                            <option value="<?= $tip['KullanicilarLogTipleri_Id'] ?>"><?= htmlspecialchars($tip['KullanicilarLogTipleri_Ad']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <?php if ($isAdmin): ?>
                                    <div class="col-md-3">
                                        <label class="form-label">Kullanıcı</label>
                                        <select class="form-select" name="kullanici_id" id="filter_kullanici">
                                            <option value="">Tümü</option>
                                            <?php foreach ($kullaniciListesi as $k): ?>
                                            <option value="<?= $k['kullanici_id'] ?>"><?= htmlspecialchars($k['kullanici_adi'] . ' (' . $k['kullanici_email'] . ')') ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <?php endif; ?>
                                    <div class="col-md-3">
                                        <label class="form-label">Ara (E-posta/IP/Cihaz)</label>
                                        <input type="text" class="form-control" name="search" id="filter_search" placeholder="Anahtar kelime...">
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

                    <!-- Giriş Geçmişi Tablosu -->
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">Giriş Geçmişi</h3>
                        </div>
                        <div class="card-body">
                            <table id="logTable" class="table table-bordered table-striped">
                                <thead>
                                    <tr>
                                        <th>Id</th>
                                        <th>Tarih</th>
                                        <th>Kullanıcı</th>
                                        <th>İşlem</th>
                                        <th>IP</th>
                                        <th>Cihaz</th>
                                        <th>PWA</th>
                                        <th>Konum</th>
                                        <th>Tarayıcı</th>
                                        <th>Açıklama</th>
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

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js"></script>
    <script src="/Admin/assets/js/Adminlte.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="/Admin/assets/js/custom.js"></script>

    <script>
        let table;
        let currentfilters = {};

        // Tip koduna göre badge rengi
        const tipBadge = {
            'giris_basarili':  'bg-success',
            'giris_basarisiz': 'bg-danger',
            'cikis':           'bg-secondary',
            'sifre_sifirlama': 'bg-warning',
            'email_dogrulama': 'bg-info',
            'impersonate_bas': 'bg-dark',
            'impersonate_son': 'bg-dark'
        };

        function loadStats() {
            $.post('', { action: 'stats' }, response => {
                if (response.success) {
                    $('#stat-toplam').text(response.data.toplam);
                    $('#stat-bugun-giris').text(response.data.bugun_giris);
                    $('#stat-bugun-basarisiz').text(response.data.bugun_basarisiz);
                    $('#stat-pwa').text(response.data.pwa_giris);
                }
            });
        }

        function initDataTable() {
            table = $('#logTable').DataTable({
                processing: true,
                language: { url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json' },
                ajax: {
                    url: '',
                    type: 'POST',
                    data: function(d) {
                        return { action: 'list', ...currentfilters };
                    },
                    dataSrc: json => json.success ? json.data : []
                },
                columns: [
                    { data: 'KullanicilarLog_Id' },
                    { data: 'log_tarih' },
                    {
                        data: null,
                        render: row => {
                            const ad = row.kullanici_adi || '-';
                            const email = row.KullanicilarLog_Email || '';
                            return email ? `${ad}<br><small class="text-muted">${email}</small>` : ad;
                        }
                    },
                    {
                        data: null,
                        render: row => {
                            const badge = tipBadge[row.tip_kod] || 'bg-secondary';
                            return `<span class="badge ${badge}">${row.tip_adi}</span>`;
                        }
                    },
                    { data: 'KullanicilarLog_IP', defaultContent: '-' },
                    { data: 'KullanicilarLog_Cihaz', defaultContent: '-' },
                    {
                        data: 'KullanicilarLog_PWA',
                        render: data => data == 1
                            ? '<span class="badge bg-info"><i class="bi bi-phone"></i> PWA</span>'
                            : '-'
                    },
                    {
                        data: 'KullanicilarLog_Konum',
                        render: data => {
                            if (!data) return '-';
                            return `<a href="https://www.google.com/maps?q=${encodeURIComponent(data)}" target="_blank">
                                        <i class="bi bi-geo-alt"></i> Haritada Gör
                                    </a>`;
                        }
                    },
                    {
                        data: 'KullanicilarLog_Tarayici',
                        render: data => data
                            ? `<span class="tarayici-hucre" title="${$('<span>').text(data).html()}">${$('<span>').text(data).html()}</span>`
                            : '-'
                    },
                    { data: 'KullanicilarLog_Aciklama', defaultContent: '-' }
                ],
                order: [[0, 'desc']]
            });
        }

        $(document).ready(() => {
            loadStats();
            initDataTable();

            // Select2 (searchable dropdown)
            $('#filter_tip, #filter_kullanici').select2({
                theme: 'bootstrap-5',
                width: '100%',
                language: {
                    noResults: function() { return "Sonuç bulunamadı"; },
                    searching: function() { return "Aranıyor..."; }
                }
            });

            // Filtre formu
            $('#filterForm').on('submit', function(e) {
                e.preventDefault();
                currentfilters = {
                    start_date: $('#filter_start_date').val(),
                    end_date: $('#filter_end_date').val(),
                    tip_id: $('#filter_tip').val(),
                    kullanici_id: $('#filter_kullanici').val() || '',
                    search: $('#filter_search').val()
                };
                table.ajax.reload();
            });

            // Filtre temizle
            $('#clearfilters').on('click', function() {
                $('#filterForm')[0].reset();
                $('#filter_tip, #filter_kullanici').val('').trigger('change');
                currentfilters = {};
                table.ajax.reload();
            });
        });
    </script>
</body>
</html>
