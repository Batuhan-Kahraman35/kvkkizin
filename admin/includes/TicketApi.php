<?php
/**
 * Destek (Ticket) API Helper
 * Örnek Yazılım Destek Sistemi entegrasyonu (destek.ornekyazilim.com)
 *
 * Ayarlar veritabanından okunur:
 *   - Entegrasyonlar (entegrasyon_kod = 'destek') → entegrasyon_ayarlar JSON → BaseURL
 *   - EntegrasyonKanallari (varsayılan aktif kanal) → kanal_ayarlar JSON → ApiKey
 *
 * Tüm API çağrıları EntegrasyonLoglari tablosuna loglanır.
 */

class TicketApi {
    private $db;
    private $config = [];
    private $entegrasyonId = null;
    private $kanalId = null;
    private $userId = null;
    private $hata = '';

    public function __construct($userId = null) {
        $this->db = Database::getInstance();
        $this->userId = $userId;
        $this->configYukle();
    }

    /**
     * Entegrasyon + kanal ayarlarını DB'den yükle ve birleştir
     */
    private function configYukle() {
        $row = $this->db->fetchOne("
            SELECT TOP 1
                e.entegrasyon_id,
                e.entegrasyon_ayarlar,
                k.kanal_id,
                k.kanal_ayarlar
            FROM Entegrasyonlar e
            INNER JOIN EntegrasyonKanallari k ON k.kanal_entegrasyon_id = e.entegrasyon_id
            WHERE e.entegrasyon_kod = 'destek'
              AND e.entegrasyon_durum = 1
              AND k.kanal_durum = 1
            ORDER BY k.kanal_varsayilan DESC, k.kanal_id ASC
        ");

        if (!$row) {
            $this->hata = 'Destek entegrasyonu tanımı bulunamadı. Entegrasyon Yönetimi sayfasını kontrol edin.';
            return;
        }

        $this->entegrasyonId = (int)$row['entegrasyon_id'];
        $this->kanalId = (int)$row['kanal_id'];

        $entAyar   = json_decode($row['entegrasyon_ayarlar'] ?? '{}', true) ?: [];
        $kanalAyar = json_decode($row['kanal_ayarlar'] ?? '{}', true) ?: [];
        $this->config = array_merge($entAyar, $kanalAyar);

        if (empty($this->config['BaseURL'])) {
            $this->hata = 'Destek entegrasyonu ayarlarında BaseURL tanımlı değil.';
        } elseif (empty($this->config['ApiKey'])) {
            $this->hata = 'Destek entegrasyon kanalı ayarlarında ApiKey tanımlı değil.';
        }
    }

    public function hazirMi() {
        return $this->hata === '';
    }

    public function getHata() {
        return $this->hata;
    }

    /**
     * API çağrısı yap (JSON veya dosyalı multipart) ve sonucu logla
     *
     * @param array $payload API payload (action zorunlu)
     * @param array $files   $_FILES['dosyalar'] formatında dosya dizisi (opsiyonel)
     * @return array API yanıtı (success/message/data)
     */
    public function call(array $payload, array $files = []) {
        if (!$this->hazirMi()) {
            return ['success' => false, 'message' => $this->hata];
        }

        $url = $this->config['BaseURL'];
        $headers = ['X-API-KEY: ' . $this->config['ApiKey']];
        $dosyaAdlari = [];

        // Dosya varsa multipart, yoksa JSON gönder
        if (!empty($files) && isset($files['name']) && is_array($files['name'])) {
            $postData = [];
            foreach ($payload as $key => $value) {
                $postData[$key] = is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : $value;
            }

            $count = count($files['name']);
            for ($i = 0; $i < $count; $i++) {
                if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
                $postData["dosyalar[$i]"] = new CURLFile(
                    $files['tmp_name'][$i],
                    $files['type'][$i],
                    $files['name'][$i]
                );
                $dosyaAdlari[] = $files['name'][$i];
            }
        } else {
            $postData = json_encode($payload, JSON_UNESCAPED_UNICODE);
            $headers[] = 'Content-Type: application/json';
        }

        $start = microtime(true);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => $postData,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            // ModSecurity (Plesk WAF) User-Agent'sız istekleri 403 ile engelliyor
            CURLOPT_USERAGENT      => 'KVKKizinPortal-TicketApi/1.0'
        ]);
        $res     = curl_exec($ch);
        $curlErr = curl_error($ch);
        $httpKod = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $sureMs = (int)round((microtime(true) - $start) * 1000);

