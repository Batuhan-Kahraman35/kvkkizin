<?php
/**
 * WebPush — bağımlılıksız web push motoru
 * VAPID JWT (ES256) + RFC 8291 (aes128gcm) şifreleme, tamamen openssl ile.
 *
 * Kullanım:
 *   $wp = new WebPush($vapidPublic, $vapidPrivatePem, $vapidSubject);
 *   $sonuc = $wp->gonder($endpoint, $p256dh, $auth, ['baslik'=>'...','govde'=>'...','url'=>'/admin/']);
 */
class WebPush
{
    private string $vapidPublic;   // base64url (65 byte uncompressed point)
    private string $vapidPrivate;  // PEM (EC private key)
    private string $vapidSubject;  // mailto:... veya https://...
    private string $opensslCnf;

    public function __construct(string $vapidPublic, string $vapidPrivatePem, string $vapidSubject)
    {
        $this->vapidPublic  = $vapidPublic;
        $this->vapidPrivate = $vapidPrivatePem;
        $this->vapidSubject = $vapidSubject;
        $this->opensslCnf   = __DIR__ . '/openssl.cnf';
    }

    /**
     * Tek cihaza push gönderir.
     * @return array{success:bool, http_code:int, message:string}
     */
    public function gonder(string $endpoint, string $p256dh, string $auth, array $veri): array
    {
        try {
            $payload = json_encode([
                'baslik' => $veri['baslik'] ?? 'Bildirim',
                'govde'  => $veri['govde'] ?? '',
                'url'    => $veri['url'] ?? '/admin/',
            ], JSON_UNESCAPED_UNICODE);

            $sifreli = $this->sifrele($payload, $p256dh, $auth);
            $jwt     = $this->vapidJwt($endpoint);

            $ch = curl_init($endpoint);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $sifreli,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/octet-stream',
                    'Content-Encoding: aes128gcm',
                    'Content-Length: ' . strlen($sifreli),
                    'TTL: 86400',
                    'Urgency: normal',
                    'Authorization: vapid t=' . $jwt . ', k=' . $this->vapidPublic,
                ],
            ]);
            $yanit = curl_exec($ch);
            $kod   = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $hata  = curl_error($ch);
            curl_close($ch);

            if ($kod >= 200 && $kod < 300) {
                return ['success' => true, 'http_code' => $kod, 'message' => 'Gönderildi'];
            }
            return ['success' => false, 'http_code' => $kod, 'message' => $hata ?: (string)$yanit];
        } catch (Throwable $e) {
            return ['success' => false, 'http_code' => 0, 'message' => $e->getMessage()];
        }
    }

    // ---------------------------------------------------------------
    // RFC 8291 — aes128gcm şifreleme
    // ---------------------------------------------------------------
    private function sifrele(string $payload, string $p256dhB64, string $authB64): string
    {
        $uaPublic   = self::b64uDecode($p256dhB64); // 65 byte abone public key
        $authSecret = self::b64uDecode($authB64);   // 16 byte

        if (strlen($uaPublic) !== 65) {
            throw new RuntimeException('Geçersiz p256dh anahtarı');
        }

        // Geçici (as) ECDH anahtar çifti üret
        $asKey = openssl_pkey_new([
            'curve_name'       => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'config'           => $this->opensslCnf,
        ]);
        if ($asKey === false) {
            throw new RuntimeException('openssl_pkey_new başarısız: ' . openssl_error_string());
        }

        $detay    = openssl_pkey_get_details($asKey);
        $asPublic = "\x04"
            . str_pad($detay['ec']['x'], 32, "\x00", STR_PAD_LEFT)
            . str_pad($detay['ec']['y'], 32, "\x00", STR_PAD_LEFT);

        // Abonenin raw public anahtarını PEM'e çevir → ECDH paylaşımlı sır
        $uaPem  = self::rawPublicToPem($uaPublic);
        $shared = openssl_pkey_derive(openssl_pkey_get_public($uaPem), $asKey, 32);
        if ($shared === false) {
            throw new RuntimeException('ECDH türetme başarısız: ' . openssl_error_string());
        }

        // HKDF zinciri (RFC 8291)
        $ikm  = hash_hkdf('sha256', $shared, 32, 'WebPush: info' . "\x00" . $uaPublic . $asPublic, $authSecret);
        $salt = random_bytes(16);
        $cek  = hash_hkdf('sha256', $ikm, 16, 'Content-Encoding: aes128gcm' . "\x00", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, 'Content-Encoding: nonce' . "\x00", $salt);

        // Kayıt: payload + 0x02 (son kayıt ayracı)
        $tag = '';
        $sifreli = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
        if ($sifreli === false) {
            throw new RuntimeException('AES-GCM şifreleme başarısız');
        }

        // Gövde başlığı: salt(16) + rs(4) + idlen(1) + as_public(65)
        $baslik = $salt . pack('N', 4096) . chr(65) . $asPublic;

        return $baslik . $sifreli . $tag;
    }

    // ---------------------------------------------------------------
    // VAPID JWT (ES256)
    // ---------------------------------------------------------------
    private function vapidJwt(string $endpoint): string
    {
        $u   = parse_url($endpoint);
        $aud = $u['scheme'] . '://' . $u['host'];

        $header  = self::b64uEncode(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $payload = self::b64uEncode(json_encode([
            'aud' => $aud,
            'exp' => time() + 12 * 3600,
            'sub' => $this->vapidSubject,
        ]));

        $veri = $header . '.' . $payload;

        $pkey = openssl_pkey_get_private($this->vapidPrivate);
        if ($pkey === false) {
            throw new RuntimeException('VAPID private key okunamadı');
        }

        $imzaDer = '';
        if (!openssl_sign($veri, $imzaDer, $pkey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('JWT imzalama başarısız');
        }

        return $veri . '.' . self::b64uEncode(self::derToJose($imzaDer));
    }

    /** DER (SEQUENCE of two INTEGERs) → JOSE (R||S, 64 byte) */
    private static function derToJose(string $der): string
    {
        $poz = 2; // SEQUENCE başlığını atla
        if (ord($der[1]) & 0x80) {
            $poz += ord($der[1]) & 0x7F;
        }

        $oku = function () use ($der, &$poz) {
            if (ord($der[$poz]) !== 0x02) {
                throw new RuntimeException('DER ayrıştırma hatası');
            }
            $len = ord($der[$poz + 1]);
            $poz += 2;
            $deger = substr($der, $poz, $len);
            $poz += $len;
            // Baştaki 0x00 dolgusunu at, 32 byte'a sabitle
            $deger = ltrim($deger, "\x00");
            return str_pad($deger, 32, "\x00", STR_PAD_LEFT);
        };

        $r = $oku();
        $s = $oku();
        return $r . $s;
    }

    /** Raw 65-byte P-256 public key → PEM */
    private static function rawPublicToPem(string $raw): string
    {
        // SPKI DER başlığı (id-ecPublicKey + prime256v1 + BIT STRING)
        $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $raw;
        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }

    public static function b64uEncode(string $veri): string
    {
        return rtrim(strtr(base64_encode($veri), '+/', '-_'), '=');
    }

    public static function b64uDecode(string $veri): string
    {
        return base64_decode(strtr($veri, '-_', '+/'));
    }
}
