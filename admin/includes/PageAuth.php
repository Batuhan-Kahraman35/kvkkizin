<?php
/**
 * Sayfa Yetki Kontrolü
 * 
 * Kullanıcının mevcut sayfaya erişim yetkisini kontrol eder.
 * departman_id=1 (Administrator) için tüm yetkilere izin verir.
 * Diğer departmanlar için menu_sayfa_yetkiler tablosunu kontrol eder.
 */

class PageAuth {
    
    /**
     * Sayfa yetkilerini kontrol et
     * 
     * @param int $userId Kullanıcı ID
     * @param int $departmanId Departman ID
     * @param string $pageFile Sayfa dosya adı (örn: stok-yonetimi.php)
     * @return array Yetki bilgileri
     */
    public static function checkPagePermissions($userId, $departmanId, $pageFile) {
        // Administrator departmanı için tüm yetkiler açık
        if ($departmanId == 1) {
            return [
                'has_access' => true,
                'can_view' => true,
                'can_add' => true,
                'can_edit' => true,
                'can_delete' => true,
                'can_view_firma' => true,
                'can_view_sube' => true,
                'can_view_own_records' => false, // Admin tüm kayıtları görür
                'is_admin' => true
            ];
        }
        
        $db = Database::getInstance();
        
        // Sayfa ID'sini bul
        // URL'ler DB'de karışık formatta olabilir: 'sayfa.php', 'sayfa', '/admin/pages/sayfa.php'
        // Bu yüzden hem uzantılı hem uzantısız, hem de yol önekli biçimler denenir.
        $dosyaAdi = trim($pageFile);
        $uzantisiz = preg_replace('/\.php$/i', '', $dosyaAdi);

        $page = $db->fetchOne("
            SELECT TOP 1 sayfalar_id
            FROM Menu_Sayfalar
            WHERE sayfalar_durum = 1
              AND (
                    RTRIM(LTRIM(sayfalar_sayfa_url)) = ?
                 OR RTRIM(LTRIM(sayfalar_sayfa_url)) = ?
                 OR RTRIM(LTRIM(sayfalar_sayfa_url)) LIKE ?
                 OR RTRIM(LTRIM(sayfalar_sayfa_url)) LIKE ?
              )
            ORDER BY
                CASE
                    WHEN RTRIM(LTRIM(sayfalar_sayfa_url)) = ? THEN 1
                    WHEN RTRIM(LTRIM(sayfalar_sayfa_url)) = ? THEN 2
                    ELSE 3
                END
        ", [
            $uzantisiz . '.php',
            $uzantisiz,
            '%/' . $uzantisiz . '.php',
            '%/' . $uzantisiz,
            $uzantisiz . '.php',
            $uzantisiz
        ]);
        
        if (!$page) {
            // Sayfa tanımı yoksa erişim yok
            return [
                'has_access' => false,
                'can_view' => false,
                'can_add' => false,
                'can_edit' => false,
                'can_delete' => false,
                'can_view_firma' => false,
                'can_view_sube' => false,
                'is_admin' => false,
                'error' => 'Sayfa tanımı bulunamadı'
            ];
        }
        
        // Departman yetkilerini kontrol et
        $permissions = $db->fetchOne("
            SELECT 
                gor,
                ekle,
                duzenle,
                sil,
                firma_gor,
                sube_gor,
                bolge_gor,
                kendi_kullanicini_gor
            FROM Menu_SayfaYetkileri
            WHERE departman_id = ? 
              AND sayfa_id = ? 
              AND durum = 1
        ", [$departmanId, $page['sayfalar_id']]);
        
        if (!$permissions) {
            // Yetki kaydı yoksa erişim yok
            return [
                'has_access' => false,
                'can_view' => false,
                'can_add' => false,
                'can_edit' => false,
                'can_delete' => false,
                'can_view_firma' => false,
                'can_view_sube' => false,
                'can_view_own_records' => false,
                'is_admin' => false,
                'error' => 'Bu sayfaya erişim yetkiniz yok'
            ];
        }
        
        return [
            'has_access' => (bool)$permissions['gor'],
            'can_view' => (bool)$permissions['gor'],
            'can_add' => (bool)$permissions['ekle'],
            'can_edit' => (bool)$permissions['duzenle'],
            'can_delete' => (bool)$permissions['sil'],
            'can_view_firma' => (bool)$permissions['firma_gor'],
            'can_view_sube' => (bool)$permissions['sube_gor'],
            'bolge_gor' => (bool)$permissions['bolge_gor'],
            'can_view_own_records' => (bool)$permissions['kendi_kullanicini_gor'],
            'is_admin' => false
        ];
    }
    
