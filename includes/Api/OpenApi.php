<?php
/**
 * İzin API OpenAPI 3.1 belgesi (api-dokuman.php?format=json)
 *
 * Elle tutulan bir dosya yerine koddan üretilir; değişebilen değerler kaynağından okunur:
 *   - hata kodları ve HTTP karşılıkları  → ApiHata::KODLAR
 *   - limitler / süreler                → Ayarlar (genel değerler; firmaya özel değer farklı olabilir)
 *   - onay metni anahtarları            → FormBasvuru::METINLER
 *   - webhook olayları                  → tanim_WebhookOlaylari
 */

require_once __DIR__ . '/Api.php';
require_once __DIR__ . '/../FormBasvuru.php';
require_once __DIR__ . '/../Ayarlar.php';

class ApiOpenApi
{
    public static function belge($kokAdres, $siteAdi)
    {
        $db = Database::getInstance();
        $dakikaLimit = Ayarlar::sayi('api_dakika_limit');
        $listeLimit = Ayarlar::sayi('api_liste_limit');
        $bekleyenSaat = Ayarlar::sayi('api_bekleyen_saat');
        $tekrarDk = Ayarlar::liste('webhook_tekrar_dk');
        $zamanAsimi = Ayarlar::sayi('webhook_zaman_asimi_sn');
        $pasifHata = Ayarlar::sayi('webhook_pasif_hata');
        $onayAnahtarlari = array_keys(FormBasvuru::METINLER);
        $olaylar = $db->fetchAll("SELECT olay_kod, olay_adi, olay_aciklama FROM dbo.tanim_WebhookOlaylari WHERE Durum = 1 ORDER BY olay_sira_no, olay_id") ?: [];

        $ref = fn($ad) => ['$ref' => "#/components/schemas/$ad"];
        $zarf = fn($veri) => ['type' => 'object', 'required' => ['basarili', 'veri'], 'properties' => [
            'basarili' => ['type' => 'boolean', 'const' => true],
            'veri'     => $veri,
        ]];
        $yanit = fn($aciklama, $veri) => ['description' => $aciklama, 'content' => ['application/json' => ['schema' => $zarf($veri)]]];
        $hata = function (...$kodlar) {
            $sonuc = [];
            foreach ($kodlar as $kod) {
                $http = (string)ApiHata::KODLAR[$kod];
                $sonuc[$http]['kodlar'][] = $kod;
            }
            $cikti = [];
            foreach ($sonuc as $http => $s) {
                $cikti[$http] = ['description' => implode(', ', $s['kodlar']), 'content' => ['application/json' => [
                    'schema' => ['$ref' => '#/components/schemas/Hata'],
                    'example' => ['basarili' => false, 'hata' => ['kod' => $s['kodlar'][0], 'mesaj' => '...']],
                ]]];
            }
            return $cikti;
        };
        $govde = fn($sema, $ornek) => ['required' => true, 'content' => ['application/json' => ['schema' => $sema, 'example' => $ornek]]];
        $ortak = ['ANAHTAR_GECERSIZ', 'IP_YETKISIZ', 'COK_FAZLA_ISTEK', 'SUNUCU_HATASI'];
        $uniqid = ['name' => 'uniqid', 'in' => 'path', 'required' => true, 'description' => 'Form kodu (`GET /formlar` → `uniqid`)',
                   'schema' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9]{1,32}$'], 'example' => '349162581023'];
        $basvuruId = ['name' => 'id', 'in' => 'path', 'required' => true, 'description' => 'Başvuru numarası',
                      'schema' => ['type' => 'integer', 'minimum' => 1], 'example' => 2499];

        $hataTablo = "| Kod | HTTP |\n|---|---|\n";
        foreach (ApiHata::KODLAR as $kod => $http) {
            $hataTablo .= "| `$kod` | $http |\n";
        }
        $olayTablo = "| Olay | Açıklama |\n|---|---|\n";
        foreach ($olaylar as $o) {
            $olayTablo .= "| `{$o['olay_kod']}` | " . ($o['olay_aciklama'] ?: $o['olay_adi']) . " |\n";
        }

        $tekrarMetni = implode(', ', $tekrarDk);
        $aciklama = <<<MD
{$siteAdi} İzin API'si ile kendi sitenizden, uygulamanızdan veya CRM'inizden KVKK / açık rıza / ETK izni toplayabilir,
izin durumunu sorgulayabilir ve iptal edebilirsiniz. Toplanan her izin, web formundaki gibi onay kanıtıyla (metin hash'i, zaman, IP) saklanır.

## Kimlik doğrulama
Panelde **API Yönetimi › Anahtarlar** bölümünden firmanıza özel anahtar oluşturun. Anahtar yalnız oluşturulduğunda bir kez gösterilir.

    Authorization: Bearer kvk_...

Anahtara IP kısıtı tanımlanabilir. Anahtar başına dakikada en fazla **{$dakikaLimit}** istek gönderilebilir; aşılırsa `429` ve `Retry-After` başlığı döner.

## İzin toplama akışı
1. `GET /formlar` → formlarınız · `GET /formlar/{uniqid}` → alanlar, seçenekler, onay metinleri ve **hash**'leri
2. Müşteriye onay metinlerini gösterin, cevaplarını alın.
3. `POST /formlar/{uniqid}/basvuru` → metin hash'leriyle birlikte gönderin.
   - SMS doğrulamasız form: **201** + `basvuru_id` (kayıt tamam)
   - SMS doğrulamalı form: **202** + `dogrulama_id`; müşteriye SMS kodu gider. Kod {$bekleyenSaat} saat içinde `POST /basvuru/dogrula` ile girilir → **201** + `basvuru_id`
4. Metin panelde değiştiyse **409 `METIN_DEGISTI`** döner; formu yeniden alıp müşteriye güncel metni gösterin.

Aynı telefon aynı forma yeniden onay verirse eski kayıt **yenilendi** durumuna geçer; güncel kayıt yenisidir.

## Yanıt biçimi
Başarılı: `{"basarili": true, "veri": ...}` · Hatalı: `{"basarili": false, "hata": {"kod", "mesaj", ...}}`

Alan hatalarında `hata.alanlar` nesnesi alan koduna göre mesajları içerir.

{$hataTablo}
## Bildirimler (webhook)
Panelde **API Yönetimi › Bildirim Adresleri** bölümünden https adresinizi ve olayları seçin. Olay oluşunca en geç 1 dakika içinde
adresinize `POST` edilir. 2xx dışındaki yanıt, {$zamanAsimi} sn zaman aşımı veya bağlantı hatasında **{$tekrarMetni}** dakika sonra yeniden denenir;
{$pasifHata} ardışık başarısızlıkta adres pasife alınır. Gövdede kişisel veri yoktur; ayrıntı için `GET /basvurular/{id}` kullanın.

{$olayTablo}
Başlıklar: `X-KVKK-Olay`, `X-KVKK-Teslim` (tekrar denemelerde aynı kalır, çift işlemeyi önlemek için kullanın), `X-KVKK-Zaman`, `X-KVKK-Imza`.

İmza: `sha256=` + HMAC-SHA256(`X-KVKK-Zaman` + `.` + ham gövde, gizli anahtar `whsec_...`)

```php
\$govde = file_get_contents('php://input');
\$beklenen = 'sha256=' . hash_hmac('sha256', \$_SERVER['HTTP_X_KVKK_ZAMAN'] . '.' . \$govde, \$gizliAnahtar);
\$gecerli = hash_equals(\$beklenen, \$_SERVER['HTTP_X_KVKK_IMZA'] ?? '')
    && abs(time() - (int)\$_SERVER['HTTP_X_KVKK_ZAMAN']) < 300;
```
MD;

        $izinler = ['type' => 'object', 'description' => '1 = verildi, 0 = verilmedi, null = formda yok',
            'properties' => array_fill_keys(['aydinlatma', 'acik_riza', 'kvkk', 'etk', 'gps'], ['type' => ['integer', 'null'], 'enum' => [0, 1, null]])];

        $semalar = [
            'Hata' => ['type' => 'object', 'required' => ['basarili', 'hata'], 'properties' => [
                'basarili' => ['type' => 'boolean', 'const' => false],
                'hata' => ['type' => 'object', 'required' => ['kod', 'mesaj'], 'properties' => [
                    'kod'            => ['type' => 'string', 'enum' => array_keys(ApiHata::KODLAR)],
                    'mesaj'          => ['type' => 'string'],
                    'alanlar'        => ['type' => 'object', 'additionalProperties' => ['type' => 'string'], 'description' => 'ALAN_HATASI: alan kodu → mesaj'],
                    'guncel_hashler' => ['type' => 'object', 'additionalProperties' => ['type' => 'string'], 'description' => 'METIN_DEGISTI: onay anahtarı → güncel hash'],
                    'bekleme_sn'     => ['type' => 'integer', 'description' => 'Kod tekrarında beklenecek süre'],
                    'basvuru_id'     => ['type' => 'integer', 'description' => 'KOD_GECERSIZ: başvuru zaten tamamlandıysa'],
                ]],
            ]],
            'Form' => ['type' => 'object', 'properties' => [
                'uniqid'            => ['type' => 'string', 'example' => '349162581023'],
                'adi'               => ['type' => 'string', 'example' => 'Çalışan'],
                'sms_dogrulama'     => ['type' => 'boolean'],
                'adres'             => ['type' => 'string', 'format' => 'uri', 'description' => 'Formun web adresi'],
                'olusturma_tarihi'  => ['type' => ['string', 'null'], 'example' => '2026-11-10 09:30:00'],
                'guncelleme_tarihi' => ['type' => ['string', 'null']],
            ]],
            'FormDesen' => ['type' => 'object', 'properties' => [
                'uniqid'        => ['type' => 'string'],
                'adi'           => ['type' => 'string'],
                'firma'         => ['type' => 'string'],
                'adres'         => ['type' => 'string', 'format' => 'uri'],
                'sms_dogrulama' => ['type' => 'boolean'],
                'dogrulama'     => ['type' => ['object', 'null'], 'description' => 'SMS doğrulamasız formda null', 'properties' => [
                    'kod_hane' => ['type' => 'integer'], 'gecerlilik_sn' => ['type' => 'integer'],
                    'tekrar_sn' => ['type' => 'integer'], 'deneme_hakki' => ['type' => 'integer'],
                ]],
                'alanlar' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                    'kod'        => ['type' => 'string', 'example' => 'cep_telefonu', 'description' => 'Başvuru gövdesinde `alanlar` altında kullanılacak anahtar'],
                    'etiket'     => ['type' => 'string'],
                    'tip'        => ['type' => 'string', 'example' => 'tel'],
                    'zorunlu'    => ['type' => 'boolean'],
                    'deger'      => ['type' => 'string', 'description' => 'Beklenen değer biçimi (seçenek id, il id, YYYY-AA-GG)'],
                    'kaynak'     => ['type' => 'string', 'description' => 'İl / ilçe listesinin adresi'],
                    'secenekler' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                        'id' => ['type' => 'integer'], 'adi' => ['type' => 'string'], 'alt_baslik' => ['type' => 'string'],
                        'alt_secenekler' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'adi' => ['type' => 'string']]]],
                    ]]],
                    'alt_alan'   => ['type' => 'string', 'description' => 'Alt seçenekli alanlarda alt seçimin gönderileceği kod (`<kod>_alt`)'],
                ]]],
                'onaylar' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                    'anahtar' => ['type' => 'string', 'enum' => $onayAnahtarlari],
                    'baslik'  => ['type' => 'string'],
                    'zorunlu' => ['type' => 'boolean'],
                    'hash'    => ['type' => 'string', 'description' => 'Metnin SHA-256 özeti; başvuruda aynen geri gönderilir'],
                    'adres'   => ['type' => 'string', 'format' => 'uri', 'description' => 'Metnin web adresi'],
                    'metin'   => ['type' => 'string', 'description' => 'Metin (sınırlı HTML içerebilir)'],
                ]]],
            ]],
            'BasvuruGovde' => ['type' => 'object', 'required' => ['alanlar'], 'properties' => [
                'alanlar' => ['type' => 'object', 'additionalProperties' => ['type' => ['string', 'number', 'boolean', 'null']],
                              'description' => 'Form desenindeki alan kodu → değer'],
                'onaylar' => ['type' => 'object', 'description' => 'Formdaki her onay metni için kabul / ret ve metin hash\'i',
                              'propertyNames' => ['enum' => $onayAnahtarlari],
                              'additionalProperties' => ['type' => 'object', 'required' => ['kabul', 'hash'], 'properties' => [
                                  'kabul' => ['type' => 'boolean'], 'hash' => ['type' => 'string']]]],
                'musteri' => ['type' => 'object', 'description' => 'İsteğe bağlı: müşterinin gerçek bilgileri (kanıt kaydında tutulur)', 'properties' => [
                    'ip' => ['type' => 'string', 'description' => 'IPv4 / IPv6'], 'tarayici' => ['type' => 'string', 'maxLength' => 300]]],
            ]],
            'Izinler' => $izinler,
            'BasvuruOzet' => ['type' => 'object', 'properties' => [
                'id'                   => ['type' => 'integer', 'example' => 2499],
                'form'                 => ['type' => 'object', 'properties' => ['uniqid' => ['type' => 'string'], 'adi' => ['type' => 'string']]],
                'durum'                => ['type' => 'string', 'description' => 'onaylandi · yenilendi · iptal · excel · bekliyor', 'example' => 'onaylandi'],
                'durum_adi'            => ['type' => 'string'],
                'gecerli'              => ['type' => 'boolean', 'description' => 'Güncel ve geçerli izin kaydı mı'],
                'kaynak'               => ['type' => 'string', 'enum' => ['api', 'form', 'excel']],
                'ad'                   => ['type' => ['string', 'null']],
                'soyad'                => ['type' => ['string', 'null']],
                'telefon'              => ['type' => ['string', 'null'], 'example' => '905321234567'],
                'eposta'               => ['type' => ['string', 'null']],
                'izinler'              => array_merge($ref('Izinler'), ['description' => 'Güncel izinler; iptalde açık rıza / KVKK / ETK 0 olur']),
                'verilen_izinler'      => array_merge($ref('Izinler'), ['description' => 'Yalnız iptal edilmiş kayıtta: ilk verilen izinler']),
                'tarih'                => ['type' => 'string', 'example' => '2026-11-23 14:03:09'],
                'sms_dogrulama_tarihi' => ['type' => ['string', 'null']],
            ]],
            'BasvuruDetay' => ['allOf' => [$ref('BasvuruOzet'), ['type' => 'object', 'properties' => [
                'ip'      => ['type' => ['string', 'null']],
                'alanlar' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                    'kod' => ['type' => 'string'], 'etiket' => ['type' => 'string'], 'deger' => ['type' => ['string', 'null']]]]],
                'onaylar' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                    'anahtar' => ['type' => 'string'], 'baslik' => ['type' => 'string'], 'kabul' => ['type' => 'boolean'], 'hash' => ['type' => 'string']]]],
                'kanit'   => ['type' => ['object', 'null'], 'description' => 'Onay kanıtının sürümü ve SHA-256 özeti', 'properties' => [
                    'surum' => ['type' => 'integer'], 'hash' => ['type' => 'string']]],
                'iptal'   => ['type' => ['object', 'null'], 'properties' => [
                    'tarih' => ['type' => 'string'], 'neden' => ['type' => 'string'], 'kaynak' => ['type' => 'string', 'enum' => ['api', 'panel']]]],
            ]]]],
            'Il' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer', 'example' => 35], 'adi' => ['type' => 'string', 'example' => 'İzmir']]],
            'WebhookGovde' => ['type' => 'object', 'properties' => [
                'id'    => ['type' => 'string', 'example' => 'whk_3405cba431dd7b280266c9fb1aa99ffc', 'description' => 'Teslim numarası (X-KVKK-Teslim ile aynı)'],
                'olay'  => ['type' => 'string', 'enum' => array_column($olaylar, 'olay_kod')],
                'tarih' => ['type' => 'string', 'format' => 'date-time'],
                'veri'  => ['type' => 'object', 'properties' => [
                    'basvuru_id' => ['type' => 'integer'],
                    'form'       => ['type' => 'string', 'description' => 'Form uniqid'],
                    'durum'      => ['type' => 'string'],
                    'kaynak'     => ['type' => 'string', 'enum' => ['api', 'form', 'panel']],
                    'onceki_basvuru_idleri' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Yalnız basvuru.yenilendi'],
                ]],
            ]],
        ];

        $yollar = [
            '/formlar' => ['get' => [
                'tags' => ['Formlar'], 'summary' => 'Formları listele', 'operationId' => 'formlar',
                'description' => 'Anahtarın firmasına ait aktif formlar.',
                'responses' => ['200' => $yanit('Formlar', ['type' => 'array', 'items' => $ref('Form')])] + $hata(...$ortak),
            ]],
            '/formlar/{uniqid}' => ['get' => [
                'tags' => ['Formlar'], 'summary' => 'Form deseni', 'operationId' => 'formDeseni', 'parameters' => [$uniqid],
                'description' => 'Alanlar, seçenekler, onay metinleri ve hash\'leri. Başvurudan önce alınmalı; metin hash\'leri başvuruda geri gönderilir.',
                'responses' => ['200' => $yanit('Form deseni', $ref('FormDesen'))] + $hata('FORM_BULUNAMADI', ...$ortak),
            ]],
            '/formlar/{uniqid}/basvuru' => ['post' => [
                'tags' => ['Başvuru'], 'summary' => 'Başvuru gönder', 'operationId' => 'basvuruGonder', 'parameters' => [$uniqid],
                'description' => "SMS doğrulamasız formda kayıt hemen oluşur (**201**). SMS doğrulamalı formda telefona kod gider (**202**); kayıt `POST /basvuru/dogrula` ile tamamlanır.\n\nOnay hash'i güncel değilse **409 `METIN_DEGISTI`** ve `guncel_hashler` döner.",
                'requestBody' => $govde($ref('BasvuruGovde'), [
                    'alanlar' => ['ad' => 'Ayşe', 'soyad' => 'Yılmaz', 'cep_telefonu' => '05321234567'],
                    'onaylar' => ['aydinlatma' => ['kabul' => true, 'hash' => 'eba672be...'], 'acik-riza' => ['kabul' => true, 'hash' => '17172eb4...']],
                    'musteri' => ['ip' => '203.0.113.10', 'tarayici' => 'Mozilla/5.0 ...'],
                ]),
                'responses' => [
                    '201' => $yanit('Kayıt oluştu (SMS doğrulamasız form)', ['type' => 'object', 'properties' => [
                        'basvuru_id' => ['type' => 'integer'], 'durum' => ['type' => 'string', 'const' => 'onaylandi'], 'sms_dogrulama' => ['type' => 'boolean', 'const' => false]]]),
                    '202' => $yanit('SMS kodu gönderildi', ['type' => 'object', 'properties' => [
                        'dogrulama_id'  => ['type' => 'string', 'example' => 'dgr_…', 'description' => 'Doğrulama ve kod tekrarında kullanılır'],
                        'telefon'       => ['type' => 'string', 'description' => 'Maskeli', 'example' => '0532***4567'],
                        'kod_hane'      => ['type' => 'integer'], 'gecerlilik_sn' => ['type' => 'integer'], 'tekrar_sn' => ['type' => 'integer'],
                        'son_tarih'     => ['type' => 'string', 'description' => "Bu tarihe kadar doğrulanmalı ({$bekleyenSaat} saat)"]]]),
                ] + $hata('GOVDE_GECERSIZ', 'FORM_BULUNAMADI', 'METIN_DEGISTI', 'ALAN_HATASI', 'SMS_GONDERILEMEDI', ...$ortak),
            ]],
            '/basvuru/dogrula' => ['post' => [
                'tags' => ['Başvuru'], 'summary' => 'SMS kodunu doğrula', 'operationId' => 'kodDogrula',
                'requestBody' => $govde(['type' => 'object', 'required' => ['dogrulama_id', 'kod'], 'properties' => [
                    'dogrulama_id' => ['type' => 'string', 'pattern' => '^dgr_[a-f0-9]{40}$'], 'kod' => ['type' => 'string']]],
                    ['dogrulama_id' => 'dgr_…', 'kod' => '123456']),
                'responses' => ['201' => $yanit('Kayıt oluştu', ['type' => 'object', 'properties' => [
                    'basvuru_id' => ['type' => 'integer'], 'durum' => ['type' => 'string', 'const' => 'onaylandi']]])]
                    + $hata('GOVDE_GECERSIZ', 'KOD_GECERSIZ', 'KOD_HATALI', 'ALAN_HATASI', 'FORM_BULUNAMADI', ...$ortak),
                'description' => '`KOD_HATALI`: yanlış kod, deneme hakkı var. `KOD_GECERSIZ`: süre doldu, deneme hakkı bitti veya başvuru zaten tamamlandı.',
            ]],
            '/basvuru/kod-tekrar' => ['post' => [
                'tags' => ['Başvuru'], 'summary' => 'Kodu tekrar gönder', 'operationId' => 'kodTekrar',
                'description' => 'Bekleme süresi dolmadan çağrılırsa **429** ve `bekleme_sn` döner.',
                'requestBody' => $govde(['type' => 'object', 'required' => ['dogrulama_id'], 'properties' => ['dogrulama_id' => ['type' => 'string']]],
                    ['dogrulama_id' => 'dgr_…']),
                'responses' => ['200' => $yanit('Yeni kod gönderildi', ['type' => 'object', 'properties' => [
                    'telefon' => ['type' => 'string'], 'gecerlilik_sn' => ['type' => 'integer'], 'tekrar_sn' => ['type' => 'integer']]])]
                    + $hata('GOVDE_GECERSIZ', 'KOD_GECERSIZ', 'ALAN_HATASI', 'SMS_GONDERILEMEDI', ...$ortak),
            ]],
            '/formlar/{uniqid}/basvurular' => ['get' => [
                'tags' => ['Kayıtlar'], 'summary' => 'Form başvuruları', 'operationId' => 'basvurular',
                'description' => 'Sayfalı liste, yeniden eskiye. Yenilenen ve iptal edilen kayıtlar da listelenir (`durum`, `gecerli` alanlarına bakın).',
                'parameters' => [$uniqid,
                    ['name' => 'baslangic', 'in' => 'query', 'schema' => ['type' => 'string', 'format' => 'date'], 'description' => 'YYYY-AA-GG (dahil)'],
                    ['name' => 'bitis', 'in' => 'query', 'schema' => ['type' => 'string', 'format' => 'date'], 'description' => 'YYYY-AA-GG (dahil)'],
                    ['name' => 'sayfa', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'default' => 1]],
                    ['name' => 'limit', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => $listeLimit, 'default' => min(100, $listeLimit)]],
                ],
                'responses' => ['200' => $yanit('Kayıtlar', ['type' => 'object', 'properties' => [
                    'kayitlar'  => ['type' => 'array', 'items' => $ref('BasvuruOzet')],
                    'sayfalama' => ['type' => 'object', 'properties' => [
                        'sayfa' => ['type' => 'integer'], 'limit' => ['type' => 'integer'], 'toplam' => ['type' => 'integer'], 'sayfa_sayisi' => ['type' => 'integer']]],
                ]])] + $hata('FORM_BULUNAMADI', 'ALAN_HATASI', ...$ortak),
            ]],
            '/basvurular/{id}' => ['get' => [
                'tags' => ['Kayıtlar'], 'summary' => 'Başvuru detayı', 'operationId' => 'basvuruDetay', 'parameters' => [$basvuruId],
                'responses' => ['200' => $yanit('Başvuru', $ref('BasvuruDetay'))] + $hata('BASVURU_BULUNAMADI', ...$ortak),
            ]],
            '/basvurular/{id}/iptal' => ['post' => [
                'tags' => ['Kayıtlar'], 'summary' => 'Başvuruyu iptal et', 'operationId' => 'basvuruIptal', 'parameters' => [$basvuruId],
                'description' => "Müşteri iznini geri çektiğinde çağrılır. **Geri alınamaz.** Açık rıza, KVKK ve ETK izinleri geri alınmış sayılır; onay kanıtı değişmeden korunur. Yalnız geçerli kayıt iptal edilebilir.",
                'requestBody' => $govde(['type' => 'object', 'required' => ['neden'], 'properties' => [
                    'neden'   => ['type' => 'string', 'maxLength' => 500],
                    'musteri' => ['type' => 'object', 'properties' => ['ip' => ['type' => 'string']]]]],
                    ['neden' => 'Müşteri telefonla izni geri çekti', 'musteri' => ['ip' => '203.0.113.10']]),
                'responses' => ['200' => $yanit('İptal edilen başvuru', $ref('BasvuruDetay'))]
                    + $hata('GOVDE_GECERSIZ', 'BASVURU_BULUNAMADI', 'IPTAL_EDILEMEZ', 'ALAN_HATASI', ...$ortak),
            ]],
            '/izin-durumu' => ['get' => [
                'tags' => ['Kayıtlar'], 'summary' => 'Telefonun izin durumu', 'operationId' => 'izinDurumu',
                'description' => 'Telefonun her formdaki güncel kaydı ve tüm formlar üzerinden özet (en az bir formda verildiyse `true`).',
                'parameters' => [['name' => 'telefon', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string'], 'example' => '05321234567']],
                'responses' => ['200' => $yanit('İzin durumu', ['type' => 'object', 'properties' => [
                    'telefon' => ['type' => 'string', 'example' => '905321234567'],
                    'izinler' => ['type' => 'object', 'properties' => array_fill_keys(['aydinlatma', 'acik_riza', 'kvkk', 'etk', 'gps'], ['type' => 'boolean'])],
                    'formlar' => ['type' => 'array', 'items' => $ref('BasvuruOzet')],
                ]])] + $hata('ALAN_HATASI', ...$ortak),
            ]],
            '/iller' => ['get' => [
                'tags' => ['Konum'], 'summary' => 'İller', 'operationId' => 'iller',
                'responses' => ['200' => $yanit('İller', ['type' => 'array', 'items' => $ref('Il')])] + $hata(...$ortak),
            ]],
            '/iller/{id}/ilceler' => ['get' => [
                'tags' => ['Konum'], 'summary' => 'İlçeler', 'operationId' => 'ilceler',
                'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer'], 'example' => 35]],
                'responses' => ['200' => $yanit('İlçeler', ['type' => 'array', 'items' => $ref('Il')])] + $hata('ALAN_HATASI', ...$ortak),
            ]],
        ];

        $webhooklar = [];
        foreach ($olaylar as $o) {
            $webhooklar[$o['olay_kod']] = ['post' => [
                'tags' => ['Bildirimler'], 'summary' => $o['olay_adi'], 'description' => (string)$o['olay_aciklama'],
                'parameters' => [
                    ['name' => 'X-KVKK-Olay', 'in' => 'header', 'required' => true, 'schema' => ['type' => 'string', 'const' => $o['olay_kod']]],
                    ['name' => 'X-KVKK-Teslim', 'in' => 'header', 'required' => true, 'schema' => ['type' => 'string'], 'description' => 'Tekrar denemelerde aynı kalır'],
                    ['name' => 'X-KVKK-Zaman', 'in' => 'header', 'required' => true, 'schema' => ['type' => 'integer'], 'description' => 'Unix zamanı'],
                    ['name' => 'X-KVKK-Imza', 'in' => 'header', 'required' => true, 'schema' => ['type' => 'string'], 'description' => 'sha256=HMAC(Zaman.gövde)'],
                ],
                'requestBody' => ['content' => ['application/json' => ['schema' => $ref('WebhookGovde')]]],
                'responses' => ['200' => ['description' => '2xx: teslim alındı'], 'default' => ['description' => 'Diğer yanıtlar hata sayılır, yeniden denenir']],
            ]];
        }

        return [
            'openapi' => '3.1.0',
            'info' => ['title' => "$siteAdi İzin API", 'version' => '1.0', 'description' => $aciklama],
            'servers' => [['url' => rtrim($kokAdres, '/') . '/api']],
            'security' => [['anahtar' => []]],
            'tags' => [
                ['name' => 'Formlar', 'description' => 'Formlar ve form desenleri'],
                ['name' => 'Başvuru', 'description' => 'İzin toplama ve SMS doğrulama'],
                ['name' => 'Kayıtlar', 'description' => 'Kayıt sorgulama ve iptal'],
                ['name' => 'Konum', 'description' => 'İl / ilçe listeleri'],
                ['name' => 'Bildirimler', 'description' => 'Adresinize gönderilen webhook olayları'],
            ],
            'paths' => $yollar,
            'webhooks' => $webhooklar,
            'components' => [
                'securitySchemes' => ['anahtar' => ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'kvk_…',
                    'description' => 'Panelde API Yönetimi › Anahtarlar bölümünde oluşturulan anahtar']],
                'schemas' => $semalar,
            ],
        ];
    }
}
