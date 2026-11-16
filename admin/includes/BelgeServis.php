<?php
/**
 * Firma Belgeleri Servisi
 *
 * Belge listeleme, yükleme, onaylama ve silme mantığını tek yerde toplar.
 * Hem Firma Belgeleri sayfası hem de Firma Yönetimi içindeki belge modalı kullanır.
 */

require_once __DIR__ . '/PageAuth.php';
require_once __DIR__ . '/BelgeHelper.php';

class BelgeServis {

    /**
     * Firma kullanıcının kapsamında mı?
     */
    public static function kapsamdaMi($firmaScopeIds, $firmaId) {
        if ($firmaScopeIds === null) {
            return true;
        }
        return in_array((int)$firmaId, $firmaScopeIds, true);
    }

    /**
     * Firma listelerine eklenecek belge özeti alt sorguları
     * Firmalar tablosunun "f" takma adıyla kullanıldığı sorgulara eklenir.
     */
    public static function firmaOzetSql() {
        return "
            (SELECT count(*) FROM dbo.Firma_Belgeleri fbs
                WHERE fbs.firma_id = f.firma_id AND fbs.firma_belge_durum = 1) as belge_sayisi,
            (SELECT count(*) FROM dbo.tanim_BelgeTipleri bts
                WHERE bts.belge_tipi_durum = 1 AND bts.belge_tipi_zorunlu = 1
                  AND NOT EXISTS (
                        SELECT 1 FROM dbo.Firma_Belgeleri fbe
                        WHERE fbe.firma_id = f.firma_id
                          AND fbe.belge_tipi_id = bts.belge_tipi_id
                          AND fbe.firma_belge_durum = 1
                  )) as eksik_zorunlu_belge,
            (SELECT count(*) FROM dbo.Firma_Belgeleri fbd
                WHERE fbd.firma_id = f.firma_id AND fbd.firma_belge_durum = 1
                  AND fbd.firma_belge_gecerlilik_bitis IS NOT NULL
                  AND fbd.firma_belge_gecerlilik_bitis <= DATEADD(day, 30, CAST(GETDATE() AS DATE))) as suresi_dolan_belge,
            (SELECT count(*) FROM dbo.Firma_Belgeleri fbb
                INNER JOIN dbo.tanim_BelgeOnayDurumlari odb ON fbb.firma_belge_onay_durumu_id = odb.onay_durumu_id
                WHERE fbb.firma_id = f.firma_id AND fbb.firma_belge_durum = 1
                  AND odb.onay_durumu_kod = 'BEKLEMEDE') as bekleyen_belge
        ";
    }

