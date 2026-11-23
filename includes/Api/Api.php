<?php
/**
 * İzin API çekirdeği (ornek-kvkk.com/api/...)
 *
 * - Anahtar:   Authorization: Bearer kvk_...  (yalnız SHA-256 hash'i saklanır)
 * - IP:        anahtarda IP listesi varsa çağıran IP listede olmalı (tek IP veya IPv4 CIDR)
 * - Sınır:     anahtar başına dakikada api_dakika_limit istek (Api_Istek_Loglari'ndan sayılır)
 * - Log:       her istek Api_Istek_Loglari'na maskeli yazılır
 * - Biçim:     {"basarili":true,"veri":...}  /  {"basarili":false,"hata":{"kod","mesaj"}}
 */

require_once __DIR__ . '/../../admin/db.php';
require_once __DIR__ . '/../Ayarlar.php';

class ApiHata extends Exception
{
    /** Hata kodu => HTTP durum kodu (API sözleşmesinin parçası, dokümanla aynı) */
    const KODLAR = [
        'GOVDE_GECERSIZ'     => 400,
        'ANAHTAR_GECERSIZ'   => 401,
        'IP_YETKISIZ'        => 403,
        'YOL_BULUNAMADI'     => 404,
        'FORM_BULUNAMADI'    => 404,
        'BASVURU_BULUNAMADI' => 404,
        'YONTEM_GECERSIZ'    => 405,
        'METIN_DEGISTI'      => 409,
        'IPTAL_EDILEMEZ'     => 409,
        'KOD_GECERSIZ'       => 410,
        'ALAN_HATASI'        => 422,
        'KOD_HATALI'         => 422,
        'COK_FAZLA_ISTEK'    => 429,
        'SUNUCU_HATASI'      => 500,
        'SMS_GONDERILEMEDI'  => 503,
    ];

    public $kod;
    public $http;
    public $ek;
    public $basliklar;

    /**
     * @param string $kod  KODLAR içindeki hata kodu
     * @param array  $ek   yanıttaki hata nesnesine eklenecek alanlar (ör. alanlar => [...])
     */
    public function __construct($kod, $mesaj, array $ek = [], array $basliklar = [])
    {
        parent::__construct($mesaj);
        $this->kod = isset(self::KODLAR[$kod]) ? $kod : 'SUNUCU_HATASI';
        $this->http = self::KODLAR[$this->kod];
        $this->ek = $ek;
        $this->basliklar = $basliklar;
    }
}

class Api
{
    /** Log'da değeri maskelenecek anahtarlar (her seviyede) */
    const MASKELI = ['telefon', 'cep_telefonu', 'tc', 'tc_kimlik', 'tc_kimlik_no', 'email', 'eposta',
                     'ad', 'soyad', 'ad_soyad', 'adres', 'dogum_tarihi', 'kod', 'tarayici'];

    const ANAHTAR_DESENI = '/^kvk_[a-f0-9]{48}$/';

    /** @var Database */
    private $db;
    private $baslangic;
    private $metot;
    private $yol;
    private $hamGovde = '';
    private $govde = null;

    /** @var array|null doğrulanan anahtar satırı */
    private $anahtar = null;

    private $httpKod = 200;
    private $hataKod = null;
    private $logYanit = null;
    private $logBasvuruId = null;

    public function __construct()
    {
        $this->baslangic = hrtime(true);
        $this->db = Database::getInstance();
        ini_set('display_errors', '0');   // JSON çıktısı bozulmasın (site Debug Modu açık olsa bile)

        $this->metot = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = $_SERVER['HTTP_X_ORIGINAL_URL'] ?? ($_SERVER['REQUEST_URI'] ?? '/');
        $yol = rawurldecode((string)parse_url($uri, PHP_URL_PATH));
        $this->yol = trim(preg_replace('#^/api(?=/|$)#i', '', $yol), '/');
    }

    /* ---------------------------------------------------------------------
     * Yönlendirme
     * ------------------------------------------------------------------- */

