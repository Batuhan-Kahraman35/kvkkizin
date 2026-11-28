<?php
/**
 * Admin Panel - Firma Yönetimi
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
require_once __DIR__ . '/../includes/BelgeServis.php';
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

$pageTitle = $pageinfo['sayfalar_sayfa_adi'] ?? 'Firma Yönetimi';
$pageDescription = $pageinfo['sayfalar_aciklama'] ?? '';
$menuAdi = $pageinfo['menu_adi'] ?? null;

// Sayfa yetkilerini kontrol et
$permissions = PageAuth::checkPagePermissions($user['kullanici_id'], $user['departman_id'], $currentPagefile);

// Erişim Yetkisi yoksa hata sayfası göster
if (!$permissions['has_access']) {
    PageAuth::accessDenied($permissions['error'] ?? 'Bu sayfaya erişim Yetkiniz yok!');
}

// Firma kapsamı: null = kısıtlama yok (admin), dizi = görülebilecek firma id'leri
$firmaScopeIds = PageAuth::firmaScopeIds($permissions, $user['firma_id'] ?? null);
$sadeceKendiKayitlari = !empty($permissions['can_view_own_records']);

// Personel Yönetimi sayfasına erişim yetkisi (satır bazlı personel butonu için)
$personelPermissions = PageAuth::checkPagePermissions($user['kullanici_id'], $user['departman_id'], 'personel-yonetimi.php');
$personelErisimi = !empty($personelPermissions['has_access']);

// Firma Belgeleri sayfası yetkileri (satır bazlı belge butonu ve belge modalı için)
$belgePermissions = PageAuth::checkPagePermissions($user['kullanici_id'], $user['departman_id'], 'firma-belgeleri.php');
$belgeErisimi = !empty($belgePermissions['has_access']);

// Form Yönetimi sayfası erişimi (satır bazlı form butonu için)
$formPermissions = PageAuth::checkPagePermissions($user['kullanici_id'], $user['departman_id'], 'form-yonetimi.php');
$formErisimi = !empty($formPermissions['has_access']);
$evrakPermissions = PageAuth::checkPagePermissions($user['kullanici_id'], $user['departman_id'], 'evrak-seti-hazirla.php');
$evrakErisimi = !empty($evrakPermissions['can_add']);

// SMS Hesapları sayfası erişimi (kredi hücresinin bağlantı olması için)
$smsPermissions = PageAuth::checkPagePermissions($user['kullanici_id'], $user['departman_id'], 'sms-hesaplari.php');
$smsErisimi = !empty($smsPermissions['has_access']);

/**
 * Verilen firma id kullanıcının kapsamında mı?
 */
$firmaKapsamdaMi = function ($firmaId) use ($firmaScopeIds, $sadeceKendiKayitlari, $db, $user) {
    $firmaId = (int)$firmaId;
    if ($firmaId <= 0) {
        return false;
    }
    if ($firmaScopeIds !== null && !in_array($firmaId, $firmaScopeIds, true)) {
        return false;
    }
    if ($sadeceKendiKayitlari) {
        $sahip = $db->fetchOne(
            "SELECT firma_id FROM Firmalar WHERE firma_id = ? AND firma_olusturan_kullanici_id = ?",
            [$firmaId, $user['kullanici_id']]
        );
        if (!$sahip) {
            return false;
        }
    }
    return true;
};

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

