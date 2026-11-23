<?php
/**
 * Onay kanıtı: metin anlık görüntüsü + SHA-256 hash
 *
 * - Başvuru anında onaylanan metinler Form_Onay_Metinleri'ne bir kez yazılır (form + tür + hash tekil).
 * - Her metne verilen cevap Form_Basvuru_Onaylari'na yazılır.
 * - Başvurunun değişmeyen alanları, form alan değerleri ve onaylar sabit sıralı bir JSON'a
 *   çevrilir; JSON Form_Basvurulari.form_basvuru_kanit_verisi'ne, SHA-256'sı
 *   form_basvuru_kanit_hash'e yazılır.
 * - Doğrulamada JSON aynı fonksiyonla DB'den yeniden üretilip saklananla karşılaştırılır.
 *
 * Değişen alanlar (dogrulama_durumu: "yenilendi", durum: pasife alma) kanıta dahil DEĞİLDİR.
 */

class OnayKanit
{
    const SURUM = 1;

    public static function metinHash($metin)
    {
        return hash('sha256', (string)$metin);
    }

    /** Yönetici girişli metni güvenli HTML'e çevirir (izinli etiketler, öznitelik yok) */
    public static function metinHtml($metin)
    {
        $metin = (string)$metin;
        if ($metin !== strip_tags($metin)) {
            $html = strip_tags($metin, '<p><br><b><strong><i><em><u><ul><ol><li><h3><h4><h5>');
            return preg_replace('/<(\/?)(\w+)[^>]*>/', '<$1$2>', $html);
        }
        return nl2br(htmlspecialchars($metin, ENT_QUOTES, 'UTF-8'));
    }

