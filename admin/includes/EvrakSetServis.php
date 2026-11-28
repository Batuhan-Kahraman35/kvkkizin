<?php
/**
 * Evrak seti servis sınıfı
 *
 * Firma bilgilerinden (Firmalar) ve formdan gelen değerlerle DOCX şablonlarını
 * doldurur; tek evrakta DOCX, birden fazlasında ZIP üretir. Üretilen dosyalar
 * belgeler/evrak-setleri/YYYY/MM altında saklanır, Firma_EvrakSetleri'ne kaydedilir.
 */

require_once __DIR__ . '/BelgeHelper.php';
require_once __DIR__ . '/EvrakSablonHelper.php';

class EvrakSetServis {

    const SABLON_MAX_MB = 10;

    /* ------------------------------------------------------------------ */
    /* Tanımlar                                                            */
    /* ------------------------------------------------------------------ */

    public static function degiskenler($db) {
        return $db->fetchAll("
            SELECT evrak_degisken_id, evrak_degisken_kod, evrak_degisken_etiket, evrak_degisken_kaynak,
                   evrak_degisken_kaynak_alan, evrak_degisken_yedek_alan, evrak_degisken_bicim,
                   evrak_degisken_zorunlu, evrak_degisken_sira_no
            FROM dbo.tanim_EvrakDegiskenleri
            WHERE Durum = 1
            ORDER BY evrak_degisken_sira_no, evrak_degisken_etiket
        ");
    }

    /**
     * Şablonlar + her şablonda kullanılan değişkenler (dosya taranır)
     */
    public static function sablonlar($db, $yalnizAktif = true) {
        $sablonlar = $db->fetchAll("
            SELECT s.evrak_sablon_id, s.evrak_sablon_kod, s.evrak_sablon_adi, s.evrak_sablon_aciklama,
                   s.evrak_sablon_dosya_yolu, s.evrak_sablon_cikti_adi, s.evrak_sablon_sira_no, s.Durum,
                   CONVERT(VARCHAR(19), ISNULL(s.GuncellemeTarihi, s.OlusturmaTarihi), 120) AS son_islem,
                   (SELECT COUNT(*) FROM dbo.Firma_EvrakSetleri e
                     WHERE e.Durum = 1 AND e.evrak_set_sablonlar LIKE '%\"kod\":\"' + s.evrak_sablon_kod + '\"%') AS kullanim
            FROM dbo.tanim_EvrakSablonlari s
            " . ($yalnizAktif ? "WHERE s.Durum = 1" : "") . "
            ORDER BY s.evrak_sablon_sira_no, s.evrak_sablon_adi
        ");
        foreach ($sablonlar as &$s) {
            $yol = $s['evrak_sablon_dosya_yolu'] ? BelgeHelper::tamYol($s['evrak_sablon_dosya_yolu']) : null;
            $s['dosya_var'] = $yol !== null ? 1 : 0;
            $s['dosya_boyut'] = $yol !== null ? filesize($yol) : 0;
            try {
                $s['degiskenler'] = $yol !== null ? EvrakSablonHelper::degiskenler($yol) : [];
            } catch (Exception $e) {
                $s['degiskenler'] = [];
                $s['dosya_var'] = 0;
            }
            unset($s['evrak_sablon_dosya_yolu']);
        }
        unset($s);
        return $sablonlar;
    }

    /* ------------------------------------------------------------------ */
    /* Değer çözümleme                                                     */
    /* ------------------------------------------------------------------ */

    /**
     * Firmanın değişken değerleri ve eksikler
     *
     * @return array ['firma' => [...], 'degerler' => [kod => deger], 'detay' => [...]]
     */
    public static function firmaDegerleri($db, $firmaId, array $formDegerleri = []) {
        $firma = $db->fetchOne("SELECT * FROM dbo.Firmalar WHERE firma_id = ?", [(int)$firmaId]);
        if (!$firma) {
            throw new Exception('Firma bulunamadı.');
        }

        $degerler = [];
        $detay = [];
        foreach (self::degiskenler($db) as $d) {
            $kod = $d['evrak_degisken_kod'];
            $deger = '';
            if ($d['evrak_degisken_kaynak'] === 'firma') {
                $alan = $d['evrak_degisken_kaynak_alan'];
                $yedek = $d['evrak_degisken_yedek_alan'];
                if ($alan && array_key_exists($alan, $firma)) {
                    $deger = trim((string)$firma[$alan]);
                }
                if ($deger === '' && $yedek && array_key_exists($yedek, $firma)) {
                    $deger = trim((string)$firma[$yedek]);
                }
            } else {
                $deger = trim((string)($formDegerleri[$kod] ?? ''));
                if ($deger !== '' && $d['evrak_degisken_bicim'] === 'tarih') {
                    $t = DateTime::createFromFormat('Y-m-d', $deger);
                    if (!$t || $t->format('Y-m-d') !== $deger) {
                        throw new Exception($d['evrak_degisken_etiket'] . ' geçerli bir tarih değil.');
                    }
                    $deger = $t->format('d.m.Y');
                }
            }
            $degerler[$kod] = $deger;
            $detay[] = [
                'kod'     => $kod,
                'etiket'  => $d['evrak_degisken_etiket'],
                'kaynak'  => $d['evrak_degisken_kaynak'],
                'bicim'   => $d['evrak_degisken_bicim'],
                'zorunlu' => (int)$d['evrak_degisken_zorunlu'],
                'deger'   => $deger,
            ];
        }

        return [
            'firma' => [
                'firma_id'  => (int)$firma['firma_id'],
                'firma_adi' => $firma['firma_adi'],
            ],
            'degerler' => $degerler,
            'detay'    => $detay,
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Üretim                                                              */
    /* ------------------------------------------------------------------ */

    public static function hazirla($db, $firmaScopeIds, $permissions, $userId, $post) {
        if (empty($permissions['can_add'])) {
            return ['success' => false, 'message' => 'Evrak hazırlama yetkiniz yok.'];
        }

        $firmaId = (int)($post['firma_id'] ?? 0);
        if ($firmaId <= 0 || !self::kapsamda($firmaScopeIds, $firmaId)) {
            return ['success' => false, 'message' => 'Firma seçimi geçersiz.'];
        }

        $sablonIds = array_values(array_unique(array_filter(array_map('intval', (array)($post['sablon_ids'] ?? [])))));
        if (!$sablonIds) {
            return ['success' => false, 'message' => 'En az bir evrak seçin.'];
        }

        $yerTutucu = implode(',', array_fill(0, count($sablonIds), '?'));
        $sablonlar = $db->fetchAll("
            SELECT evrak_sablon_id, evrak_sablon_kod, evrak_sablon_adi, evrak_sablon_dosya_yolu, evrak_sablon_cikti_adi
            FROM dbo.tanim_EvrakSablonlari
            WHERE Durum = 1 AND evrak_sablon_id IN ($yerTutucu)
            ORDER BY evrak_sablon_sira_no, evrak_sablon_adi
        ", $sablonIds);
        if (count($sablonlar) !== count($sablonIds)) {
            return ['success' => false, 'message' => 'Seçilen evraklardan biri bulunamadı veya pasif.'];
        }

        $cozum = self::firmaDegerleri($db, $firmaId, (array)($post['deger'] ?? []));
        $degerler = $cozum['degerler'];
        $etiketler = array_column($cozum['detay'], 'etiket', 'kod');
        $zorunlular = array_column($cozum['detay'], 'zorunlu', 'kod');

        // Şablon dosyaları ve kullanılan değişkenlerin dolu olması
        $eksikler = [];
        foreach ($sablonlar as &$s) {
            $s['tam_yol'] = $s['evrak_sablon_dosya_yolu'] ? BelgeHelper::tamYol($s['evrak_sablon_dosya_yolu']) : null;
            if ($s['tam_yol'] === null) {
                return ['success' => false, 'message' => $s['evrak_sablon_adi'] . ' şablon dosyası bulunamadı.'];
            }
            foreach (EvrakSablonHelper::degiskenler($s['tam_yol']) as $kod) {
                if (!array_key_exists($kod, $degerler)) {
                    return ['success' => false, 'message' => $s['evrak_sablon_adi'] . " şablonunda tanımsız değişken var: {{" . $kod . "}}"];
                }
                if ($degerler[$kod] === '' && !empty($zorunlular[$kod])) {
                    $eksikler[$kod] = $etiketler[$kod];
                }
            }
        }
        unset($s);
        if ($eksikler) {
            return [
                'success' => false,
                'message' => 'Eksik bilgi: ' . implode(', ', $eksikler) . '. Firma Yönetimi\'nden tamamlayın.',
                'eksikler' => array_keys($eksikler),
            ];
        }

        // Geçici klasörde üret
        $gecici = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'evrak_' . bin2hex(random_bytes(8));
        if (!@mkdir($gecici, 0700, true)) {
            throw new Exception('Geçici klasör oluşturulamadı.');
        }

        try {
            $uretilen = [];
            $kullanilanAdlar = [];
            foreach ($sablonlar as $s) {
                $ad = EvrakSablonHelper::dosyaAdi($s['evrak_sablon_cikti_adi'], $degerler);
                $aday = $ad;
                $n = 2;
                while (isset($kullanilanAdlar[mb_strtolower($aday)])) {
                    $aday = $ad . ' (' . $n++ . ')';
                }
                $kullanilanAdlar[mb_strtolower($aday)] = true;

                $hedef = $gecici . DIRECTORY_SEPARATOR . count($uretilen) . '.docx';
                EvrakSablonHelper::doldur($s['tam_yol'], $hedef, $degerler);
                $uretilen[] = ['yol' => $hedef, 'ad' => $aday . '.docx'];
            }

            [$goreliKlasor, $fizikselKlasor] = self::klasorHazirla();

            if (count($uretilen) === 1) {
                $dosyaAdi = $uretilen[0]['ad'];
                $kayitAdi = BelgeHelper::dosyaAdiUret('docx');
                if (!rename($uretilen[0]['yol'], $fizikselKlasor . DIRECTORY_SEPARATOR . $kayitAdi)) {
                    throw new Exception('Evrak kaydedilemedi.');
                }
            } else {
                $dosyaAdi = EvrakSablonHelper::dosyaAdi('{{firma_adi}} - Evrak Seti - ' . date('d.m.Y'), $degerler) . '.zip';
                $kayitAdi = BelgeHelper::dosyaAdiUret('zip');
                $zip = new ZipArchive();
                if ($zip->open($fizikselKlasor . DIRECTORY_SEPARATOR . $kayitAdi, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                    throw new Exception('ZIP dosyası oluşturulamadı.');
                }
                foreach ($uretilen as $u) {
                    $zip->addFile($u['yol'], $u['ad']);
                }
                $zip->close();
            }

            $goreliYol = $goreliKlasor . '/' . $kayitAdi;
            $boyut = (int)filesize($fizikselKlasor . DIRECTORY_SEPARATOR . $kayitAdi);

            $id = $db->insert('dbo.Firma_EvrakSetleri', [
                'evrak_set_firma_id'    => $firmaId,
                'evrak_set_sablonlar'   => json_encode(array_map(function ($s) {
                    return ['id' => (int)$s['evrak_sablon_id'], 'kod' => $s['evrak_sablon_kod'], 'adi' => $s['evrak_sablon_adi']];
                }, $sablonlar), JSON_UNESCAPED_UNICODE),
                'evrak_set_degerler'    => json_encode($degerler, JSON_UNESCAPED_UNICODE),
                'evrak_set_dosya_yolu'  => $goreliYol,
                'evrak_set_dosya_adi'   => $dosyaAdi,
                'evrak_set_dosya_boyut' => $boyut,
                'OlusturanKullanici'    => (int)$userId,
            ]);
        } finally {
            foreach (glob($gecici . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($gecici);
        }

        return [
            'success'      => true,
            'message'      => count($uretilen) . ' evrak hazırlandı.',
            'evrak_set_id' => (int)$id,
        ];
    }

    private static function klasorHazirla() {
        $goreli = 'evrak-setleri/' . date('Y') . '/' . date('m');
        $fiziksel = BelgeHelper::kokDizin() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $goreli);
        if (!is_dir($fiziksel) && !@mkdir($fiziksel, 0775, true) && !is_dir($fiziksel)) {
            throw new Exception('Evrak klasörü oluşturulamadı.');
        }
        return [$goreli, $fiziksel];
    }

    /* ------------------------------------------------------------------ */
    /* Geçmiş                                                              */
    /* ------------------------------------------------------------------ */

    private static function filtre($firmaScopeIds, $post) {
        [$sql, $params] = PageAuth::firmaScopeWhere($firmaScopeIds, 'e.evrak_set_firma_id');
        $sql = "e.Durum = 1 AND $sql";

        if (!empty($post['firma_id'])) {
            $sql .= " AND e.evrak_set_firma_id = ?";
            $params[] = (int)$post['firma_id'];
        }
        if (!empty($post['sablon_kod']) && preg_match('/^[A-Z0-9_]{1,50}$/', $post['sablon_kod'])) {
            $sql .= " AND e.evrak_set_sablonlar LIKE ?";
            $params[] = '%"kod":"' . $post['sablon_kod'] . '"%';
        }
        if (!empty($post['olusturan_id'])) {
            $sql .= " AND e.OlusturanKullanici = ?";
            $params[] = (int)$post['olusturan_id'];
        }
        foreach (['tarih_bas' => '>=', 'tarih_bit' => '<'] as $alan => $op) {
            $t = !empty($post[$alan]) ? DateTime::createFromFormat('Y-m-d', $post[$alan]) : false;
            if ($t) {
                if ($alan === 'tarih_bit') {
                    $t->modify('+1 day');
                }
                $sql .= " AND e.OlusturmaTarihi $op ?";
                $params[] = $t->format('Y-m-d');
            }
        }
        $ara = trim((string)($post['search'] ?? ''));
        if ($ara !== '') {
            $sql .= " AND (e.evrak_set_dosya_adi LIKE ? OR EXISTS (SELECT 1 FROM dbo.Firmalar f2 WHERE f2.firma_id = e.evrak_set_firma_id AND (f2.firma_adi LIKE ? OR f2.firma_vkn_tc LIKE ?)))";
            $like = '%' . $ara . '%';
            array_push($params, $like, $like, $like);
        }
        return [$sql, $params];
    }

    public static function listele($db, $firmaScopeIds, $post) {
        $draw   = (int)($post['draw'] ?? 1);
        $start  = max(0, (int)($post['start'] ?? 0));
        $length = (int)($post['length'] ?? 25);
        if ($length <= 0 || $length > 500) {
            $length = 25;
        }

        $kolonlar = [
            0 => 'f.firma_adi',
            2 => 'e.evrak_set_dosya_boyut',
            4 => 'e.OlusturmaTarihi',
        ];
        $kolon = $kolonlar[(int)($post['order'][0]['column'] ?? 4)] ?? 'e.OlusturmaTarihi';
        $yon = strtolower($post['order'][0]['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';
        $firmaJoin = $kolon === 'f.firma_adi' ? 'INNER JOIN dbo.Firmalar f ON f.firma_id = e.evrak_set_firma_id' : '';

        [$scopeSql, $scopeParams] = PageAuth::firmaScopeWhere($firmaScopeIds, 'e.evrak_set_firma_id');
        [$filtreSql, $filtreParams] = self::filtre($firmaScopeIds, $post);

        $toplam = (int)($db->fetchOne("SELECT COUNT(*) AS sayi FROM dbo.Firma_EvrakSetleri e WHERE e.Durum = 1 AND $scopeSql", $scopeParams)['sayi'] ?? 0);
        $filtreli = (int)($db->fetchOne("SELECT COUNT(*) AS sayi FROM dbo.Firma_EvrakSetleri e WHERE $filtreSql", $filtreParams)['sayi'] ?? 0);

        $satirlar = $db->fetchAll("
            WITH Sayfa AS (
                SELECT e.evrak_set_id,
                       ROW_NUMBER() OVER (ORDER BY $kolon $yon, e.evrak_set_id DESC) AS sira
                FROM dbo.Firma_EvrakSetleri e
                $firmaJoin
                WHERE $filtreSql
                ORDER BY $kolon $yon, e.evrak_set_id DESC
                OFFSET ? ROWS FETCH NEXT ? ROWS ONLY
            )
            SELECT e.evrak_set_id, e.evrak_set_firma_id AS firma_id, f.firma_adi, f.firma_vkn_tc,
                   e.evrak_set_sablonlar, e.evrak_set_dosya_adi, e.evrak_set_dosya_boyut,
                   CONVERT(VARCHAR(19), e.OlusturmaTarihi, 120) AS tarih,
                   NULLIF(LTRIM(RTRIM(ISNULL(k.kullanici_ad, '') + ' ' + ISNULL(k.kullanici_soyad, ''))), '') AS olusturan
            FROM Sayfa p
            INNER JOIN dbo.Firma_EvrakSetleri e ON e.evrak_set_id = p.evrak_set_id
            INNER JOIN dbo.Firmalar f ON f.firma_id = e.evrak_set_firma_id
            LEFT JOIN dbo.kullanicilar k ON k.kullanici_id = e.OlusturanKullanici
            ORDER BY p.sira
        ", array_merge($filtreParams, [$start, $length]));

        foreach ($satirlar as &$s) {
            $s['sablonlar'] = json_decode($s['evrak_set_sablonlar'] ?? '[]', true) ?: [];
            unset($s['evrak_set_sablonlar']);
        }
        unset($s);

        return ['draw' => $draw, 'recordsTotal' => $toplam, 'recordsFiltered' => $filtreli, 'data' => $satirlar];
    }

    public static function istatistik($db, $firmaScopeIds) {
        [$scopeSql, $scopeParams] = PageAuth::firmaScopeWhere($firmaScopeIds, 'e.evrak_set_firma_id');
        $ozet = $db->fetchOne("
            SELECT COUNT(*) AS toplam,
                   SUM(CASE WHEN e.OlusturmaTarihi >= DATEFROMPARTS(YEAR(GETDATE()), MONTH(GETDATE()), 1) THEN 1 ELSE 0 END) AS bu_ay,
                   COUNT(DISTINCT e.evrak_set_firma_id) AS firma
            FROM dbo.Firma_EvrakSetleri e
            WHERE e.Durum = 1 AND $scopeSql
        ", $scopeParams);
        $sablon = $db->fetchOne("SELECT COUNT(*) AS sayi FROM dbo.tanim_EvrakSablonlari WHERE Durum = 1");
        return [
            'toplam' => (int)($ozet['toplam'] ?? 0),
            'bu_ay'  => (int)($ozet['bu_ay'] ?? 0),
            'firma'  => (int)($ozet['firma'] ?? 0),
            'sablon' => (int)($sablon['sayi'] ?? 0),
        ];
    }

    public static function olusturanlar($db, $firmaScopeIds) {
        [$scopeSql, $scopeParams] = PageAuth::firmaScopeWhere($firmaScopeIds, 'e.evrak_set_firma_id');
        return $db->fetchAll("
            SELECT k.kullanici_id, LTRIM(RTRIM(ISNULL(k.kullanici_ad, '') + ' ' + ISNULL(k.kullanici_soyad, ''))) AS ad
            FROM dbo.kullanicilar k
            WHERE k.kullanici_id IN (SELECT DISTINCT e.OlusturanKullanici FROM dbo.Firma_EvrakSetleri e WHERE e.Durum = 1 AND $scopeSql)
            ORDER BY ad
        ", $scopeParams);
    }

    public static function getir($db, $firmaScopeIds, $id) {
        $kayit = $db->fetchOne("SELECT * FROM dbo.Firma_EvrakSetleri WHERE evrak_set_id = ? AND Durum = 1", [(int)$id]);
        if (!$kayit || !self::kapsamda($firmaScopeIds, $kayit['evrak_set_firma_id'])) {
            return null;
        }
        return $kayit;
    }

    public static function sil($db, $firmaScopeIds, $permissions, $userId, $id) {
        if (empty($permissions['can_delete'])) {
            return ['success' => false, 'message' => 'Silme yetkiniz yok.'];
        }
        $kayit = self::getir($db, $firmaScopeIds, $id);
        if (!$kayit) {
            return ['success' => false, 'message' => 'Kayıt bulunamadı.'];
        }
        BelgeHelper::dosyaSil($kayit['evrak_set_dosya_yolu']);
        $db->execute("
            UPDATE dbo.Firma_EvrakSetleri
            SET Durum = 0, GuncelleyenKullanici = ?, GuncellemeTarihi = GETDATE()
            WHERE evrak_set_id = ?
        ", [(int)$userId, (int)$kayit['evrak_set_id']]);
        return ['success' => true, 'message' => 'Evrak seti silindi.'];
    }

    /* ------------------------------------------------------------------ */
    /* Şablon yönetimi                                                     */
    /* ------------------------------------------------------------------ */

    public static function sablonGetir($db, $id) {
        return $db->fetchOne("SELECT * FROM dbo.tanim_EvrakSablonlari WHERE evrak_sablon_id = ?", [(int)$id]);
    }

    public static function sablonKaydet($db, $permissions, $userId, $post, $files) {
        $id = (int)($post['evrak_sablon_id'] ?? 0);
        if (($id > 0 && empty($permissions['can_edit'])) || ($id === 0 && empty($permissions['can_add']))) {
            return ['success' => false, 'message' => 'Bu işlem için yetkiniz yok.'];
        }

        $kod = strtoupper(trim((string)($post['evrak_sablon_kod'] ?? '')));
        $adi = trim((string)($post['evrak_sablon_adi'] ?? ''));
        $aciklama = trim((string)($post['evrak_sablon_aciklama'] ?? ''));
        $ciktiAdi = trim((string)($post['evrak_sablon_cikti_adi'] ?? ''));
        $sira = (int)($post['evrak_sablon_sira_no'] ?? 0);
        $durum = !empty($post['Durum']) ? 1 : 0;

        if (!preg_match('/^[A-Z0-9_]{2,50}$/', $kod)) {
            return ['success' => false, 'message' => 'Kod 2-50 karakter; büyük harf, rakam ve alt çizgi içermeli.'];
        }
        if ($adi === '' || mb_strlen($adi) > 150) {
            return ['success' => false, 'message' => 'Şablon adı zorunludur (en fazla 150 karakter).'];
        }
        if ($ciktiAdi === '' || mb_strlen($ciktiAdi) > 200) {
            return ['success' => false, 'message' => 'Çıktı dosya adı zorunludur (en fazla 200 karakter).'];
        }
        if (mb_strlen($aciklama) > 500) {
            return ['success' => false, 'message' => 'Açıklama en fazla 500 karakter olabilir.'];
        }

        $cakisan = $db->fetchOne("SELECT evrak_sablon_id FROM dbo.tanim_EvrakSablonlari WHERE evrak_sablon_kod = ? AND evrak_sablon_id <> ?", [$kod, $id]);
        if ($cakisan) {
            return ['success' => false, 'message' => 'Bu kod başka bir şablonda kullanılıyor.'];
        }

        $mevcut = $id > 0 ? self::sablonGetir($db, $id) : null;
        if ($id > 0 && !$mevcut) {
            return ['success' => false, 'message' => 'Şablon bulunamadı.'];
        }

        // Dosya
        $yeniYol = null;
        $dosya = $files['sablon_dosya'] ?? null;
        $dosyaGeldi = $dosya && ($dosya['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        if (!$dosyaGeldi && !$mevcut) {
            return ['success' => false, 'message' => 'Şablon dosyası (DOCX) seçin.'];
        }
        if ($dosyaGeldi) {
            if ($dosya['error'] !== UPLOAD_ERR_OK) {
                return ['success' => false, 'message' => BelgeHelper::yuklemeHatasi($dosya['error'])];
            }
            if (strtolower(pathinfo($dosya['name'], PATHINFO_EXTENSION)) !== 'docx') {
                return ['success' => false, 'message' => 'Yalnızca DOCX dosyası yüklenebilir.'];
            }
            if ($dosya['size'] > self::SABLON_MAX_MB * 1024 * 1024) {
                return ['success' => false, 'message' => 'Dosya en fazla ' . self::SABLON_MAX_MB . ' MB olabilir.'];
            }
            $zip = new ZipArchive();
            if ($zip->open($dosya['tmp_name']) !== true || $zip->locateName('word/document.xml') === false) {
                return ['success' => false, 'message' => 'Dosya geçerli bir Word (DOCX) belgesi değil.'];
            }
            $zip->close();

            // Tanımsız değişken kontrolü
            $tanimli = array_column(self::degiskenler($db), 'evrak_degisken_kod');
            $tanimsiz = array_diff(EvrakSablonHelper::degiskenler($dosya['tmp_name']), $tanimli);
            if ($tanimsiz) {
                return ['success' => false, 'message' => 'Şablonda tanımsız değişken var: {{' . implode('}}, {{', $tanimsiz) . '}}'];
            }

            $klasor = BelgeHelper::kokDizin() . DIRECTORY_SEPARATOR . 'sablonlar';
            if (!is_dir($klasor) && !@mkdir($klasor, 0775, true) && !is_dir($klasor)) {
                throw new Exception('Şablon klasörü oluşturulamadı.');
            }
            $kayitAdi = strtolower(str_replace('_', '-', $kod)) . '-' . bin2hex(random_bytes(4)) . '.docx';
            if (!move_uploaded_file($dosya['tmp_name'], $klasor . DIRECTORY_SEPARATOR . $kayitAdi)) {
                throw new Exception('Şablon dosyası kaydedilemedi.');
            }
            $yeniYol = 'sablonlar/' . $kayitAdi;
        }

        $veri = [
            'evrak_sablon_kod'       => $kod,
            'evrak_sablon_adi'       => $adi,
            'evrak_sablon_aciklama'  => $aciklama !== '' ? $aciklama : null,
            'evrak_sablon_cikti_adi' => $ciktiAdi,
            'evrak_sablon_sira_no'   => $sira,
            'Durum'                  => $durum,
        ];
        if ($yeniYol) {
            $veri['evrak_sablon_dosya_yolu'] = $yeniYol;
        }

        if ($mevcut) {
            $veri['GuncelleyenKullanici'] = (int)$userId;
            $set = implode(', ', array_map(function ($k) { return "$k = ?"; }, array_keys($veri)));
            $db->execute("UPDATE dbo.tanim_EvrakSablonlari SET $set, GuncellemeTarihi = GETDATE() WHERE evrak_sablon_id = ?",
                array_merge(array_values($veri), [$id]));
            if ($yeniYol && $mevcut['evrak_sablon_dosya_yolu']) {
                BelgeHelper::dosyaSil($mevcut['evrak_sablon_dosya_yolu']);
            }
            return ['success' => true, 'message' => 'Şablon güncellendi.'];
        }

        $veri['OlusturanKullanici'] = (int)$userId;
        $db->insert('dbo.tanim_EvrakSablonlari', $veri);
        return ['success' => true, 'message' => 'Şablon eklendi.'];
    }

    public static function sablonSil($db, $permissions, $userId, $id) {
        if (empty($permissions['can_delete'])) {
            return ['success' => false, 'message' => 'Silme yetkiniz yok.'];
        }
        $s = self::sablonGetir($db, $id);
        if (!$s) {
            return ['success' => false, 'message' => 'Şablon bulunamadı.'];
        }
        // Geçmiş kayıtları şablonu adıyla tuttuğu için kayıt pasife alınır, dosya korunur
        $db->execute("
            UPDATE dbo.tanim_EvrakSablonlari
            SET Durum = 0, GuncelleyenKullanici = ?, GuncellemeTarihi = GETDATE()
            WHERE evrak_sablon_id = ?
        ", [(int)$userId, (int)$id]);
        return ['success' => true, 'message' => 'Şablon pasife alındı.'];
    }

    /* ------------------------------------------------------------------ */

    public static function kapsamda($firmaScopeIds, $firmaId) {
        return $firmaScopeIds === null || in_array((int)$firmaId, $firmaScopeIds, true);
    }

    /**
     * Dosyayı indirme olarak gönderir
     */
    public static function gonder($tamYol, $indirmeAdi, $mime) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($tamYol));
        header('Content-Disposition: attachment; filename="' . rawurlencode($indirmeAdi) . '"; filename*=UTF-8\'\'' . rawurlencode($indirmeAdi));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=0, no-cache');
        header('Pragma: no-cache');
        readfile($tamYol);
        exit;
    }
}
