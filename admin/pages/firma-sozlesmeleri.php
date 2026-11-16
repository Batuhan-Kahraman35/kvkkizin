<?php
/**
 * Admin Panel - Firma Sözleşmeleri
 *
 * Firmaların sözleşme dönemlerini (başlangıç/bitiş), tutarlarını ve ödeme
 * durumlarını yönetir. Sözleşmesi biten firmanın kullanıcıları giriş yapamaz
 * (kural: includes/SozlesmeHelper.php).
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
require_once __DIR__ . '/../includes/SozlesmeHelper.php';
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

$pageTitle = $pageinfo['sayfalar_sayfa_adi'] ?? 'Firma Sözleşmeleri';
$pageDescription = $pageinfo['sayfalar_aciklama'] ?? '';
$menuAdi = $pageinfo['menu_adi'] ?? null;

// Sayfa yetkilerini kontrol et
$permissions = PageAuth::checkPagePermissions($user['kullanici_id'], $user['departman_id'], $currentPagefile);

if (!$permissions['has_access']) {
    PageAuth::accessDenied($permissions['error'] ?? 'Bu sayfaya erişim yetkiniz yok!');
}

// Kullanıcının görebileceği firmalar (null = sınırsız)
$firmaScopeIds = PageAuth::firmaScopeIds($permissions, $user['firma_id'] ?? null);
$yoneticiMi = !empty($permissions['is_admin']);

$firmaKapsamdaMi = function ($firmaId) use ($firmaScopeIds) {
    if ($firmaScopeIds === null) {
        return true;
    }
    return in_array((int)$firmaId, $firmaScopeIds, true);
};

// Yönetici dışındaki kullanıcı kendi firmasının sözleşmesini değiştiremez
$sozlesmeDegistirilebilir = function ($firmaId) use ($yoneticiMi, $user, $firmaKapsamdaMi) {
    if (!$firmaKapsamdaMi($firmaId)) {
        return false;
    }
    return $yoneticiMi || (int)$firmaId !== (int)($user['firma_id'] ?? 0);
};

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title, site_ayarlari_currency FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';
$paraBirimi = $siteAyarlari['site_ayarlari_currency'] ?? 'TRY';

/**
 * Liste / özet filtreleri — yalnız Firma_Sozlesmeleri (s) üzerinden, JOIN'siz
 */
$filtreOlustur = function (array $girdi) use ($firmaScopeIds) {
    [$scopeSql, $params] = PageAuth::firmaScopeWhere($firmaScopeIds, 's.firma_id');
    $kosullar = [$scopeSql];

    if (!empty($girdi['firma_id'])) {
        $kosullar[] = 's.firma_id = ?';
        $params[] = (int)$girdi['firma_id'];
    }

    if (!empty($girdi['odeme_durumu_id'])) {
        $kosullar[] = 's.odeme_durumu_id = ?';
        $params[] = (int)$girdi['odeme_durumu_id'];
    }

    if (!empty($girdi['yil']) && ctype_digit((string)$girdi['yil'])) {
        $yil = (int)$girdi['yil'];
        $kosullar[] = 's.firma_sozlesme_baslangic_tarihi >= ? AND s.firma_sozlesme_baslangic_tarihi < ?';
        $params[] = sprintf('%04d-01-01', $yil);
        $params[] = sprintf('%04d-01-01', $yil + 1);
    }

    switch ($girdi['gecerlilik'] ?? '') {
        case 'gecerli':
            $kosullar[] = 'CAST(GETDATE() AS DATE) BETWEEN s.firma_sozlesme_baslangic_tarihi AND s.firma_sozlesme_bitis_tarihi';
            break;
        case 'yaklasan':
            $kosullar[] = 's.firma_sozlesme_bitis_tarihi BETWEEN CAST(GETDATE() AS DATE) AND DATEADD(DAY, ' . SozlesmeHelper::YAKLASAN_GUN . ', CAST(GETDATE() AS DATE))';
            break;
        case 'dolmus':
            $kosullar[] = 's.firma_sozlesme_bitis_tarihi < CAST(GETDATE() AS DATE)';
            break;
        case 'baslamadi':
            $kosullar[] = 's.firma_sozlesme_baslangic_tarihi > CAST(GETDATE() AS DATE)';
            break;
    }

    if (isset($girdi['status']) && $girdi['status'] !== '') {
        $kosullar[] = 's.firma_sozlesme_durum = ?';
        $params[] = (int)$girdi['status'];
    }

    $arama = trim($girdi['search_text'] ?? '');
    if ($arama !== '') {
        $like = '%' . $arama . '%';
        $kosullar[] = '(s.firma_sozlesme_aciklama LIKE ? OR s.firma_id IN (SELECT firma_id FROM dbo.Firmalar WHERE firma_adi LIKE ? OR firma_vkn_tc LIKE ?))';
        array_push($params, $like, $like, $like);
    }

    return [implode(' AND ', $kosullar), $params];
};

$tarihGecerli = function ($tarih) {
    $d = DateTime::createFromFormat('Y-m-d', (string)$tarih);
    return $d && $d->format('Y-m-d') === $tarih;
};