    /**
     * Firma listesinde belge durumu filtresi için WHERE parçası
     *
     * @param string $belgeDurumu eksik | suresi_dolan | bekleyen | tam
     * @return string|null
     */
    public static function firmaOzetFiltreSql($belgeDurumu) {
        switch ($belgeDurumu) {
            case 'eksik':
                return "EXISTS (
                            SELECT 1 FROM dbo.tanim_BelgeTipleri btf
                            WHERE btf.belge_tipi_durum = 1 AND btf.belge_tipi_zorunlu = 1
                              AND NOT EXISTS (
                                    SELECT 1 FROM dbo.Firma_Belgeleri fbf
                                    WHERE fbf.firma_id = f.firma_id
                                      AND fbf.belge_tipi_id = btf.belge_tipi_id
                                      AND fbf.firma_belge_durum = 1
                              )
                        )";
            case 'suresi_dolan':
                return "EXISTS (
                            SELECT 1 FROM dbo.Firma_Belgeleri fbf
                            WHERE fbf.firma_id = f.firma_id AND fbf.firma_belge_durum = 1
                              AND fbf.firma_belge_gecerlilik_bitis IS NOT NULL
                              AND fbf.firma_belge_gecerlilik_bitis <= DATEADD(day, 30, CAST(GETDATE() AS DATE))
                        )";
            case 'bekleyen':
                return "EXISTS (
                            SELECT 1 FROM dbo.Firma_Belgeleri fbf
                            INNER JOIN dbo.tanim_BelgeOnayDurumlari odf ON fbf.firma_belge_onay_durumu_id = odf.onay_durumu_id
                            WHERE fbf.firma_id = f.firma_id AND fbf.firma_belge_durum = 1
                              AND odf.onay_durumu_kod = 'BEKLEMEDE'
                        )";
            case 'tam':
                return "NOT EXISTS (
                            SELECT 1 FROM dbo.tanim_BelgeTipleri btf
                            WHERE btf.belge_tipi_durum = 1 AND btf.belge_tipi_zorunlu = 1
                              AND NOT EXISTS (
                                    SELECT 1 FROM dbo.Firma_Belgeleri fbf
                                    WHERE fbf.firma_id = f.firma_id
                                      AND fbf.belge_tipi_id = btf.belge_tipi_id
                                      AND fbf.firma_belge_durum = 1
                              )
                        )";
            default:
                return null;
        }
    }

    /**
     * Belge listesi
     *
     * @param array $filtreler search, firma_id, belge_tipi_id, onay_durumu_id, gecerlilik, status
     */
    public static function listele($db, $firmaScopeIds, $filtreler = []) {
        [$scopeSql, $scopeParams] = PageAuth::firmaScopeWhere($firmaScopeIds, 'fb.firma_id');

        $whereConditions = [$scopeSql];
        $params = $scopeParams;

        $search       = $filtreler['search'] ?? '';
        $firmaId      = $filtreler['firma_id'] ?? '';
        $belgeTipiId  = $filtreler['belge_tipi_id'] ?? '';
        $onayDurumuId = $filtreler['onay_durumu_id'] ?? '';
        $gecerlilik   = $filtreler['gecerlilik'] ?? '';
        $status       = $filtreler['status'] ?? '';

        if ($search !== '') {
            $whereConditions[] = "(fb.firma_belge_baslik LIKE ? OR fb.firma_belge_dosya_adi LIKE ? OR fb.firma_belge_aciklama LIKE ? OR f.firma_adi LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }

        if ($firmaId !== '') {
            $whereConditions[] = "fb.firma_id = ?";
            $params[] = (int)$firmaId;
        }

        if ($belgeTipiId !== '') {
            $whereConditions[] = "fb.belge_tipi_id = ?";
            $params[] = (int)$belgeTipiId;
        }

        if ($onayDurumuId !== '') {
            $whereConditions[] = "fb.firma_belge_onay_durumu_id = ?";
            $params[] = (int)$onayDurumuId;
        }

        if ($gecerlilik === 'dolmus') {
            $whereConditions[] = "fb.firma_belge_gecerlilik_bitis IS NOT NULL AND fb.firma_belge_gecerlilik_bitis < CAST(GETDATE() AS DATE)";
        } elseif ($gecerlilik === 'yaklasan') {
            $whereConditions[] = "fb.firma_belge_gecerlilik_bitis IS NOT NULL AND fb.firma_belge_gecerlilik_bitis >= CAST(GETDATE() AS DATE) AND fb.firma_belge_gecerlilik_bitis <= DATEADD(day, 30, CAST(GETDATE() AS DATE))";
        } elseif ($gecerlilik === 'gecerli') {
            $whereConditions[] = "(fb.firma_belge_gecerlilik_bitis IS NULL OR fb.firma_belge_gecerlilik_bitis > DATEADD(day, 30, CAST(GETDATE() AS DATE)))";
        }

        if ($status !== '') {
            $whereConditions[] = "fb.firma_belge_durum = ?";
            $params[] = (int)$status;
        }

        $whereClause = implode(" AND ", $whereConditions);

        return $db->fetchAll("
            SELECT
                fb.firma_belge_id,
                fb.firma_id,
                f.firma_adi,
                fb.belge_tipi_id,
                bt.belge_tipi_adi,
                bt.belge_tipi_ikon,
                fb.firma_belge_baslik,
                fb.firma_belge_aciklama,
                fb.firma_belge_dosya_adi,
                fb.firma_belge_dosya_uzanti,
                fb.firma_belge_dosya_boyut,
                CONVERT(VARCHAR(10), fb.firma_belge_gecerlilik_baslangic, 120) as firma_belge_gecerlilik_baslangic,
                CONVERT(VARCHAR(10), fb.firma_belge_gecerlilik_bitis, 120) as firma_belge_gecerlilik_bitis,
                CASE
                    WHEN fb.firma_belge_gecerlilik_bitis IS NULL THEN NULL
                    ELSE DATEDIFF(day, CAST(GETDATE() AS DATE), fb.firma_belge_gecerlilik_bitis)
                END as kalan_gun,
                fb.firma_belge_onay_durumu_id,
                od.onay_durumu_adi,
                od.onay_durumu_renk,
                od.onay_durumu_ikon,
                fb.firma_belge_onay_aciklama,
                CONVERT(VARCHAR(19), fb.firma_belge_onay_tarihi, 120) as firma_belge_onay_tarihi,
                kon.kullanici_ad + ' ' + kon.kullanici_soyad as onaylayan_kullanici,
                fb.firma_belge_durum,
                CONVERT(VARCHAR(19), fb.firma_belge_olusturma_tarihi, 120) as firma_belge_olusturma_tarihi,
                ko.kullanici_ad + ' ' + ko.kullanici_soyad as yukleyen_kullanici
            FROM dbo.Firma_Belgeleri fb
            INNER JOIN dbo.Firmalar f ON fb.firma_id = f.firma_id
            INNER JOIN dbo.tanim_BelgeTipleri bt ON fb.belge_tipi_id = bt.belge_tipi_id
            INNER JOIN dbo.tanim_BelgeOnayDurumlari od ON fb.firma_belge_onay_durumu_id = od.onay_durumu_id
            LEFT JOIN kullanicilar ko ON fb.firma_belge_olusturan_kullanici_id = ko.kullanici_id
            LEFT JOIN kullanicilar kon ON fb.firma_belge_onaylayan_kullanici_id = kon.kullanici_id
            WHERE $whereClause
            ORDER BY fb.firma_belge_olusturma_tarihi DESC
        ", $params);
    }

    /**
     * Aktif belge tipleri
     */
    public static function belgeTipleri($db) {
        return $db->fetchAll("
            SELECT
                belge_tipi_id,
                belge_tipi_adi,
                belge_tipi_kod,
                belge_tipi_zorunlu,
                belge_tipi_coklu_yukleme,
                belge_tipi_gecerlilik_ay,
                belge_tipi_izinli_formatlar,
                belge_tipi_max_boyut_mb
            FROM dbo.tanim_BelgeTipleri
            WHERE belge_tipi_durum = 1
            ORDER BY belge_tipi_sira_no, belge_tipi_adi
        ");
    }

    /**
     * Aktif onay durumları
     */
    public static function onayDurumlari($db) {
        return $db->fetchAll("
            SELECT onay_durumu_id, onay_durumu_adi, onay_durumu_kod, onay_durumu_renk, onay_durumu_ikon, onay_durumu_varsayilan
            FROM dbo.tanim_BelgeOnayDurumlari
            WHERE onay_durumu_durum = 1
            ORDER BY onay_durumu_sira_no, onay_durumu_adi
        ");
    }

    /**
     * Firmanın zorunlu belge durumu (modal özeti için)
     */
    public static function zorunluBelgeDurumu($db, $firmaId) {
        return $db->fetchAll("
            SELECT
                bt.belge_tipi_id,
                bt.belge_tipi_adi,
                bt.belge_tipi_ikon,
                (SELECT count(*) FROM dbo.Firma_Belgeleri fb
                    WHERE fb.firma_id = ? AND fb.belge_tipi_id = bt.belge_tipi_id AND fb.firma_belge_durum = 1) as belge_sayisi
            FROM dbo.tanim_BelgeTipleri bt
            WHERE bt.belge_tipi_durum = 1 AND bt.belge_tipi_zorunlu = 1
            ORDER BY bt.belge_tipi_sira_no, bt.belge_tipi_adi
        ", [(int)$firmaId]);
    }

    /**
     * Tek belge kaydı
     */
    public static function getir($db, $firmaScopeIds, $belgeId) {
        $belge = $db->fetchOne("SELECT * FROM dbo.Firma_Belgeleri WHERE firma_belge_id = ?", [(int)$belgeId]);

        if (!$belge || !self::kapsamdaMi($firmaScopeIds, $belge['firma_id'])) {
            return ['success' => false, 'message' => 'Belge bulunamadı!'];
        }

        foreach (['firma_belge_gecerlilik_baslangic', 'firma_belge_gecerlilik_bitis'] as $alan) {
            if (!empty($belge[$alan])) {
                $belge[$alan] = substr($belge[$alan], 0, 10);
            }
        }

        return ['success' => true, 'data' => $belge];
    }

    /**
     * Belge yükleme / güncelleme
     *
     * @param array $permissions Belge sayfası yetkileri (can_add / can_edit)
     * @return array ['success' => bool, 'message' => string]
     */
    public static function kaydet($db, $firmaScopeIds, $permissions, $userId, $post, $files) {
        $belgeId = (int)($post['firma_belge_id'] ?? 0);

        if ($belgeId > 0 && empty($permissions['can_edit'])) {
            return ['success' => false, 'message' => 'Düzenleme yetkiniz yok!'];
        }
        if ($belgeId == 0 && empty($permissions['can_add'])) {
            return ['success' => false, 'message' => 'Belge yükleme yetkiniz yok!'];
        }

        $firmaId = (int)($post['firma_id'] ?? 0);
        $belgeTipiId = (int)($post['belge_tipi_id'] ?? 0);

        if ($firmaId <= 0 || $belgeTipiId <= 0) {
            return ['success' => false, 'message' => 'Firma ve belge tipi seçilmelidir!'];
        }

        if (!self::kapsamdaMi($firmaScopeIds, $firmaId)) {
            return ['success' => false, 'message' => 'Bu firma için işlem yapma yetkiniz yok!'];
        }

        $firma = $db->fetchOne("SELECT firma_id FROM dbo.Firmalar WHERE firma_id = ?", [$firmaId]);
        if (!$firma) {
            return ['success' => false, 'message' => 'Seçilen firma bulunamadı!'];
        }

        $belgeTipi = $db->fetchOne("SELECT * FROM dbo.tanim_BelgeTipleri WHERE belge_tipi_id = ?", [$belgeTipiId]);
        if (!$belgeTipi) {
            return ['success' => false, 'message' => 'Seçilen belge tipi bulunamadı!'];
        }
        if (!$belgeTipi['belge_tipi_durum']) {
            return ['success' => false, 'message' => 'Pasif bir belge tipi seçilemez!'];
        }

        $mevcut = null;
        if ($belgeId > 0) {
            $mevcut = $db->fetchOne("SELECT * FROM dbo.Firma_Belgeleri WHERE firma_belge_id = ?", [$belgeId]);
            if (!$mevcut || !self::kapsamdaMi($firmaScopeIds, $mevcut['firma_id'])) {
                return ['success' => false, 'message' => 'Belge bulunamadı!'];
            }
        }

        $dosyalar = self::dosyaListesi($files['belge_dosya'] ?? null);
        $dosyaGeldi = count($dosyalar) > 0;

        if ($belgeId == 0 && !$dosyaGeldi) {
            return ['success' => false, 'message' => 'Yüklenecek dosyayı seçin!'];
        }

        if (count($dosyalar) > 1 && $belgeTipi['belge_tipi_coklu_yukleme'] == 0) {
            return ['success' => false, 'message' => 'Bu belge tipi çoklu yüklemeye kapalı. Tek dosya seçin.'];
        }

        $yeniDurum = isset($post['firma_belge_durum']) ? 1 : 0;

        // Çoklu yüklemeye kapalı tiplerde firma başına tek aktif belge
        if ($belgeTipi['belge_tipi_coklu_yukleme'] == 0 && $yeniDurum == 1) {
            $varOlan = $db->fetchOne("
                SELECT count(*) as count
                FROM dbo.Firma_Belgeleri
                WHERE firma_id = ? AND belge_tipi_id = ? AND firma_belge_durum = 1 AND firma_belge_id <> ?
            ", [$firmaId, $belgeTipiId, $belgeId])['count'] ?? 0;

            if ($varOlan > 0) {
                return [
                    'success' => false,
                    'message' => 'Bu belge tipi çoklu yüklemeye kapalı. Firmanın bu tipte zaten aktif bir belgesi var; önce onu pasife alın veya silin.'
                ];
            }
        }

        // Geçerlilik tarihleri
        $baslangic = trim($post['firma_belge_gecerlilik_baslangic'] ?? '') ?: null;
        $bitis     = trim($post['firma_belge_gecerlilik_bitis'] ?? '') ?: null;

        if ($baslangic !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $baslangic)) {
            return ['success' => false, 'message' => 'Geçerlilik başlangıç tarihi hatalı!'];
        }
        if ($bitis !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $bitis)) {
            return ['success' => false, 'message' => 'Geçerlilik bitiş tarihi hatalı!'];
        }

        // Bitiş boşsa belge tipinin geçerlilik süresinden hesapla
        if ($bitis === null && $baslangic !== null && !empty($belgeTipi['belge_tipi_gecerlilik_ay'])) {
            $bitis = date('Y-m-d', strtotime($baslangic . ' +' . (int)$belgeTipi['belge_tipi_gecerlilik_ay'] . ' months'));
        }

        if ($baslangic !== null && $bitis !== null && $bitis < $baslangic) {
            return ['success' => false, 'message' => 'Geçerlilik bitiş tarihi başlangıçtan önce olamaz!'];
        }

        $data = [
            'firma_id'                        => $firmaId,
            'belge_tipi_id'                   => $belgeTipiId,
            'firma_belge_baslik'              => trim($post['firma_belge_baslik'] ?? '') ?: null,
            'firma_belge_aciklama'            => trim($post['firma_belge_aciklama'] ?? '') ?: null,
            'firma_belge_gecerlilik_baslangic'=> $baslangic,
            'firma_belge_gecerlilik_bitis'    => $bitis,
            'firma_belge_durum'               => $yeniDurum,
        ];

        // Dosyaları önce topluca doğrula: biri hatalıysa hiçbiri kaydedilmez
        $hazirlananlar = [];
        foreach ($dosyalar as $dosya) {
            $sonuc = self::dosyaDogrula($db, $belgeTipi, $dosya);
            if (isset($sonuc['hata'])) {
                $onEk = count($dosyalar) > 1 ? $dosya['name'] . ': ' : '';
                return ['success' => false, 'message' => $onEk . $sonuc['hata']];
            }
            $hazirlananlar[] = $sonuc;
        }

        // Dosyaları diske taşı
        $kaydedilenler = [];
        if ($hazirlananlar) {
            [$goreliKlasor, $fizikselKlasor] = BelgeHelper::klasorHazirla();

            foreach ($hazirlananlar as $h) {
                $yeniAd = BelgeHelper::dosyaAdiUret($h['uzanti']);
                $hedef = $fizikselKlasor . DIRECTORY_SEPARATOR . $yeniAd;

                if (!move_uploaded_file($h['tmp_name'], $hedef)) {
                    foreach ($kaydedilenler as $k) {
                        BelgeHelper::dosyaSil($k['firma_belge_dosya_yolu']);
                    }
                    return ['success' => false, 'message' => 'Dosya sunucuya kaydedilemedi: ' . $h['ad']];
                }

                $kaydedilenler[] = [
                    'firma_belge_dosya_adi'    => mb_substr($h['ad'], 0, 255),
                    'firma_belge_dosya_yolu'   => $goreliKlasor . '/' . $yeniAd,
                    'firma_belge_dosya_uzanti' => $h['uzanti'],
                    'firma_belge_dosya_mime'   => $h['mime'],
                    'firma_belge_dosya_boyut'  => $h['boyut'],
                    'firma_belge_dosya_hash'   => hash_file('sha256', $hedef),
                ];
            }
        }

        // Düzenlemede ilk dosya mevcut kaydın yerine geçer, kalanlar yeni belge olur
        $yeniKayitDosyalari = $belgeId > 0 ? array_slice($kaydedilenler, 1) : $kaydedilenler;

        $varsayilan = $db->fetchOne("
            SELECT TOP 1 onay_durumu_id
            FROM dbo.tanim_BelgeOnayDurumlari
            WHERE onay_durumu_varsayilan = 1 AND onay_durumu_durum = 1
            ORDER BY onay_durumu_sira_no
        ");

        if ($yeniKayitDosyalari && !$varsayilan) {
            foreach ($kaydedilenler as $k) {
                BelgeHelper::dosyaSil($k['firma_belge_dosya_yolu']);
            }
            return ['success' => false, 'message' => 'Varsayılan onay durumu tanımlı değil!'];
        }

        $guncellendi = false;
        if ($belgeId > 0) {
            $guncelleData = $data;
            $guncelleData['firma_belge_guncelleme_tarihi'] = date('Y-m-d H:i:s');
            $guncelleData['firma_belge_guncelleyen_kullanici_id'] = $userId;

            $eskiDosyaYolu = null;
            if ($kaydedilenler) {
                $guncelleData = array_merge($guncelleData, $kaydedilenler[0]);
                $eskiDosyaYolu = $mevcut['firma_belge_dosya_yolu'];

                // Dosya değiştiyse belge yeniden onaya düşer
                if ($varsayilan) {
                    $guncelleData['firma_belge_onay_durumu_id'] = $varsayilan['onay_durumu_id'];
                    $guncelleData['firma_belge_onay_aciklama'] = null;
                    $guncelleData['firma_belge_onaylayan_kullanici_id'] = null;
                    $guncelleData['firma_belge_onay_tarihi'] = null;
                }
            }

            $db->update('dbo.Firma_Belgeleri', $guncelleData, ['firma_belge_id' => $belgeId]);

            if ($eskiDosyaYolu) {
                BelgeHelper::dosyaSil($eskiDosyaYolu);
            }
            $guncellendi = true;
        }

        // Her dosya ayrı belge kaydı olur
        $data['firma_belge_olusturma_tarihi'] = date('Y-m-d H:i:s');
        $data['firma_belge_olusturan_kullanici_id'] = $userId;
        if ($varsayilan) {
            $data['firma_belge_onay_durumu_id'] = $varsayilan['onay_durumu_id'];
        }

        $eklenen = 0;
        try {
            foreach ($yeniKayitDosyalari as $k) {
                $db->insert('dbo.Firma_Belgeleri', array_merge($data, $k));
                $eklenen++;
            }
        } catch (Exception $e) {
            // Kaydı açılamayan dosyaları diskten kaldır
            foreach (array_slice($yeniKayitDosyalari, $eklenen) as $k) {
                BelgeHelper::dosyaSil($k['firma_belge_dosya_yolu']);
            }
            $basarili = ($guncellendi ? 1 : 0) + $eklenen;
            return [
                'success' => false,
                'message' => $basarili > 0
                    ? $basarili . ' belge kaydedildi, kalanlar kaydedilemedi: ' . $e->getMessage()
                    : 'Belge kaydedilemedi: ' . $e->getMessage()
            ];
        }

        if ($guncellendi) {
            $mesaj = $eklenen > 0
                ? 'Belge güncellendi, ' . $eklenen . ' yeni belge eklendi'
                : 'Belge başarıyla güncellendi';
        } else {
            $mesaj = $eklenen > 1 ? $eklenen . ' belge başarıyla yüklendi' : 'Belge başarıyla yüklendi';
        }

        return ['success' => true, 'message' => $mesaj];
    }

    /**
     * $_FILES alanını (tekli veya name="x[]" çoklu) düz listeye çevirir.
     * Boş bırakılan alanlar atlanır.
     */
    private static function dosyaListesi($alan) {
        if (!is_array($alan) || !isset($alan['name'])) {
            return [];
        }

        $liste = [];
        if (is_array($alan['name'])) {
            foreach ($alan['name'] as $i => $ad) {
                $liste[] = [
                    'name'     => $ad,
                    'type'     => $alan['type'][$i] ?? '',
                    'tmp_name' => $alan['tmp_name'][$i] ?? '',
                    'error'    => $alan['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                    'size'     => $alan['size'][$i] ?? 0,
                ];
            }
        } else {
            $liste[] = $alan;
        }

        return array_values(array_filter($liste, fn($d) => $d['error'] !== UPLOAD_ERR_NO_FILE));
    }

    /**
     * Tek dosyayı belge tipi kurallarına göre doğrular
     *
     * @return array ['hata' => string] veya dosya bilgileri
     */
    private static function dosyaDogrula($db, $belgeTipi, $dosya) {
        if ($dosya['error'] !== UPLOAD_ERR_OK) {
            return ['hata' => BelgeHelper::yuklemeHatasi($dosya['error'])];
        }

        if (!is_uploaded_file($dosya['tmp_name'])) {
            return ['hata' => 'Geçersiz dosya yüklemesi!'];
        }

        $uzanti = strtolower(pathinfo($dosya['name'], PATHINFO_EXTENSION));

        $izinliler = array_filter(array_map('trim', explode(',', strtolower($belgeTipi['belge_tipi_izinli_formatlar'] ?? ''))));
        if (!in_array($uzanti, $izinliler, true)) {
            return ['hata' => 'Bu belge tipi için izin verilen formatlar: ' . strtoupper(implode(', ', $izinliler))];
        }

        $maxByte = (int)$belgeTipi['belge_tipi_max_boyut_mb'] * 1024 * 1024;
        if ($dosya['size'] > $maxByte) {
            return ['hata' => 'Dosya boyutu ' . $belgeTipi['belge_tipi_max_boyut_mb'] . ' MB sınırını aşıyor.'];
        }

        $gercekMime = BelgeHelper::mimeTipi($dosya['tmp_name']);
        $formatKaydi = $db->fetchOne("
            SELECT dosya_formati_mime
            FROM dbo.tanim_DosyaFormatlari
            WHERE dosya_formati_uzanti = ? AND dosya_formati_durum = 1
        ", [$uzanti]);

        if (!$formatKaydi) {
            return ['hata' => 'Bu dosya formatı sistemde tanımlı değil!'];
        }

        if ($gercekMime !== null && !empty($formatKaydi['dosya_formati_mime'])
            && strcasecmp($gercekMime, $formatKaydi['dosya_formati_mime']) !== 0) {
            return ['hata' => 'Dosya içeriği uzantısıyla uyuşmuyor (' . $gercekMime . '). Yükleme reddedildi.'];
        }

        return [
            'ad'       => $dosya['name'],
            'tmp_name' => $dosya['tmp_name'],
            'uzanti'   => $uzanti,
            'mime'     => $gercekMime ?: ($formatKaydi['dosya_formati_mime'] ?: null),
            'boyut'    => (int)$dosya['size'],
        ];
    }

    /**
     * Onay durumu güncelleme
     */
    public static function onayKaydet($db, $firmaScopeIds, $permissions, $userId, $post) {
        if (empty($permissions['can_edit'])) {
            return ['success' => false, 'message' => 'Onay işlemi yetkiniz yok!'];
        }

        $belgeId = (int)($post['firma_belge_id'] ?? 0);
        $onayDurumuId = (int)($post['firma_belge_onay_durumu_id'] ?? 0);
        $onayAciklama = trim($post['firma_belge_onay_aciklama'] ?? '') ?: null;

        $belge = $db->fetchOne("SELECT firma_id FROM dbo.Firma_Belgeleri WHERE firma_belge_id = ?", [$belgeId]);
        if (!$belge || !self::kapsamdaMi($firmaScopeIds, $belge['firma_id'])) {
            return ['success' => false, 'message' => 'Belge bulunamadı!'];
        }

        $durum = $db->fetchOne("
            SELECT onay_durumu_id, onay_durumu_kod
            FROM dbo.tanim_BelgeOnayDurumlari
            WHERE onay_durumu_id = ? AND onay_durumu_durum = 1
        ", [$onayDurumuId]);

        if (!$durum) {
            return ['success' => false, 'message' => 'Geçersiz onay durumu!'];
        }

        // Red ve revizyon durumlarında gerekçe zorunlu
        if (in_array($durum['onay_durumu_kod'], ['REDDEDILDI', 'REVIZYON'], true) && $onayAciklama === null) {
            return ['success' => false, 'message' => 'Bu durum için açıklama girmelisiniz!'];
        }

        $db->update('dbo.Firma_Belgeleri', [
            'firma_belge_onay_durumu_id'           => $onayDurumuId,
            'firma_belge_onay_aciklama'            => $onayAciklama,
            'firma_belge_onaylayan_kullanici_id'   => $userId,
            'firma_belge_onay_tarihi'              => date('Y-m-d H:i:s'),
            'firma_belge_guncelleme_tarihi'        => date('Y-m-d H:i:s'),
            'firma_belge_guncelleyen_kullanici_id' => $userId,
        ], ['firma_belge_id' => $belgeId]);

        return ['success' => true, 'message' => 'Onay durumu güncellendi'];
    }

    /**
     * Belge silme (dosya diskten de kaldırılır)
     */
    public static function sil($db, $firmaScopeIds, $permissions, $belgeId) {
        if (empty($permissions['can_delete'])) {
            return ['success' => false, 'message' => 'Silme yetkiniz yok!'];
        }

        $belgeId = (int)$belgeId;
        $belge = $db->fetchOne("SELECT firma_id, firma_belge_dosya_yolu FROM dbo.Firma_Belgeleri WHERE firma_belge_id = ?", [$belgeId]);

        if (!$belge || !self::kapsamdaMi($firmaScopeIds, $belge['firma_id'])) {
            return ['success' => false, 'message' => 'Belge bulunamadı!'];
        }

        $db->delete('dbo.Firma_Belgeleri', ['firma_belge_id' => $belgeId]);
        BelgeHelper::dosyaSil($belge['firma_belge_dosya_yolu']);

        return ['success' => true, 'message' => 'Belge başarıyla silindi'];
    }

    /**
     * Belge istatistikleri
     */
    public static function istatistik($db, $firmaScopeIds) {
        [$scopeSql, $scopeParams] = PageAuth::firmaScopeWhere($firmaScopeIds, 'fb.firma_id');

        return [
            'toplam' => $db->fetchOne("
                SELECT count(*) as count FROM dbo.Firma_Belgeleri fb WHERE $scopeSql
            ", $scopeParams)['count'] ?? 0,

            'bekleyen' => $db->fetchOne("
                SELECT count(*) as count
                FROM dbo.Firma_Belgeleri fb
                INNER JOIN dbo.tanim_BelgeOnayDurumlari od ON fb.firma_belge_onay_durumu_id = od.onay_durumu_id
                WHERE od.onay_durumu_kod = 'BEKLEMEDE' AND $scopeSql
            ", $scopeParams)['count'] ?? 0,

            'onayli' => $db->fetchOne("
                SELECT count(*) as count
                FROM dbo.Firma_Belgeleri fb
                INNER JOIN dbo.tanim_BelgeOnayDurumlari od ON fb.firma_belge_onay_durumu_id = od.onay_durumu_id
                WHERE od.onay_durumu_kod = 'ONAYLANDI' AND $scopeSql
            ", $scopeParams)['count'] ?? 0,

            'suresi_dolan' => $db->fetchOne("
                SELECT count(*) as count
                FROM dbo.Firma_Belgeleri fb
                WHERE fb.firma_belge_gecerlilik_bitis IS NOT NULL
                  AND fb.firma_belge_gecerlilik_bitis <= DATEADD(day, 30, CAST(GETDATE() AS DATE))
                  AND fb.firma_belge_durum = 1
                  AND $scopeSql
            ", $scopeParams)['count'] ?? 0,
        ];
    }
}
