<?php
/**
 * Admin Panel - Varsayılan Dashboard (firma kapsamlı)
 * Departmana özel dashboard tanımlanmamışsa bu sayfa gösterilir.
 * NOT: Bu dosya anasayfa.php tarafından include edilir, doğrudan çağrılmaz!
 *
 * Firma kapsamı:
 *  - Administrator (departman 1) → tüm firmalar
 *  - Diğer kullanıcılar          → kendi firması + alt firmaları
 *  Kapsamda birden fazla firma varsa üstte firma seçici gösterilir.
 */

// $user ve $db değişkenleri anasayfa.php'den geliyor
require_once __DIR__ . '/../includes/PageAuth.php';

$dashboardYetki = [
    'is_admin'       => ((int)($user['departman_id'] ?? 0) === 1),
    'can_view_firma' => true,
];
$firmaScopeIds = PageAuth::firmaScopeIds($dashboardYetki, $user['firma_id'] ?? null);

/**
 * İstekteki firma seçimini kapsamla doğrulayıp aktif kapsamı döndürür.
 * Seçim yoksa kullanıcının tüm kapsamı, kapsam dışı seçimde boş dizi döner.
 */
function dashboardKapsam($firmaScopeIds) {
    $secili = (int)($_POST['firma_id'] ?? 0);
    if ($secili <= 0) {
        return $firmaScopeIds;
    }
    if ($firmaScopeIds === null || in_array($secili, $firmaScopeIds, true)) {
        return [$secili];
    }
    return [];
}

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Portal';

