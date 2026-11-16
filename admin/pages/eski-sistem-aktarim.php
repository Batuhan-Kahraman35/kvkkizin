<?php
/**
 * Admin Panel - Eski Sistem Aktarım
 *
 * Eski sistemden (portal.ornek-kvkk.com / portal_db) firma bazlı seçimli taşıma:
 * firma + alt firmalar, kullanıcılar, form tanımları ve doldurulmuş formlar (onaylayanlar).
 * Yalnız Bilgi İşlem (departman_id = 1) kullanabilir.
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
require_once __DIR__ . '/../includes/EskiSistemAktarim.php';
requireAuth();

$user = Auth::user();
$db = Database::getInstance();

$currentPagefile = basename($_SERVER['PHP_SELF']);
$pagePermissions = PageAuth::checkPagePermissions($user['kullanici_id'], $user['departman_id'], $currentPagefile);

if (!$pagePermissions['has_access'] || (int)$user['departman_id'] !== 1) {
    PageAuth::accessDenied('Eski sistem aktarımı yalnız Bilgi İşlem yetkisiyle kullanılabilir.');
}

$pageinfo = $db->fetchOne("
    SELECT s.sayfalar_sayfa_adi, s.sayfalar_aciklama, m.menuler_menu_adi AS menu_adi
    FROM Menu_Sayfalar s
    LEFT JOIN Menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1
", ['%' . $currentPagefile]);

$pageTitle = $pageinfo['sayfalar_sayfa_adi'] ?? 'Eski Sistem Aktarım';
$menuAdi = $pageinfo['menu_adi'] ?? null;

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

// Aktarım sonuç etiketleri (rozet adı / rengi DB'den)
$aktarimSonuclari = [];
foreach ($db->fetchAll("SELECT aktarim_sonuc_kod, aktarim_sonuc_adi, aktarim_sonuc_renk
                        FROM dbo.tanim_AktarimSonuclari WHERE aktarim_sonuc_durum = 1") as $s) {
    $aktarimSonuclari[$s['aktarim_sonuc_kod']] = ['adi' => $s['aktarim_sonuc_adi'], 'renk' => $s['aktarim_sonuc_renk'] ?: 'secondary'];
}

// AJAX işlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    @set_time_limit(300);

    try {
        $aktarim = new EskiSistemAktarim($db, $user['kullanici_id']);
        $action = $_POST['action'] ?? '';

        switch ($action) {
            case 'stats':
                echo json_encode(['success' => true, 'data' => $aktarim->istatistik()]);
                break;

            case 'list':
                $sonuc = $aktarim->firmaListe($_POST);
                echo json_encode([
                    'draw'            => (int)($_POST['draw'] ?? 0),
                    'recordsTotal'    => $sonuc['recordsTotal'],
                    'recordsFiltered' => $sonuc['recordsFiltered'],
                    'data'            => $sonuc['data'],
                ]);
                break;

            case 'onizle':
                echo json_encode(['success' => true, 'data' => $aktarim->onizle((int)($_POST['co_id'] ?? 0))]);
                break;

            case 'aktar_yapi':
                if (!$pagePermissions['can_add']) {
                    throw new Exception('Ekleme yetkiniz bulunmamaktadır.');
                }
                $kullanicilar = json_decode($_POST['kullanicilar'] ?? '[]', true) ?: [];
                $firmaHedefler = json_decode($_POST['firma_hedef'] ?? '{}', true) ?: [];
                $sonuc = $aktarim->aktarYapi(
                    (array)($_POST['firmalar'] ?? []),
                    $kullanicilar,
                    (array)($_POST['formlar'] ?? []),
                    $firmaHedefler,
                    (array)($_POST['sms'] ?? [])
                );
                echo json_encode(['success' => true, 'data' => $sonuc]);
                break;

            case 'aktar_basvuru':
                if (!$pagePermissions['can_add']) {
                    throw new Exception('Ekleme yetkiniz bulunmamaktadır.');
                }
                $sonuc = $aktarim->aktarBasvuru((int)($_POST['form_id'] ?? 0), (int)($_POST['son_id'] ?? 0));
                echo json_encode(['success' => true, 'data' => $sonuc]);
                break;

            default:
                echo json_encode(['success' => false, 'message' => 'Geçersiz işlem!']);
        }
    } catch (Throwable $e) {
        error_log('Eski sistem aktarım: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
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
        .onizleme-tablo td, .onizleme-tablo th { vertical-align: middle; font-size: .9rem; }
        .onizleme-tablo .form-switch { margin-bottom: 0; }
        .onizleme-tablo input.eposta { min-width: 220px; }
        .onizleme-tablo .departman-hucre { min-width: 170px; }
        .sayac { white-space: nowrap; }
        #aktarimRapor { max-height: 320px; overflow-y: auto; }
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
                                <span class="info-box-icon text-bg-primary shadow-sm"><i class="bi bi-buildings"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Firma (taşınan / eski)</span>
                                    <span class="info-box-number" id="stat-firma">-</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-success shadow-sm"><i class="bi bi-people"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Kullanıcı (taşınan / eski)</span>
                                    <span class="info-box-number" id="stat-kullanici">-</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-warning shadow-sm"><i class="bi bi-ui-checks"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Form (taşınan / eski)</span>
                                    <span class="info-box-number" id="stat-form">-</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-info shadow-sm"><i class="bi bi-person-check"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Onaylayan (taşınan / eski)</span>
                                    <span class="info-box-number" id="stat-basvuru">-</span>
                                </div>
                            </div>
                        </div>
                    </div>

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
                                        <label class="form-label">Ara (Firma adı / ünvan / ID)</label>
                                        <input type="text" class="form-control" id="filter_arama" placeholder="Anahtar kelime...">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Firma Tipi</label>
                                        <select class="form-select" id="filter_tip">
                                            <option value="">Tümü</option>
                                            <option value="ana">Ana firma</option>
                                            <option value="alt">Alt firma</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Aktarım Durumu</label>
                                        <select class="form-select" id="filter_durum">
                                            <option value="">Tümü</option>
                                            <option value="tasinmadi">Taşınmadı</option>
                                            <option value="tasindi">Taşındı</option>
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

                    <!-- Eski Sistem Firmaları -->
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">Eski Sistem Firmaları <small class="text-muted">(portal.ornek-kvkk.com)</small></h3>
                        </div>
                        <div class="card-body">
                            <table id="firmaTable" class="table table-bordered table-striped w-100">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Firma</th>
                                        <th>Üst Firma</th>
                                        <th>Kullanıcı</th>
                                        <th>Form</th>
                                        <th>Onaylayan</th>
                                        <th>Durum</th>
                                        <th>İşlem</th>
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

    <!-- Önizleme / Taşıma Modalı -->
    <div class="modal fade" id="onizlemeModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-box-arrow-in-down"></i> <span id="modalBaslik">Aktarım Önizleme</span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" id="modalKapatX"></button>
                </div>
                <div class="modal-body">
                    <div id="onizlemeYukleniyor" class="text-center py-5">
                        <div class="spinner-border text-primary"></div>
                        <div class="mt-2 text-muted">Eski sistemden okunuyor...</div>
                    </div>

                    <div id="onizlemeIcerik" class="d-none">
                        <!-- Firmalar -->
                        <h6 class="fw-bold"><i class="bi bi-buildings"></i> Firmalar</h6>
                        <div class="table-responsive mb-3">
                            <table class="table table-sm table-bordered onizleme-tablo" id="tblFirmalar">
                                <thead class="table-light">
                                    <tr><th class="text-center" style="width:60px">Seç</th><th>ID</th><th>Firma</th><th>Üst Firma</th><th style="min-width:280px">Hedef Firma</th></tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>

                        <!-- SMS Hesapları -->
                        <div class="d-flex justify-content-between align-items-center">
                            <h6 class="fw-bold mb-0"><i class="bi bi-chat-dots"></i> SMS Hesapları <span class="badge text-bg-secondary" id="smsSayi">0</span></h6>
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" role="switch" id="smsTumu" value="1" checked>
                                <label class="form-check-label" for="smsTumu">Tümünü seç</label>
                            </div>
                        </div>
                        <div class="table-responsive mt-2 mb-3">
                            <table class="table table-sm table-bordered onizleme-tablo" id="tblSms">
                                <thead class="table-light">
                                    <tr><th class="text-center" style="width:60px">Seç</th><th>Firma</th><th>Kullanıcı Adı</th><th>Gönderici</th><th>Eski Panel</th><th>Yeni Operatör</th><th>Hedef</th></tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>

                        <!-- Kullanıcılar -->
                        <div class="d-flex justify-content-between align-items-center">
                            <h6 class="fw-bold mb-0"><i class="bi bi-people"></i> Kullanıcılar <span class="badge text-bg-secondary" id="kulSayi">0</span></h6>
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" role="switch" id="kulTumu" value="1" checked>
                                <label class="form-check-label" for="kulTumu">Tümünü seç</label>
                            </div>
                        </div>
                        <div class="alert alert-light border small py-2 mt-2 mb-2">
                            <i class="bi bi-info-circle"></i> Yeni sistemde giriş e-posta ile yapılır; e-postası olmayan kullanıcıya <b>kullanıcı adı@site alan adı</b> otomatik verilir (<span class="badge text-bg-warning">Otomatik</span>), istenirse değiştirilebilir.
                            Eski şifreler (MD5) taşınamaz: rastgele şifre atanır, kullanıcı <b>Şifremi Unuttum</b> ile yeni şifre alır.
                        </div>
                        <div class="table-responsive mb-3">
                            <table class="table table-sm table-bordered onizleme-tablo" id="tblKullanicilar">
                                <thead class="table-light">
                                    <tr><th class="text-center" style="width:60px">Seç</th><th>Kullanıcı</th><th>Firma</th><th>Eski Yetki</th><th>E-posta</th><th>Departman</th><th>Hedef</th></tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>

                        <!-- Formlar -->
                        <div class="d-flex justify-content-between align-items-center">
                            <h6 class="fw-bold mb-0"><i class="bi bi-ui-checks"></i> Formlar <span class="badge text-bg-secondary" id="formSayi">0</span></h6>
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" role="switch" id="formTumu" value="1" checked>
                                <label class="form-check-label" for="formTumu">Tümünü seç</label>
                            </div>
                        </div>
                        <div class="table-responsive mt-2 mb-3">
                            <table class="table table-sm table-bordered onizleme-tablo" id="tblFormlar">
                                <thead class="table-light">
                                    <tr><th class="text-center" style="width:60px">Seç</th><th>Form</th><th>Firma</th><th>Dil</th><th>Alan</th><th>Onaylayan</th><th>Hedef</th></tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>

                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" id="basvuruTasi" value="1" checked>
                            <label class="form-check-label" for="basvuruTasi">
                                Seçili formların <b>doldurulmuş kayıtlarını (onaylayanları)</b> da taşı
                                <small class="text-muted">— KVKK/açık rıza/ETK onayları, SMS doğrulama, İYS izinleri dahil</small>
                            </label>
                        </div>
                    </div>

                    <!-- İlerleme ve rapor -->
                    <div id="aktarimAlani" class="d-none mt-3">
                        <hr>
                        <h6 class="fw-bold"><i class="bi bi-list-check"></i> Aktarım Sonucu</h6>
                        <div id="basvuruIlerleme" class="d-none mb-3">
                            <div class="d-flex justify-content-between small mb-1">
                                <span id="ilerlemeMetin">Onaylayanlar taşınıyor...</span>
                                <span id="ilerlemeSayi">0 / 0</span>
                            </div>
                            <div class="progress" style="height: 20px;">
                                <div class="progress-bar progress-bar-striped progress-bar-animated" id="ilerlemeBar" style="width: 0%">0%</div>
                            </div>
                        </div>
                        <div id="aktarimRapor">
                            <table class="table table-sm table-bordered onizleme-tablo mb-0">
                                <thead class="table-light"><tr><th>Tür</th><th>Eski ID</th><th>Ad</th><th>Sonuç</th><th>Açıklama</th><th>Yeni ID</th></tr></thead>
                                <tbody id="raporGovde"></tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" id="btnKapat">Kapat</button>
                    <button type="button" class="btn btn-primary" id="btnTasi" disabled>
                        <i class="bi bi-box-arrow-in-down"></i> Seçilenleri Taşı
                    </button>
                </div>
            </div>
        </div>
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
        let onizleme = null;     // son önizleme verisi
        let aktarimSuruyor = false;
        const SONUC = <?= json_encode($aktarimSonuclari, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
        const sonucEtiket = kod => SONUC[kod] || { adi: kod, renk: 'secondary' };

        const esc = s => $('<span>').text(s == null ? '' : String(s)).html();
        const sayac = (tasinan, toplam) => {
            const renk = toplam === 0 ? 'text-muted' : (tasinan >= toplam ? 'text-success' : (tasinan > 0 ? 'text-warning' : ''));
            return `<span class="sayac ${renk}">${tasinan.toLocaleString('tr-TR')} / ${toplam.toLocaleString('tr-TR')}</span>`;
        };
        const durumRozet = (adi, renk) => adi
            ? `<span class="badge text-bg-${esc(renk)}">${esc(adi)}</span>`
            : '<span class="badge text-bg-light border">Taşınmadı</span>';
        const select2Dil = { noResults: () => 'Sonuç bulunamadı', searching: () => 'Aranıyor...' };

        function loadStats() {
            $.post('', { action: 'stats' }, r => {
                if (!r.success) return;
                const f = o => `${o.tasinan.toLocaleString('tr-TR')} / ${o.kaynak.toLocaleString('tr-TR')}`;
                $('#stat-firma').text(f(r.data.firma));
                $('#stat-kullanici').text(f(r.data.kullanici));
                $('#stat-form').text(f(r.data.form));
                $('#stat-basvuru').text(f(r.data.basvuru));
            }, 'json');
        }

        function initDataTable() {
            table = $('#firmaTable').DataTable({
                serverSide: true,
                processing: true,
                dom: 'lrtip',
                pageLength: 25,
                language: { url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json' },
                ajax: {
                    url: '',
                    type: 'POST',
                    data: d => ({ action: 'list', draw: d.draw, start: d.start, length: d.length, order: d.order, ...currentfilters }),
                    error: () => showToast('Liste yüklenemedi', 'error')
                },
                columns: [
                    { data: 'co_id' },
                    { data: null, render: r => `<strong>${esc(r.co_name)}</strong>${r.co_title ? `<br><small class="text-muted">${esc(r.co_title)}</small>` : ''}` },
                    { data: null, render: r => (r.ust_ad ? esc(r.ust_ad) : '-') + (r.alt_sayi ? ` <span class="badge text-bg-light border" title="Alt firma">${r.alt_sayi} alt</span>` : '') },
                    { data: null, orderable: false, render: r => sayac(r.kullanici_tasinan, r.kullanici_toplam) },
                    { data: null, orderable: false, render: r => sayac(r.form_tasinan, r.form_toplam) },
                    { data: null, orderable: false, render: r => sayac(r.basvuru_tasinan, r.basvuru_toplam) },
                    { data: null, orderable: false, render: r => durumRozet(r.durum_adi, r.durum_renk) + (r.hedef_firma_id ? ` <small class="text-muted">#${r.hedef_firma_id}</small>` : '') },
                    { data: null, orderable: false, className: 'text-center', render: r =>
                        `<button class="btn btn-sm btn-primary" onclick="onizle(${r.co_id})" title="Önizle / Taşı"><i class="bi bi-box-arrow-in-down"></i> Önizle</button>` }
                ],
                order: [[1, 'asc']],
                initComplete: () => loadStats()
            });
        }

        /* ---------------- Önizleme ---------------- */

        function onizle(coId) {
            onizleme = null;
            $('#modalBaslik').text('Aktarım Önizleme');
            $('#onizlemeYukleniyor').removeClass('d-none');
            $('#onizlemeIcerik, #aktarimAlani, #basvuruIlerleme').addClass('d-none');
            $('#raporGovde').empty();
            $('#btnTasi').prop('disabled', true).removeClass('d-none');
            $('#kulTumu, #formTumu, #smsTumu, #basvuruTasi').prop('checked', true);
            bootstrap.Modal.getOrCreateInstance('#onizlemeModal').show();

            $.post('', { action: 'onizle', co_id: coId }, r => {
                if (!r.success) {
                    showToast(r.message || 'Önizleme alınamadı', 'error');
                    bootstrap.Modal.getInstance('#onizlemeModal').hide();
                    return;
                }
                onizleme = r.data;
                onizlemeCiz();
            }, 'json').fail(() => {
                showToast('Sunucu hatası', 'error');
                bootstrap.Modal.getInstance('#onizlemeModal').hide();
            });
        }

        function onizlemeCiz() {
            const d = onizleme;
            $('#modalBaslik').text('Aktarım Önizleme — ' + (d.firmalar[0]?.co_name || ''));

            // Firmalar: taşınmamış firma için hedef seçilir (yeni oluştur / mevcut firmaya eşle)
            const hedefSecenek = d.hedef_firmalar.map(h =>
                `<option value="${h.id}">${esc(h.adi)} (#${h.id})${h.durum ? '' : ' — pasif'}</option>`).join('');
            $('#tblFirmalar tbody').html(d.firmalar.map(f => {
                let hedef;
                if (f.tasindi) {
                    const ad = d.hedef_firmalar.find(h => h.id === f.hedef_id)?.adi || '';
                    hedef = durumRozet(f.durum_adi, f.durum_renk) + ` <small>#${f.hedef_id} ${esc(ad)}</small>`;
                } else {
                    hedef = `<select class="form-select form-select-sm hedef-firma" data-co="${f.co_id}">
                        <option value="0">➕ Yeni firma oluştur</option>${hedefSecenek}</select>`;
                    if (f.oneri_tip === 'ayni') hedef += '<small class="d-block text-info"><i class="bi bi-check2-circle"></i> Aynı adla mevcut firma önerildi</small>';
                    if (f.oneri_tip === 'benzer') hedef += '<small class="d-block text-warning"><i class="bi bi-exclamation-triangle"></i> Benzer adlı firma önerildi, kontrol edin</small>';
                }
                if (f.uyari) hedef += `<br><small class="text-warning"><i class="bi bi-exclamation-triangle"></i> ${esc(f.uyari)}</small>`;
                return `<tr data-co="${f.co_id}">
                    <td><div class="form-check form-switch d-flex justify-content-center">
                        <input class="form-check-input sec-firma" type="checkbox" role="switch" value="${f.co_id}" ${f.tasindi ? 'disabled' : 'checked'}>
                        <label class="form-check-label"></label></div></td>
                    <td>${f.co_id}</td>
                    <td><strong>${esc(f.co_name)}</strong>${f.co_title ? `<br><small class="text-muted">${esc(f.co_title)}</small>` : ''}</td>
                    <td>${f.ust_ad ? esc(f.ust_ad) : '-'}</td>
                    <td>${hedef}</td></tr>`;
            }).join(''));
            d.firmalar.forEach(f => {
                if (!f.tasindi) $(`#tblFirmalar select.hedef-firma[data-co="${f.co_id}"]`).val(f.hedef_id || 0);
            });
            $('#tblFirmalar select.hedef-firma').select2({
                theme: 'bootstrap-5', width: '100%', dropdownParent: $('#onizlemeModal'), language: select2Dil
            });

            // SMS hesapları
            $('#smsSayi').text(d.sms.length);
            $('#tblSms tbody').html(d.sms.length ? d.sms.map(s => {
                let hedef = s.tasindi ? durumRozet(s.durum_adi, s.durum_renk) + ` <small>#${s.hedef_id}</small>`
                    : (s.sorun ? `<span class="badge text-bg-warning">Taşınamaz</span> <small>${esc(s.sorun)}</small>`
                        : (s.hesap_mevcut ? `<span class="badge text-bg-info">Hesap mevcut</span> <small>#${s.hedef_id} ile eşleşecek</small>`
                                          : '<span class="badge text-bg-light border">Yeni eklenecek</span>'));
                const kapali = s.tasindi || !!s.sorun;
                return `<tr>
                    <td><div class="form-check form-switch d-flex justify-content-center">
                        <input class="form-check-input sec-sms" type="checkbox" role="switch" value="${s.sms_id}" ${kapali ? 'disabled' : 'checked'}>
                        <label class="form-check-label"></label></div></td>
                    <td>${esc(s.firma_adi)}</td>
                    <td>${esc(s.kullanici_adi || '-')}</td>
                    <td>${esc(s.gonderici || '-')}</td>
                    <td><small>${esc(s.panel_adi)}</small></td>
                    <td>${s.operator_adi ? esc(s.operator_adi) : '<span class="text-muted">-</span>'}</td>
                    <td>${hedef}</td></tr>`;
            }).join('') : '<tr><td colspan="7" class="text-center text-muted">Bu firmalara ait SMS hesabı yok</td></tr>');

            // Kullanıcılar
            const depSecenek = d.departmanlar.map(x => `<option value="${x.departman_id}">${esc(x.departman_adi)}</option>`).join('');
            $('#kulSayi').text(d.kullanicilar.length);
            $('#tblKullanicilar tbody').html(d.kullanicilar.length ? d.kullanicilar.map(u => {
                const kilit = u.tasindi;
                let hedef = kilit ? durumRozet(u.durum_adi, u.durum_renk) + ` <small>#${u.hedef_id}</small>`
                    : (u.email_mevcut ? `<span class="badge text-bg-info">E-posta mevcut</span> <small>#${u.hedef_id} ile eşleşecek</small>`
                                      : '<span class="badge text-bg-light border">Yeni eklenecek</span>');
                if (u.silindi) hedef += ' <span class="badge text-bg-danger">Eski sistemde silinmiş</span>';
                return `<tr data-id="${u.u_id}">
                    <td><div class="form-check form-switch d-flex justify-content-center">
                        <input class="form-check-input sec-kul" type="checkbox" role="switch" value="${u.u_id}" ${kilit ? 'disabled' : (u.silindi ? '' : 'checked')}>
                        <label class="form-check-label"></label></div></td>
                    <td>${esc(u.u_name || '-')}<br><small class="text-muted">${esc(u.u_username)}${u.son_giris ? ' · son giriş ' + esc(u.son_giris.substring(0, 10)) : ''}</small></td>
                    <td>${esc(u.firma_adi)}</td>
                    <td><small>${esc(u.yetki)}</small></td>
                    <td><input type="email" class="form-control form-control-sm eposta" value="${esc(u.u_email)}" placeholder="Boş bırakılırsa otomatik üretilir" ${kilit ? 'disabled' : ''}>${u.email_otomatik ? '<span class="badge text-bg-warning mt-1">Otomatik</span> <small class="text-muted">eski sistemde e-posta yok</small>' : ''}</td>
                    <td class="departman-hucre"><select class="form-select form-select-sm departman" ${kilit ? 'disabled' : ''}>
                        <option value="">Seçiniz</option>${depSecenek}</select></td>
                    <td>${hedef}</td></tr>`;
            }).join('') : '<tr><td colspan="7" class="text-center text-muted">Bu firmalara bağlı kullanıcı yok</td></tr>');

            d.kullanicilar.forEach(u => {
                $(`#tblKullanicilar tr[data-id="${u.u_id}"] select.departman`).val(u.departman_id || '');
            });
            $('#tblKullanicilar select.departman').select2({
                theme: 'bootstrap-5', width: '100%', dropdownParent: $('#onizlemeModal'), language: select2Dil
            });

            // Formlar
            $('#formSayi').text(d.formlar.length);
            $('#tblFormlar tbody').html(d.formlar.length ? d.formlar.map(f => {
                const hedef = f.tasindi ? durumRozet(f.durum_adi, f.durum_renk) + ` <small>#${f.hedef_id}</small>`
                                        : '<span class="badge text-bg-light border">Yeni eklenecek</span>';
                // Taşınmış form da seçilebilir: kalan onaylayanları tamamlamak için
                const tamam = f.tasindi && f.basvuru_tasinan >= f.basvuru_toplam;
                return `<tr>
                    <td><div class="form-check form-switch d-flex justify-content-center">
                        <input class="form-check-input sec-form" type="checkbox" role="switch" value="${f.form_id}" ${tamam ? 'disabled' : 'checked'}>
                        <label class="form-check-label"></label></div></td>
                    <td><strong>${esc(f.form_ad)}</strong><br><small class="text-muted">#${f.form_id}${f.tarih ? ' · ' + esc(f.tarih.substring(0, 10)) : ''}${f.sms_onay ? ' · <i class="bi bi-phone"></i> SMS' : ''}</small></td>
                    <td>${esc(f.firma_adi)}</td>
                    <td>${esc(f.dil || '-')}</td>
                    <td>${f.alan_sayisi}</td>
                    <td>${sayac(f.basvuru_tasinan, f.basvuru_toplam)}</td>
                    <td>${hedef}</td></tr>`;
            }).join('') : '<tr><td colspan="7" class="text-center text-muted">Bu firmalara bağlı form yok</td></tr>');

            $('#onizlemeYukleniyor').addClass('d-none');
            $('#onizlemeIcerik').removeClass('d-none');
            $('#btnTasi').prop('disabled', false);
        }

        /* ---------------- Taşıma ---------------- */

        function seciliDegerler(sinif) {
            return $(`.${sinif}:checked:not(:disabled)`).map((_, el) => el.value).get();
        }

        function raporEkle(satirlar) {
            $('#raporGovde').append(satirlar.map(s => `<tr>
                <td>${esc(s.tur)}</td><td>${esc(s.kaynak_id)}</td><td>${esc(s.ad)}</td>
                <td><span class="badge text-bg-${esc(s.sonuc_renk)}">${esc(s.sonuc_adi)}</span></td>
                <td><small>${esc(s.mesaj || '')}</small></td><td>${s.hedef_id ? '#' + s.hedef_id : '-'}</td></tr>`).join(''));
        }

        function kilitle(durum) {
            aktarimSuruyor = durum;
            $('#btnKapat, #modalKapatX').prop('disabled', durum);
            if (durum) {
                // Yalnız açık olanları kilitle; taşınmış satırlar zaten kapalı kalır
                $('#onizlemeIcerik').find('input:not(:disabled), select:not(:disabled)').addClass('gecici-kilit').prop('disabled', true);
            } else {
                $('.gecici-kilit').prop('disabled', false).removeClass('gecici-kilit');
                $('#btnTasi').prop('disabled', false);
            }
        }

        function tasi() {
            const firmalar = seciliDegerler('sec-firma');
            const formlar = seciliDegerler('sec-form');
            const smsler = seciliDegerler('sec-sms');
            // Seçili firmaların hedefi: 0 = yeni firma, >0 = mevcut firmaya eşle
            const firmaHedef = {};
            $('#tblFirmalar select.hedef-firma').each((_, el) => { firmaHedef[$(el).data('co')] = parseInt(el.value, 10) || 0; });
            const eslenen = firmalar.filter(id => firmaHedef[id] > 0).length;
            const kullanicilar = $('#tblKullanicilar tbody tr').filter((_, tr) => $(tr).find('.sec-kul').is(':checked:not(:disabled)'))
                .map((_, tr) => ({
                    id: $(tr).data('id'),
                    email: $(tr).find('.eposta').val().trim(),
                    departman_id: $(tr).find('.departman').val()
                })).get();

            if (!firmalar.length && !kullanicilar.length && !formlar.length && !smsler.length) {
                showToast('Taşınacak kayıt seçilmedi', 'warning');
                return;
            }
            const epostasiz = kullanicilar.filter(k => !k.email).length;
            const basvuruTasi = $('#basvuruTasi').is(':checked');

            const metin = `${firmalar.length} firma, ${smsler.length} SMS hesabı, ${kullanicilar.length} kullanıcı, ${formlar.length} form` +
                (basvuruTasi ? ' ve formların onaylayanları' : '') + ' taşınacak.' +
                (eslenen ? `<br><span class="text-info">${eslenen} firma yeni açılmayacak, seçilen mevcut firmaya eşlenecek.</span>` : '') +
                (epostasiz ? `<br><span class="text-info">${epostasiz} kullanıcının e-postası boş; kullanıcı adından otomatik üretilecek.</span>` : '');

            Swal.fire({
                title: 'Aktarım başlatılsın mı?', html: metin, icon: 'question',
                showCancelButton: true, confirmButtonText: 'Evet, taşı', cancelButtonText: 'Vazgeç'
            }).then(res => {
                if (!res.isConfirmed) return;

                // Seçimler kilitlemeden önce okundu; şimdi kilitle
                const seciliForm = new Set(formlar.map(Number));
                kilitle(true);
                $('#btnTasi').prop('disabled', true);
                $('#aktarimAlani').removeClass('d-none');
                $('#raporGovde').empty();

                $.post('', {
                    action: 'aktar_yapi',
                    firmalar: firmalar,
                    formlar: formlar,
                    sms: smsler,
                    firma_hedef: JSON.stringify(firmaHedef),
                    kullanicilar: JSON.stringify(kullanicilar)
                }, r => {
                    if (!r.success) {
                        showToast(r.message || 'Aktarım başarısız, hiçbir kayıt yazılmadı', 'error');
                        kilitle(false);
                        return;
                    }
                    raporEkle(r.data.rapor);
                    // Yeni eklenen formların hedef ID'si rapordan alınır (önizlemede henüz yoktu)
                    r.data.rapor.filter(s => s.tur === 'Form' && s.hedef_id).forEach(s => {
                        const f = onizleme.formlar.find(x => x.form_id === Number(s.kaynak_id));
                        if (f) f.hedef_id = s.hedef_id;
                    });
                    const kuyruk = basvuruTasi
                        ? onizleme.formlar.filter(f => seciliForm.has(f.form_id) && r.data.basvuru_formlari.includes(f.form_id)
                                                       && f.basvuru_toplam > f.basvuru_tasinan)
                        : [];
                    if (kuyruk.length) {
                        basvurulariTasi(kuyruk);
                    } else {
                        aktarimBitti();
                    }
                }, 'json').fail(() => {
                    showToast('Sunucu hatası, hiçbir kayıt yazılmadı', 'error');
                    kilitle(false);
                });
            });
        }

        async function basvurulariTasi(kuyruk) {
            const toplam = kuyruk.reduce((t, f) => t + (f.basvuru_toplam - f.basvuru_tasinan), 0);
            let islenen = 0;
            $('#basvuruIlerleme').removeClass('d-none');
            const guncelle = (metin) => {
                const yuzde = toplam ? Math.min(100, Math.round(islenen * 100 / toplam)) : 100;
                $('#ilerlemeBar').css('width', yuzde + '%').text(yuzde + '%');
                $('#ilerlemeSayi').text(`${islenen.toLocaleString('tr-TR')} / ${toplam.toLocaleString('tr-TR')}`);
                if (metin) $('#ilerlemeMetin').text(metin);
            };
            guncelle('Onaylayanlar taşınıyor...');

            for (const f of kuyruk) {
                let sonId = 0, aktarilan = 0, mevcut = 0;
                guncelle(`"${f.form_ad}" onaylayanları taşınıyor...`);
                try {
                    while (true) {
                        const r = await $.post('', { action: 'aktar_basvuru', form_id: f.form_id, son_id: sonId }, null, 'json');
                        if (!r.success) throw new Error(r.message || 'Bilinmeyen hata');
                        aktarilan += r.data.aktarilan;
                        mevcut += r.data.mevcut;
                        islenen += r.data.aktarilan;
                        sonId = r.data.son_id;
                        guncelle();
                        if (r.data.bitti) break;
                    }
                    raporEkle([{ tur: 'Onaylayan', kaynak_id: f.form_id, ad: f.form_ad,
                                 sonuc_adi: sonucEtiket('eklendi').adi, sonuc_renk: sonucEtiket('eklendi').renk,
                                 mesaj: `${aktarilan.toLocaleString('tr-TR')} kayıt taşındı` + (mevcut ? `, ${mevcut} kayıt zaten vardı` : ''), hedef_id: f.hedef_id }]);
                } catch (e) {
                    raporEkle([{ tur: 'Onaylayan', kaynak_id: f.form_id, ad: f.form_ad,
                                 sonuc_adi: sonucEtiket('hata').adi, sonuc_renk: sonucEtiket('hata').renk,
                                 mesaj: `${aktarilan} kayıt taşındıktan sonra durdu: ${e.message || e.statusText || 'Sunucu hatası'} (tekrar başlatılırsa kaldığı yerden devam eder)` }]);
                }
            }
            $('#ilerlemeBar').removeClass('progress-bar-animated');
            guncelle('Onaylayanların aktarımı tamamlandı');
            aktarimBitti();
        }

        function aktarimBitti() {
            showToast('Aktarım tamamlandı', 'success');
            $('#btnTasi').addClass('d-none');
            aktarimSuruyor = false;
            $('#btnKapat, #modalKapatX').prop('disabled', false);
            table.ajax.reload(null, false);
            loadStats();
        }

        $(document).ready(() => {
            initDataTable();

            $('#filter_tip, #filter_durum').select2({ theme: 'bootstrap-5', width: '100%', language: select2Dil });

            $('#filterForm').on('submit', e => {
                e.preventDefault();
                currentfilters = { arama: $('#filter_arama').val(), tip: $('#filter_tip').val(), durum: $('#filter_durum').val() };
                table.ajax.reload();
            });
            $('#clearfilters').on('click', () => {
                $('#filterForm')[0].reset();
                $('#filter_tip, #filter_durum').val('').trigger('change');
                currentfilters = {};
                table.ajax.reload();
            });

            $('#kulTumu').on('change', function () { $('.sec-kul:not(:disabled)').prop('checked', this.checked); });
            $('#formTumu').on('change', function () { $('.sec-form:not(:disabled)').prop('checked', this.checked); });
            $('#smsTumu').on('change', function () { $('.sec-sms:not(:disabled)').prop('checked', this.checked); });
            $('#btnTasi').on('click', tasi);

            // Aktarım sürerken modal kapatılamaz / sayfa terk edilirken uyarı
            $('#onizlemeModal').on('hide.bs.modal', e => { if (aktarimSuruyor) e.preventDefault(); });
            window.addEventListener('beforeunload', e => { if (aktarimSuruyor) { e.preventDefault(); e.returnValue = ''; } });
        });
    </script>
</body>
</html>
