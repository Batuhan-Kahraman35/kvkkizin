<?php
/**
 * Admin Panel - Formlarım
 *
 * Müşteri (firma) kullanıcılarının kendi formlarını gördüğü sayfa.
 * Form bağlantısı, QR kod, izinli müşteri listesi, Excel şablonu ile toplu
 * izinli müşteri yükleme ve telefon listesiyle çoklu izin kontrolü.
 * Form düzenleme / silme Form Yönetimi'nde kalır.
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
require_once __DIR__ . '/../includes/TelefonHelper.php';
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

$pageTitle = $pageinfo['sayfalar_sayfa_adi'] ?? 'Formlarım';
$menuAdi = $pageinfo['menu_adi'] ?? null;

$permissions = PageAuth::checkPagePermissions($user['kullanici_id'], $user['departman_id'], $currentPagefile);
if (!$permissions['has_access']) {
    PageAuth::accessDenied($permissions['error'] ?? 'Bu sayfaya erişim yetkiniz yok!');
}

// Kullanıcının görebileceği firmalar (null = sınırsız)
$firmaScopeIds = PageAuth::firmaScopeIds($permissions, $user['firma_id'] ?? null);
$tekFirma = ($firmaScopeIds !== null && count($firmaScopeIds) <= 1);

// Müşteri Listesi bağlantısı için İzinli Müşteriler yetkisi
$musteriYetki = PageAuth::checkPagePermissions($user['kullanici_id'], $user['departman_id'], 'izinli-musteriler.php');
$apiYetki = PageAuth::checkPagePermissions($user['kullanici_id'], $user['departman_id'], 'api-yonetimi.php');

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Portal';

$formLinkKoku = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/form/';

const TOPLU_SATIR_SINIRI = 5000;

/** Formu firma kapsamıyla getirir */
function kapsamdakiForm($db, $firmaScopeIds, $formId) {
    $form = $db->fetchOne("SELECT form_id, firma_id, form_adi, form_durum FROM dbo.Formlar WHERE form_id = ?", [(int)$formId]);
    if (!$form) {
        return null;
    }
    if ($firmaScopeIds !== null && !in_array((int)$form['firma_id'], $firmaScopeIds, true)) {
        return null;
    }
    return $form;
}

/** Tek değer döndüren ham sorgu (OUTPUT INSERTED için) */
function tekDeger($db, $sql, array $params) {
    $stmt = sqlsrv_query($db->getConnection(), $sql, $params);
    if ($stmt === false) {
        $e = sqlsrv_errors(SQLSRV_ERR_ERRORS);
        throw new Exception('Veritabanı hatası: ' . ($e[0]['message'] ?? 'bilinmeyen'));
    }
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_NUMERIC);
    sqlsrv_free_stmt($stmt);
    return $row ? $row[0] : null;
}

