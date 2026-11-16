<?php
/**
 * Admin Panel - Entegrasyon Yönetimi
 * Entegrasyonlar, kanallar ve logların yönetimi + bağlantı testleri
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

$pageTitle = $pageinfo['sayfalar_sayfa_adi'] ?? 'Entegrasyon Yönetimi';
$pageDescription = $pageinfo['sayfalar_aciklama'] ?? '';
$menuAdi = $pageinfo['menu_adi'] ?? null;

// Sayfa yetkilerini kontrol et
$permissions = PageAuth::checkPagePermissions($user['kullanici_id'], $user['departman_id'], $currentPagefile);

// Erişim Yetkisi yoksa hata sayfası göster
if (!$permissions['has_access']) {
    PageAuth::accessDenied($permissions['error'] ?? 'Bu sayfaya erişim Yetkiniz yok!');
}

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

/**
 * cURL ile HTTP isteği gönder
 * @return array [httpKod, yanit, sureMs]
 */
function httpIstek($url, $method = 'GET', $body = null, $headers = [], $timeout = 15) {
    $start = microtime(true);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers
    ]);

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $yanit = curl_exec($ch);
    $httpKod = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hata = curl_error($ch);
    curl_close($ch);

    $sureMs = (int)round((microtime(true) - $start) * 1000);

    if ($yanit === false) {
        return [0, 'cURL hatası: ' . $hata, $sureMs];
    }

    return [$httpKod, $yanit, $sureMs];
}

/**
 * Entegrasyon bağlantı testi
 * Entegrasyon koduna göre uygun endpoint'lere istek atar, sonucu loglar
 */