        // Sonucu değerlendir
        if ($res === false) {
            $sonuc = ['success' => false, 'message' => 'API bağlantı hatası: ' . ($curlErr ?: 'Bilinmeyen hata')];
        } else {
            $data = json_decode($res, true);
            if (is_array($data)) {
                $sonuc = $data;
            } elseif ($httpKod < 200 || $httpKod >= 300) {
                $sonuc = ['success' => false, 'message' => 'API HTTP hatası: ' . $httpKod . ' — Yanıt: ' . mb_substr($res, 0, 200)];
            } else {
                $sonuc = ['success' => false, 'message' => 'Geçersiz API yanıtı (JSON parse hatası). Ham yanıt: ' . mb_substr($res, 0, 200)];
            }
        }

        $this->logla($payload, $dosyaAdlari, $sonuc, $httpKod, $sureMs, $res);

        return $sonuc;
    }

    /**
     * API çağrısını EntegrasyonLoglari tablosuna kaydet
     * Base64/dosya içerikleri loglanmaz, sadece dosya adları yazılır.
     */
    private function logla(array $payload, array $dosyaAdlari, array $sonuc, $httpKod, $sureMs, $hamYanit) {
        try {
            $logIstek = $payload;
            unset($logIstek['ekler']); // base64 içerik loglanmaz
            if (!empty($dosyaAdlari)) {
                $logIstek['dosyalar'] = $dosyaAdlari;
            }

            $this->db->insert('EntegrasyonLoglari', [
                'log_entegrasyon_id'          => $this->entegrasyonId,
                'log_kanal_id'                => $this->kanalId,
                'log_islem'                   => $payload['action'] ?? 'bilinmiyor',
                'log_istek'                   => json_encode($logIstek, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'log_yanit'                   => mb_substr(is_string($hamYanit) ? $hamYanit : json_encode($sonuc, JSON_UNESCAPED_UNICODE), 0, 4000),
                'log_http_kod'                => $httpKod ?: null,
                'log_sure_ms'                 => $sureMs,
                'log_basarili'                => ($sonuc['success'] ?? false) ? 1 : 0,
                'log_olusturan_kullanici_id'  => $this->userId,
                'log_tarih'                   => date('Y-m-d H:i:s')
            ]);
        } catch (Exception $e) {
            error_log('TicketApi log hatası: ' . $e->getMessage());
        }
    }

    /**
     * Oturumdaki kullanıcının API için kullanici nesnesini hazırla
     * (kaynak_kullanici_id + ad/soyad/eposta/telefon)
     */
    public function kullaniciNesnesi($kullaniciId) {
        $k = $this->db->fetchOne("
            SELECT kullanici_id, kullanici_ad, kullanici_soyad, kullanici_email, kullanici_telefon
            FROM kullanicilar
            WHERE kullanici_id = ?
        ", [$kullaniciId]);

        if (!$k) return null;

        $nesne = [
            'kaynak_kullanici_id' => (string)$k['kullanici_id'],
            'ad'     => $k['kullanici_ad'] ?? '',
            'soyad'  => $k['kullanici_soyad'] ?? '',
            'eposta' => $k['kullanici_email'] ?? ''
        ];
        if (!empty($k['kullanici_telefon'])) {
            $nesne['telefon'] = $k['kullanici_telefon'];
        }

        return $nesne;
    }
}
