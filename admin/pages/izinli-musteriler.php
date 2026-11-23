<?php
/**
 * Admin Panel - İzinli Müşteriler
 *
 * Genel formlardan (form/{uniqid}) gelen başvuruları ve verilen izinleri
 * (Aydınlatma, Açık Rıza, KVKK, ETK, GPS) listeler. Kaynak: Form_Basvurulari.
 * Hareket tablosu olduğu için liste server-side ve iki aşamalı sorguyla kurulur.
 *
 * İptal (API veya panel): onay kolonları DEĞİŞTİRİLMEZ (kanıt onlardan üretilir). Kayıt dogrulama_durumu = 'iptal'
 * ve Form_Basvuru_Iptalleri satırıyla işaretlenir; ekranda açık rıza / KVKK / ETK geri alınmış (0) gösterilir.
 * Kaynak: API (form_basvuru_api_anahtar_id dolu) · Excel (durum 'excel') · Form (diğerleri).
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
require_once __DIR__ . '/../../includes/OnayKanit.php';
require_once __DIR__ . '/../../includes/Webhook.php';
requireAuth();

$user = Auth::user();
$db = Database::getInstance();

$currentPagefile = basename($_SERVER['PHP_SELF']);
$pageinfo = $db->fetchOne("
    SELECT s.sayfalar_sayfa_adi, s.sayfalar_aciklama, m.menuler_menu_adi AS menu_adi
    FROM Menu_Sayfalar s
    LEFT JOIN Menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1
", ['%' . preg_replace('/\.php$/', '', $currentPagefile) . '%']);

$pageTitle = $pageinfo['sayfalar_sayfa_adi'] ?? 'İzinli Müşteriler';
$menuAdi = $pageinfo['menu_adi'] ?? null;

$permissions = PageAuth::checkPagePermissions($user['kullanici_id'], $user['departman_id'], $currentPagefile);
if (!$permissions['has_access']) {
    PageAuth::accessDenied($permissions['error'] ?? 'Bu sayfaya erişim yetkiniz yok!');
}

// Kullanıcının görebileceği firmalar (null = sınırsız)
$firmaScopeIds = PageAuth::firmaScopeIds($permissions, $user['firma_id'] ?? null);
$tekFirma = ($firmaScopeIds !== null && count($firmaScopeIds) <= 1);

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Portal';

$tarihGecerli = function ($tarih) {
    $d = DateTime::createFromFormat('Y-m-d', (string)$tarih);
    return $d && $d->format('Y-m-d') === $tarih;
};

/** Kaydın aktif iptali var mı (b alias'ı üzerinden) */
const IPTAL_VAR = 'EXISTS (SELECT 1 FROM dbo.Form_Basvuru_Iptalleri ix WHERE ix.iptal_form_basvuru_id = b.form_basvuru_id AND ix.Durum = 1)';
/** Excel ile yüklenen kayıtların durum id'leri */
const EXCEL_DURUM = "(SELECT form_dogrulama_durum_id FROM dbo.tanim_FormDogrulamaDurumlari WHERE form_dogrulama_durum_kod = 'excel')";

/** Kaynak ifadesi (b + d alias'ları) */
const KAYNAK_SQL = "CASE WHEN b.form_basvuru_api_anahtar_id IS NOT NULL THEN 'api' WHEN d.form_dogrulama_durum_kod = 'excel' THEN 'excel' ELSE 'form' END";

/** İptalde geri alınan izinler için ekranda gösterilecek değer (i = Form_Basvuru_Iptalleri LEFT JOIN) */
$etkinIzin = fn($kolon) => "CASE WHEN i.iptal_id IS NOT NULL AND $kolon IS NOT NULL THEN 0 ELSE $kolon END";

/**
 * Liste / özet / Excel filtreleri — yalnız Form_Basvurulari (b) üzerinden, JOIN'siz
 */
$filtreOlustur = function (array $girdi) use ($firmaScopeIds, $tarihGecerli) {
    [$scopeSql, $params] = PageAuth::firmaScopeWhere($firmaScopeIds, 'b.firma_id');
    $kosullar = [$scopeSql];

    foreach (['firma_id' => 'b.firma_id', 'form_id' => 'b.form_id', 'dogrulama_id' => 'b.form_basvuru_dogrulama_durumu'] as $alan => $kolon) {
        if (!empty($girdi[$alan])) {
            $kosullar[] = "$kolon = ?";
            $params[] = (int)$girdi[$alan];
        }
    }

    // İzin filtreleri (1 = verildi, 0 = verilmedi). İptalde geri alınan izinler (KVKK, ETK) verilmedi sayılır.
    foreach (['etk' => ['b.form_basvuru_etk_onay', true], 'gps' => ['b.form_basvuru_gps_onay', false], 'kvkk' => ['b.form_basvuru_kvkk_onay', true]] as $alan => [$kolon, $iptaldeGeriAlinir]) {
        if (isset($girdi[$alan]) && in_array((string)$girdi[$alan], ['0', '1'], true)) {
            if (!$iptaldeGeriAlinir) {
                $kosullar[] = "$kolon = ?";
                $params[] = (int)$girdi[$alan];
            } elseif ($girdi[$alan] === '1') {
                $kosullar[] = "$kolon = 1 AND NOT " . IPTAL_VAR;
            } else {
                $kosullar[] = "($kolon = 0 OR ($kolon IS NOT NULL AND " . IPTAL_VAR . '))';
            }
        }
    }

    switch ($girdi['kaynak'] ?? '') {
        case 'api':
            $kosullar[] = 'b.form_basvuru_api_anahtar_id IS NOT NULL';
            break;
        case 'excel':
            $kosullar[] = 'b.form_basvuru_api_anahtar_id IS NULL AND b.form_basvuru_dogrulama_durumu IN ' . EXCEL_DURUM;
            break;
        case 'form':
            $kosullar[] = 'b.form_basvuru_api_anahtar_id IS NULL AND (b.form_basvuru_dogrulama_durumu IS NULL OR b.form_basvuru_dogrulama_durumu NOT IN ' . EXCEL_DURUM . ')';
            break;
    }

    if (!empty($girdi['tarih_bas']) && $tarihGecerli($girdi['tarih_bas'])) {
        $kosullar[] = 'b.form_basvuru_tarihi >= ?';
        $params[] = $girdi['tarih_bas'];
    }
    if (!empty($girdi['tarih_bit']) && $tarihGecerli($girdi['tarih_bit'])) {
        $kosullar[] = 'b.form_basvuru_tarihi < DATEADD(DAY, 1, CAST(? AS DATE))';
        $params[] = $girdi['tarih_bit'];
    }

    $durum = $girdi['status'] ?? '1';
    if ($durum === '0' || $durum === '1') {
        $kosullar[] = 'b.form_basvuru_durum = ?';
        $params[] = (int)$durum;
    }

    $arama = trim($girdi['search_text'] ?? '');
    if ($arama !== '') {
        $like = '%' . $arama . '%';
        $telefon = preg_replace('/[^0-9]/', '', $arama);
        $kosul = '(b.form_basvuru_ad LIKE ? OR b.form_basvuru_soyad LIKE ? OR b.form_basvuru_eposta LIKE ?'
               . " OR (ISNULL(b.form_basvuru_ad, '') + ' ' + ISNULL(b.form_basvuru_soyad, '')) LIKE ?";
        array_push($params, $like, $like, $like, $like);
        if (strlen($telefon) >= 3) {
            $kosul .= ' OR b.form_basvuru_telefon LIKE ?';
            $params[] = '%' . ltrim($telefon, '0') . '%';
        }
        $kosullar[] = $kosul . ')';
    }

    return [implode(' AND ', $kosullar), $params];
};