    /**
     * @param array $rotalar [metot, regex (api/ sonrası yol), callable(Api, array $parametreler), ['acik' => true]?]
     *                       Callable veri döner; [veri, http] dizisi dönerse durum kodu da verilir.
     */
    public function calistir(array $rotalar)
    {
        try {
            $eslesen = null;
            $yolVar = false;
            foreach ($rotalar as $r) {
                if (preg_match($r[1], $this->yol, $m)) {
                    $yolVar = true;
                    if ($r[0] === $this->metot) {
                        $eslesen = [$r, array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY)];
                        break;
                    }
                }
            }
            if (!$eslesen) {
                throw $yolVar
                    ? new ApiHata('YONTEM_GECERSIZ', "Bu adres {$this->metot} yöntemini desteklemiyor.")
                    : new ApiHata('YOL_BULUNAMADI', 'Adres bulunamadı.');
            }

            [$rota, $parametreler] = $eslesen;
            if (empty($rota[3]['acik'])) {
                $this->anahtarDogrula();
            }

            $sonuc = call_user_func($rota[2], $this, $parametreler);
            if (is_array($sonuc) && array_key_exists('__http', $sonuc)) {
                $this->gonder($sonuc['veri'], $sonuc['__http']);
            } else {
                $this->gonder($sonuc, 200);
            }
        } catch (ApiHata $h) {
            $this->hataGonder($h);
        } catch (MetinDegistiException $e) {
            $this->hataGonder(new ApiHata('METIN_DEGISTI', $e->getMessage()));
        } catch (Throwable $e) {
            error_log('API hatası [' . $this->metot . ' /api/' . $this->yol . ']: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            $this->hataGonder(new ApiHata('SUNUCU_HATASI', 'Beklenmeyen bir hata oluştu.'));
        } finally {
            $this->logYaz();
        }
    }

    /** Uç noktanın 200 dışında bir durum koduyla dönmesi için: return Api::durum($veri, 201); */
    public static function durum($veri, $http)
    {
        return ['__http' => (int)$http, 'veri' => $veri];
    }

    /* ---------------------------------------------------------------------
     * İstek bilgileri (uç noktalar için)
     * ------------------------------------------------------------------- */

    public function firmaId()
    {
        return (int)$this->anahtar['api_anahtar_firma_id'];
    }

    public function anahtarId()
    {
        return (int)$this->anahtar['api_anahtar_id'];
    }

    public function anahtar()
    {
        return $this->anahtar;
    }

    /** JSON gövde (dizi). Boş gövde → []. */
    public function govde()
    {
        if ($this->govde !== null) {
            return $this->govde;
        }
        $this->hamGovde = (string)file_get_contents('php://input');
        if (trim($this->hamGovde) === '') {
            return $this->govde = [];
        }
        $veri = json_decode($this->hamGovde, true);
        if (!is_array($veri)) {
            throw new ApiHata('GOVDE_GECERSIZ', 'İstek gövdesi geçerli bir JSON nesnesi değil.');
        }
        return $this->govde = $veri;
    }

    /** Sorgu parametresi (string) */
    public function sorgu($ad, $varsayilan = null)
    {
        $d = $_GET[$ad] ?? null;
        return is_string($d) && trim($d) !== '' ? trim($d) : $varsayilan;
    }

    /** Log satırını başvuruyla ilişkilendirir */
    public function logBasvuru($basvuruId)
    {
        $this->logBasvuruId = $basvuruId ? (int)$basvuruId : null;
    }

    public static function istemciIp()
    {
        return mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    }

    /* ---------------------------------------------------------------------
     * Anahtar, IP, sınır
     * ------------------------------------------------------------------- */

