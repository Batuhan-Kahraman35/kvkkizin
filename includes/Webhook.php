<?php
/**
 * Webhook (bildirim) gönderimi
 *
 * Akış: olay() kuyruğa yazar (Api_Webhook_Gonderimleri) → cron "webhook_gonder" her dakika kuyruguIsle() çalıştırır.
 * İstek içinde gönderim yapılmaz; müşterinin yavaş adresi formu / API yanıtını bekletmez.
 *
 * Gövde (kişisel veri yok; ayrıntı GET /api/basvurular/{id} ile alınır):
 *   {"id": "whk_...", "olay": "basvuru.onaylandi", "tarih": "2026-11-23T09:19:17+03:00",
 *    "veri": {"basvuru_id": 2384, "form": "284369590932", "durum": "onaylandi", "kaynak": "api"}}
 *
 * Başlıklar:
 *   X-KVKK-Olay    : olay kodu
 *   X-KVKK-Teslim  : gövdedeki id (tekrar denemelerde aynı kalır; alıcı çift işlemeyi bununla önler)
 *   X-KVKK-Zaman   : unix zaman damgası (her denemede yenilenir)
 *   X-KVKK-Imza    : "sha256=" + HMAC-SHA256(Zaman + "." + gövde, gizli anahtar)
 *
 * Tekrar: ilk deneme + webhook_tekrar_dk listesindeki aralıklar (varsayılan 1, 10, 60 dk).
 * Art arda webhook_pasif_hata başarısız denemede adres pasife alınır.
 * Gizli anahtar Api_Webhooklari.webhook_gizli_anahtar'da Sifreleme ile, bağlam 'webhook:<firma_id>'.
 */

require_once __DIR__ . '/../admin/db.php';
require_once __DIR__ . '/Ayarlar.php';
require_once __DIR__ . '/Sifreleme.php';

class Webhook
{
    const KIRALAMA_DK = 5;          // işlenmek üzere alınan satır bu süre kilitli kalır (çökme olursa yeniden denenir)