// Excel çıktısı (JSON başlığından önce)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'excel') {
    [$filtreSql, $filtreParams] = $filtreOlustur($_POST);
    $satirlar = $db->fetchAll("
        SELECT b.form_basvuru_id, fi.firma_adi, f.form_adi,
               b.form_basvuru_ad, b.form_basvuru_soyad, b.form_basvuru_telefon, b.form_basvuru_eposta,
               b.form_basvuru_aydinlatma_onay,
               " . $etkinIzin('b.form_basvuru_acik_riza_onay') . " AS form_basvuru_acik_riza_onay,
               " . $etkinIzin('b.form_basvuru_kvkk_onay') . " AS form_basvuru_kvkk_onay,
               " . $etkinIzin('b.form_basvuru_etk_onay') . " AS form_basvuru_etk_onay,
               b.form_basvuru_gps_onay,
               d.form_dogrulama_durum_adi, " . KAYNAK_SQL . " AS kaynak,
               CONVERT(VARCHAR(19), b.form_basvuru_sms_dogrulama_tarihi, 120) AS sms_tarih,
               b.form_basvuru_ip_adresi,
               CONVERT(VARCHAR(19), b.form_basvuru_tarihi, 120) AS tarih,
               CONVERT(VARCHAR(19), i.OlusturmaTarihi, 120) AS iptal_tarih, i.iptal_neden
        FROM dbo.Form_Basvurulari b
        LEFT JOIN dbo.Formlar f ON f.form_id = b.form_id
        LEFT JOIN dbo.Firmalar fi ON fi.firma_id = b.firma_id
        LEFT JOIN dbo.tanim_FormDogrulamaDurumlari d ON d.form_dogrulama_durum_id = b.form_basvuru_dogrulama_durumu
        LEFT JOIN dbo.Form_Basvuru_Iptalleri i ON i.iptal_form_basvuru_id = b.form_basvuru_id AND i.Durum = 1
        WHERE $filtreSql
        ORDER BY b.form_basvuru_tarihi DESC, b.form_basvuru_id DESC
    ", $filtreParams) ?: [];

    $h = function ($v) {
        $v = (string)$v;
        if ($v !== '' && strpbrk($v[0], '=+-@') !== false) {
            $v = "'" . $v; // formül enjeksiyonunu engelle
        }
        return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    };
    $evet = fn($v) => $v === null ? '-' : ((int)$v === 1 ? 'Evet' : 'Hayır');
    $kaynakAdi = ['api' => 'API', 'excel' => 'Excel', 'form' => 'Form'];

    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="izinli_musteriler_' . date('Y-m-d_H-i-s') . '.xls"');
    header('Pragma: no-cache');
    header('Expires: 0');
    echo "\xEF\xBB\xBF";
    echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta http-equiv="content-type" content="application/vnd.ms-excel; charset=UTF-8">';
    echo '<style>td{mso-number-format:"\@";}</style></head><body><table border="1">';
    echo '<tr style="background-color:#0d6efd;color:#fff;font-weight:bold;">';
    foreach (['ID', 'Firma', 'Form', 'Ad', 'Soyad', 'Cep Telefonu', 'E-posta', 'Aydınlatma', 'Açık Rıza', 'KVKK', 'ETK', 'GPS', 'Doğrulama', 'Kaynak', 'SMS Doğrulama Tarihi', 'IP Adresi', 'Başvuru Tarihi', 'İptal Tarihi', 'İptal Nedeni'] as $b) {
        echo '<th>' . $b . '</th>';
    }
    echo '</tr>';
    foreach ($satirlar as $r) {
        echo '<tr>'
            . '<td>' . (int)$r['form_basvuru_id'] . '</td>'
            . '<td>' . $h($r['firma_adi']) . '</td>'
            . '<td>' . $h($r['form_adi']) . '</td>'
            . '<td>' . $h($r['form_basvuru_ad']) . '</td>'
            . '<td>' . $h($r['form_basvuru_soyad']) . '</td>'
            . '<td>' . $h($r['form_basvuru_telefon']) . '</td>'
            . '<td>' . $h($r['form_basvuru_eposta']) . '</td>'
            . '<td>' . $evet($r['form_basvuru_aydinlatma_onay']) . '</td>'
            . '<td>' . $evet($r['form_basvuru_acik_riza_onay']) . '</td>'
            . '<td>' . $evet($r['form_basvuru_kvkk_onay']) . '</td>'
            . '<td>' . $evet($r['form_basvuru_etk_onay']) . '</td>'
            . '<td>' . $evet($r['form_basvuru_gps_onay']) . '</td>'
            . '<td>' . $h($r['form_dogrulama_durum_adi']) . '</td>'
            . '<td>' . $h($kaynakAdi[$r['kaynak']] ?? $r['kaynak']) . '</td>'
            . '<td>' . $h($r['sms_tarih']) . '</td>'
            . '<td>' . $h($r['form_basvuru_ip_adresi']) . '</td>'
            . '<td>' . $h($r['tarih']) . '</td>'
            . '<td>' . $h($r['iptal_tarih']) . '</td>'
            . '<td>' . $h($r['iptal_neden']) . '</td>'
            . '</tr>';
    }
    echo '</table></body></html>';
    exit;
}