/** JSON dizi parametresini okur */
function jsonDizi($anahtar) {
    $veri = json_decode((string)($_POST[$anahtar] ?? ''), true);
    return is_array($veri) ? $veri : [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {
            case 'list':
                [$scopeSql, $params] = PageAuth::firmaScopeWhere($firmaScopeIds, 'f.firma_id');
                $where = [$scopeSql];

                $search = trim($_POST['search'] ?? '');
                if ($search !== '') {
                    $where[] = '(f.form_adi LIKE ? OR fi.firma_adi LIKE ?)';
                    array_push($params, "%$search%", "%$search%");
                }
                foreach (['firma_id' => 'f.firma_id', 'form_dil_id' => 'f.form_dil_id'] as $alan => $kolon) {
                    if (($_POST[$alan] ?? '') !== '') {
                        $where[] = "$kolon = ?";
                        $params[] = (int)$_POST[$alan];
                    }
                }
                if (in_array((string)($_POST['status'] ?? ''), ['0', '1'], true)) {
                    $where[] = 'f.form_durum = ?';
                    $params[] = (int)$_POST['status'];
                }

                $formlar = $db->fetchAll("
                    SELECT f.form_id, f.form_adi, f.form_uniqid, f.form_durum, f.form_sms_onay,
                           fi.firma_adi, d.form_dil_adi,
                           ISNULL(b.basvuru_sayisi, 0) AS basvuru_sayisi
                    FROM dbo.Formlar f
                    INNER JOIN dbo.Firmalar fi ON fi.firma_id = f.firma_id
                    LEFT JOIN dbo.tanim_FormDilleri d ON d.form_dil_id = f.form_dil_id
                    OUTER APPLY (
                        SELECT COUNT(*) AS basvuru_sayisi
                        FROM dbo.Form_Basvurulari x
                        WHERE x.form_id = f.form_id AND x.form_basvuru_durum = 1
                    ) b
                    WHERE " . implode(' AND ', $where) . "
                    ORDER BY f.form_id DESC
                ", $params);

                echo json_encode(['success' => true, 'data' => $formlar ?: []], JSON_UNESCAPED_UNICODE);
                break;

            case 'stats':
                [$scopeSql, $scopeParams] = PageAuth::firmaScopeWhere($firmaScopeIds, 'firma_id');
                $form = $db->fetchOne("
                    SELECT COUNT(*) AS toplam, SUM(CASE WHEN form_durum = 1 THEN 1 ELSE 0 END) AS aktif
                    FROM dbo.Formlar WHERE $scopeSql
                ", $scopeParams);
                $basvuru = $db->fetchOne("
                    SELECT COUNT(*) AS toplam,
                           SUM(CASE WHEN form_basvuru_tarihi >= DATEFROMPARTS(YEAR(GETDATE()), MONTH(GETDATE()), 1) THEN 1 ELSE 0 END) AS bu_ay
                    FROM dbo.Form_Basvurulari
                    WHERE form_basvuru_durum = 1 AND $scopeSql
                ", $scopeParams);
                echo json_encode(['success' => true, 'data' => [
                    'form_toplam'    => (int)($form['toplam'] ?? 0),
                    'form_aktif'     => (int)($form['aktif'] ?? 0),
                    'basvuru_toplam' => (int)($basvuru['toplam'] ?? 0),
                    'basvuru_bu_ay'  => (int)($basvuru['bu_ay'] ?? 0),
                ]]);
                break;

            case 'filtre_list':
                [$scopeSql, $scopeParams] = PageAuth::firmaScopeWhere($firmaScopeIds, 'firma_id');
                $firmalar = $tekFirma ? [] : $db->fetchAll("
                    SELECT firma_id, firma_adi FROM dbo.Firmalar
                    WHERE firma_durum = 1 AND $scopeSql ORDER BY firma_adi
                ", $scopeParams);
                $diller = $db->fetchAll("
                    SELECT form_dil_id, form_dil_adi FROM dbo.tanim_FormDilleri
                    WHERE form_dil_durum = 1 ORDER BY form_dil_sira_no, form_dil_adi
                ");
                echo json_encode(['success' => true, 'data' => ['firmalar' => $firmalar ?: [], 'diller' => $diller ?: []]], JSON_UNESCAPED_UNICODE);
                break;

            case 'sablon_yukle':
                if (!$permissions['can_add']) {
                    echo json_encode(['success' => false, 'message' => 'Yükleme yetkiniz yok']);
                    break;
                }
                $form = kapsamdakiForm($db, $firmaScopeIds, $_POST['form_id'] ?? 0);
                if (!$form) {
                    echo json_encode(['success' => false, 'message' => 'Form bulunamadı veya erişim yetkiniz yok']);
                    break;
                }

                $satirlar = jsonDizi('satirlar');
                if (!$satirlar) {
                    echo json_encode(['success' => false, 'message' => 'Dosyada yüklenecek satır bulunamadı']);
                    break;
                }
                if (count($satirlar) > TOPLU_SATIR_SINIRI) {
                    echo json_encode(['success' => false, 'message' => 'Tek seferde en fazla ' . TOPLU_SATIR_SINIRI . ' satır yüklenebilir']);
                    break;
                }

                $durum = $db->fetchOne("
                    SELECT form_dogrulama_durum_id FROM dbo.tanim_FormDogrulamaDurumlari
                    WHERE form_dogrulama_durum_kod = 'excel' AND form_dogrulama_durum_durum = 1
                ");
                if (!$durum) {
                    echo json_encode(['success' => false, 'message' => "Doğrulama durumu tanımı ('excel') bulunamadı. Sistem yöneticisine bildirin."]);
                    break;
                }
                $durumId = (int)$durum['form_dogrulama_durum_id'];

                $kvkk = !empty($_POST['izin_kvkk']) ? 1 : 0;
                $etk  = !empty($_POST['izin_etk']) ? 1 : 0;
                $gps  = !empty($_POST['izin_gps']) ? 1 : 0;

                // Satır doğrulama: telefon zorunlu, dosya içi tekrar atlanır
                $gecerli = [];
                $hatalar = [];
                $dosyaTekrar = 0;
                foreach ($satirlar as $i => $s) {
                    $satirNo = $i + 2; // 1. satır başlık
                    $ad = mb_substr(trim((string)($s['ad'] ?? '')), 0, 150);
                    $soyad = mb_substr(trim((string)($s['soyad'] ?? '')), 0, 150);
                    $telefon = telefonNormalizeEtGuvenli($s['telefon'] ?? '', $hata);
                    if (!$telefon) {
                        $hatalar[] = ['satir' => $satirNo, 'deger' => (string)($s['telefon'] ?? ''), 'mesaj' => $hata ?: 'Telefon boş'];
                        continue;
                    }
                    if (isset($gecerli[$telefon])) {
                        $dosyaTekrar++;
                        continue;
                    }
                    $gecerli[$telefon] = ['ad' => $ad !== '' ? $ad : null, 'soyad' => $soyad !== '' ? $soyad : null];
                }

                // Formda zaten aktif kaydı olan telefonlar atlanır
                $mevcut = [];
                foreach (array_chunk(array_keys($gecerli), 1000) as $parca) {
                    $yer = implode(',', array_fill(0, count($parca), '?'));
                    $rows = $db->fetchAll("
                        SELECT DISTINCT form_basvuru_telefon FROM dbo.Form_Basvurulari
                        WHERE form_id = ? AND form_basvuru_durum = 1 AND form_basvuru_telefon IN ($yer)
                    ", array_merge([(int)$form['form_id']], $parca)) ?: [];
                    foreach ($rows as $r) {
                        $mevcut[$r['form_basvuru_telefon']] = true;
                    }
                }

                // Alan değerleri: ad / soyad / cep telefonu
                $alanIdleri = [];
                foreach ($db->fetchAll("SELECT form_alan_id, form_alan_kod FROM dbo.tanim_FormAlanlari WHERE form_alan_kod IN ('ad', 'soyad', 'cep_telefonu')") ?: [] as $a) {
                    $alanIdleri[$a['form_alan_kod']] = (int)$a['form_alan_id'];
                }

                $simdi = date('Y-m-d H:i:s');
                $eklenen = 0;
                $degerSatirlari = [];

                $conn = $db->getConnection();
                sqlsrv_begin_transaction($conn);
                try {
                    foreach ($gecerli as $telefon => $k) {
                        if (isset($mevcut[$telefon])) {
                            continue;
                        }
                        $basvuruId = (int)tekDeger($db, "
                            INSERT INTO dbo.Form_Basvurulari
                                (form_id, firma_id, form_basvuru_ad, form_basvuru_soyad, form_basvuru_telefon,
                                 form_basvuru_aydinlatma_onay, form_basvuru_acik_riza_onay, form_basvuru_kvkk_onay,
                                 form_basvuru_etk_onay, form_basvuru_gps_onay, form_basvuru_dogrulama_durumu,
                                 form_basvuru_tarihi, form_basvuru_durum, form_basvuru_olusturan_kullanici_id, form_basvuru_olusturma_tarihi)
                            OUTPUT INSERTED.form_basvuru_id
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)
                        ", [
                            (int)$form['form_id'], (int)$form['firma_id'], $k['ad'], $k['soyad'], $telefon,
                            $kvkk, $kvkk, $kvkk, $etk, $gps, $durumId,
                            $simdi, (int)$user['kullanici_id'], $simdi,
                        ]);
                        $eklenen++;

                        foreach (['ad' => $k['ad'], 'soyad' => $k['soyad'], 'cep_telefonu' => $telefon] as $kod => $deger) {
                            if ($deger !== null && isset($alanIdleri[$kod])) {
                                $degerSatirlari[] = [$basvuruId, $alanIdleri[$kod], $deger];
                            }
                        }
                    }

                    // Değerler toplu yazılır (parça başı 400 satır × 5 parametre < 2100)
                    foreach (array_chunk($degerSatirlari, 400) as $parca) {
                        $yer = [];
                        $prm = [];
                        foreach ($parca as [$bId, $aId, $deger]) {
                            $yer[] = '(?, ?, ?, ?, ?)';
                            array_push($prm, $bId, $aId, $deger, (int)$user['kullanici_id'], $simdi);
                        }
                        $db->execute("
                            INSERT INTO dbo.Form_Basvuru_Degerleri
                                (form_basvuru_id, form_alan_id, form_basvuru_deger, form_basvuru_deger_olusturan_kullanici_id, form_basvuru_deger_olusturma_tarihi)
                            VALUES " . implode(', ', $yer), $prm);
                    }

                    sqlsrv_commit($conn);
                } catch (Throwable $e) {
                    sqlsrv_rollback($conn);
                    throw $e;
                }

                echo json_encode(['success' => true, 'data' => [
                    'toplam'        => count($satirlar),
                    'eklenen'       => $eklenen,
                    'mevcut'        => count(array_intersect_key($gecerli, $mevcut)),
                    'dosya_tekrar'  => $dosyaTekrar,
                    'hatali'        => count($hatalar),
                    'hatalar'       => array_slice($hatalar, 0, 100),
                ]], JSON_UNESCAPED_UNICODE);
                break;

            case 'coklu_kontrol':
                $form = kapsamdakiForm($db, $firmaScopeIds, $_POST['form_id'] ?? 0);
                if (!$form) {
                    echo json_encode(['success' => false, 'message' => 'Form bulunamadı veya erişim yetkiniz yok']);
                    break;
                }
                $girdiler = array_values(array_filter(array_map(fn($v) => trim((string)$v), jsonDizi('telefonlar')), fn($v) => $v !== ''));
                if (!$girdiler) {
                    echo json_encode(['success' => false, 'message' => 'Kontrol edilecek telefon bulunamadı']);
                    break;
                }
                if (count($girdiler) > TOPLU_SATIR_SINIRI) {
                    echo json_encode(['success' => false, 'message' => 'Tek seferde en fazla ' . TOPLU_SATIR_SINIRI . ' numara kontrol edilebilir']);
                    break;
                }

                $normal = [];
                foreach ($girdiler as $g) {
                    $normal[$g] = telefonNormalizeEtGuvenli($g);
                }
                $telefonlar = array_values(array_unique(array_filter($normal)));

                // Her telefonun bu formdaki en güncel aktif kaydı
                $kayitlar = [];
                foreach (array_chunk($telefonlar, 1000) as $parca) {
                    $yer = implode(',', array_fill(0, count($parca), '?'));
                    $rows = $db->fetchAll("
                        WITH Son AS (
                            SELECT b.*, ROW_NUMBER() OVER (PARTITION BY b.form_basvuru_telefon
                                                           ORDER BY b.form_basvuru_tarihi DESC, b.form_basvuru_id DESC) AS sira
                            FROM dbo.Form_Basvurulari b
                            WHERE b.form_id = ? AND b.form_basvuru_durum = 1 AND b.form_basvuru_telefon IN ($yer)
                        )
                        SELECT s.form_basvuru_telefon AS telefon, s.form_basvuru_ad AS ad, s.form_basvuru_soyad AS soyad,
                               s.form_basvuru_kvkk_onay AS kvkk, s.form_basvuru_etk_onay AS etk, s.form_basvuru_gps_onay AS gps,
                               CONVERT(VARCHAR(19), s.form_basvuru_tarihi, 120) AS tarih,
                               d.form_dogrulama_durum_adi AS dogrulama_adi, d.form_dogrulama_durum_renk AS dogrulama_renk
                        FROM Son s
                        LEFT JOIN dbo.tanim_FormDogrulamaDurumlari d ON d.form_dogrulama_durum_id = s.form_basvuru_dogrulama_durumu
                        WHERE s.sira = 1
                    ", array_merge([(int)$form['form_id']], $parca)) ?: [];
                    foreach ($rows as $r) {
                        $kayitlar[$r['telefon']] = $r;
                    }
                }

                $sonuc = [];
                $ozet = ['kayitli' => 0, 'kayit_yok' => 0, 'gecersiz' => 0];
                foreach ($girdiler as $g) {
                    $tel = $normal[$g];
                    if (!$tel) {
                        $sonuc[] = ['girilen' => $g, 'telefon' => null, 'durum' => 'gecersiz'];
                        $ozet['gecersiz']++;
                    } elseif (isset($kayitlar[$tel])) {
                        $sonuc[] = ['girilen' => $g, 'durum' => 'kayitli'] + $kayitlar[$tel];
                        $ozet['kayitli']++;
                    } else {
                        $sonuc[] = ['girilen' => $g, 'telefon' => $tel, 'durum' => 'kayit_yok'];
                        $ozet['kayit_yok']++;
                    }
                }

                echo json_encode(['success' => true, 'data' => ['ozet' => $ozet, 'sonuc' => $sonuc]], JSON_UNESCAPED_UNICODE);
                break;

            default:
                echo json_encode(['success' => false, 'message' => 'Geçersiz işlem']);
        }
    } catch (Throwable $e) {
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
        .status-badge { padding: .25rem .5rem; border-radius: .25rem; font-size: .875rem; }
        .status-active { background-color: #d4edda; color: #155724; }
        .status-inactive { background-color: #f8d7da; color: #721c24; }
        #qrKutu { display: inline-block; padding: 12px; background: #fff; border-radius: .5rem; }
        .form-link { font-family: monospace; font-size: .85rem; word-break: break-all; }
        #formTable td { vertical-align: middle; }
        .sonuc-kutu { max-height: 55vh; overflow: auto; }
        .izin-ikon { font-size: 1.1rem; }
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
                        <div class="col-md-3 col-sm-6">
                            <div class="info-box text-bg-primary">
                                <span class="info-box-icon"><i class="bi bi-ui-checks"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Toplam Form</span>
                                    <span class="info-box-number" id="stat-form-toplam">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 col-sm-6">
                            <div class="info-box text-bg-success">
                                <span class="info-box-icon"><i class="bi bi-check-circle"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Aktif Form</span>
                                    <span class="info-box-number" id="stat-form-aktif">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 col-sm-6">
                            <div class="info-box text-bg-info">
                                <span class="info-box-icon"><i class="bi bi-people"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">İzinli Müşteri</span>
                                    <span class="info-box-number" id="stat-basvuru-toplam">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 col-sm-6">
                            <div class="info-box text-bg-warning">
                                <span class="info-box-icon"><i class="bi bi-calendar-check"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Bu Ay</span>
                                    <span class="info-box-number" id="stat-basvuru-bu-ay">0</span>
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
                                    <div class="col-md-<?= $tekFirma ? '4' : '3' ?>">
                                        <label class="form-label" for="filter_search">Ara</label>
                                        <input type="text" class="form-control" id="filter_search" placeholder="Form adı...">
                                    </div>
                                    <?php if (!$tekFirma): ?>
                                    <div class="col-md-3">
                                        <label class="form-label" for="filter_firma_id">Firma</label>
                                        <select class="form-select select2-filter" id="filter_firma_id" style="width: 100%;">
                                            <option value="">Tümü</option>
                                        </select>
                                    </div>
                                    <?php endif; ?>
                                    <div class="col-md-<?= $tekFirma ? '4' : '3' ?>">
                                        <label class="form-label" for="filter_form_dil_id">Dil</label>
                                        <select class="form-select select2-filter" id="filter_form_dil_id" style="width: 100%;">
                                            <option value="">Tümü</option>
                                        </select>
                                    </div>
                                    <div class="col-md-<?= $tekFirma ? '4' : '3' ?>">
                                        <label class="form-label" for="filter_status">Durum</label>
                                        <select class="form-select select2-filter" id="filter_status" style="width: 100%;">
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

                    <!-- Liste -->
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">Formlar</h3>
                        </div>
                        <div class="card-body">
                            <table id="formTable" class="table table-bordered table-striped table-hover" style="width:100%">
                                <thead>
                                    <tr>
                                        <th>Form Adı</th>
                                        <th>Dil</th>
                                        <th class="text-center">İzinli Müşteri</th>
                                        <th class="text-center">Durum</th>
                                        <th class="text-center">Web Link</th>
                                        <th class="text-center">QR Kodu</th>
                                        <th class="text-center">İşlem</th>
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
                    <button type="button" class="btn btn-success" onclick="qrIndir()"><i class="bi bi-download"></i> PNG İndir</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Şablon Yükle Modal -->
    <div class="modal fade" id="yukleModal" tabindex="-1" aria-labelledby="yukleModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="yukleModalLabel">Şablon Yükle</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="yukle_form_id">
                    <div class="alert alert-info py-2 small mb-3">
                        <i class="bi bi-info-circle"></i> Şablondaki <strong>Ad, Soyad, Telefon</strong> kolonları okunur. Telefon zorunludur.
                        Bu formda aynı telefonla kaydı olan kişiler tekrar eklenmez. Tek seferde en fazla <?= TOPLU_SATIR_SINIRI ?> satır yüklenebilir.
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="yukle_dosya">Excel Dosyası <span class="text-danger">*</span></label>
                        <input type="file" class="form-control" id="yukle_dosya" accept=".xlsx,.xls,.csv">
                    </div>
                    <label class="form-label">Bu kişilerden alınan izinler</label>
                    <div class="d-flex gap-4 flex-wrap mb-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" id="izin_kvkk" name="izin_kvkk" value="1" checked>
                            <label class="form-check-label" for="izin_kvkk">KVKK (Aydınlatma + Açık Rıza)</label>
                        </div>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" id="izin_etk" name="izin_etk" value="1">
                            <label class="form-check-label" for="izin_etk">ETK</label>
                        </div>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" id="izin_gps" name="izin_gps" value="1">
                            <label class="form-check-label" for="izin_gps">GPS</label>
                        </div>
                    </div>
                    <div id="yukleOnizleme" class="d-none">
                        <div class="small text-muted mb-1" id="yukleOnizlemeBaslik"></div>
                        <div class="sonuc-kutu border rounded" style="max-height: 35vh;">
                            <table class="table table-sm table-striped mb-0">
                                <thead class="table-light"><tr><th>#</th><th>Ad</th><th>Soyad</th><th>Telefon</th></tr></thead>
                                <tbody id="yukleOnizlemeGovde"></tbody>
                            </table>
                        </div>
                    </div>
                    <div id="yukleSonuc" class="d-none mt-3"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
                    <button type="button" class="btn btn-primary" id="yukleBtn" disabled onclick="sablonGonder()">
                        <i class="bi bi-upload"></i> Yükle
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Çoklu İzin Kontrol Modal -->
    <div class="modal fade" id="kontrolModal" tabindex="-1" aria-labelledby="kontrolModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="kontrolModalLabel">Çoklu İzin Kontrol</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="kontrol_form_id">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="kontrol_metin">Telefon numaraları <small class="text-muted">(her satıra bir numara)</small></label>
                            <textarea class="form-control font-monospace" id="kontrol_metin" rows="6" placeholder="0532 123 45 67&#10;5551234567"></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="kontrol_dosya">veya Excel dosyası</label>
                            <input type="file" class="form-control" id="kontrol_dosya" accept=".xlsx,.xls,.csv">
                            <small class="text-muted">"Telefon" başlıklı kolon okunur; başlık yoksa ilk kolon kullanılır. Şablon dosyası da kullanılabilir.</small>
                            <div class="mt-3">
                                <button type="button" class="btn btn-primary" id="kontrolBtn" onclick="kontrolEt()">
                                    <i class="bi bi-search"></i> Kontrol Et
                                </button>
                            </div>
                        </div>
                    </div>
                    <div id="kontrolSonuc" class="d-none mt-3">
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                            <div id="kontrolOzet"></div>
                            <button type="button" class="btn btn-success btn-sm" onclick="kontrolExcel()">
                                <i class="bi bi-file-earmark-excel"></i> Excel İndir
                            </button>
                        </div>
                        <div class="sonuc-kutu border rounded">
                            <table class="table table-sm table-striped table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Girilen</th><th>Telefon</th><th>Ad Soyad</th>
                                        <th class="text-center">KVKK</th><th class="text-center">ETK</th><th class="text-center">GPS</th>
                                        <th>Doğrulama</th><th>Tarih</th>
                                    </tr>
                                </thead>
                                <tbody id="kontrolGovde"></tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
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
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <script src="/Admin/assets/js/custom.js"></script>

    <script>
        const formLinkKoku = <?= json_encode($formLinkKoku) ?>;
        const TEK_FIRMA = <?= $tekFirma ? 'true' : 'false' ?>;
        const permissions = {
            canAdd: <?= $permissions['can_add'] ? 'true' : 'false' ?>,
            musteriListesi: <?= !empty($musteriYetki['has_access']) ? 'true' : 'false' ?>
        };
        const API_YONETIMI = <?= !empty($apiYetki['has_access']) ? 'true' : 'false' ?>;

        let dataTable, qrModal, yukleModal, kontrolModal;
        let currentfilters = {};
        let formlarById = {};
        let qrDosyaAdi = 'form-qr.png';
        let yukleSatirlari = [];
        let kontrolSonuclari = [];

        function escapeHtml(text) {
            if (text === null || text === undefined) return '';
            return String(text).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
        }

        function sayi(v) {
            return (parseInt(v, 10) || 0).toLocaleString('tr-TR');
        }

        function formLinki(uniqid) {
            return formLinkKoku + uniqid;
        }

        // Dosya adında kullanılamayan karakterler temizlenir
        function dosyaAdi(metin) {
            return String(metin || 'form').replace(/[\\/:*?"<>|]+/g, '').replace(/\s+/g, '-').substring(0, 60);
        }

        function izinIkon(v) {
            if (v === null || v === undefined || v === '') return '<span class="text-muted">-</span>';
            return v == 1
                ? '<i class="bi bi-check-circle text-success izin-ikon" title="İzin verildi"></i>'
                : '<i class="bi bi-x-circle text-danger izin-ikon" title="İzin verilmedi"></i>';
        }

        $(document).ready(function() {
            qrModal = new bootstrap.Modal(document.getElementById('qrModal'));
            yukleModal = new bootstrap.Modal(document.getElementById('yukleModal'));
            kontrolModal = new bootstrap.Modal(document.getElementById('kontrolModal'));

            dataTable = $('#formTable').DataTable({
                language: { url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/tr.json' },
                dom: 'lrtip',
                scrollX: true,
                order: [],
                pageLength: 25,
                lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'Tümü']],
                columnDefs: [{ orderable: false, targets: [4, 5, 6] }]
            });

            $('.select2-filter').select2({ theme: 'bootstrap-5', width: '100%', placeholder: 'Tümü', allowClear: true });

            loadFiltreler();
            loadFormList();
            loadStats();

            $('#filterForm').on('submit', function(e) {
                e.preventDefault();
                currentfilters = {
                    search: $('#filter_search').val(),
                    firma_id: $('#filter_firma_id').val(),
                    form_dil_id: $('#filter_form_dil_id').val(),
                    status: $('#filter_status').val()
                };
                Object.keys(currentfilters).forEach(k => { if (!currentfilters[k]) delete currentfilters[k]; });
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

            $('#yukle_dosya').on('change', function() { sablonOku(this.files[0]); });
            $('#kontrol_dosya').on('change', function() { kontrolDosyaOku(this.files[0]); });
        });

        function loadFiltreler() {
            $.post('', { action: 'filtre_list' }, function(r) {
                if (!r.success) return;
                r.data.firmalar.forEach(f => $('#filter_firma_id').append(new Option(f.firma_adi, f.firma_id, false, false)));
                r.data.diller.forEach(d => $('#filter_form_dil_id').append(new Option(d.form_dil_adi, d.form_dil_id, false, false)));
                $('.select2-filter').trigger('change.select2');
            }, 'json');
        }

        function loadStats() {
            $.post('', { action: 'stats' }, function(r) {
                if (!r.success) return;
                $('#stat-form-toplam').text(sayi(r.data.form_toplam));
                $('#stat-form-aktif').text(sayi(r.data.form_aktif));
                $('#stat-basvuru-toplam').text(sayi(r.data.basvuru_toplam));
                $('#stat-basvuru-bu-ay').text(sayi(r.data.basvuru_bu_ay));
            }, 'json');
        }

        function loadFormList() {
            $.post('', { action: 'list', ...currentfilters }, function(r) {
                if (!r.success) {
                    showToast(r.message || 'Liste yüklenirken hata oluştu', 'error');
                    return;
                }
                renderFormTable(r.data);
            }, 'json').fail(function() {
                showToast('Sunucu hatası oluştu', 'error');
            });
        }

        function renderFormTable(formlar) {
            dataTable.clear();
            formlarById = {};

            formlar.forEach(f => {
                formlarById[f.form_id] = f;
                const link = formLinki(f.form_uniqid);

                const ad = `<div class="fw-semibold">${escapeHtml(f.form_adi)}</div>`
                    + (!TEK_FIRMA ? `<small class="text-muted">${escapeHtml(f.firma_adi)}</small>` : '')
                    + (f.form_sms_onay == 1 ? ' <span class="badge text-bg-light border" title="SMS doğrulama"><i class="bi bi-phone"></i> SMS</span>' : '');

                const durum = f.form_durum == 1
                    ? '<span class="status-badge status-active">Aktif</span>'
                    : '<span class="status-badge status-inactive">Pasif</span>';

                const webLink = `<div class="btn-group">
                        <a href="${escapeHtml(link)}" target="_blank" rel="noopener" class="btn btn-sm btn-primary"><i class="bi bi-box-arrow-up-right"></i> Link</a>
                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="linkKopyala('${escapeHtml(link)}')" title="Bağlantıyı Kopyala"><i class="bi bi-clipboard"></i></button>
                    </div>`;

                const qr = `<button type="button" class="btn btn-sm btn-primary" onclick="qrAc(${f.form_id})"><i class="bi bi-qr-code"></i> QR Kodu</button>`;

                let menu = '';
                if (permissions.musteriListesi) {
                    menu += `<li><a class="dropdown-item" href="/Admin/izinli-musteriler?form_id=${f.form_id}"><i class="bi bi-list-ul me-2"></i>Müşteri Listesi</a></li>`;
                }
                if (permissions.canAdd) {
                    menu += `<li><a class="dropdown-item" href="#" onclick="sablonIndir(${f.form_id}); return false;"><i class="bi bi-download me-2"></i>Şablon İndir</a></li>
                             <li><a class="dropdown-item" href="#" onclick="sablonYukleAc(${f.form_id}); return false;"><i class="bi bi-upload me-2"></i>Şablon Yükle</a></li>`;
                }
                menu += `<li><a class="dropdown-item" href="#" onclick="kontrolAc(${f.form_id}); return false;"><i class="bi bi-search me-2"></i>Çoklu İzin Kontrol</a></li>`;
                menu += `<li><hr class="dropdown-divider"></li>
                         <li><a class="dropdown-item" href="#" onclick="apiBilgi(${f.form_id}); return false;"><i class="bi bi-plug me-2"></i>API ile Kullan</a></li>`;

                const varsayilan = permissions.musteriListesi
                    ? `location.href='/Admin/izinli-musteriler?form_id=${f.form_id}'`
                    : `kontrolAc(${f.form_id})`;

                const islem = `<div class="btn-group">
                        <button type="button" class="btn btn-sm btn-primary" onclick="${varsayilan}">İşlem</button>
                        <button type="button" class="btn btn-sm btn-primary dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown"
                                data-bs-popper-config='{"strategy":"fixed"}' aria-expanded="false">
                            <span class="visually-hidden">Menü</span>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">${menu}</ul>
                    </div>`;

                dataTable.row.add([
                    ad,
                    escapeHtml(f.form_dil_adi || '-'),
                    `<span class="badge text-bg-info">${sayi(f.basvuru_sayisi)}</span>`,
                    durum,
                    webLink,
                    qr,
                    islem
                ]);
            });

            dataTable.columns.adjust().draw();
        }

        /* ---------- Link / QR ---------- */

        function linkKopyala(link, mesaj) {
            mesaj = mesaj || 'Bağlantı kopyalandı';
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(link).then(() => showToast(mesaj, 'success'));
                return;
            }
            const $gecici = $('<textarea>').val(link).appendTo('body').select();
            document.execCommand('copy');
            $gecici.remove();
            showToast(mesaj, 'success');
        }

        /* ---------- API ile kullanım ---------- */

        // Form kodunu (uniqid) ve bu formun API adreslerini gösterir
        function apiBilgi(formId) {
            const f = formlarById[formId];
            if (!f) return;
            const kok = location.origin + '/api';
            const kod = String(f.form_uniqid);
            const satir = (metot, yol) => `<tr><td><span class="badge text-bg-${metot === 'GET' ? 'success' : 'primary'}">${metot}</span></td>
                <td class="font-monospace small text-break">${escapeHtml(yol)}</td></tr>`;
            Swal.fire({
                title: 'API ile Kullan',
                width: 720,
                html: `<div class="text-start">
                    <p class="small text-muted mb-2"><b>${escapeHtml(f.form_adi)}</b> formuna kendi sitenizden veya yazılımınızdan izin göndermek için bu form kodunu kullanın.</p>
                    <label class="form-label small fw-semibold mb-1">Form kodu (uniqid)</label>
                    <div class="input-group mb-3">
                        <input type="text" class="form-control font-monospace" value="${escapeHtml(kod)}" readonly>
                        <button type="button" class="btn btn-outline-primary" onclick="linkKopyala('${escapeHtml(kod)}', 'Form kodu kopyalandı')"><i class="bi bi-clipboard"></i> Kopyala</button>
                    </div>
                    <table class="table table-sm mb-3"><tbody>
                        ${satir('GET', kok + '/formlar/' + kod)}
                        ${satir('POST', kok + '/formlar/' + kod + '/basvuru')}
                        ${satir('GET', kok + '/formlar/' + kod + '/basvurular')}
                    </tbody></table>
                    <div class="small text-muted mb-3">İstekler <span class="font-monospace">Authorization: Bearer kvk_...</span> başlığıyla gönderilir.
                        ${f.form_sms_onay == 1 ? 'Bu form SMS doğrulamalı: başvurudan sonra müşteriye giden kod <span class="font-monospace">POST /api/basvuru/dogrula</span> ile onaylanır.' : ''}</div>
                    <div class="d-flex flex-wrap gap-2">
                        <a href="/api-dokuman" target="_blank" rel="noopener" class="btn btn-sm btn-primary"><i class="bi bi-book"></i> API Dokümantasyonu</a>
                        ${API_YONETIMI ? '<a href="/admin/api-yonetimi" class="btn btn-sm btn-outline-secondary"><i class="bi bi-key"></i> Anahtar Oluştur</a>' : ''}
                    </div>
                </div>`,
                showConfirmButton: false,
                showCloseButton: true
            });
        }

        function qrAc(formId) {
            const f = formlarById[formId];
            if (!f) return;
            const link = formLinki(f.form_uniqid);
            const $kutu = $('#qrKutu').empty();
            new QRCode($kutu[0], { text: link, width: 256, height: 256, correctLevel: QRCode.CorrectLevel.H });
            $('#qr_link').val(link);
            $('#qrModalLabel').text('QR Kod: ' + f.form_adi);
            qrDosyaAdi = 'QR-' + dosyaAdi(f.form_adi) + '.png';
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

        /* ---------- Excel okuma ---------- */

        // Dosyayı satır dizisine çevirir (ilk sayfa, hücreler metin)
        function excelOku(dosya, geriCagir) {
            const okuyucu = new FileReader();
            okuyucu.onload = function(e) {
                try {
                    const kitap = XLSX.read(new Uint8Array(e.target.result), { type: 'array' });
                    const sayfa = kitap.Sheets[kitap.SheetNames[0]];
                    geriCagir(XLSX.utils.sheet_to_json(sayfa, { header: 1, raw: false, defval: '' }));
                } catch (hata) {
                    showToast('Dosya okunamadı. Geçerli bir Excel dosyası seçin.', 'error');
                }
            };
            okuyucu.readAsArrayBuffer(dosya);
        }

        function baslikNormal(v) {
            return String(v || '').toLocaleLowerCase('tr-TR').replace(/\s+/g, '');
        }

        /* ---------- Şablon İndir / Yükle ---------- */

        function sablonIndir(formId) {
            const f = formlarById[formId];
            const sayfa = XLSX.utils.aoa_to_sheet([['Ad', 'Soyad', 'Telefon'], ['Ahmet', 'Yılmaz', '05321234567']]);
            sayfa['!cols'] = [{ wch: 20 }, { wch: 20 }, { wch: 18 }];
            sayfa['C2'].z = '@';
            const kitap = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(kitap, sayfa, 'Musteriler');
            XLSX.writeFile(kitap, 'sablon-' + dosyaAdi(f ? f.form_adi : 'form') + '.xlsx');
            showToast('Şablon indirildi. Örnek satırı silip kendi listenizi girin.', 'info');
        }

        function sablonYukleAc(formId) {
            const f = formlarById[formId];
            $('#yukle_form_id').val(formId);
            $('#yukleModalLabel').text('Şablon Yükle: ' + (f ? f.form_adi : ''));
            $('#yukle_dosya').val('');
            $('#izin_kvkk').prop('checked', true);
            $('#izin_etk, #izin_gps').prop('checked', false);
            $('#yukleOnizleme, #yukleSonuc').addClass('d-none');
            $('#yukleBtn').prop('disabled', true);
            yukleSatirlari = [];
            yukleModal.show();
        }

        function sablonOku(dosya) {
            yukleSatirlari = [];
            $('#yukleOnizleme, #yukleSonuc').addClass('d-none');
            $('#yukleBtn').prop('disabled', true);
            if (!dosya) return;

            excelOku(dosya, function(satirlar) {
                if (!satirlar.length) {
                    showToast('Dosya boş', 'warning');
                    return;
                }
                // Kolonlar başlıktan bulunur; bulunamazsa şablon sırası (Ad, Soyad, Telefon)
                const baslik = satirlar[0].map(baslikNormal);
                const bul = (adaylar, varsayilan) => {
                    const i = baslik.findIndex(b => adaylar.includes(b));
                    return i >= 0 ? i : varsayilan;
                };
                const iAd = bul(['ad', 'adı', 'isim'], 0);
                const iSoyad = bul(['soyad', 'soyadı', 'soyisim'], 1);
                const iTel = bul(['telefon', 'cep', 'ceptelefonu', 'gsm', 'telefonno'], 2);

                yukleSatirlari = satirlar.slice(1)
                    .map(s => ({ ad: String(s[iAd] || '').trim(), soyad: String(s[iSoyad] || '').trim(), telefon: String(s[iTel] || '').trim() }))
                    .filter(s => s.ad || s.soyad || s.telefon);

                if (!yukleSatirlari.length) {
                    showToast('Dosyada başlık dışında satır bulunamadı', 'warning');
                    return;
                }

                const $govde = $('#yukleOnizlemeGovde').empty();
                yukleSatirlari.slice(0, 50).forEach((s, i) => {
                    $govde.append(`<tr><td>${i + 2}</td><td>${escapeHtml(s.ad)}</td><td>${escapeHtml(s.soyad)}</td><td>${escapeHtml(s.telefon)}</td></tr>`);
                });
                $('#yukleOnizlemeBaslik').text(`${sayi(yukleSatirlari.length)} satır okundu` + (yukleSatirlari.length > 50 ? ' (ilk 50 satır gösteriliyor)' : ''));
                $('#yukleOnizleme').removeClass('d-none');
                $('#yukleBtn').prop('disabled', false);
            });
        }

        function sablonGonder() {
            if (!yukleSatirlari.length) return;
            const $btn = $('#yukleBtn');
            $btn.prop('disabled', true).html('<i class="bi bi-hourglass-split"></i> Yükleniyor...');

            $.post('', {
                action: 'sablon_yukle',
                form_id: $('#yukle_form_id').val(),
                satirlar: JSON.stringify(yukleSatirlari),
                izin_kvkk: $('#izin_kvkk').is(':checked') ? 1 : '',
                izin_etk: $('#izin_etk').is(':checked') ? 1 : '',
                izin_gps: $('#izin_gps').is(':checked') ? 1 : ''
            }, function(r) {
                if (!r.success) {
                    showToast(r.message, 'error');
                    $btn.prop('disabled', false);
                    return;
                }
                const d = r.data;
                let html = `<div class="alert alert-${d.eklenen > 0 ? 'success' : 'warning'} mb-2">
                        <strong>${sayi(d.eklenen)}</strong> kişi eklendi.
                        <span class="ms-2">Toplam satır: ${sayi(d.toplam)}</span>
                        <span class="ms-2">Zaten kayıtlı: ${sayi(d.mevcut)}</span>
                        <span class="ms-2">Dosyada tekrar: ${sayi(d.dosya_tekrar)}</span>
                        <span class="ms-2">Hatalı: ${sayi(d.hatali)}</span>
                    </div>`;
                if (d.hatalar.length) {
                    html += '<div class="sonuc-kutu border rounded" style="max-height: 25vh;"><table class="table table-sm mb-0"><thead class="table-light"><tr><th>Satır</th><th>Telefon</th><th>Hata</th></tr></thead><tbody>'
                        + d.hatalar.map(h => `<tr><td>${h.satir}</td><td>${escapeHtml(h.deger)}</td><td class="text-danger">${escapeHtml(h.mesaj)}</td></tr>`).join('')
                        + '</tbody></table></div>';
                }
                $('#yukleOnizleme').addClass('d-none');
                $('#yukleSonuc').html(html).removeClass('d-none');
                $('#yukle_dosya').val('');
                yukleSatirlari = [];
                showToast(`${d.eklenen} kişi izinli müşterilere eklendi`, d.eklenen > 0 ? 'success' : 'warning');
                loadFormList();
                loadStats();
            }, 'json').fail(function() {
                showToast('Yükleme sırasında hata oluştu', 'error');
                $btn.prop('disabled', false);
            }).always(function() {
                $btn.html('<i class="bi bi-upload"></i> Yükle');
            });
        }

        /* ---------- Çoklu İzin Kontrol ---------- */

        function kontrolAc(formId) {
            const f = formlarById[formId];
            $('#kontrol_form_id').val(formId);
            $('#kontrolModalLabel').text('Çoklu İzin Kontrol: ' + (f ? f.form_adi : ''));
            $('#kontrol_metin, #kontrol_dosya').val('');
            $('#kontrolSonuc').addClass('d-none');
            kontrolSonuclari = [];
            kontrolModal.show();
        }

        // Excel'deki telefonlar metin kutusuna aktarılır
        function kontrolDosyaOku(dosya) {
            if (!dosya) return;
            excelOku(dosya, function(satirlar) {
                if (!satirlar.length) return;
                const baslik = satirlar[0].map(baslikNormal);
                let i = baslik.findIndex(b => ['telefon', 'cep', 'ceptelefonu', 'gsm', 'telefonno'].includes(b));
                const basla = i >= 0 ? 1 : 0;
                if (i < 0) i = 0;
                const telefonlar = satirlar.slice(basla).map(s => String(s[i] || '').trim()).filter(Boolean);
                $('#kontrol_metin').val(telefonlar.join('\n'));
                showToast(`${telefonlar.length} numara okundu`, 'info');
            });
        }

        function kontrolEt() {
            const telefonlar = $('#kontrol_metin').val().split(/[\r\n,;]+/).map(s => s.trim()).filter(Boolean);
            if (!telefonlar.length) {
                showToast('Kontrol edilecek numara girin', 'warning');
                return;
            }
            const $btn = $('#kontrolBtn');
            $btn.prop('disabled', true).html('<i class="bi bi-hourglass-split"></i> Kontrol ediliyor...');

            $.post('', { action: 'coklu_kontrol', form_id: $('#kontrol_form_id').val(), telefonlar: JSON.stringify(telefonlar) }, function(r) {
                if (!r.success) {
                    showToast(r.message, 'error');
                    return;
                }
                kontrolSonuclari = r.data.sonuc;
                const o = r.data.ozet;
                $('#kontrolOzet').html(`<span class="badge text-bg-success me-1">Kayıtlı: ${sayi(o.kayitli)}</span>
                    <span class="badge text-bg-secondary me-1">Kayıt yok: ${sayi(o.kayit_yok)}</span>
                    <span class="badge text-bg-danger">Geçersiz: ${sayi(o.gecersiz)}</span>`);

                $('#kontrolGovde').html(kontrolSonuclari.map(s => {
                    if (s.durum === 'gecersiz') {
                        return `<tr><td>${escapeHtml(s.girilen)}</td><td colspan="7" class="text-danger">Geçersiz telefon numarası</td></tr>`;
                    }
                    if (s.durum === 'kayit_yok') {
                        return `<tr><td>${escapeHtml(s.girilen)}</td><td>${escapeHtml(s.telefon)}</td><td colspan="6" class="text-muted">Bu formda kaydı yok</td></tr>`;
                    }
                    const adSoyad = [s.ad, s.soyad].filter(Boolean).join(' ') || '-';
                    return `<tr>
                        <td>${escapeHtml(s.girilen)}</td><td>${escapeHtml(s.telefon)}</td><td>${escapeHtml(adSoyad)}</td>
                        <td class="text-center">${izinIkon(s.kvkk)}</td><td class="text-center">${izinIkon(s.etk)}</td><td class="text-center">${izinIkon(s.gps)}</td>
                        <td><span class="badge text-bg-${escapeHtml(s.dogrulama_renk || 'secondary')}">${escapeHtml(s.dogrulama_adi || '-')}</span></td>
                        <td class="text-nowrap">${escapeHtml(s.tarih || '-')}</td>
                    </tr>`;
                }).join(''));
                $('#kontrolSonuc').removeClass('d-none');
            }, 'json').fail(function() {
                showToast('Kontrol sırasında hata oluştu', 'error');
            }).always(function() {
                $btn.prop('disabled', false).html('<i class="bi bi-search"></i> Kontrol Et');
            });
        }

        function kontrolExcel() {
            if (!kontrolSonuclari.length) return;
            const evet = v => (v === null || v === undefined || v === '') ? '-' : (v == 1 ? 'Evet' : 'Hayır');
            const durumAdi = { kayitli: 'Kayıtlı', kayit_yok: 'Kayıt yok', gecersiz: 'Geçersiz numara' };
            const satirlar = [['Girilen', 'Telefon', 'Sonuç', 'Ad', 'Soyad', 'KVKK', 'ETK', 'GPS', 'Doğrulama', 'Tarih']];
            kontrolSonuclari.forEach(s => {
                const kayitli = s.durum === 'kayitli';
                satirlar.push([
                    s.girilen, s.telefon || '', durumAdi[s.durum] || s.durum,
                    kayitli ? (s.ad || '') : '', kayitli ? (s.soyad || '') : '',
                    kayitli ? evet(s.kvkk) : '', kayitli ? evet(s.etk) : '', kayitli ? evet(s.gps) : '',
                    kayitli ? (s.dogrulama_adi || '') : '', kayitli ? (s.tarih || '') : ''
                ]);
            });
            const sayfa = XLSX.utils.aoa_to_sheet(satirlar);
            const kitap = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(kitap, sayfa, 'Izin Kontrol');
            const f = formlarById[$('#kontrol_form_id').val()];
            XLSX.writeFile(kitap, 'izin-kontrol-' + dosyaAdi(f ? f.form_adi : 'form') + '.xlsx');
        }
    </script>
</body>
</html>