    /**
     * Başvurunun kanıt JSON'unu DB'deki güncel verilerden üretir.
     * Kayıt anında (transaction içinde) ve doğrulamada aynı fonksiyon kullanılır.
     * @return string|null Kayıt yoksa null
     */
    public static function veriUret($db, $basvuruId)
    {
        $b = $db->fetchOne("
            SELECT form_basvuru_id, form_id, firma_id,
                   form_basvuru_ad, form_basvuru_soyad, form_basvuru_telefon, form_basvuru_eposta,
                   form_basvuru_ip_adresi, form_basvuru_tarayici,
                   CONVERT(VARCHAR(19), form_basvuru_tarihi, 120) AS basvuru_tarihi,
                   form_basvuru_sms_referans,
                   CONVERT(VARCHAR(19), form_basvuru_sms_dogrulama_tarihi, 120) AS sms_dogrulama_tarihi,
                   form_basvuru_aydinlatma_onay, form_basvuru_acik_riza_onay, form_basvuru_kvkk_onay,
                   form_basvuru_etk_onay, form_basvuru_gps_onay, form_basvuru_api_anahtar_id
            FROM dbo.Form_Basvurulari
            WHERE form_basvuru_id = ?
        ", [(int)$basvuruId]);
        if (!$b) {
            return null;
        }

        $alanlar = $db->fetchAll("
            SELECT form_alan_id, form_basvuru_deger
            FROM dbo.Form_Basvuru_Degerleri
            WHERE form_basvuru_id = ?
            ORDER BY form_alan_id
        ", [(int)$basvuruId]);

        $onaylar = $db->fetchAll("
            SELECT m.form_onay_metin_tur, m.form_onay_metin_id, m.form_onay_metin_hash, o.form_basvuru_onay_kabul
            FROM dbo.Form_Basvuru_Onaylari o
            INNER JOIN dbo.Form_Onay_Metinleri m ON m.form_onay_metin_id = o.form_onay_metin_id
            WHERE o.form_basvuru_id = ?
            ORDER BY m.form_onay_metin_tur, m.form_onay_metin_id
        ", [(int)$basvuruId]);

        $s = fn($v) => $v === null ? null : (string)$v;
        $bit = fn($v) => $v === null ? null : (int)$v;

        $veri = [
            'surum'                => self::SURUM,
            'basvuru_id'           => (int)$b['form_basvuru_id'],
            'form_id'              => (int)$b['form_id'],
            'firma_id'             => (int)$b['firma_id'],
            'ad'                   => $s($b['form_basvuru_ad']),
            'soyad'                => $s($b['form_basvuru_soyad']),
            'telefon'              => $s($b['form_basvuru_telefon']),
            'eposta'               => $s($b['form_basvuru_eposta']),
            'ip'                   => $s($b['form_basvuru_ip_adresi']),
            'tarayici'             => $s($b['form_basvuru_tarayici']),
            'basvuru_tarihi'       => $s($b['basvuru_tarihi']),
            'sms_referans'         => $s($b['form_basvuru_sms_referans']),
            'sms_dogrulama_tarihi' => $s($b['sms_dogrulama_tarihi']),
            'izinler'              => [
                'aydinlatma' => $bit($b['form_basvuru_aydinlatma_onay']),
                'acik_riza'  => $bit($b['form_basvuru_acik_riza_onay']),
                'kvkk'       => $bit($b['form_basvuru_kvkk_onay']),
                'etk'        => $bit($b['form_basvuru_etk_onay']),
                'gps'        => $bit($b['form_basvuru_gps_onay']),
            ],
            'alanlar'              => array_map(fn($a) => [(int)$a['form_alan_id'], $s($a['form_basvuru_deger'])], $alanlar ?: []),
            'onaylar'              => array_map(fn($o) => [
                'tur'        => (string)$o['form_onay_metin_tur'],
                'metin_id'   => (int)$o['form_onay_metin_id'],
                'metin_hash' => (string)$o['form_onay_metin_hash'],
                'kabul'      => (int)$o['form_basvuru_onay_kabul'],
            ], $onaylar ?: []),
        ];

        // API'den gelen kayıt: anahtar ve çağıran sunucunun IP'si de kanıta girer.
        // Blok yalnız API kayıtlarında eklenir; web formu kayıtlarının kanıt JSON'u değişmez.
        if ($b['form_basvuru_api_anahtar_id'] !== null) {
            $bekleyen = $db->fetchOne("
                SELECT bekleyen_cagiran_ip FROM dbo.Api_Bekleyen_Basvurular WHERE bekleyen_basvuru_id = ?
            ", [(int)$basvuruId]);
            $veri['api'] = [
                'anahtar_id' => (int)$b['form_basvuru_api_anahtar_id'],
                'cagiran_ip' => $s($bekleyen['bekleyen_cagiran_ip'] ?? null),
            ];
        }

        return json_encode($veri, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Saklanan kanıtı doğrular.
     * @return array ['durum' => dogrulandi|uyusmazlik|yok, 'mesaj', 'hash', 'olusturma']
     */
    public static function dogrula($db, $basvuruId)
    {
        $k = $db->fetchOne("
            SELECT form_basvuru_kanit_surum, form_basvuru_kanit_verisi, form_basvuru_kanit_hash
            FROM dbo.Form_Basvurulari WHERE form_basvuru_id = ?
        ", [(int)$basvuruId]);

        if (!$k || $k['form_basvuru_kanit_verisi'] === null || $k['form_basvuru_kanit_hash'] === null) {
            return ['durum' => 'yok', 'mesaj' => 'Bu kayıt kanıt sistemi devreye girmeden önce oluşturulmuş.', 'hash' => null];
        }

        $saklanan = (string)$k['form_basvuru_kanit_verisi'];
        $hash = trim((string)$k['form_basvuru_kanit_hash']);
        $sonuc = fn($durum, $mesaj) => ['durum' => $durum, 'mesaj' => $mesaj, 'hash' => $hash];

        if (!hash_equals($hash, self::metinHash($saklanan))) {
            return $sonuc('uyusmazlik', 'Kanıt verisi ile hash uyuşmuyor; kanıt kaydı sonradan değiştirilmiş.');
        }

        $guncel = self::veriUret($db, $basvuruId);
        if ($guncel !== $saklanan) {
            $a = json_decode($saklanan, true) ?: [];
            $g = json_decode((string)$guncel, true) ?: [];
            $farkli = array_keys(array_filter($a, fn($v, $key) => ($g[$key] ?? null) !== $v, ARRAY_FILTER_USE_BOTH));
            return $sonuc('uyusmazlik', 'Başvuru verisi kanıt alındıktan sonra değiştirilmiş: ' . implode(', ', $farkli) . '.');
        }

        // Onaylanan metinlerin içeriği hâlâ kendi hash'iyle aynı mı?
        $metinler = $db->fetchAll("
            SELECT m.form_onay_metin_baslik, m.form_onay_metin_icerik, m.form_onay_metin_hash
            FROM dbo.Form_Basvuru_Onaylari o
            INNER JOIN dbo.Form_Onay_Metinleri m ON m.form_onay_metin_id = o.form_onay_metin_id
            WHERE o.form_basvuru_id = ?
        ", [(int)$basvuruId]);
        foreach ($metinler ?: [] as $m) {
            if (!hash_equals(trim((string)$m['form_onay_metin_hash']), self::metinHash($m['form_onay_metin_icerik']))) {
                return $sonuc('uyusmazlik', $m['form_onay_metin_baslik'] . ' içeriği kanıt alındıktan sonra değiştirilmiş.');
            }
        }

        return $sonuc('dogrulandi', 'Başvuru verisi ve onaylanan metinler kayıt anındaki haliyle aynı.');
    }
}