// AJAX işlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {
            case 'list':
                $search      = $_POST['search'] ?? '';
                $status      = $_POST['status'] ?? '';
                $ustFirmaId  = $_POST['ust_firma_id'] ?? '';
                $tip         = $_POST['tip'] ?? '';

                $whereConditions = ["1=1"];
                $params = [];

                // Firma kapsam filtresi
                [$scopeSql, $scopeParams] = PageAuth::firmaScopeWhere($firmaScopeIds, 'f.firma_id');
                $whereConditions[] = $scopeSql;
                $params = array_merge($params, $scopeParams);

                // Sadece kendi oluşturduğu kayıtlar
                if ($sadeceKendiKayitlari) {
                    $whereConditions[] = "f.firma_olusturan_kullanici_id = ?";
                    $params[] = $user['kullanici_id'];
                }

                if ($search) {
                    $whereConditions[] = "(f.firma_adi LIKE ? OR f.firma_ticari_unvan LIKE ? OR f.firma_vkn_tc LIKE ? OR f.firma_email LIKE ? OR f.firma_kep_adresi LIKE ? OR f.firma_yetkili_ad_soyad LIKE ? OR f.firma_irtibat_kisisi LIKE ?)";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                }

                if ($status !== '') {
                    $whereConditions[] = "f.firma_durum = ?";
                    $params[] = $status;
                }

                if ($ustFirmaId !== '') {
                    $whereConditions[] = "f.firma_ust_firma_id = ?";
                    $params[] = $ustFirmaId;
                }

                if ($tip === 'ana') {
                    $whereConditions[] = "f.firma_ust_firma_id IS NULL";
                } elseif ($tip === 'alt') {
                    $whereConditions[] = "f.firma_ust_firma_id IS NOT NULL";
                }

                // Belge durumu filtresi (eksik zorunlu / süresi dolan / onay bekleyen / tam)
                $belgeDurumu = $_POST['belge_durumu'] ?? '';
                if ($belgeDurumu !== '') {
                    $belgeFiltre = BelgeServis::firmaOzetFiltreSql($belgeDurumu);
                    if ($belgeFiltre !== null) {
                        $whereConditions[] = $belgeFiltre;
                    }
                }

                $whereClause = implode(" AND ", $whereConditions);
                $belgeOzetSql = BelgeServis::firmaOzetSql();

                $firmaList = $db->fetchAll("
                    SELECT
                        f.firma_id,
                        f.firma_ust_firma_id,
                        f.firma_adi,
                        f.firma_vkn_tc,
                        f.firma_ticari_unvan,
                        f.firma_adres,
                        f.firma_telefon,
                        f.firma_internet_sitesi,
                        f.firma_kep_adresi,
                        f.firma_email,
                        f.firma_yetkili_ad_soyad,
                        f.firma_irtibat_kisisi,
                        f.firma_max_alt_firma_sayisi,
                        f.firma_max_kullanici_sayisi,
                        f.firma_durum,
                        uf.firma_adi as ust_firma_adi,
                        (SELECT count(*) FROM Firmalar a WHERE a.firma_ust_firma_id = f.firma_id) as alt_firma_sayisi,
                        (SELECT count(*) FROM kullanicilar k WHERE k.kullanici_firma_id = f.firma_id) as kullanici_sayisi,
                        (SELECT count(*) FROM SmsHesaplari sh WHERE sh.sms_hesap_firma_id = f.firma_id AND sh.Durum = 1) as sms_hesap_sayisi,
                        (SELECT SUM(sh.sms_hesap_kredi) FROM SmsHesaplari sh WHERE sh.sms_hesap_firma_id = f.firma_id AND sh.Durum = 1) as sms_kredi,
                        (SELECT CONVERT(VARCHAR(19), MAX(sh.sms_hesap_kredi_tarihi), 120) FROM SmsHesaplari sh WHERE sh.sms_hesap_firma_id = f.firma_id AND sh.Durum = 1) as sms_kredi_tarihi,
                        $belgeOzetSql,
                        CONVERT(VARCHAR(19), f.firma_olusturma_tarihi, 120) as firma_olusturma_tarihi,
                        ko.kullanici_ad + ' ' + ko.kullanici_soyad as olusturan_kullanici,
                        CONVERT(VARCHAR(19), f.firma_guncelleme_tarihi, 120) as firma_guncelleme_tarihi
                    FROM Firmalar f
                    LEFT JOIN Firmalar uf ON f.firma_ust_firma_id = uf.firma_id
                    LEFT JOIN kullanicilar ko ON f.firma_olusturan_kullanici_id = ko.kullanici_id
                    WHERE $whereClause
                    ORDER BY f.firma_adi
                ", $params);
                echo json_encode(['success' => true, 'data' => $firmaList]);
                break;

            case 'get':
                $firmaid = $_POST['firma_id'] ?? 0;

                if (!$firmaKapsamdaMi($firmaid)) {
                    echo json_encode(['success' => false, 'message' => 'Bu firmaya erişim yetkiniz yok!']);
                    break;
                }

                $firma = $db->fetchOne("SELECT * FROM Firmalar WHERE firma_id = ?", [$firmaid]);
                echo json_encode(['success' => true, 'data' => $firma]);
                break;

            case 'ust_firma_list':
                // Düzenlemede firmanın kendisi ve alt firmaları üst firma olarak seçilemez
                $haricFirmaId = (int)($_POST['haric_firma_id'] ?? 0);

                // Firma kapsam filtresi
                [$scopeSql, $scopeParams] = PageAuth::firmaScopeWhere($firmaScopeIds, 'firma_id');

                if ($haricFirmaId > 0) {
                    $firmalar = $db->fetchAll("
                        WITH AltAgac AS (
                            SELECT firma_id FROM Firmalar WHERE firma_id = ?
                            UNION ALL
                            SELECT f.firma_id FROM Firmalar f
                            INNER JOIN AltAgac a ON f.firma_ust_firma_id = a.firma_id
                        )
                        SELECT firma_id, firma_adi
                        FROM Firmalar
                        WHERE firma_durum = 1
                          AND firma_id NOT IN (SELECT firma_id FROM AltAgac)
                          AND $scopeSql
                        ORDER BY firma_adi
                    ", array_merge([$haricFirmaId], $scopeParams));
                } else {
                    $firmalar = $db->fetchAll("
                        SELECT firma_id, firma_adi
                        FROM Firmalar
                        WHERE firma_durum = 1 AND $scopeSql
                        ORDER BY firma_adi
                    ", $scopeParams);
                }
                echo json_encode(['success' => true, 'data' => $firmalar]);
                break;

            case 'save':
                $firmaid = (int)($_POST['firma_id'] ?? 0);

                // Yetki kontrolü
                if ($firmaid > 0 && !$permissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Düzenleme yetkiniz yok!']);
                    break;
                }
                if ($firmaid == 0 && !$permissions['can_add']) {
                    echo json_encode(['success' => false, 'message' => 'Ekleme Yetkiniz yok!']);
                    break;
                }

                // Kapsam kontrolü: düzenlenen firma kullanıcının kapsamında olmalı
                if ($firmaid > 0 && !$firmaKapsamdaMi($firmaid)) {
                    echo json_encode(['success' => false, 'message' => 'Bu firmaya erişim yetkiniz yok!']);
                    break;
                }

                $firmaAdi = trim($_POST['firma_adi'] ?? '');
                if ($firmaAdi === '') {
                    echo json_encode(['success' => false, 'message' => 'Firma adı zorunludur!']);
                    break;
                }

                $vknTc = trim($_POST['firma_vkn_tc'] ?? '');
                if ($vknTc !== '') {
                    if (!preg_match('/^\d{10,11}$/', $vknTc)) {
                        echo json_encode(['success' => false, 'message' => 'VKN 10, TC Kimlik No 11 haneli ve sadece rakam olmalıdır!']);
                        break;
                    }

                    $mevcut = $db->fetchOne(
                        "SELECT firma_id, firma_adi FROM Firmalar WHERE firma_vkn_tc = ? AND firma_id <> ?",
                        [$vknTc, $firmaid]
                    );
                    if ($mevcut) {
                        echo json_encode(['success' => false, 'message' => 'Bu VKN/TC başka bir firmada kayıtlı: ' . $mevcut['firma_adi']]);
                        break;
                    }
                } else {
                    $vknTc = null;
                }

                $email = trim($_POST['firma_email'] ?? '');
                if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    echo json_encode(['success' => false, 'message' => 'Geçerli bir e-posta adresi giriniz!']);
                    break;
                }

                $kepAdresi = trim($_POST['firma_kep_adresi'] ?? '');
                if ($kepAdresi !== '' && !filter_var($kepAdresi, FILTER_VALIDATE_EMAIL)) {
                    echo json_encode(['success' => false, 'message' => 'Geçerli bir KEP adresi giriniz!']);
                    break;
                }

                $ustFirmaId = $_POST['firma_ust_firma_id'] ?? '';
                $ustFirmaId = ($ustFirmaId === '' || $ustFirmaId == 0) ? null : (int)$ustFirmaId;

                if ($ustFirmaId !== null) {
                    if ($ustFirmaId === $firmaid) {
                        echo json_encode(['success' => false, 'message' => 'Firma kendi üst firması olamaz!']);
                        break;
                    }

                    // Üst firma da kullanıcının kapsamında olmalı
                    if ($firmaScopeIds !== null && !in_array($ustFirmaId, $firmaScopeIds, true)) {
                        echo json_encode(['success' => false, 'message' => 'Seçilen üst firma için yetkiniz yok!']);
                        break;
                    }

                    // Döngüsel bağ kontrolü: üst firma, bu firmanın alt ağacında olamaz
                    if ($firmaid > 0) {
                        $dongu = $db->fetchOne("
                            WITH AltAgac AS (
                                SELECT firma_id FROM Firmalar WHERE firma_ust_firma_id = ?
                                UNION ALL
                                SELECT f.firma_id FROM Firmalar f
                                INNER JOIN AltAgac a ON f.firma_ust_firma_id = a.firma_id
                            )
                            SELECT count(*) as count FROM AltAgac WHERE firma_id = ?
                        ", [$firmaid, $ustFirmaId])['count'] ?? 0;

                        if ($dongu > 0) {
                            echo json_encode(['success' => false, 'message' => 'Seçilen üst firma, bu firmanın alt firmalarından biri! Döngüsel bağ kurulamaz.']);
                            break;
                        }
                    }

                    // Üst firmanın alt firma limiti kontrolü
                    $ustFirma = $db->fetchOne("SELECT firma_adi, firma_max_alt_firma_sayisi FROM Firmalar WHERE firma_id = ?", [$ustFirmaId]);
                    if (!$ustFirma) {
                        echo json_encode(['success' => false, 'message' => 'Seçilen üst firma bulunamadı!']);
                        break;
                    }

                    $limit = (int)$ustFirma['firma_max_alt_firma_sayisi'];
                    if ($limit > 0) {
                        $mevcutAlt = $db->fetchOne(
                            "SELECT count(*) as count FROM Firmalar WHERE firma_ust_firma_id = ? AND firma_id <> ?",
                            [$ustFirmaId, $firmaid]
                        )['count'] ?? 0;

                        if ($mevcutAlt >= $limit) {
                            echo json_encode(['success' => false, 'message' => $ustFirma['firma_adi'] . ' firmasının alt firma limiti dolu (' . $mevcutAlt . '/' . $limit . ')']);
                            break;
                        }
                    }
                }

                $maxAlt = (int)($_POST['firma_max_alt_firma_sayisi'] ?? 0);
                $maxKullanici = (int)($_POST['firma_max_kullanici_sayisi'] ?? 0);

                // Limitler mevcut kayıt sayısının altına çekilemez
                if ($firmaid > 0) {
                    if ($maxAlt > 0) {
                        $altSayi = $db->fetchOne("SELECT count(*) as count FROM Firmalar WHERE firma_ust_firma_id = ?", [$firmaid])['count'] ?? 0;
                        if ($altSayi > $maxAlt) {
                            echo json_encode(['success' => false, 'message' => "Firmanın $altSayi alt firması var, limit bunun altına çekilemez!"]);
                            break;
                        }
                    }
                    if ($maxKullanici > 0) {
                        $kullaniciSayi = $db->fetchOne("SELECT count(*) as count FROM kullanicilar WHERE kullanici_firma_id = ?", [$firmaid])['count'] ?? 0;
                        if ($kullaniciSayi > $maxKullanici) {
                            echo json_encode(['success' => false, 'message' => "Firmaya bağlı $kullaniciSayi kullanıcı var, limit bunun altına çekilemez!"]);
                            break;
                        }
                    }
                }

                $data = [
                    'firma_ust_firma_id'         => $ustFirmaId,
                    'firma_adi'                  => $firmaAdi,
                    'firma_vkn_tc'               => $vknTc,
                    'firma_ticari_unvan'         => trim($_POST['firma_ticari_unvan'] ?? '') ?: null,
                    'firma_adres'                => trim($_POST['firma_adres'] ?? '') ?: null,
                    'firma_telefon'              => trim($_POST['firma_telefon'] ?? '') ?: null,
                    'firma_internet_sitesi'      => trim($_POST['firma_internet_sitesi'] ?? '') ?: null,
                    'firma_kep_adresi'           => $kepAdresi ?: null,
                    'firma_email'                => $email ?: null,
                    'firma_yetkili_ad_soyad'     => trim($_POST['firma_yetkili_ad_soyad'] ?? '') ?: null,
                    'firma_irtibat_kisisi'       => trim($_POST['firma_irtibat_kisisi'] ?? '') ?: null,
                    'firma_max_alt_firma_sayisi' => $maxAlt,
                    'firma_max_kullanici_sayisi' => $maxKullanici,
                    'firma_durum'                => isset($_POST['firma_durum']) ? 1 : 0,
                ];

                if ($firmaid > 0) {
                    $data['firma_guncelleme_tarihi'] = date('Y-m-d H:i:s');
                    $data['firma_guncelleyen_kullanici_id'] = $user['kullanici_id'];

                    $result = $db->update('Firmalar', $data, ['firma_id' => $firmaid]);
                    echo json_encode(['success' => $result, 'message' => $result ? 'Firma başarıyla güncellendi' : 'Güncelleme hatası']);
                } else {
                    $data['firma_olusturma_tarihi'] = date('Y-m-d H:i:s');
                    $data['firma_olusturan_kullanici_id'] = $user['kullanici_id'];

                    $result = $db->insert('Firmalar', $data);
                    echo json_encode(['success' => $result, 'message' => $result ? 'Firma başarıyla eklendi' : 'Ekleme hatası']);
                }
                break;

            case 'delete':
                // Yetki kontrolü
                if (!$permissions['can_delete']) {
                    echo json_encode(['success' => false, 'message' => 'Silme Yetkiniz yok!']);
                    break;
                }

                $firmaid = $_POST['firma_id'] ?? 0;

                // Kapsam kontrolü
                if (!$firmaKapsamdaMi($firmaid)) {
                    echo json_encode(['success' => false, 'message' => 'Bu firmaya erişim yetkiniz yok!']);
                    break;
                }

                // Kullanım kontrolleri
                $checks = [];

                $altcount = $db->fetchOne("SELECT count(*) as count FROM Firmalar WHERE firma_ust_firma_id = ?", [$firmaid])['count'] ?? 0;
                if ($altcount > 0) {
                    $checks[] = "$altcount alt firma";
                }

                $kullanicicount = $db->fetchOne("SELECT count(*) as count FROM kullanicilar WHERE kullanici_firma_id = ?", [$firmaid])['count'] ?? 0;
                if ($kullanicicount > 0) {
                    $checks[] = "$kullanicicount kullanıcı";
                }

                // Kullaniliyorsa silinemez
                if (!empty($checks)) {
                    $message = "Bu firma silinemez! Şu kayıtlarda kullanılıyor: " . implode(', ', $checks);
                    echo json_encode(['success' => false, 'message' => $message]);
                    break;
                }

                $result = $db->delete('Firmalar', ['firma_id' => $firmaid]);
                echo json_encode(['success' => true, 'message' => $result ? 'Firma başarıyla silindi' : 'Silme hatası']);
                break;

            case 'stats':
                // Sayaçlar da kullanıcının kapsamına göre hesaplanır
                [$scopeSql, $scopeParams] = PageAuth::firmaScopeWhere($firmaScopeIds, 'firma_id');

                $statsWhere = $scopeSql;
                $statsParams = $scopeParams;

                if ($sadeceKendiKayitlari) {
                    $statsWhere .= " AND firma_olusturan_kullanici_id = ?";
                    $statsParams[] = $user['kullanici_id'];
                }

                $stats = [
                    'toplam' => $db->fetchOne("SELECT count(*) as count FROM Firmalar WHERE $statsWhere", $statsParams)['count'] ?? 0,
                    'aktif'  => $db->fetchOne("SELECT count(*) as count FROM Firmalar WHERE $statsWhere AND firma_durum = 1", $statsParams)['count'] ?? 0,
                    'ana'    => $db->fetchOne("SELECT count(*) as count FROM Firmalar WHERE $statsWhere AND firma_ust_firma_id IS NULL", $statsParams)['count'] ?? 0,
                    'alt'    => $db->fetchOne("SELECT count(*) as count FROM Firmalar WHERE $statsWhere AND firma_ust_firma_id IS NOT NULL", $statsParams)['count'] ?? 0
                ];
                echo json_encode(['success' => true, 'data' => $stats]);
                break;

            // ---------------------------------------------------------------
            // Belge modalı işlemleri (Firma Belgeleri sayfası yetkileriyle)
            // ---------------------------------------------------------------
            case 'belge_list':
                if (!$belgeErisimi) {
                    echo json_encode(['success' => false, 'message' => 'Belge görüntüleme yetkiniz yok!']);
                    break;
                }

                $belgeFirmaId = (int)($_POST['firma_id'] ?? 0);
                if (!$firmaKapsamdaMi($belgeFirmaId)) {
                    echo json_encode(['success' => false, 'message' => 'Bu firmaya erişim yetkiniz yok!']);
                    break;
                }

                echo json_encode([
                    'success' => true,
                    'data' => BelgeServis::listele($db, $firmaScopeIds, ['firma_id' => $belgeFirmaId]),
                    'zorunlu' => BelgeServis::zorunluBelgeDurumu($db, $belgeFirmaId)
                ]);
                break;

            case 'belge_tanimlari':
                if (!$belgeErisimi) {
                    echo json_encode(['success' => false, 'message' => 'Belge görüntüleme yetkiniz yok!']);
                    break;
                }

                echo json_encode([
                    'success' => true,
                    'tipler' => BelgeServis::belgeTipleri($db),
                    'onay_durumlari' => BelgeServis::onayDurumlari($db)
                ]);
                break;

            case 'belge_get':
                if (!$belgeErisimi) {
                    echo json_encode(['success' => false, 'message' => 'Belge görüntüleme yetkiniz yok!']);
                    break;
                }
                echo json_encode(BelgeServis::getir($db, $firmaScopeIds, $_POST['firma_belge_id'] ?? 0));
                break;

            case 'belge_kaydet':
                if (!$belgeErisimi) {
                    echo json_encode(['success' => false, 'message' => 'Belge işlemi yetkiniz yok!']);
                    break;
                }
                echo json_encode(BelgeServis::kaydet($db, $firmaScopeIds, $belgePermissions, $user['kullanici_id'], $_POST, $_FILES));
                break;

            case 'belge_onay':
                if (!$belgeErisimi) {
                    echo json_encode(['success' => false, 'message' => 'Belge işlemi yetkiniz yok!']);
                    break;
                }
                echo json_encode(BelgeServis::onayKaydet($db, $firmaScopeIds, $belgePermissions, $user['kullanici_id'], $_POST));
                break;

            case 'belge_sil':
                if (!$belgeErisimi) {
                    echo json_encode(['success' => false, 'message' => 'Belge işlemi yetkiniz yok!']);
                    break;
                }
                echo json_encode(BelgeServis::sil($db, $firmaScopeIds, $belgePermissions, $_POST['firma_belge_id'] ?? 0));
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
                                <span class="info-box-icon"><i class="bi bi-buildings"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Toplam Firma</span>
                                    <span class="info-box-number" id="stat-toplam">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-box text-bg-success">
                                <span class="info-box-icon"><i class="bi bi-check-circle"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Aktif Firma</span>
                                    <span class="info-box-number" id="stat-aktif">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-box text-bg-info">
                                <span class="info-box-icon"><i class="bi bi-building"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Ana Firma</span>
                                    <span class="info-box-number" id="stat-ana">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-box text-bg-warning">
                                <span class="info-box-icon"><i class="bi bi-diagram-3"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Alt Firma</span>
                                    <span class="info-box-number" id="stat-alt">0</span>
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
                                        <input type="text" class="form-control" name="search" id="filter_search" placeholder="Firma adı, unvan, VKN/TC, e-posta, yetkili...">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Üst Firma</label>
                                        <select class="form-select select2-filter" name="ust_firma_id" id="filter_ust_firma_id">
                                            <option value="">Tümü</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Tip</label>
                                        <select class="form-select select2-filter" name="tip" id="filter_tip">
                                            <option value="">Tümü</option>
                                            <option value="ana">Ana Firma</option>
                                            <option value="alt">Alt Firma</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Durum</label>
                                        <select class="form-select select2-filter" name="status" id="filter_status">
                                            <option value="">Tümü</option>
                                            <option value="1">Aktif</option>
                                            <option value="0">Pasif</option>
                                        </select>
                                    </div>
                                    <?php if ($belgeErisimi): ?>
                                    <div class="col-md-3">
                                        <label class="form-label">Belge Durumu</label>
                                        <select class="form-select select2-filter" name="belge_durumu" id="filter_belge_durumu">
                                            <option value="">Tümü</option>
                                            <option value="eksik">Eksik Zorunlu Belge</option>
                                            <option value="suresi_dolan">Süresi Dolan / Dolacak</option>
                                            <option value="bekleyen">Onay Bekleyen</option>
                                            <option value="tam">Belgeleri Tam</option>
                                        </select>
                                    </div>
                                    <?php endif; ?>
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
                            <h3 class="card-title">Firma Listesi</h3>
                            <div class="card-tools">
                                <?php if ($permissions['can_add']): ?>
                                <button type="button" class="btn btn-primary btn-sm" onclick="yeniFirma()">
                                    <i class="bi bi-plus-circle"></i> Yeni Firma Ekle
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="card-body">
                            <table id="firmaTable" class="table table-bordered table-striped table-hover">
                                <thead>
                                    <tr>
                                        <th>Firma Adı</th>
                                        <th>Ticari Unvan</th>
                                        <th>SMS Kredisi</th>
                                        <th>Alt Firma</th>
                                        <th>Kullanıcı</th>
                                        <?php if ($belgeErisimi): ?>
                                        <th>Belgeler</th>
                                        <?php endif; ?>
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

    <!-- Firma Modal -->
    <div class="modal fade" id="firmaModal" tabindex="-1" aria-labelledby="firmaModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="firmaModalLabel">Yeni Firma Ekle</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="firmaForm">
                    <div class="modal-body">
                        <input type="hidden" id="firma_id" name="firma_id" value="">

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="firma_adi" class="form-label">Firma Adı <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="firma_adi" name="firma_adi" maxlength="200" required>
                            </div>

                            <div class="col-md-6">
                                <label for="firma_vkn_tc" class="form-label">VKN / TC Kimlik No</label>
                                <input type="text" class="form-control" id="firma_vkn_tc" name="firma_vkn_tc" maxlength="11" inputmode="numeric" placeholder="10 veya 11 haneli">
                            </div>

                            <div class="col-md-12">
                                <label for="firma_ticari_unvan" class="form-label">Firma Ticari Unvanı</label>
                                <input type="text" class="form-control" id="firma_ticari_unvan" name="firma_ticari_unvan" maxlength="300">
                            </div>

                            <div class="col-md-12">
                                <label for="firma_ust_firma_id" class="form-label">Üst Firma</label>
                                <select class="form-select" id="firma_ust_firma_id" name="firma_ust_firma_id" style="width: 100%;">
                                    <option value="">Yok (Ana Firma)</option>
                                </select>
                            </div>

                            <div class="col-md-12">
                                <label for="firma_adres" class="form-label">Adres</label>
                                <textarea class="form-control" id="firma_adres" name="firma_adres" rows="2" maxlength="500"></textarea>
                            </div>

                            <div class="col-md-6">
                                <label for="firma_telefon" class="form-label">Telefon</label>
                                <input type="text" class="form-control" id="firma_telefon" name="firma_telefon" maxlength="20">
                            </div>

                            <div class="col-md-6">
                                <label for="firma_internet_sitesi" class="form-label">İnternet Sitesi</label>
                                <input type="text" class="form-control" id="firma_internet_sitesi" name="firma_internet_sitesi" maxlength="255" placeholder="https://">
                            </div>

                            <div class="col-md-6">
                                <label for="firma_email" class="form-label">E-posta</label>
                                <input type="email" class="form-control" id="firma_email" name="firma_email" maxlength="255">
                            </div>

                            <div class="col-md-6">
                                <label for="firma_kep_adresi" class="form-label">KEP Adresi</label>
                                <input type="email" class="form-control" id="firma_kep_adresi" name="firma_kep_adresi" maxlength="255" placeholder="Varsa">
                            </div>

                            <div class="col-md-6">
                                <label for="firma_yetkili_ad_soyad" class="form-label">Yetkili İsim Soyisim</label>
                                <input type="text" class="form-control" id="firma_yetkili_ad_soyad" name="firma_yetkili_ad_soyad" maxlength="150">
                            </div>

                            <div class="col-md-6">
                                <label for="firma_irtibat_kisisi" class="form-label">İrtibat Kişisi</label>
                                <input type="text" class="form-control" id="firma_irtibat_kisisi" name="firma_irtibat_kisisi" maxlength="150">
                            </div>

                            <div class="col-md-6">
                                <label for="firma_max_alt_firma_sayisi" class="form-label">Maksimum Alt Firma Sayısı</label>
                                <input type="number" class="form-control" id="firma_max_alt_firma_sayisi" name="firma_max_alt_firma_sayisi" min="0" value="0">
                                <div class="form-text">0 = Sınırsız</div>
                            </div>

                            <div class="col-md-6">
                                <label for="firma_max_kullanici_sayisi" class="form-label">Maksimum Kullanıcı Sayısı</label>
                                <input type="number" class="form-control" id="firma_max_kullanici_sayisi" name="firma_max_kullanici_sayisi" min="0" value="0">
                                <div class="form-text">0 = Sınırsız</div>
                            </div>

                            <div class="col-md-12">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="firma_durum" name="firma_durum" checked>
                                    <label class="form-check-label" for="firma_durum">Aktif</label>
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

    <?php if ($belgeErisimi): ?>
    <!-- Firma Belgeleri Modal -->
    <div class="modal fade" id="belgeModal" tabindex="-1" aria-labelledby="belgeModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="belgeModalLabel">
                        <i class="bi bi-files"></i> Firma Belgeleri
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="belge_zorunlu_ozet" class="mb-3"></div>

                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h6 class="mb-0">Yüklü Belgeler</h6>
                        <div>
                            <?php if (!empty($belgePermissions['can_add'])): ?>
                            <button type="button" class="btn btn-primary btn-sm" onclick="belgeFormAc()">
                                <i class="bi bi-cloud-upload"></i> Belge Yükle
                            </button>
                            <?php endif; ?>
                            <a href="/Admin/firma-belgeleri" id="belge_tum_link" class="btn btn-outline-secondary btn-sm" title="Firma Belgeleri sayfasında aç">
                                <i class="bi bi-box-arrow-up-right"></i>
                            </a>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-bordered table-sm table-hover mb-0" id="belgeListTable">
                            <thead>
                                <tr>
                                    <th>Belge Tipi</th>
                                    <th>Belge</th>
                                    <th>Geçerlilik</th>
                                    <th>Onay Durumu</th>
                                    <th>Yükleyen</th>
                                    <th>Durum</th>
                                    <th>İşlemler</th>
                                </tr>
                            </thead>
                            <tbody id="belgeListBody">
                                <tr><td colspan="7" class="text-center text-muted">Yükleniyor...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle"></i> Kapat
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Belge Yükleme / Düzenleme Modal -->
    <div class="modal fade" id="belgeFormModal" tabindex="-1" aria-labelledby="belgeFormModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="belgeFormModalLabel">Belge Yükle</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="belgeForm" enctype="multipart/form-data">
                    <div class="modal-body">
                        <input type="hidden" id="belge_firma_belge_id" name="firma_belge_id" value="">
                        <input type="hidden" id="belge_firma_id" name="firma_id" value="">

                        <div class="row g-3">
                            <div class="col-md-12">
                                <label for="belge_tipi_id" class="form-label">Belge Tipi <span class="text-danger">*</span></label>
                                <select class="form-select" id="belge_tipi_id" name="belge_tipi_id" style="width: 100%;" required></select>
                                <small class="text-muted" id="belge_tipi_bilgi"></small>
                            </div>

                            <div class="col-md-12">
                                <label for="belge_dosya" class="form-label">Dosya <span class="text-danger" id="belge_dosya_zorunlu">*</span></label>
                                <input type="file" class="form-control" id="belge_dosya" name="belge_dosya[]">
                                <small class="text-muted" id="belge_dosya_yardim">Önce belge tipini seçin.</small>
                                <div id="belge_mevcut_dosya" class="mt-2 d-none">
                                    <span class="badge text-bg-secondary">
                                        <i class="bi bi-paperclip"></i> Mevcut dosya: <span id="belge_mevcut_dosya_adi"></span>
                                    </span>
                                    <small class="text-muted d-block">Yeni dosya seçerseniz eskisi silinir ve belge yeniden onaya düşer.</small>
                                </div>
                            </div>

                            <div class="col-md-12">
                                <label for="firma_belge_baslik" class="form-label">Başlık</label>
                                <input type="text" class="form-control" id="firma_belge_baslik" name="firma_belge_baslik" maxlength="200" placeholder="Boş bırakılırsa dosya adı kullanılır">
                            </div>

                            <div class="col-md-6">
                                <label for="firma_belge_gecerlilik_baslangic" class="form-label">Geçerlilik Başlangıç</label>
                                <input type="date" class="form-control" id="firma_belge_gecerlilik_baslangic" name="firma_belge_gecerlilik_baslangic">
                            </div>

                            <div class="col-md-6">
                                <label for="firma_belge_gecerlilik_bitis" class="form-label">Geçerlilik Bitiş</label>
                                <input type="date" class="form-control" id="firma_belge_gecerlilik_bitis" name="firma_belge_gecerlilik_bitis">
                                <small class="text-muted">Boş bırakılırsa belge tipinin süresine göre hesaplanır</small>
                            </div>

                            <div class="col-md-12">
                                <label for="firma_belge_aciklama" class="form-label">Açıklama</label>
                                <textarea class="form-control" id="firma_belge_aciklama" name="firma_belge_aciklama" rows="2" maxlength="500"></textarea>
                            </div>

                            <div class="col-md-12">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" role="switch" id="firma_belge_durum" name="firma_belge_durum" value="1" checked>
                                    <label class="form-check-label" for="firma_belge_durum">Aktif</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="bi bi-x-circle"></i> İptal
                        </button>
                        <button type="submit" class="btn btn-primary" id="belgeKaydetBtn">
                            <i class="bi bi-save"></i> Kaydet
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Belge Onay Modal -->
    <div class="modal fade" id="belgeOnayModal" tabindex="-1" aria-labelledby="belgeOnayModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="belgeOnayModalLabel">Onay Durumu</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="belgeOnayForm">
                    <div class="modal-body">
                        <input type="hidden" id="onay_firma_belge_id" name="firma_belge_id" value="">

                        <div class="mb-3">
                            <label for="firma_belge_onay_durumu_id" class="form-label">Durum <span class="text-danger">*</span></label>
                            <select class="form-select" id="firma_belge_onay_durumu_id" name="firma_belge_onay_durumu_id" style="width: 100%;" required></select>
                        </div>

                        <div class="mb-3">
                            <label for="firma_belge_onay_aciklama" class="form-label">Açıklama</label>
                            <textarea class="form-control" id="firma_belge_onay_aciklama" name="firma_belge_onay_aciklama" rows="3" maxlength="500"></textarea>
                            <small class="text-muted">Reddedildi ve Revizyon İstendi durumlarında zorunludur.</small>
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
    <?php endif; ?>

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
        let firmaModal;
        let dataTable;

        function escapeHtml(text) {
            if (text === null || text === undefined) return '';
            return String(text)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        // Sayfa yetkileri (PHP'den)
        const permissions = {
            canAdd: <?= $permissions['can_add'] ? 'true' : 'false' ?>,
            canEdit: <?= $permissions['can_edit'] ? 'true' : 'false' ?>,
            canDelete: <?= $permissions['can_delete'] ? 'true' : 'false' ?>,
            canViewPersonel: <?= $personelErisimi ? 'true' : 'false' ?>,
            canViewBelge: <?= $belgeErisimi ? 'true' : 'false' ?>,
            canViewSms: <?= $smsErisimi ? 'true' : 'false' ?>,
            formErisimi: <?= $formErisimi ? 'true' : 'false' ?>,
            evrakErisimi: <?= $evrakErisimi ? 'true' : 'false' ?>,
            belgeAdd: <?= !empty($belgePermissions['can_add']) ? 'true' : 'false' ?>,
            belgeEdit: <?= !empty($belgePermissions['can_edit']) ? 'true' : 'false' ?>,
            belgeDelete: <?= !empty($belgePermissions['can_delete']) ? 'true' : 'false' ?>
        };

        // Belge modalı durumu
        let belgeModal, belgeFormModal, belgeOnayModal;
        let aktifBelgeFirmaId = null;
        let aktifBelgeFirmaAdi = '';
        let belgeTipleri = [];
        let belgeOnayDurumlari = [];
        const firmaAdlari = {};

        let currentfilters = {};

        $(document).ready(function() {
            firmaModal = new bootstrap.Modal(document.getElementById('firmaModal'));

            dataTable = $('#firmaTable').DataTable({
                language: {
                    url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/tr.json'
                },
                order: [[0, 'asc']],
                columnDefs: [
                    { orderable: false, targets: permissions.canViewBelge ? [5, 7] : [6] }
                ],
                pageLength: 25,
                lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "Tümü"]]
            });

            // Filtre dropdown'lari aranabilir
            $('.select2-filter').select2({
                theme: 'bootstrap-5',
                width: '100%',
                language: { noResults: () => 'Sonuç bulunamadı' }
            });

            // Modal üst firma dropdown'i aranabilir
            $('#firma_ust_firma_id').select2({
                theme: 'bootstrap-5',
                width: '100%',
                dropdownParent: $('#firmaModal'),
                placeholder: 'Üst firma seçin (boş bırakılırsa ana firma)',
                allowClear: true,
                language: { noResults: () => 'Sonuç bulunamadı' }
            });

            loadFirmaList();
            loadStats();
            loadFilterUstFirmalar();

            $('#firmaForm').on('submit', function(e) {
                e.preventDefault();
                saveFirma();
            });

            // Filtre form submit
            $('#filterForm').on('submit', function(e) {
                e.preventDefault();

                currentfilters = {
                    search: $('#filter_search').val(),
                    ust_firma_id: $('#filter_ust_firma_id').val(),
                    tip: $('#filter_tip').val(),
                    status: $('#filter_status').val(),
                    belge_durumu: $('#filter_belge_durumu').val()
                };

                Object.keys(currentfilters).forEach(key => {
                    if (!currentfilters[key]) delete currentfilters[key];
                });

                loadFirmaList();
                showToast('Filtre uygulandı', 'info');
            });

            // Filtreleri temizle
            $('#clearfilters').on('click', function() {
                $('#filterForm')[0].reset();
                $('.select2-filter').val('').trigger('change.select2');
                currentfilters = {};
                loadFirmaList();
                showToast('Filtreler temizlendi', 'info');
            });
        });

        // Alt firma hücresinden: üst firma filtresini bu firmaya ayarla
        function altFirmalariGoster(firmaId) {
            $('#filter_ust_firma_id').val(String(firmaId)).trigger('change.select2');
            $('#filter_tip, #filter_search, #filter_status, #filter_belge_durumu').val('').trigger('change.select2');
            $('#filterCard').collapse('show');

            currentfilters = { ust_firma_id: String(firmaId) };
            loadFirmaList();
            showToast('Alt firmalar listeleniyor', 'info');
        }

        function loadStats() {
            $.post('', { action: 'stats' }, function(response) {
                if (response.success) {
                    $('#stat-toplam').text(response.data.toplam);
                    $('#stat-aktif').text(response.data.aktif);
                    $('#stat-ana').text(response.data.ana);
                    $('#stat-alt').text(response.data.alt);
                }
            }, 'json');
        }

        function loadFilterUstFirmalar() {
            $.post('', { action: 'ust_firma_list' }, function(response) {
                if (response.success) {
                    const $filter = $('#filter_ust_firma_id');
                    const secili = $filter.val();
                    $filter.find('option:not(:first)').remove();
                    response.data.forEach(f => {
                        $filter.append(new Option(f.firma_adi, f.firma_id));
                    });
                    $filter.val(secili).trigger('change.select2');
                }
            }, 'json');
        }

        // Modal üst firma listesini doldurur, secili degeri isaretler
        function loadUstFirmalar(haricFirmaId, seciliId, callback) {
            $.post('', { action: 'ust_firma_list', haric_firma_id: haricFirmaId || 0 }, function(response) {
                const $select = $('#firma_ust_firma_id');
                $select.find('option:not(:first)').remove();

                if (response.success) {
                    response.data.forEach(f => {
                        $select.append(new Option(f.firma_adi, f.firma_id));
                    });
                }

                $select.val(seciliId || '').trigger('change.select2');

                if (callback) callback();
            }, 'json');
        }

        function loadFirmaList() {
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
                        renderFirmaTable(data.data);
                    } else {
                        showToast('Liste yüklenirken hata oluştu', 'error');
                    }
                },
                error: function(error) {
                    showToast('Sunucu hatası oluştu', 'error');
                }
            });
        }

        function renderFirmaTable(firmalar) {
            dataTable.clear();

            firmalar.forEach(firma => {
                const durumBadge = firma.firma_durum
                    ? '<span class="status-badge status-active">Aktif</span>'
                    : '<span class="status-badge status-inactive">Pasif</span>';

                const maxAlt = parseInt(firma.firma_max_alt_firma_sayisi) || 0;
                const maxKul = parseInt(firma.firma_max_kullanici_sayisi) || 0;

                const altBilgi = `${firma.alt_firma_sayisi} / ${maxAlt > 0 ? maxAlt : '∞'}`;
                const kullaniciBilgi = `${firma.kullanici_sayisi} / ${maxKul > 0 ? maxKul : '∞'}`;

                const altRenk = (maxAlt > 0 && firma.alt_firma_sayisi >= maxAlt) ? 'bg-danger' : 'bg-secondary';
                let altBadge = `<span class="badge ${altRenk}">${altBilgi}</span>`;
                if (firma.alt_firma_sayisi > 0) {
                    altBadge = `<a href="#" onclick="altFirmalariGoster(${firma.firma_id}); return false;" class="text-decoration-none" title="Alt firmaları listele">${altBadge}</a>`;
                }

                const kulRenk = (maxKul > 0 && firma.kullanici_sayisi >= maxKul) ? 'bg-danger' : 'bg-secondary';
                let kullaniciBadge = `<span class="badge ${kulRenk}">${kullaniciBilgi}</span>`;
                if (permissions.canViewPersonel) {
                    kullaniciBadge = `<a href="/Admin/personel-yonetimi?firma_id=${firma.firma_id}" class="text-decoration-none" title="Personelleri görüntüle">${kullaniciBadge}</a>`;
                }

                const ticariUnvan = firma.firma_ticari_unvan
                    ? escapeHtml(firma.firma_ticari_unvan.length > 40 ? firma.firma_ticari_unvan.substring(0, 40) + '...' : firma.firma_ticari_unvan)
                    : '-';

                // Belge özeti: toplam aktif belge + onay durumlarına göre adetler (Firma Belgeleri kayıtlarından)
                const belgeSayisi = parseInt(firma.belge_sayisi || 0, 10);
                let onayOzet = [];
                try { onayOzet = JSON.parse(firma.belge_onay_ozet || '[]'); } catch (e) { onayOzet = []; }

                let belgeHucre = '<span class="text-muted">-</span>';
                if (belgeSayisi > 0) {
                    belgeHucre = `<span class="badge bg-secondary" title="Toplam aktif belge">${belgeSayisi}</span>`;
                    onayOzet.forEach(o => {
                        belgeHucre += ` <span class="badge text-bg-${escapeHtml(o.renk || 'secondary')}" title="${escapeHtml(o.adi)}">` +
                            `<i class="${escapeHtml(o.ikon || 'bi bi-dot')}"></i> ${parseInt(o.adet, 10)}</span>`;
                    });
                }

                if (permissions.canViewBelge) {
                    firmaAdlari[firma.firma_id] = firma.firma_adi;
                    belgeHucre = `<a href="#" onclick="belgeleriAc(${firma.firma_id}); return false;" class="text-decoration-none" title="Belgeleri yönet">${belgeHucre}</a>`;
                }

                let islemler = '';
                if (permissions.formErisimi) {
                    islemler += `
                        <a class="btn btn-sm btn-info" href="/Admin/form-yonetimi?firma_id=${firma.firma_id}" title="Form Yönetimi">
                            <i class="bi bi-ui-checks"></i>
                        </a>
                    `;
                }
                if (permissions.evrakErisimi) {
                    islemler += `
                        <a class="btn btn-sm btn-secondary" href="/Admin/evrak-seti-hazirla?firma_id=${firma.firma_id}" title="Evrak Seti Hazırla">
                            <i class="bi bi-files"></i>
                        </a>
                    `;
                }
                if (permissions.canEdit) {
                    islemler += `
                        <button class="btn btn-sm btn-warning" onclick="editFirma(${firma.firma_id})" title="Düzenle">
                            <i class="bi bi-pencil"></i>
                        </button>
                    `;
                }
                if (permissions.canDelete) {
                    islemler += `
                        <button class="btn btn-sm btn-danger" onclick="deleteFirma(${firma.firma_id})" title="Sil">
                            <i class="bi bi-trash"></i>
                        </button>
                    `;
                }
                if (!islemler) {
                    islemler = '<span class="text-muted">-</span>';
                }

                let smsHucre;
                if (!firma.sms_hesap_sayisi) {
                    smsHucre = '<span class="text-muted" title="Tanımlı SMS hesabı yok">-</span>';
                } else if (firma.sms_kredi === null || firma.sms_kredi === undefined) {
                    smsHucre = '<span class="badge text-bg-warning" title="Kredi henüz sorgulanmadı">Sorgulanmadı</span>';
                } else {
                    const kredi = Number(firma.sms_kredi);
                    const renk = kredi <= 0 ? 'text-bg-danger' : (kredi < 100 ? 'text-bg-warning' : 'text-bg-info');
                    smsHucre = `<span class="badge ${renk}">${kredi.toLocaleString('tr-TR')}</span>` +
                        (firma.sms_kredi_tarihi ? `<br><small class="text-muted">${escapeHtml(firma.sms_kredi_tarihi)}</small>` : '');
                }

                if (permissions.canViewSms) {
                    const smsBaslik = firma.sms_hesap_sayisi ? 'SMS hesabını görüntüle' : 'SMS hesabı tanımla';
                    smsHucre = `<a href="/Admin/sms-hesaplari?firma_id=${firma.firma_id}" class="text-decoration-none" title="${smsBaslik}">${smsHucre}</a>`;
                }

                const satir = [
                    escapeHtml(firma.firma_adi),
                    ticariUnvan,
                    smsHucre,
                    altBadge,
                    kullaniciBadge
                ];

                if (permissions.canViewBelge) {
                    satir.push(belgeHucre);
                }

                satir.push(durumBadge, islemler);
                dataTable.row.add(satir);
            });

            dataTable.draw();
        }

        function resetForm() {
            document.getElementById('firmaForm').reset();
            document.getElementById('firma_id').value = '';
            document.getElementById('firma_max_alt_firma_sayisi').value = 0;
            document.getElementById('firma_max_kullanici_sayisi').value = 0;
            document.getElementById('firma_durum').checked = true;
            $('#firma_ust_firma_id').val('').trigger('change.select2');
            document.getElementById('firmaModalLabel').textContent = 'Yeni Firma Ekle';
        }

        function yeniFirma() {
            resetForm();
            loadUstFirmalar(0, '', function() {
                firmaModal.show();
            });
        }

        function saveFirma() {
            const formData = new FormData(document.getElementById('firmaForm'));
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
                        firmaModal.hide();
                        loadFirmaList();
                        loadStats();
                        loadFilterUstFirmalar();
                    } else {
                        showToast(data.message, 'error');
                    }
                },
                error: function(error) {
                    showToast('Kayıt sırasında hata oluştu', 'error');
                }
            });
        }

        function editFirma(firmaid) {
            $.ajax({
                url: '',
                method: 'POST',
                data: { action: 'get', firma_id: firmaid },
                dataType: 'json',
                success: function(data) {
                    if (data.success && data.data) {
                        const firma = data.data;

                        resetForm();

                        document.getElementById('firma_id').value = firma.firma_id;
                        document.getElementById('firma_adi').value = firma.firma_adi || '';
                        document.getElementById('firma_vkn_tc').value = firma.firma_vkn_tc || '';
                        document.getElementById('firma_ticari_unvan').value = firma.firma_ticari_unvan || '';
                        document.getElementById('firma_adres').value = firma.firma_adres || '';
                        document.getElementById('firma_telefon').value = firma.firma_telefon || '';
                        document.getElementById('firma_internet_sitesi').value = firma.firma_internet_sitesi || '';
                        document.getElementById('firma_email').value = firma.firma_email || '';
                        document.getElementById('firma_kep_adresi').value = firma.firma_kep_adresi || '';
                        document.getElementById('firma_yetkili_ad_soyad').value = firma.firma_yetkili_ad_soyad || '';
                        document.getElementById('firma_irtibat_kisisi').value = firma.firma_irtibat_kisisi || '';
                        document.getElementById('firma_max_alt_firma_sayisi').value = firma.firma_max_alt_firma_sayisi ?? 0;
                        document.getElementById('firma_max_kullanici_sayisi').value = firma.firma_max_kullanici_sayisi ?? 0;
                        document.getElementById('firma_durum').checked = firma.firma_durum == 1;

                        document.getElementById('firmaModalLabel').textContent = 'Firma Düzenle';

                        // Kendisi ve alt firmalari haric listeyi yukle
                        loadUstFirmalar(firma.firma_id, firma.firma_ust_firma_id || '', function() {
                            firmaModal.show();
                        });
                    }
                },
                error: function(error) {
                    showToast('Kayıt yüklenirken hata oluştu', 'error');
                }
            });
        }

        function deleteFirma(firmaid) {
            confirmAction(
                'Bu firmayı silmek istediğinize emin misiniz?',
                'Bu işlem geri alınamaz!',
                function() {
                    $.ajax({
                        url: '',
                        method: 'POST',
                        data: { action: 'delete', firma_id: firmaid },
                        dataType: 'json',
                        success: function(data) {
                            if (data.success) {
                                showSuccess('Silindi!', data.message);
                                loadFirmaList();
                                loadStats();
                                loadFilterUstFirmalar();
                            } else {
                                showError('Hata!', data.message);
                            }
                        },
                        error: function(error) {
                            showError('Bağlantı Hatası!', 'Sunucuya ulaşılamıyor.');
                        }
                    });
                }
            );
        }

        // ===================================================================
        // Firma Belgeleri (modal içi yönetim)
        // ===================================================================

        function belgeBoyutFormatla(byte) {
            byte = parseFloat(byte || 0);
            const birimler = ['B', 'KB', 'MB', 'GB'];
            let i = 0;
            while (byte >= 1024 && i < birimler.length - 1) {
                byte /= 1024;
                i++;
            }
            return (i === 0 ? Math.round(byte) : byte.toFixed(1)) + ' ' + birimler[i];
        }

        function belgeTarihFormatla(datestring) {
            if (!datestring) return null;
            const parcalar = String(datestring).substring(0, 10).split('-');
            if (parcalar.length !== 3) return null;
            return parcalar[2] + '.' + parcalar[1] + '.' + parcalar[0];
        }

        // Belge tanımlarını (tip + onay durumu) bir kez yükler
        function belgeTanimlariYukle(callback) {
            if (belgeTipleri.length > 0) {
                if (typeof callback === 'function') callback();
                return;
            }

            $.post('', { action: 'belge_tanimlari' }, function(response) {
                if (response.success) {
                    belgeTipleri = response.tipler || [];
                    belgeOnayDurumlari = response.onay_durumlari || [];

                    const $tip = $('#belge_tipi_id');
                    $tip.empty().append(new Option('Seçiniz...', '', false, false));
                    belgeTipleri.forEach(t => $tip.append(new Option(t.belge_tipi_adi, t.belge_tipi_id, false, false)));

                    const $onay = $('#firma_belge_onay_durumu_id');
                    $onay.empty();
                    belgeOnayDurumlari.forEach(d => $onay.append(new Option(d.onay_durumu_adi, d.onay_durumu_id, false, false)));
                }
                if (typeof callback === 'function') callback();
            }, 'json');
        }

        function belgeleriAc(firmaId) {
            aktifBelgeFirmaId = firmaId;
            aktifBelgeFirmaAdi = firmaAdlari[firmaId] || '';

            $('#belgeModalLabel').html('<i class="bi bi-files"></i> Firma Belgeleri — ' + escapeHtml(aktifBelgeFirmaAdi));
            $('#belge_tum_link').attr('href', '/Admin/firma-belgeleri?firma_id=' + firmaId);
            $('#belgeListBody').html('<tr><td colspan="7" class="text-center text-muted">Yükleniyor...</td></tr>');
            $('#belge_zorunlu_ozet').empty();

            belgeTanimlariYukle(function() {
                belgeListesiYukle();
                belgeModal.show();
            });
        }

        function belgeListesiYukle() {
            $.post('', { action: 'belge_list', firma_id: aktifBelgeFirmaId }, function(response) {
                if (!response.success) {
                    $('#belgeListBody').html(`<tr><td colspan="7" class="text-center text-danger">${escapeHtml(response.message)}</td></tr>`);
                    return;
                }

                belgeZorunluOzetCiz(response.zorunlu || []);
                belgeTablosuCiz(response.data || []);
            }, 'json');
        }

        // Zorunlu belge tiplerinin firmada bulunup bulunmadığını gösterir
        function belgeZorunluOzetCiz(zorunlular) {
            if (!zorunlular.length) {
                $('#belge_zorunlu_ozet').empty();
                return;
            }

            let html = '<div class="d-flex flex-wrap gap-2 align-items-center"><small class="text-muted me-1">Zorunlu belgeler:</small>';
            zorunlular.forEach(z => {
                const varMi = parseInt(z.belge_sayisi || 0, 10) > 0;
                html += `
                    <span class="badge ${varMi ? 'bg-success' : 'bg-danger'}">
                        <i class="bi ${varMi ? 'bi-check-lg' : 'bi-exclamation-triangle'}"></i>
                        ${escapeHtml(z.belge_tipi_adi)}
                    </span>
                `;
            });
            html += '</div>';
            $('#belge_zorunlu_ozet').html(html);
        }

        function belgeTablosuCiz(belgeler) {
            if (!belgeler.length) {
                $('#belgeListBody').html('<tr><td colspan="7" class="text-center text-muted">Bu firmaya ait belge yok</td></tr>');
                return;
            }

            let html = '';
            belgeler.forEach(b => {
                const ikon = b.belge_tipi_ikon
                    ? `<i class="${escapeHtml(b.belge_tipi_ikon)} me-1"></i>`
                    : '<i class="bi bi-file-earmark me-1"></i>';

                const dosyaVar = !!b.firma_belge_dosya_adi;
                const baslik = b.firma_belge_baslik || b.firma_belge_dosya_adi || b.belge_tipi_adi;

                // Geçerlilik
                let gecerlilik;
                const bitis = belgeTarihFormatla(b.firma_belge_gecerlilik_bitis);
                if (!bitis) {
                    gecerlilik = '<span class="text-muted">Süresiz</span>';
                } else {
                    const kalan = b.kalan_gun === null ? null : parseInt(b.kalan_gun, 10);
                    let renk = 'success';
                    let ek = '';
                    if (kalan !== null && kalan < 0) {
                        renk = 'danger';
                        ek = `<br><small>${Math.abs(kalan)} gün önce doldu</small>`;
                    } else if (kalan !== null && kalan <= 30) {
                        renk = 'warning text-dark';
                        ek = `<br><small>${kalan} gün kaldı</small>`;
                    }
                    gecerlilik = `<span class="badge bg-${renk}">${bitis}</span>${ek}`;
                }

                let onay = `<span class="badge text-bg-${escapeHtml(b.onay_durumu_renk || 'secondary')}">
                        <i class="${escapeHtml(b.onay_durumu_ikon || 'bi bi-dot')}"></i> ${escapeHtml(b.onay_durumu_adi)}
                    </span>`;
                if (b.firma_belge_onay_aciklama) {
                    onay += `<br><small class="text-muted">${escapeHtml(b.firma_belge_onay_aciklama.length > 40 ? b.firma_belge_onay_aciklama.substring(0, 40) + '...' : b.firma_belge_onay_aciklama)}</small>`;
                }

                let islemler = dosyaVar ? `
                    <a class="btn btn-sm btn-info" href="/admin/belge-indir.php?id=${b.firma_belge_id}&onizle=1" target="_blank" title="Önizle">
                        <i class="bi bi-eye"></i>
                    </a>
                    <a class="btn btn-sm btn-success" href="/admin/belge-indir.php?id=${b.firma_belge_id}" title="İndir">
                        <i class="bi bi-download"></i>
                    </a>
                ` : '';
                if (permissions.belgeEdit) {
                    islemler += `
                        <button class="btn btn-sm btn-primary" onclick="belgeOnayAc(${b.firma_belge_id}, ${b.firma_belge_onay_durumu_id})" title="Onay Durumu">
                            <i class="bi bi-clipboard-check"></i>
                        </button>
                        <button class="btn btn-sm btn-warning" onclick="belgeDuzenle(${b.firma_belge_id})" title="Düzenle">
                            <i class="bi bi-pencil"></i>
                        </button>
                    `;
                }
                if (permissions.belgeDelete) {
                    islemler += `
                        <button class="btn btn-sm btn-danger" onclick="belgeSil(${b.firma_belge_id})" title="Sil">
                            <i class="bi bi-trash"></i>
                        </button>
                    `;
                }

                html += `
                    <tr>
                        <td>${ikon}${escapeHtml(b.belge_tipi_adi)}</td>
                        <td>
                            <strong>${escapeHtml(baslik)}</strong><br>
                            <small class="text-muted">
                                ${dosyaVar
                                    ? `<span class="badge bg-secondary">${escapeHtml((b.firma_belge_dosya_uzanti || '').toUpperCase())}</span>
                                       ${escapeHtml(b.firma_belge_dosya_adi)} · ${belgeBoyutFormatla(b.firma_belge_dosya_boyut)}`
                                    : '<span class="badge text-bg-light border"><i class="bi bi-file-earmark-x"></i> Dosya yok</span>'}
                            </small>
                        </td>
                        <td>${gecerlilik}</td>
                        <td>${onay}</td>
                        <td>${escapeHtml(b.yukleyen_kullanici || '-')}</td>
                        <td>${b.firma_belge_durum
                                ? '<span class="status-badge status-active">Aktif</span>'
                                : '<span class="status-badge status-inactive">Pasif</span>'}</td>
                        <td>${islemler}</td>
                    </tr>
                `;
            });

            $('#belgeListBody').html(html);
        }

        function belgeTipiBilgisiGoster(belgeTipiId) {
            const tip = belgeTipleri.find(t => String(t.belge_tipi_id) === String(belgeTipiId));
            if (!tip) {
                $('#belge_tipi_bilgi').text('');
                $('#belge_dosya_yardim').text('Önce belge tipini seçin.');
                $('#belge_dosya').attr('accept', '').prop('multiple', false);
                return;
            }

            // Çoklu seçim tip izin veriyorsa; düzenlemede ilk dosya mevcudun yerine geçer
            const coklu = tip.belge_tipi_coklu_yukleme == 1;
            const duzenleme = !!$('#belge_firma_belge_id').val();
            $('#belge_dosya').prop('multiple', coklu);

            const formatlar = (tip.belge_tipi_izinli_formatlar || '').split(',').map(f => f.trim()).filter(f => f);

            let bilgi = [];
            if (tip.belge_tipi_zorunlu == 1) bilgi.push('Zorunlu belge');
            bilgi.push(tip.belge_tipi_coklu_yukleme == 1 ? 'Çoklu yükleme açık' : 'Firma başına tek aktif belge');
            if (tip.belge_tipi_gecerlilik_ay) bilgi.push(tip.belge_tipi_gecerlilik_ay + ' ay geçerli');
            $('#belge_tipi_bilgi').text(bilgi.join(' • '));

            // Dosya gerektirmeyen tipte yıldız gizlenir; düzenlemede zaten gizli
            const dosyaGerekli = tip.belge_tipi_dosya_zorunlu != 0;
            $('#belge_dosya_zorunlu').toggleClass('d-none', !dosyaGerekli || duzenleme);

            $('#belge_dosya_yardim').text(
                (dosyaGerekli ? '' : 'Bu belge tipinde dosya isteğe bağlıdır • ') +
                'İzinli formatlar: ' + (formatlar.length ? formatlar.join(', ').toUpperCase() : 'Tüm tanımlı formatlar') +
                ' • Maksimum ' + tip.belge_tipi_max_boyut_mb + ' MB' +
                (coklu ? ' • Birden fazla dosya seçebilirsiniz' : '') +
                (coklu && duzenleme ? ' (ilk dosya mevcut dosyanın yerine geçer, diğerleri yeni belge olarak eklenir)' : '')
            );
            $('#belge_dosya').attr('accept', formatlar.map(f => '.' + f).join(','));
        }

        function belgeGecerlilikBitisHesapla() {
            const tip = belgeTipleri.find(t => String(t.belge_tipi_id) === String($('#belge_tipi_id').val()));
            const baslangic = $('#firma_belge_gecerlilik_baslangic').val();

            if (!tip || !tip.belge_tipi_gecerlilik_ay || !baslangic) return;
            if ($('#firma_belge_gecerlilik_bitis').val()) return;

            const d = new Date(baslangic);
            if (isNaN(d.getTime())) return;

            d.setMonth(d.getMonth() + parseInt(tip.belge_tipi_gecerlilik_ay, 10));
            $('#firma_belge_gecerlilik_bitis').val(d.toISOString().substring(0, 10));
        }

        function belgeFormAc() {
            document.getElementById('belgeForm').reset();
            document.getElementById('belge_firma_belge_id').value = '';
            document.getElementById('belge_firma_id').value = aktifBelgeFirmaId;
            document.getElementById('belgeFormModalLabel').textContent = 'Belge Yükle — ' + aktifBelgeFirmaAdi;
            $('#belge_tipi_id').val('').trigger('change.select2');
            $('#belge_mevcut_dosya').addClass('d-none');
            $('#belge_dosya_zorunlu').removeClass('d-none');
            $('#belge_tipi_bilgi').text('');
            $('#belge_dosya_yardim').text('Önce belge tipini seçin.');
            $('#belge_dosya').prop('multiple', false);
            belgeFormModal.show();
        }

        function belgeDuzenle(belgeId) {
            $.post('', { action: 'belge_get', firma_belge_id: belgeId }, function(response) {
                if (!response.success || !response.data) {
                    showToast(response.message || 'Belge yüklenemedi', 'error');
                    return;
                }

                const b = response.data;

                document.getElementById('belge_firma_belge_id').value = b.firma_belge_id;
                document.getElementById('belge_firma_id').value = b.firma_id;
                document.getElementById('firma_belge_baslik').value = b.firma_belge_baslik || '';
                document.getElementById('firma_belge_aciklama').value = b.firma_belge_aciklama || '';
                document.getElementById('firma_belge_gecerlilik_baslangic').value = b.firma_belge_gecerlilik_baslangic || '';
                document.getElementById('firma_belge_gecerlilik_bitis').value = b.firma_belge_gecerlilik_bitis || '';
                document.getElementById('firma_belge_durum').checked = b.firma_belge_durum == 1;
                document.getElementById('belge_dosya').value = '';

                $('#belge_tipi_id').val(b.belge_tipi_id).trigger('change.select2');
                belgeTipiBilgisiGoster(b.belge_tipi_id);

                $('#belge_mevcut_dosya_adi').text(b.firma_belge_dosya_adi || 'Dosya yok');
                $('#belge_mevcut_dosya').removeClass('d-none');
                $('#belge_dosya_zorunlu').addClass('d-none');

                document.getElementById('belgeFormModalLabel').textContent = 'Belge Düzenle';
                belgeFormModal.show();
            }, 'json');
        }

        function belgeKaydet() {
            const formData = new FormData(document.getElementById('belgeForm'));
            formData.append('action', 'belge_kaydet');

            const $btn = $('#belgeKaydetBtn');
            $btn.prop('disabled', true).html('<i class="bi bi-hourglass-split"></i> Yükleniyor...');

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
                        belgeFormModal.hide();
                        belgeListesiYukle();
                        loadFirmaList();
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

        function belgeOnayAc(belgeId, mevcutDurumId) {
            document.getElementById('onay_firma_belge_id').value = belgeId;
            document.getElementById('firma_belge_onay_aciklama').value = '';
            $('#firma_belge_onay_durumu_id').val(mevcutDurumId).trigger('change.select2');
            belgeOnayModal.show();
        }

        function belgeOnayKaydet() {
            const formData = new FormData(document.getElementById('belgeOnayForm'));
            formData.append('action', 'belge_onay');

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
                        belgeOnayModal.hide();
                        belgeListesiYukle();
                        loadFirmaList();
                    } else {
                        showToast(data.message, 'error');
                    }
                },
                error: function() {
                    showToast('İşlem sırasında hata oluştu', 'error');
                }
            });
        }

        function belgeSil(belgeId) {
            confirmAction(
                'Bu belgeyi silmek istediğinize emin misiniz?',
                'Dosya sunucudan da silinecek, bu işlem geri alınamaz!',
                function() {
                    $.post('', { action: 'belge_sil', firma_belge_id: belgeId }, function(data) {
                        if (data.success) {
                            showSuccess('Silindi!', data.message);
                            belgeListesiYukle();
                            loadFirmaList();
                        } else {
                            showError('Hata!', data.message);
                        }
                    }, 'json');
                }
            );
        }

        // Belge modallarının kurulumu
        $(document).ready(function() {
            if (!permissions.canViewBelge) return;

            belgeModal = new bootstrap.Modal(document.getElementById('belgeModal'));
            belgeFormModal = new bootstrap.Modal(document.getElementById('belgeFormModal'));
            belgeOnayModal = new bootstrap.Modal(document.getElementById('belgeOnayModal'));

            $('#belge_tipi_id').select2({
                theme: 'bootstrap-5',
                width: '100%',
                placeholder: 'Belge tipi seçin...',
                dropdownParent: $('#belgeFormModal')
            });

            $('#firma_belge_onay_durumu_id').select2({
                theme: 'bootstrap-5',
                width: '100%',
                placeholder: 'Seçiniz...',
                dropdownParent: $('#belgeOnayModal')
            });

            $('#belge_tipi_id').on('change', function() {
                belgeTipiBilgisiGoster($(this).val());
                belgeGecerlilikBitisHesapla();
            });

            $('#firma_belge_gecerlilik_baslangic').on('change', belgeGecerlilikBitisHesapla);

            $('#belgeForm').on('submit', function(e) {
                e.preventDefault();
                belgeKaydet();
            });

            $('#belgeOnayForm').on('submit', function(e) {
                e.preventDefault();
                belgeOnayKaydet();
            });
        });
    </script>
</body>
</html>