// AJAX işlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
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
                    0 => 'f.form_adi',
                    1 => 'b.form_basvuru_etk_onay',
                    2 => 'b.form_basvuru_gps_onay',
                    3 => 'b.form_basvuru_kvkk_onay',
                    4 => 'b.form_basvuru_tarihi',
                    5 => 'b.form_basvuru_ad',
                    6 => 'b.form_basvuru_soyad',
                    7 => 'b.form_basvuru_telefon',
                    8 => 'fi.firma_adi',
                    9 => 'b.form_basvuru_dogrulama_durumu',
                ];
                $kolonIndex = (int)($_POST['order'][0]['column'] ?? 4);
                $siralaKolon = $siralamaKolonlari[$kolonIndex] ?? 'b.form_basvuru_tarihi';
                $siralaYon = strtolower($_POST['order'][0]['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';
                $siralaJoin = '';
                if ($siralaKolon === 'f.form_adi') {
                    $siralaJoin = 'INNER JOIN dbo.Formlar f ON f.form_id = b.form_id';
                } elseif ($siralaKolon === 'fi.firma_adi') {
                    $siralaJoin = 'INNER JOIN dbo.Firmalar fi ON fi.firma_id = b.firma_id';
                }

                [$scopeSql, $scopeParams] = PageAuth::firmaScopeWhere($firmaScopeIds, 'b.firma_id');
                [$filtreSql, $filtreParams] = $filtreOlustur($_POST);

                $recordsTotal = (int)($db->fetchOne(
                    "SELECT COUNT(*) AS sayi FROM dbo.Form_Basvurulari b WHERE $scopeSql",
                    $scopeParams
                )['sayi'] ?? 0);

                $recordsFiltered = (int)($db->fetchOne(
                    "SELECT COUNT(*) AS sayi FROM dbo.Form_Basvurulari b WHERE $filtreSql",
                    $filtreParams
                )['sayi'] ?? 0);

                // 1. aşama: yalnız sayfanın ID'leri  2. aşama: detaylar bu N satıra
                $satirlar = $db->fetchAll("
                    WITH Sayfa AS (
                        SELECT b.form_basvuru_id,
                               ROW_NUMBER() OVER (ORDER BY $siralaKolon $siralaYon, b.form_basvuru_id DESC) AS sira
                        FROM dbo.Form_Basvurulari b
                        $siralaJoin
                        WHERE $filtreSql
                        ORDER BY $siralaKolon $siralaYon, b.form_basvuru_id DESC
                        OFFSET ? ROWS FETCH NEXT ? ROWS ONLY
                    )
                    SELECT
                        b.form_basvuru_id,
                        b.form_basvuru_ad AS ad,
                        b.form_basvuru_soyad AS soyad,
                        b.form_basvuru_telefon AS telefon,
                        " . $etkinIzin('b.form_basvuru_etk_onay') . " AS etk,
                        b.form_basvuru_gps_onay AS gps,
                        " . $etkinIzin('b.form_basvuru_kvkk_onay') . " AS kvkk,
                        b.form_basvuru_durum AS durum,
                        CONVERT(VARCHAR(19), b.form_basvuru_tarihi, 120) AS tarih,
                        f.form_adi, f.form_uniqid,
                        fi.firma_adi,
                        d.form_dogrulama_durum_adi AS dogrulama_adi,
                        d.form_dogrulama_durum_renk AS dogrulama_renk,
                        d.form_dogrulama_durum_gecerli AS gecerli,
                        " . KAYNAK_SQL . " AS kaynak,
                        CASE WHEN i.iptal_id IS NULL THEN 0 ELSE 1 END AS iptal
                    FROM Sayfa p
                    INNER JOIN dbo.Form_Basvurulari b ON b.form_basvuru_id = p.form_basvuru_id
                    LEFT JOIN dbo.Formlar f ON f.form_id = b.form_id
                    LEFT JOIN dbo.Firmalar fi ON fi.firma_id = b.firma_id
                    LEFT JOIN dbo.tanim_FormDogrulamaDurumlari d ON d.form_dogrulama_durum_id = b.form_basvuru_dogrulama_durumu
                    LEFT JOIN dbo.Form_Basvuru_Iptalleri i ON i.iptal_form_basvuru_id = b.form_basvuru_id AND i.Durum = 1
                    ORDER BY p.sira
                ", array_merge($filtreParams, [$start, $length]));

                echo json_encode([
                    'draw'            => $draw,
                    'recordsTotal'    => $recordsTotal,
                    'recordsFiltered' => $recordsFiltered,
                    'data'            => $satirlar ?: [],
                ], JSON_UNESCAPED_UNICODE);
                break;

            case 'stats':
                // Filtrelerle birlikte çalışır. İzin sayaçları yalnız güncel kayıtlar (onaylandı + Excel):
                // yenilenen (aynı kişinin eski kaydı), iptal edilen ve doğrulama bekleyen kayıtlar sayılmaz.
                [$filtreSql, $filtreParams] = $filtreOlustur($_POST);
                // SUM içinde alt sorgu kullanılamadığı için durum tanımı (küçük tanım tablosu) JOIN ile alınır
                $guncel = "(d.form_dogrulama_durum_gecerli = 1 OR d.form_dogrulama_durum_kod = 'excel')";
                $ozet = $db->fetchOne("
                    SELECT
                        COUNT(*) AS toplam,
                        SUM(CASE WHEN b.form_basvuru_tarihi >= DATEFROMPARTS(YEAR(GETDATE()), MONTH(GETDATE()), 1) THEN 1 ELSE 0 END) AS bu_ay,
                        SUM(CASE WHEN d.form_dogrulama_durum_gecerli = 1 THEN 1 ELSE 0 END) AS gecerli,
                        SUM(CASE WHEN $guncel AND b.form_basvuru_kvkk_onay = 1 THEN 1 ELSE 0 END) AS kvkk,
                        SUM(CASE WHEN $guncel AND b.form_basvuru_etk_onay = 1 THEN 1 ELSE 0 END) AS etk,
                        SUM(CASE WHEN $guncel AND b.form_basvuru_gps_onay = 1 THEN 1 ELSE 0 END) AS gps
                    FROM dbo.Form_Basvurulari b
                    LEFT JOIN dbo.tanim_FormDogrulamaDurumlari d ON d.form_dogrulama_durum_id = b.form_basvuru_dogrulama_durumu
                    WHERE $filtreSql
                ", $filtreParams);
                echo json_encode(['success' => true, 'data' => array_map('intval', $ozet ?: [])], JSON_UNESCAPED_UNICODE);
                break;

            case 'firma_list':
                [$scopeSql, $scopeParams] = PageAuth::firmaScopeWhere($firmaScopeIds, 'firma_id');
                $data = $db->fetchAll("
                    SELECT firma_id, firma_adi FROM dbo.Firmalar
                    WHERE firma_durum = 1 AND $scopeSql
                    ORDER BY firma_adi
                ", $scopeParams);
                echo json_encode(['success' => true, 'data' => $data ?: []], JSON_UNESCAPED_UNICODE);
                break;

            case 'form_list':
                [$scopeSql, $scopeParams] = PageAuth::firmaScopeWhere($firmaScopeIds, 'f.firma_id');
                $firmaId = (int)($_POST['firma_id'] ?? 0);
                if ($firmaId > 0) {
                    $scopeSql .= ' AND f.firma_id = ?';
                    $scopeParams[] = $firmaId;
                }
                $data = $db->fetchAll("
                    SELECT f.form_id, f.form_adi, fi.firma_adi
                    FROM dbo.Formlar f
                    LEFT JOIN dbo.Firmalar fi ON fi.firma_id = f.firma_id
                    WHERE $scopeSql
                    ORDER BY fi.firma_adi, f.form_adi
                ", $scopeParams);
                echo json_encode(['success' => true, 'data' => $data ?: []], JSON_UNESCAPED_UNICODE);
                break;

            case 'dogrulama_list':
                $data = $db->fetchAll("
                    SELECT form_dogrulama_durum_id, form_dogrulama_durum_adi
                    FROM dbo.tanim_FormDogrulamaDurumlari
                    WHERE form_dogrulama_durum_durum = 1
                    ORDER BY form_dogrulama_durum_id
                ");
                echo json_encode(['success' => true, 'data' => $data ?: []], JSON_UNESCAPED_UNICODE);
                break;

            case 'get':
                $id = (int)($_POST['form_basvuru_id'] ?? 0);
                [$scopeSql, $scopeParams] = PageAuth::firmaScopeWhere($firmaScopeIds, 'b.firma_id');
                $kayit = $db->fetchOne("
                    SELECT b.form_basvuru_id, b.form_id,
                           b.form_basvuru_ad AS ad, b.form_basvuru_soyad AS soyad,
                           b.form_basvuru_telefon AS telefon, b.form_basvuru_eposta AS eposta,
                           b.form_basvuru_aydinlatma_onay AS aydinlatma, b.form_basvuru_acik_riza_onay AS acik_riza,
                           b.form_basvuru_kvkk_onay AS kvkk, b.form_basvuru_etk_onay AS etk, b.form_basvuru_gps_onay AS gps,
                           b.form_basvuru_ip_adresi AS ip, b.form_basvuru_durum AS durum,
                           CONVERT(VARCHAR(19), b.form_basvuru_tarihi, 120) AS tarih,
                           CONVERT(VARCHAR(19), b.form_basvuru_sms_dogrulama_tarihi, 120) AS sms_tarih,
                           f.form_adi, fi.firma_adi,
                           d.form_dogrulama_durum_adi AS dogrulama_adi, d.form_dogrulama_durum_renk AS dogrulama_renk,
                           d.form_dogrulama_durum_gecerli AS gecerli, " . KAYNAK_SQL . " AS kaynak,
                           i.iptal_id, i.iptal_neden, i.iptal_ip, i.iptal_musteri_ip,
                           CONVERT(VARCHAR(19), i.OlusturmaTarihi, 120) AS iptal_tarih,
                           a.api_anahtar_on_ek AS iptal_anahtar, a.api_anahtar_adi AS iptal_anahtar_adi,
                           LTRIM(RTRIM(ISNULL(k.kullanici_ad, '') + ' ' + ISNULL(k.kullanici_soyad, ''))) AS iptal_kullanici
                    FROM dbo.Form_Basvurulari b
                    LEFT JOIN dbo.Formlar f ON f.form_id = b.form_id
                    LEFT JOIN dbo.Firmalar fi ON fi.firma_id = b.firma_id
                    LEFT JOIN dbo.tanim_FormDogrulamaDurumlari d ON d.form_dogrulama_durum_id = b.form_basvuru_dogrulama_durumu
                    LEFT JOIN dbo.Form_Basvuru_Iptalleri i ON i.iptal_form_basvuru_id = b.form_basvuru_id AND i.Durum = 1
                    LEFT JOIN dbo.Api_Anahtarlari a ON a.api_anahtar_id = i.iptal_api_anahtar_id
                    LEFT JOIN dbo.Kullanicilar k ON k.kullanici_id = i.OlusturanKullanici
                    WHERE b.form_basvuru_id = ? AND $scopeSql
                ", array_merge([$id], $scopeParams));

                if (!$kayit) {
                    echo json_encode(['success' => false, 'message' => 'Kayıt bulunamadı'], JSON_UNESCAPED_UNICODE);
                    break;
                }

                // İptalde geri alınan izinler: ekranda 0, ilk verilen hali 'verilen' altında
                $kayit['verilen'] = null;
                if ($kayit['iptal_id'] !== null) {
                    $kayit['verilen'] = ['acik_riza' => $kayit['acik_riza'], 'kvkk' => $kayit['kvkk'], 'etk' => $kayit['etk']];
                    foreach (['acik_riza', 'kvkk', 'etk'] as $k) {
                        if ($kayit[$k] !== null) {
                            $kayit[$k] = 0;
                        }
                    }
                }

                $kayit['alanlar'] = $db->fetchAll("
                    SELECT COALESCE(NULLIF(LTRIM(RTRIM(fa.form_alani_etiket)), ''), t.form_alan_adi) AS etiket,
                           v.form_basvuru_deger AS deger
                    FROM dbo.Form_Basvuru_Degerleri v
                    INNER JOIN dbo.tanim_FormAlanlari t ON t.form_alan_id = v.form_alan_id
                    LEFT JOIN dbo.Form_Alanlari fa ON fa.form_id = ? AND fa.form_alan_id = v.form_alan_id
                    WHERE v.form_basvuru_id = ?
                    ORDER BY ISNULL(fa.form_alani_sira_no, 9999), t.form_alan_sira_no
                ", [(int)$kayit['form_id'], $id]) ?: [];

                // Kanıt durumu ve onaylanan metin sürümleri
                $kayit['kanit'] = OnayKanit::dogrula($db, $id);
                $kayit['onay_metinleri'] = $db->fetchAll("
                    SELECT m.form_onay_metin_id, m.form_onay_metin_baslik AS baslik, m.form_onay_metin_hash AS hash,
                           o.form_basvuru_onay_kabul AS kabul
                    FROM dbo.Form_Basvuru_Onaylari o
                    INNER JOIN dbo.Form_Onay_Metinleri m ON m.form_onay_metin_id = o.form_onay_metin_id
                    WHERE o.form_basvuru_id = ?
                    ORDER BY o.form_basvuru_onay_id
                ", [$id]) ?: [];

                // Aynı telefonun önceki başvuruları
                $kayit['gecmis'] = [];
                if (!empty($kayit['telefon'])) {
                    $kayit['gecmis'] = $db->fetchAll("
                        SELECT TOP 20 b.form_basvuru_id, CONVERT(VARCHAR(19), b.form_basvuru_tarihi, 120) AS tarih,
                               f.form_adi, d.form_dogrulama_durum_adi AS dogrulama_adi, d.form_dogrulama_durum_renk AS dogrulama_renk
                        FROM dbo.Form_Basvurulari b
                        LEFT JOIN dbo.Formlar f ON f.form_id = b.form_id
                        LEFT JOIN dbo.tanim_FormDogrulamaDurumlari d ON d.form_dogrulama_durum_id = b.form_basvuru_dogrulama_durumu
                        WHERE b.form_basvuru_telefon = ? AND b.form_basvuru_id <> ? AND $scopeSql
                        ORDER BY b.form_basvuru_tarihi DESC
                    ", array_merge([$kayit['telefon'], $id], $scopeParams)) ?: [];
                }

                echo json_encode(['success' => true, 'data' => $kayit], JSON_UNESCAPED_UNICODE);
                break;

            case 'metin':
                // Başvurunun onayladığı metnin kayıt anındaki hali (yalnız kapsamdaki başvurular)
                [$scopeSql, $scopeParams] = PageAuth::firmaScopeWhere($firmaScopeIds, 'b.firma_id');
                $metin = $db->fetchOne("
                    SELECT m.form_onay_metin_baslik AS baslik, m.form_onay_metin_icerik AS icerik, m.form_onay_metin_hash AS hash,
                           CONVERT(VARCHAR(19), m.form_onay_metin_olusturma_tarihi, 120) AS ilk_kullanim
                    FROM dbo.Form_Basvuru_Onaylari o
                    INNER JOIN dbo.Form_Onay_Metinleri m ON m.form_onay_metin_id = o.form_onay_metin_id
                    INNER JOIN dbo.Form_Basvurulari b ON b.form_basvuru_id = o.form_basvuru_id
                    WHERE o.form_basvuru_id = ? AND o.form_onay_metin_id = ? AND $scopeSql
                ", array_merge([(int)($_POST['form_basvuru_id'] ?? 0), (int)($_POST['form_onay_metin_id'] ?? 0)], $scopeParams));
                if (!$metin) {
                    echo json_encode(['success' => false, 'message' => 'Metin bulunamadı'], JSON_UNESCAPED_UNICODE);
                    break;
                }
                echo json_encode(['success' => true, 'data' => [
                    'baslik'       => $metin['baslik'],
                    'html'         => OnayKanit::metinHtml($metin['icerik']),
                    'hash'         => trim($metin['hash']),
                    'hash_gecerli' => hash_equals(trim($metin['hash']), OnayKanit::metinHash($metin['icerik'])),
                    'ilk_kullanim' => $metin['ilk_kullanim'],
                ]], JSON_UNESCAPED_UNICODE);
                break;

            case 'durum':
                if (!$permissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Düzenleme yetkiniz yok'], JSON_UNESCAPED_UNICODE);
                    break;
                }
                $id = (int)($_POST['form_basvuru_id'] ?? 0);
                $yeni = (int)($_POST['durum'] ?? 0) === 1 ? 1 : 0;
                [$scopeSql, $scopeParams] = PageAuth::firmaScopeWhere($firmaScopeIds, 'b.firma_id');
                $mevcut = $db->fetchOne("SELECT b.form_basvuru_id FROM dbo.Form_Basvurulari b WHERE b.form_basvuru_id = ? AND $scopeSql",
                    array_merge([$id], $scopeParams));
                if (!$mevcut) {
                    echo json_encode(['success' => false, 'message' => 'Kayıt bulunamadı'], JSON_UNESCAPED_UNICODE);
                    break;
                }
                $db->update('dbo.Form_Basvurulari', [
                    'form_basvuru_durum'                    => $yeni,
                    'form_basvuru_guncelleyen_kullanici_id' => $user['kullanici_id'],
                    'form_basvuru_guncelleme_tarihi'        => date('Y-m-d H:i:s'),
                ], ['form_basvuru_id' => $id]);
                echo json_encode(['success' => true, 'message' => $yeni ? 'Kayıt aktif edildi' : 'Kayıt pasife alındı'], JSON_UNESCAPED_UNICODE);
                break;

            case 'iptal':
                // Müşterinin izni geri çekmesi: API iptaliyle aynı kayıt, anahtar yerine kullanıcı
                if (!$permissions['can_delete']) {
                    echo json_encode(['success' => false, 'message' => 'İptal yetkiniz yok'], JSON_UNESCAPED_UNICODE);
                    break;
                }
                $id = (int)($_POST['form_basvuru_id'] ?? 0);
                $neden = trim(preg_replace('/\s+/u', ' ', (string)($_POST['neden'] ?? '')));
                if ($neden === '' || mb_strlen($neden) > 500) {
                    echo json_encode(['success' => false, 'message' => 'İptal nedeni zorunlu (en fazla 500 karakter)'], JSON_UNESCAPED_UNICODE);
                    break;
                }
                [$scopeSql, $scopeParams] = PageAuth::firmaScopeWhere($firmaScopeIds, 'b.firma_id');

                $conn = $db->getConnection();
                sqlsrv_begin_transaction($conn);
                try {
                    $b = $db->fetchOne("
                        SELECT b.form_basvuru_id, b.firma_id, f.form_uniqid,
                               d.form_dogrulama_durum_gecerli AS gecerli, d.form_dogrulama_durum_adi AS durum_adi
                        FROM dbo.Form_Basvurulari b WITH (UPDLOCK, HOLDLOCK)
                        INNER JOIN dbo.Formlar f ON f.form_id = b.form_id
                        LEFT JOIN dbo.tanim_FormDogrulamaDurumlari d ON d.form_dogrulama_durum_id = b.form_basvuru_dogrulama_durumu
                        WHERE b.form_basvuru_id = ? AND $scopeSql
                    ", array_merge([$id], $scopeParams));
                    if (!$b) {
                        throw new RuntimeException('Kayıt bulunamadı');
                    }
                    if ((int)$b['gecerli'] !== 1) {
                        throw new RuntimeException('Yalnız geçerli (onaylanmış) kayıt iptal edilebilir; bu kaydın durumu: ' . $b['durum_adi']);
                    }
                    $iptalDurum = $db->fetchOne("
                        SELECT form_dogrulama_durum_id FROM dbo.tanim_FormDogrulamaDurumlari
                        WHERE form_dogrulama_durum_kod = 'iptal' AND form_dogrulama_durum_durum = 1
                    ");
                    if (!$iptalDurum) {
                        throw new Exception("tanim_FormDogrulamaDurumlari içinde 'iptal' tanımı yok.");
                    }
                    $ok = $db->execute("
                        INSERT INTO dbo.Form_Basvuru_Iptalleri (iptal_form_basvuru_id, iptal_neden, iptal_api_anahtar_id, iptal_ip, OlusturanKullanici)
                        VALUES (?, ?, NULL, ?, ?)
                    ", [$id, $neden, mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45), (int)$user['kullanici_id']])
                        && $db->execute("
                        UPDATE dbo.Form_Basvurulari
                        SET form_basvuru_dogrulama_durumu = ?, form_basvuru_guncelleyen_kullanici_id = ?, form_basvuru_guncelleme_tarihi = GETDATE()
                        WHERE form_basvuru_id = ?
                    ", [(int)$iptalDurum['form_dogrulama_durum_id'], (int)$user['kullanici_id'], $id]);
                    if (!$ok) {
                        throw new Exception('İptal kaydı yazılamadı (#' . $id . ').');
                    }
                    sqlsrv_commit($conn);
                } catch (RuntimeException $e) {
                    sqlsrv_rollback($conn);
                    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
                    break;
                } catch (Throwable $e) {
                    sqlsrv_rollback($conn);
                    throw $e;
                }

                $bildirim = Webhook::olay('basvuru.iptal_edildi', (int)$b['firma_id'], $id,
                    ['form' => $b['form_uniqid'], 'durum' => 'iptal', 'kaynak' => 'panel']);
                echo json_encode(['success' => true, 'message' => 'Kayıt iptal edildi' . ($bildirim ? " ($bildirim bildirim kuyruğa alındı)" : '')], JSON_UNESCAPED_UNICODE);
                break;

            default:
                echo json_encode(['success' => false, 'message' => 'Geçersiz işlem'], JSON_UNESCAPED_UNICODE);
        }
    } catch (Throwable $e) {
        error_log('İzinli müşteriler hatası (' . $action . '): ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'İşlem sırasında hata oluştu.'], JSON_UNESCAPED_UNICODE);
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
    <link rel="stylesheet" href="/admin/assets/css/adminlte.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/admin/assets/css/custom.css">

    <style>
        #musteriTable th { text-transform: uppercase; font-size: .78rem; letter-spacing: .4px; white-space: nowrap; }
        #musteriTable td { vertical-align: middle; }
        .izin-ikon { font-size: 1.35rem; line-height: 1; }
        .form-link { font-weight: 600; text-decoration: underline; }
        .satir-pasif td { opacity: .55; }
        .detay-tablo th { width: 40%; color: var(--bs-secondary-color); font-weight: 500; }
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
                    <div class="row row-cols-1 row-cols-sm-2 row-cols-xl-5 g-3 mb-3">
                        <div class="col">
                            <div class="info-box mb-0">
                                <span class="info-box-icon text-bg-primary shadow-sm"><i class="bi bi-people"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Toplam Müşteri</span>
                                    <span class="info-box-number" id="stat-toplam">-</span>
                                    <small class="text-muted">Bu ay: <span id="stat-bu-ay">-</span></small>
                                </div>
                            </div>
                        </div>
                        <div class="col">
                            <div class="info-box mb-0">
                                <span class="info-box-icon text-bg-success shadow-sm"><i class="bi bi-patch-check"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">SMS ile Doğrulanan</span>
                                    <span class="info-box-number" id="stat-gecerli">-</span>
                                </div>
                            </div>
                        </div>
                        <div class="col">
                            <div class="info-box mb-0">
                                <span class="info-box-icon text-bg-info shadow-sm"><i class="bi bi-shield-check"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">KVKK Onaylı</span>
                                    <span class="info-box-number" id="stat-kvkk">-</span>
                                </div>
                            </div>
                        </div>
                        <div class="col">
                            <div class="info-box mb-0">
                                <span class="info-box-icon text-bg-warning shadow-sm"><i class="bi bi-envelope-check"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">ETK İzinli</span>
                                    <span class="info-box-number" id="stat-etk">-</span>
                                </div>
                            </div>
                        </div>
                        <div class="col">
                            <div class="info-box mb-0">
                                <span class="info-box-icon text-bg-danger shadow-sm"><i class="bi bi-geo-alt"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">GPS İzinli</span>
                                    <span class="info-box-number" id="stat-gps">-</span>
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
                                        <input type="text" class="form-control" id="filter_search" placeholder="Ad, soyad, telefon veya e-posta...">
                                    </div>
                                    <div class="col-md-4 <?= $tekFirma ? 'd-none' : '' ?>">
                                        <label class="form-label">Firma</label>
                                        <select class="form-select select2-filter" id="filter_firma_id" style="width: 100%;">
                                            <option value="">Tümü</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Form</label>
                                        <select class="form-select select2-filter" id="filter_form_id" style="width: 100%;">
                                            <option value="">Tümü</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Doğrulama</label>
                                        <select class="form-select select2-filter" id="filter_dogrulama_id" style="width: 100%;">
                                            <option value="">Tümü</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">ETK</label>
                                        <select class="form-select select2-filter izin-filtre" id="filter_etk" style="width: 100%;">
                                            <option value="">Tümü</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">GPS</label>
                                        <select class="form-select select2-filter izin-filtre" id="filter_gps" style="width: 100%;">
                                            <option value="">Tümü</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">KVKK</label>
                                        <select class="form-select select2-filter izin-filtre" id="filter_kvkk" style="width: 100%;">
                                            <option value="">Tümü</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Kaynak</label>
                                        <select class="form-select select2-filter" id="filter_kaynak" style="width: 100%;">
                                            <option value="">Tümü</option>
                                            <option value="form">Form</option>
                                            <option value="api">API</option>
                                            <option value="excel">Excel</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Başlangıç Tarihi</label>
                                        <input type="date" class="form-control" id="filter_tarih_bas">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Bitiş Tarihi</label>
                                        <input type="date" class="form-control" id="filter_tarih_bit">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Durum</label>
                                        <select class="form-select select2-filter" id="filter_status" style="width: 100%;">
                                            <option value="1" selected>Aktif</option>
                                            <option value="0">Pasif</option>
                                            <option value="hepsi">Tümü</option>
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

                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">Müşteri Listesi</h3>
                            <div class="card-tools">
                                <button type="button" class="btn btn-success btn-sm" id="excelindir">
                                    <i class="bi bi-file-earmark-excel"></i> Excel
                                </button>
                            </div>
                        </div>
                        <div class="card-body">
                            <table id="musteriTable" class="table table-hover" style="width:100%">
                                <thead>
                                    <tr>
                                        <th>Form</th>
                                        <th class="text-center">ETK</th>
                                        <th class="text-center">GPS</th>
                                        <th class="text-center">KVKK</th>
                                        <th>Zaman</th>
                                        <th>Müşteri Adı</th>
                                        <th>Soyad</th>
                                        <th>Cep Telefonu</th>
                                        <th>Firma</th>
                                        <th>Doğrulama</th>
                                        <th>Kaynak</th>
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

    <!-- Detay Modal -->
    <div class="modal fade" id="detayModal" tabindex="-1" aria-labelledby="detayModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="detayModalLabel">Müşteri Detayı</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                </div>
                <div class="modal-body" id="detayIcerik">
                    <div class="text-center text-muted py-4">Yükleniyor...</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="bi bi-x-circle"></i> Kapat</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
    <script src="/admin/assets/js/adminlte.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script src="/admin/assets/js/custom.js"></script>

    <script>
        let dataTable, detayModal;
        let currentfilters = { status: '1' };

        const TEK_FIRMA = <?= $tekFirma ? 'true' : 'false' ?>;
        const permissions = {
            canEdit: <?= $permissions['can_edit'] ? 'true' : 'false' ?>,
            canDelete: <?= $permissions['can_delete'] ? 'true' : 'false' ?>
        };
        const KAYNAK = {
            api:   '<span class="badge text-bg-info"><i class="bi bi-plug me-1"></i>API</span>',
            excel: '<span class="badge text-bg-secondary"><i class="bi bi-file-earmark-excel me-1"></i>Excel</span>',
            form:  '<span class="badge text-bg-light border"><i class="bi bi-ui-checks me-1"></i>Form</span>'
        };

        function escapeHtml(text) {
            if (text === null || text === undefined) return '';
            return String(text)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
        }

        // 'YYYY-MM-DD HH:MM:SS' → 'DD.MM.YYYY HH:MM:SS'
        function formatZaman(v) {
            if (!v) return '-';
            const [t, s] = String(v).split(' ');
            const p = t.split('-');
            return p.length === 3 ? `${p[2]}.${p[1]}.${p[0]}${s ? ' ' + s : ''}` : v;
        }

        function izinIkon(v) {
            if (v === null || v === undefined || v === '') return '<span class="text-muted" title="Formda yok">-</span>';
            return v == 1
                ? '<i class="bi bi-check-circle text-success izin-ikon" title="İzin verildi"></i>'
                : '<i class="bi bi-x-circle text-danger izin-ikon" title="İzin verilmedi"></i>';
        }

        function sayi(v) {
            return (parseInt(v, 10) || 0).toLocaleString('tr-TR');
        }

        $(document).ready(function() {
            detayModal = new bootstrap.Modal(document.getElementById('detayModal'));

            $('.izin-filtre').each(function() {
                $(this).append(new Option('Verildi', '1'), new Option('Verilmedi', '0'));
            });
            $('.select2-filter').select2({ theme: 'bootstrap-5', width: '100%', placeholder: 'Tümü', allowClear: true });
            $('#filter_status').select2({ theme: 'bootstrap-5', width: '100%', minimumResultsForSearch: 0 });

            // Formlarım > Müşteri Listesi'nden geliş (?form_id=)
            const urlFormId = parseInt(new URLSearchParams(window.location.search).get('form_id'), 10);
            if (urlFormId > 0) {
                currentfilters.form_id = String(urlFormId);
                $('#filter_form_id').data('url-secim', String(urlFormId));
                $('#filterCard').addClass('show');
            }

            dataTable = $('#musteriTable').DataTable({
                language: { url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/tr.json' },
                serverSide: true,
                processing: true,
                dom: 'lrtip',
                scrollX: true,
                pageLength: 25,
                lengthMenu: [[10, 25, 50, 100, 250], [10, 25, 50, 100, 250]],
                order: [[4, 'desc']],
                ajax: {
                    url: '',
                    type: 'POST',
                    data: function(d) {
                        d.action = 'list';
                        Object.assign(d, currentfilters);
                    },
                    error: function() { showToast('Liste yüklenirken hata oluştu', 'error'); }
                },
                createdRow: function(row, r) {
                    if (r.durum != 1) $(row).addClass('satir-pasif');
                },
                columns: [
                    { data: 'form_adi', render: function(v, t, r) {
                        if (!v) return '-';
                        return r.form_uniqid
                            ? `<a href="/form/${encodeURIComponent(r.form_uniqid)}" target="_blank" class="form-link">${escapeHtml(v)}</a>`
                            : escapeHtml(v);
                    }},
                    { data: 'etk', className: 'text-center', render: izinIkon },
                    { data: 'gps', className: 'text-center', render: izinIkon },
                    { data: 'kvkk', className: 'text-center', render: izinIkon },
                    { data: 'tarih', className: 'text-nowrap', render: v => `<span class="text-muted">${escapeHtml(formatZaman(v))}</span>` },
                    { data: 'ad', render: v => escapeHtml(v || '-') },
                    { data: 'soyad', render: v => escapeHtml(v || '-') },
                    { data: 'telefon', className: 'text-nowrap', render: v => v
                        ? `<a href="tel:${escapeHtml(v)}" class="text-reset">${escapeHtml(v)}</a>` : '-' },
                    { data: 'firma_adi', visible: !TEK_FIRMA, render: v => `<small>${escapeHtml(v || '-')}</small>` },
                    { data: 'dogrulama_adi', render: (v, t, r) =>
                        `<span class="badge text-bg-${escapeHtml(r.dogrulama_renk || 'secondary')}">${escapeHtml(v || '-')}</span>` },
                    { data: 'kaynak', orderable: false, className: 'text-nowrap', render: v => KAYNAK[v] || escapeHtml(v || '-') },
                    { data: null, orderable: false, className: 'text-center text-nowrap', render: function(v, t, r) {
                        let menu = `<li><a class="dropdown-item" href="#" onclick="detayGoster(${r.form_basvuru_id}); return false;"><i class="bi bi-eye me-2"></i>Detay</a></li>`;
                        if (r.telefon) {
                            menu += `<li><a class="dropdown-item" href="tel:${escapeHtml(r.telefon)}"><i class="bi bi-telephone me-2"></i>Ara</a></li>`;
                        }
                        if (permissions.canEdit) {
                            menu += '<li><hr class="dropdown-divider"></li>' + (r.durum == 1
                                ? `<li><a class="dropdown-item text-danger" href="#" onclick="durumDegistir(${r.form_basvuru_id}, 0); return false;"><i class="bi bi-eye-slash me-2"></i>Pasife Al</a></li>`
                                : `<li><a class="dropdown-item text-success" href="#" onclick="durumDegistir(${r.form_basvuru_id}, 1); return false;"><i class="bi bi-eye me-2"></i>Aktif Et</a></li>`);
                        }
                        if (permissions.canDelete && r.gecerli == 1) {
                            const etiket = ((r.ad || '') + ' ' + (r.soyad || '')).trim() || r.telefon || ('#' + r.form_basvuru_id);
                            menu += `<li><a class="dropdown-item text-danger" href="#" onclick="iptalEt(${r.form_basvuru_id}, ${escapeHtml(JSON.stringify(etiket))}); return false;"><i class="bi bi-x-octagon me-2"></i>İzni İptal Et</a></li>`;
                        }
                        return `<div class="btn-group">
                                    <button type="button" class="btn btn-sm btn-primary" onclick="detayGoster(${r.form_basvuru_id})">İşlem</button>
                                    <button type="button" class="btn btn-sm btn-primary dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown" data-bs-boundary="viewport" aria-expanded="false">
                                        <span class="visually-hidden">Menü</span>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end">${menu}</ul>
                                </div>`;
                    }}
                ],
                initComplete: function() {
                    loadStats();
                }
            });

            loadFirmalar();
            loadFormlar();
            loadDogrulamalar();

            $('#filter_firma_id').on('change', function() {
                loadFormlar($(this).val());
            });

            $('#filterForm').on('submit', function(e) {
                e.preventDefault();
                const durum = $('#filter_status').val();
                currentfilters = {
                    search_text: $('#filter_search').val(),
                    firma_id: $('#filter_firma_id').val(),
                    form_id: $('#filter_form_id').val(),
                    dogrulama_id: $('#filter_dogrulama_id').val(),
                    etk: $('#filter_etk').val(),
                    gps: $('#filter_gps').val(),
                    kvkk: $('#filter_kvkk').val(),
                    kaynak: $('#filter_kaynak').val(),
                    tarih_bas: $('#filter_tarih_bas').val(),
                    tarih_bit: $('#filter_tarih_bit').val(),
                    status: durum === 'hepsi' ? 'hepsi' : durum
                };
                Object.keys(currentfilters).forEach(k => {
                    if (currentfilters[k] === null || currentfilters[k] === '') delete currentfilters[k];
                });
                listeYenile();
                showToast('Filtre uygulandı', 'info');
            });

            $('#clearfilters').on('click', function() {
                $('#filterForm')[0].reset();
                $('.select2-filter').not('#filter_status').val('').trigger('change.select2');
                $('#filter_status').val('1').trigger('change.select2');
                loadFormlar();
                currentfilters = { status: '1' };
                listeYenile();
                showToast('Filtreler temizlendi', 'info');
            });

            $('#excelindir').on('click', function() {
                const $form = $('<form>', { method: 'POST', action: '' });
                $form.append($('<input>', { type: 'hidden', name: 'action', value: 'excel' }));
                Object.keys(currentfilters).forEach(k => {
                    $form.append($('<input>', { type: 'hidden', name: k, value: currentfilters[k] }));
                });
                $('body').append($form);
                $form.submit();
                $form.remove();
                showToast('Excel dosyası indiriliyor...', 'info');
            });
        });

        function listeYenile(sayfayiKoru) {
            dataTable.ajax.reload(null, !sayfayiKoru);
            loadStats();
        }

        function loadStats() {
            $.post('', { action: 'stats', ...currentfilters }, function(r) {
                if (!r.success) return;
                $('#stat-toplam').text(sayi(r.data.toplam));
                $('#stat-bu-ay').text(sayi(r.data.bu_ay));
                $('#stat-gecerli').text(sayi(r.data.gecerli));
                $('#stat-kvkk').text(sayi(r.data.kvkk));
                $('#stat-etk').text(sayi(r.data.etk));
                $('#stat-gps').text(sayi(r.data.gps));
            }, 'json');
        }

        function loadFirmalar() {
            if (TEK_FIRMA) return;
            $.post('', { action: 'firma_list' }, function(r) {
                if (!r.success) return;
                const $s = $('#filter_firma_id');
                r.data.forEach(f => $s.append(new Option(f.firma_adi, f.firma_id, false, false)));
                $s.trigger('change.select2');
            }, 'json');
        }

        function loadFormlar(firmaId) {
            $.post('', { action: 'form_list', firma_id: firmaId || '' }, function(r) {
                if (!r.success) return;
                const $s = $('#filter_form_id');
                const secili = $s.val() || $s.data('url-secim');
                $s.removeData('url-secim');
                $s.empty().append(new Option('Tümü', '', false, false));
                r.data.forEach(f => {
                    const etiket = (!TEK_FIRMA && !firmaId && f.firma_adi) ? `${f.form_adi} (${f.firma_adi})` : f.form_adi;
                    $s.append(new Option(etiket, f.form_id, false, false));
                });
                if (secili && $s.find(`option[value="${secili}"]`).length) $s.val(secili);
                $s.trigger('change.select2');
            }, 'json');
        }

        function loadDogrulamalar() {
            $.post('', { action: 'dogrulama_list' }, function(r) {
                if (!r.success) return;
                const $s = $('#filter_dogrulama_id');
                r.data.forEach(d => $s.append(new Option(d.form_dogrulama_durum_adi, d.form_dogrulama_durum_id, false, false)));
                $s.trigger('change.select2');
            }, 'json');
        }

        // ilk: iptalde ilk verilen değer (geri alındıysa "iptalle geri alındı" notu)
        function izinSatir(baslik, v, ilk) {
            let deger;
            if (v === null || v === undefined || v === '') deger = '<span class="text-muted">Formda yok</span>';
            else if (ilk == 1 && v == 0) deger = '<span class="text-danger"><i class="bi bi-arrow-counterclockwise me-1"></i>Geri alındı</span> <small class="text-muted">(iptalle)</small>';
            else deger = v == 1
                ? '<span class="text-success"><i class="bi bi-check-circle me-1"></i>Verildi</span>'
                : '<span class="text-danger"><i class="bi bi-x-circle me-1"></i>Verilmedi</span>';
            return `<tr><th>${baslik}</th><td>${deger}</td></tr>`;
        }

        function iptalHtml(d) {
            if (!d.iptal_id) return '';
            const kim = d.iptal_anahtar
                ? `API · <span class="font-monospace">${escapeHtml(d.iptal_anahtar)}…</span> ${escapeHtml(d.iptal_anahtar_adi || '')}`
                : `Panel · ${escapeHtml(d.iptal_kullanici || '-')}`;
            return `<div class="alert alert-danger mt-2 mb-3">
                <h6 class="alert-heading mb-2"><i class="bi bi-x-octagon me-1"></i>İzin İptal Edildi</h6>
                <table class="table table-sm table-borderless mb-0 small detay-tablo"><tbody>
                    <tr><th>Tarih</th><td>${escapeHtml(formatZaman(d.iptal_tarih))}</td></tr>
                    <tr><th>Neden</th><td>${escapeHtml(d.iptal_neden)}</td></tr>
                    <tr><th>Yapan</th><td>${kim}</td></tr>
                    <tr><th>IP</th><td>${escapeHtml(d.iptal_ip || '-')}${d.iptal_musteri_ip ? ` <small class="text-muted">(müşteri: ${escapeHtml(d.iptal_musteri_ip)})</small>` : ''}</td></tr>
                </tbody></table>
                <div class="small mt-2">Açık rıza, KVKK ve ETK izinleri geri alınmış sayılır. Onay kanıtı değişmeden korunur.</div>
            </div>`;
        }

        function iptalEt(id, etiket) {
            Swal.fire({
                title: 'İzin iptal edilsin mi?',
                html: `<div class="text-start small mb-2"><b>${escapeHtml(etiket)}</b> kaydının açık rıza, KVKK ve ETK izinleri geri alınmış sayılır.
                       Bu işlem <b>geri alınamaz</b>; firmanın bildirim adresine "iptal edildi" bildirimi gönderilir.</div>`,
                input: 'textarea',
                inputLabel: 'İptal nedeni',
                inputPlaceholder: 'Örn. Müşteri telefonla izni geri çekti',
                inputAttributes: { maxlength: 500 },
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'İptal Et',
                cancelButtonText: 'Vazgeç',
                confirmButtonColor: '#dc3545',
                inputValidator: v => (!v || !v.trim()) ? 'İptal nedeni zorunludur' : undefined
            }).then(res => {
                if (!res.isConfirmed) return;
                $.post('', { action: 'iptal', form_basvuru_id: id, neden: res.value.trim() }, function(r) {
                    showToast(r.message, r.success ? 'success' : 'error');
                    if (r.success) listeYenile(true);
                }, 'json').fail(() => showToast('Sunucuya ulaşılamadı', 'error'));
            });
        }

        function detayGoster(id) {
            $('#detayIcerik').html('<div class="text-center text-muted py-4">Yükleniyor...</div>');
            detayModal.show();
            $.post('', { action: 'get', form_basvuru_id: id }, function(r) {
                if (!r.success) {
                    $('#detayIcerik').html(`<div class="alert alert-danger mb-0">${escapeHtml(r.message || 'Kayıt yüklenemedi')}</div>`);
                    return;
                }
                const d = r.data;
                const adSoyad = ((d.ad || '') + ' ' + (d.soyad || '')).trim() || '-';
                $('#detayModalLabel').text(adSoyad);

                let alanlar = '';
                d.alanlar.forEach(a => {
                    alanlar += `<tr><th>${escapeHtml(a.etiket)}</th><td>${escapeHtml(a.deger || '-')}</td></tr>`;
                });

                let gecmis = '';
                if (d.gecmis.length) {
                    gecmis = '<h6 class="mt-4 mb-2"><i class="bi bi-clock-history me-1"></i>Aynı Telefonun Diğer Başvuruları</h6>'
                        + '<table class="table table-sm"><tbody>';
                    d.gecmis.forEach(g => {
                        gecmis += `<tr>
                            <td class="text-nowrap"><small>${escapeHtml(formatZaman(g.tarih))}</small></td>
                            <td><small>${escapeHtml(g.form_adi || '-')}</small></td>
                            <td class="text-end"><span class="badge text-bg-${escapeHtml(g.dogrulama_renk || 'secondary')}">${escapeHtml(g.dogrulama_adi || '-')}</span></td>
                        </tr>`;
                    });
                    gecmis += '</tbody></table>';
                }

                $('#detayIcerik').html(`
                    <div class="row g-4">
                        <div class="col-md-6">
                            <h6 class="mb-2"><i class="bi bi-person me-1"></i>Başvuru Bilgileri</h6>
                            <table class="table table-sm detay-tablo">
                                <tbody>
                                    ${TEK_FIRMA ? '' : `<tr><th>Firma</th><td>${escapeHtml(d.firma_adi || '-')}</td></tr>`}
                                    <tr><th>Form</th><td>${escapeHtml(d.form_adi || '-')}</td></tr>
                                    <tr><th>Başvuru Zamanı</th><td>${escapeHtml(formatZaman(d.tarih))}</td></tr>
                                    <tr><th>Cep Telefonu</th><td>${escapeHtml(d.telefon || '-')}</td></tr>
                                    <tr><th>E-posta</th><td>${escapeHtml(d.eposta || '-')}</td></tr>
                                    <tr><th>IP Adresi</th><td>${escapeHtml(d.ip || '-')}</td></tr>
                                    <tr><th>Kaynak</th><td>${KAYNAK[d.kaynak] || escapeHtml(d.kaynak || '-')}</td></tr>
                                    <tr><th>Durum</th><td>${d.durum == 1 ? '<span class="badge text-bg-success">Aktif</span>' : '<span class="badge text-bg-secondary">Pasif</span>'}</td></tr>
                                </tbody>
                            </table>
                        </div>
                        <div class="col-md-6">
                            <h6 class="mb-2"><i class="bi bi-shield-check me-1"></i>İzinler</h6>
                            <table class="table table-sm detay-tablo">
                                <tbody>
                                    ${izinSatir('Aydınlatma Metni', d.aydinlatma)}
                                    ${izinSatir('Açık Rıza', d.acik_riza, d.verilen && d.verilen.acik_riza)}
                                    ${izinSatir('KVKK', d.kvkk, d.verilen && d.verilen.kvkk)}
                                    ${izinSatir('ETK', d.etk, d.verilen && d.verilen.etk)}
                                    ${izinSatir('GPS', d.gps)}
                                    <tr><th>Doğrulama</th><td><span class="badge text-bg-${escapeHtml(d.dogrulama_renk || 'secondary')}">${escapeHtml(d.dogrulama_adi || '-')}</span></td></tr>
                                    <tr><th>SMS Doğrulama</th><td>${escapeHtml(formatZaman(d.sms_tarih))}</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    ${iptalHtml(d)}
                    ${kanitHtml(d)}
                    ${alanlar ? `<h6 class="mt-2 mb-2"><i class="bi bi-ui-checks me-1"></i>Form Alanları</h6>
                        <table class="table table-sm table-striped detay-tablo"><tbody>${alanlar}</tbody></table>` : ''}
                    ${gecmis}
                `);
            }, 'json').fail(() => {
                $('#detayIcerik').html('<div class="alert alert-danger mb-0">Sunucuya ulaşılamadı.</div>');
            });
        }

        // Kanıt durumu + onaylanan metin sürümleri
        function kanitHtml(d) {
            const k = d.kanit || { durum: 'yok', mesaj: '' };
            const rozet = {
                dogrulandi: '<span class="badge text-bg-success"><i class="bi bi-shield-check me-1"></i>Doğrulandı</span>',
                uyusmazlik: '<span class="badge text-bg-danger"><i class="bi bi-shield-exclamation me-1"></i>Uyuşmazlık</span>',
                yok:        '<span class="badge text-bg-secondary"><i class="bi bi-shield me-1"></i>Kanıt yok</span>'
            }[k.durum] || '';

            let metinler = '';
            (d.onay_metinleri || []).forEach(m => {
                metinler += `<tr>
                    <td>${escapeHtml(m.baslik)}</td>
                    <td>${m.kabul == 1 ? '<span class="text-success">Kabul</span>' : '<span class="text-danger">Ret</span>'}</td>
                    <td><code class="small" title="${escapeHtml(m.hash)}">${escapeHtml(String(m.hash).substring(0, 12))}…</code></td>
                    <td class="text-end"><button type="button" class="btn btn-sm btn-outline-primary" onclick="metinGoster(${d.form_basvuru_id}, ${m.form_onay_metin_id})">
                        <i class="bi bi-file-earmark-text"></i> Metni Gör</button></td>
                </tr>`;
            });

            return `<div class="border rounded p-3 mt-2 mb-3 ${k.durum === 'uyusmazlik' ? 'border-danger' : ''}">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <h6 class="mb-0"><i class="bi bi-fingerprint me-1"></i>Onay Kanıtı</h6>${rozet}
                </div>
                <div class="small text-muted mb-2">${escapeHtml(k.mesaj)}</div>
                ${k.hash ? `<div class="small mb-2">SHA-256: <code class="text-break">${escapeHtml(k.hash)}</code></div>` : ''}
                ${metinler ? `<table class="table table-sm mb-0"><thead><tr><th>Onaylanan Metin</th><th>Cevap</th><th>Metin Hash</th><th></th></tr></thead>
                    <tbody>${metinler}</tbody></table>` : ''}
            </div>`;
        }

        function metinGoster(basvuruId, metinId) {
            $.post('', { action: 'metin', form_basvuru_id: basvuruId, form_onay_metin_id: metinId }, function(r) {
                if (!r.success) {
                    showToast(r.message || 'Metin yüklenemedi', 'error');
                    return;
                }
                const m = r.data;
                Swal.fire({
                    title: escapeHtml(m.baslik),
                    width: 800,
                    html: `<div class="text-start small mb-2">
                               ${m.hash_gecerli ? '<span class="badge text-bg-success">İçerik hash ile uyumlu</span>' : '<span class="badge text-bg-danger">İçerik değiştirilmiş!</span>'}
                               <span class="text-muted ms-2">İlk kullanım: ${escapeHtml(formatZaman(m.ilk_kullanim))}</span>
                               <div class="mt-1">SHA-256: <code class="text-break">${escapeHtml(m.hash)}</code></div>
                           </div>
                           <div class="text-start border rounded p-3" style="max-height:60vh;overflow-y:auto">${m.html}</div>`,
                    confirmButtonText: 'Kapat'
                });
            }, 'json').fail(() => showToast('Sunucuya ulaşılamadı', 'error'));
        }

        function durumDegistir(id, durum) {
            const metin = durum ? 'Kayıt yeniden aktif edilecek.' : 'Kayıt listeden gizlenir, silinmez. Filtreden "Pasif" seçilerek görülebilir.';
            confirmAction(durum ? 'Kayıt aktif edilsin mi?' : 'Kayıt pasife alınsın mı?', metin, function() {
                $.post('', { action: 'durum', form_basvuru_id: id, durum: durum }, function(r) {
                    if (r.success) {
                        showToast(r.message, 'success');
                        listeYenile(true);
                    } else {
                        showToast(r.message, 'error');
                    }
                }, 'json').fail(() => showToast('Sunucuya ulaşılamadı', 'error'));
            });
        }
    </script>
</body>
</html>