function entegrasyonTest($db, $entegrasyon, $kanal, $userId) {
    $ayarlar = json_decode($entegrasyon['entegrasyon_ayarlar'] ?? '{}', true) ?: [];
    $kanalAyarlar = $kanal ? (json_decode($kanal['kanal_ayarlar'] ?? '{}', true) ?: []) : [];
    $config = array_merge($ayarlar, $kanalAyarlar);

    $hedefler = [];

    // Entegrasyon koduna göre test hedeflerini belirle
    if ($entegrasyon['entegrasyon_kod'] === 'whatsapp') {
        $baseUrl = rtrim($config['BaseURL'] ?? '', '/');
        $apiKey = $config['GlobalApiKey'] ?? $config['ApiKey'] ?? '';

        if (!empty($config['Instance'])) {
            $hedefler[] = [
                'ad' => 'Instance Durumu (' . $config['Instance'] . ')',
                'url' => $baseUrl . '/instance/connectionState/' . rawurlencode($config['Instance']),
                'headers' => ['apikey: ' . $apiKey]
            ];
        } else {
            $hedefler[] = [
                'ad' => 'Instance Listesi',
                'url' => $baseUrl . '/instance/fetchInstances',
                'headers' => ['apikey: ' . $apiKey]
            ];
        }
    } else {
        // Genel yaklaşım: ayarlardaki tüm URL değerlerine erişilebilirlik testi
        foreach ($config as $anahtar => $deger) {
            if (is_string($deger) && filter_var($deger, FILTER_VALIDATE_URL)) {
                $hedefler[] = [
                    'ad' => $anahtar,
                    'url' => $deger,
                    'headers' => []
                ];
            }
        }
    }

    if (empty($hedefler)) {
        return [
            'basarili' => false,
            'sonuclar' => [],
            'mesaj' => 'Test edilecek URL bulunamadı. Ayarlar JSON içinde geçerli bir URL olmalı.'
        ];
    }

    $sonuclar = [];
    $tumBasarili = true;
    $toplamSure = 0;

    foreach ($hedefler as $hedef) {
        [$kod, $yanit, $sure] = httpIstek($hedef['url'], 'GET', null, $hedef['headers']);
        $basarili = ($kod >= 200 && $kod < 400);

        if (!$basarili) $tumBasarili = false;
        $toplamSure += $sure;

        $sonuclar[] = [
            'ad' => $hedef['ad'],
            'url' => $hedef['url'],
            'http_kod' => $kod,
            'sure_ms' => $sure,
            'basarili' => $basarili,
            'yanit' => mb_substr($yanit, 0, 1000)
        ];
    }

    // Sonucu logla
    $db->insert('EntegrasyonLoglari', [
        'log_entegrasyon_id' => $entegrasyon['entegrasyon_id'],
        'log_kanal_id' => $kanal['kanal_id'] ?? null,
        'log_islem' => 'baglanti_testi',
        'log_istek' => json_encode(array_map(fn($h) => ['ad' => $h['ad'], 'url' => $h['url']], $hedefler), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'log_yanit' => json_encode($sonuclar, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'log_http_kod' => $sonuclar[0]['http_kod'] ?? null,
        'log_sure_ms' => $toplamSure,
        'log_basarili' => $tumBasarili ? 1 : 0,
        'log_olusturan_kullanici_id' => $userId,
        'log_tarih' => date('Y-m-d H:i:s')
    ]);

    return [
        'basarili' => $tumBasarili,
        'sonuclar' => $sonuclar,
        'mesaj' => $tumBasarili ? 'Tüm bağlantı testleri başarılı' : 'Bazı bağlantı testleri başarısız oldu'
    ];
}

// AJAX işlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {
            // ===================== İSTATİSTİKLER =====================
            case 'stats':
                $stats = [
                    'entegrasyon' => $db->fetchOne("SELECT count(*) as count FROM Entegrasyonlar WHERE entegrasyon_durum = 1")['count'] ?? 0,
                    'kanal' => $db->fetchOne("SELECT count(*) as count FROM EntegrasyonKanallari WHERE kanal_durum = 1")['count'] ?? 0,
                    'bugun_log' => $db->fetchOne("SELECT count(*) as count FROM EntegrasyonLoglari WHERE CAST(log_tarih AS DATE) = CAST(GETDATE() AS DATE)")['count'] ?? 0,
                    'bugun_hata' => $db->fetchOne("SELECT count(*) as count FROM EntegrasyonLoglari WHERE CAST(log_tarih AS DATE) = CAST(GETDATE() AS DATE) AND log_basarili = 0")['count'] ?? 0
                ];
                echo json_encode(['success' => true, 'data' => $stats]);
                break;

            // ===================== ENTEGRASYONLAR =====================
            case 'list_entegrasyon':
                $search = $_POST['search'] ?? '';
                $status = $_POST['status'] ?? '';

                $whereConditions = ["1=1"];
                $params = [];

                if ($search) {
                    $whereConditions[] = "(e.entegrasyon_adi LIKE ? OR e.entegrasyon_kod LIKE ?)";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                }
                if ($status !== '') {
                    $whereConditions[] = "e.entegrasyon_durum = ?";
                    $params[] = $status;
                }

                $whereClause = implode(" AND ", $whereConditions);

                $list = $db->fetchAll("
                    SELECT
                        e.entegrasyon_id,
                        e.entegrasyon_adi,
                        e.entegrasyon_kod,
                        e.entegrasyon_aciklama,
                        e.entegrasyon_ayarlar,
                        e.entegrasyon_durum,
                        CONVERT(VARCHAR(19), e.entegrasyon_olusturma_tarihi, 120) as entegrasyon_olusturma_tarihi,
                        (SELECT count(*) FROM EntegrasyonKanallari k WHERE k.kanal_entegrasyon_id = e.entegrasyon_id AND k.kanal_durum = 1) as kanal_sayisi,
                        (SELECT TOP 1 l.log_basarili FROM EntegrasyonLoglari l WHERE l.log_entegrasyon_id = e.entegrasyon_id AND l.log_islem = 'baglanti_testi' ORDER BY l.log_id DESC) as son_test_durum,
                        (SELECT TOP 1 CONVERT(VARCHAR(19), l.log_tarih, 120) FROM EntegrasyonLoglari l WHERE l.log_entegrasyon_id = e.entegrasyon_id AND l.log_islem = 'baglanti_testi' ORDER BY l.log_id DESC) as son_test_tarihi
                    FROM Entegrasyonlar e
                    WHERE $whereClause
                    ORDER BY e.entegrasyon_adi
                ", $params);
                echo json_encode(['success' => true, 'data' => $list]);
                break;

            case 'get_entegrasyon':
                $id = $_POST['entegrasyon_id'] ?? 0;
                $row = $db->fetchOne("SELECT * FROM Entegrasyonlar WHERE entegrasyon_id = ?", [$id]);
                echo json_encode(['success' => true, 'data' => $row]);
                break;

            case 'save_entegrasyon':
                $id = $_POST['entegrasyon_id'] ?? 0;

                if ($id > 0 && !$permissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Düzenleme yetkiniz yok!']);
                    break;
                }
                if ($id == 0 && !$permissions['can_add']) {
                    echo json_encode(['success' => false, 'message' => 'Ekleme yetkiniz yok!']);
                    break;
                }

                // JSON doğrulama
                $ayarlarJson = trim($_POST['entegrasyon_ayarlar'] ?? '');
                if ($ayarlarJson !== '' && json_decode($ayarlarJson) === null && json_last_error() !== JSON_ERROR_NONE) {
                    echo json_encode(['success' => false, 'message' => 'Ayarlar geçerli bir JSON değil: ' . json_last_error_msg()]);
                    break;
                }

                $data = [
                    'entegrasyon_adi' => $_POST['entegrasyon_adi'] ?? '',
                    'entegrasyon_kod' => strtolower(trim($_POST['entegrasyon_kod'] ?? '')),
                    'entegrasyon_aciklama' => $_POST['entegrasyon_aciklama'] ?? null,
                    'entegrasyon_ayarlar' => $ayarlarJson ?: null,
                    'entegrasyon_durum' => isset($_POST['entegrasyon_durum']) ? 1 : 0,
                ];

                // Kod benzersizlik kontrolü
                $mevcut = $db->fetchOne("SELECT entegrasyon_id FROM Entegrasyonlar WHERE entegrasyon_kod = ? AND entegrasyon_id != ?", [$data['entegrasyon_kod'], $id]);
                if ($mevcut) {
                    echo json_encode(['success' => false, 'message' => 'Bu entegrasyon kodu zaten kullanılıyor!']);
                    break;
                }

                if ($id > 0) {
                    $data['entegrasyon_guncelleme_tarihi'] = date('Y-m-d H:i:s');
                    $data['entegrasyon_guncelleyen_kullanici_id'] = $user['kullanici_id'];

                    $result = $db->update('Entegrasyonlar', $data, ['entegrasyon_id' => $id]);
                    echo json_encode(['success' => $result, 'message' => $result ? 'Entegrasyon başarıyla güncellendi' : 'Güncelleme hatası']);
                } else {
                    $data['entegrasyon_olusturma_tarihi'] = date('Y-m-d H:i:s');
                    $data['entegrasyon_olusturan_kullanici_id'] = $user['kullanici_id'];

                    $result = $db->insert('Entegrasyonlar', $data);
                    echo json_encode(['success' => (bool)$result, 'message' => $result ? 'Entegrasyon başarıyla eklendi' : 'Ekleme hatası']);
                }
                break;

            case 'delete_entegrasyon':
                if (!$permissions['can_delete']) {
                    echo json_encode(['success' => false, 'message' => 'Silme yetkiniz yok!']);
                    break;
                }

                $id = $_POST['entegrasyon_id'] ?? 0;

                $checks = [];
                $kanalCount = $db->fetchOne("SELECT count(*) as count FROM EntegrasyonKanallari WHERE kanal_entegrasyon_id = ?", [$id])['count'] ?? 0;
                if ($kanalCount > 0) $checks[] = "$kanalCount kanal";

                $logCount = $db->fetchOne("SELECT count(*) as count FROM EntegrasyonLoglari WHERE log_entegrasyon_id = ?", [$id])['count'] ?? 0;
                if ($logCount > 0) $checks[] = "$logCount log kaydı";

                if (!empty($checks)) {
                    echo json_encode(['success' => false, 'message' => 'Bu entegrasyon silinemez! Bağlı kayıtlar: ' . implode(', ', $checks)]);
                    break;
                }

                $result = $db->delete('Entegrasyonlar', ['entegrasyon_id' => $id]);
                echo json_encode(['success' => true, 'message' => $result ? 'Entegrasyon başarıyla silindi' : 'Silme hatası']);
                break;

            // ===================== KANALLAR =====================
            case 'list_kanal':
                $entegrasyonId = $_POST['entegrasyon_id'] ?? '';
                $search = $_POST['search'] ?? '';
                $status = $_POST['status'] ?? '';

                $whereConditions = ["1=1"];
                $params = [];

                if ($entegrasyonId !== '') {
                    $whereConditions[] = "k.kanal_entegrasyon_id = ?";
                    $params[] = $entegrasyonId;
                }
                if ($search) {
                    $whereConditions[] = "k.kanal_adi LIKE ?";
                    $params[] = "%$search%";
                }
                if ($status !== '') {
                    $whereConditions[] = "k.kanal_durum = ?";
                    $params[] = $status;
                }

                $whereClause = implode(" AND ", $whereConditions);

                $list = $db->fetchAll("
                    SELECT
                        k.kanal_id,
                        k.kanal_entegrasyon_id,
                        k.kanal_adi,
                        k.kanal_ayarlar,
                        k.kanal_varsayilan,
                        k.kanal_durum,
                        CONVERT(VARCHAR(19), k.kanal_olusturma_tarihi, 120) as kanal_olusturma_tarihi,
                        e.entegrasyon_adi
                    FROM EntegrasyonKanallari k
                    INNER JOIN Entegrasyonlar e ON k.kanal_entegrasyon_id = e.entegrasyon_id
                    WHERE $whereClause
                    ORDER BY e.entegrasyon_adi, k.kanal_adi
                ", $params);
                echo json_encode(['success' => true, 'data' => $list]);
                break;

            case 'get_kanal':
                $id = $_POST['kanal_id'] ?? 0;
                $row = $db->fetchOne("SELECT * FROM EntegrasyonKanallari WHERE kanal_id = ?", [$id]);
                echo json_encode(['success' => true, 'data' => $row]);
                break;

            case 'save_kanal':
                $id = $_POST['kanal_id'] ?? 0;

                if ($id > 0 && !$permissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Düzenleme yetkiniz yok!']);
                    break;
                }
                if ($id == 0 && !$permissions['can_add']) {
                    echo json_encode(['success' => false, 'message' => 'Ekleme yetkiniz yok!']);
                    break;
                }

                // JSON doğrulama
                $ayarlarJson = trim($_POST['kanal_ayarlar'] ?? '');
                if ($ayarlarJson !== '' && json_decode($ayarlarJson) === null && json_last_error() !== JSON_ERROR_NONE) {
                    echo json_encode(['success' => false, 'message' => 'Ayarlar geçerli bir JSON değil: ' . json_last_error_msg()]);
                    break;
                }

                $data = [
                    'kanal_entegrasyon_id' => $_POST['kanal_entegrasyon_id'] ?? 0,
                    'kanal_adi' => $_POST['kanal_adi'] ?? '',
                    'kanal_ayarlar' => $ayarlarJson ?: null,
                    'kanal_varsayilan' => isset($_POST['kanal_varsayilan']) ? 1 : 0,
                    'kanal_durum' => isset($_POST['kanal_durum']) ? 1 : 0,
                ];

                // Varsayılan işaretlendiyse aynı entegrasyonun diğer kanallarını varsayılandan çıkar
                if ($data['kanal_varsayilan'] == 1) {
                    $db->execute("UPDATE EntegrasyonKanallari SET kanal_varsayilan = 0 WHERE kanal_entegrasyon_id = ? AND kanal_id != ?", [$data['kanal_entegrasyon_id'], $id]);
                }

                if ($id > 0) {
                    $data['kanal_guncelleme_tarihi'] = date('Y-m-d H:i:s');
                    $data['kanal_guncelleyen_kullanici_id'] = $user['kullanici_id'];

                    $result = $db->update('EntegrasyonKanallari', $data, ['kanal_id' => $id]);
                    echo json_encode(['success' => $result, 'message' => $result ? 'Kanal başarıyla güncellendi' : 'Güncelleme hatası']);
                } else {
                    $data['kanal_olusturma_tarihi'] = date('Y-m-d H:i:s');
                    $data['kanal_olusturan_kullanici_id'] = $user['kullanici_id'];

                    $result = $db->insert('EntegrasyonKanallari', $data);
                    echo json_encode(['success' => (bool)$result, 'message' => $result ? 'Kanal başarıyla eklendi' : 'Ekleme hatası']);
                }
                break;

            case 'delete_kanal':
                if (!$permissions['can_delete']) {
                    echo json_encode(['success' => false, 'message' => 'Silme yetkiniz yok!']);
                    break;
                }

                $id = $_POST['kanal_id'] ?? 0;

                $logCount = $db->fetchOne("SELECT count(*) as count FROM EntegrasyonLoglari WHERE log_kanal_id = ?", [$id])['count'] ?? 0;
                if ($logCount > 0) {
                    echo json_encode(['success' => false, 'message' => "Bu kanal silinemez! $logCount log kaydı bağlı."]);
                    break;
                }

                $result = $db->delete('EntegrasyonKanallari', ['kanal_id' => $id]);
                echo json_encode(['success' => true, 'message' => $result ? 'Kanal başarıyla silindi' : 'Silme hatası']);
                break;

            // ===================== LOGLAR =====================
            case 'list_log':
                $entegrasyonId = $_POST['entegrasyon_id'] ?? '';
                $basarili = $_POST['basarili'] ?? '';
                $tarihBas = $_POST['tarih_bas'] ?? '';
                $tarihBit = $_POST['tarih_bit'] ?? '';

                $whereConditions = ["1=1"];
                $params = [];

                if ($entegrasyonId !== '') {
                    $whereConditions[] = "l.log_entegrasyon_id = ?";
                    $params[] = $entegrasyonId;
                }
                if ($basarili !== '') {
                    $whereConditions[] = "l.log_basarili = ?";
                    $params[] = $basarili;
                }
                if ($tarihBas !== '') {
                    $whereConditions[] = "l.log_tarih >= ?";
                    $params[] = $tarihBas . ' 00:00:00';
                }
                if ($tarihBit !== '') {
                    $whereConditions[] = "l.log_tarih <= ?";
                    $params[] = $tarihBit . ' 23:59:59';
                }

                $whereClause = implode(" AND ", $whereConditions);

                $list = $db->fetchAll("
                    SELECT TOP 500
                        l.log_id,
                        l.log_islem,
                        l.log_http_kod,
                        l.log_sure_ms,
                        l.log_basarili,
                        CONVERT(VARCHAR(19), l.log_tarih, 120) as log_tarih,
                        e.entegrasyon_adi,
                        k.kanal_adi,
                        ku.kullanici_ad + ' ' + ku.kullanici_soyad as kullanici
                    FROM EntegrasyonLoglari l
                    INNER JOIN Entegrasyonlar e ON l.log_entegrasyon_id = e.entegrasyon_id
                    LEFT JOIN EntegrasyonKanallari k ON l.log_kanal_id = k.kanal_id
                    LEFT JOIN kullanicilar ku ON l.log_olusturan_kullanici_id = ku.kullanici_id
                    WHERE $whereClause
                    ORDER BY l.log_id DESC
                ", $params);
                echo json_encode(['success' => true, 'data' => $list]);
                break;

            case 'get_log':
                $id = $_POST['log_id'] ?? 0;
                $row = $db->fetchOne("
                    SELECT
                        l.*,
                        CONVERT(VARCHAR(19), l.log_tarih, 120) as log_tarih_str,
                        e.entegrasyon_adi,
                        k.kanal_adi
                    FROM EntegrasyonLoglari l
                    INNER JOIN Entegrasyonlar e ON l.log_entegrasyon_id = e.entegrasyon_id
                    LEFT JOIN EntegrasyonKanallari k ON l.log_kanal_id = k.kanal_id
                    WHERE l.log_id = ?
                ", [$id]);
                echo json_encode(['success' => true, 'data' => $row]);
                break;

            // ===================== DROPDOWN SEÇENEKLERİ =====================
            case 'entegrasyon_options':
                $list = $db->fetchAll("SELECT entegrasyon_id, entegrasyon_adi FROM Entegrasyonlar WHERE entegrasyon_durum = 1 ORDER BY entegrasyon_adi");
                echo json_encode(['success' => true, 'data' => $list]);
                break;

            // ===================== BAĞLANTI TESTİ =====================
            case 'test':
                $entegrasyonId = $_POST['entegrasyon_id'] ?? 0;
                $kanalId = $_POST['kanal_id'] ?? 0;

                $entegrasyon = $db->fetchOne("SELECT * FROM Entegrasyonlar WHERE entegrasyon_id = ?", [$entegrasyonId]);
                if (!$entegrasyon) {
                    echo json_encode(['success' => false, 'message' => 'Entegrasyon bulunamadı!']);
                    break;
                }

                $kanal = null;
                if ($kanalId > 0) {
                    $kanal = $db->fetchOne("SELECT * FROM EntegrasyonKanallari WHERE kanal_id = ?", [$kanalId]);
                }

                $sonuc = entegrasyonTest($db, $entegrasyon, $kanal, $user['kullanici_id']);
                echo json_encode([
                    'success' => $sonuc['basarili'],
                    'message' => $sonuc['mesaj'],
                    'data' => $sonuc['sonuclar']
                ]);
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
        .json-preview {
            font-family: monospace;
            font-size: 0.75rem;
            max-width: 300px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            display: inline-block;
        }
        textarea.json-editor {
            font-family: monospace;
            font-size: 0.85rem;
        }
        .test-sonuc-yanit {
            font-family: monospace;
            font-size: 0.75rem;
            max-height: 150px;
            overflow-y: auto;
            background: var(--bs-tertiary-bg);
            border-radius: 0.25rem;
            padding: 0.5rem;
            white-space: pre-wrap;
            word-break: break-all;
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
                        <div class="col-md-3">
                            <div class="info-box text-bg-primary">
                                <span class="info-box-icon"><i class="bi bi-plug"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Aktif Entegrasyon</span>
                                    <span class="info-box-number" id="stat-entegrasyon">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-box text-bg-info">
                                <span class="info-box-icon"><i class="bi bi-diagram-3"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Aktif Kanal</span>
                                    <span class="info-box-number" id="stat-kanal">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-box text-bg-success">
                                <span class="info-box-icon"><i class="bi bi-journal-text"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Bugünkü İstek</span>
                                    <span class="info-box-number" id="stat-bugun-log">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-box text-bg-danger">
                                <span class="info-box-icon"><i class="bi bi-exclamation-triangle"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Bugünkü Hata</span>
                                    <span class="info-box-number" id="stat-bugun-hata">0</span>
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
                                        <input type="text" class="form-control" name="search" id="filter_search" placeholder="Ad veya kod...">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Entegrasyon</label>
                                        <select class="form-select select2" name="entegrasyon_id" id="filter_entegrasyon">
                                            <option value="">Tümü</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Durum</label>
                                        <select class="form-select select2" name="status" id="filter_status">
                                            <option value="">Tümü</option>
                                            <option value="1">Aktif</option>
                                            <option value="0">Pasif</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Başlangıç Tarihi (Log)</label>
                                        <input type="date" class="form-control" id="filter_tarih_bas">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Bitiş Tarihi (Log)</label>
                                        <input type="date" class="form-control" id="filter_tarih_bit">
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

                    <!-- Sekmeler -->
                    <div class="card card-primary card-outline card-outline-tabs">
                        <div class="card-header p-0 border-bottom-0">
                            <ul class="nav nav-tabs" id="entegrasyonTabs" role="tablist">
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link active" id="tab-entegrasyon" data-bs-toggle="pill" data-bs-target="#pane-entegrasyon" type="button" role="tab">
                                        <i class="bi bi-plug"></i> Entegrasyonlar
                                    </button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="tab-kanal" data-bs-toggle="pill" data-bs-target="#pane-kanal" type="button" role="tab">
                                        <i class="bi bi-diagram-3"></i> Kanallar
                                    </button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="tab-log" data-bs-toggle="pill" data-bs-target="#pane-log" type="button" role="tab">
                                        <i class="bi bi-journal-text"></i> Loglar
                                    </button>
                                </li>
                            </ul>
                        </div>
                        <div class="card-body">
                            <div class="tab-content">
                                <!-- Entegrasyonlar Sekmesi -->
                                <div class="tab-pane fade show active" id="pane-entegrasyon" role="tabpanel">
                                    <?php if ($permissions['can_add']): ?>
                                    <div class="mb-3 text-end">
                                        <button type="button" class="btn btn-primary btn-sm" onclick="resetEntegrasyonForm()" data-bs-toggle="modal" data-bs-target="#entegrasyonModal">
                                            <i class="bi bi-plus-circle"></i> Yeni Entegrasyon Ekle
                                        </button>
                                    </div>
                                    <?php endif; ?>
                                    <table id="entegrasyonTable" class="table table-bordered table-striped table-hover">
                                        <thead>
                                            <tr>
                                                <th>Entegrasyon Adı</th>
                                                <th>Kod</th>
                                                <th>Ayarlar</th>
                                                <th>Kanal</th>
                                                <th>Son Test</th>
                                                <th>Durum</th>
                                                <th>İşlemler</th>
                                            </tr>
                                        </thead>
                                        <tbody></tbody>
                                    </table>
                                </div>

                                <!-- Kanallar Sekmesi -->
                                <div class="tab-pane fade" id="pane-kanal" role="tabpanel">
                                    <?php if ($permissions['can_add']): ?>
                                    <div class="mb-3 text-end">
                                        <button type="button" class="btn btn-primary btn-sm" onclick="resetKanalForm()" data-bs-toggle="modal" data-bs-target="#kanalModal">
                                            <i class="bi bi-plus-circle"></i> Yeni Kanal Ekle
                                        </button>
                                    </div>
                                    <?php endif; ?>
                                    <table id="kanalTable" class="table table-bordered table-striped table-hover">
                                        <thead>
                                            <tr>
                                                <th>Entegrasyon</th>
                                                <th>Kanal Adı</th>
                                                <th>Ayarlar</th>
                                                <th>Varsayılan</th>
                                                <th>Durum</th>
                                                <th>İşlemler</th>
                                            </tr>
                                        </thead>
                                        <tbody></tbody>
                                    </table>
                                </div>

                                <!-- Loglar Sekmesi -->
                                <div class="tab-pane fade" id="pane-log" role="tabpanel">
                                    <table id="logTable" class="table table-bordered table-striped table-hover">
                                        <thead>
                                            <tr>
                                                <th>Tarih</th>
                                                <th>Entegrasyon</th>
                                                <th>Kanal</th>
                                                <th>İşlem</th>
                                                <th>HTTP</th>
                                                <th>Süre</th>
                                                <th>Sonuç</th>
                                                <th>Detay</th>
                                            </tr>
                                        </thead>
                                        <tbody></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>

        <?php include __DIR__ . '/../includes/footer.php'; ?>
    </div>

    <!-- Entegrasyon Modal -->
    <div class="modal fade" id="entegrasyonModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="entegrasyonModalLabel">Yeni Entegrasyon Ekle</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="entegrasyonForm">
                    <div class="modal-body">
                        <input type="hidden" id="entegrasyon_id" name="entegrasyon_id" value="">

                        <div class="row g-3">
                            <div class="col-md-8">
                                <label for="entegrasyon_adi" class="form-label">Entegrasyon Adı <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="entegrasyon_adi" name="entegrasyon_adi" required>
                            </div>
                            <div class="col-md-4">
                                <label for="entegrasyon_kod" class="form-label">Kod <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="entegrasyon_kod" name="entegrasyon_kod" placeholder="whatsapp" required>
                            </div>
                            <div class="col-md-12">
                                <label for="entegrasyon_aciklama" class="form-label">Açıklama</label>
                                <textarea class="form-control" id="entegrasyon_aciklama" name="entegrasyon_aciklama" rows="2"></textarea>
                            </div>
                            <div class="col-md-12">
                                <label for="entegrasyon_ayarlar" class="form-label">
                                    Ayarlar (JSON)
                                    <button type="button" class="btn btn-outline-secondary btn-sm ms-2" onclick="jsonFormatla('entegrasyon_ayarlar')">
                                        <i class="bi bi-braces"></i> Formatla
                                    </button>
                                </label>
                                <textarea class="form-control json-editor" id="entegrasyon_ayarlar" name="entegrasyon_ayarlar" rows="6" placeholder='{"BaseURL": "https://..."}'></textarea>
                            </div>
                            <div class="col-md-12">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="entegrasyon_durum" name="entegrasyon_durum" checked>
                                    <label class="form-check-label" for="entegrasyon_durum">Aktif</label>
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

    <!-- Kanal Modal -->
    <div class="modal fade" id="kanalModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="kanalModalLabel">Yeni Kanal Ekle</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="kanalForm">
                    <div class="modal-body">
                        <input type="hidden" id="kanal_id" name="kanal_id" value="">

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="kanal_entegrasyon_id" class="form-label">Entegrasyon <span class="text-danger">*</span></label>
                                <select class="form-select" id="kanal_entegrasyon_id" name="kanal_entegrasyon_id" required></select>
                            </div>
                            <div class="col-md-6">
                                <label for="kanal_adi" class="form-label">Kanal Adı <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="kanal_adi" name="kanal_adi" required>
                            </div>
                            <div class="col-md-12">
                                <label for="kanal_ayarlar" class="form-label">
                                    Ayarlar (JSON)
                                    <button type="button" class="btn btn-outline-secondary btn-sm ms-2" onclick="jsonFormatla('kanal_ayarlar')">
                                        <i class="bi bi-braces"></i> Formatla
                                    </button>
                                </label>
                                <textarea class="form-control json-editor" id="kanal_ayarlar" name="kanal_ayarlar" rows="6" placeholder='{"ApiKey": "...", "Instance": "..."}'></textarea>
                            </div>
                            <div class="col-md-6">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="kanal_varsayilan" name="kanal_varsayilan">
                                    <label class="form-check-label" for="kanal_varsayilan">Varsayılan Kanal</label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="kanal_durum" name="kanal_durum" checked>
                                    <label class="form-check-label" for="kanal_durum">Aktif</label>
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

    <!-- Test Sonuç Modal -->
    <div class="modal fade" id="testModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Bağlantı Testi Sonuçları</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="testSonucBody">
                    <div class="text-center py-4">
                        <div class="spinner-border text-primary" role="status"></div>
                        <p class="mt-2">Test yapılıyor...</p>
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

    <!-- Log Detay Modal -->
    <div class="modal fade" id="logModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Log Detayı</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3 mb-3">
                        <div class="col-md-3"><strong>Entegrasyon:</strong> <span id="log_detay_entegrasyon"></span></div>
                        <div class="col-md-3"><strong>Kanal:</strong> <span id="log_detay_kanal"></span></div>
                        <div class="col-md-2"><strong>İşlem:</strong> <span id="log_detay_islem"></span></div>
                        <div class="col-md-2"><strong>HTTP:</strong> <span id="log_detay_http"></span></div>
                        <div class="col-md-2"><strong>Süre:</strong> <span id="log_detay_sure"></span></div>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label"><strong>İstek</strong></label>
                            <pre class="test-sonuc-yanit" style="max-height: 400px;" id="log_detay_istek"></pre>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><strong>Yanıt</strong></label>
                            <pre class="test-sonuc-yanit" style="max-height: 400px;" id="log_detay_yanit"></pre>
                        </div>
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
        let entegrasyonModal, kanalModal, testModal, logModal;
        let entegrasyonTable, kanalTable, logTable;
        let currentfilters = {};

        // Sayfa yetkileri (PHP'den)
        const permissions = {
            canAdd: <?= $permissions['can_add'] ? 'true' : 'false' ?>,
            canEdit: <?= $permissions['can_edit'] ? 'true' : 'false' ?>,
            canDelete: <?= $permissions['can_delete'] ? 'true' : 'false' ?>
        };

        function formatDate(datestring) {
            if (!datestring) return '-';

            try {
                if (typeof datestring !== 'string') {
                    datestring = String(datestring);
                }

                const date = new Date(datestring.replace(' ', 'T'));
                if (isNaN(date.getTime())) return '-';

                return date.toLocaleDateString('tr-TR', {
                    year: 'numeric', month: '2-digit', day: '2-digit',
                    hour: '2-digit', minute: '2-digit'
                });
            } catch (e) {
                return '-';
            }
        }

        function escapeHtml(text) {
            if (text === null || text === undefined) return '';
            return String(text)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;')
                .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        // JSON textarea içeriğini formatla
        function jsonFormatla(elementId) {
            const el = document.getElementById(elementId);
            if (!el.value.trim()) return;

            try {
                el.value = JSON.stringify(JSON.parse(el.value), null, 2);
            } catch (e) {
                showToast('Geçersiz JSON: ' + e.message, 'error');
            }
        }

        $(document).ready(function() {
            entegrasyonModal = new bootstrap.Modal(document.getElementById('entegrasyonModal'));
            kanalModal = new bootstrap.Modal(document.getElementById('kanalModal'));
            testModal = new bootstrap.Modal(document.getElementById('testModal'));
            logModal = new bootstrap.Modal(document.getElementById('logModal'));

            const dtOptions = {
                language: { url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/tr.json' },
                pageLength: 25,
                lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "Tümü"]]
            };

            entegrasyonTable = $('#entegrasyonTable').DataTable({
                ...dtOptions,
                order: [[0, 'asc']],
                columnDefs: [{ orderable: false, targets: [2, 6] }]
            });

            kanalTable = $('#kanalTable').DataTable({
                ...dtOptions,
                order: [[0, 'asc']],
                columnDefs: [{ orderable: false, targets: [2, 5] }]
            });

            logTable = $('#logTable').DataTable({
                ...dtOptions,
                order: [[0, 'desc']],
                columnDefs: [{ orderable: false, targets: [7] }]
            });

            // Select2 - filtre dropdown'ları
            $('#filter_entegrasyon, #filter_status').select2({
                theme: 'bootstrap-5',
                width: '100%'
            });

            // Select2 - modal içi dropdown
            $('#kanal_entegrasyon_id').select2({
                theme: 'bootstrap-5',
                width: '100%',
                dropdownParent: $('#kanalModal')
            });

            loadEntegrasyonOptions();
            loadAllTables();
            loadStats();

            $('#entegrasyonForm').on('submit', function(e) {
                e.preventDefault();
                saveEntegrasyon();
            });

            $('#kanalForm').on('submit', function(e) {
                e.preventDefault();
                saveKanal();
            });

            // Filtre form submit
            $('#filterForm').on('submit', function(e) {
                e.preventDefault();

                currentfilters = {
                    search: $('#filter_search').val(),
                    entegrasyon_id: $('#filter_entegrasyon').val(),
                    status: $('#filter_status').val(),
                    tarih_bas: $('#filter_tarih_bas').val(),
                    tarih_bit: $('#filter_tarih_bit').val()
                };

                Object.keys(currentfilters).forEach(key => {
                    if (!currentfilters[key]) delete currentfilters[key];
                });

                loadAllTables();
                showToast('Filtre uygulandı', 'info');
            });

            // Filtreleri temizle
            $('#clearfilters').on('click', function() {
                $('#filterForm')[0].reset();
                $('#filter_entegrasyon, #filter_status').val('').trigger('change.select2');
                currentfilters = {};
                loadAllTables();
                showToast('Filtreler temizlendi', 'info');
            });
        });

        function loadAllTables() {
            loadEntegrasyonList();
            loadKanalList();
            loadLogList();
        }

        function loadStats() {
            $.post('', { action: 'stats' }, function(response) {
                if (response.success) {
                    $('#stat-entegrasyon').text(response.data.entegrasyon);
                    $('#stat-kanal').text(response.data.kanal);
                    $('#stat-bugun-log').text(response.data.bugun_log);
                    $('#stat-bugun-hata').text(response.data.bugun_hata);
                }
            });
        }

        // Entegrasyon dropdown seçeneklerini yükle
        function loadEntegrasyonOptions() {
            $.post('', { action: 'entegrasyon_options' }, function(response) {
                if (response.success) {
                    const filterSelect = $('#filter_entegrasyon');
                    const modalSelect = $('#kanal_entegrasyon_id');

                    filterSelect.find('option:not(:first)').remove();
                    modalSelect.empty();
                    modalSelect.append('<option value="">Seçiniz...</option>');

                    response.data.forEach(e => {
                        filterSelect.append(`<option value="${e.entegrasyon_id}">${escapeHtml(e.entegrasyon_adi)}</option>`);
                        modalSelect.append(`<option value="${e.entegrasyon_id}">${escapeHtml(e.entegrasyon_adi)}</option>`);
                    });
                }
            });
        }

        // ===================== ENTEGRASYONLAR =====================
        function loadEntegrasyonList() {
            $.post('', { action: 'list_entegrasyon', ...currentfilters }, function(data) {
                if (data.success) {
                    renderEntegrasyonTable(data.data);
                } else {
                    showToast('Liste yüklenirken hata oluştu', 'error');
                }
            }, 'json').fail(() => showToast('Sunucu hatası oluştu', 'error'));
        }

        function renderEntegrasyonTable(list) {
            entegrasyonTable.clear();

            list.forEach(e => {
                const durumBadge = e.entegrasyon_durum
                    ? '<span class="status-badge status-active">Aktif</span>'
                    : '<span class="status-badge status-inactive">Pasif</span>';

                let sonTest = '<span class="text-muted">-</span>';
                if (e.son_test_durum !== null) {
                    const testBadge = e.son_test_durum == 1
                        ? '<span class="badge bg-success"><i class="bi bi-check"></i> Başarılı</span>'
                        : '<span class="badge bg-danger"><i class="bi bi-x"></i> Başarısız</span>';
                    sonTest = `${testBadge}<br><small class="text-muted">${formatDate(e.son_test_tarihi)}</small>`;
                }

                let islemler = `
                    <button class="btn btn-sm btn-info" onclick="testEntegrasyon(${e.entegrasyon_id}, 0)" title="Bağlantı Testi">
                        <i class="bi bi-lightning-charge"></i>
                    </button>
                `;
                if (permissions.canEdit) {
                    islemler += `
                        <button class="btn btn-sm btn-warning" onclick="editEntegrasyon(${e.entegrasyon_id})" title="Düzenle">
                            <i class="bi bi-pencil"></i>
                        </button>
                    `;
                }
                if (permissions.canDelete) {
                    islemler += `
                        <button class="btn btn-sm btn-danger" onclick="deleteEntegrasyon(${e.entegrasyon_id})" title="Sil">
                            <i class="bi bi-trash"></i>
                        </button>
                    `;
                }

                entegrasyonTable.row.add([
                    escapeHtml(e.entegrasyon_adi),
                    `<code>${escapeHtml(e.entegrasyon_kod)}</code>`,
                    `<span class="json-preview" title="${escapeHtml(e.entegrasyon_ayarlar)}">${escapeHtml(e.entegrasyon_ayarlar || '-')}</span>`,
                    `<span class="badge bg-secondary">${e.kanal_sayisi}</span>`,
                    sonTest,
                    durumBadge,
                    islemler
                ]);
            });

            entegrasyonTable.draw();
        }

        function resetEntegrasyonForm() {
            document.getElementById('entegrasyonForm').reset();
            document.getElementById('entegrasyon_id').value = '';
            document.getElementById('entegrasyonModalLabel').textContent = 'Yeni Entegrasyon Ekle';
        }

        function saveEntegrasyon() {
            const formData = new FormData(document.getElementById('entegrasyonForm'));
            formData.append('action', 'save_entegrasyon');

            $.ajax({
                url: '', method: 'POST', data: formData,
                processData: false, contentType: false, dataType: 'json',
                success: function(data) {
                    if (data.success) {
                        showToast(data.message, 'success');
                        entegrasyonModal.hide();
                        loadEntegrasyonList();
                        loadEntegrasyonOptions();
                        loadStats();
                    } else {
                        showToast(data.message, 'error');
                    }
                },
                error: () => showToast('Kayıt sırasında hata oluştu', 'error')
            });
        }

        function editEntegrasyon(id) {
            $.post('', { action: 'get_entegrasyon', entegrasyon_id: id }, function(data) {
                if (data.success && data.data) {
                    const e = data.data;

                    document.getElementById('entegrasyon_id').value = e.entegrasyon_id;
                    document.getElementById('entegrasyon_adi').value = e.entegrasyon_adi || '';
                    document.getElementById('entegrasyon_kod').value = e.entegrasyon_kod || '';
                    document.getElementById('entegrasyon_aciklama').value = e.entegrasyon_aciklama || '';
                    document.getElementById('entegrasyon_ayarlar').value = e.entegrasyon_ayarlar || '';
                    document.getElementById('entegrasyon_durum').checked = e.entegrasyon_durum == 1;

                    jsonFormatla('entegrasyon_ayarlar');
                    document.getElementById('entegrasyonModalLabel').textContent = 'Entegrasyon Düzenle';
                    entegrasyonModal.show();
                }
            }, 'json').fail(() => showToast('Kayıt yüklenirken hata oluştu', 'error'));
        }

        function deleteEntegrasyon(id) {
            confirmAction(
                'Bu entegrasyonu silmek istediğinize emin misiniz?',
                'Bu işlem geri alınamaz!',
                function() {
                    $.post('', { action: 'delete_entegrasyon', entegrasyon_id: id }, function(data) {
                        if (data.success) {
                            showSuccess('Silindi!', data.message);
                            loadEntegrasyonList();
                            loadEntegrasyonOptions();
                            loadStats();
                        } else {
                            showError('Hata!', data.message);
                        }
                    }, 'json').fail(() => showError('Bağlantı Hatası!', 'Sunucuya ulaşılamıyor.'));
                }
            );
        }

        // ===================== KANALLAR =====================
        function loadKanalList() {
            $.post('', { action: 'list_kanal', ...currentfilters }, function(data) {
                if (data.success) {
                    renderKanalTable(data.data);
                } else {
                    showToast('Kanal listesi yüklenirken hata oluştu', 'error');
                }
            }, 'json').fail(() => showToast('Sunucu hatası oluştu', 'error'));
        }

        function renderKanalTable(list) {
            kanalTable.clear();

            list.forEach(k => {
                const durumBadge = k.kanal_durum
                    ? '<span class="status-badge status-active">Aktif</span>'
                    : '<span class="status-badge status-inactive">Pasif</span>';

                const varsayilanBadge = k.kanal_varsayilan
                    ? '<span class="badge bg-primary"><i class="bi bi-star-fill"></i> Varsayılan</span>'
                    : '<span class="text-muted">-</span>';

                let islemler = `
                    <button class="btn btn-sm btn-info" onclick="testEntegrasyon(${k.kanal_entegrasyon_id}, ${k.kanal_id})" title="Bağlantı Testi">
                        <i class="bi bi-lightning-charge"></i>
                    </button>
                `;
                if (permissions.canEdit) {
                    islemler += `
                        <button class="btn btn-sm btn-warning" onclick="editKanal(${k.kanal_id})" title="Düzenle">
                            <i class="bi bi-pencil"></i>
                        </button>
                    `;
                }
                if (permissions.canDelete) {
                    islemler += `
                        <button class="btn btn-sm btn-danger" onclick="deleteKanal(${k.kanal_id})" title="Sil">
                            <i class="bi bi-trash"></i>
                        </button>
                    `;
                }

                kanalTable.row.add([
                    escapeHtml(k.entegrasyon_adi),
                    escapeHtml(k.kanal_adi),
                    `<span class="json-preview" title="${escapeHtml(k.kanal_ayarlar)}">${escapeHtml(k.kanal_ayarlar || '-')}</span>`,
                    varsayilanBadge,
                    durumBadge,
                    islemler
                ]);
            });

            kanalTable.draw();
        }

        function resetKanalForm() {
            document.getElementById('kanalForm').reset();
            document.getElementById('kanal_id').value = '';
            $('#kanal_entegrasyon_id').val('').trigger('change.select2');
            document.getElementById('kanalModalLabel').textContent = 'Yeni Kanal Ekle';
        }

        function saveKanal() {
            const formData = new FormData(document.getElementById('kanalForm'));
            formData.append('action', 'save_kanal');

            $.ajax({
                url: '', method: 'POST', data: formData,
                processData: false, contentType: false, dataType: 'json',
                success: function(data) {
                    if (data.success) {
                        showToast(data.message, 'success');
                        kanalModal.hide();
                        loadKanalList();
                        loadStats();
                    } else {
                        showToast(data.message, 'error');
                    }
                },
                error: () => showToast('Kayıt sırasında hata oluştu', 'error')
            });
        }

        function editKanal(id) {
            $.post('', { action: 'get_kanal', kanal_id: id }, function(data) {
                if (data.success && data.data) {
                    const k = data.data;

                    document.getElementById('kanal_id').value = k.kanal_id;
                    $('#kanal_entegrasyon_id').val(k.kanal_entegrasyon_id).trigger('change.select2');
                    document.getElementById('kanal_adi').value = k.kanal_adi || '';
                    document.getElementById('kanal_ayarlar').value = k.kanal_ayarlar || '';
                    document.getElementById('kanal_varsayilan').checked = k.kanal_varsayilan == 1;
                    document.getElementById('kanal_durum').checked = k.kanal_durum == 1;

                    jsonFormatla('kanal_ayarlar');
                    document.getElementById('kanalModalLabel').textContent = 'Kanal Düzenle';
                    kanalModal.show();
                }
            }, 'json').fail(() => showToast('Kayıt yüklenirken hata oluştu', 'error'));
        }

        function deleteKanal(id) {
            confirmAction(
                'Bu kanalı silmek istediğinize emin misiniz?',
                'Bu işlem geri alınamaz!',
                function() {
                    $.post('', { action: 'delete_kanal', kanal_id: id }, function(data) {
                        if (data.success) {
                            showSuccess('Silindi!', data.message);
                            loadKanalList();
                            loadStats();
                        } else {
                            showError('Hata!', data.message);
                        }
                    }, 'json').fail(() => showError('Bağlantı Hatası!', 'Sunucuya ulaşılamıyor.'));
                }
            );
        }

        // ===================== BAĞLANTI TESTİ =====================
        function testEntegrasyon(entegrasyonId, kanalId) {
            document.getElementById('testSonucBody').innerHTML = `
                <div class="text-center py-4">
                    <div class="spinner-border text-primary" role="status"></div>
                    <p class="mt-2">Test yapılıyor, lütfen bekleyin...</p>
                </div>
            `;
            testModal.show();

            $.post('', { action: 'test', entegrasyon_id: entegrasyonId, kanal_id: kanalId }, function(data) {
                let html = '';

                const genelBadge = data.success
                    ? '<span class="badge bg-success fs-6"><i class="bi bi-check-circle"></i> Başarılı</span>'
                    : '<span class="badge bg-danger fs-6"><i class="bi bi-x-circle"></i> Başarısız</span>';

                html += `<div class="mb-3">${genelBadge} <span class="ms-2">${escapeHtml(data.message)}</span></div>`;

                if (data.data && data.data.length) {
                    data.data.forEach(s => {
                        const satirBadge = s.basarili
                            ? '<span class="badge bg-success">HTTP ' + s.http_kod + '</span>'
                            : '<span class="badge bg-danger">HTTP ' + s.http_kod + '</span>';

                        html += `
                            <div class="card mb-2">
                                <div class="card-header py-2">
                                    <strong>${escapeHtml(s.ad)}</strong>
                                    <span class="float-end">${satirBadge} <span class="badge bg-secondary">${s.sure_ms} ms</span></span>
                                </div>
                                <div class="card-body py-2">
                                    <small class="text-muted d-block mb-1">${escapeHtml(s.url)}</small>
                                    <div class="test-sonuc-yanit">${escapeHtml(s.yanit)}</div>
                                </div>
                            </div>
                        `;
                    });
                }

                document.getElementById('testSonucBody').innerHTML = html;
                loadEntegrasyonList();
                loadLogList();
                loadStats();
            }, 'json').fail(function() {
                document.getElementById('testSonucBody').innerHTML = `
                    <div class="alert alert-danger">
                        <i class="bi bi-exclamation-triangle"></i> Test sırasında sunucu hatası oluştu.
                    </div>
                `;
            });
        }

        // ===================== LOGLAR =====================
        function loadLogList() {
            $.post('', { action: 'list_log', ...currentfilters }, function(data) {
                if (data.success) {
                    renderLogTable(data.data);
                } else {
                    showToast('Log listesi yüklenirken hata oluştu', 'error');
                }
            }, 'json').fail(() => showToast('Sunucu hatası oluştu', 'error'));
        }

        function renderLogTable(list) {
            logTable.clear();

            list.forEach(l => {
                const sonucBadge = l.log_basarili == 1
                    ? '<span class="badge bg-success"><i class="bi bi-check"></i></span>'
                    : '<span class="badge bg-danger"><i class="bi bi-x"></i></span>';

                const httpBadge = l.log_http_kod
                    ? `<span class="badge ${l.log_http_kod >= 200 && l.log_http_kod < 400 ? 'bg-success' : 'bg-danger'}">${l.log_http_kod}</span>`
                    : '<span class="text-muted">-</span>';

                logTable.row.add([
                    formatDate(l.log_tarih),
                    escapeHtml(l.entegrasyon_adi),
                    escapeHtml(l.kanal_adi || '-'),
                    `<code>${escapeHtml(l.log_islem)}</code>`,
                    httpBadge,
                    l.log_sure_ms !== null ? l.log_sure_ms + ' ms' : '-',
                    sonucBadge,
                    `<button class="btn btn-sm btn-secondary" onclick="showLogDetay(${l.log_id})" title="Detay">
                        <i class="bi bi-eye"></i>
                    </button>`
                ]);
            });

            logTable.draw();
        }

        function showLogDetay(id) {
            $.post('', { action: 'get_log', log_id: id }, function(data) {
                if (data.success && data.data) {
                    const l = data.data;

                    $('#log_detay_entegrasyon').text(l.entegrasyon_adi || '-');
                    $('#log_detay_kanal').text(l.kanal_adi || '-');
                    $('#log_detay_islem').text(l.log_islem || '-');
                    $('#log_detay_http').text(l.log_http_kod || '-');
                    $('#log_detay_sure').text(l.log_sure_ms !== null ? l.log_sure_ms + ' ms' : '-');

                    // JSON ise formatlı göster
                    const formatJson = (str) => {
                        if (!str) return '-';
                        try {
                            return JSON.stringify(JSON.parse(str), null, 2);
                        } catch (e) {
                            return str;
                        }
                    };

                    $('#log_detay_istek').text(formatJson(l.log_istek));
                    $('#log_detay_yanit').text(formatJson(l.log_yanit));

                    logModal.show();
                }
            }, 'json').fail(() => showToast('Log detayı yüklenirken hata oluştu', 'error'));
        }
    </script>
</body>
</html>