    /**
     * Olayı firmanın bu olaya abone adreslerinin kuyruğuna yazar. Hata fırlatmaz (kayıt akışını bozmamalı).
     * @return int kuyruğa yazılan gönderim sayısı
     */
    public static function olay($olayKod, $firmaId, $basvuruId, array $veri)
    {
        try {
            $db = Database::getInstance();
            $hedefler = $db->fetchAll("
                SELECT w.webhook_id, o.olay_id
                FROM dbo.Api_Webhooklari w
                INNER JOIN dbo.Api_WebhookOlaylari wo ON wo.webhook_olay_webhook_id = w.webhook_id AND wo.Durum = 1
                INNER JOIN dbo.tanim_WebhookOlaylari o ON o.olay_id = wo.webhook_olay_olay_id AND o.Durum = 1
                WHERE w.webhook_firma_id = ? AND w.Durum = 1 AND o.olay_kod = ?
            ", [(int)$firmaId, $olayKod]) ?: [];

            $adet = 0;
            foreach ($hedefler as $h) {
                $govde = json_encode([
                    'id'    => 'whk_' . bin2hex(random_bytes(16)),
                    'olay'  => $olayKod,
                    'tarih' => date('c'),
                    'veri'  => ['basvuru_id' => (int)$basvuruId] + $veri,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($db->execute("
                    INSERT INTO dbo.Api_Webhook_Gonderimleri (gonderim_webhook_id, gonderim_olay_id, gonderim_basvuru_id, gonderim_govde)
                    VALUES (?, ?, ?, ?)
                ", [(int)$h['webhook_id'], (int)$h['olay_id'], (int)$basvuruId, $govde])) {
                    $adet++;
                }
            }
            return $adet;
        } catch (Throwable $e) {
            error_log("Webhook::olay($olayKod, #$basvuruId): " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Zamanı gelen gönderimleri işler (cron).
     * @return array ['islenen', 'basarili', 'hatali', 'birakilan', 'satirlar' => [metin]]
     */
    public static function kuyruguIsle($limit = 100, $sureSn = 45)
    {
        $db = Database::getInstance();
        $bas = microtime(true);

        // Kiralama: aynı satırı iki çalışma birden almasın
        $alinan = $db->fetchAll("
            UPDATE TOP (?) g
            SET g.gonderim_sonraki_deneme = DATEADD(MINUTE, ?, GETDATE()), g.GuncellemeTarihi = GETDATE()
            OUTPUT INSERTED.gonderim_id
            FROM dbo.Api_Webhook_Gonderimleri g WITH (READPAST, UPDLOCK, ROWLOCK)
            INNER JOIN dbo.Api_Webhooklari w ON w.webhook_id = g.gonderim_webhook_id AND w.Durum = 1
            WHERE g.gonderim_basarili_tarihi IS NULL AND g.gonderim_sonraki_deneme <= GETDATE() AND g.Durum = 1
        ", [(int)$limit, self::KIRALAMA_DK]) ?: [];
        $idler = array_map(fn($r) => (int)$r['gonderim_id'], $alinan);
        sort($idler);

        $sonuc = ['islenen' => 0, 'basarili' => 0, 'hatali' => 0, 'birakilan' => 0, 'satirlar' => []];
        $hataliAdres = [];
        foreach ($idler as $i => $id) {
            if (microtime(true) - $bas > $sureSn) {
                self::birak($db, array_slice($idler, $i));
                $sonuc['birakilan'] = count($idler) - $i;
                break;
            }
            $g = $db->fetchOne("
                SELECT g.gonderim_id, g.gonderim_govde, g.gonderim_deneme, g.gonderim_webhook_id,
                       w.webhook_adres, w.webhook_gizli_anahtar, w.webhook_firma_id, o.olay_kod
                FROM dbo.Api_Webhook_Gonderimleri g
                INNER JOIN dbo.Api_Webhooklari w ON w.webhook_id = g.gonderim_webhook_id
                INNER JOIN dbo.tanim_WebhookOlaylari o ON o.olay_id = g.gonderim_olay_id
                WHERE g.gonderim_id = ?
            ", [$id]);
            if (!$g) {
                continue;
            }
            // Bu çalışmada hata veren adrese sıradaki olaylar gönderilmez, sonraki dakikaya bırakılır
            if (isset($hataliAdres[$g['gonderim_webhook_id']])) {
                self::birak($db, [$id]);
                $sonuc['birakilan']++;
                continue;
            }

            $r = self::gonder($g);
            self::sonucYaz($db, $g, $r);
            $sonuc['islenen']++;
            $sonuc[$r['basarili'] ? 'basarili' : 'hatali']++;
            if (!$r['basarili']) {
                $hataliAdres[$g['gonderim_webhook_id']] = true;
            }
            $sonuc['satirlar'][] = sprintf('#%d %s → %s%s (%d ms)', $id, $g['olay_kod'],
                $r['http'] ?: '-', $r['hata'] ? ' ' . $r['hata'] : '', $r['sure_ms']);
        }
        return $sonuc;
    }

    /** Tek gönderim: imzalı POST. Dönüş: ['basarili', 'http', 'hata', 'sure_ms'] */
    public static function gonder(array $g)
    {
        $bas = microtime(true);
        $sonuc = fn($ok, $http, $hata) => ['basarili' => $ok, 'http' => $http, 'hata' => $hata,
                                           'sure_ms' => (int)round((microtime(true) - $bas) * 1000)];
        try {
            $hedef = self::adresCoz((string)$g['webhook_adres']);
            $gizli = Sifreleme::coz($g['webhook_gizli_anahtar'], 'webhook:' . (int)$g['webhook_firma_id']);
        } catch (Throwable $e) {
            return $sonuc(false, null, $e->getMessage());
        }

        $govde = (string)$g['gonderim_govde'];
        $teslim = json_decode($govde, true)['id'] ?? '';
        $zaman = (string)time();
        $zamanAsimi = Ayarlar::sayi('webhook_zaman_asimi_sn', (int)$g['webhook_firma_id']);

        $yanit = '';
        $ch = curl_init($g['webhook_adres']);
        curl_setopt_array($ch, [
            CURLOPT_POST            => true,
            CURLOPT_POSTFIELDS      => $govde,
            CURLOPT_HTTPHEADER      => [
                'Content-Type: application/json; charset=utf-8',
                'User-Agent: KVKKizin-Webhook/1.0',
                'X-KVKK-Olay: ' . $g['olay_kod'],
                'X-KVKK-Teslim: ' . $teslim,
                'X-KVKK-Zaman: ' . $zaman,
                'X-KVKK-Imza: sha256=' . hash_hmac('sha256', $zaman . '.' . $govde, $gizli),
            ],
            CURLOPT_RESOLVE         => [$hedef['host'] . ':' . $hedef['port'] . ':' . $hedef['ip']],
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION  => false,
            CURLOPT_CONNECTTIMEOUT  => min(5, $zamanAsimi),
            CURLOPT_TIMEOUT         => $zamanAsimi,
            CURLOPT_SSL_VERIFYPEER  => true,
            CURLOPT_SSL_VERIFYHOST  => 2,
            CURLOPT_RETURNTRANSFER  => false,
            CURLOPT_WRITEFUNCTION   => function ($c, $parca) use (&$yanit) {
                if (strlen($yanit) < 300) {
                    $yanit .= substr($parca, 0, 300 - strlen($yanit));
                }
                return strlen($parca);
            },
        ]);
        curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $hata = curl_errno($ch) ? curl_error($ch) : null;
        curl_close($ch);

        if ($hata !== null) {
            return $sonuc(false, $http ?: null, $hata);
        }
        if ($http >= 200 && $http < 300) {
            return $sonuc(true, $http, null);
        }
        $ozet = trim(preg_replace('/\s+/u', ' ', strip_tags($yanit)));
        return $sonuc(false, $http, 'HTTP ' . $http . ($ozet !== '' ? ': ' . mb_substr($ozet, 0, 200) : ''));
    }

    /**
     * Adresi doğrular ve IP'ye çözer: yalnız https, iç ağ / ayrılmış IP'ler reddedilir (SSRF).
     * Çözülen IP CURLOPT_RESOLVE ile sabitlenir; DNS ikinci kez sorulmaz.
     */
    public static function adresCoz($adres)
    {
        $p = parse_url($adres);
        if (!$p || strtolower($p['scheme'] ?? '') !== 'https' || empty($p['host']) || isset($p['user']) || isset($p['pass'])) {
            throw new RuntimeException('Adres https:// ile başlayan geçerli bir URL olmalı.');
        }
        $host = strtolower(trim($p['host'], '[]'));
        $port = (int)($p['port'] ?? 443);

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ipler = [$host];
        } else {
            $ipler = gethostbynamel($host) ?: [];
            if (!$ipler) {
                foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $r) {
                    $ipler[] = $r['ipv6'];
                }
            }
        }
        if (!$ipler) {
            throw new RuntimeException('Adres çözülemedi: ' . $host);
        }
        foreach ($ipler as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new RuntimeException('İç ağ veya ayrılmış IP adresine gönderim yapılmaz: ' . $ip);
            }
        }
        $ip = $ipler[0];
        return ['host' => $host, 'port' => $port, 'ip' => str_contains($ip, ':') ? '[' . $ip . ']' : $ip];
    }

    /** Gönderim sonucunu ve adresin hata sayacını yazar */
    private static function sonucYaz($db, array $g, array $r)
    {
        $deneme = (int)$g['gonderim_deneme'] + 1;
        $firmaId = (int)$g['webhook_firma_id'];
        $sonraki = null;
        if (!$r['basarili']) {
            $araliklar = Ayarlar::liste('webhook_tekrar_dk', $firmaId);
            $sonraki = $araliklar[$deneme - 1] ?? null;     // liste bittiyse NULL: denemeler bitti
        }

        $param = [min(255, $deneme), $r['http'], $r['hata'] !== null ? mb_substr($r['hata'], 0, 500) : null, $r['sure_ms']];
        if ($r['basarili']) {
            $ek = 'gonderim_basarili_tarihi = GETDATE(), gonderim_sonraki_deneme = NULL';
        } elseif ($sonraki === null) {
            $ek = 'gonderim_sonraki_deneme = NULL';
        } else {
            $ek = 'gonderim_sonraki_deneme = DATEADD(MINUTE, ?, GETDATE())';
            $param[] = (int)$sonraki;
        }
        $param[] = (int)$g['gonderim_id'];
        $db->execute("
            UPDATE dbo.Api_Webhook_Gonderimleri
            SET gonderim_deneme = ?, gonderim_son_http_kod = ?, gonderim_son_hata = ?, gonderim_sure_ms = ?,
                $ek, GuncellemeTarihi = GETDATE()
            WHERE gonderim_id = ?
        ", $param);

        if ($r['basarili']) {
            $db->execute("
                UPDATE dbo.Api_Webhooklari SET webhook_ardisik_hata = 0, webhook_son_basarili = GETDATE() WHERE webhook_id = ?
            ", [(int)$g['gonderim_webhook_id']]);
            return;
        }
        $esik = Ayarlar::sayi('webhook_pasif_hata', $firmaId);
        $db->execute("
            UPDATE dbo.Api_Webhooklari
            SET webhook_ardisik_hata = webhook_ardisik_hata + 1,
                Durum = CASE WHEN webhook_ardisik_hata + 1 >= ? THEN 0 ELSE Durum END,
                GuncellemeTarihi = CASE WHEN webhook_ardisik_hata + 1 >= ? THEN GETDATE() ELSE GuncellemeTarihi END
            WHERE webhook_id = ?
        ", [$esik, $esik, (int)$g['gonderim_webhook_id']]);
    }

    /** Kiralanan ama işlenmeyen satırları hemen tekrar sıraya koyar */
    private static function birak($db, array $idler)
    {
        foreach (array_chunk($idler, 500) as $parca) {
            $db->execute("
                UPDATE dbo.Api_Webhook_Gonderimleri SET gonderim_sonraki_deneme = GETDATE()
                WHERE gonderim_id IN (" . implode(',', array_map('intval', $parca)) . ")
            ");
        }
    }
}