    private function anahtarDogrula()
    {
        $ip = self::istemciIp();
        $ham = $this->anahtarOku();

        if ($ham === null || !preg_match(self::ANAHTAR_DESENI, $ham)) {
            $this->basarisizSinir($ip);
            throw new ApiHata('ANAHTAR_GECERSIZ', 'API anahtarı eksik veya geçersiz.');
        }

        $satir = $this->db->fetchOne("
            SELECT a.api_anahtar_id, a.api_anahtar_firma_id, a.api_anahtar_adi, a.api_anahtar_ip_listesi,
                   a.api_anahtar_son_ip
            FROM dbo.Api_Anahtarlari a
            INNER JOIN dbo.Firmalar f ON f.firma_id = a.api_anahtar_firma_id AND f.firma_durum = 1
            WHERE a.api_anahtar_hash = ? AND a.Durum = 1 AND a.api_anahtar_iptal_tarihi IS NULL
        ", [hash('sha256', $ham)]);

        if (!$satir) {
            $this->basarisizSinir($ip);
            throw new ApiHata('ANAHTAR_GECERSIZ', 'API anahtarı eksik veya geçersiz.');
        }
        $this->anahtar = $satir;

        if (!self::ipIzinli($ip, (string)$satir['api_anahtar_ip_listesi'])) {
            throw new ApiHata('IP_YETKISIZ', "Bu anahtar $ip adresinden kullanılamaz.");
        }

        $limit = Ayarlar::sayi('api_dakika_limit', $this->firmaId());
        $sayi = (int)($this->db->fetchOne("
            SELECT COUNT(*) AS n FROM dbo.Api_Istek_Loglari
            WHERE api_log_anahtar_id = ? AND api_log_tarih >= DATEADD(SECOND, -60, GETDATE()) AND api_log_http_kod <> 429
        ", [$this->anahtarId()])['n'] ?? 0);
        if ($sayi >= $limit) {
            throw new ApiHata('COK_FAZLA_ISTEK', "Dakikada en fazla $limit istek gönderilebilir.", [], ['Retry-After' => '60']);
        }

        // Son kullanım: aynı IP'den dakikada en fazla bir kez güncellenir
        $this->db->execute("
            UPDATE dbo.Api_Anahtarlari
            SET api_anahtar_son_kullanim = GETDATE(), api_anahtar_son_ip = ?
            WHERE api_anahtar_id = ?
              AND (api_anahtar_son_kullanim IS NULL OR api_anahtar_son_kullanim < DATEADD(MINUTE, -1, GETDATE())
                   OR ISNULL(api_anahtar_son_ip, '') <> ?)
        ", [$ip, $this->anahtarId(), $ip]);
    }

    /** Geçersiz anahtar denemeleri: IP başına dakikada api_dakika_limit (genel değer) */
    private function basarisizSinir($ip)
    {
        $limit = Ayarlar::sayi('api_dakika_limit');
        $sayi = (int)($this->db->fetchOne("
            SELECT COUNT(*) AS n FROM dbo.Api_Istek_Loglari
            WHERE api_log_anahtar_id IS NULL AND api_log_ip = ? AND api_log_tarih >= DATEADD(SECOND, -60, GETDATE())
              AND api_log_http_kod <> 429
        ", [$ip])['n'] ?? 0);
        if ($sayi >= $limit) {
            throw new ApiHata('COK_FAZLA_ISTEK', 'Çok fazla hatalı deneme. Bir dakika sonra tekrar deneyin.', [], ['Retry-After' => '60']);
        }
    }

    private function anahtarOku()
    {
        $baslik = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if ($baslik === '' && function_exists('getallheaders')) {
            foreach (getallheaders() as $ad => $deger) {
                if (strcasecmp($ad, 'Authorization') === 0) {
                    $baslik = $deger;
                    break;
                }
            }
        }
        if (preg_match('/^Bearer\s+(\S+)$/i', trim((string)$baslik), $m)) {
            return $m[1];
        }
        return null;
    }

    /** $liste: virgül / satır sonu ile ayrılmış IP veya IPv4 CIDR; boşsa her IP serbest */
    public static function ipIzinli($ip, $liste)
    {
        $girdiler = array_filter(array_map('trim', preg_split('/[\s,;]+/', $liste)));
        if (!$girdiler) {
            return true;
        }
        foreach ($girdiler as $g) {
            if (strpos($g, '/') === false) {
                if (inet_pton($g) !== false && inet_pton($g) === @inet_pton($ip)) {
                    return true;
                }
                continue;
            }
            [$ag, $bit] = explode('/', $g, 2);
            if (!filter_var($ag, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
                || !ctype_digit($bit) || (int)$bit > 32) {
                continue;
            }
            $maske = (int)$bit === 0 ? 0 : (~0 << (32 - (int)$bit)) & 0xFFFFFFFF;
            if ((ip2long($ip) & $maske) === (ip2long($ag) & $maske)) {
                return true;
            }
        }
        return false;
    }

    /* ---------------------------------------------------------------------
     * Yanıt ve log
     * ------------------------------------------------------------------- */

    private function gonder($veri, $http)
    {
        $this->httpKod = (int)$http;
        $this->cikti(['basarili' => true, 'veri' => $veri]);
    }

    private function hataGonder(ApiHata $h)
    {
        $this->httpKod = $h->http;
        $this->hataKod = $h->kod;
        foreach ($h->basliklar as $ad => $deger) {
            header("$ad: $deger");
        }
        $govde = ['basarili' => false, 'hata' => array_merge(['kod' => $h->kod, 'mesaj' => $h->getMessage()], $h->ek)];
        $this->logYanit = mb_substr(json_encode($govde['hata'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 1000);
        $this->cikti($govde);
    }

    private function cikti(array $govde)
    {
        http_response_code($this->httpKod);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($govde, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function logYaz()
    {
        try {
            if ($this->hamGovde === '' && in_array($this->metot, ['POST', 'PUT', 'PATCH'], true)) {
                $this->hamGovde = (string)file_get_contents('php://input');
            }
            $istek = null;
            if (trim($this->hamGovde) !== '') {
                $veri = json_decode($this->hamGovde, true);
                $istek = is_array($veri)
                    ? json_encode(self::maskele($veri), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    : '(JSON değil, ' . strlen($this->hamGovde) . ' bayt)';
            }

            $sorgu = $_GET;
            $yol = '/api/' . $this->yol;
            if ($sorgu) {
                $yol .= '?' . http_build_query(self::maskele($sorgu));
            }

            $this->db->execute("
                INSERT INTO dbo.Api_Istek_Loglari
                    (api_log_anahtar_id, api_log_firma_id, api_log_metot, api_log_yol, api_log_http_kod, api_log_hata_kod,
                     api_log_sure_ms, api_log_ip, api_log_istek, api_log_yanit, api_log_basvuru_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ", [
                $this->anahtar ? $this->anahtarId() : null,
                $this->anahtar ? $this->firmaId() : null,
                mb_substr($this->metot, 0, 10),
                mb_substr($yol, 0, 300),
                $this->httpKod,
                $this->hataKod,
                (int)((hrtime(true) - $this->baslangic) / 1e6),
                self::istemciIp(),
                $istek,
                $this->logYanit,
                $this->logBasvuruId,
            ]);
        } catch (Throwable $e) {
            error_log('API log yazılamadı: ' . $e->getMessage());
        }
    }

    /** Kişisel veri anahtarlarının değerini ve 'alanlar' altındaki tüm değerleri maskeler */
    public static function maskele(array $veri, $hepsi = false)
    {
        foreach ($veri as $k => $v) {
            $gizle = $hepsi || in_array(strtolower((string)$k), self::MASKELI, true);
            if (is_array($v)) {
                $veri[$k] = self::maskele($v, $gizle || $k === 'alanlar');
            } elseif ($gizle && $v !== null && !is_bool($v)) {
                $veri[$k] = $k === 'kod' ? '***' : self::maske((string)$v);
            }
        }
        return $veri;
    }

    private static function maske($s)
    {
        $n = mb_strlen($s);
        if ($n <= 2) {
            return str_repeat('*', $n);
        }
        if ($n <= 4) {
            return mb_substr($s, 0, 1) . str_repeat('*', $n - 1);
        }
        return mb_substr($s, 0, 1) . str_repeat('*', $n - 3) . mb_substr($s, -2);
    }
}
