<?php
/**
 * Cron Sistemi — çekirdek yardımcılar + görev fonksiyonları
 *
 * İçerik:
 *   - cronKey()            : runner/worker/panel ortak gizli anahtar (DB'den, tek merkez)
 *   - cronEslesiyor()      : 5 alanlı cron ifadesi ayrıştırıcı
 *   - cronLogOlustur/Bitir : CronCalismaLog yardımcıları
 *   - dinamikTarih/ParamCoz: {bugun}, {dun} vb. yer tutucular
 *   - gorevCalistir()      : GorevKodu → fonksiyon dispatcher
 *   - Görev fonksiyonları  : bildirim_outbox, destek_yaniti, log_temizle, sms_kredi_guncelle
 *
 * Görev sözleşmesi:
 *   function gorevX(array $params, $db): array
 *   → ['durum' => 1|2, 'sonuc' => 'özet', 'cikti' => 'ob çıktısı']  (1=başarılı, 2=hata)
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/Bildirim.php';
require_once __DIR__ . '/../includes/TicketApi.php';
require_once __DIR__ . '/../includes/SmsApi.php';

if (!defined('APP_TIMEZONE')) {
    define('APP_TIMEZONE', 'Europe/Istanbul');
}
date_default_timezone_set(APP_TIMEZONE);

// ================================================================
// Gizli anahtar — Entegrasyonlar (kod 'cron') → EntegrasyonKanallari
// kanal_ayarlar JSON: {"key":"..."}
// ================================================================
function cronKey(Database $db): ?string
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $row = $db->fetchOne("
        SELECT TOP 1 k.kanal_ayarlar
        FROM Entegrasyonlar e
        INNER JOIN EntegrasyonKanallari k ON k.kanal_entegrasyon_id = e.entegrasyon_id
        WHERE e.entegrasyon_kod = 'cron'
          AND e.entegrasyon_durum = 1
          AND k.kanal_durum = 1
        ORDER BY k.kanal_varsayilan DESC, k.kanal_id ASC
    ");

    if (!$row || empty($row['kanal_ayarlar'])) {
        return null;
    }

    $ayar = json_decode($row['kanal_ayarlar'], true);
    $cache = $ayar['key'] ?? null;
    return $cache;
}

/** Web isteğinde key doğrulaması; CLI/komut satırında key istenmez.
 *  Plesk "Run a PHP script" modu php-cgi kullanabilir (SAPI 'cli' olmaz) —
 *  HTTP bağlamı yoksa (REMOTE_ADDR boş) komut satırı kabul edilir. */
function cronKeyDogrula(Database $db): bool
{
    if (PHP_SAPI === 'cli' || empty($_SERVER['REMOTE_ADDR'])) {
        return true;
    }
    $beklenen = cronKey($db);
    $gelen = $_GET['key'] ?? '';
    return $beklenen !== null && $gelen !== '' && hash_equals($beklenen, $gelen);
}

// ================================================================
// Cron ifadesi ayrıştırıcı (5 alan: dk sa gün ay haftagünü)
// Destek: * · 30 · 0,30 · 1-5 · */15 · 10-50/5 · kombinasyonlar
// ================================================================
function cronEslesiyor(string $ifade, DateTime $dt): bool
{
    $parts = preg_split('/\s+/', trim($ifade));
    if (count($parts) !== 5) return false;
    [$dk, $sa, $gun, $ay, $hgn] = $parts;
    return cronAlanEslesiyor($dk,  (int)$dt->format('i'))
        && cronAlanEslesiyor($sa,  (int)$dt->format('G'))
        && cronAlanEslesiyor($gun, (int)$dt->format('j'))
        && cronAlanEslesiyor($ay,  (int)$dt->format('n'))
        && cronAlanEslesiyor($hgn, (int)$dt->format('w'));   // 0=Pazar
}

