<?php
/**
 * Admin Panel - API Yönetimi
 *
 * Sekmeler: Anahtarlar · Bildirim Adresleri (webhook) · Gönderimler · İstek Logları
 * Ayarlar modalı (yalnız yönetici): genel değerler ve firma bazlı değerler.
 *   Değer şu sırayla geçerli olur: firmanın kendi değeri → üst firmanın değeri → genel değer (includes/Ayarlar.php).
 *
 * Gizli bilgiler yalnız oluşturulduğunda bir kez gösterilir:
 *   - API anahtarı (kvk_ + 48 hex): DB'de yalnız SHA-256'sı ve ilk 12 karakteri durur.
 *   - Webhook gizli anahtarı (whsec_ + 64 hex): Sifreleme ile şifreli, bağlam 'webhook:<firma_id>'.
 * Gönderimler ve istek logları hareket tablosu olduğu için server-side, iki aşamalı sorguyla listelenir.
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
require_once __DIR__ . '/../../includes/Ayarlar.php';
require_once __DIR__ . '/../../includes/Sifreleme.php';
require_once __DIR__ . '/../../includes/Webhook.php';
require_once __DIR__ . '/../../includes/FormBasvuru.php';
require_once __DIR__ . '/../../includes/Api/Api.php';
requireAuth();

$user = Auth::user();
$db = Database::getInstance();

$currentPagefile = basename($_SERVER['PHP_SELF']);
$pageinfo = $db->fetchOne("
    SELECT s.sayfalar_sayfa_adi, s.sayfalar_aciklama, m.menuler_menu_adi AS menu_adi
    FROM Menu_Sayfalar s
    LEFT JOIN Menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1
", ['%' . $currentPagefile]);

$pageTitle = $pageinfo['sayfalar_sayfa_adi'] ?? 'API Yönetimi';
$menuAdi = $pageinfo['menu_adi'] ?? null;

$permissions = PageAuth::checkPagePermissions($user['kullanici_id'], $user['departman_id'], $currentPagefile);
if (!$permissions['has_access']) {
    PageAuth::accessDenied($permissions['error'] ?? 'Bu sayfaya erişim yetkiniz yok!');
}

$yonetici = !empty($permissions['is_admin']);
$firmaScopeIds = PageAuth::firmaScopeIds($permissions, $user['firma_id'] ?? null);
$tekFirma = ($firmaScopeIds !== null && count($firmaScopeIds) <= 1);
$firmaKapsamdaMi = function ($firmaId) use ($firmaScopeIds) {
    return $firmaScopeIds === null || in_array((int)$firmaId, $firmaScopeIds, true);
};

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Portal';
$apiAdres = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'ornek-kvkk.com') . '/api';

/** Virgül / boşluk ile ayrılmış IP listesi: tek IP (v4/v6) veya IPv4 CIDR. Dönüş [normalize metin|null, hata|null] */
$ipListesiDogrula = function ($metin) {
    $girdiler = array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', (string)$metin))));
    foreach ($girdiler as $g) {
        if (strpos($g, '/') !== false) {
            [$ag, $bit] = explode('/', $g, 2);
            if (!filter_var($ag, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || !ctype_digit($bit) || (int)$bit > 32) {
                return [null, "Geçersiz ağ: $g (IPv4/0-32 biçiminde olmalı)"];
            }
        } elseif (!filter_var($g, FILTER_VALIDATE_IP)) {
            return [null, "Geçersiz IP: $g"];
        }
    }
    $sonuc = implode(', ', array_unique($girdiler));
    if (mb_strlen($sonuc) > 500) {
        return [null, 'IP listesi en fazla 500 karakter olabilir.'];
    }
    return [$sonuc !== '' ? $sonuc : null, null];
};

$tarihGecerli = function ($tarih) {
    $d = DateTime::createFromFormat('!Y-m-d', (string)$tarih);
    return $d && $d->format('Y-m-d') === $tarih;
};

/** DataTables sıralaması: kolon index → kolon adı (whitelist), yön normalize */
$siralama = function (array $kolonlar, $varsayilan) {
    $index = (int)($_POST['order'][0]['column'] ?? -1);
    $kolon = $kolonlar[$index] ?? $varsayilan;
    $yon = strtolower($_POST['order'][0]['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';
    return [$kolon, $yon];
};

$sayfalama = function () {
    $start = max(0, (int)($_POST['start'] ?? 0));
    $length = (int)($_POST['length'] ?? 25);
    if ($length <= 0 || $length > 500) {
        $length = 25;
    }
    return [$start, $length, (int)($_POST['draw'] ?? 1)];
};

/** Gönderim filtreleri — yalnız Api_Webhook_Gonderimleri (g), JOIN'siz */
$gonderimFiltre = function (array $girdi) use ($firmaScopeIds, $tarihGecerli) {
    $kosullar = ['g.Durum = 1'];
    $params = [];
    if ($firmaScopeIds !== null) {
        [$s, $p] = PageAuth::firmaScopeWhere($firmaScopeIds, 'webhook_firma_id');
        $kosullar[] = "g.gonderim_webhook_id IN (SELECT webhook_id FROM dbo.Api_Webhooklari WHERE $s)";
        $params = array_merge($params, $p);
    }
    if (!empty($girdi['firma_id'])) {
        $kosullar[] = 'g.gonderim_webhook_id IN (SELECT webhook_id FROM dbo.Api_Webhooklari WHERE webhook_firma_id = ?)';
        $params[] = (int)$girdi['firma_id'];
    }
    foreach (['webhook_id' => 'g.gonderim_webhook_id', 'olay_id' => 'g.gonderim_olay_id', 'basvuru_id' => 'g.gonderim_basvuru_id'] as $alan => $kolon) {
        if (!empty($girdi[$alan])) {
            $kosullar[] = "$kolon = ?";
            $params[] = (int)$girdi[$alan];
        }
    }
    $durumlar = [
        'basarili'  => 'g.gonderim_basarili_tarihi IS NOT NULL',
        'sirada'    => 'g.gonderim_basarili_tarihi IS NULL AND g.gonderim_sonraki_deneme IS NOT NULL',
        'basarisiz' => 'g.gonderim_basarili_tarihi IS NULL AND g.gonderim_sonraki_deneme IS NULL',
    ];
    if (isset($durumlar[$girdi['sonuc'] ?? ''])) {
        $kosullar[] = $durumlar[$girdi['sonuc']];
    }
    if (!empty($girdi['tarih_bas']) && $tarihGecerli($girdi['tarih_bas'])) {
        $kosullar[] = 'g.OlusturmaTarihi >= ?';
        $params[] = $girdi['tarih_bas'];
    }
    if (!empty($girdi['tarih_bit']) && $tarihGecerli($girdi['tarih_bit'])) {
        $kosullar[] = 'g.OlusturmaTarihi < DATEADD(DAY, 1, CAST(? AS DATE))';
        $params[] = $girdi['tarih_bit'];
    }
    return [implode(' AND ', $kosullar), $params];
};

/** İstek logu filtreleri — yalnız Api_Istek_Loglari (l), JOIN'siz */
$logFiltre = function (array $girdi) use ($firmaScopeIds, $tarihGecerli) {
    [$s, $params] = PageAuth::firmaScopeWhere($firmaScopeIds, 'l.api_log_firma_id');
    $kosullar = [$s];
    foreach (['firma_id' => 'l.api_log_firma_id', 'anahtar_id' => 'l.api_log_anahtar_id', 'basvuru_id' => 'l.api_log_basvuru_id'] as $alan => $kolon) {
        if (!empty($girdi[$alan])) {
            $kosullar[] = "$kolon = ?";
            $params[] = (int)$girdi[$alan];
        }
    }
    $sonuclar = [
        'basarili' => 'l.api_log_http_kod < 400',
        'istemci'  => 'l.api_log_http_kod >= 400 AND l.api_log_http_kod < 500',
        'sunucu'   => 'l.api_log_http_kod >= 500',
    ];
    if (isset($sonuclar[$girdi['sonuc'] ?? ''])) {
        $kosullar[] = $sonuclar[$girdi['sonuc']];
    }
    if (!empty($girdi['hata_kod']) && preg_match('/^[A-Z_]{1,40}$/', $girdi['hata_kod'])) {
        $kosullar[] = 'l.api_log_hata_kod = ?';
        $params[] = $girdi['hata_kod'];
    }
    if (!empty($girdi['metot']) && in_array($girdi['metot'], ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        $kosullar[] = 'l.api_log_metot = ?';
        $params[] = $girdi['metot'];
    }
    $yol = trim((string)($girdi['yol'] ?? ''));
    if ($yol !== '') {
        $kosullar[] = 'l.api_log_yol LIKE ?';
        $params[] = '%' . $yol . '%';
    }
    $ip = trim((string)($girdi['ip'] ?? ''));
    if ($ip !== '') {
        $kosullar[] = 'l.api_log_ip = ?';
        $params[] = $ip;
    }
    if (!empty($girdi['tarih_bas']) && $tarihGecerli($girdi['tarih_bas'])) {
        $kosullar[] = 'l.api_log_tarih >= ?';
        $params[] = $girdi['tarih_bas'];
    }
    if (!empty($girdi['tarih_bit']) && $tarihGecerli($girdi['tarih_bit'])) {
        $kosullar[] = 'l.api_log_tarih < DATEADD(DAY, 1, CAST(? AS DATE))';
        $params[] = $girdi['tarih_bit'];
    }
    return [implode(' AND ', $kosullar), $params];
};

/** Kapsamdaki anahtar / webhook; yoksa null */
$anahtarGetir = function ($id) use ($db, $firmaKapsamdaMi) {
    $a = $db->fetchOne("SELECT * FROM dbo.Api_Anahtarlari WHERE api_anahtar_id = ?", [(int)$id]);
    return ($a && $firmaKapsamdaMi($a['api_anahtar_firma_id'])) ? $a : null;
};
$webhookGetir = function ($id) use ($db, $firmaKapsamdaMi) {
    $w = $db->fetchOne("SELECT * FROM dbo.Api_Webhooklari WHERE webhook_id = ?", [(int)$id]);
    return ($w && $firmaKapsamdaMi($w['webhook_firma_id'])) ? $w : null;
};

/** Seçilen olayları webhook'a eşitler (seçilmeyenler pasif) */
$olaylariEsitle = function ($webhookId, array $olayIdleri) use ($db, $user) {
    $db->execute("
        UPDATE dbo.Api_WebhookOlaylari SET Durum = 0, GuncelleyenKullanici = ?, GuncellemeTarihi = GETDATE()
        WHERE webhook_olay_webhook_id = ? AND Durum = 1
    ", [(int)$user['kullanici_id'], (int)$webhookId]);
    foreach ($olayIdleri as $olayId) {
        $var = $db->fetchOne("SELECT webhook_olay_id FROM dbo.Api_WebhookOlaylari WHERE webhook_olay_webhook_id = ? AND webhook_olay_olay_id = ?",
            [(int)$webhookId, (int)$olayId]);
        if ($var) {
            $db->execute("UPDATE dbo.Api_WebhookOlaylari SET Durum = 1, GuncelleyenKullanici = ?, GuncellemeTarihi = GETDATE() WHERE webhook_olay_id = ?",
                [(int)$user['kullanici_id'], (int)$var['webhook_olay_id']]);
        } else {
            $db->execute("INSERT INTO dbo.Api_WebhookOlaylari (webhook_olay_webhook_id, webhook_olay_olay_id, OlusturanKullanici) VALUES (?, ?, ?)",
                [(int)$webhookId, (int)$olayId, (int)$user['kullanici_id']]);
        }
    }
};

/** Ayar değeri doğrulama: dönüş [normalize değer|null, hata|null] */
$ayarDogrula = function (array $t, $deger) {
    $deger = trim((string)$deger);
    $mn = $t['ayar_min'] !== null ? (int)$t['ayar_min'] : null;
    $mx = $t['ayar_max'] !== null ? (int)$t['ayar_max'] : null;
    $aralikta = fn($n) => ($mn === null || $n >= $mn) && ($mx === null || $n <= $mx);
    $aralikMetni = ($mn !== null ? $mn : '') . ' - ' . ($mx !== null ? $mx : '');
    if ($t['ayar_tip'] === 'sayi_liste') {
        $parcalar = array_map('trim', explode(',', $deger));
        if (!$parcalar || count($parcalar) > 10) {
            return [null, $t['ayar_adi'] . ': 1 ile 10 arasında değer girilmeli.'];
        }
        foreach ($parcalar as $p) {
            if (!ctype_digit($p) || !$aralikta((int)$p)) {
                return [null, $t['ayar_adi'] . ": her değer $aralikMetni aralığında tam sayı olmalı."];
            }
        }
        return [implode(',', array_map('intval', $parcalar)), null];
    }
    if (!preg_match('/^\d+$/', $deger) || !$aralikta((int)$deger)) {
        return [null, $t['ayar_adi'] . ": $aralikMetni aralığında tam sayı olmalı."];
    }
    return [(string)(int)$deger, null];
};

/**
 * Postman Collection v2.1: firmanın seçilen formuna göre örnek gövdeli istekler.
 * Anahtar koleksiyona yazılmaz (yalnız oluşturulurken gösterilir); kullanıcı {{api_key}} değişkenine yapıştırır.
 */
$postmanKoleksiyon = function (array $firma, ?array $form) use ($apiAdres, $siteTitle) {
    $servis = new FormBasvuru();
    $json = fn($v) => json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $degiskenler = [
        'base_url'     => [$apiAdres, 'API kök adresi'],
        'api_key'      => ['', 'Panelde oluşturulan anahtar (kvk_...). Yalnız oluşturulduğunda gösterilir.'],
        'form_uniqid'  => [$form['form_uniqid'] ?? '', 'Form kodu. "Formlar" isteği boşsa ilk formla doldurur.'],
        'telefon'      => ['', 'Test için cep telefonu (05xxxxxxxxx). SMS doğrulamalı formda bu numaraya gerçek SMS gider.'],
        'dogrulama_id' => ['', '"Başvuru gönder" 202 yanıtında otomatik dolar.'],
        'sms_kod'      => ['', 'Telefona gelen doğrulama kodu.'],
        'basvuru_id'   => ['', 'Başvuru kaydedilince otomatik dolar.'],
        'il_id'        => ['', '"İller" isteği boşsa ilk ille doldurur.'],
    ];

    // Örnek başvuru gövdesi (seçilen formun alanları ve güncel onay hash'leri)
    $alanlar = [];
    $onaylar = [];
    $smsli = false;
    if ($form) {
        $smsli = (int)$form['form_sms_onay'] === 1;
        $il = $servis->iller()[0] ?? null;
        $ilce = $il ? ($servis->ilceler($il['il_id'])[0] ?? null) : null;
        foreach ($servis->alanlar($form['form_id']) as $a) {
            $kod = $a['form_alan_kod'];
            $tip = $a['form_alan_tipi'];
            switch (true) {
                case $kod === 'cep_telefonu' || $tip === 'tel': $d = '{{telefon}}'; break;
                case $kod === 'eposta' || $tip === 'email':     $d = 'test@example.com'; break;
                case $kod === 'ad':                             $d = 'Test'; break;
                case $kod === 'soyad':                          $d = 'Kullanıcı'; break;
                case $kod === 'tc_kimlik':                      $d = '10000000146'; break;
                case $kod === 'vergi_no':                       $d = '1234567890'; break;
                case $kod === 'il':                             $d = $il ? (string)$il['il_id'] : '{{il_id}}'; break;
                case $kod === 'ilce':                           $d = $ilce ? (string)$ilce['ilce_id'] : ''; break;
                case $tip === 'date':                           $d = '1990-01-01'; break;
                case $tip === 'select':
                    $s = $a['secenekler'][0] ?? null;
                    $d = $s ? (string)$s['form_alan_secenek_id'] : '';
                    $alt = $s ? ($a['alt_secenekler'][(int)$s['form_alan_secenek_id']][0] ?? null) : null;
                    if ($alt) {
                        $alanlar[$kod] = $d;
                        $kod .= '_alt';
                        $d = (string)$alt['form_alan_secenek_id'];
                    }
                    break;
                default: $d = 'Postman test';
            }
            $alanlar[$kod] = $d;
        }
        foreach (FormBasvuru::onayMetinleri($form) as $anahtar => $m) {
            $onaylar[$anahtar] = ['kabul' => true, 'hash' => '{{hash_' . $anahtar . '}}'];
            $degiskenler['hash_' . $anahtar] = [$m['hash'], $m['baslik'] . ' metin hash\'i. "Form deseni" isteği günceller.'];
        }
    } else {
        $alanlar = ['ad' => 'Test', 'soyad' => 'Kullanıcı', 'cep_telefonu' => '{{telefon}}'];
        $onaylar = ['aydinlatma' => ['kabul' => true, 'hash' => '{{hash_aydinlatma}}']];
    }

    $istek = function ($ad, $metot, $yol, array $o = []) use ($json) {
        $raw = '{{base_url}}/' . $yol;
        $url = ['raw' => $raw, 'host' => ['{{base_url}}'], 'path' => explode('/', $yol)];
        if (!empty($o['sorgu'])) {
            $url['query'] = [];
            $parca = [];
            foreach ($o['sorgu'] as [$k, $v, $acik, $kapali]) {
                $url['query'][] = ['key' => $k, 'value' => $v, 'description' => $acik] + ($kapali ? ['disabled' => true] : []);
                if (!$kapali) {
                    $parca[] = "$k=$v";
                }
            }
            $url['raw'] .= $parca ? '?' . implode('&', $parca) : '';
        }
        $r = ['method' => $metot, 'header' => [], 'url' => $url, 'description' => $o['aciklama'] ?? ''];
        if (array_key_exists('govde', $o)) {
            $r['header'][] = ['key' => 'Content-Type', 'value' => 'application/json'];
            $r['body'] = ['mode' => 'raw', 'raw' => is_string($o['govde']) ? $o['govde'] : $json($o['govde']),
                          'options' => ['raw' => ['language' => 'json']]];
        }
        if (isset($o['auth'])) {
            $r['auth'] = $o['auth'];
        }
        $oge = ['name' => $ad, 'request' => $r];
        if (!empty($o['test'])) {
            $oge['event'] = [['listen' => 'test', 'script' => ['type' => 'text/javascript', 'exec' => $o['test']]]];
        }
        return $oge;
    };
    $ok = fn(...$kodlar) => "pm.test('HTTP " . implode('/', $kodlar) . "', () => pm.expect(pm.response.code).to.be.oneOf([" . implode(', ', $kodlar) . "]));";
    $j = 'const j = pm.response.json();';
    $set = fn($ad, $ifade) => "pm.collectionVariables.set('$ad', $ifade)";

    $ogeler = [
        ['name' => '1. Formlar', 'item' => [
            $istek('Formlar', 'GET', 'formlar', [
                'aciklama' => 'Anahtarın firmasına ait aktif formlar.',
                'test' => [$ok(200), $j, "if (j.basarili && j.veri.length && !pm.collectionVariables.get('form_uniqid')) " . $set('form_uniqid', 'j.veri[0].uniqid') . ';'],
            ]),
            $istek('Form deseni', 'GET', 'formlar/{{form_uniqid}}', [
                'aciklama' => 'Alanlar, seçenekler, onay metinleri ve hash\'leri. Onay hash\'leri koleksiyon değişkenlerine yazılır.',
                'test' => [$ok(200), $j, "(j.veri && j.veri.onaylar || []).forEach(o => " . $set("hash_' + o.anahtar + '", 'o.hash') . ");"],
            ]),
        ]],
        ['name' => '2. Başvuru', 'item' => [
            $istek('Başvuru gönder', 'POST', 'formlar/{{form_uniqid}}/basvuru', [
                'aciklama' => ($smsli
                    ? "Bu form **SMS doğrulamalı**: {{telefon}} numarasına kod gider, yanıt 202 + dogrulama_id döner. Ardından \"SMS kodunu doğrula\" çalıştırılır."
                    : "Bu form SMS doğrulamasız: kayıt doğrudan oluşur, yanıt 201 + basvuru_id döner.")
                    . "\n\n`musteri.ip` / `musteri.tarayici` isteğe bağlıdır; kanıt kaydında müşterinin gerçek bilgisi olarak tutulur.",
                'govde' => ['alanlar' => (object)$alanlar, 'onaylar' => (object)$onaylar,
                            'musteri' => ['ip' => '203.0.113.10', 'tarayici' => 'Postman']],
                'test' => [$ok(201, 202), $j,
                    "if (pm.response.code === 202) " . $set('dogrulama_id', 'j.veri.dogrulama_id') . ';',
                    "if (pm.response.code === 201) " . $set('basvuru_id', 'j.veri.basvuru_id') . ';',
                    "if (j.hata && j.hata.guncel_hashler) { Object.entries(j.hata.guncel_hashler).forEach(([k, v]) => " . $set("hash_' + k + '", 'v') . "); console.log('Onay hash\\'leri güncellendi, isteği tekrar gönderin.'); }"],
            ]),
            $istek('SMS kodunu doğrula', 'POST', 'basvuru/dogrula', [
                'aciklama' => 'Önce {{sms_kod}} değişkenine telefona gelen kodu yazın.',
                'govde' => "{\n    \"dogrulama_id\": \"{{dogrulama_id}}\",\n    \"kod\": \"{{sms_kod}}\"\n}",
                'test' => [$ok(201), $j, "if (pm.response.code === 201) " . $set('basvuru_id', 'j.veri.basvuru_id') . ';'],
            ]),
            $istek('Kodu tekrar gönder', 'POST', 'basvuru/kod-tekrar', [
                'aciklama' => 'Bekleme süresi dolmadan çağrılırsa 429 + bekleme_sn döner.',
                'govde' => "{\n    \"dogrulama_id\": \"{{dogrulama_id}}\"\n}",
                'test' => [$ok(200)],
            ]),
        ]],
        ['name' => '3. Kayıtlar', 'item' => [
            $istek('Form başvuruları', 'GET', 'formlar/{{form_uniqid}}/basvurular', [
                'aciklama' => 'Sayfalı liste (yeniden eskiye).',
                'sorgu' => [['sayfa', '1', '', false], ['limit', '100', 'En fazla ayardaki api_liste_limit', false],
                            ['baslangic', date('Y-m-01'), 'YYYY-AA-GG', true], ['bitis', date('Y-m-d'), 'YYYY-AA-GG', true]],
                'test' => [$ok(200)],
            ]),
            $istek('Başvuru detayı', 'GET', 'basvurular/{{basvuru_id}}', ['test' => [$ok(200)]]),
            $istek('İzin durumu (telefon)', 'GET', 'izin-durumu', [
                'aciklama' => 'Telefonun her formdaki güncel izinleri ve özet.',
                'sorgu' => [['telefon', '{{telefon}}', '05xxxxxxxxx', false]],
                'test' => [$ok(200)],
            ]),
            $istek('Başvuruyu iptal et', 'POST', 'basvurular/{{basvuru_id}}/iptal', [
                'aciklama' => '**Geri alınamaz.** Açık rıza / KVKK / ETK izinleri geri alınmış sayılır, `basvuru.iptal_edildi` bildirimi kuyruğa yazılır.',
                'govde' => ['neden' => 'Müşteri izni geri çekti (Postman testi)', 'musteri' => ['ip' => '203.0.113.10']],
                'test' => [$ok(200)],
            ]),
        ]],
        ['name' => '4. Konum', 'item' => [
            $istek('İller', 'GET', 'iller', [
                'test' => [$ok(200), $j, "if (j.basarili && j.veri.length && !pm.collectionVariables.get('il_id')) " . $set('il_id', 'String(j.veri[0].id)') . ';'],
            ]),
            $istek('İlçeler', 'GET', 'iller/{{il_id}}/ilceler', ['test' => [$ok(200)]]),
        ]],
        ['name' => '5. Hata örnekleri', 'item' => [
            $istek('Anahtarsız istek (401)', 'GET', 'formlar', [
                'auth' => ['type' => 'noauth'],
                'test' => [$ok(401)],
            ]),
            $istek('Olmayan adres (404)', 'GET', 'olmayan-adres', ['test' => [$ok(404)]]),
            $istek('Yanlış yöntem (405)', 'GET', 'basvuru/dogrula', ['test' => [$ok(405)]]),
            $istek('Geçersiz JSON (400)', 'POST', 'basvuru/dogrula', ['govde' => '{ bozuk json', 'test' => [$ok(400)]]),
        ]],
    ];

    $hataSatir = '';
    foreach (ApiHata::KODLAR as $kod => $http) {
        $hataSatir .= "| `$kod` | $http |\n";
    }
    $aciklama = "**{$firma['firma_adi']}** için {$siteTitle} İzin API test koleksiyonu" . ($form ? " (form: {$form['form_adi']})" : '') . ".\n\n"
        . "### Kullanım\n"
        . "1. Koleksiyon › **Variables** sekmesinde `api_key` değerine panelde oluşturduğunuz anahtarı yapıştırın.\n"
        . "2. `telefon` değerine test numarasını yazın" . ($smsli ? ' (SMS doğrulamalı formda bu numaraya gerçek SMS gider)' : '') . ".\n"
        . "3. Sırayla çalıştırın: Formlar → Form deseni → Başvuru gönder" . ($smsli ? ' → (kodu `sms_kod`a yazın) → SMS kodunu doğrula' : '') . " → Başvuru detayı.\n\n"
        . "`dogrulama_id`, `basvuru_id` ve onay hash'leri yanıtlardan otomatik doldurulur.\n\n"
        . "Tam dokümantasyon: " . preg_replace('#/api$#', '', $apiAdres) . "/api-dokuman\n\n"
        . "### Yanıt biçimi\n"
        . "Başarılı: `{\"basarili\": true, \"veri\": ...}` · Hatalı: `{\"basarili\": false, \"hata\": {\"kod\", \"mesaj\", \"alanlar\"?}}`\n\n"
        . "| Hata kodu | HTTP |\n|---|---|\n" . $hataSatir . "\n"
        . "### Bildirim (webhook) imzası\n"
        . "Başlıklar: `X-KVKK-Olay`, `X-KVKK-Teslim`, `X-KVKK-Zaman`, `X-KVKK-Imza`.\n"
        . "İmza: `sha256=` + HMAC-SHA256(`X-KVKK-Zaman` + `.` + ham gövde, gizli anahtar `whsec_...`).\n\n"
        . "```php\n\$beklenen = 'sha256=' . hash_hmac('sha256', \$_SERVER['HTTP_X_KVKK_ZAMAN'] . '.' . file_get_contents('php://input'), \$gizli);\n"
        . "\$gecerli = hash_equals(\$beklenen, \$_SERVER['HTTP_X_KVKK_IMZA'] ?? '') && abs(time() - (int)\$_SERVER['HTTP_X_KVKK_ZAMAN']) < 300;\n```\n";

    $uuid = bin2hex(random_bytes(16));
    return [
        'info' => [
            '_postman_id' => substr($uuid, 0, 8) . '-' . substr($uuid, 8, 4) . '-4' . substr($uuid, 13, 3) . '-a' . substr($uuid, 17, 3) . '-' . substr($uuid, 20, 12),
            'name' => "$siteTitle İzin API - {$firma['firma_adi']}",
            'description' => $aciklama,
            'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
        ],
        'auth' => ['type' => 'bearer', 'bearer' => [['key' => 'token', 'value' => '{{api_key}}', 'type' => 'string']]],
        'event' => [['listen' => 'prerequest', 'script' => ['type' => 'text/javascript', 'exec' => [
            "if (!pm.collectionVariables.get('api_key')) console.warn('api_key boş: Koleksiyon > Variables sekmesine anahtarı yapıştırın.');",
        ]]]],
        'item' => $ogeler,
        'variable' => array_map(fn($k, $v) => ['key' => $k, 'value' => $v[0], 'type' => 'string', 'description' => $v[1]],
            array_keys($degiskenler), $degiskenler),
    ];
};

// Postman koleksiyonu indirme (GET)
if (($_GET['postman'] ?? '') === '1') {
    $firmaId = (int)($_GET['firma_id'] ?? 0);
    $firma = $firmaId && $firmaKapsamdaMi($firmaId)
        ? $db->fetchOne("SELECT firma_id, firma_adi FROM dbo.Firmalar WHERE firma_id = ? AND firma_durum = 1", [$firmaId])
        : null;
    if (!$firma) {
        http_response_code(404);
        exit('Firma bulunamadı veya yetkiniz yok.');
    }
    $form = null;
    if (!empty($_GET['form'])) {
        $form = (new FormBasvuru())->formGetir($_GET['form']);
        if (!$form || (int)$form['firma_id'] !== $firmaId) {
            http_response_code(404);
            exit('Form bulunamadı.');
        }
    }
    $dosya = preg_replace('/[^a-z0-9]+/', '-', strtolower(strtr($firma['firma_adi'], ['ç' => 'c', 'ğ' => 'g', 'ı' => 'i', 'ö' => 'o', 'ş' => 's', 'ü' => 'u',
        'Ç' => 'c', 'Ğ' => 'g', 'İ' => 'i', 'Ö' => 'o', 'Ş' => 's', 'Ü' => 'u'])));
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="izin-api-' . trim($dosya, '-') . '.postman_collection.json"');
    header('Cache-Control: no-store');
    echo json_encode($postmanKoleksiyon($firma, $form), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// AJAX işlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'] ?? '';
    $yanit = fn(array $v) => print json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $hata = fn($m) => print json_encode(['success' => false, 'message' => $m], JSON_UNESCAPED_UNICODE);
    $kid = (int)$user['kullanici_id'];

    try {
        switch ($action) {
            /* ------------------------------------------------ Özet */
            case 'stats':
                [$aSql, $aPrm] = PageAuth::firmaScopeWhere($firmaScopeIds, 'a.api_anahtar_firma_id');
                [$lSql, $lPrm] = PageAuth::firmaScopeWhere($firmaScopeIds, 'l.api_log_firma_id');
                [$gSql, $gPrm] = $gonderimFiltre([]);
                [$wSql, $wPrm] = PageAuth::firmaScopeWhere($firmaScopeIds, 'w.webhook_firma_id');

                $anahtar = $db->fetchOne("SELECT COUNT(*) AS n FROM dbo.Api_Anahtarlari a WHERE a.Durum = 1 AND a.api_anahtar_iptal_tarihi IS NULL AND $aSql", $aPrm);
                $istek = $db->fetchOne("
                    SELECT COUNT(*) AS toplam, SUM(CASE WHEN l.api_log_http_kod >= 400 THEN 1 ELSE 0 END) AS hatali
                    FROM dbo.Api_Istek_Loglari l WHERE l.api_log_tarih >= DATEADD(HOUR, -24, GETDATE()) AND $lSql
                ", $lPrm);
                $gonderim = $db->fetchOne("
                    SELECT COUNT(*) AS toplam,
                           SUM(CASE WHEN g.gonderim_basarili_tarihi IS NOT NULL THEN 1 ELSE 0 END) AS basarili,
                           SUM(CASE WHEN g.gonderim_basarili_tarihi IS NULL AND g.gonderim_sonraki_deneme IS NOT NULL THEN 1 ELSE 0 END) AS sirada
                    FROM dbo.Api_Webhook_Gonderimleri g WHERE g.OlusturmaTarihi >= DATEADD(HOUR, -24, GETDATE()) AND $gSql
                ", $gPrm);
                $adres = $db->fetchOne("SELECT COUNT(*) AS n FROM dbo.Api_Webhooklari w WHERE w.Durum = 1 AND $wSql", $wPrm);

                $yanit(['success' => true, 'data' => [
                    'anahtar'           => (int)($anahtar['n'] ?? 0),
                    'istek'             => (int)($istek['toplam'] ?? 0),
                    'hatali'            => (int)($istek['hatali'] ?? 0),
                    'adres'             => (int)($adres['n'] ?? 0),
                    'gonderim'          => (int)($gonderim['toplam'] ?? 0),
                    'gonderim_basarili' => (int)($gonderim['basarili'] ?? 0),
                    'gonderim_sirada'   => (int)($gonderim['sirada'] ?? 0),
                ]]);
                break;

            /* ------------------------------------------------ Seçim listeleri */
            case 'firma_list':
                [$fSql, $fPrm] = PageAuth::firmaScopeWhere($firmaScopeIds, 'firma_id');
                $yanit(['success' => true, 'data' => $db->fetchAll("
                    SELECT firma_id, firma_adi FROM dbo.Firmalar WHERE firma_durum = 1 AND $fSql ORDER BY firma_adi
                ", $fPrm) ?: []]);
                break;

            case 'postman_form_list':
                $firmaId = (int)($_POST['firma_id'] ?? 0);
                $yanit(['success' => true, 'data' => $firmaId && $firmaKapsamdaMi($firmaId) ? ($db->fetchAll("
                    SELECT form_uniqid, form_adi, form_sms_onay FROM dbo.Formlar WHERE firma_id = ? AND form_durum = 1 ORDER BY form_adi
                ", [$firmaId]) ?: []) : []]);
                break;

            case 'olay_list':
                $yanit(['success' => true, 'data' => $db->fetchAll("
                    SELECT olay_id, olay_kod, olay_adi, olay_aciklama FROM dbo.tanim_WebhookOlaylari WHERE Durum = 1 ORDER BY olay_sira_no, olay_id
                ") ?: []]);
                break;

            case 'hata_kod_list':
                [$lSql, $lPrm] = PageAuth::firmaScopeWhere($firmaScopeIds, 'l.api_log_firma_id');
                $yanit(['success' => true, 'data' => array_column($db->fetchAll("
                    SELECT DISTINCT l.api_log_hata_kod AS kod FROM dbo.Api_Istek_Loglari l
                    WHERE l.api_log_hata_kod IS NOT NULL AND $lSql ORDER BY l.api_log_hata_kod
                ", $lPrm) ?: [], 'kod')]);
                break;

            /* ------------------------------------------------ Anahtarlar */
            case 'anahtar_list':
                [$aSql, $prm] = PageAuth::firmaScopeWhere($firmaScopeIds, 'a.api_anahtar_firma_id');
                $kosul = $aSql;
                if (!empty($_POST['firma_id'])) {
                    $kosul .= ' AND a.api_anahtar_firma_id = ?';
                    $prm[] = (int)$_POST['firma_id'];
                }
                $durum = $_POST['durum'] ?? '';
                if ($durum === 'aktif') {
                    $kosul .= ' AND a.Durum = 1 AND a.api_anahtar_iptal_tarihi IS NULL';
                } elseif ($durum === 'iptal') {
                    $kosul .= ' AND (a.Durum = 0 OR a.api_anahtar_iptal_tarihi IS NOT NULL)';
                }
                $ara = is_string($_POST['search'] ?? null) ? trim($_POST['search']) : '';
                if ($ara !== '') {
                    $kosul .= ' AND (a.api_anahtar_adi LIKE ? OR a.api_anahtar_on_ek LIKE ? OR f.firma_adi LIKE ?)';
                    array_push($prm, "%$ara%", "%$ara%", "%$ara%");
                }
                $yanit(['success' => true, 'data' => $db->fetchAll("
                    SELECT a.api_anahtar_id, a.api_anahtar_firma_id, f.firma_adi, a.api_anahtar_adi, a.api_anahtar_on_ek,
                           a.api_anahtar_ip_listesi, a.api_anahtar_son_ip, a.Durum,
                           CONVERT(VARCHAR(19), a.api_anahtar_son_kullanim, 120) AS son_kullanim,
                           CONVERT(VARCHAR(19), a.api_anahtar_iptal_tarihi, 120) AS iptal_tarihi,
                           CONVERT(VARCHAR(19), a.OlusturmaTarihi, 120) AS olusturma,
                           (SELECT COUNT(*) FROM dbo.Api_Istek_Loglari l
                            WHERE l.api_log_anahtar_id = a.api_anahtar_id AND l.api_log_tarih >= DATEADD(HOUR, -24, GETDATE())) AS istek_24s
                    FROM dbo.Api_Anahtarlari a
                    INNER JOIN dbo.Firmalar f ON f.firma_id = a.api_anahtar_firma_id
                    WHERE $kosul
                    ORDER BY CASE WHEN a.api_anahtar_iptal_tarihi IS NULL THEN 0 ELSE 1 END, f.firma_adi, a.api_anahtar_adi
                ", $prm) ?: []]);
                break;

            case 'anahtar_get':
                $a = $anahtarGetir($_POST['id'] ?? 0);
                if (!$a) {
                    $hata('Anahtar bulunamadı!');
                    break;
                }
                unset($a['api_anahtar_hash']);
                $yanit(['success' => true, 'data' => $a]);
                break;

            case 'anahtar_kaydet':
                $id = (int)($_POST['id'] ?? 0);
                if (!$permissions[$id > 0 ? 'can_edit' : 'can_add']) {
                    $hata($id > 0 ? 'Düzenleme yetkiniz yok!' : 'Ekleme yetkiniz yok!');
                    break;
                }
                $ad = trim(preg_replace('/\s+/u', ' ', (string)($_POST['ad'] ?? '')));
                if ($ad === '' || mb_strlen($ad) > 100) {
                    $hata('Anahtar adı zorunlu, en fazla 100 karakter.');
                    break;
                }
                [$ipListesi, $ipHata] = $ipListesiDogrula($_POST['ip_listesi'] ?? '');
                if ($ipHata) {
                    $hata($ipHata);
                    break;
                }

                if ($id > 0) {
                    $a = $anahtarGetir($id);
                    if (!$a || $a['api_anahtar_iptal_tarihi'] !== null) {
                        $hata('Anahtar bulunamadı veya iptal edilmiş!');
                        break;
                    }
                    $db->execute("
                        UPDATE dbo.Api_Anahtarlari
                        SET api_anahtar_adi = ?, api_anahtar_ip_listesi = ?, GuncelleyenKullanici = ?, GuncellemeTarihi = GETDATE()
                        WHERE api_anahtar_id = ?
                    ", [$ad, $ipListesi, $kid, $id]);
                    $yanit(['success' => true, 'message' => 'Anahtar güncellendi']);
                    break;
                }

                $firmaId = (int)($_POST['firma_id'] ?? 0);
                $firma = $firmaId > 0 && $firmaKapsamdaMi($firmaId)
                    ? $db->fetchOne("SELECT firma_id, firma_adi FROM dbo.Firmalar WHERE firma_id = ? AND firma_durum = 1", [$firmaId]) : null;
                if (!$firma) {
                    $hata('Geçerli bir firma seçmelisiniz!');
                    break;
                }
                $sinir = Ayarlar::sayi('api_max_anahtar', $firmaId);
                $mevcut = (int)($db->fetchOne("
                    SELECT COUNT(*) AS n FROM dbo.Api_Anahtarlari
                    WHERE api_anahtar_firma_id = ? AND Durum = 1 AND api_anahtar_iptal_tarihi IS NULL
                ", [$firmaId])['n'] ?? 0);
                if ($mevcut >= $sinir) {
                    $hata("Bu firmanın $sinir aktif anahtar sınırı dolu. Yeni anahtar için kullanılmayan bir anahtarı iptal edin.");
                    break;
                }

                $ham = 'kvk_' . bin2hex(random_bytes(24));
                $ok = $db->execute("
                    INSERT INTO dbo.Api_Anahtarlari (api_anahtar_firma_id, api_anahtar_adi, api_anahtar_on_ek, api_anahtar_hash, api_anahtar_ip_listesi, OlusturanKullanici)
                    VALUES (?, ?, ?, ?, ?, ?)
                ", [$firmaId, $ad, substr($ham, 0, 12), hash('sha256', $ham), $ipListesi, $kid]);
                if (!$ok) {
                    $hata('Anahtar kaydedilemedi.');
                    break;
                }
                $yanit(['success' => true, 'message' => 'Anahtar oluşturuldu', 'anahtar' => $ham, 'firma' => $firma['firma_adi']]);
                break;

            case 'anahtar_iptal':
                if (!$permissions['can_delete']) {
                    $hata('İptal yetkiniz yok!');
                    break;
                }
                $a = $anahtarGetir($_POST['id'] ?? 0);
                if (!$a || $a['api_anahtar_iptal_tarihi'] !== null) {
                    $hata('Anahtar bulunamadı veya zaten iptal edilmiş!');
                    break;
                }
                $db->execute("
                    UPDATE dbo.Api_Anahtarlari
                    SET api_anahtar_iptal_tarihi = GETDATE(), Durum = 0, GuncelleyenKullanici = ?, GuncellemeTarihi = GETDATE()
                    WHERE api_anahtar_id = ?
                ", [$kid, (int)$a['api_anahtar_id']]);
                $yanit(['success' => true, 'message' => 'Anahtar iptal edildi; bu anahtarla yapılan istekler artık reddedilir.']);
                break;

            /* ------------------------------------------------ Bildirim adresleri */
            case 'webhook_list':
                [$wSql, $prm] = PageAuth::firmaScopeWhere($firmaScopeIds, 'w.webhook_firma_id');
                $kosul = $wSql;
                if (!empty($_POST['firma_id'])) {
                    $kosul .= ' AND w.webhook_firma_id = ?';
                    $prm[] = (int)$_POST['firma_id'];
                }
                if (in_array($_POST['durum'] ?? '', ['0', '1'], true)) {
                    $kosul .= ' AND w.Durum = ?';
                    $prm[] = (int)$_POST['durum'];
                }
                if (!empty($_POST['olay_id'])) {
                    $kosul .= ' AND EXISTS (SELECT 1 FROM dbo.Api_WebhookOlaylari wo WHERE wo.webhook_olay_webhook_id = w.webhook_id AND wo.Durum = 1 AND wo.webhook_olay_olay_id = ?)';
                    $prm[] = (int)$_POST['olay_id'];
                }
                $ara = is_string($_POST['search'] ?? null) ? trim($_POST['search']) : '';
                if ($ara !== '') {
                    $kosul .= ' AND (w.webhook_adres LIKE ? OR f.firma_adi LIKE ?)';
                    array_push($prm, "%$ara%", "%$ara%");
                }
                $satirlar = $db->fetchAll("
                    SELECT w.webhook_id, w.webhook_firma_id, f.firma_adi, w.webhook_adres, w.webhook_ardisik_hata, w.Durum,
                           CONVERT(VARCHAR(19), w.webhook_son_basarili, 120) AS son_basarili,
                           CONVERT(VARCHAR(19), w.OlusturmaTarihi, 120) AS olusturma,
                           (SELECT COUNT(*) FROM dbo.Api_Webhook_Gonderimleri g
                            WHERE g.gonderim_webhook_id = w.webhook_id AND g.gonderim_basarili_tarihi IS NULL AND g.gonderim_sonraki_deneme IS NOT NULL) AS sirada
                    FROM dbo.Api_Webhooklari w
                    INNER JOIN dbo.Firmalar f ON f.firma_id = w.webhook_firma_id
                    WHERE $kosul
                    ORDER BY f.firma_adi, w.webhook_adres
                ", $prm) ?: [];

                $olaylar = [];
                if ($satirlar) {
                    $idler = implode(',', array_map(fn($r) => (int)$r['webhook_id'], $satirlar));
                    foreach ($db->fetchAll("
                        SELECT wo.webhook_olay_webhook_id AS webhook_id, o.olay_id, o.olay_kod, o.olay_adi
                        FROM dbo.Api_WebhookOlaylari wo
                        INNER JOIN dbo.tanim_WebhookOlaylari o ON o.olay_id = wo.webhook_olay_olay_id AND o.Durum = 1
                        WHERE wo.Durum = 1 AND wo.webhook_olay_webhook_id IN ($idler)
                        ORDER BY o.olay_sira_no
                    ") ?: [] as $o) {
                        $olaylar[(int)$o['webhook_id']][] = ['id' => (int)$o['olay_id'], 'kod' => $o['olay_kod'], 'adi' => $o['olay_adi']];
                    }
                }
                foreach ($satirlar as &$r) {
                    $r['olaylar'] = $olaylar[(int)$r['webhook_id']] ?? [];
                    $r['hata_pasif'] = (int)$r['Durum'] === 0
                        && (int)$r['webhook_ardisik_hata'] >= Ayarlar::sayi('webhook_pasif_hata', (int)$r['webhook_firma_id']);
                }
                unset($r);
                $yanit(['success' => true, 'data' => $satirlar]);
                break;

            case 'webhook_get':
                $w = $webhookGetir($_POST['id'] ?? 0);
                if (!$w) {
                    $hata('Adres bulunamadı!');
                    break;
                }
                unset($w['webhook_gizli_anahtar']);
                $w['olaylar'] = array_map('intval', array_column($db->fetchAll("
                    SELECT webhook_olay_olay_id FROM dbo.Api_WebhookOlaylari WHERE webhook_olay_webhook_id = ? AND Durum = 1
                ", [(int)$w['webhook_id']]) ?: [], 'webhook_olay_olay_id'));
                $yanit(['success' => true, 'data' => $w]);
                break;

            case 'webhook_kaydet':
                $id = (int)($_POST['id'] ?? 0);
                if (!$permissions[$id > 0 ? 'can_edit' : 'can_add']) {
                    $hata($id > 0 ? 'Düzenleme yetkiniz yok!' : 'Ekleme yetkiniz yok!');
                    break;
                }
                $adres = trim((string)($_POST['adres'] ?? ''));
                if ($adres === '' || mb_strlen($adres) > 500) {
                    $hata('Adres zorunlu, en fazla 500 karakter.');
                    break;
                }
                try {
                    Webhook::adresCoz($adres);
                } catch (RuntimeException $e) {
                    $hata($e->getMessage());
                    break;
                }
                $gecerliOlaylar = array_map('intval', array_column($db->fetchAll("SELECT olay_id FROM dbo.tanim_WebhookOlaylari WHERE Durum = 1") ?: [], 'olay_id'));
                $olayIdleri = array_values(array_intersect(array_map('intval', (array)($_POST['olaylar'] ?? [])), $gecerliOlaylar));
                if (!$olayIdleri) {
                    $hata('En az bir olay seçmelisiniz!');
                    break;
                }
                $aktif = ($_POST['durum'] ?? '0') === '1' ? 1 : 0;

                $conn = $db->getConnection();
                if ($id > 0) {
                    $w = $webhookGetir($id);
                    if (!$w) {
                        $hata('Adres bulunamadı!');
                        break;
                    }
                    sqlsrv_begin_transaction($conn);
                    try {
                        // Aktife alınırken hata sayacı sıfırlanır
                        $db->execute("
                            UPDATE dbo.Api_Webhooklari
                            SET webhook_adres = ?, Durum = ?,
                                webhook_ardisik_hata = CASE WHEN ? = 1 AND Durum = 0 THEN 0 ELSE webhook_ardisik_hata END,
                                GuncelleyenKullanici = ?, GuncellemeTarihi = GETDATE()
                            WHERE webhook_id = ?
                        ", [$adres, $aktif, $aktif, $kid, $id]);
                        $olaylariEsitle($id, $olayIdleri);
                        sqlsrv_commit($conn);
                    } catch (Throwable $e) {
                        sqlsrv_rollback($conn);
                        throw $e;
                    }
                    $yanit(['success' => true, 'message' => 'Adres güncellendi']);
                    break;
                }

                $firmaId = (int)($_POST['firma_id'] ?? 0);
                $firma = $firmaId > 0 && $firmaKapsamdaMi($firmaId)
                    ? $db->fetchOne("SELECT firma_id, firma_adi FROM dbo.Firmalar WHERE firma_id = ? AND firma_durum = 1", [$firmaId]) : null;
                if (!$firma) {
                    $hata('Geçerli bir firma seçmelisiniz!');
                    break;
                }
                $gizli = 'whsec_' . bin2hex(random_bytes(32));
                sqlsrv_begin_transaction($conn);
                try {
                    $yeni = $db->fetchOne("
                        INSERT INTO dbo.Api_Webhooklari (webhook_firma_id, webhook_adres, webhook_gizli_anahtar, OlusturanKullanici, Durum)
                        OUTPUT INSERTED.webhook_id
                        VALUES (?, ?, ?, ?, ?)
                    ", [$firmaId, $adres, Sifreleme::sifrele($gizli, 'webhook:' . $firmaId), $kid, $aktif]);
                    if (!$yeni) {
                        throw new Exception('Adres kaydedilemedi.');
                    }
                    $olaylariEsitle((int)$yeni['webhook_id'], $olayIdleri);
                    sqlsrv_commit($conn);
                } catch (Throwable $e) {
                    sqlsrv_rollback($conn);
                    throw $e;
                }
                $yanit(['success' => true, 'message' => 'Adres eklendi', 'gizli' => $gizli, 'firma' => $firma['firma_adi']]);
                break;

            case 'webhook_gizli_yenile':
                if (!$permissions['can_edit']) {
                    $hata('Düzenleme yetkiniz yok!');
                    break;
                }
                $w = $webhookGetir($_POST['id'] ?? 0);
                if (!$w) {
                    $hata('Adres bulunamadı!');
                    break;
                }
                $gizli = 'whsec_' . bin2hex(random_bytes(32));
                $db->execute("
                    UPDATE dbo.Api_Webhooklari SET webhook_gizli_anahtar = ?, GuncelleyenKullanici = ?, GuncellemeTarihi = GETDATE() WHERE webhook_id = ?
                ", [Sifreleme::sifrele($gizli, 'webhook:' . (int)$w['webhook_firma_id']), $kid, (int)$w['webhook_id']]);
                $yanit(['success' => true, 'message' => 'Gizli anahtar yenilendi', 'gizli' => $gizli]);
                break;

            case 'webhook_durum':
                if (!$permissions['can_edit']) {
                    $hata('Düzenleme yetkiniz yok!');
                    break;
                }
                $w = $webhookGetir($_POST['id'] ?? 0);
                if (!$w) {
                    $hata('Adres bulunamadı!');
                    break;
                }
                $aktif = ($_POST['durum'] ?? '0') === '1' ? 1 : 0;
                $db->execute("
                    UPDATE dbo.Api_Webhooklari
                    SET Durum = ?, webhook_ardisik_hata = CASE WHEN ? = 1 THEN 0 ELSE webhook_ardisik_hata END,
                        GuncelleyenKullanici = ?, GuncellemeTarihi = GETDATE()
                    WHERE webhook_id = ?
                ", [$aktif, $aktif, $kid, (int)$w['webhook_id']]);
                $yanit(['success' => true, 'message' => $aktif ? 'Adres aktif edildi' : 'Adres pasife alındı; yeni olay gönderilmez']);
                break;

            case 'webhook_test':
                if (!$permissions['can_edit']) {
                    $hata('Test yetkiniz yok!');
                    break;
                }
                $w = $webhookGetir($_POST['id'] ?? 0);
                if (!$w) {
                    $hata('Adres bulunamadı!');
                    break;
                }
                // Kuyruğa yazılmaz, sayaçlara dokunmaz; anında gönderilir
                $r = Webhook::gonder($w + [
                    'olay_kod'       => 'test',
                    'gonderim_govde' => json_encode([
                        'id'    => 'whk_test_' . bin2hex(random_bytes(12)),
                        'olay'  => 'test',
                        'tarih' => date('c'),
                        'veri'  => ['mesaj' => 'Bu bir test bildirimidir.'],
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]);
                $yanit(['success' => true, 'data' => $r,
                    'message' => $r['basarili'] ? "Test başarılı: HTTP {$r['http']} ({$r['sure_ms']} ms)" : 'Test başarısız: ' . ($r['hata'] ?: 'HTTP ' . $r['http'])]);
                break;

            /* ------------------------------------------------ Gönderimler (server-side) */
            case 'gonderim_list':
                [$start, $length, $draw] = $sayfalama();
                [$kolon, $yon] = $siralama([0 => 'g.gonderim_id', 4 => 'g.gonderim_basvuru_id', 5 => 'g.gonderim_deneme', 6 => 'g.gonderim_son_http_kod'], 'g.gonderim_id');
                $sira = $kolon === 'g.gonderim_id' ? "$kolon $yon" : "$kolon $yon, g.gonderim_id DESC";   // aynı kolon ORDER BY'da iki kez olamaz
                [$tSql, $tPrm] = $gonderimFiltre([]);
                [$fSql, $fPrm] = $gonderimFiltre($_POST);

                $toplam = (int)($db->fetchOne("SELECT COUNT(*) AS n FROM dbo.Api_Webhook_Gonderimleri g WHERE $tSql", $tPrm)['n'] ?? 0);
                $filtreli = (int)($db->fetchOne("SELECT COUNT(*) AS n FROM dbo.Api_Webhook_Gonderimleri g WHERE $fSql", $fPrm)['n'] ?? 0);
                $satirlar = $db->fetchAll("
                    WITH Sayfa AS (
                        SELECT g.gonderim_id, ROW_NUMBER() OVER (ORDER BY $sira) AS sira
                        FROM dbo.Api_Webhook_Gonderimleri g
                        WHERE $fSql
                        ORDER BY $sira
                        OFFSET ? ROWS FETCH NEXT ? ROWS ONLY
                    )
                    SELECT g.gonderim_id, g.gonderim_basvuru_id, g.gonderim_deneme, g.gonderim_son_http_kod, g.gonderim_son_hata, g.gonderim_sure_ms,
                           CONVERT(VARCHAR(19), g.OlusturmaTarihi, 120) AS tarih,
                           CONVERT(VARCHAR(19), g.gonderim_basarili_tarihi, 120) AS basarili_tarih,
                           CONVERT(VARCHAR(19), g.gonderim_sonraki_deneme, 120) AS sonraki_deneme,
                           CASE WHEN g.gonderim_basarili_tarihi IS NOT NULL THEN 'basarili'
                                WHEN g.gonderim_sonraki_deneme IS NOT NULL THEN 'sirada' ELSE 'basarisiz' END AS sonuc,
                           w.webhook_id, w.webhook_adres, w.Durum AS webhook_durum, f.firma_adi, o.olay_kod, o.olay_adi
                    FROM Sayfa s
                    INNER JOIN dbo.Api_Webhook_Gonderimleri g ON g.gonderim_id = s.gonderim_id
                    INNER JOIN dbo.Api_Webhooklari w ON w.webhook_id = g.gonderim_webhook_id
                    INNER JOIN dbo.Firmalar f ON f.firma_id = w.webhook_firma_id
                    INNER JOIN dbo.tanim_WebhookOlaylari o ON o.olay_id = g.gonderim_olay_id
                    ORDER BY s.sira
                ", array_merge($fPrm, [$start, $length])) ?: [];
                $yanit(['draw' => $draw, 'recordsTotal' => $toplam, 'recordsFiltered' => $filtreli, 'data' => $satirlar]);
                break;

            case 'gonderim_detay':
                [$fSql, $fPrm] = $gonderimFiltre([]);
                $g = $db->fetchOne("
                    SELECT g.gonderim_id, g.gonderim_govde, g.gonderim_deneme, g.gonderim_son_http_kod, g.gonderim_son_hata, g.gonderim_sure_ms,
                           CONVERT(VARCHAR(19), g.OlusturmaTarihi, 120) AS tarih,
                           CONVERT(VARCHAR(19), g.gonderim_basarili_tarihi, 120) AS basarili_tarih,
                           CONVERT(VARCHAR(19), g.gonderim_sonraki_deneme, 120) AS sonraki_deneme,
                           CONVERT(VARCHAR(19), g.GuncellemeTarihi, 120) AS son_deneme,
                           w.webhook_adres, o.olay_kod
                    FROM dbo.Api_Webhook_Gonderimleri g
                    INNER JOIN dbo.Api_Webhooklari w ON w.webhook_id = g.gonderim_webhook_id
                    INNER JOIN dbo.tanim_WebhookOlaylari o ON o.olay_id = g.gonderim_olay_id
                    WHERE g.gonderim_id = ? AND $fSql
                ", array_merge([(int)($_POST['id'] ?? 0)], $fPrm));
                if (!$g) {
                    $hata('Gönderim bulunamadı!');
                    break;
                }
                $yanit(['success' => true, 'data' => $g]);
                break;

            case 'gonderim_tekrar':
                if (!$permissions['can_edit']) {
                    $hata('Yetkiniz yok!');
                    break;
                }
                [$fSql, $fPrm] = $gonderimFiltre([]);
                $g = $db->fetchOne("
                    SELECT g.gonderim_id, g.gonderim_basarili_tarihi, w.Durum AS webhook_durum
                    FROM dbo.Api_Webhook_Gonderimleri g INNER JOIN dbo.Api_Webhooklari w ON w.webhook_id = g.gonderim_webhook_id
                    WHERE g.gonderim_id = ? AND $fSql
                ", array_merge([(int)($_POST['id'] ?? 0)], $fPrm));
                if (!$g) {
                    $hata('Gönderim bulunamadı!');
                    break;
                }
                if ($g['gonderim_basarili_tarihi'] !== null) {
                    $hata('Bu gönderim zaten başarılı.');
                    break;
                }
                if ((int)$g['webhook_durum'] !== 1) {
                    $hata('Adres pasif. Önce Bildirim Adresleri sekmesinden aktif edin.');
                    break;
                }
                $db->execute("
                    UPDATE dbo.Api_Webhook_Gonderimleri SET gonderim_sonraki_deneme = GETDATE(), GuncelleyenKullanici = ?, GuncellemeTarihi = GETDATE()
                    WHERE gonderim_id = ? AND gonderim_basarili_tarihi IS NULL
                ", [$kid, (int)$g['gonderim_id']]);
                $yanit(['success' => true, 'message' => 'Gönderim sıraya alındı; en geç 1 dakika içinde gönderilir.']);
                break;

            /* ------------------------------------------------ İstek logları (server-side) */
            case 'log_list':
                [$start, $length, $draw] = $sayfalama();
                [$kolon, $yon] = $siralama([0 => 'l.api_log_id', 5 => 'l.api_log_http_kod', 7 => 'l.api_log_sure_ms'], 'l.api_log_id');
                $sira = $kolon === 'l.api_log_id' ? "$kolon $yon" : "$kolon $yon, l.api_log_id DESC";
                [$tSql, $tPrm] = $logFiltre([]);
                [$fSql, $fPrm] = $logFiltre($_POST);

                $toplam = (int)($db->fetchOne("SELECT COUNT(*) AS n FROM dbo.Api_Istek_Loglari l WHERE $tSql", $tPrm)['n'] ?? 0);
                $filtreli = (int)($db->fetchOne("SELECT COUNT(*) AS n FROM dbo.Api_Istek_Loglari l WHERE $fSql", $fPrm)['n'] ?? 0);
                $satirlar = $db->fetchAll("
                    WITH Sayfa AS (
                        SELECT l.api_log_id, ROW_NUMBER() OVER (ORDER BY $sira) AS sira
                        FROM dbo.Api_Istek_Loglari l
                        WHERE $fSql
                        ORDER BY $sira
                        OFFSET ? ROWS FETCH NEXT ? ROWS ONLY
                    )
                    SELECT l.api_log_id, l.api_log_metot, l.api_log_yol, l.api_log_http_kod, l.api_log_hata_kod, l.api_log_sure_ms,
                           l.api_log_ip, l.api_log_basvuru_id, CONVERT(VARCHAR(19), l.api_log_tarih, 120) AS tarih,
                           f.firma_adi, a.api_anahtar_on_ek, a.api_anahtar_adi
                    FROM Sayfa s
                    INNER JOIN dbo.Api_Istek_Loglari l ON l.api_log_id = s.api_log_id
                    LEFT JOIN dbo.Firmalar f ON f.firma_id = l.api_log_firma_id
                    LEFT JOIN dbo.Api_Anahtarlari a ON a.api_anahtar_id = l.api_log_anahtar_id
                    ORDER BY s.sira
                ", array_merge($fPrm, [$start, $length])) ?: [];
                $yanit(['draw' => $draw, 'recordsTotal' => $toplam, 'recordsFiltered' => $filtreli, 'data' => $satirlar]);
                break;

            case 'log_detay':
                [$fSql, $fPrm] = $logFiltre([]);
                $l = $db->fetchOne("
                    SELECT l.api_log_id, l.api_log_metot, l.api_log_yol, l.api_log_http_kod, l.api_log_hata_kod, l.api_log_sure_ms,
                           l.api_log_ip, l.api_log_istek, l.api_log_yanit, l.api_log_basvuru_id,
                           CONVERT(VARCHAR(19), l.api_log_tarih, 120) AS tarih, a.api_anahtar_on_ek, a.api_anahtar_adi
                    FROM dbo.Api_Istek_Loglari l
                    LEFT JOIN dbo.Api_Anahtarlari a ON a.api_anahtar_id = l.api_log_anahtar_id
                    WHERE l.api_log_id = ? AND $fSql
                ", array_merge([(int)($_POST['id'] ?? 0)], $fPrm));
                if (!$l) {
                    $hata('Kayıt bulunamadı!');
                    break;
                }
                $yanit(['success' => true, 'data' => $l]);
                break;

            /* ------------------------------------------------ Ayarlar (yalnız yönetici) */
            case 'ayar_getir':
                if (!$yonetici) {
                    $hata('Ayarları yalnız yönetici değiştirebilir.');
                    break;
                }
                $firmaId = (int)($_POST['firma_id'] ?? 0);
                $ustFirmaId = null;
                $kendi = [];
                if ($firmaId > 0) {
                    $firma = $db->fetchOne("SELECT firma_id, firma_ust_firma_id FROM dbo.Firmalar WHERE firma_id = ?", [$firmaId]);
                    if (!$firma) {
                        $hata('Firma bulunamadı!');
                        break;
                    }
                    $ustFirmaId = $firma['firma_ust_firma_id'] ? (int)$firma['firma_ust_firma_id'] : null;
                    foreach ($db->fetchAll("
                        SELECT a.ayar_kod, fa.firma_ayar_deger FROM dbo.Firma_Ayarlari fa
                        INNER JOIN dbo.tanim_Ayarlar a ON a.ayar_id = fa.firma_ayar_ayar_id
                        WHERE fa.firma_ayar_firma_id = ? AND fa.Durum = 1
                    ", [$firmaId]) ?: [] as $r) {
                        $kendi[$r['ayar_kod']] = $r['firma_ayar_deger'];
                    }
                }
                Ayarlar::sifirla();
                $liste = [];
                foreach (Ayarlar::tanimlar() as $kod => $t) {
                    if ($firmaId > 0 && (int)$t['ayar_firma_ozel'] !== 1) {
                        continue;
                    }
                    $liste[] = [
                        'kod'      => $kod,
                        'grup'     => $t['ayar_grup'],
                        'adi'      => $t['ayar_adi'],
                        'aciklama' => $t['ayar_aciklama'],
                        'tip'      => $t['ayar_tip'],
                        'min'      => $t['ayar_min'] !== null ? (int)$t['ayar_min'] : null,
                        'max'      => $t['ayar_max'] !== null ? (int)$t['ayar_max'] : null,
                        'deger'    => $firmaId > 0 ? ($kendi[$kod] ?? '') : $t['ayar_deger'],
                        // Firma değeri boşsa geçerli olacak değer: üst firma zinciri → genel
                        'miras'    => $firmaId > 0 ? Ayarlar::al($kod, $ustFirmaId) : null,
                        'kaynak'   => $firmaId > 0 ? ($ustFirmaId && Ayarlar::al($kod, $ustFirmaId) !== $t['ayar_deger'] ? 'üst firma' : 'genel') : null,
                    ];
                }
                $yanit(['success' => true, 'data' => $liste]);
                break;

            case 'ayar_kaydet':
                if (!$yonetici) {
                    $hata('Ayarları yalnız yönetici değiştirebilir.');
                    break;
                }
                $firmaId = (int)($_POST['firma_id'] ?? 0);
                if ($firmaId > 0 && !$db->fetchOne("SELECT firma_id FROM dbo.Firmalar WHERE firma_id = ?", [$firmaId])) {
                    $hata('Firma bulunamadı!');
                    break;
                }
                Ayarlar::sifirla();
                $tanimlar = Ayarlar::tanimlar();
                $gelen = is_array($_POST['ayar'] ?? null) ? $_POST['ayar'] : [];
                $yazilacak = [];
                foreach ($gelen as $kod => $deger) {
                    $t = $tanimlar[$kod] ?? null;
                    if (!$t || ($firmaId > 0 && (int)$t['ayar_firma_ozel'] !== 1)) {
                        continue;
                    }
                    if ($firmaId > 0 && trim((string)$deger) === '') {
                        $yazilacak[] = [$t, null];          // firma değeri kaldırılır (miras)
                        continue;
                    }
                    [$normal, $ayarHata] = $ayarDogrula($t, $deger);
                    if ($ayarHata) {
                        $hata($ayarHata);
                        break 2;
                    }
                    $yazilacak[] = [$t, $normal];
                }

                $conn = $db->getConnection();
                sqlsrv_begin_transaction($conn);
                try {
                    $degisen = 0;
                    foreach ($yazilacak as [$t, $deger]) {
                        if ($firmaId === 0) {
                            if ((string)$t['ayar_deger'] !== $deger) {
                                $db->execute("UPDATE dbo.tanim_Ayarlar SET ayar_deger = ?, GuncelleyenKullanici = ?, GuncellemeTarihi = GETDATE() WHERE ayar_id = ?",
                                    [$deger, $kid, (int)$t['ayar_id']]);
                                $degisen++;
                            }
                            continue;
                        }
                        $var = $db->fetchOne("SELECT firma_ayar_id, firma_ayar_deger, Durum FROM dbo.Firma_Ayarlari WHERE firma_ayar_firma_id = ? AND firma_ayar_ayar_id = ?",
                            [$firmaId, (int)$t['ayar_id']]);
                        if ($deger === null) {
                            if ($var && (int)$var['Durum'] === 1) {
                                $db->execute("UPDATE dbo.Firma_Ayarlari SET Durum = 0, GuncelleyenKullanici = ?, GuncellemeTarihi = GETDATE() WHERE firma_ayar_id = ?",
                                    [$kid, (int)$var['firma_ayar_id']]);
                                $degisen++;
                            }
                        } elseif ($var) {
                            if ((int)$var['Durum'] !== 1 || $var['firma_ayar_deger'] !== $deger) {
                                $db->execute("UPDATE dbo.Firma_Ayarlari SET firma_ayar_deger = ?, Durum = 1, GuncelleyenKullanici = ?, GuncellemeTarihi = GETDATE() WHERE firma_ayar_id = ?",
                                    [$deger, $kid, (int)$var['firma_ayar_id']]);
                                $degisen++;
                            }
                        } else {
                            $db->execute("INSERT INTO dbo.Firma_Ayarlari (firma_ayar_firma_id, firma_ayar_ayar_id, firma_ayar_deger, OlusturanKullanici) VALUES (?, ?, ?, ?)",
                                [$firmaId, (int)$t['ayar_id'], $deger, $kid]);
                            $degisen++;
                        }
                    }
                    sqlsrv_commit($conn);
                } catch (Throwable $e) {
                    sqlsrv_rollback($conn);
                    throw $e;
                }
                Ayarlar::sifirla();
                $yanit(['success' => true, 'message' => $degisen ? "$degisen ayar kaydedildi" : 'Değişiklik yok']);
                break;

            default:
                $hata('Geçersiz işlem');
        }
    } catch (Throwable $e) {
        error_log('api-yonetimi.php [' . $action . ']: ' . $e->getMessage());
        $hata('İşlem sırasında hata oluştu: ' . $e->getMessage());
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
        .kod { font-family: SFMono-Regular, Consolas, monospace; font-size: .85rem; }
        .adres-kisa { max-width: 320px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; display: inline-block; vertical-align: middle; }
        .json-kutu { background: var(--bs-tertiary-bg); border-radius: .375rem; padding: .75rem; max-height: 320px; overflow: auto;
                     font-family: SFMono-Regular, Consolas, monospace; font-size: .8rem; white-space: pre-wrap; word-break: break-all; }
        .gizli-kutu { font-family: SFMono-Regular, Consolas, monospace; letter-spacing: .02em; }
        .nav-tabs .badge { font-size: .7rem; }
        .ayar-grup-baslik { font-size: .8rem; text-transform: uppercase; letter-spacing: .05em; color: var(--bs-secondary-color); border-bottom: 1px solid var(--bs-border-color); padding-bottom: .25rem; }
        tr.satir-pasif td { opacity: .6; }
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
                        <div class="col-md-3 col-sm-6">
                            <div class="info-box text-bg-primary">
                                <span class="info-box-icon"><i class="bi bi-key"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Aktif Anahtar</span>
                                    <span class="info-box-number" id="stat-anahtar">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 col-sm-6">
                            <div class="info-box text-bg-info">
                                <span class="info-box-icon"><i class="bi bi-arrow-left-right"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">İstek (24 saat)</span>
                                    <span class="info-box-number" id="stat-istek">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 col-sm-6">
                            <div class="info-box text-bg-warning">
                                <span class="info-box-icon"><i class="bi bi-exclamation-triangle"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Hatalı İstek (24 saat)</span>
                                    <span class="info-box-number" id="stat-hatali">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 col-sm-6">
                            <div class="info-box text-bg-success">
                                <span class="info-box-icon"><i class="bi bi-broadcast"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Bildirim (24 saat)</span>
                                    <span class="info-box-number" id="stat-gonderim">0</span>
                                    <small id="stat-gonderim-alt" class="opacity-75"></small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card card-primary card-outline card-tabs">
                        <div class="card-header p-0 pt-1 border-bottom-0 d-flex align-items-center">
                            <ul class="nav nav-tabs flex-grow-1" id="apiSekmeler" role="tablist">
                                <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#sekmeAnahtar" type="button" role="tab"><i class="bi bi-key me-1"></i>Anahtarlar</button></li>
                                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#sekmeWebhook" type="button" role="tab"><i class="bi bi-broadcast me-1"></i>Bildirim Adresleri</button></li>
                                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#sekmeGonderim" type="button" role="tab"><i class="bi bi-send me-1"></i>Gönderimler <span class="badge text-bg-warning d-none" id="rozet-sirada"></span></button></li>
                                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#sekmeLog" type="button" role="tab"><i class="bi bi-journal-text me-1"></i>İstek Logları</button></li>
                            </ul>
                            <div class="px-2 text-nowrap">
                                <a href="/api-dokuman" target="_blank" rel="noopener" class="btn btn-outline-secondary btn-sm"><i class="bi bi-book"></i> Dokümantasyon</a>
                                <button type="button" class="btn btn-outline-warning btn-sm" onclick="postmanAc()"><i class="bi bi-download"></i> Postman</button>
                                <?php if ($yonetici): ?>
                                <button type="button" class="btn btn-outline-primary btn-sm" onclick="ayarlarAc()"><i class="bi bi-gear"></i> Ayarlar</button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="tab-content">

                                <!-- ================= Anahtarlar ================= -->
                                <div class="tab-pane fade show active" id="sekmeAnahtar" role="tabpanel">
                                    <div class="card card-outline card-secondary mb-3">
                                        <div class="card-header">
                                            <h3 class="card-title"><i class="bi bi-funnel"></i> Filtrele</h3>
                                            <div class="card-tools">
                                                <button type="button" class="btn btn-tool" data-bs-toggle="collapse" data-bs-target="#filtreAnahtar"><i class="bi bi-chevron-down"></i></button>
                                            </div>
                                        </div>
                                        <div class="card-body collapse" id="filtreAnahtar">
                                            <form class="filtre-form" data-tablo="anahtar">
                                                <div class="row g-3">
                                                    <div class="col-md-4"><label class="form-label">Ara</label><input type="text" class="form-control" name="search" placeholder="Anahtar adı, ön ek, firma..."></div>
                                                    <div class="col-md-4 firma-filtre-alan"><label class="form-label">Firma</label><select class="form-select select2-filter firma-secim" name="firma_id"><option value="">Tümü</option></select></div>
                                                    <div class="col-md-4"><label class="form-label">Durum</label>
                                                        <select class="form-select select2-filter" name="durum"><option value="">Tümü</option><option value="aktif">Aktif</option><option value="iptal">İptal edilmiş</option></select></div>
                                                    <div class="col-12">
                                                        <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Filtrele</button>
                                                        <button type="button" class="btn btn-secondary filtre-temizle"><i class="bi bi-x-circle"></i> Temizle</button>
                                                    </div>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <small class="text-muted">API adresi: <span class="kod"><?= htmlspecialchars($apiAdres) ?></span> · Kimlik doğrulama: <span class="kod">Authorization: Bearer kvk_...</span></small>
                                        <?php if ($permissions['can_add']): ?>
                                        <button type="button" class="btn btn-primary btn-sm" onclick="anahtarYeni()"><i class="bi bi-plus-circle"></i> Yeni Anahtar</button>
                                        <?php endif; ?>
                                    </div>
                                    <table id="anahtarTable" class="table table-bordered table-striped table-hover w-100">
                                        <thead><tr>
                                            <th>Firma</th><th>Ad</th><th>Anahtar</th><th>IP Kısıtı</th><th>İstek (24s)</th><th>Son Kullanım</th><th>Oluşturma</th><th>Durum</th><th>İşlemler</th>
                                        </tr></thead>
                                        <tbody></tbody>
                                    </table>
                                </div>

                                <!-- ================= Bildirim adresleri ================= -->
                                <div class="tab-pane fade" id="sekmeWebhook" role="tabpanel">
                                    <div class="card card-outline card-secondary mb-3">
                                        <div class="card-header">
                                            <h3 class="card-title"><i class="bi bi-funnel"></i> Filtrele</h3>
                                            <div class="card-tools">
                                                <button type="button" class="btn btn-tool" data-bs-toggle="collapse" data-bs-target="#filtreWebhook"><i class="bi bi-chevron-down"></i></button>
                                            </div>
                                        </div>
                                        <div class="card-body collapse" id="filtreWebhook">
                                            <form class="filtre-form" data-tablo="webhook">
                                                <div class="row g-3">
                                                    <div class="col-md-3"><label class="form-label">Ara</label><input type="text" class="form-control" name="search" placeholder="Adres, firma..."></div>
                                                    <div class="col-md-3 firma-filtre-alan"><label class="form-label">Firma</label><select class="form-select select2-filter firma-secim" name="firma_id"><option value="">Tümü</option></select></div>
                                                    <div class="col-md-3"><label class="form-label">Olay</label><select class="form-select select2-filter olay-secim" name="olay_id"><option value="">Tümü</option></select></div>
                                                    <div class="col-md-3"><label class="form-label">Durum</label>
                                                        <select class="form-select select2-filter" name="durum"><option value="">Tümü</option><option value="1">Aktif</option><option value="0">Pasif</option></select></div>
                                                    <div class="col-12">
                                                        <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Filtrele</button>
                                                        <button type="button" class="btn btn-secondary filtre-temizle"><i class="bi bi-x-circle"></i> Temizle</button>
                                                    </div>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <small class="text-muted">Her olay imzalı POST ile gönderilir (<span class="kod">X-KVKK-Imza</span>). Hata olursa 1, 10 ve 60 dakika sonra yeniden denenir.</small>
                                        <?php if ($permissions['can_add']): ?>
                                        <button type="button" class="btn btn-primary btn-sm" onclick="webhookYeni()"><i class="bi bi-plus-circle"></i> Yeni Adres</button>
                                        <?php endif; ?>
                                    </div>
                                    <table id="webhookTable" class="table table-bordered table-striped table-hover w-100">
                                        <thead><tr>
                                            <th>Firma</th><th>Adres</th><th>Olaylar</th><th>Son Başarılı</th><th>Art Arda Hata</th><th>Sırada</th><th>Aktif</th><th>İşlemler</th>
                                        </tr></thead>
                                        <tbody></tbody>
                                    </table>
                                </div>

                                <!-- ================= Gönderimler ================= -->
                                <div class="tab-pane fade" id="sekmeGonderim" role="tabpanel">
                                    <div class="card card-outline card-secondary mb-3">
                                        <div class="card-header">
                                            <h3 class="card-title"><i class="bi bi-funnel"></i> Filtrele</h3>
                                            <div class="card-tools">
                                                <button type="button" class="btn btn-tool" data-bs-toggle="collapse" data-bs-target="#filtreGonderim"><i class="bi bi-chevron-down"></i></button>
                                            </div>
                                        </div>
                                        <div class="card-body collapse" id="filtreGonderim">
                                            <form class="filtre-form" data-tablo="gonderim">
                                                <div class="row g-3">
                                                    <div class="col-md-3 firma-filtre-alan"><label class="form-label">Firma</label><select class="form-select select2-filter firma-secim" name="firma_id"><option value="">Tümü</option></select></div>
                                                    <div class="col-md-3"><label class="form-label">Adres</label><select class="form-select select2-filter webhook-secim" name="webhook_id"><option value="">Tümü</option></select></div>
                                                    <div class="col-md-3"><label class="form-label">Olay</label><select class="form-select select2-filter olay-secim" name="olay_id"><option value="">Tümü</option></select></div>
                                                    <div class="col-md-3"><label class="form-label">Sonuç</label>
                                                        <select class="form-select select2-filter" name="sonuc"><option value="">Tümü</option><option value="basarili">Başarılı</option><option value="sirada">Sırada / tekrar bekliyor</option><option value="basarisiz">Başarısız (denemeler bitti)</option></select></div>
                                                    <div class="col-md-3"><label class="form-label">Başvuru ID</label><input type="number" class="form-control" name="basvuru_id" min="1"></div>
                                                    <div class="col-md-3"><label class="form-label">Başlangıç</label><input type="date" class="form-control" name="tarih_bas"></div>
                                                    <div class="col-md-3"><label class="form-label">Bitiş</label><input type="date" class="form-control" name="tarih_bit"></div>
                                                    <div class="col-12">
                                                        <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Filtrele</button>
                                                        <button type="button" class="btn btn-secondary filtre-temizle"><i class="bi bi-x-circle"></i> Temizle</button>
                                                    </div>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                    <table id="gonderimTable" class="table table-bordered table-striped table-hover w-100">
                                        <thead><tr>
                                            <th>Tarih</th><th>Firma</th><th>Adres</th><th>Olay</th><th>Başvuru</th><th>Deneme</th><th>HTTP</th><th>Sonuç</th><th>İşlemler</th>
                                        </tr></thead>
                                        <tbody></tbody>
                                    </table>
                                </div>

                                <!-- ================= İstek logları ================= -->
                                <div class="tab-pane fade" id="sekmeLog" role="tabpanel">
                                    <div class="card card-outline card-secondary mb-3">
                                        <div class="card-header">
                                            <h3 class="card-title"><i class="bi bi-funnel"></i> Filtrele</h3>
                                            <div class="card-tools">
                                                <button type="button" class="btn btn-tool" data-bs-toggle="collapse" data-bs-target="#filtreLog"><i class="bi bi-chevron-down"></i></button>
                                            </div>
                                        </div>
                                        <div class="card-body collapse" id="filtreLog">
                                            <form class="filtre-form" data-tablo="log">
                                                <div class="row g-3">
                                                    <div class="col-md-3 firma-filtre-alan"><label class="form-label">Firma</label><select class="form-select select2-filter firma-secim" name="firma_id"><option value="">Tümü</option></select></div>
                                                    <div class="col-md-3"><label class="form-label">Anahtar</label><select class="form-select select2-filter anahtar-secim" name="anahtar_id"><option value="">Tümü</option></select></div>
                                                    <div class="col-md-2"><label class="form-label">Metot</label>
                                                        <select class="form-select select2-filter" name="metot"><option value="">Tümü</option><option>GET</option><option>POST</option></select></div>
                                                    <div class="col-md-2"><label class="form-label">Sonuç</label>
                                                        <select class="form-select select2-filter" name="sonuc"><option value="">Tümü</option><option value="basarili">Başarılı (2xx/3xx)</option><option value="istemci">İstemci hatası (4xx)</option><option value="sunucu">Sunucu hatası (5xx)</option></select></div>
                                                    <div class="col-md-2"><label class="form-label">Hata Kodu</label><select class="form-select select2-filter hata-kod-secim" name="hata_kod"><option value="">Tümü</option></select></div>
                                                    <div class="col-md-3"><label class="form-label">Yol</label><input type="text" class="form-control" name="yol" placeholder="/api/formlar..."></div>
                                                    <div class="col-md-2"><label class="form-label">IP</label><input type="text" class="form-control" name="ip"></div>
                                                    <div class="col-md-2"><label class="form-label">Başvuru ID</label><input type="number" class="form-control" name="basvuru_id" min="1"></div>
                                                    <div class="col-md-2"><label class="form-label">Başlangıç</label><input type="date" class="form-control" name="tarih_bas"></div>
                                                    <div class="col-md-2"><label class="form-label">Bitiş</label><input type="date" class="form-control" name="tarih_bit"></div>
                                                    <div class="col-12">
                                                        <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Filtrele</button>
                                                        <button type="button" class="btn btn-secondary filtre-temizle"><i class="bi bi-x-circle"></i> Temizle</button>
                                                    </div>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                    <table id="logTable" class="table table-bordered table-striped table-hover w-100">
                                        <thead><tr>
                                            <th>Tarih</th><th>Firma</th><th>Anahtar</th><th>Metot</th><th>Yol</th><th>HTTP</th><th>Hata Kodu</th><th>Süre (ms)</th><th>IP</th><th>Başvuru</th><th>İşlem</th>
                                        </tr></thead>
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

    <!-- Anahtar Modal -->
    <div class="modal fade" id="anahtarModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="anahtarModalBaslik">Yeni Anahtar</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                </div>
                <form id="anahtarForm">
                    <div class="modal-body">
                        <input type="hidden" name="id" id="anahtar_id">
                        <div class="row g-3">
                            <div class="col-12" id="anahtar_firma_alan">
                                <label class="form-label" for="anahtar_firma_id">Firma <span class="text-danger">*</span></label>
                                <select class="form-select firma-secim" id="anahtar_firma_id" name="firma_id" style="width:100%"></select>
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="anahtar_ad">Anahtar Adı <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="anahtar_ad" name="ad" maxlength="100" required placeholder="Örn. Web sitesi, CRM entegrasyonu">
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="anahtar_ip">İzin Verilen IP'ler</label>
                                <textarea class="form-control kod" id="anahtar_ip" name="ip_listesi" rows="2" maxlength="500" placeholder="Boş: tüm IP'ler. Örn. 203.0.113.10, 198.51.100.0/24"></textarea>
                                <div class="form-text">Virgülle ayrılmış IP veya IPv4 ağ (CIDR). Bu listede olmayan adreslerden gelen istekler 403 ile reddedilir.</div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="bi bi-x-circle"></i> İptal</button>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Kaydet</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Webhook Modal -->
    <div class="modal fade" id="webhookModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="webhookModalBaslik">Yeni Bildirim Adresi</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                </div>
                <form id="webhookForm">
                    <div class="modal-body">
                        <input type="hidden" name="id" id="webhook_id">
                        <div class="row g-3">
                            <div class="col-md-6" id="webhook_firma_alan">
                                <label class="form-label" for="webhook_firma_id">Firma <span class="text-danger">*</span></label>
                                <select class="form-select firma-secim" id="webhook_firma_id" name="firma_id" style="width:100%"></select>
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="webhook_adres">Adres <span class="text-danger">*</span></label>
                                <input type="url" class="form-control kod" id="webhook_adres" name="adres" maxlength="500" required placeholder="https://ornek.com/kvkk-bildirim">
                                <div class="form-text">Yalnız <b>https</b>. İç ağ adreslerine gönderim yapılmaz, yönlendirme izlenmez. 2xx dışındaki yanıt hata sayılır.</div>
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="webhook_olaylar">Olaylar <span class="text-danger">*</span></label>
                                <select class="form-select olay-secim" id="webhook_olaylar" name="olaylar[]" multiple style="width:100%"></select>
                            </div>
                            <div class="col-12">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" role="switch" id="webhook_durum" name="durum" value="1" checked>
                                    <label class="form-check-label" for="webhook_durum">Aktif</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="bi bi-x-circle"></i> İptal</button>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Kaydet</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Gizli bilgi (bir kez gösterilir) Modal -->
    <div class="modal fade" id="gizliModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="gizliBaslik"><i class="bi bi-shield-lock"></i> Anahtar</h5>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning py-2 mb-3">
                        <i class="bi bi-exclamation-triangle"></i> Bu değer <b>bir daha gösterilmeyecek</b>. Şimdi kopyalayıp güvenli bir yere kaydedin.
                    </div>
                    <p class="mb-1 small text-muted" id="gizliAciklama"></p>
                    <div class="input-group">
                        <input type="text" class="form-control gizli-kutu" id="gizliDeger" readonly>
                        <button class="btn btn-outline-primary" type="button" onclick="gizliKopyala()"><i class="bi bi-clipboard"></i> Kopyala</button>
                    </div>
                    <div class="small text-muted mt-2" id="gizliKullanim"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-primary" onclick="gizliKapat()"><i class="bi bi-check2"></i> Kaydettim, kapat</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Detay Modal (gönderim / log) -->
    <div class="modal fade" id="detayModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="detayBaslik">Detay</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                </div>
                <div class="modal-body" id="detayIcerik"></div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="bi bi-x-circle"></i> Kapat</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Postman Modal -->
    <div class="modal fade" id="postmanModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-download"></i> Postman Koleksiyonu</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12 firma-filtre-alan">
                            <label class="form-label" for="postman_firma_id">Firma <span class="text-danger">*</span></label>
                            <select class="form-select" id="postman_firma_id" style="width:100%"></select>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="postman_form">Form</label>
                            <select class="form-select" id="postman_form" style="width:100%"></select>
                            <div class="form-text">Örnek başvuru gövdesi bu formun alanları ve güncel onay metni hash'leriyle hazırlanır.</div>
                        </div>
                    </div>
                    <ol class="small text-muted mt-3 mb-0 ps-3">
                        <li>Postman › <b>Import</b> ile dosyayı içe aktarın.</li>
                        <li>Koleksiyon › <b>Variables</b>: <span class="kod">api_key</span> değerine anahtarı, <span class="kod">telefon</span> değerine test numarasını yazın.</li>
                        <li>İstekleri sırayla çalıştırın; <span class="kod">dogrulama_id</span>, <span class="kod">basvuru_id</span> ve hash'ler yanıtlardan otomatik dolar.</li>
                    </ol>
                    <div class="alert alert-warning small mt-3 mb-0 py-2"><i class="bi bi-shield-lock"></i> API anahtarı dosyaya yazılmaz; yalnız oluşturulduğunda gösterilir.</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="bi bi-x-circle"></i> Kapat</button>
                    <button type="button" class="btn btn-primary" onclick="postmanIndir()"><i class="bi bi-download"></i> İndir</button>
                </div>
            </div>
        </div>
    </div>

    <?php if ($yonetici): ?>
    <!-- Ayarlar Modal -->
    <div class="modal fade" id="ayarModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-gear"></i> API ve Doğrulama Ayarları</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                </div>
                <form id="ayarForm">
                    <div class="modal-body">
                        <div class="row g-3 mb-3">
                            <div class="col-md-8">
                                <label class="form-label" for="ayar_firma_id">Kapsam</label>
                                <select class="form-select" id="ayar_firma_id" name="firma_id" style="width:100%"><option value="0">Genel (tüm firmalar)</option></select>
                                <div class="form-text" id="ayarKapsamNot">Genel değerler, firmaya özel değeri olmayan tüm firmalarda geçerlidir.</div>
                            </div>
                        </div>
                        <div id="ayarAlanlari"></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="bi bi-x-circle"></i> İptal</button>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Kaydet</button>
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
        const permissions = {
            canAdd: <?= $permissions['can_add'] ? 'true' : 'false' ?>,
            canEdit: <?= $permissions['can_edit'] ? 'true' : 'false' ?>,
            canDelete: <?= $permissions['can_delete'] ? 'true' : 'false' ?>
        };
        const TEK_FIRMA = <?= $tekFirma ? 'true' : 'false' ?>;
        const API_ADRES = <?= json_encode($apiAdres) ?>;
        const DT_DIL = { url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/tr.json' };

        const tablolar = {};             // anahtar, webhook, gonderim, log
        const filtreler = { anahtar: {}, webhook: {}, gonderim: {}, log: {} };
        let anahtarModal, webhookModal, gizliModal, detayModal, ayarModal, postmanModal;

        function escapeHtml(t) {
            if (t === null || t === undefined) return '';
            return String(t).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
        }
        function sayi(v) { return (parseInt(v, 10) || 0).toLocaleString('tr-TR'); }
        function zaman(v) {
            if (!v) return '<span class="text-muted">-</span>';
            const [g, s] = String(v).split(' ');
            const [y, a, d] = g.split('-');
            return `<span class="text-nowrap">${d}.${a}.${y} ${escapeHtml((s || '').substring(0, 5))}</span>`;
        }
        function istek(action, veri) {
            return $.ajax({ url: '', type: 'POST', dataType: 'json', data: Object.assign({ action: action }, veri || {}) })
                .fail(() => showToast('Sunucu hatası oluştu', 'error'));
        }
        function httpRozet(kod) {
            if (!kod) return '<span class="text-muted">-</span>';
            const renk = kod < 300 ? 'success' : (kod < 500 ? 'warning' : 'danger');
            return `<span class="badge text-bg-${renk}">${kod}</span>`;
        }
        function jsonGuzel(m) {
            if (!m) return '';
            try { return JSON.stringify(JSON.parse(m), null, 2); } catch (e) { return m; }
        }
        function islemMenu(anaEtiket, anaIslem, ogeler) {
            const menu = ogeler.filter(Boolean).join('');
            return `<div class="btn-group">
                        <button type="button" class="btn btn-sm btn-primary" onclick="${anaIslem}">${anaEtiket}</button>
                        ${menu ? `<button type="button" class="btn btn-sm btn-primary dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown" data-bs-boundary="viewport" aria-expanded="false"><span class="visually-hidden">Menü</span></button>
                        <ul class="dropdown-menu dropdown-menu-end">${menu}</ul>` : ''}
                    </div>`;
        }
        function menuOge(ikon, etiket, islem, sinif) {
            return `<li><a class="dropdown-item ${sinif || ''}" href="#" onclick="${islem}; return false;"><i class="bi ${ikon} me-2"></i>${etiket}</a></li>`;
        }

        /* ---------------------------------------------------- Özet */
        function loadStats() {
            istek('stats').done(r => {
                if (!r.success) return;
                const d = r.data;
                $('#stat-anahtar').text(sayi(d.anahtar));
                $('#stat-istek').text(sayi(d.istek));
                $('#stat-hatali').text(sayi(d.hatali) + (d.istek ? ` (%${Math.round(d.hatali * 100 / d.istek)})` : ''));
                $('#stat-gonderim').text(d.gonderim ? `${sayi(d.gonderim_basarili)} / ${sayi(d.gonderim)}` : '0');
                $('#stat-gonderim-alt').text(`${sayi(d.adres)} aktif adres` + (d.gonderim_sirada ? ` · ${sayi(d.gonderim_sirada)} sırada` : ''));
                $('#rozet-sirada').toggleClass('d-none', !d.gonderim_sirada).text(d.gonderim_sirada || '');
            });
        }

        /* ---------------------------------------------------- Seçim listeleri */
        function secimDoldur($sel, ogeler, bosEtiket) {
            $sel.each(function() {
                const $s = $(this), secili = $s.val();
                $s.empty();
                if (bosEtiket !== null) $s.append(new Option(bosEtiket, '', false, false));
                ogeler.forEach(o => $s.append(new Option(o.text, o.id, false, false)));
                if (secili) $s.val(secili);
                $s.trigger('change.select2');
            });
        }
        function listeleriYukle() {
            istek('firma_list').done(r => {
                if (!r.success) return;
                const ogeler = r.data.map(f => ({ id: f.firma_id, text: f.firma_adi }));
                secimDoldur($('.filtre-form .firma-secim'), ogeler, 'Tümü');
                secimDoldur($('#anahtar_firma_id, #webhook_firma_id, #postman_firma_id'), ogeler, '');
                $('#ayar_firma_id').find('option:not([value="0"])').remove();
                ogeler.forEach(o => $('#ayar_firma_id').append(new Option(o.text, o.id, false, false)));
            });
            istek('olay_list').done(r => {
                if (!r.success) return;
                const ogeler = r.data.map(o => ({ id: o.olay_id, text: `${o.olay_adi} (${o.olay_kod})` }));
                secimDoldur($('.filtre-form .olay-secim'), ogeler, 'Tümü');
                secimDoldur($('#webhook_olaylar'), ogeler, null);
            });
            istek('hata_kod_list').done(r => {
                if (r.success) secimDoldur($('.hata-kod-secim'), r.data.map(k => ({ id: k, text: k })), 'Tümü');
            });
        }
        function anahtarSecimGuncelle(satirlar) {
            secimDoldur($('.anahtar-secim'), satirlar.map(a => ({ id: a.api_anahtar_id, text: `${a.api_anahtar_on_ek}… ${a.api_anahtar_adi}` + (TEK_FIRMA ? '' : ` · ${a.firma_adi}`) })), 'Tümü');
        }
        function webhookSecimGuncelle(satirlar) {
            secimDoldur($('.webhook-secim'), satirlar.map(w => ({ id: w.webhook_id, text: w.webhook_adres + (TEK_FIRMA ? '' : ` · ${w.firma_adi}`) })), 'Tümü');
        }

        /* ---------------------------------------------------- Anahtarlar */
        function anahtarYukle() {
            istek('anahtar_list', filtreler.anahtar).done(r => {
                if (!r.success) { showToast(r.message || 'Liste yüklenemedi', 'error'); return; }
                tablolar.anahtar.clear().rows.add(r.data).draw();
                if (!Object.keys(filtreler.anahtar).length) anahtarSecimGuncelle(r.data);
            });
        }
        function anahtarYeni() {
            $('#anahtarForm')[0].reset();
            $('#anahtar_id').val('');
            $('#anahtar_firma_alan').show();
            $('#anahtar_firma_id').val(TEK_FIRMA ? $('#anahtar_firma_id option[value!=""]').first().val() : '').trigger('change');
            $('#anahtarModalBaslik').text('Yeni Anahtar');
            anahtarModal.show();
        }
        function anahtarDuzenle(id) {
            istek('anahtar_get', { id }).done(r => {
                if (!r.success) { showToast(r.message, 'error'); return; }
                $('#anahtar_id').val(r.data.api_anahtar_id);
                $('#anahtar_firma_alan').hide();
                $('#anahtar_ad').val(r.data.api_anahtar_adi);
                $('#anahtar_ip').val(r.data.api_anahtar_ip_listesi || '');
                $('#anahtarModalBaslik').text('Anahtarı Düzenle · ' + r.data.api_anahtar_on_ek + '…');
                anahtarModal.show();
            });
        }
        function anahtarIptal(id, etiket) {
            Swal.fire({
                title: 'Anahtar iptal edilsin mi?',
                html: `<b>${escapeHtml(etiket)}</b><br>Bu anahtarla yapılan tüm istekler hemen reddedilir. İşlem geri alınamaz.`,
                icon: 'warning', showCancelButton: true, confirmButtonText: 'İptal et', cancelButtonText: 'Vazgeç', confirmButtonColor: '#dc3545'
            }).then(s => {
                if (!s.isConfirmed) return;
                istek('anahtar_iptal', { id }).done(r => {
                    showToast(r.message, r.success ? 'success' : 'error');
                    if (r.success) { anahtarYukle(); loadStats(); }
                });
            });
        }
        function logaGit(alan, deger) {
            const $f = $('.filtre-form[data-tablo="log"]');
            $f[0].reset();
            $f.find('select').val('').trigger('change.select2');
            $f.find(`[name="${alan}"]`).val(String(deger)).trigger('change.select2');
            $f.trigger('submit');
            $('#filtreLog').collapse('show');
            bootstrap.Tab.getOrCreateInstance(document.querySelector('[data-bs-target="#sekmeLog"]')).show();
        }

        /* ---------------------------------------------------- Bildirim adresleri */
        function webhookYukle() {
            istek('webhook_list', filtreler.webhook).done(r => {
                if (!r.success) { showToast(r.message || 'Liste yüklenemedi', 'error'); return; }
                tablolar.webhook.clear().rows.add(r.data).draw();
                if (!Object.keys(filtreler.webhook).length) webhookSecimGuncelle(r.data);
            });
        }
        function webhookYeni() {
            $('#webhookForm')[0].reset();
            $('#webhook_id').val('');
            $('#webhook_firma_alan').show();
            $('#webhook_firma_id').val(TEK_FIRMA ? $('#webhook_firma_id option[value!=""]').first().val() : '').trigger('change');
            $('#webhook_olaylar').val($('#webhook_olaylar option').map((i, o) => o.value).get()).trigger('change');
            $('#webhook_durum').prop('checked', true);
            $('#webhookModalBaslik').text('Yeni Bildirim Adresi');
            webhookModal.show();
        }
        function webhookDuzenle(id) {
            istek('webhook_get', { id }).done(r => {
                if (!r.success) { showToast(r.message, 'error'); return; }
                $('#webhook_id').val(r.data.webhook_id);
                $('#webhook_firma_alan').hide();
                $('#webhook_adres').val(r.data.webhook_adres);
                $('#webhook_olaylar').val(r.data.olaylar.map(String)).trigger('change');
                $('#webhook_durum').prop('checked', r.data.Durum == 1);
                $('#webhookModalBaslik').text('Bildirim Adresini Düzenle');
                webhookModal.show();
            });
        }
        function webhookDurum(id, aktif, kutu) {
            istek('webhook_durum', { id, durum: aktif ? 1 : 0 }).done(r => {
                showToast(r.message, r.success ? 'success' : 'error');
                if (r.success) { webhookYukle(); loadStats(); } else if (kutu) { kutu.checked = !aktif; }
            });
        }
        function webhookTest(id) {
            showToast('Test bildirimi gönderiliyor...', 'info');
            istek('webhook_test', { id }).done(r => {
                if (!r.success) { showToast(r.message, 'error'); return; }
                Swal.fire({ icon: r.data.basarili ? 'success' : 'error', title: r.data.basarili ? 'Test başarılı' : 'Test başarısız',
                    html: `HTTP: <b>${escapeHtml(r.data.http || '-')}</b> · Süre: <b>${sayi(r.data.sure_ms)} ms</b>` +
                          (r.data.hata ? `<div class="mt-2 small text-start json-kutu">${escapeHtml(r.data.hata)}</div>` : '') });
            });
        }
        function webhookGizliYenile(id) {
            Swal.fire({
                title: 'Gizli anahtar yenilensin mi?',
                text: 'Eski anahtarla imza doğrulayan sistem, yeni anahtar girilene kadar bildirimleri reddeder.',
                icon: 'warning', showCancelButton: true, confirmButtonText: 'Yenile', cancelButtonText: 'Vazgeç'
            }).then(s => {
                if (!s.isConfirmed) return;
                istek('webhook_gizli_yenile', { id }).done(r => {
                    if (!r.success) { showToast(r.message, 'error'); return; }
                    gizliGoster('webhook', r.gizli, '');
                });
            });
        }
        function gonderimeGit(webhookId) {
            const $f = $('.filtre-form[data-tablo="gonderim"]');
            $f[0].reset();
            $f.find('select').val('').trigger('change.select2');
            $f.find('[name="webhook_id"]').val(String(webhookId)).trigger('change.select2');
            $f.trigger('submit');
            $('#filtreGonderim').collapse('show');
            bootstrap.Tab.getOrCreateInstance(document.querySelector('[data-bs-target="#sekmeGonderim"]')).show();
        }

        /* ---------------------------------------------------- Gizli bilgi modalı */
        function gizliGoster(tur, deger, firma) {
            const anahtar = tur === 'anahtar';
            $('#gizliBaslik').html(`<i class="bi bi-shield-lock"></i> ${anahtar ? 'API Anahtarı' : 'Bildirim Gizli Anahtarı'}`);
            $('#gizliAciklama').text(firma ? `Firma: ${firma}` : '');
            $('#gizliDeger').val(deger);
            $('#gizliKullanim').html(anahtar
                ? `İsteklerde başlık olarak gönderin: <span class="kod">Authorization: Bearer ${escapeHtml(deger.substring(0, 12))}…</span>`
                : 'Gelen bildirimde <span class="kod">X-KVKK-Imza</span> = <span class="kod">sha256=HMAC-SHA256(X-KVKK-Zaman + "." + gövde, gizli anahtar)</span> olmalı.');
            gizliModal.show();
        }
        function gizliKopyala() {
            const v = $('#gizliDeger').val();
            (navigator.clipboard ? navigator.clipboard.writeText(v) : Promise.reject())
                .then(() => showToast('Kopyalandı', 'success'))
                .catch(() => { $('#gizliDeger').trigger('select'); document.execCommand('copy'); showToast('Kopyalandı', 'success'); });
        }
        function gizliKapat() {
            $('#gizliDeger').val('');
            gizliModal.hide();
        }

        /* ---------------------------------------------------- Gönderimler / log detayı */
        function gonderimDetay(id) {
            istek('gonderim_detay', { id }).done(r => {
                if (!r.success) { showToast(r.message, 'error'); return; }
                const g = r.data;
                $('#detayBaslik').text(`Gönderim #${g.gonderim_id} · ${g.olay_kod}`);
                $('#detayIcerik').html(`
                    <dl class="row mb-3 small">
                        <dt class="col-sm-3">Adres</dt><dd class="col-sm-9 kod">${escapeHtml(g.webhook_adres)}</dd>
                        <dt class="col-sm-3">Oluşturma</dt><dd class="col-sm-9">${zaman(g.tarih)}</dd>
                        <dt class="col-sm-3">Deneme</dt><dd class="col-sm-9">${sayi(g.gonderim_deneme)} · son: ${zaman(g.son_deneme)}</dd>
                        <dt class="col-sm-3">Son yanıt</dt><dd class="col-sm-9">${httpRozet(g.gonderim_son_http_kod)} ${g.gonderim_sure_ms !== null ? sayi(g.gonderim_sure_ms) + ' ms' : ''}</dd>
                        <dt class="col-sm-3">Sonuç</dt><dd class="col-sm-9">${g.basarili_tarih ? 'Başarılı: ' + zaman(g.basarili_tarih)
                            : (g.sonraki_deneme ? 'Sıradaki deneme: ' + zaman(g.sonraki_deneme) : '<span class="text-danger">Denemeler bitti</span>')}</dd>
                        ${g.gonderim_son_hata ? `<dt class="col-sm-3">Son hata</dt><dd class="col-sm-9 text-danger">${escapeHtml(g.gonderim_son_hata)}</dd>` : ''}
                    </dl>
                    <label class="form-label small fw-bold">Gövde</label>
                    <div class="json-kutu">${escapeHtml(jsonGuzel(g.gonderim_govde))}</div>`);
                detayModal.show();
            });
        }
        function gonderimTekrar(id) {
            istek('gonderim_tekrar', { id }).done(r => {
                showToast(r.message, r.success ? 'success' : 'error');
                if (r.success) { tablolar.gonderim.ajax.reload(null, false); loadStats(); }
            });
        }
        function logDetay(id) {
            istek('log_detay', { id }).done(r => {
                if (!r.success) { showToast(r.message, 'error'); return; }
                const l = r.data;
                $('#detayBaslik').text(`İstek #${l.api_log_id}`);
                $('#detayIcerik').html(`
                    <dl class="row mb-3 small">
                        <dt class="col-sm-3">İstek</dt><dd class="col-sm-9 kod">${escapeHtml(l.api_log_metot)} ${escapeHtml(l.api_log_yol)}</dd>
                        <dt class="col-sm-3">Tarih</dt><dd class="col-sm-9">${zaman(l.tarih)} · ${sayi(l.api_log_sure_ms)} ms</dd>
                        <dt class="col-sm-3">Sonuç</dt><dd class="col-sm-9">${httpRozet(l.api_log_http_kod)} ${l.api_log_hata_kod ? `<span class="kod">${escapeHtml(l.api_log_hata_kod)}</span>` : ''}</dd>
                        <dt class="col-sm-3">Anahtar</dt><dd class="col-sm-9">${l.api_anahtar_on_ek ? `<span class="kod">${escapeHtml(l.api_anahtar_on_ek)}…</span> ${escapeHtml(l.api_anahtar_adi)}` : '<span class="text-muted">Geçersiz / yok</span>'}</dd>
                        <dt class="col-sm-3">IP</dt><dd class="col-sm-9 kod">${escapeHtml(l.api_log_ip)}</dd>
                        ${l.api_log_basvuru_id ? `<dt class="col-sm-3">Başvuru</dt><dd class="col-sm-9">#${sayi(l.api_log_basvuru_id)}</dd>` : ''}
                    </dl>
                    <label class="form-label small fw-bold">İstek (kişisel veriler maskeli)</label>
                    <div class="json-kutu mb-3">${escapeHtml(jsonGuzel(l.api_log_istek) || '-')}</div>
                    ${l.api_log_yanit ? `<label class="form-label small fw-bold">Yanıt özeti</label><div class="json-kutu">${escapeHtml(jsonGuzel(l.api_log_yanit))}</div>` : ''}`);
                detayModal.show();
            });
        }

        /* ---------------------------------------------------- Ayarlar */
        function ayarlarAc() {
            $('#ayar_firma_id').val('0').trigger('change.select2');
            ayarYukle();
            ayarModal.show();
        }
        function ayarYukle() {
            const firmaId = $('#ayar_firma_id').val() || '0';
            const firmaModu = firmaId !== '0';
            $('#ayarKapsamNot').text(firmaModu
                ? 'Yalnız firmaya özel olabilen ayarlar listelenir. Boş bırakılan alan üst firmanın, o da yoksa genel değeri kullanır. Alt firmalar bu değeri devralır.'
                : 'Genel değerler, firmaya özel değeri olmayan tüm firmalarda geçerlidir.');
            $('#ayarAlanlari').html('<div class="text-center text-muted py-4"><span class="spinner-border spinner-border-sm"></span> Yükleniyor...</div>');
            istek('ayar_getir', { firma_id: firmaId }).done(r => {
                if (!r.success) { showToast(r.message, 'error'); return; }
                let html = '', grup = null;
                r.data.forEach(a => {
                    if (a.grup !== grup) {
                        if (grup !== null) html += '</div>';
                        grup = a.grup;
                        html += `<div class="ayar-grup-baslik mt-3 mb-2">${escapeHtml(grup)}</div><div class="row g-3">`;
                    }
                    const aralik = (a.min !== null || a.max !== null) ? `${a.min ?? ''} – ${a.max ?? ''}` : '';
                    const yer = firmaModu ? `${a.miras} (${a.kaynak})` : '';
                    html += `<div class="col-md-6">
                        <label class="form-label small mb-1" for="ayar_${a.kod}">${escapeHtml(a.adi)}</label>
                        <input type="text" class="form-control form-control-sm" id="ayar_${a.kod}" name="ayar[${a.kod}]" value="${escapeHtml(a.deger)}"
                               placeholder="${escapeHtml(yer)}" inputmode="${a.tip === 'sayi' ? 'numeric' : 'text'}" ${firmaModu ? '' : 'required'}>
                        <div class="form-text">${escapeHtml(a.aciklama || '')}${a.aciklama && aralik ? ' · ' : ''}${aralik ? 'Aralık: ' + aralik : ''}${a.tip === 'sayi_liste' ? ' · Virgülle ayrılmış' : ''}</div>
                    </div>`;
                });
                if (grup !== null) html += '</div>';
                $('#ayarAlanlari').html(html || '<div class="text-muted">Ayar yok.</div>');
            });
        }

        /* ---------------------------------------------------- Postman */
        function postmanAc() {
            const $f = $('#postman_firma_id');
            if (!$f.val() && $f.find('option[value!=""]').length) $f.val($f.find('option[value!=""]').first().val()).trigger('change.select2');
            postmanFormYukle();
            postmanModal.show();
        }
        function postmanFormYukle() {
            const firmaId = $('#postman_firma_id').val();
            const $s = $('#postman_form').empty().trigger('change.select2');
            if (!firmaId) return;
            istek('postman_form_list', { firma_id: firmaId }).done(r => {
                if (!r.success) { showToast(r.message, 'error'); return; }
                if (!r.data.length) $s.append(new Option('Aktif form yok (genel örnek)', '', true, true));
                r.data.forEach((f, i) => $s.append(new Option(f.form_adi + (f.form_sms_onay == 1 ? ' (SMS doğrulamalı)' : ''), f.form_uniqid, i === 0, i === 0)));
                $s.trigger('change.select2');
            });
        }
        function postmanIndir() {
            const firmaId = $('#postman_firma_id').val();
            if (!firmaId) { showToast('Firma seçin', 'warning'); return; }
            window.location.href = '?' + $.param({ postman: 1, firma_id: firmaId, form: $('#postman_form').val() || '' });
        }

        /* ---------------------------------------------------- Kurulum */
        $(document).ready(function() {
            anahtarModal = new bootstrap.Modal('#anahtarModal');
            webhookModal = new bootstrap.Modal('#webhookModal');
            gizliModal = new bootstrap.Modal('#gizliModal');
            detayModal = new bootstrap.Modal('#detayModal');
            if (document.getElementById('ayarModal')) ayarModal = new bootstrap.Modal('#ayarModal');
            postmanModal = new bootstrap.Modal('#postmanModal');
            $('#postman_firma_id').select2({ theme: 'bootstrap-5', width: '100%', placeholder: 'Firma seçin', dropdownParent: $('#postmanModal') }).on('change', postmanFormYukle);
            $('#postman_form').select2({ theme: 'bootstrap-5', width: '100%', dropdownParent: $('#postmanModal') });

            if (TEK_FIRMA) $('.firma-filtre-alan').hide();

            $('.select2-filter').select2({ theme: 'bootstrap-5', width: '100%', placeholder: 'Tümü', allowClear: true });
            $('#anahtar_firma_id').select2({ theme: 'bootstrap-5', width: '100%', placeholder: 'Firma seçin', dropdownParent: $('#anahtarModal') });
            $('#webhook_firma_id').select2({ theme: 'bootstrap-5', width: '100%', placeholder: 'Firma seçin', dropdownParent: $('#webhookModal') });
            $('#webhook_olaylar').select2({ theme: 'bootstrap-5', width: '100%', placeholder: 'Olay seçin', closeOnSelect: false, dropdownParent: $('#webhookModal') });
            $('#ayar_firma_id').select2({ theme: 'bootstrap-5', width: '100%', dropdownParent: $('#ayarModal') }).on('change', ayarYukle);

            tablolar.anahtar = $('#anahtarTable').DataTable({
                language: DT_DIL, dom: 'lrtip', scrollX: true, pageLength: 25, order: [],
                columns: [
                    { data: 'firma_adi', visible: !TEK_FIRMA, render: v => escapeHtml(v) },
                    { data: 'api_anahtar_adi', render: v => `<b>${escapeHtml(v)}</b>` },
                    { data: 'api_anahtar_on_ek', render: v => `<span class="kod">${escapeHtml(v)}…</span>` },
                    { data: 'api_anahtar_ip_listesi', render: v => v ? `<span class="kod small">${escapeHtml(v)}</span>` : '<span class="text-muted">Tüm IP\'ler</span>' },
                    { data: 'istek_24s', className: 'text-end', render: v => sayi(v) },
                    { data: 'son_kullanim', render: (v, t, r) => v ? zaman(v) + (r.api_anahtar_son_ip ? `<br><small class="kod text-muted">${escapeHtml(r.api_anahtar_son_ip)}</small>` : '') : '<span class="text-muted">Hiç</span>' },
                    { data: 'olusturma', render: zaman },
                    { data: 'iptal_tarihi', render: (v, t, r) => v ? `<span class="badge text-bg-danger">İptal</span><br><small class="text-muted">${zaman(v)}</small>`
                        : (r.Durum == 1 ? '<span class="badge text-bg-success">Aktif</span>' : '<span class="badge text-bg-secondary">Pasif</span>') },
                    { data: null, orderable: false, className: 'text-center text-nowrap', render: (v, t, r) => {
                        const iptal = !!r.iptal_tarihi;
                        const etiket = `${r.api_anahtar_on_ek}… ${r.api_anahtar_adi}`;
                        return islemMenu('Loglar', `logaGit('anahtar_id', ${r.api_anahtar_id})`, [
                            permissions.canEdit && !iptal ? menuOge('bi-pencil', 'Düzenle', `anahtarDuzenle(${r.api_anahtar_id})`) : '',
                            permissions.canDelete && !iptal ? '<li><hr class="dropdown-divider"></li>' + menuOge('bi-x-octagon', 'İptal Et', `anahtarIptal(${r.api_anahtar_id}, ${escapeHtml(JSON.stringify(etiket))})`, 'text-danger') : ''
                        ]);
                    }}
                ],
                createdRow: (row, r) => { if (r.iptal_tarihi) $(row).addClass('satir-pasif'); }
            });

            tablolar.webhook = $('#webhookTable').DataTable({
                language: DT_DIL, dom: 'lrtip', scrollX: true, pageLength: 25, order: [],
                columns: [
                    { data: 'firma_adi', visible: !TEK_FIRMA, render: v => escapeHtml(v) },
                    { data: 'webhook_adres', render: v => `<span class="kod adres-kisa" title="${escapeHtml(v)}">${escapeHtml(v)}</span>` },
                    { data: 'olaylar', orderable: false, render: v => v.map(o => `<span class="badge text-bg-light border me-1" title="${escapeHtml(o.kod)}">${escapeHtml(o.adi)}</span>`).join('') },
                    { data: 'son_basarili', render: zaman },
                    { data: 'webhook_ardisik_hata', className: 'text-end', render: (v, t, r) =>
                        (v > 0 ? `<span class="text-danger fw-bold">${sayi(v)}</span>` : '0') + (r.hata_pasif ? '<br><small class="text-danger">Hata nedeniyle pasif</small>' : '') },
                    { data: 'sirada', className: 'text-end', render: (v, t, r) => v > 0 ? `<a href="#" onclick="gonderimeGit(${r.webhook_id}); return false;">${sayi(v)}</a>` : '0' },
                    { data: 'Durum', orderable: false, render: (v, t, r) => `<div class="form-check form-switch d-flex justify-content-center">
                        <input class="form-check-input" type="checkbox" role="switch" ${v == 1 ? 'checked' : ''} ${permissions.canEdit ? '' : 'disabled'}
                               onchange="webhookDurum(${r.webhook_id}, this.checked, this)"><label class="form-check-label"></label></div>` },
                    { data: null, orderable: false, className: 'text-center text-nowrap', render: (v, t, r) => islemMenu('Gönderimler', `gonderimeGit(${r.webhook_id})`, [
                        permissions.canEdit ? menuOge('bi-lightning', 'Test Gönder', `webhookTest(${r.webhook_id})`) : '',
                        permissions.canEdit ? menuOge('bi-pencil', 'Düzenle', `webhookDuzenle(${r.webhook_id})`) : '',
                        permissions.canEdit ? menuOge('bi-arrow-repeat', 'Gizli Anahtarı Yenile', `webhookGizliYenile(${r.webhook_id})`, 'text-warning') : ''
                    ]) }
                ],
                createdRow: (row, r) => { if (r.Durum != 1) $(row).addClass('satir-pasif'); }
            });

            const sunucuTablo = (secici, action, kolonlar, siralama) => $(secici).DataTable({
                language: DT_DIL, serverSide: true, processing: true, dom: 'lrtip', scrollX: true,
                pageLength: 25, lengthMenu: [[10, 25, 50, 100, 250], [10, 25, 50, 100, 250]], order: siralama,
                ajax: { url: '', type: 'POST',
                        data: d => { d.action = action; Object.assign(d, filtreler[action === 'log_list' ? 'log' : 'gonderim']); },
                        error: () => showToast('Liste yüklenirken hata oluştu', 'error') },
                columns: kolonlar
            });

            const sonucRozet = { basarili: ['success', 'Başarılı'], sirada: ['warning', 'Sırada'], basarisiz: ['danger', 'Başarısız'] };
            tablolar.gonderim = sunucuTablo('#gonderimTable', 'gonderim_list', [
                { data: 'tarih', render: zaman },
                { data: 'firma_adi', orderable: false, visible: !TEK_FIRMA, render: v => escapeHtml(v) },
                { data: 'webhook_adres', orderable: false, render: v => `<span class="kod adres-kisa" title="${escapeHtml(v)}">${escapeHtml(v)}</span>` },
                { data: 'olay_adi', orderable: false, render: (v, t, r) => `<span title="${escapeHtml(r.olay_kod)}">${escapeHtml(v)}</span>` },
                { data: 'gonderim_basvuru_id', className: 'text-end', render: v => v ? '#' + sayi(v) : '-' },
                { data: 'gonderim_deneme', className: 'text-end', render: v => sayi(v) },
                { data: 'gonderim_son_http_kod', render: httpRozet },
                { data: 'sonuc', orderable: false, render: (v, t, r) => {
                    const [renk, ad] = sonucRozet[v];
                    const alt = v === 'sirada' ? `Sıradaki: ${zaman(r.sonraki_deneme)}` : (v === 'basarisiz' && r.gonderim_son_hata ? escapeHtml(r.gonderim_son_hata.substring(0, 60)) : '');
                    return `<span class="badge text-bg-${renk}">${ad}</span>${alt ? `<br><small class="text-muted">${alt}</small>` : ''}`;
                }},
                { data: null, orderable: false, className: 'text-center text-nowrap', render: (v, t, r) => islemMenu('Detay', `gonderimDetay(${r.gonderim_id})`, [
                    permissions.canEdit && r.sonuc !== 'basarili' && r.webhook_durum == 1 ? menuOge('bi-arrow-clockwise', 'Şimdi Yeniden Gönder', `gonderimTekrar(${r.gonderim_id})`) : ''
                ]) }
            ], [[0, 'desc']]);

            tablolar.log = sunucuTablo('#logTable', 'log_list', [
                { data: 'tarih', render: zaman },
                { data: 'firma_adi', orderable: false, visible: !TEK_FIRMA, render: v => v ? escapeHtml(v) : '<span class="text-muted">-</span>' },
                { data: 'api_anahtar_on_ek', orderable: false, render: (v, t, r) => v ? `<span class="kod" title="${escapeHtml(r.api_anahtar_adi)}">${escapeHtml(v)}…</span>` : '<span class="text-muted">Geçersiz</span>' },
                { data: 'api_log_metot', orderable: false, render: v => `<span class="badge text-bg-${v === 'GET' ? 'info' : 'primary'}">${escapeHtml(v)}</span>` },
                { data: 'api_log_yol', orderable: false, render: v => `<span class="kod adres-kisa" title="${escapeHtml(v)}">${escapeHtml(v)}</span>` },
                { data: 'api_log_http_kod', render: httpRozet },
                { data: 'api_log_hata_kod', orderable: false, render: v => v ? `<span class="kod small">${escapeHtml(v)}</span>` : '' },
                { data: 'api_log_sure_ms', className: 'text-end', render: v => v !== null ? sayi(v) : '-' },
                { data: 'api_log_ip', orderable: false, render: v => `<span class="kod small">${escapeHtml(v)}</span>` },
                { data: 'api_log_basvuru_id', orderable: false, className: 'text-end', render: v => v ? '#' + sayi(v) : '' },
                { data: null, orderable: false, className: 'text-center', render: (v, t, r) =>
                    `<button type="button" class="btn btn-sm btn-primary" onclick="logDetay(${r.api_log_id})"><i class="bi bi-eye"></i></button>` }
            ], [[0, 'desc']]);

            // Gizli sekmede kurulan scrollX tablolarının başlık genişliği sekme açılınca düzeltilir
            $('#apiSekmeler button[data-bs-toggle="tab"]').on('shown.bs.tab', () => {
                Object.values(tablolar).forEach(t => t.columns.adjust());
            });

            // Filtre formları
            $('.filtre-form').on('submit', function(e) {
                e.preventDefault();
                const tablo = $(this).data('tablo');
                const f = {};
                $(this).serializeArray().forEach(a => { if (a.value !== '') f[a.name] = a.value; });
                filtreler[tablo] = f;
                if (tablo === 'anahtar') anahtarYukle();
                else if (tablo === 'webhook') webhookYukle();
                else tablolar[tablo].ajax.reload();
                showToast('Filtre uygulandı', 'info');
            });
            $('.filtre-temizle').on('click', function() {
                const $f = $(this).closest('form');
                $f[0].reset();
                $f.find('select').val('').trigger('change.select2');
                $f.trigger('submit');
            });

            $('#anahtarForm').on('submit', function(e) {
                e.preventDefault();
                const yeni = !$('#anahtar_id').val();
                istek('anahtar_kaydet', Object.fromEntries(new FormData(this))).done(r => {
                    if (!r.success) { showToast(r.message, 'error'); return; }
                    anahtarModal.hide();
                    showToast(r.message, 'success');
                    anahtarYukle();
                    loadStats();
                    if (yeni && r.anahtar) gizliGoster('anahtar', r.anahtar, r.firma);
                });
            });

            $('#webhookForm').on('submit', function(e) {
                e.preventDefault();
                const veri = { id: $('#webhook_id').val(), firma_id: $('#webhook_firma_id').val(), adres: $('#webhook_adres').val(),
                               olaylar: $('#webhook_olaylar').val() || [], durum: $('#webhook_durum').is(':checked') ? 1 : 0 };
                istek('webhook_kaydet', veri).done(r => {
                    if (!r.success) { showToast(r.message, 'error'); return; }
                    webhookModal.hide();
                    showToast(r.message, 'success');
                    webhookYukle();
                    loadStats();
                    if (r.gizli) gizliGoster('webhook', r.gizli, r.firma);
                });
            });

            $('#ayarForm').on('submit', function(e) {
                e.preventDefault();
                istek('ayar_kaydet', $(this).serialize().split('&').reduce((o, p) => {
                    const [k, v] = p.split('=').map(x => decodeURIComponent((x || '').replace(/\+/g, ' ')));
                    o[k] = v; return o;
                }, {})).done(r => {
                    showToast(r.message, r.success ? 'success' : 'error');
                    if (r.success) ayarYukle();
                });
            });

            listeleriYukle();
            anahtarYukle();
            webhookYukle();
            loadStats();
        });
    </script>
</body>
</html>