// AJAX işlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {
            case 'list':
                $draw   = (int)($_POST['draw'] ?? 1);
                $start  = max(0, (int)($_POST['start'] ?? 0));
                $length = (int)($_POST['length'] ?? 25);
                if ($length <= 0 || $length > 500) {
                    $length = 25;
                }

                // Sıralama: kolon index → kolon adı (whitelist)
                $siralamaKolonlari = [
                    0 => 'f.firma_adi',
                    1 => 's.firma_sozlesme_baslangic_tarihi',
                    2 => 's.firma_sozlesme_bitis_tarihi',
                    3 => 's.firma_sozlesme_tutar',
                    4 => 's.odeme_durumu_id',
                    5 => 's.firma_sozlesme_odeme_tarihi',
                    6 => 's.firma_sozlesme_durum',
                ];
                $kolonIndex = (int)($_POST['order'][0]['column'] ?? 2);
                $siralaKolon = $siralamaKolonlari[$kolonIndex] ?? 's.firma_sozlesme_bitis_tarihi';
                $siralaYon = strtolower($_POST['order'][0]['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';
                $firmaJoin = $siralaKolon === 'f.firma_adi'
                    ? 'INNER JOIN dbo.Firmalar f ON f.firma_id = s.firma_id'
                    : '';

                [$scopeSql, $scopeParams] = PageAuth::firmaScopeWhere($firmaScopeIds, 's.firma_id');
                [$filtreSql, $filtreParams] = $filtreOlustur($_POST);

                $recordsTotal = (int)($db->fetchOne(
                    "SELECT COUNT(*) AS sayi FROM dbo.Firma_Sozlesmeleri s WHERE $scopeSql",
                    $scopeParams
                )['sayi'] ?? 0);

                $recordsFiltered = (int)($db->fetchOne(
                    "SELECT COUNT(*) AS sayi FROM dbo.Firma_Sozlesmeleri s WHERE $filtreSql",
                    $filtreParams
                )['sayi'] ?? 0);

                // 1. aşama: yalnız sayfanın ID'leri  2. aşama: detaylar bu N satıra
                $satirlar = $db->fetchAll("
                    WITH Sayfa AS (
                        SELECT s.firma_sozlesme_id,
                               ROW_NUMBER() OVER (ORDER BY $siralaKolon $siralaYon, s.firma_sozlesme_id DESC) AS sira
                        FROM dbo.Firma_Sozlesmeleri s
                        $firmaJoin
                        WHERE $filtreSql
                        ORDER BY $siralaKolon $siralaYon, s.firma_sozlesme_id DESC
                        OFFSET ? ROWS FETCH NEXT ? ROWS ONLY
                    )
                    SELECT
                        s.firma_sozlesme_id,
                        s.firma_id,
                        f.firma_adi,
                        f.firma_vkn_tc,
                        CONVERT(VARCHAR(10), s.firma_sozlesme_baslangic_tarihi, 23) AS baslangic,
                        CONVERT(VARCHAR(10), s.firma_sozlesme_bitis_tarihi, 23)     AS bitis,
                        CONVERT(VARCHAR(10), s.firma_sozlesme_odeme_tarihi, 23)     AS odeme_tarihi,
                        s.firma_sozlesme_tutar AS tutar,
                        s.firma_sozlesme_aciklama AS aciklama,
                        s.firma_sozlesme_durum AS durum,
                        DATEDIFF(DAY, CAST(GETDATE() AS DATE), s.firma_sozlesme_bitis_tarihi)     AS kalan_gun,
                        DATEDIFF(DAY, CAST(GETDATE() AS DATE), s.firma_sozlesme_baslangic_tarihi) AS baslamaya_gun,
                        o.odeme_durumu_adi, o.odeme_durumu_renk, o.odeme_durumu_ikon, o.odeme_durumu_odendi,
                        LTRIM(RTRIM(ISNULL(k.kullanici_ad, '') + ' ' + ISNULL(k.kullanici_soyad, ''))) AS olusturan
                    FROM Sayfa p
                    INNER JOIN dbo.Firma_Sozlesmeleri s ON s.firma_sozlesme_id = p.firma_sozlesme_id
                    INNER JOIN dbo.Firmalar f ON f.firma_id = s.firma_id
                    LEFT JOIN dbo.tanim_SozlesmeOdemeDurumlari o ON o.odeme_durumu_id = s.odeme_durumu_id
                    LEFT JOIN dbo.kullanicilar k ON k.kullanici_id = s.firma_sozlesme_olusturan_kullanici_id
                    ORDER BY p.sira
                ", array_merge($filtreParams, [$start, $length]));

                // Satır bazlı düzenleme izni (kendi firması kısıtı)
                foreach ($satirlar as &$satir) {
                    $satir['degistirilebilir'] = $sozlesmeDegistirilebilir($satir['firma_id']) ? 1 : 0;
                }
                unset($satir);

                echo json_encode([
                    'draw'            => $draw,
                    'recordsTotal'    => $recordsTotal,
                    'recordsFiltered' => $recordsFiltered,
                    'data'            => $satirlar,
                ]);
                break;

            case 'stats':
                [$scopeSql, $scopeParams] = PageAuth::firmaScopeWhere($firmaScopeIds, 's.firma_id');
                $ozet = $db->fetchOne("
                    SELECT
                        SUM(CASE WHEN CAST(GETDATE() AS DATE) BETWEEN s.firma_sozlesme_baslangic_tarihi AND s.firma_sozlesme_bitis_tarihi THEN 1 ELSE 0 END) AS gecerli,
                        SUM(CASE WHEN s.firma_sozlesme_bitis_tarihi BETWEEN CAST(GETDATE() AS DATE)
                                      AND DATEADD(DAY, " . SozlesmeHelper::YAKLASAN_GUN . ", CAST(GETDATE() AS DATE)) THEN 1 ELSE 0 END) AS yaklasan,
                        SUM(CASE WHEN o.odeme_durumu_odendi = 1
                                  AND YEAR(s.firma_sozlesme_baslangic_tarihi) = YEAR(GETDATE())
                                 THEN s.firma_sozlesme_tutar ELSE 0 END) AS bu_yil_odenen
                    FROM dbo.Firma_Sozlesmeleri s
                    LEFT JOIN dbo.tanim_SozlesmeOdemeDurumlari o ON o.odeme_durumu_id = s.odeme_durumu_id
                    WHERE s.firma_sozlesme_durum = 1 AND $scopeSql
                ", $scopeParams);

                [$firmaScopeSql, $firmaScopeParams] = PageAuth::firmaScopeWhere($firmaScopeIds, 'firma_id');

                echo json_encode(['success' => true, 'data' => [
                    'gecerli'       => (int)($ozet['gecerli'] ?? 0),
                    'yaklasan'      => (int)($ozet['yaklasan'] ?? 0),
                    'bu_yil_odenen' => (float)($ozet['bu_yil_odenen'] ?? 0),
                    'kapali_firma'  => SozlesmeHelper::erisimiKapaliFirmaSayisi($db, $firmaScopeSql, $firmaScopeParams),
                ]]);
                break;

            case 'yillik_ozet':
                [$filtreSql, $filtreParams] = $filtreOlustur($_POST);
                $yillar = $db->fetchAll("
                    SELECT
                        YEAR(s.firma_sozlesme_baslangic_tarihi) AS yil,
                        COUNT(*) AS sozlesme_sayisi,
                        COUNT(DISTINCT s.firma_id) AS firma_sayisi,
                        SUM(s.firma_sozlesme_tutar) AS toplam,
                        SUM(CASE WHEN o.odeme_durumu_odendi = 1 THEN s.firma_sozlesme_tutar ELSE 0 END) AS odenen,
                        SUM(CASE WHEN ISNULL(o.odeme_durumu_odendi, 0) = 0 THEN s.firma_sozlesme_tutar ELSE 0 END) AS bekleyen
                    FROM dbo.Firma_Sozlesmeleri s
                    LEFT JOIN dbo.tanim_SozlesmeOdemeDurumlari o ON o.odeme_durumu_id = s.odeme_durumu_id
                    WHERE s.firma_sozlesme_durum = 1 AND $filtreSql
                    GROUP BY YEAR(s.firma_sozlesme_baslangic_tarihi)
                    ORDER BY yil DESC
                ", $filtreParams);
                echo json_encode(['success' => true, 'data' => $yillar]);
                break;

            case 'yil_list':
                [$scopeSql, $scopeParams] = PageAuth::firmaScopeWhere($firmaScopeIds, 's.firma_id');
                $yillar = $db->fetchAll("
                    SELECT DISTINCT YEAR(s.firma_sozlesme_baslangic_tarihi) AS yil
                    FROM dbo.Firma_Sozlesmeleri s
                    WHERE $scopeSql
                    ORDER BY yil DESC
                ", $scopeParams);
                echo json_encode(['success' => true, 'data' => array_column($yillar, 'yil')]);
                break;

            case 'firma_list':
                [$scopeSql, $scopeParams] = PageAuth::firmaScopeWhere($firmaScopeIds, 'firma_id');
                $firmalar = $db->fetchAll("
                    SELECT firma_id, firma_adi, firma_vkn_tc
                    FROM dbo.Firmalar
                    WHERE firma_durum = 1 AND $scopeSql
                    ORDER BY firma_adi
                ", $scopeParams);
                foreach ($firmalar as &$f) {
                    $f['degistirilebilir'] = $sozlesmeDegistirilebilir($f['firma_id']) ? 1 : 0;
                }
                unset($f);
                echo json_encode(['success' => true, 'data' => $firmalar]);
                break;

            case 'odeme_durumu_list':
                $durumlar = $db->fetchAll("
                    SELECT odeme_durumu_id, odeme_durumu_adi, odeme_durumu_renk, odeme_durumu_odendi, odeme_durumu_varsayilan
                    FROM dbo.tanim_SozlesmeOdemeDurumlari
                    WHERE odeme_durumu_durum = 1
                    ORDER BY odeme_durumu_sira_no, odeme_durumu_adi
                ");
                echo json_encode(['success' => true, 'data' => $durumlar]);
                break;

            case 'get':
                $id = (int)($_POST['firma_sozlesme_id'] ?? 0);
                $kayit = $db->fetchOne("
                    SELECT firma_sozlesme_id, firma_id,
                           CONVERT(VARCHAR(10), firma_sozlesme_baslangic_tarihi, 23) AS firma_sozlesme_baslangic_tarihi,
                           CONVERT(VARCHAR(10), firma_sozlesme_bitis_tarihi, 23)     AS firma_sozlesme_bitis_tarihi,
                           CONVERT(VARCHAR(10), firma_sozlesme_odeme_tarihi, 23)     AS firma_sozlesme_odeme_tarihi,
                           firma_sozlesme_tutar, odeme_durumu_id, firma_sozlesme_aciklama, firma_sozlesme_durum
                    FROM dbo.Firma_Sozlesmeleri
                    WHERE firma_sozlesme_id = ?
                ", [$id]);

                if (!$kayit || !$firmaKapsamdaMi($kayit['firma_id'])) {
                    echo json_encode(['success' => false, 'message' => 'Sözleşme bulunamadı']);
                    break;
                }
                echo json_encode(['success' => true, 'data' => $kayit]);
                break;

            case 'save':
                $id = (int)($_POST['firma_sozlesme_id'] ?? 0);

                if ($id > 0 && !$permissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Düzenleme yetkiniz yok']);
                    break;
                }
                if ($id === 0 && !$permissions['can_add']) {
                    echo json_encode(['success' => false, 'message' => 'Ekleme yetkiniz yok']);
                    break;
                }

                $firmaId    = (int)($_POST['firma_id'] ?? 0);
                $baslangic  = trim($_POST['firma_sozlesme_baslangic_tarihi'] ?? '');
                $bitis      = trim($_POST['firma_sozlesme_bitis_tarihi'] ?? '');
                $odemeTarih = trim($_POST['firma_sozlesme_odeme_tarihi'] ?? '');
                $odemeId    = (int)($_POST['odeme_durumu_id'] ?? 0);
                $aciklama   = trim($_POST['firma_sozlesme_aciklama'] ?? '');
                $tutarHam   = str_replace([' ', ','], ['', '.'], trim($_POST['firma_sozlesme_tutar'] ?? ''));

                // Mevcut kayıt ve kapsam kontrolü
                if ($id > 0) {
                    $mevcut = $db->fetchOne("SELECT firma_id FROM dbo.Firma_Sozlesmeleri WHERE firma_sozlesme_id = ?", [$id]);
                    if (!$mevcut || !$sozlesmeDegistirilebilir($mevcut['firma_id'])) {
                        echo json_encode(['success' => false, 'message' => 'Bu sözleşmeyi değiştirme yetkiniz yok']);
                        break;
                    }
                }

                $firma = $firmaId > 0 ? $db->fetchOne("SELECT firma_id FROM dbo.Firmalar WHERE firma_id = ?", [$firmaId]) : null;
                if (!$firma || !$sozlesmeDegistirilebilir($firmaId)) {
                    echo json_encode(['success' => false, 'message' => 'Geçerli bir firma seçin (kendi firmanızın sözleşmesini değiştiremezsiniz)']);
                    break;
                }

                if (!$tarihGecerli($baslangic) || !$tarihGecerli($bitis)) {
                    echo json_encode(['success' => false, 'message' => 'Başlangıç ve bitiş tarihi zorunludur']);
                    break;
                }
                if ($bitis < $baslangic) {
                    echo json_encode(['success' => false, 'message' => 'Bitiş tarihi başlangıç tarihinden önce olamaz']);
                    break;
                }

                if ($tutarHam === '' || !is_numeric($tutarHam) || (float)$tutarHam < 0 || (float)$tutarHam > 9999999999999999) {
                    echo json_encode(['success' => false, 'message' => 'Geçerli bir tutar girin']);
                    break;
                }

                $odeme = $db->fetchOne("
                    SELECT odeme_durumu_id, odeme_durumu_odendi
                    FROM dbo.tanim_SozlesmeOdemeDurumlari
                    WHERE odeme_durumu_id = ? AND odeme_durumu_durum = 1
                ", [$odemeId]);
                if (!$odeme) {
                    echo json_encode(['success' => false, 'message' => 'Ödeme durumu seçin']);
                    break;
                }

                if ((int)$odeme['odeme_durumu_odendi'] === 1) {
                    if (!$tarihGecerli($odemeTarih)) {
                        echo json_encode(['success' => false, 'message' => 'Ödendi durumunda ödeme tarihi zorunludur']);
                        break;
                    }
                } else {
                    $odemeTarih = '';
                }

                $durum = isset($_POST['firma_sozlesme_durum']) ? 1 : 0;

                // Aynı firmada çakışan aktif sözleşme olmamalı
                if ($durum === 1) {
                    $cakisan = $db->fetchOne("
                        SELECT TOP 1
                            CONVERT(VARCHAR(10), firma_sozlesme_baslangic_tarihi, 104) AS baslangic,
                            CONVERT(VARCHAR(10), firma_sozlesme_bitis_tarihi, 104)     AS bitis
                        FROM dbo.Firma_Sozlesmeleri
                        WHERE firma_id = ? AND firma_sozlesme_durum = 1 AND firma_sozlesme_id <> ?
                          AND firma_sozlesme_baslangic_tarihi <= ? AND firma_sozlesme_bitis_tarihi >= ?
                    ", [$firmaId, $id, $bitis, $baslangic]);
                    if ($cakisan) {
                        echo json_encode(['success' => false, 'message' => "Bu tarihler firmanın mevcut sözleşmesiyle çakışıyor ({$cakisan['baslangic']} - {$cakisan['bitis']})"]);
                        break;
                    }
                }

                $veri = [
                    'firma_id'                        => $firmaId,
                    'firma_sozlesme_baslangic_tarihi' => $baslangic,
                    'firma_sozlesme_bitis_tarihi'     => $bitis,
                    'firma_sozlesme_tutar'            => number_format((float)$tutarHam, 2, '.', ''),
                    'odeme_durumu_id'                 => $odemeId,
                    'firma_sozlesme_odeme_tarihi'     => $odemeTarih !== '' ? $odemeTarih : null,
                    'firma_sozlesme_aciklama'         => $aciklama !== '' ? mb_substr($aciklama, 0, 500) : null,
                    'firma_sozlesme_durum'            => $durum,
                ];

                if ($id > 0) {
                    $veri['firma_sozlesme_guncelleyen_kullanici_id'] = $user['kullanici_id'];
                    $veri['firma_sozlesme_guncelleme_tarihi'] = date('Y-m-d H:i:s');
                    $db->update('dbo.Firma_Sozlesmeleri', $veri, ['firma_sozlesme_id' => $id]);
                    echo json_encode(['success' => true, 'message' => 'Sözleşme güncellendi']);
                } else {
                    $veri['firma_sozlesme_olusturan_kullanici_id'] = $user['kullanici_id'];
                    $veri['firma_sozlesme_olusturma_tarihi'] = date('Y-m-d H:i:s');
                    $db->insert('dbo.Firma_Sozlesmeleri', $veri);
                    echo json_encode(['success' => true, 'message' => 'Sözleşme eklendi']);
                }
                break;

            case 'delete':
                if (!$permissions['can_delete']) {
                    echo json_encode(['success' => false, 'message' => 'Silme yetkiniz yok']);
                    break;
                }
                $id = (int)($_POST['firma_sozlesme_id'] ?? 0);
                $mevcut = $db->fetchOne("SELECT firma_id FROM dbo.Firma_Sozlesmeleri WHERE firma_sozlesme_id = ?", [$id]);
                if (!$mevcut || !$sozlesmeDegistirilebilir($mevcut['firma_id'])) {
                    echo json_encode(['success' => false, 'message' => 'Bu sözleşmeyi silme yetkiniz yok']);
                    break;
                }
                $db->delete('dbo.Firma_Sozlesmeleri', ['firma_sozlesme_id' => $id]);
                echo json_encode(['success' => true, 'message' => 'Sözleşme silindi']);
                break;

            default:
                echo json_encode(['success' => false, 'message' => 'Geçersiz işlem']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Hata: ' . $e->getMessage(), 'message' => 'Hata: ' . $e->getMessage()]);
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
        .tutar { white-space: nowrap; font-variant-numeric: tabular-nums; }
        #yillikOzetTable td, #yillikOzetTable th { white-space: nowrap; }
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
                            <div class="info-box text-bg-success">
                                <span class="info-box-icon"><i class="bi bi-file-earmark-check"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Geçerli Sözleşme</span>
                                    <span class="info-box-number" id="stat-gecerli">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-box text-bg-warning">
                                <span class="info-box-icon"><i class="bi bi-hourglass-split"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text"><?= SozlesmeHelper::YAKLASAN_GUN ?> Gün İçinde Bitecek</span>
                                    <span class="info-box-number" id="stat-yaklasan">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-box text-bg-danger">
                                <span class="info-box-icon"><i class="bi bi-lock"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Erişimi Kapalı Firma</span>
                                    <span class="info-box-number" id="stat-kapali">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-box text-bg-primary">
                                <span class="info-box-icon"><i class="bi bi-cash-stack"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text"><?= date('Y') ?> Ödenen</span>
                                    <span class="info-box-number" id="stat-odenen">0</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Filtre Kartı -->
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
                                        <input type="text" class="form-control" id="filter_search" placeholder="Firma adı, VKN/TC veya açıklama...">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Firma</label>
                                        <select class="form-select select2-filter" id="filter_firma_id" style="width: 100%;">
                                            <option value="">Tümü</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Sözleşme Yılı</label>
                                        <select class="form-select select2-filter" id="filter_yil" style="width: 100%;">
                                            <option value="">Tümü</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Geçerlilik</label>
                                        <select class="form-select select2-filter" id="filter_gecerlilik" style="width: 100%;">
                                            <option value="">Tümü</option>
                                            <option value="gecerli">Geçerli</option>
                                            <option value="yaklasan"><?= SozlesmeHelper::YAKLASAN_GUN ?> Gün İçinde Bitecek</option>
                                            <option value="dolmus">Süresi Dolmuş</option>
                                            <option value="baslamadi">Henüz Başlamamış</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Ödeme Durumu</label>
                                        <select class="form-select select2-filter" id="filter_odeme_durumu_id" style="width: 100%;">
                                            <option value="">Tümü</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Durum</label>
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

                    <div class="row">
                        <!-- Sözleşme listesi -->
                        <div class="col-xl-9">
                            <div class="card">
                                <div class="card-header">
                                    <h3 class="card-title">Sözleşme Listesi</h3>
                                    <div class="card-tools">
                                        <?php if ($permissions['can_add']): ?>
                                        <button type="button" class="btn btn-primary btn-sm" onclick="yeniSozlesme()">
                                            <i class="bi bi-plus-circle"></i> Yeni Sözleşme
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <table id="sozlesmeTable" class="table table-bordered table-striped table-hover" style="width:100%">
                                        <thead>
                                            <tr>
                                                <th>Firma</th>
                                                <th>Başlangıç</th>
                                                <th>Bitiş</th>
                                                <th>Tutar</th>
                                                <th>Ödeme</th>
                                                <th>Ödeme Tarihi</th>
                                                <th>Durum</th>
                                                <th>İşlemler</th>
                                            </tr>
                                        </thead>
                                        <tbody></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <!-- Yıllık ödeme özeti -->
                        <div class="col-xl-3">
                            <div class="card card-outline card-success">
                                <div class="card-header">
                                    <h3 class="card-title"><i class="bi bi-calendar3"></i> Yıllık Ödeme Özeti</h3>
                                </div>
                                <div class="card-body p-0">
                                    <table class="table table-sm mb-0" id="yillikOzetTable">
                                        <thead>
                                            <tr>
                                                <th>Yıl</th>
                                                <th class="text-end">Ödenen</th>
                                                <th class="text-end">Bekleyen</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr><td colspan="3" class="text-center text-muted py-3">Yükleniyor...</td></tr>
                                        </tbody>
                                    </table>
                                </div>
                                <div class="card-footer small text-muted">
                                    Sözleşme başlangıç yılına göre gruplanır, filtreler uygulanır. Pasif sözleşmeler dahil edilmez.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>

        <?php include __DIR__ . '/../includes/footer.php'; ?>
    </div>

    <!-- Sözleşme Modal -->
    <div class="modal fade" id="sozlesmeModal" tabindex="-1" aria-labelledby="sozlesmeModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="sozlesmeModalLabel">Yeni Sözleşme</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="sozlesmeForm">
                    <div class="modal-body">
                        <input type="hidden" id="firma_sozlesme_id" name="firma_sozlesme_id" value="">

                        <div class="row g-3">
                            <div class="col-md-12">
                                <label for="firma_id" class="form-label">Firma <span class="text-danger">*</span></label>
                                <select class="form-select" id="firma_id" name="firma_id" style="width: 100%;" required></select>
                                <small class="text-muted">Üst firmanın sözleşmesi bitince, kendi geçerli sözleşmesi olmayan alt firmalar da giriş yapamaz.</small>
                            </div>

                            <div class="col-md-6">
                                <label for="firma_sozlesme_baslangic_tarihi" class="form-label">Başlangıç Tarihi <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="firma_sozlesme_baslangic_tarihi" name="firma_sozlesme_baslangic_tarihi" required>
                            </div>

                            <div class="col-md-6">
                                <label for="firma_sozlesme_bitis_tarihi" class="form-label">Bitiş Tarihi <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="firma_sozlesme_bitis_tarihi" name="firma_sozlesme_bitis_tarihi" required>
                                <small class="text-muted">Boşsa başlangıçtan itibaren 1 yıl önerilir. Bitiş günü dahil giriş yapılabilir.</small>
                            </div>

                            <div class="col-md-4">
                                <label for="firma_sozlesme_tutar" class="form-label">Tutar (<?= htmlspecialchars($paraBirimi) ?>) <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" id="firma_sozlesme_tutar" name="firma_sozlesme_tutar" min="0" step="0.01" required>
                            </div>

                            <div class="col-md-4">
                                <label for="odeme_durumu_id" class="form-label">Ödeme Durumu <span class="text-danger">*</span></label>
                                <select class="form-select" id="odeme_durumu_id" name="odeme_durumu_id" style="width: 100%;" required></select>
                            </div>

                            <div class="col-md-4">
                                <label for="firma_sozlesme_odeme_tarihi" class="form-label">Ödeme Tarihi <span class="text-danger d-none" id="odeme_tarihi_zorunlu">*</span></label>
                                <input type="date" class="form-control" id="firma_sozlesme_odeme_tarihi" name="firma_sozlesme_odeme_tarihi">
                            </div>

                            <div class="col-md-12">
                                <label for="firma_sozlesme_aciklama" class="form-label">Açıklama</label>
                                <textarea class="form-control" id="firma_sozlesme_aciklama" name="firma_sozlesme_aciklama" rows="2" maxlength="500"></textarea>
                            </div>

                            <div class="col-md-12">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" role="switch" id="firma_sozlesme_durum" name="firma_sozlesme_durum" value="1" checked>
                                    <label class="form-check-label" for="firma_sozlesme_durum">Aktif <small class="text-muted">(pasif sözleşme giriş kontrolünde ve özetlerde dikkate alınmaz)</small></label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="bi bi-x-circle"></i> İptal</button>
                        <button type="submit" class="btn btn-primary" id="kaydetBtn"><i class="bi bi-save"></i> Kaydet</button>
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
        let sozlesmeModal;
        let dataTable;
        let odemeDurumlari = [];
        let currentfilters = {};

        const PARA_BIRIMI = <?= json_encode($paraBirimi) ?>;
        const YAKLASAN_GUN = <?= (int)SozlesmeHelper::YAKLASAN_GUN ?>;
        const permissions = {
            canAdd: <?= $permissions['can_add'] ? 'true' : 'false' ?>,
            canEdit: <?= $permissions['can_edit'] ? 'true' : 'false' ?>,
            canDelete: <?= $permissions['can_delete'] ? 'true' : 'false' ?>
        };

        function escapeHtml(text) {
            if (text === null || text === undefined) return '';
            return String(text)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
        }

        function formatPara(deger) {
            const sayi = parseFloat(deger || 0);
            try {
                return new Intl.NumberFormat('tr-TR', { style: 'currency', currency: PARA_BIRIMI }).format(sayi);
            } catch (e) {
                return sayi.toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ' + PARA_BIRIMI;
            }
        }

        function formatTarih(ymd) {
            if (!ymd) return null;
            const p = String(ymd).substring(0, 10).split('-');
            return p.length === 3 ? `${p[2]}.${p[1]}.${p[0]}` : null;
        }

        // 'YYYY-MM-DD' üzerinde saat diliminden bağımsız tarih aritmetiği
        function tarihEkle(ymd, yil, gun) {
            const p = ymd.split('-').map(Number);
            const d = new Date(Date.UTC(p[0] + yil, p[1] - 1, p[2] + gun));
            return d.toISOString().substring(0, 10);
        }

        $(document).ready(function() {
            sozlesmeModal = new bootstrap.Modal(document.getElementById('sozlesmeModal'));

            $('.select2-filter').select2({ theme: 'bootstrap-5', width: '100%', placeholder: 'Seçiniz...', allowClear: true });
            $('#firma_id, #odeme_durumu_id').select2({ theme: 'bootstrap-5', width: '100%', placeholder: 'Seçiniz...', dropdownParent: $('#sozlesmeModal') });

            // URL'den gelen firma filtresi (?firma_id=)
            const urlFirmaId = new URLSearchParams(window.location.search).get('firma_id');
            if (urlFirmaId) {
                currentfilters.firma_id = urlFirmaId;
                $('#filterCard').addClass('show');
            }

            dataTable = $('#sozlesmeTable').DataTable({
                language: { url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/tr.json' },
                serverSide: true,
                processing: true,
                dom: 'lrtip',
                scrollX: true,
                pageLength: 25,
                lengthMenu: [[10, 25, 50, 100, 250], [10, 25, 50, 100, 250]],
                order: [[2, 'desc']],
                ajax: {
                    url: '',
                    type: 'POST',
                    data: function(d) {
                        d.action = 'list';
                        Object.assign(d, currentfilters);
                    },
                    error: function() { showToast('Liste yüklenirken hata oluştu', 'error'); }
                },
                columns: [
                    { data: 'firma_adi', render: function(v, t, r) {
                        let html = `<strong>${escapeHtml(v)}</strong>`;
                        if (r.firma_vkn_tc) html += `<br><small class="text-muted">${escapeHtml(r.firma_vkn_tc)}</small>`;
                        if (r.aciklama) {
                            const kisa = r.aciklama.length > 50 ? r.aciklama.substring(0, 50) + '...' : r.aciklama;
                            html += `<br><small class="text-muted" title="${escapeHtml(r.aciklama)}"><i class="bi bi-chat-left-text"></i> ${escapeHtml(kisa)}</small>`;
                        }
                        return html;
                    }},
                    { data: 'baslangic', render: function(v, t, r) {
                        let html = formatTarih(v) || '-';
                        const gun = parseInt(r.baslamaya_gun, 10);
                        if (gun > 0) html += `<br><small class="badge text-bg-info">${gun} gün sonra başlar</small>`;
                        return html;
                    }},
                    { data: 'bitis', render: function(v, t, r) {
                        const kalan = parseInt(r.kalan_gun, 10);
                        const basladi = parseInt(r.baslamaya_gun, 10) <= 0;
                        let renk = 'success', ek = '';
                        if (kalan < 0) {
                            renk = 'danger';
                            ek = `<br><small class="text-danger">${Math.abs(kalan)} gün önce bitti</small>`;
                        } else if (basladi && kalan <= YAKLASAN_GUN) {
                            renk = 'warning';
                            ek = `<br><small>${kalan} gün kaldı</small>`;
                        } else if (!basladi) {
                            renk = 'secondary';
                        }
                        return `<span class="badge text-bg-${renk}">${formatTarih(v)}</span>${ek}`;
                    }},
                    { data: 'tutar', className: 'text-end tutar', render: v => formatPara(v) },
                    { data: 'odeme_durumu_adi', render: function(v, t, r) {
                        return `<span class="badge text-bg-${escapeHtml(r.odeme_durumu_renk || 'secondary')}">
                            <i class="${escapeHtml(r.odeme_durumu_ikon || 'bi bi-dot')}"></i> ${escapeHtml(v || '-')}</span>`;
                    }},
                    { data: 'odeme_tarihi', render: v => formatTarih(v) || '<span class="text-muted">-</span>' },
                    { data: 'durum', render: v => v == 1
                        ? '<span class="status-badge status-active">Aktif</span>'
                        : '<span class="status-badge status-inactive">Pasif</span>' },
                    { data: null, orderable: false, className: 'text-nowrap', render: function(v, t, r) {
                        if (r.degistirilebilir != 1) return '<span class="text-muted small">Salt okunur</span>';
                        let html = '';
                        if (permissions.canAdd) {
                            html += `<button class="btn btn-sm btn-success" onclick="yenileSozlesme(${r.firma_sozlesme_id})" title="Sonraki Dönemi Oluştur"><i class="bi bi-arrow-repeat"></i></button> `;
                        }
                        if (permissions.canEdit) {
                            html += `<button class="btn btn-sm btn-warning" onclick="duzenleSozlesme(${r.firma_sozlesme_id})" title="Düzenle"><i class="bi bi-pencil"></i></button> `;
                        }
                        if (permissions.canDelete) {
                            html += `<button class="btn btn-sm btn-danger" onclick="silSozlesme(${r.firma_sozlesme_id})" title="Sil"><i class="bi bi-trash"></i></button>`;
                        }
                        return html || '-';
                    }}
                ],
                initComplete: function() {
                    loadStats();
                    loadYillikOzet();
                }
            });

            loadFirmalar();
            loadOdemeDurumlari();
            loadYillar();

            $('#firma_sozlesme_baslangic_tarihi').on('change', function() {
                const bas = $(this).val();
                if (bas && !$('#firma_sozlesme_bitis_tarihi').val()) {
                    $('#firma_sozlesme_bitis_tarihi').val(tarihEkle(bas, 1, -1));
                }
            });

            $('#odeme_durumu_id').on('change', odemeTarihiDurumu);

            $('#sozlesmeForm').on('submit', function(e) {
                e.preventDefault();
                kaydetSozlesme();
            });

            $('#filterForm').on('submit', function(e) {
                e.preventDefault();
                currentfilters = {
                    search_text: $('#filter_search').val(),
                    firma_id: $('#filter_firma_id').val(),
                    yil: $('#filter_yil').val(),
                    gecerlilik: $('#filter_gecerlilik').val(),
                    odeme_durumu_id: $('#filter_odeme_durumu_id').val(),
                    status: $('#filter_status').val()
                };
                Object.keys(currentfilters).forEach(k => { if (!currentfilters[k]) delete currentfilters[k]; });
                listeYenile();
                showToast('Filtre uygulandı', 'info');
            });

            $('#clearfilters').on('click', function() {
                $('#filterForm')[0].reset();
                $('.select2-filter').val('').trigger('change.select2');
                currentfilters = {};
                listeYenile();
                showToast('Filtreler temizlendi', 'info');
            });
        });

        function listeYenile(sayfayiKoru) {
            dataTable.ajax.reload(null, !sayfayiKoru);
            loadYillikOzet();
        }

        function loadStats() {
            $.post('', { action: 'stats' }, function(r) {
                if (!r.success) return;
                $('#stat-gecerli').text(r.data.gecerli);
                $('#stat-yaklasan').text(r.data.yaklasan);
                $('#stat-kapali').text(r.data.kapali_firma);
                $('#stat-odenen').text(formatPara(r.data.bu_yil_odenen));
            }, 'json');
        }

        function loadYillikOzet() {
            $.post('', { action: 'yillik_ozet', ...currentfilters }, function(r) {
                const $tbody = $('#yillikOzetTable tbody').empty();
                if (!r.success || !r.data.length) {
                    $tbody.append('<tr><td colspan="3" class="text-center text-muted py-3">Kayıt yok</td></tr>');
                    return;
                }
                r.data.forEach(y => {
                    $tbody.append(`
                        <tr title="${y.sozlesme_sayisi} sözleşme · ${y.firma_sayisi} firma · Toplam ${formatPara(y.toplam)}">
                            <td><strong>${escapeHtml(y.yil)}</strong><br><small class="text-muted">${y.sozlesme_sayisi} sözleşme</small></td>
                            <td class="text-end tutar text-success">${formatPara(y.odenen)}</td>
                            <td class="text-end tutar ${parseFloat(y.bekleyen) > 0 ? 'text-warning' : 'text-muted'}">${formatPara(y.bekleyen)}</td>
                        </tr>
                    `);
                });
            }, 'json');
        }

        function loadFirmalar() {
            $.post('', { action: 'firma_list' }, function(r) {
                if (!r.success) return;
                const $filtre = $('#filter_firma_id');
                const $modal = $('#firma_id').empty().append(new Option('Seçiniz...', '', false, false));
                r.data.forEach(f => {
                    const etiket = f.firma_vkn_tc ? `${f.firma_adi} (${f.firma_vkn_tc})` : f.firma_adi;
                    $filtre.append(new Option(etiket, f.firma_id, false, false));
                    if (f.degistirilebilir == 1) $modal.append(new Option(etiket, f.firma_id, false, false));
                });
                if (currentfilters.firma_id) $filtre.val(currentfilters.firma_id);
                $filtre.trigger('change.select2');
                $modal.trigger('change.select2');
            }, 'json');
        }

        function loadOdemeDurumlari() {
            $.post('', { action: 'odeme_durumu_list' }, function(r) {
                if (!r.success) return;
                odemeDurumlari = r.data;
                const $filtre = $('#filter_odeme_durumu_id');
                const $modal = $('#odeme_durumu_id').empty();
                odemeDurumlari.forEach(d => {
                    $filtre.append(new Option(d.odeme_durumu_adi, d.odeme_durumu_id, false, false));
                    $modal.append(new Option(d.odeme_durumu_adi, d.odeme_durumu_id, false, false));
                });
                $filtre.trigger('change.select2');
                $modal.trigger('change.select2');
            }, 'json');
        }

        function loadYillar() {
            $.post('', { action: 'yil_list' }, function(r) {
                if (!r.success) return;
                const $filtre = $('#filter_yil');
                r.data.forEach(y => $filtre.append(new Option(y, y, false, false)));
                $filtre.trigger('change.select2');
            }, 'json');
        }

        function varsayilanOdemeDurumu() {
            const d = odemeDurumlari.find(x => x.odeme_durumu_varsayilan == 1) || odemeDurumlari[0];
            return d ? d.odeme_durumu_id : '';
        }

        // "Ödendi" sayılan durumda ödeme tarihi zorunlu, diğerlerinde kapalı
        function odemeTarihiDurumu() {
            const d = odemeDurumlari.find(x => String(x.odeme_durumu_id) === String($('#odeme_durumu_id').val()));
            const odendi = d && d.odeme_durumu_odendi == 1;
            const $tarih = $('#firma_sozlesme_odeme_tarihi');
            $tarih.prop('disabled', !odendi).prop('required', odendi);
            $('#odeme_tarihi_zorunlu').toggleClass('d-none', !odendi);
            if (!odendi) {
                $tarih.val('');
            } else if (!$tarih.val()) {
                $tarih.val(new Date().toLocaleDateString('en-CA'));
            }
        }

        function formuTemizle() {
            document.getElementById('sozlesmeForm').reset();
            $('#firma_sozlesme_id').val('');
            $('#firma_id').val(currentfilters.firma_id || '').trigger('change.select2');
            $('#odeme_durumu_id').val(varsayilanOdemeDurumu()).trigger('change.select2');
            odemeTarihiDurumu();
        }

        function yeniSozlesme() {
            formuTemizle();
            $('#sozlesmeModalLabel').text('Yeni Sözleşme');
            sozlesmeModal.show();
        }

        function sozlesmeGetir(id, callback) {
            $.post('', { action: 'get', firma_sozlesme_id: id }, function(r) {
                if (r.success && r.data) {
                    callback(r.data);
                } else {
                    showToast(r.message || 'Sözleşme yüklenemedi', 'error');
                }
            }, 'json').fail(() => showToast('Kayıt yüklenirken hata oluştu', 'error'));
        }

        function duzenleSozlesme(id) {
            sozlesmeGetir(id, function(s) {
                formuTemizle();
                $('#firma_sozlesme_id').val(s.firma_sozlesme_id);
                $('#firma_id').val(s.firma_id).trigger('change.select2');
                $('#firma_sozlesme_baslangic_tarihi').val(s.firma_sozlesme_baslangic_tarihi);
                $('#firma_sozlesme_bitis_tarihi').val(s.firma_sozlesme_bitis_tarihi);
                $('#firma_sozlesme_tutar').val(s.firma_sozlesme_tutar);
                $('#odeme_durumu_id').val(s.odeme_durumu_id).trigger('change.select2');
                $('#firma_sozlesme_odeme_tarihi').val(s.firma_sozlesme_odeme_tarihi || '');
                odemeTarihiDurumu();
                $('#firma_sozlesme_aciklama').val(s.firma_sozlesme_aciklama || '');
                $('#firma_sozlesme_durum').prop('checked', s.firma_sozlesme_durum == 1);
                $('#sozlesmeModalLabel').text('Sözleşme Düzenle');
                sozlesmeModal.show();
            });
        }

        // Bitişin ertesi gününden başlayan 1 yıllık yeni dönem önerir
        function yenileSozlesme(id) {
            sozlesmeGetir(id, function(s) {
                formuTemizle();
                const bas = tarihEkle(s.firma_sozlesme_bitis_tarihi, 0, 1);
                $('#firma_id').val(s.firma_id).trigger('change.select2');
                $('#firma_sozlesme_baslangic_tarihi').val(bas);
                $('#firma_sozlesme_bitis_tarihi').val(tarihEkle(bas, 1, -1));
                $('#firma_sozlesme_tutar').val(s.firma_sozlesme_tutar);
                $('#sozlesmeModalLabel').text('Sonraki Dönem Sözleşmesi');
                sozlesmeModal.show();
            });
        }

        function kaydetSozlesme() {
            const $btn = $('#kaydetBtn');
            $btn.prop('disabled', true).html('<i class="bi bi-hourglass-split"></i> Kaydediliyor...');

            $.ajax({
                url: '',
                method: 'POST',
                data: $('#sozlesmeForm').serialize() + '&action=save',
                dataType: 'json',
                success: function(r) {
                    if (r.success) {
                        showToast(r.message, 'success');
                        sozlesmeModal.hide();
                        listeYenile(true);
                        loadStats();
                        loadYillar();
                    } else {
                        showToast(r.message, 'error');
                    }
                },
                error: function() { showToast('Kayıt sırasında hata oluştu', 'error'); },
                complete: function() { $btn.prop('disabled', false).html('<i class="bi bi-save"></i> Kaydet'); }
            });
        }

        function silSozlesme(id) {
            confirmAction(
                'Bu sözleşmeyi silmek istediğinize emin misiniz?',
                'Ödeme geçmişinden de kaldırılır. Kaydı saklamak için pasife almayı tercih edin.',
                function() {
                    $.post('', { action: 'delete', firma_sozlesme_id: id }, function(r) {
                        if (r.success) {
                            showSuccess('Silindi!', r.message);
                            listeYenile(true);
                            loadStats();
                        } else {
                            showError('Hata!', r.message);
                        }
                    }, 'json').fail(() => showError('Bağlantı Hatası!', 'Sunucuya ulaşılamıyor.'));
                }
            );
        }
    </script>
</body>
</html>