function cronAlanEslesiyor(string $alan, int $deger): bool
{
    if ($alan === '*') return true;
    foreach (explode(',', $alan) as $parca) {
        if (str_contains($parca, '/')) {
            [$aralik, $adim] = explode('/', $parca, 2);
            [$bas, $son] = $aralik === '*' ? [0, 59] : array_map('intval', explode('-', $aralik));
            if ($deger >= $bas && $deger <= $son && ($deger - $bas) % (int)$adim === 0) return true;
        } elseif (str_contains($parca, '-')) {
            [$bas, $son] = array_map('intval', explode('-', $parca));
            if ($deger >= $bas && $deger <= $son) return true;
        } elseif ((int)$parca === $deger) {
            return true;
        }
    }
    return false;
}

// ================================================================
// Log yardımcıları
// ================================================================
function cronLogOlustur($db, int $gorevId, ?int $zamanlamaId, array $params, int $tetikTur, ?int $kullanici = null): int
{
    $simdi = date('Y-m-d H:i:s');
    return (int)$db->insert('CronCalismaLog', [
        'CronCalismaLog_GorevId'             => $gorevId,
        'CronCalismaLog_ZamanlamaId'         => $zamanlamaId,
        'CronCalismaLog_BaslangicTarihi'     => $simdi,
        'CronCalismaLog_Parametreler'        => $params ? json_encode($params, JSON_UNESCAPED_UNICODE) : null,
        'CronCalismaLog_CalismaDurum'        => 0,
        'CronCalismaLog_TetikleyenTur'       => $tetikTur,
        'CronCalismaLog_TetikleyenKullanici' => $kullanici,
        'OlusturanKullanici'                 => $kullanici ?? 0,
        'OlusturmaTarihi'                    => $simdi,
        'GuncelleyenKullanici'               => $kullanici ?? 0,
        'GuncellemeTarihi'                   => $simdi,
        'Durum'                              => 1,
    ]);
}

function cronLogBitir($db, int $logId, int $durum, string $sonuc, float $basZaman): void
{
    $simdi = date('Y-m-d H:i:s');
    $db->update('CronCalismaLog', [
        'CronCalismaLog_BitisTarihi'  => $simdi,
        'CronCalismaLog_SureSaniye'   => (int)(microtime(true) - $basZaman),
        'CronCalismaLog_CalismaDurum' => $durum,
        'CronCalismaLog_Sonuc'        => mb_substr($sonuc, 0, 2000),
        'GuncelleyenKullanici'        => 0,
        'GuncellemeTarihi'            => $simdi,
    ], ['CronCalismaLog_Id' => $logId]);
}

// ================================================================
// Dinamik tarih parametreleri ({bugun}, {dun} vb. → dd.mm.yyyy)
// ================================================================
function dinamikTarih(string $deger): string
{
    $bugun = new DateTime();
    $map = [
        '{bugun}'         => fn() => $bugun->format('d.m.Y'),
        '{dun}'           => fn() => (clone $bugun)->modify('-1 day')->format('d.m.Y'),
        '{7gun_once}'     => fn() => (clone $bugun)->modify('-7 days')->format('d.m.Y'),
        '{30gun_once}'    => fn() => (clone $bugun)->modify('-30 days')->format('d.m.Y'),
        '{ay_basi}'       => fn() => $bugun->format('01.m.Y'),
        '{ay_sonu}'       => fn() => (new DateTime('last day of this month'))->format('d.m.Y'),
        '{gecen_ay_basi}' => fn() => (new DateTime('first day of last month'))->format('d.m.Y'),
        '{gecen_ay_sonu}' => fn() => (new DateTime('last day of last month'))->format('d.m.Y'),
        '{3ay_once}'      => fn() => (clone $bugun)->modify('-3 months')->format('d.m.Y'),
        '{6ay_once}'      => fn() => (clone $bugun)->modify('-6 months')->format('d.m.Y'),
    ];
    return isset($map[$deger]) ? ($map[$deger])() : $deger;
}

function dinamikParamCoz(array $params): array
{
    return array_map(fn($v) => is_string($v) ? dinamikTarih($v) : $v, $params);
}