// AJAX işlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'] ?? '';
    $kapsam = dashboardKapsam($firmaScopeIds);

    try {
        switch ($action) {
            case 'firmalar':
                [$sql, $prm] = PageAuth::firmaScopeWhere($firmaScopeIds, 'firma_id');
                $data = $db->fetchAll("
                    SELECT firma_id, firma_adi, firma_ust_firma_id
                    FROM dbo.Firmalar
                    WHERE firma_durum = 1 AND $sql
                    ORDER BY firma_adi
                ", $prm);
                echo json_encode(['success' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
                break;

            case 'ozet':
                [$sqlB, $prmB] = PageAuth::firmaScopeWhere($kapsam, 'b.firma_id');
                $basvuru = $db->fetchOne("
                    SELECT
                        COUNT(*) AS toplam,
                        SUM(CASE WHEN b.form_basvuru_tarihi >= DATEFROMPARTS(YEAR(GETDATE()), MONTH(GETDATE()), 1) THEN 1 ELSE 0 END) AS bu_ay,
                        SUM(CASE WHEN d.form_dogrulama_durum_kod = 'onaylandi' THEN 1 ELSE 0 END) AS dogrulanan,
                        SUM(CASE WHEN d.form_dogrulama_durum_kod = 'bekliyor' THEN 1 ELSE 0 END) AS bekleyen
                    FROM dbo.Form_Basvurulari b
                    LEFT JOIN dbo.tanim_FormDogrulamaDurumlari d ON d.form_dogrulama_durum_id = b.form_basvuru_dogrulama_durumu
                    WHERE b.form_basvuru_durum = 1 AND $sqlB
                ", $prmB);

                [$sqlF, $prmF] = PageAuth::firmaScopeWhere($kapsam, 'firma_id');
                $form = $db->fetchOne("
                    SELECT COUNT(*) AS toplam, SUM(CASE WHEN form_durum = 1 THEN 1 ELSE 0 END) AS aktif
                    FROM dbo.Formlar WHERE $sqlF
                ", $prmF);

                echo json_encode(['success' => true, 'data' => [
                    'basvuru_toplam'    => (int)($basvuru['toplam'] ?? 0),
                    'basvuru_bu_ay'     => (int)($basvuru['bu_ay'] ?? 0),
                    'basvuru_dogrulanan'=> (int)($basvuru['dogrulanan'] ?? 0),
                    'basvuru_bekleyen'  => (int)($basvuru['bekleyen'] ?? 0),
                    'form_toplam'       => (int)($form['toplam'] ?? 0),
                    'form_aktif'        => (int)($form['aktif'] ?? 0),
                ]], JSON_UNESCAPED_UNICODE);
                break;

            case 'grafik':
                [$sql, $prm] = PageAuth::firmaScopeWhere($kapsam, 'b.firma_id');
                $rows = $db->fetchAll("
                    SELECT FORMAT(b.form_basvuru_tarihi, 'yyyy-MM') AS ay, COUNT(*) AS adet
                    FROM dbo.Form_Basvurulari b
                    WHERE b.form_basvuru_durum = 1
                      AND b.form_basvuru_tarihi >= DATEADD(MONTH, -11, DATEFROMPARTS(YEAR(GETDATE()), MONTH(GETDATE()), 1))
                      AND $sql
                    GROUP BY FORMAT(b.form_basvuru_tarihi, 'yyyy-MM')
                ", $prm);
                $adetler = array_column($rows, 'adet', 'ay');

                // Son 12 ay, boş aylar 0 olarak
                $aylar = ['Oca', 'Şub', 'Mar', 'Nis', 'May', 'Haz', 'Tem', 'Ağu', 'Eyl', 'Eki', 'Kas', 'Ara'];
                $etiketler = [];
                $seri = [];
                $bas = new DateTime('first day of this month');
                $bas->modify('-11 months');
                for ($i = 0; $i < 12; $i++) {
                    $anahtar = $bas->format('Y-m');
                    $etiketler[] = $aylar[(int)$bas->format('n') - 1] . ' ' . $bas->format('y');
                    $seri[] = (int)($adetler[$anahtar] ?? 0);
                    $bas->modify('+1 month');
                }
                echo json_encode(['success' => true, 'data' => ['etiketler' => $etiketler, 'seri' => $seri]], JSON_UNESCAPED_UNICODE);
                break;

            case 'son_basvurular':
                [$sql, $prm] = PageAuth::firmaScopeWhere($kapsam, 'b.firma_id');
                $data = $db->fetchAll("
                    SELECT TOP 10
                        b.form_basvuru_id,
                        b.form_basvuru_ad, b.form_basvuru_soyad,
                        CONVERT(VARCHAR(16), b.form_basvuru_tarihi, 120) AS tarih,
                        f.form_adi, fi.firma_adi,
                        d.form_dogrulama_durum_adi AS dogrulama_adi,
                        d.form_dogrulama_durum_kod AS dogrulama_kod
                    FROM dbo.Form_Basvurulari b
                    LEFT JOIN dbo.Formlar f ON f.form_id = b.form_id
                    LEFT JOIN dbo.Firmalar fi ON fi.firma_id = b.firma_id
                    LEFT JOIN dbo.tanim_FormDogrulamaDurumlari d ON d.form_dogrulama_durum_id = b.form_basvuru_dogrulama_durumu
                    WHERE b.form_basvuru_durum = 1 AND $sql
                    ORDER BY b.form_basvuru_tarihi DESC, b.form_basvuru_id DESC
                ", $prm);
                echo json_encode(['success' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
                break;

            case 'sozlesmeler':
                // Her firmanın en güncel aktif sözleşmesi
                [$sql, $prm] = PageAuth::firmaScopeWhere($kapsam, 's.firma_id');
                $data = $db->fetchAll("
                    SELECT TOP 10 * FROM (
                        SELECT
                            fi.firma_adi,
                            CONVERT(VARCHAR(10), s.firma_sozlesme_baslangic_tarihi, 104) AS baslangic,
                            CONVERT(VARCHAR(10), s.firma_sozlesme_bitis_tarihi, 104) AS bitis,
                            DATEDIFF(DAY, CAST(GETDATE() AS DATE), s.firma_sozlesme_bitis_tarihi) AS kalan_gun,
                            o.odeme_durumu_adi, o.odeme_durumu_renk,
                            ROW_NUMBER() OVER (PARTITION BY s.firma_id ORDER BY s.firma_sozlesme_bitis_tarihi DESC) AS sira
                        FROM dbo.Firma_Sozlesmeleri s
                        INNER JOIN dbo.Firmalar fi ON fi.firma_id = s.firma_id
                        LEFT JOIN dbo.tanim_SozlesmeOdemeDurumlari o ON o.odeme_durumu_id = s.odeme_durumu_id
                        WHERE s.firma_sozlesme_durum = 1 AND $sql
                    ) x
                    WHERE x.sira = 1
                    ORDER BY x.kalan_gun
                ", $prm);
                echo json_encode(['success' => true, 'data' => $data, 'yaklasan_gun' => SozlesmeHelper::YAKLASAN_GUN], JSON_UNESCAPED_UNICODE);
                break;

            case 'belgeler':
                [$sql, $prm] = PageAuth::firmaScopeWhere($kapsam, 'b.firma_id');
                $durumlar = $db->fetchAll("
                    SELECT o.onay_durumu_adi, o.onay_durumu_renk, o.onay_durumu_ikon, COUNT(b.firma_belge_id) AS adet
                    FROM dbo.tanim_BelgeOnayDurumlari o
                    LEFT JOIN dbo.Firma_Belgeleri b
                           ON b.firma_belge_onay_durumu_id = o.onay_durumu_id
                          AND b.firma_belge_durum = 1 AND $sql
                    WHERE o.onay_durumu_durum = 1
                    GROUP BY o.onay_durumu_id, o.onay_durumu_adi, o.onay_durumu_renk, o.onay_durumu_ikon, o.onay_durumu_sira_no
                    ORDER BY o.onay_durumu_sira_no
                ", $prm);

                $yaklasan = $db->fetchAll("
                    SELECT TOP 5
                        b.firma_belge_baslik, t.belge_tipi_adi, fi.firma_adi,
                        CONVERT(VARCHAR(10), b.firma_belge_gecerlilik_bitis, 104) AS bitis,
                        DATEDIFF(DAY, CAST(GETDATE() AS DATE), b.firma_belge_gecerlilik_bitis) AS kalan_gun
                    FROM dbo.Firma_Belgeleri b
                    LEFT JOIN dbo.tanim_BelgeTipleri t ON t.belge_tipi_id = b.belge_tipi_id
                    LEFT JOIN dbo.Firmalar fi ON fi.firma_id = b.firma_id
                    WHERE b.firma_belge_durum = 1
                      AND b.firma_belge_gecerlilik_bitis IS NOT NULL
                      AND b.firma_belge_gecerlilik_bitis <= DATEADD(DAY, ?, CAST(GETDATE() AS DATE))
                      AND $sql
                    ORDER BY b.firma_belge_gecerlilik_bitis
                ", array_merge([SozlesmeHelper::YAKLASAN_GUN], $prm));

                echo json_encode(['success' => true, 'data' => ['durumlar' => $durumlar, 'yaklasan' => $yaklasan]], JSON_UNESCAPED_UNICODE);
                break;

            default:
                echo json_encode(['success' => false, 'message' => 'Geçersiz işlem'], JSON_UNESCAPED_UNICODE);
        }
    } catch (Throwable $e) {
        error_log('Dashboard hatası (' . $action . '): ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Veriler yüklenemedi.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// Başlıkta kullanıcının firma adı
$kullaniciFirma = $db->fetchOne("SELECT firma_adi FROM dbo.Firmalar WHERE firma_id = ?", [(int)($user['firma_id'] ?? 0)]);
$firmaSeciciGoster = ($firmaScopeIds === null || count($firmaScopeIds) > 1);
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ana Sayfa - <?= htmlspecialchars($siteTitle) ?></title>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous">
    <link rel="stylesheet" href="/admin/assets/css/adminlte.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/admin/assets/css/custom.css">
    <style>
        .dashboard-card { border: none; }
        .table-dashboard th { font-size: .78rem; text-transform: uppercase; letter-spacing: .4px; color: #6c757d; border-top: none; }
        .table-dashboard td { font-size: .86rem; vertical-align: middle; }
        .firma-secici { min-width: 280px; }
        .belge-durum { display: flex; align-items: center; justify-content: space-between; padding: .45rem 0; border-bottom: 1px solid var(--bs-border-color); }
        .belge-durum:last-child { border-bottom: none; }
    </style>
</head>
<body class="layout-fixed sidebar-expand-lg sidebar-open bg-body-tertiary">
    <div class="app-wrapper">
        <?php include __DIR__ . '/../includes/header.php'; ?>
        <?php include __DIR__ . '/../includes/sidebar.php'; ?>

        <main class="app-main">
            <div class="app-content-header">
                <div class="container-fluid">
                    <div class="row align-items-center g-2">
                        <div class="col-md-6">
                            <h3 class="mb-0">Ana Sayfa</h3>
                            <small class="text-muted"><i class="bi bi-building me-1"></i><?= htmlspecialchars($kullaniciFirma['firma_adi'] ?? '-') ?></small>
                        </div>
                        <?php if ($firmaSeciciGoster): ?>
                        <div class="col-md-6 d-flex justify-content-md-end">
                            <div class="firma-secici">
                                <select class="form-select" id="dash_firma_id" style="width: 100%;">
                                    <option value="">Tüm Firmalar</option>
                                </select>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="app-content">
                <div class="container-fluid">

                    <!-- InfoBox'lar -->
                    <div class="row mb-3">
                        <div class="col-sm-6 col-lg-4">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-primary shadow-sm"><i class="bi bi-people"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Toplam Başvuru</span>
                                    <span class="info-box-number" id="ib_basvuru_toplam">-</span>
                                    <small class="text-muted">Bu ay: <span id="ib_basvuru_bu_ay">-</span></small>
                                </div>
                            </div>
                        </div>
                        <div class="col-sm-6 col-lg-4">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-success shadow-sm"><i class="bi bi-patch-check"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">SMS ile Doğrulanan</span>
                                    <span class="info-box-number" id="ib_basvuru_dogrulanan">-</span>
                                    <small class="text-muted">Bekleyen: <span id="ib_basvuru_bekleyen">-</span></small>
                                </div>
                            </div>
                        </div>
                        <div class="col-sm-6 col-lg-4">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-warning shadow-sm"><i class="bi bi-ui-checks"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Aktif Form</span>
                                    <span class="info-box-number" id="ib_form_aktif">-</span>
                                    <small class="text-muted">Toplam: <span id="ib_form_toplam">-</span></small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <!-- Aylık başvuru grafiği -->
                        <div class="col-lg-8 mb-3 mb-lg-0">
                            <div class="card dashboard-card h-100">
                                <div class="card-header">
                                    <h3 class="card-title"><i class="bi bi-bar-chart me-2"></i>Son 12 Ay Başvurular</h3>
                                </div>
                                <div class="card-body">
                                    <div id="grafik_basvuru" style="min-height: 280px;"></div>
                                </div>
                            </div>
                        </div>
                        <!-- Belge durumu -->
                        <div class="col-lg-4">
                            <div class="card dashboard-card h-100">
                                <div class="card-header">
                                    <h3 class="card-title"><i class="bi bi-folder2-open me-2"></i>Belge Durumu</h3>
                                    <div class="card-tools">
                                        <a href="/admin/firma-belgeleri" class="btn btn-sm btn-outline-secondary">Tümü</a>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <div id="belge_durumlar"><div class="text-center text-muted py-3">Yükleniyor...</div></div>
                                    <div id="belge_yaklasan" class="mt-3"></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row mb-4">
                        <!-- Son başvurular -->
                        <div class="col-lg-8 mb-3 mb-lg-0">
                            <div class="card dashboard-card h-100">
                                <div class="card-header">
                                    <h3 class="card-title"><i class="bi bi-clock-history me-2"></i>Son Başvurular</h3>
                                </div>
                                <div class="card-body p-0">
                                    <div class="table-responsive">
                                        <table class="table table-hover table-dashboard mb-0">
                                            <thead>
                                                <tr>
                                                    <th>Tarih</th>
                                                    <th>Ad Soyad</th>
                                                    <th>Form</th>
                                                    <th class="kolon-firma">Firma</th>
                                                    <th>Doğrulama</th>
                                                </tr>
                                            </thead>
                                            <tbody id="tbl_son_basvurular">
                                                <tr><td colspan="5" class="text-center text-muted py-3">Yükleniyor...</td></tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <!-- Sözleşmeler -->
                        <div class="col-lg-4">
                            <div class="card dashboard-card h-100">
                                <div class="card-header">
                                    <h3 class="card-title"><i class="bi bi-file-earmark-text me-2"></i>Sözleşme Durumu</h3>
                                    <div class="card-tools">
                                        <a href="/admin/firma-sozlesmeleri" class="btn btn-sm btn-outline-secondary">Tümü</a>
                                    </div>
                                </div>
                                <div class="card-body p-0">
                                    <ul class="list-group list-group-flush" id="liste_sozlesmeler">
                                        <li class="list-group-item text-center text-muted py-3">Yükleniyor...</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </main>

        <?php include __DIR__ . '/../includes/footer.php'; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
    <script src="/admin/assets/js/adminlte.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/apexcharts@3.54.1/dist/apexcharts.min.js"></script>
    <script src="/admin/assets/js/custom.js"></script>
    <script>
    const tekFirma = <?= $firmaSeciciGoster ? 'false' : 'true' ?>;
    let grafik = null;

    function esc(s) {
        return $('<div>').text(s == null ? '' : String(s)).html();
    }
    function sayi(v) {
        return (parseFloat(v) || 0).toLocaleString('tr-TR', { maximumFractionDigits: 0 });
    }
    function seciliFirma() {
        return $('#dash_firma_id').val() || '';
    }
    function istek(action, cb) {
        $.post('', { action: action, firma_id: seciliFirma() }, function(res) {
            if (!res || !res.success) {
                showToast((res && res.message) || 'Veriler yüklenemedi.', 'error');
                return;
            }
            cb(res);
        }, 'json').fail(function() {
            showToast('Sunucuya ulaşılamadı.', 'error');
        });
    }

    $(document).ready(function() {
        if (tekFirma) {
            $('.kolon-firma').hide();
        } else {
            $.post('', { action: 'firmalar' }, function(res) {
                if (!res.success) return;
                const $s = $('#dash_firma_id');
                res.data.forEach(function(f) {
                    $s.append(new Option(f.firma_adi, f.firma_id, false, false));
                });
                $s.select2({ theme: 'bootstrap-5', width: '100%', placeholder: 'Tüm Firmalar', allowClear: true });
            }, 'json');

            $('#dash_firma_id').on('change', function() {
                $('.kolon-firma').toggle(!seciliFirma());
                hepsiniYukle();
            });
        }
        hepsiniYukle();
    });

    function hepsiniYukle() {
        loadOzet();
        loadGrafik();
        loadSonBasvurular();
        loadBelgeler();
        loadSozlesmeler();
    }

    function loadOzet() {
        istek('ozet', function(res) {
            const d = res.data;
            $('#ib_basvuru_toplam').text(sayi(d.basvuru_toplam));
            $('#ib_basvuru_bu_ay').text(sayi(d.basvuru_bu_ay));
            $('#ib_basvuru_dogrulanan').text(sayi(d.basvuru_dogrulanan));
            $('#ib_basvuru_bekleyen').text(sayi(d.basvuru_bekleyen));
            $('#ib_form_aktif').text(sayi(d.form_aktif));
            $('#ib_form_toplam').text(sayi(d.form_toplam));
        });
    }

    function loadGrafik() {
        istek('grafik', function(res) {
            const ayar = {
                chart: { type: 'bar', height: 280, toolbar: { show: false }, fontFamily: 'inherit' },
                series: [{ name: 'Başvuru', data: res.data.seri }],
                xaxis: { categories: res.data.etiketler },
                yaxis: { labels: { formatter: v => Math.round(v) } },
                plotOptions: { bar: { borderRadius: 4, columnWidth: '55%' } },
                dataLabels: { enabled: false },
                colors: ['#0d6efd'],
                grid: { strokeDashArray: 4 }
            };
            if (grafik) {
                grafik.updateOptions({ xaxis: ayar.xaxis });
                grafik.updateSeries(ayar.series);
            } else {
                grafik = new ApexCharts(document.querySelector('#grafik_basvuru'), ayar);
                grafik.render();
            }
        });
    }

    function loadSonBasvurular() {
        istek('son_basvurular', function(res) {
            if (!res.data.length) {
                $('#tbl_son_basvurular').html('<tr><td colspan="5" class="text-center text-muted py-3">Kayıt bulunamadı</td></tr>');
                return;
            }
            const firmaGoster = !tekFirma && !seciliFirma();
            const renk = { onaylandi: 'success', bekliyor: 'warning', yenilendi: 'info' };
            let html = '';
            res.data.forEach(function(r) {
                html += '<tr>' +
                    '<td><small class="text-muted">' + esc(formatDate(r.tarih, 'dd.mm.yyyy hh:mm')) + '</small></td>' +
                    '<td class="fw-semibold">' + esc(((r.form_basvuru_ad || '') + ' ' + (r.form_basvuru_soyad || '')).trim() || '-') + '</td>' +
                    '<td><small>' + esc(r.form_adi || '-') + '</small></td>' +
                    (firmaGoster ? '<td><small>' + esc(r.firma_adi || '-') + '</small></td>' : '') +
                    '<td><span class="badge text-bg-' + (renk[r.dogrulama_kod] || 'secondary') + '">' + esc(r.dogrulama_adi || '-') + '</span></td>' +
                    '</tr>';
            });
            $('#tbl_son_basvurular').html(html);
        });
    }

    function loadBelgeler() {
        istek('belgeler', function(res) {
            const d = res.data;
            let html = '';
            d.durumlar.forEach(function(r) {
                html += '<div class="belge-durum">' +
                    '<span><i class="' + esc(r.onay_durumu_ikon || 'bi bi-circle') + ' text-' + esc(r.onay_durumu_renk || 'secondary') + ' me-2"></i>' + esc(r.onay_durumu_adi) + '</span>' +
                    '<span class="badge text-bg-' + esc(r.onay_durumu_renk || 'secondary') + '">' + sayi(r.adet) + '</span>' +
                    '</div>';
            });
            $('#belge_durumlar').html(html || '<div class="text-center text-muted py-3">Kayıt bulunamadı</div>');

            if (!d.yaklasan.length) {
                $('#belge_yaklasan').html('');
                return;
            }
            let y = '<h6 class="text-muted small text-uppercase mb-2">Süresi Dolan / Yaklaşan</h6>';
            d.yaklasan.forEach(function(r) {
                const g = parseInt(r.kalan_gun, 10);
                const etiket = g < 0 ? '<span class="badge text-bg-danger">Doldu</span>' : '<span class="badge text-bg-warning">' + g + ' gün</span>';
                y += '<div class="d-flex justify-content-between align-items-center small py-1">' +
                    '<span>' + esc(r.firma_belge_baslik || r.belge_tipi_adi || '-') +
                    (!tekFirma && !seciliFirma() ? '<br><span class="text-muted">' + esc(r.firma_adi) + '</span>' : '') + '</span>' +
                    '<span class="text-end text-nowrap ms-2">' + etiket + '<br><span class="text-muted">' + esc(r.bitis) + '</span></span>' +
                    '</div>';
            });
            $('#belge_yaklasan').html(y);
        });
    }

    function loadSozlesmeler() {
        istek('sozlesmeler', function(res) {
            if (!res.data.length) {
                $('#liste_sozlesmeler').html('<li class="list-group-item text-center text-muted py-3">Sözleşme kaydı yok</li>');
                return;
            }
            const yaklasan = parseInt(res.yaklasan_gun, 10) || 30;
            let html = '';
            res.data.forEach(function(r) {
                const g = parseInt(r.kalan_gun, 10);
                let durum;
                if (g < 0)              durum = '<span class="badge text-bg-danger">Süresi doldu</span>';
                else if (g <= yaklasan) durum = '<span class="badge text-bg-warning">' + g + ' gün kaldı</span>';
                else                    durum = '<span class="badge text-bg-success">' + g + ' gün kaldı</span>';
                html += '<li class="list-group-item">' +
                    '<div class="d-flex justify-content-between align-items-start">' +
                        '<div><div class="fw-semibold small">' + esc(r.firma_adi) + '</div>' +
                        '<small class="text-muted">' + esc(r.baslangic) + ' – ' + esc(r.bitis) + '</small></div>' +
                        '<div class="text-end">' + durum +
                        (r.odeme_durumu_adi ? '<br><span class="badge text-bg-' + esc(r.odeme_durumu_renk || 'secondary') + ' mt-1">' + esc(r.odeme_durumu_adi) + '</span>' : '') +
                        '</div>' +
                    '</div></li>';
            });
            $('#liste_sozlesmeler').html(html);
        });
    }
    </script>
</body>
</html>