    /**
     * Kullanıcının görebileceği firma ID listesini döndür
     *
     * Administrator  -> null (kısıtlama yok)
     * firma_gor = 1  -> kendi firması + tüm alt firmaları
     * firma_gor = 0  -> sadece kendi firması
     * firma yoksa    -> boş dizi (hiçbir kayıt görünmez)
     *
     * @param array $permissions checkPagePermissions() çıktısı
     * @param int|null $firmaId Kullanıcının firma_id değeri
     * @return array|null
     */
    public static function firmaScopeIds($permissions, $firmaId) {
        if (!empty($permissions['is_admin'])) {
            return null;
        }

        $firmaId = (int)$firmaId;
        if ($firmaId <= 0) {
            return [];
        }

        if (empty($permissions['can_view_firma'])) {
            return [$firmaId];
        }

        $db = Database::getInstance();
        $rows = $db->fetchAll("
            WITH AltAgac AS (
                SELECT firma_id FROM Firmalar WHERE firma_id = ?
                UNION ALL
                SELECT f.firma_id FROM Firmalar f
                INNER JOIN AltAgac a ON f.firma_ust_firma_id = a.firma_id
            )
            SELECT firma_id FROM AltAgac
        ", [$firmaId]);

        return array_map('intval', array_column($rows, 'firma_id'));
    }

    /**
     * Firma kapsamı için WHERE parçası üret
     *
     * @param array|null $scopeIds firmaScopeIds() çıktısı
     * @param string $column Filtrelenecek kolon (örn: f.firma_id)
     * @return array [sql, params]
     */
    public static function firmaScopeWhere($scopeIds, $column) {
        if ($scopeIds === null) {
            return ['1=1', []];
        }

        if (empty($scopeIds)) {
            return ['1=0', []];
        }

        $placeholders = implode(',', array_fill(0, count($scopeIds), '?'));
        return ["$column IN ($placeholders)", $scopeIds];
    }

    /**
     * Kullanıcının seçebileceği departman ID listesini döndür
     *
     * Administrator   -> null (kısıtlama yok)
     * Diğer kullanıcı -> kendi departmanı + tüm alt departmanları
     * Departmanı yoksa -> boş dizi (hiçbir departman seçilemez)
     *
     * @param array $permissions checkPagePermissions() çıktısı
     * @param int|null $departmanId Kullanıcının departman_id değeri
     * @return array|null
     */
    public static function departmanScopeIds($permissions, $departmanId) {
        if (!empty($permissions['is_admin'])) {
            return null;
        }

        $departmanId = (int)$departmanId;
        if ($departmanId <= 0) {
            return [];
        }

        $db = Database::getInstance();
        $rows = $db->fetchAll("
            WITH AltAgac AS (
                SELECT departman_id FROM kullanici_Departmanlar WHERE departman_id = ?
                UNION ALL
                SELECT d.departman_id FROM kullanici_Departmanlar d
                INNER JOIN AltAgac a ON d.departman_ust_id = a.departman_id
            )
            SELECT departman_id FROM AltAgac
        ", [$departmanId]);

        return array_map('intval', array_column($rows, 'departman_id'));
    }

    /**
     * Departman kapsamı için WHERE parçası üret
     *
     * @param array|null $scopeIds departmanScopeIds() çıktısı
     * @param string $column Filtrelenecek kolon (örn: d.departman_id)
     * @return array [sql, params]
     */
    public static function departmanScopeWhere($scopeIds, $column) {
        if ($scopeIds === null) {
            return ['1=1', []];
        }

        if (empty($scopeIds)) {
            return ['1=0', []];
        }

        $placeholders = implode(',', array_fill(0, count($scopeIds), '?'));
        return ["$column IN ($placeholders)", $scopeIds];
    }

    /**
     * Yetkisiz erişim durumunda hata sayfası göster
     */
    public static function accessDenied($message = 'Bu sayfaya erişim yetkiniz yok!') {
        http_response_code(403);
        ?>
        <!DOCTYPE html>
        <html lang="tr">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Erişim Engellendi</title>
            <link rel="stylesheet" href="/admin/assets/css/adminlte.min.css">
            <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
        </head>
        <body class="d-flex align-items-center justify-content-center" style="min-height: 100vh; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
            <div class="text-center text-white">
                <i class="bi bi-shield-x" style="font-size: 120px; opacity: 0.8;"></i>
                <h1 class="display-4 mt-4">403 - Erişim Engellendi</h1>
                <p class="lead mb-4"><?= htmlspecialchars($message) ?></p>
                <a href="/admin/anasayfa" class="btn btn-light btn-lg">
                    <i class="bi bi-house-door"></i> Ana Sayfaya Dön
                </a>
            </div>
        </body>
        </html>
        <?php
        exit;
    }
}
