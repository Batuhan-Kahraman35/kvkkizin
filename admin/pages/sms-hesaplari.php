<?php
/**
 * Admin Panel - SMS Hesapları
 *
 * Firma bazlı SMS operatör hesaplarını (kullanıcı adı, şifre, gönderici başlığı) yönetir.
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
require_once __DIR__ . '/../includes/SmsApi.php';
require_once __DIR__ . '/../includes/TelefonHelper.php';
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

$pageTitle = $pageinfo['sayfalar_sayfa_adi'] ?? 'SMS Hesapları';
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

// Test SMS alıcısı için varsayılan: oturumdaki kullanıcının telefonu
$kullaniciTelefon = $db->fetchOne("SELECT kullanici_telefon FROM kullanicilar WHERE kullanici_id = ?", [$user['kullanici_id']])['kullanici_telefon'] ?? '';

// AJAX işlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    $action = $_POST['action'] ?? '';

    try {
        [$scopeSql, $scopeParams] = PageAuth::firmaScopeWhere($firmaScopeIds, 'h.sms_hesap_firma_id');

        switch ($action) {
            case 'list':
                $search   = $_POST['search'] ?? '';
                $firmaId  = $_POST['firma_id'] ?? '';
                $operator = $_POST['operator_id'] ?? '';
                $status   = $_POST['status'] ?? '';

                $whereConditions = [$scopeSql];
                $params = $scopeParams;

                if ($search) {
                    $whereConditions[] = "(h.sms_hesap_kullanici_adi LIKE ? OR h.sms_hesap_gonderici LIKE ? OR f.firma_adi LIKE ?)";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                }
                if ($firmaId !== '') {
                    $whereConditions[] = "h.sms_hesap_firma_id = ?";
                    $params[] = (int)$firmaId;
                }
                if ($operator !== '') {
                    $whereConditions[] = "h.sms_hesap_operator_id = ?";
                    $params[] = (int)$operator;
                }
                if ($status !== '') {
                    $whereConditions[] = "h.Durum = ?";
                    $params[] = (int)$status;
                }

                $whereClause = implode(" AND ", $whereConditions);

                $hesaplar = $db->fetchAll("
                    SELECT
                        h.sms_hesap_id,
                        h.sms_hesap_firma_id,
                        h.sms_hesap_operator_id,
                        h.sms_hesap_kullanici_adi,
                        h.sms_hesap_gonderici,
                        h.sms_hesap_varsayilan,
                        h.sms_hesap_webhook_url,
                        h.sms_hesap_ticari,
                        h.sms_hesap_encoding,
                        h.sms_hesap_gecerlilik,
                        h.sms_hesap_gunluk_limit,
                        h.sms_hesap_kredi,
                        CONVERT(VARCHAR(19), h.sms_hesap_kredi_tarihi, 120) as sms_hesap_kredi_tarihi,
                        h.sms_hesap_son_test_durum,
                        CONVERT(VARCHAR(19), h.sms_hesap_son_test_tarihi, 120) as sms_hesap_son_test_tarihi,
                        h.sms_hesap_son_test_mesaj,
                        h.sms_hesap_aciklama,
                        h.Durum,
                        f.firma_adi,
                        o.sms_operator_adi,
                        o.sms_operator_kod,
                        o.Durum as operator_durum
                    FROM SmsHesaplari h
                    INNER JOIN Firmalar f ON h.sms_hesap_firma_id = f.firma_id
                    INNER JOIN SmsOperatorleri o ON h.sms_hesap_operator_id = o.sms_operator_id
                    WHERE $whereClause
                    ORDER BY f.firma_adi, h.sms_hesap_varsayilan DESC, h.sms_hesap_kullanici_adi
                ", $params);

                echo json_encode(['success' => true, 'data' => $hesaplar]);
                break;

            case 'firma_list':
                [$fSql, $fParams] = PageAuth::firmaScopeWhere($firmaScopeIds, 'firma_id');
                $firmalar = $db->fetchAll("
                    SELECT firma_id, firma_adi
                    FROM Firmalar
                    WHERE firma_durum = 1 AND $fSql
                    ORDER BY firma_adi
                ", $fParams);
                echo json_encode(['success' => true, 'data' => $firmalar]);
                break;

            case 'operator_list':
                $operatorler = $db->fetchAll("
                    SELECT sms_operator_id, sms_operator_adi, sms_operator_kod, sms_operator_gonderici_zorunlu
                    FROM SmsOperatorleri
                    WHERE Durum = 1
                    ORDER BY sms_operator_sira, sms_operator_adi
                ");
                echo json_encode(['success' => true, 'data' => $operatorler]);
                break;

            case 'get':
                $id = (int)($_POST['sms_hesap_id'] ?? 0);
                $hesap = $db->fetchOne("SELECT * FROM SmsHesaplari WHERE sms_hesap_id = ?", [$id]);

                if (!$hesap || !$firmaKapsamdaMi($hesap['sms_hesap_firma_id'])) {
                    echo json_encode(['success' => false, 'message' => 'Kayıt bulunamadı!']);
                    break;
                }

                // Şifre istemciye gönderilmez
                unset($hesap['sms_hesap_sifre']);
                echo json_encode(['success' => true, 'data' => $hesap]);
                break;

            case 'save':
                $id = (int)($_POST['sms_hesap_id'] ?? 0);

                if ($id > 0 && !$permissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Düzenleme yetkiniz yok!']);
                    break;
                }
                if ($id == 0 && !$permissions['can_add']) {
                    echo json_encode(['success' => false, 'message' => 'Ekleme yetkiniz yok!']);
                    break;
                }

                $firmaId = (int)($_POST['sms_hesap_firma_id'] ?? 0);
                if ($firmaId <= 0 || !$firmaKapsamdaMi($firmaId)) {
                    echo json_encode(['success' => false, 'message' => 'Geçerli bir firma seçmelisiniz!']);
                    break;
                }

                $operatorId = (int)($_POST['sms_hesap_operator_id'] ?? 0);
                $operator = $db->fetchOne("SELECT * FROM SmsOperatorleri WHERE sms_operator_id = ?", [$operatorId]);
                if (!$operator) {
                    echo json_encode(['success' => false, 'message' => 'Geçerli bir operatör seçmelisiniz!']);
                    break;
                }

                $kullaniciAdi = trim($_POST['sms_hesap_kullanici_adi'] ?? '');
                if ($kullaniciAdi === '') {
                    echo json_encode(['success' => false, 'message' => 'Kullanıcı adı boş olamaz!']);
                    break;
                }

                $sifre = (string)($_POST['sms_hesap_sifre'] ?? '');
                if ($id == 0 && $sifre === '') {
                    echo json_encode(['success' => false, 'message' => 'Şifre boş olamaz!']);
                    break;
                }

                $gonderici = trim($_POST['sms_hesap_gonderici'] ?? '');
                if ($operator['sms_operator_gonderici_zorunlu'] && $gonderici === '') {
                    echo json_encode(['success' => false, 'message' => 'Bu operatörde gönderici başlığı zorunludur!']);
                    break;
                }

                // Aynı firmada aynı operatör için aynı kullanıcı adı iki kez olamaz
                $mukerrer = $db->fetchOne("
                    SELECT count(*) as count FROM SmsHesaplari
                    WHERE sms_hesap_firma_id = ? AND sms_hesap_operator_id = ? AND sms_hesap_kullanici_adi = ? AND sms_hesap_id <> ?
                ", [$firmaId, $operatorId, $kullaniciAdi, $id])['count'] ?? 0;
                if ($mukerrer > 0) {
                    echo json_encode(['success' => false, 'message' => 'Bu firma ve operatör için aynı kullanıcı adı zaten tanımlı!']);
                    break;
                }

                $varsayilan = isset($_POST['sms_hesap_varsayilan']) ? 1 : 0;
                $durum = isset($_POST['Durum']) ? 1 : 0;

                $encoding = $_POST['sms_hesap_encoding'] ?? '';
                $gecerlilik = $_POST['sms_hesap_gecerlilik'] ?? '';
                $gunlukLimit = $_POST['sms_hesap_gunluk_limit'] ?? '';

                $data = [
                    'sms_hesap_firma_id'      => $firmaId,
                    'sms_hesap_operator_id'   => $operatorId,
                    'sms_hesap_kullanici_adi' => $kullaniciAdi,
                    'sms_hesap_gonderici'     => $gonderici ?: null,
                    'sms_hesap_varsayilan'    => $varsayilan,
                    'sms_hesap_webhook_url'   => trim($_POST['sms_hesap_webhook_url'] ?? '') ?: null,
                    'sms_hesap_ticari'        => isset($_POST['sms_hesap_ticari']) ? 1 : 0,
                    'sms_hesap_otp_kullan'    => isset($_POST['sms_hesap_otp_kullan']) ? 1 : 0,
                    'sms_hesap_encoding'      => ($encoding === '' ? null : (int)$encoding),
                    'sms_hesap_gecerlilik'    => ($gecerlilik === '' ? null : (int)$gecerlilik),
                    'sms_hesap_gunluk_limit'  => ($gunlukLimit === '' ? null : (int)$gunlukLimit),
                    'sms_hesap_aciklama'      => trim($_POST['sms_hesap_aciklama'] ?? '') ?: null,
                    'Durum'                   => $durum,
                ];

                // Şifre yalnızca doldurulduysa güncellenir
                if ($sifre !== '') {
                    $data['sms_hesap_sifre'] = $sifre;
                }

                // Filtreli benzersiz indeks: firmada tek varsayılan aktif hesap
                if ($varsayilan && $durum) {
                    $db->execute("
                        UPDATE SmsHesaplari SET sms_hesap_varsayilan = 0
                        WHERE sms_hesap_firma_id = ? AND sms_hesap_id <> ?
                    ", [$firmaId, $id]);
                }

                if ($id > 0) {
                    $mevcut = $db->fetchOne("SELECT sms_hesap_firma_id FROM SmsHesaplari WHERE sms_hesap_id = ?", [$id]);
                    if (!$mevcut || !$firmaKapsamdaMi($mevcut['sms_hesap_firma_id'])) {
                        echo json_encode(['success' => false, 'message' => 'Bu kaydı düzenleme yetkiniz yok!']);
                        break;
                    }

                    $data['GuncellemeTarihi'] = date('Y-m-d H:i:s');
                    $data['GuncelleyenKullanici'] = $user['kullanici_id'];

                    $result = $db->update('SmsHesaplari', $data, ['sms_hesap_id' => $id]);
                    echo json_encode(['success' => $result, 'message' => $result ? 'SMS hesabı başarıyla güncellendi' : 'Güncelleme hatası']);
                } else {
                    $data['OlusturmaTarihi'] = date('Y-m-d H:i:s');
                    $data['OlusturanKullanici'] = $user['kullanici_id'];

                    $result = $db->insert('SmsHesaplari', $data);
                    echo json_encode(['success' => (bool)$result, 'message' => $result ? 'SMS hesabı başarıyla eklendi' : 'Ekleme hatası']);
                }
                break;

            case 'test':
                $id = (int)($_POST['sms_hesap_id'] ?? 0);
                $hesap = SmsApi::hesapGetir($id);

                if (!$hesap || !$firmaKapsamdaMi($hesap['sms_hesap_firma_id'])) {
                    echo json_encode(['success' => false, 'message' => 'Kayıt bulunamadı!']);
                    break;
                }

                $sonuc = SmsApi::kredi($hesap);
                $mesaj = $sonuc['success']
                    ? ('Bağlantı başarılı' . ($sonuc['kredi'] !== null ? ' - Kredi: ' . $sonuc['kredi'] : ''))
                    : $sonuc['message'];

                SmsApi::testKaydet($id, $sonuc['success'], $mesaj, $sonuc['kredi']);

                // Test sonucuna göre hesap durumu: başarılı → aktif, hatalı → pasif (düzenleme yetkisi gerekir)
                $durumMesaj = '';
                $yeniDurum = $sonuc['success'] ? 1 : 0;
                if ($permissions['can_edit'] && (int)$hesap['Durum'] !== $yeniDurum) {
                    $durumData = [
                        'Durum'                => $yeniDurum,
                        'GuncellemeTarihi'     => date('Y-m-d H:i:s'),
                        'GuncelleyenKullanici' => $user['kullanici_id'],
                    ];

                    // Firmada aktif varsayılan hesap varsa mevcut varsayılan korunur (UX_SmsHesaplari_FirmaVarsayilan)
                    if ($yeniDurum && $hesap['sms_hesap_varsayilan']) {
                        $digerVarsayilan = $db->fetchOne("
                            SELECT TOP 1 sms_hesap_id FROM SmsHesaplari
                            WHERE sms_hesap_firma_id = ? AND sms_hesap_id <> ? AND sms_hesap_varsayilan = 1 AND Durum = 1
                        ", [$hesap['sms_hesap_firma_id'], $id]);
                        if ($digerVarsayilan) {
                            $durumData['sms_hesap_varsayilan'] = 0;
                        }
                    }

                    $db->update('SmsHesaplari', $durumData, ['sms_hesap_id' => $id]);
                    $durumMesaj = $yeniDurum ? 'Hesap aktif edildi.' : 'Hesap pasife alındı.';
                }

                echo json_encode([
                    'success' => $sonuc['success'],
                    'message' => $mesaj . ($durumMesaj ? ' — ' . $durumMesaj : ''),
                    'kredi'   => $sonuc['kredi'],
                    'yanit'   => mb_substr((string)$sonuc['ham'], 0, 1000, 'UTF-8'),
                ]);
                break;

            case 'test_hesap_list':
                // Test SMS modalındaki hesap seçimi: kapsamdaki aktif hesaplar
                $hesaplar = $db->fetchAll("
                    SELECT h.sms_hesap_id, h.sms_hesap_kullanici_adi, h.sms_hesap_gonderici,
                           f.firma_adi, o.sms_operator_adi
                    FROM SmsHesaplari h
                    INNER JOIN Firmalar f ON h.sms_hesap_firma_id = f.firma_id
                    INNER JOIN SmsOperatorleri o ON h.sms_hesap_operator_id = o.sms_operator_id
                    WHERE h.Durum = 1 AND $scopeSql
                    ORDER BY f.firma_adi, h.sms_hesap_varsayilan DESC, h.sms_hesap_kullanici_adi
                ", $scopeParams);
                echo json_encode(['success' => true, 'data' => $hesaplar]);
                break;

            case 'test_sms':
                // Gerçek SMS gönderir, kredi düşer: düzenleme yetkisi gerekir
                if (!$permissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Test SMS gönderme yetkiniz yok!']);
                    break;
                }

                $id = (int)($_POST['sms_hesap_id'] ?? 0);
                $hesap = SmsApi::hesapGetir($id);
                if (!$hesap || !$firmaKapsamdaMi($hesap['sms_hesap_firma_id'])) {
                    echo json_encode(['success' => false, 'message' => 'Hesap bulunamadı!']);
                    break;
                }

                $telefonHata = null;
                $telefon = telefonNormalizeEtGuvenli($_POST['telefon'] ?? '', $telefonHata);
                if ($telefon === null) {
                    echo json_encode(['success' => false, 'message' => $telefonHata ?: 'Telefon numarası boş olamaz!']);
                    break;
                }

                $mesaj = trim((string)($_POST['mesaj'] ?? ''));
                if ($mesaj === '') {
                    echo json_encode(['success' => false, 'message' => 'Mesaj boş olamaz!']);
                    break;
                }
                if (mb_strlen($mesaj, 'UTF-8') > 918) {
                    echo json_encode(['success' => false, 'message' => 'Mesaj en fazla 918 karakter olabilir!']);
                    break;
                }

                $logEk = [
                    'smslog_kullanici_id' => (int)$user['kullanici_id'],
                    'smslog_amac'         => 'test',
                ];

                $sonuc = ($_POST['tip'] ?? '') === 'otp'
                    ? SmsApi::otpGonder($hesap, $telefon, $mesaj, $logEk)
                    : SmsApi::gonder($hesap, $telefon, $mesaj, 'Test', $logEk);

                // Hata durumunda başlığın operatördeki onay durumuna bakılır
                $aciklama = empty($sonuc['success']) ? SmsApi::gondericiSorunu($hesap) : null;

                echo json_encode([
                    'success'  => !empty($sonuc['success']),
                    'message'  => !empty($sonuc['success']) ? 'Test SMS gönderildi' : ($sonuc['message'] ?? 'Gönderim başarısız'),
                    'aciklama' => $aciklama,
                    'http_kod' => $sonuc['http_kod'] ?? null,
                    'log_id'   => $sonuc['log_id'] ?? null,
                    'yanit'    => mb_substr((string)($sonuc['ham'] ?? ''), 0, 1000, 'UTF-8'),
                ]);
                break;

            case 'gonderici_getir':
                // Kayıtlı hesap üzerinden operatörden başlıkları çeker
                $id = (int)($_POST['sms_hesap_id'] ?? 0);
                $hesap = SmsApi::hesapGetir($id);

                if (!$hesap || !$firmaKapsamdaMi($hesap['sms_hesap_firma_id'])) {
                    echo json_encode(['success' => false, 'message' => 'Önce hesabı kaydedin!']);
                    break;
                }

                $sonuc = SmsApi::gondericiListesi($hesap);
                echo json_encode([
                    'success' => $sonuc['success'],
                    'message' => $sonuc['success'] ? 'Başlıklar alındı' : $sonuc['message'],
                    'data'    => $sonuc['gondericiler'] ?? [],
                    'detay'   => $sonuc['gondericiDetay'] ?? [],
                ]);
                break;

            case 'toggle_status':
                if (!$permissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Düzenleme yetkiniz yok!']);
                    break;
                }

                $id = (int)($_POST['sms_hesap_id'] ?? 0);
                $mevcut = $db->fetchOne("SELECT sms_hesap_firma_id, Durum FROM SmsHesaplari WHERE sms_hesap_id = ?", [$id]);
                if (!$mevcut || !$firmaKapsamdaMi($mevcut['sms_hesap_firma_id'])) {
                    echo json_encode(['success' => false, 'message' => 'Hesap bulunamadı!']);
                    break;
                }

                $yeniDurum = $mevcut['Durum'] ? 0 : 1;
                $db->update('SmsHesaplari', [
                    'Durum' => $yeniDurum,
                    'GuncellemeTarihi' => date('Y-m-d H:i:s'),
                    'GuncelleyenKullanici' => $user['kullanici_id'],
                ], ['sms_hesap_id' => $id]);

                echo json_encode(['success' => true, 'message' => $yeniDurum ? 'Hesap aktif edildi' : 'Hesap pasife alındı']);
                break;

            case 'delete':
                if (!$permissions['can_delete']) {
                    echo json_encode(['success' => false, 'message' => 'Silme yetkiniz yok!']);
                    break;
                }

                $id = (int)($_POST['sms_hesap_id'] ?? 0);
                $mevcut = $db->fetchOne("SELECT sms_hesap_firma_id FROM SmsHesaplari WHERE sms_hesap_id = ?", [$id]);
                if (!$mevcut || !$firmaKapsamdaMi($mevcut['sms_hesap_firma_id'])) {
                    echo json_encode(['success' => false, 'message' => 'Hesap bulunamadı!']);
                    break;
                }

                $result = $db->delete('SmsHesaplari', ['sms_hesap_id' => $id]);
                echo json_encode(['success' => true, 'message' => $result ? 'SMS hesabı silindi' : 'Silme hatası']);
                break;

            case 'stats':
                $stats = [
                    'toplam'   => $db->fetchOne("SELECT count(*) as count FROM SmsHesaplari h WHERE $scopeSql", $scopeParams)['count'] ?? 0,
                    'aktif'    => $db->fetchOne("SELECT count(*) as count FROM SmsHesaplari h WHERE h.Durum = 1 AND $scopeSql", $scopeParams)['count'] ?? 0,
                    'firma'    => $db->fetchOne("SELECT count(DISTINCT h.sms_hesap_firma_id) as count FROM SmsHesaplari h WHERE h.Durum = 1 AND $scopeSql", $scopeParams)['count'] ?? 0,
                    'kredi'    => $db->fetchOne("SELECT ISNULL(SUM(h.sms_hesap_kredi), 0) as count FROM SmsHesaplari h WHERE h.Durum = 1 AND $scopeSql", $scopeParams)['count'] ?? 0,
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
                                <span class="info-box-icon"><i class="bi bi-person-badge"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Toplam Hesap</span>
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
                            <div class="info-box text-bg-secondary">
                                <span class="info-box-icon"><i class="bi bi-building"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Hesabı Olan Firma</span>
                                    <span class="info-box-number" id="stat-firma">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-box text-bg-info">
                                <span class="info-box-icon"><i class="bi bi-coin"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Toplam Kredi</span>
                                    <span class="info-box-number" id="stat-kredi">0</span>
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
                                    <div class="col-md-3">
                                        <label class="form-label">Ara</label>
                                        <input type="text" class="form-control" name="search" id="filter_search" placeholder="Kullanıcı adı, gönderici başlığı, firma...">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Firma</label>
                                        <select class="form-select select2-filter" name="firma_id" id="filter_firma" style="width: 100%;">
                                            <option value="">Tümü</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Operatör</label>
                                        <select class="form-select select2-filter" name="operator_id" id="filter_operator" style="width: 100%;">
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
                            <h3 class="card-title">SMS Hesap Listesi</h3>
                            <div class="card-tools">
                                <?php if ($permissions['can_edit']): ?>
                                <button type="button" class="btn btn-success btn-sm" onclick="testSmsAc()">
                                    <i class="bi bi-send"></i> Test SMS Gönder
                                </button>
                                <?php endif; ?>
                                <?php if ($permissions['can_add']): ?>
                                <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#hesapModal" onclick="resetForm()">
                                    <i class="bi bi-plus-circle"></i> Yeni Hesap Ekle
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="card-body">
                            <table id="hesapTable" class="table table-bordered table-striped table-hover">
                                <thead>
                                    <tr>
                                        <th>Firma</th>
                                        <th>Operatör</th>
                                        <th>Kullanıcı Adı</th>
                                        <th>Gönderici</th>
                                        <th>Kredi</th>
                                        <th>Son Test</th>
                                        <th>Varsayılan</th>
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

    <!-- Hesap Modal -->
    <div class="modal fade" id="hesapModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="hesapModalLabel">Yeni Hesap Ekle</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                </div>
                <form id="hesapForm">
                    <div class="modal-body">
                        <input type="hidden" id="sms_hesap_id" name="sms_hesap_id" value="">

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="sms_hesap_firma_id" class="form-label">Firma <span class="text-danger">*</span></label>
                                <select class="form-select" id="sms_hesap_firma_id" name="sms_hesap_firma_id" required style="width: 100%;"></select>
                            </div>
                            <div class="col-md-6">
                                <label for="sms_hesap_operator_id" class="form-label">SMS Operatörü <span class="text-danger">*</span></label>
                                <select class="form-select" id="sms_hesap_operator_id" name="sms_hesap_operator_id" required style="width: 100%;"></select>
                            </div>

                            <div class="col-md-4">
                                <label for="sms_hesap_kullanici_adi" class="form-label">Kullanıcı Adı <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="sms_hesap_kullanici_adi" name="sms_hesap_kullanici_adi" required maxlength="150" autocomplete="off">
                            </div>
                            <div class="col-md-4">
                                <label for="sms_hesap_sifre" class="form-label">Şifre <span class="text-danger" id="sifre_zorunlu">*</span></label>
                                <div class="input-group">
                                    <input type="password" class="form-control" id="sms_hesap_sifre" name="sms_hesap_sifre" maxlength="500" autocomplete="new-password">
                                    <button class="btn btn-outline-secondary" type="button" id="btnSifreGoster"><i class="bi bi-eye"></i></button>
                                </div>
                                <small class="text-muted" id="sifre_not"></small>
                            </div>
                            <div class="col-md-4">
                                <label for="sms_hesap_gonderici" class="form-label">Gönderici Başlığı</label>
                                <div class="input-group">
                                    <input type="text" class="form-control" id="sms_hesap_gonderici" name="sms_hesap_gonderici" maxlength="20" placeholder="FORM">
                                    <button class="btn btn-outline-primary" type="button" id="btnBaslikGetir" title="Operatörden başlıkları getir"><i class="bi bi-download"></i></button>
                                </div>
                                <small class="text-muted">Kayıtlı hesapta operatörden çekilebilir</small>
                            </div>

                            <div class="col-md-12">
                                <label for="sms_hesap_webhook_url" class="form-label">Webhook (Rapor) Adresi</label>
                                <input type="url" class="form-control" id="sms_hesap_webhook_url" name="sms_hesap_webhook_url" maxlength="500" placeholder="https://...">
                            </div>

                            <div class="col-md-3">
                                <label for="sms_hesap_encoding" class="form-label">Encoding</label>
                                <select class="form-select select2-modal" id="sms_hesap_encoding" name="sms_hesap_encoding" style="width: 100%;">
                                    <option value="">Operatör varsayılanı</option>
                                    <option value="0">0 - GSM</option>
                                    <option value="1">1 - Türkçe</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label for="sms_hesap_gecerlilik" class="form-label">Geçerlilik (dk)</label>
                                <input type="number" class="form-control" id="sms_hesap_gecerlilik" name="sms_hesap_gecerlilik" min="1" max="10080" placeholder="Operatör varsayılanı">
                            </div>
                            <div class="col-md-3">
                                <label for="sms_hesap_gunluk_limit" class="form-label">Günlük Limit</label>
                                <input type="number" class="form-control" id="sms_hesap_gunluk_limit" name="sms_hesap_gunluk_limit" min="1" placeholder="Limitsiz">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label d-block">&nbsp;</label>
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" role="switch" id="sms_hesap_ticari" name="sms_hesap_ticari" value="1">
                                    <label class="form-check-label" for="sms_hesap_ticari">Ticari İleti</label>
                                </div>
                            </div>

                            <div class="col-md-12">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" role="switch" id="sms_hesap_otp_kullan" name="sms_hesap_otp_kullan" value="1" checked>
                                    <label class="form-check-label" for="sms_hesap_otp_kullan">OTP Uç Noktasını Kullan</label>
                                </div>
                                <div class="form-text">
                                    Kapalıysa doğrulama kodları normal gönderim uç noktasından iletilir.
                                    Operatör OTP servisini bu hesap için açmadıysa kapatın.
                                </div>
                            </div>

                            <div class="col-md-12">
                                <label for="sms_hesap_aciklama" class="form-label">Açıklama</label>
                                <textarea class="form-control" id="sms_hesap_aciklama" name="sms_hesap_aciklama" rows="2" maxlength="500"></textarea>
                            </div>

                            <div class="col-md-6">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" role="switch" id="sms_hesap_varsayilan" name="sms_hesap_varsayilan" value="1">
                                    <label class="form-check-label" for="sms_hesap_varsayilan">Firmanın Varsayılan Hesabı</label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" role="switch" id="Durum" name="Durum" value="1" checked>
                                    <label class="form-check-label" for="Durum">Aktif</label>
                                </div>
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

    <!-- Test SMS Modal -->
    <div class="modal fade" id="testSmsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-send"></i> Test SMS Gönder</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                </div>
                <form id="testSmsForm">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-12">
                                <label for="test_hesap_id" class="form-label">SMS Hesabı <span class="text-danger">*</span></label>
                                <select class="form-select" id="test_hesap_id" name="sms_hesap_id" required style="width: 100%;"></select>
                            </div>
                            <div class="col-md-7">
                                <label for="test_telefon" class="form-label">Telefon <span class="text-danger">*</span></label>
                                <input type="tel" class="form-control" id="test_telefon" name="telefon" required maxlength="20"
                                       placeholder="05XX XXX XX XX" value="<?= htmlspecialchars((string)$kullaniciTelefon) ?>">
                            </div>
                            <div class="col-md-5">
                                <label for="test_tip" class="form-label">Gönderim Tipi</label>
                                <select class="form-select" id="test_tip" name="tip" style="width: 100%;">
                                    <option value="normal">Normal SMS</option>
                                    <option value="otp">OTP (Doğrulama)</option>
                                </select>
                            </div>
                            <div class="col-md-12">
                                <label for="test_mesaj" class="form-label">Mesaj <span class="text-danger">*</span></label>
                                <textarea class="form-control" id="test_mesaj" name="mesaj" rows="4" maxlength="918" required></textarea>
                                <small class="text-muted" id="test_mesaj_sayac">0 karakter · 1 SMS</small>
                            </div>
                            <div class="col-md-12 d-none" id="test_sonuc"></div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="bi bi-x-circle"></i> Kapat</button>
                        <button type="submit" class="btn btn-success" id="btnTestSmsGonder"><i class="bi bi-send"></i> Gönder</button>
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
        let hesapModal, dataTable;
        let currentfilters = {};
        let firmaListesi = [], operatorListesi = [];

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

        $(document).ready(function() {
            hesapModal = new bootstrap.Modal(document.getElementById('hesapModal'));

            dataTable = $('#hesapTable').DataTable({
                language: { url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/tr.json' },
                order: [[0, 'asc']],
                columnDefs: [{ orderable: false, targets: [8] }],
                pageLength: 25,
                lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "Tümü"]]
            });

            $('.select2-filter').select2({ theme: 'bootstrap-5', width: '100%', placeholder: 'Seçiniz...', allowClear: true });
            $('#sms_hesap_firma_id, #sms_hesap_operator_id, .select2-modal').select2({
                theme: 'bootstrap-5', width: '100%', placeholder: 'Seçiniz...',
                dropdownParent: $('#hesapModal')
            });

            $('#btnSifreGoster').on('click', function() {
                const el = document.getElementById('sms_hesap_sifre');
                el.type = el.type === 'password' ? 'text' : 'password';
                $(this).find('i').toggleClass('bi-eye bi-eye-slash');
            });

            $('#btnBaslikGetir').on('click', function() {
                const id = document.getElementById('sms_hesap_id').value;
                if (!id) {
                    showToast('Başlıkları çekmek için önce hesabı kaydedin', 'warning');
                    return;
                }
                gondericiGetir(id);
            });

            // Firma Yönetimi'nden gelen bağlantı: ?firma_id=X ile açılışta filtrele
            const urlFirmaId = new URLSearchParams(window.location.search).get('firma_id');
            if (urlFirmaId) {
                currentfilters = { firma_id: urlFirmaId };
                $('#filterCard').collapse('show');
            }

            loadFirmalar(urlFirmaId);
            loadOperatorler();
            loadHesapList();
            loadStats();

            $('#hesapForm').on('submit', function(e) {
                e.preventDefault();
                saveHesap();
            });

            $('#filterForm').on('submit', function(e) {
                e.preventDefault();
                currentfilters = {
                    search: $('#filter_search').val(),
                    firma_id: $('#filter_firma').val(),
                    operator_id: $('#filter_operator').val(),
                    status: $('#filter_status').val()
                };
                Object.keys(currentfilters).forEach(k => { if (!currentfilters[k]) delete currentfilters[k]; });
                loadHesapList();
                showToast('Filtre uygulandı', 'info');
            });

            $('#clearfilters').on('click', function() {
                $('#filterForm')[0].reset();
                $('#filter_firma, #filter_operator, #filter_status').val('').trigger('change.select2');
                currentfilters = {};
                loadHesapList();
                showToast('Filtreler temizlendi', 'info');
            });
        });

        function loadFirmalar(seciliFirmaId) {
            $.post('', { action: 'firma_list' }, function(response) {
                if (response.success) {
                    firmaListesi = response.data;
                    const $filtre = $('#filter_firma');
                    const $modal = $('#sms_hesap_firma_id');
                    $modal.empty().append(new Option('Seçiniz...', '', false, false));

                    firmaListesi.forEach(f => {
                        $filtre.append(new Option(f.firma_adi, f.firma_id, false, false));
                        $modal.append(new Option(f.firma_adi, f.firma_id, false, false));
                    });

                    if (seciliFirmaId) {
                        $filtre.val(String(seciliFirmaId));
                    }

                    $filtre.trigger('change.select2');
                    $modal.trigger('change.select2');
                }
            }, 'json');
        }

        function loadOperatorler() {
            $.post('', { action: 'operator_list' }, function(response) {
                if (response.success) {
                    operatorListesi = response.data;
                    const $filtre = $('#filter_operator');
                    const $modal = $('#sms_hesap_operator_id');
                    $modal.empty().append(new Option('Seçiniz...', '', false, false));

                    operatorListesi.forEach(o => {
                        $filtre.append(new Option(o.sms_operator_adi, o.sms_operator_id, false, false));
                        $modal.append(new Option(o.sms_operator_adi, o.sms_operator_id, false, false));
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
                    $('#stat-aktif').text(response.data.aktif);
                    $('#stat-firma').text(response.data.firma);
                    $('#stat-kredi').text(Number(response.data.kredi || 0).toLocaleString('tr-TR'));
                }
            }, 'json');
        }

        function loadHesapList() {
            $.ajax({
                url: '', method: 'POST', dataType: 'json',
                data: { action: 'list', ...currentfilters },
                success: function(data) {
                    if (data.success) {
                        renderTable(data.data);
                    } else {
                        showToast(data.message || 'Liste yüklenirken hata oluştu', 'error');
                    }
                },
                error: function() { showToast('Sunucu hatası oluştu', 'error'); }
            });
        }

        function renderTable(hesaplar) {
            dataTable.clear();

            hesaplar.forEach(h => {
                const durumBadge = h.Durum
                    ? '<span class="status-badge status-active">Aktif</span>'
                    : '<span class="status-badge status-inactive">Pasif</span>';

                const aciklama = h.sms_hesap_aciklama
                    ? `<br><small class="text-muted">${escapeHtml(h.sms_hesap_aciklama.substring(0, 50))}</small>`
                    : '';

                const operator = escapeHtml(h.sms_operator_adi) +
                    (h.operator_durum ? '' : ' <span class="badge text-bg-danger">Pasif</span>') + aciklama;

                const kredi = (h.sms_hesap_kredi !== null && h.sms_hesap_kredi !== undefined)
                    ? `<span class="badge text-bg-info">${Number(h.sms_hesap_kredi).toLocaleString('tr-TR')}</span>` +
                      (h.sms_hesap_kredi_tarihi ? `<br><small class="text-muted">${escapeHtml(h.sms_hesap_kredi_tarihi)}</small>` : '')
                    : '<span class="text-muted">-</span>';

                let sonTest = '<span class="text-muted">-</span>';
                if (h.sms_hesap_son_test_tarihi) {
                    const ikon = h.sms_hesap_son_test_durum
                        ? '<i class="bi bi-check-circle-fill text-success"></i>'
                        : '<i class="bi bi-x-circle-fill text-danger"></i>';
                    sonTest = `${ikon} <small>${escapeHtml(h.sms_hesap_son_test_tarihi)}</small>` +
                        (h.sms_hesap_son_test_mesaj ? `<br><small class="text-muted">${escapeHtml(h.sms_hesap_son_test_mesaj.substring(0, 45))}</small>` : '');
                }

                const varsayilan = h.sms_hesap_varsayilan
                    ? '<span class="badge text-bg-success"><i class="bi bi-star-fill"></i> Varsayılan</span>'
                    : '<span class="text-muted">-</span>';

                let islemler = `<button class="btn btn-sm btn-info" onclick="testEt(${h.sms_hesap_id})" title="Bağlantı Testi / Kredi Sorgula"><i class="bi bi-plug"></i></button>`;
                if (permissions.canEdit && h.Durum) {
                    islemler += ` <button class="btn btn-sm btn-success" onclick="testSmsAc(${h.sms_hesap_id})" title="Test SMS Gönder"><i class="bi bi-send"></i></button>`;
                }
                if (permissions.canEdit) {
                    islemler += `
                        <button class="btn btn-sm btn-warning" onclick="editHesap(${h.sms_hesap_id})" title="Düzenle"><i class="bi bi-pencil"></i></button>
                        <button class="btn btn-sm btn-secondary" onclick="toggleStatus(${h.sms_hesap_id})" title="${h.Durum ? 'Pasife Al' : 'Aktif Et'}"><i class="bi ${h.Durum ? 'bi-toggle-on' : 'bi-toggle-off'}"></i></button>`;
                }
                if (permissions.canDelete) {
                    islemler += `<button class="btn btn-sm btn-danger" onclick="deleteHesap(${h.sms_hesap_id})" title="Sil"><i class="bi bi-trash"></i></button>`;
                }

                dataTable.row.add([
                    escapeHtml(h.firma_adi),
                    operator,
                    `<code>${escapeHtml(h.sms_hesap_kullanici_adi)}</code>`,
                    h.sms_hesap_gonderici ? `<span class="badge text-bg-secondary">${escapeHtml(h.sms_hesap_gonderici)}</span>` : '<span class="text-muted">-</span>',
                    kredi,
                    sonTest,
                    varsayilan,
                    durumBadge,
                    islemler
                ]);
            });

            dataTable.draw();
        }

        function resetForm() {
            document.getElementById('hesapForm').reset();
            document.getElementById('sms_hesap_id').value = '';
            document.getElementById('hesapModalLabel').textContent = 'Yeni Hesap Ekle';
            document.getElementById('sms_hesap_otp_kullan').checked = true;
            document.getElementById('sifre_zorunlu').style.display = '';
            document.getElementById('sifre_not').textContent = '';
            document.getElementById('sms_hesap_sifre').type = 'password';
            $('#sms_hesap_firma_id, #sms_hesap_operator_id').val('').trigger('change.select2');
            $('#sms_hesap_encoding').val('').trigger('change.select2');
        }

        function saveHesap() {
            const formData = new FormData(document.getElementById('hesapForm'));
            formData.append('action', 'save');

            $.ajax({
                url: '', method: 'POST', data: formData,
                processData: false, contentType: false, dataType: 'json',
                success: function(data) {
                    if (data.success) {
                        showToast(data.message, 'success');
                        hesapModal.hide();
                        loadHesapList();
                        loadStats();
                    } else {
                        showToast(data.message, 'error');
                    }
                },
                error: function() { showToast('Kayıt sırasında hata oluştu', 'error'); }
            });
        }

        function editHesap(id) {
            $.ajax({
                url: '', method: 'POST', dataType: 'json',
                data: { action: 'get', sms_hesap_id: id },
                success: function(data) {
                    if (data.success && data.data) {
                        const h = data.data;

                        document.getElementById('sms_hesap_id').value = h.sms_hesap_id;
                        document.getElementById('sms_hesap_kullanici_adi').value = h.sms_hesap_kullanici_adi || '';
                        document.getElementById('sms_hesap_sifre').value = '';
                        document.getElementById('sms_hesap_gonderici').value = h.sms_hesap_gonderici || '';
                        document.getElementById('sms_hesap_webhook_url').value = h.sms_hesap_webhook_url || '';
                        document.getElementById('sms_hesap_gecerlilik').value = h.sms_hesap_gecerlilik || '';
                        document.getElementById('sms_hesap_gunluk_limit').value = h.sms_hesap_gunluk_limit || '';
                        document.getElementById('sms_hesap_aciklama').value = h.sms_hesap_aciklama || '';

                        document.getElementById('sms_hesap_ticari').checked = h.sms_hesap_ticari == 1;
                        document.getElementById('sms_hesap_otp_kullan').checked = h.sms_hesap_otp_kullan == 1;
                        document.getElementById('sms_hesap_varsayilan').checked = h.sms_hesap_varsayilan == 1;
                        document.getElementById('Durum').checked = h.Durum == 1;

                        $('#sms_hesap_firma_id').val(h.sms_hesap_firma_id).trigger('change.select2');
                        $('#sms_hesap_operator_id').val(h.sms_hesap_operator_id).trigger('change.select2');
                        $('#sms_hesap_encoding').val(h.sms_hesap_encoding === null ? '' : String(h.sms_hesap_encoding)).trigger('change.select2');

                        document.getElementById('sifre_zorunlu').style.display = 'none';
                        document.getElementById('sifre_not').textContent = 'Boş bırakılırsa mevcut şifre korunur';
                        document.getElementById('hesapModalLabel').textContent = 'Hesap Düzenle';
                        hesapModal.show();
                    } else {
                        showToast(data.message || 'Kayıt bulunamadı', 'error');
                    }
                },
                error: function() { showToast('Kayıt yüklenirken hata oluştu', 'error'); }
            });
        }

        function testEt(id) {
            showToast('Operatöre bağlanılıyor...', 'info');

            $.ajax({
                url: '', method: 'POST', dataType: 'json',
                data: { action: 'test', sms_hesap_id: id },
                success: function(data) {
                    if (data.success) {
                        showSuccess('Bağlantı Başarılı!', data.message);
                    } else {
                        showError('Bağlantı Başarısız!', data.message);
                    }
                    loadHesapList();
                    loadStats();
                },
                error: function() { showError('Bağlantı Hatası!', 'Sunucuya ulaşılamıyor.'); }
            });
        }

        // ---- Test SMS ----
        let testSmsModal;
        const testMesajVarsayilan = <?= json_encode('Bu bir test mesajıdır. ' . $siteTitle, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

        $(function() {
            testSmsModal = new bootstrap.Modal(document.getElementById('testSmsModal'));

            $('#test_hesap_id, #test_tip').select2({
                theme: 'bootstrap-5', width: '100%', placeholder: 'Seçiniz...',
                dropdownParent: $('#testSmsModal')
            });

            $('#test_mesaj').on('input', mesajSayacGuncelle);

            $('#testSmsForm').on('submit', function(e) {
                e.preventDefault();
                testSmsGonder();
            });
        });

        // Türkçe karakter varsa UCS-2 (70/67), yoksa GSM (160/153) bölütlenir
        function mesajSayacGuncelle() {
            const metin = $('#test_mesaj').val();
            const uzunluk = [...metin].length;
            const unicode = /[^\x00-\x7F]/.test(metin);
            const tek = unicode ? 70 : 160, parca = unicode ? 67 : 153;
            const adet = uzunluk === 0 ? 1 : (uzunluk <= tek ? 1 : Math.ceil(uzunluk / parca));
            $('#test_mesaj_sayac').text(`${uzunluk} karakter · ${adet} SMS${unicode ? ' (Türkçe karakter)' : ''}`);
        }

        function testSmsAc(seciliId) {
            $('#test_sonuc').addClass('d-none').empty();
            if (!$('#test_mesaj').val()) {
                $('#test_mesaj').val(testMesajVarsayilan);
            }
            mesajSayacGuncelle();

            $.post('', { action: 'test_hesap_list' }, function(response) {
                if (!response.success) {
                    showToast(response.message || 'Hesaplar yüklenemedi', 'error');
                    return;
                }
                if (response.data.length === 0) {
                    showToast('Aktif SMS hesabı bulunamadı', 'warning');
                    return;
                }

                const $s = $('#test_hesap_id').empty();
                response.data.forEach(h => {
                    const etiket = `${h.firma_adi} - ${h.sms_operator_adi} - ${h.sms_hesap_kullanici_adi}` +
                        (h.sms_hesap_gonderici ? ` (${h.sms_hesap_gonderici})` : '');
                    $s.append(new Option(etiket, h.sms_hesap_id, false, false));
                });
                if (seciliId) {
                    $s.val(String(seciliId));
                }
                $s.trigger('change.select2');
                testSmsModal.show();
            }, 'json');
        }

        function testSmsGonder() {
            const $btn = $('#btnTestSmsGonder');
            const formData = $('#testSmsForm').serializeArray();
            formData.push({ name: 'action', value: 'test_sms' });

            $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Gönderiliyor...');

            $.ajax({
                url: '', method: 'POST', dataType: 'json', data: $.param(formData),
                success: function(data) {
                    const sinif = data.success ? 'success' : 'danger';
                    const ikon = data.success ? 'bi-check-circle-fill' : 'bi-x-circle-fill';
                    let detay = '';
                    if (data.aciklama) detay += `<div class="mt-2 fw-semibold"><i class="bi bi-info-circle"></i> ${escapeHtml(data.aciklama)}</div>`;
                    if (data.http_kod) detay += `<br><small>HTTP: ${escapeHtml(data.http_kod)}</small>`;
                    if (data.log_id) detay += `<br><small>Log No: ${escapeHtml(data.log_id)}</small>`;
                    if (data.yanit) detay += `<pre class="small mb-0 mt-2" style="white-space: pre-wrap; max-height: 150px;">${escapeHtml(data.yanit)}</pre>`;

                    $('#test_sonuc').removeClass('d-none').html(
                        `<div class="alert alert-${sinif} mb-0"><i class="bi ${ikon}"></i> ${escapeHtml(data.message)}${detay}</div>`
                    );
                    showToast(data.message, data.success ? 'success' : 'error');
                },
                error: function() { showToast('Sunucu hatası oluştu', 'error'); },
                complete: function() {
                    $btn.prop('disabled', false).html('<i class="bi bi-send"></i> Gönder');
                }
            });
        }

        function gondericiGetir(id) {
            showToast('Başlıklar çekiliyor...', 'info');

            $.ajax({
                url: '', method: 'POST', dataType: 'json',
                data: { action: 'gonderici_getir', sms_hesap_id: id },
                success: function(data) {
                    if (data.success && data.data.length > 0) {
                        // Onay bilgisi varsa etikette gösterilir
                        const onay = {};
                        (data.detay || []).forEach(d => { onay[d.ad] = d.onayli; });
                        const onaysizVar = Object.values(onay).some(v => v === false);

                        Swal.fire({
                            title: 'Gönderici Başlığı Seç',
                            html: onaysizVar
                                ? '<small class="text-warning"><i class="bi bi-exclamation-triangle"></i> "Onaysız" başlıklarla gönderim operatörde reddedilir.</small>'
                                : '',
                            input: 'select',
                            inputOptions: data.data.reduce((acc, b) => {
                                acc[b] = b + (onay[b] === false ? ' (Onaysız)' : (onay[b] === true ? ' (Onaylı)' : ''));
                                return acc;
                            }, {}),
                            showCancelButton: true,
                            confirmButtonText: 'Seç',
                            cancelButtonText: 'İptal'
                        }).then(result => {
                            if (result.isConfirmed && result.value) {
                                document.getElementById('sms_hesap_gonderici').value = result.value;
                                showToast('Başlık seçildi', 'success');
                            }
                        });
                    } else {
                        showToast(data.message || 'Başlık bulunamadı', 'warning');
                    }
                },
                error: function() { showToast('Başlıklar alınamadı', 'error'); }
            });
        }

        function toggleStatus(id) {
            $.ajax({
                url: '', method: 'POST', dataType: 'json',
                data: { action: 'toggle_status', sms_hesap_id: id },
                success: function(data) {
                    if (data.success) {
                        showToast(data.message, 'success');
                        loadHesapList();
                        loadStats();
                    } else {
                        showToast(data.message, 'error');
                    }
                },
                error: function() { showToast('İşlem sırasında hata oluştu', 'error'); }
            });
        }

        function deleteHesap(id) {
            confirmAction(
                'Bu SMS hesabını silmek istediğinize emin misiniz?',
                'Bu işlem geri alınamaz!',
                function() {
                    $.ajax({
                        url: '', method: 'POST', dataType: 'json',
                        data: { action: 'delete', sms_hesap_id: id },
                        success: function(data) {
                            if (data.success) {
                                showSuccess('Silindi!', data.message);
                                loadHesapList();
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