// ================================================================
// Dispatcher — GorevKodu → fonksiyon
// Yeni görev eklemek: fonksiyonu yaz + buraya eşle + CronGorevler'e kayıt ekle
// ================================================================
function gorevCalistir(string $gorevKodu, array $params, $db): array
{
    $eslesme = [
        'bildirim_outbox'    => 'gorevBildirimOutbox',
        'destek_yaniti'      => 'gorevDestekYaniti',
        'log_temizle'        => 'gorevLogTemizle',
        'sms_kredi_guncelle' => 'gorevSmsKrediGuncelle',
    ];

    if (!isset($eslesme[$gorevKodu])) {
        return ['durum' => 2, 'sonuc' => 'Bilinmeyen görev kodu: ' . $gorevKodu, 'cikti' => ''];
    }

    try {
        return $eslesme[$gorevKodu](dinamikParamCoz($params), $db);
    } catch (Throwable $e) {
        return ['durum' => 2, 'sonuc' => 'Hata: ' . $e->getMessage(), 'cikti' => ''];
    }
}

// ================================================================
// GÖREV: bildirim_outbox
// push_gonderildi=0 bildirimleri push'lar (trigger + cron deseni)
// ================================================================
function gorevBildirimOutbox(array $params, $db): array
{
    ob_start();

    $bekleyenler = $db->fetchAll("
        SELECT TOP 50 bildirim_id, bildirim_kullanici_id, bildirim_baslik, bildirim_govde, bildirim_url
        FROM Bildirimler
        WHERE Durum = 1
          AND bildirim_push_gonder = 1
          AND bildirim_push_gonderildi = 0
        ORDER BY bildirim_id ASC
    ");

    echo "Bekleyen bildirim: " . count($bekleyenler) . "\n";

    $gonderilen = 0;
    foreach ($bekleyenler as $b) {
        $veri = [
            'baslik' => $b['bildirim_baslik'],
            'govde'  => $b['bildirim_govde'] ?? '',
            'url'    => $b['bildirim_url'] ?: '/admin/',
        ];

        if ($b['bildirim_kullanici_id'] !== null) {
            $r = PwaHelper::kullaniciyaGonder($db, (int)$b['bildirim_kullanici_id'], $veri);
        } else {
            $r = PwaHelper::tumCihazlaraGonder($db, $veri);
        }

        $db->execute("UPDATE Bildirimler SET bildirim_push_gonderildi = 1 WHERE bildirim_id = ?", [$b['bildirim_id']]);
        $gonderilen++;
        echo "#{$b['bildirim_id']} → {$r['gonderilen']}/{$r['cihaz']} cihaz\n";
    }

    return [
        'durum' => 1,
        'sonuc' => count($bekleyenler) . ' bekleyen, ' . $gonderilen . ' gönderildi',
        'cikti' => ob_get_clean(),
    ];
}

// ================================================================
// GÖREV: destek_yaniti
// Destek ekibi yanıt verdiyse kullanıcıya bildirim (dedup: Bildirimler)
// ================================================================
function gorevDestekYaniti(array $params, $db): array
{
    ob_start();

    $olusan = 0;
    $incelenen = 0;
    $hatalar = [];

    $kullanicilar = $db->fetchAll("
        SELECT kullanici_id, kullanici_email
        FROM kullanicilar
        WHERE kullanici_durum = 1
          AND kullanici_email IS NOT NULL
          AND kullanici_email <> ''
    ");

    foreach ($kullanicilar as $k) {
        $api = new TicketApi($k['kullanici_id']);
        if (!$api->hazirMi()) {
            return ['durum' => 2, 'sonuc' => $api->getHata(), 'cikti' => ob_get_clean()];
        }

        $sonuc = $api->call([
            'action' => 'list_tickets',
            'eposta' => $k['kullanici_email'],
        ]);

        if (!($sonuc['success'] ?? false)) {
            $hatalar[] = $k['kullanici_email'] . ': ' . ($sonuc['message'] ?? 'API hatası');
            continue;
        }

        foreach (($sonuc['data'] ?? []) as $t) {
            $ticketId = (int)($t['Tickets_id'] ?? 0);
            $sonYanit = $t['son_yanit_tarihi'] ?? null;
            if ($ticketId <= 0 || !$sonYanit) {
                continue;
            }
            $incelenen++;

            $url = '/admin/pages/destek-detay.php?id=' . $ticketId;

            // Bu ticket için en son "Destek Yanıtı" bildirimi (dedup)
            $sonBildirim = $db->fetchOne("
                SELECT TOP 1 CONVERT(VARCHAR(19), OlusturmaTarihi, 120) AS tarih
                FROM Bildirimler
                WHERE bildirim_kullanici_id = ?
                  AND bildirim_url = ?
                  AND bildirim_baslik LIKE N'Destek Yanıtı%'
                ORDER BY bildirim_id DESC
            ", [$k['kullanici_id'], $url]);

            if ($sonBildirim) {
                if ($sonYanit <= $sonBildirim['tarih']) {
                    continue; // yeni yanıt yok
                }
            } else {
                // İlk görülen ticket → sadece son 2 saatteki yanıtı bildir (flood önleme)
                if (strtotime($sonYanit) < time() - 2 * 3600) {
                    continue;
                }
            }

            // Son mesajın kaynağını doğrula (kendi mesajımıza bildirim atma)
            $detay = $api->call([
                'action'    => 'ticket_detail',
                'ticket_id' => $ticketId,
                'eposta'    => $k['kullanici_email'],
                'kullanici' => $api->kullaniciNesnesi($k['kullanici_id']),
            ]);

            if (!($detay['success'] ?? false)) {
                continue;
            }

            $mesajlar = $detay['data']['mesajlar'] ?? [];
            $sonMesaj = end($mesajlar);
            if (!$sonMesaj || (($sonMesaj['mesaj_kaynak'] ?? '') === 'api')) {
                continue; // son mesaj bizim
            }

            $ticketNo = $t['Tickets_no'] ?? ('#' . $ticketId);
            $konu     = $t['Tickets_konu'] ?? '';

            Bildirim::olustur($db, [
                'kullanici_id' => (int)$k['kullanici_id'],
                'baslik'       => 'Destek Yanıtı (' . $ticketNo . ')',
                'govde'        => 'Destek ekibi talebinize yanıt verdi: ' . mb_substr($konu, 0, 100),
                'url'          => $url,
                'tip'          => 'info',
                'push'         => true,
            ]);
            $olusan++;
            echo "Bildirim: {$ticketNo} → kullanıcı {$k['kullanici_id']}\n";
        }
    }

    echo "İncelenen ticket: $incelenen, oluşan bildirim: $olusan\n";
    foreach ($hatalar as $h) {
        echo "HATA: $h\n";
    }

    return [
        'durum' => empty($hatalar) ? 1 : 2,
        'sonuc' => "İncelenen: $incelenen, oluşan: $olusan" . ($hatalar ? ', hata: ' . count($hatalar) : ''),
        'cikti' => ob_get_clean(),
    ];
}

// ================================================================
// GÖREV: log_temizle
// Eski CronCalismaLog + Sistem_DegisiklikLog kayıtlarını siler
// gun parametresi verilmezse site ayarlarındaki Log Saklama (gün) kullanılır
// ================================================================
function gorevLogTemizle(array $params, $db): array
{
    ob_start();

    // Öncelik: parametre → site ayarı → varsayılan 30
    $gun = (int)($params['gun'] ?? 0);
    if ($gun <= 0) {
        $ayar = $db->fetchOne("SELECT TOP 1 site_ayarlari_log_retention_days FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
        $gun = (int)($ayar['site_ayarlari_log_retention_days'] ?? 30);
    }
    $gun = max(7, $gun); // güvenlik: en az 7 gün saklanır

    $tablolar = [
        'CronCalismaLog'       => 'CronCalismaLog_BaslangicTarihi',
        'Sistem_DegisiklikLog' => 'log_tarih',
    ];

    $toplam = 0;
    foreach ($tablolar as $tablo => $tarihKolonu) {
        $adet = $db->fetchOne("
            SELECT COUNT(*) AS a FROM $tablo
            WHERE $tarihKolonu < DATEADD(DAY, -?, GETDATE())
        ", [$gun])['a'] ?? 0;

        $db->execute("
            DELETE FROM $tablo
            WHERE $tarihKolonu < DATEADD(DAY, -?, GETDATE())
        ", [$gun]);

        $toplam += $adet;
        echo "$tablo: $gun günden eski $adet kayıt silindi\n";
    }

    // SmsLog kayıtları KVKK kanıtı olduğu için silinmez;
    // yalnızca süresi geçmiş doğrulama kodlarının hash'i düşürülür
    $kodAdet = $db->fetchOne("
        SELECT COUNT(*) AS a FROM SmsLog
        WHERE smslog_kod_hash IS NOT NULL AND smslog_gecerlilik < GETDATE()
    ")['a'] ?? 0;

    $db->execute("
        UPDATE SmsLog
        SET smslog_kod_hash = NULL, smslog_kod_kullanildi = 1, GuncellemeTarihi = GETDATE()
        WHERE smslog_kod_hash IS NOT NULL AND smslog_gecerlilik < GETDATE()
    ");

    echo "SmsLog: süresi geçmiş $kodAdet doğrulama kodu temizlendi (kayıtlar korundu)\n";

    return ['durum' => 1, 'sonuc' => "$toplam kayıt silindi ($gun gün), $kodAdet kod temizlendi", 'cikti' => ob_get_clean()];
}

// ================================================================
// GÖREV: sms_kredi_guncelle
// Aktif SMS hesaplarının kredisini operatörden sorgulayıp
// SmsHesaplari tablosuna yazar (bağlantı testi sonucu da güncellenir).
// Parametreler: firma_id (isteğe bağlı), esik (uyarı eşiği, isteğe bağlı)
// ================================================================
function gorevSmsKrediGuncelle(array $params, $db): array
{
    ob_start();

    $firmaId = (int)($params['firma_id'] ?? 0);
    $esik    = isset($params['esik']) && $params['esik'] !== '' ? (float)$params['esik'] : null;

    $where  = "h.Durum = 1 AND o.Durum = 1";
    $sorguParams = [];
    if ($firmaId > 0) {
        $where .= " AND h.sms_hesap_firma_id = ?";
        $sorguParams[] = $firmaId;
    }

    $hesaplar = $db->fetchAll("
        SELECT h.sms_hesap_id, h.sms_hesap_kullanici_adi, f.firma_adi, o.sms_operator_adi
        FROM SmsHesaplari h
        INNER JOIN SmsOperatorleri o ON h.sms_hesap_operator_id = o.sms_operator_id
        INNER JOIN Firmalar f ON h.sms_hesap_firma_id = f.firma_id
        WHERE $where
        ORDER BY h.sms_hesap_id
    ", $sorguParams);

    echo "Sorgulanacak hesap: " . count($hesaplar) . "\n";

    $basarili = 0;
    $hatali   = 0;
    $dusuk    = [];

    foreach ($hesaplar as $h) {
        $hesap = SmsApi::hesapGetir($h['sms_hesap_id']);
        if (!$hesap) {
            $hatali++;
            continue;
        }

        $sonuc = SmsApi::kredi($hesap);
        $etiket = $h['firma_adi'] . ' / ' . $h['sms_operator_adi'] . ' (' . $h['sms_hesap_kullanici_adi'] . ')';

        if ($sonuc['success'] && $sonuc['kredi'] !== null) {
            SmsApi::testKaydet($h['sms_hesap_id'], true, 'Kredi güncellendi: ' . $sonuc['kredi'], $sonuc['kredi']);
            $basarili++;
            echo "$etiket → " . $sonuc['kredi'] . "\n";

            if ($esik !== null && $sonuc['kredi'] < $esik) {
                $dusuk[] = $etiket . ': ' . $sonuc['kredi'];
            }
        } else {
            $mesaj = $sonuc['success'] ? 'Yanıtta kredi bilgisi bulunamadı' : $sonuc['message'];
            SmsApi::testKaydet($h['sms_hesap_id'], false, $mesaj);
            $hatali++;
            echo "$etiket → HATA: $mesaj\n";
        }
    }

    if ($dusuk) {
        echo "\nEşik altı (<$esik):\n" . implode("\n", $dusuk) . "\n";
    }

    // Doğrulama SMS'i gönderen hesabın bakiyesi eşiğin altındaysa destek talebi aç
    $ticketSonuc = '';
    if ($esik !== null) {
        $ticketSonuc = smsKrediUyariTicket($db, $esik, $params);
        if ($ticketSonuc !== '') {
            echo "\n$ticketSonuc\n";
        }
    }

    $ozet = count($hesaplar) . ' hesap, ' . $basarili . ' güncellendi'
        . ($hatali ? ', ' . $hatali . ' hata' : '')
        . ($dusuk ? ', ' . count($dusuk) . ' hesap eşik altında' : '')
        . ($ticketSonuc !== '' ? ' | ' . $ticketSonuc : '');

    return [
        'durum' => ($hatali > 0 && $basarili === 0) ? 2 : 1,
        'sonuc' => $ozet,
        'cikti' => ob_get_clean(),
    ];
}

/**
 * 2FA doğrulama SMS'inin gönderildiği hesabın bakiyesi eşiğin altındaysa
 * destek talebi açar. Aynı uyarı için 24 saat içinde ikinci talep açılmaz.
 *
 * Parametreler: ticket_kullanici_id, kategori_id, oncelik_id (hepsi isteğe bağlı)
 */
function smsKrediUyariTicket($db, float $esik, array $params): string
{
    $ayar = $db->fetchOne("
        SELECT TOP 1 site_ayarlari_two_factor_enabled, site_ayarlari_two_factor_sms_hesap_id
        FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC
    ");

    $hesapId = (int)($ayar['site_ayarlari_two_factor_sms_hesap_id'] ?? 0);
    if ($hesapId <= 0) {
        return '';
    }

    $hesap = $db->fetchOne("
        SELECT h.sms_hesap_id, h.sms_hesap_kredi, h.sms_hesap_kullanici_adi,
               f.firma_adi, o.sms_operator_adi
        FROM SmsHesaplari h
        INNER JOIN Firmalar f ON h.sms_hesap_firma_id = f.firma_id
        INNER JOIN SmsOperatorleri o ON h.sms_hesap_operator_id = o.sms_operator_id
        WHERE h.sms_hesap_id = ?
    ", [$hesapId]);

    if (!$hesap || $hesap['sms_hesap_kredi'] === null) {
        return '';
    }

    $kredi = (float)$hesap['sms_hesap_kredi'];
    if ($kredi >= $esik) {
        return '';
    }

    // Dedup: son 24 saatte aynı uyarı için talep açıldıysa tekrar açma
    $oncekiUyari = $db->fetchOne("
        SELECT TOP 1 bildirim_id FROM Bildirimler
        WHERE bildirim_baslik LIKE N'SMS Kredi Uyarısı%'
          AND OlusturmaTarihi > DATEADD(HOUR, -24, GETDATE())
        ORDER BY bildirim_id DESC
    ");

    if ($oncekiUyari) {
        return 'Kredi eşik altında ancak son 24 saatte talep açılmış, tekrar açılmadı';
    }

    // Talebi açacak kullanıcı: parametre verilmezse ilk aktif Administrator
    $kullaniciId = (int)($params['ticket_kullanici_id'] ?? 0);
    if ($kullaniciId <= 0) {
        $yonetici = $db->fetchOne("
            SELECT TOP 1 kullanici_id FROM kullanicilar
            WHERE kullanici_durum = 1 AND kullanici_departman_id = 1
              AND kullanici_email IS NOT NULL AND kullanici_email <> ''
            ORDER BY kullanici_id
        ");
        $kullaniciId = (int)($yonetici['kullanici_id'] ?? 0);
    }

    if ($kullaniciId <= 0) {
        return 'Kredi eşik altında ancak talebi açacak kullanıcı bulunamadı';
    }

    $api = new TicketApi($kullaniciId);
    if (!$api->hazirMi()) {
        return 'Kredi eşik altında ancak destek API hazır değil: ' . $api->getHata();
    }

    $kullaniciNesnesi = $api->kullaniciNesnesi($kullaniciId);
    if (!$kullaniciNesnesi) {
        return 'Kredi eşik altında ancak kullanıcı bilgisi alınamadı';
    }

    // Kategori ve öncelik parametre ile verilmediyse API'nin meta listesinden alınır
    $kategoriId = (int)($params['kategori_id'] ?? 0);
    $oncelikId  = (int)($params['oncelik_id'] ?? 0);

    if ($kategoriId <= 0 || $oncelikId <= 0) {
        $meta = $api->call(['action' => 'ticket_meta']);
        if ($meta['success'] ?? false) {
            if ($kategoriId <= 0) {
                $ilkKategori = ($meta['data']['kategoriler'] ?? [])[0] ?? null;
                $kategoriId = (int)($ilkKategori['id'] ?? $ilkKategori['Kategoriler_id'] ?? 0);
            }
            if ($oncelikId <= 0) {
                $oncelikler = $meta['data']['oncelikler'] ?? [];
                $secilen = $oncelikler[min(1, max(0, count($oncelikler) - 1))] ?? null;
                $oncelikId = (int)($secilen['id'] ?? $secilen['Oncelikler_id'] ?? 0);
            }
        }
    }

    $konu = 'SMS kredisi tükenmek üzere - ' . $hesap['firma_adi'];
    $mesaj = "Doğrulama SMS'lerinin gönderildiği hesabın bakiyesi uyarı eşiğinin altına düştü.\n\n"
        . 'Firma: ' . $hesap['firma_adi'] . "\n"
        . 'Operatör: ' . $hesap['sms_operator_adi'] . "\n"
        . 'Hesap: ' . $hesap['sms_hesap_kullanici_adi'] . "\n"
        . 'Kalan kredi: ' . $kredi . "\n"
        . 'Uyarı eşiği: ' . $esik . "\n\n"
        . 'Kredi bittiğinde iki faktörlü doğrulama kodları gönderilemez ve kullanıcılar giriş yapamaz. '
        . 'Kredi yüklemesi yapılmasını rica ederiz.';

    $sonuc = $api->call([
        'action'      => 'create_ticket',
        'kullanici'   => $kullaniciNesnesi,
        'konu'        => $konu,
        'mesaj'       => $mesaj,
        'kategori_id' => $kategoriId,
        'oncelik_id'  => $oncelikId,
    ]);

    if (!($sonuc['success'] ?? false)) {
        return 'Kredi eşik altında ancak talep açılamadı: ' . ($sonuc['message'] ?? 'API hatası');
    }

    $ticketId = (int)($sonuc['data']['ticket_id'] ?? 0);

    Bildirim::olustur($db, [
        'kullanici_id' => $kullaniciId,
        'baslik'       => 'SMS Kredi Uyarısı',
        'govde'        => $hesap['firma_adi'] . ' hesabında ' . $kredi . ' kredi kaldı, destek talebi açıldı.',
        'url'          => '/admin/pages/destek-detay.php?id=' . $ticketId,
        'tip'          => 'warning',
        'push'         => true,
    ]);

    return 'Kredi ' . $kredi . ' (<' . $esik . ') → destek talebi açıldı #' . $ticketId;
}
