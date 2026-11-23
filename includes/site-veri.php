<?php
/**
 * Genel site verileri (ön yüz)
 * Menü, footer ve iletişim içerikleri burada tanımlanır.
 */

require_once __DIR__ . '/../admin/db.php';

$siteBaslik  = 'ÖRNEKFİRMA';
$siteFavicon = '/favicon.ico';
try {
    $db   = Database::getInstance();
    $ayar = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title,
                                  site_ayarlari_favicon_url,
                                  site_ayarlari_guncelleme_tarihi
                           FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
    if ($ayar && !empty($ayar['site_ayarlari_site_title'])) {
        $siteBaslik = $ayar['site_ayarlari_site_title'];
    }

    // Favicon: kayıtlı dosya diskte varsa kullanılır, yoksa kök favicon.ico'ya düşülür
    if ($ayar && !empty($ayar['site_ayarlari_favicon_url'])) {
        $faviconYol = '/' . ltrim(str_replace('\\', '/', $ayar['site_ayarlari_favicon_url']), '/');
        if (is_file(dirname(__DIR__) . $faviconYol)) {
            $siteFavicon = $faviconYol;
        }
    }

    // Önbellek kırıcı: ayarlar güncellendikçe tarayıcı ikonu yeniden ister
    if ($ayar && !empty($ayar['site_ayarlari_guncelleme_tarihi'])) {
        $siteFavicon .= '?v=' . strtotime($ayar['site_ayarlari_guncelleme_tarihi']);
    }
} catch (Exception $e) {
    // varsayılan başlık ve ikon kullanılır
}

$esc = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

// Üst menü linkleri
$ustMenu = [
    ['ad' => 'Anasayfa',   'link' => '/'],
    ['ad' => 'KVK Nedir?', 'link' => '/#sik-sorulan-sorular'],
    ['ad' => 'Teklif Al',  'link' => '/#teklif-al'],
];

$girisButonlari = [
    ['ad' => 'İZİN SİSTEMİNE GİRİŞ',      'link' => 'https://portal.ornek-kvkk.com/'],
    ['ad' => 'ENVANTER SİSTEMİNE GİRİŞ',  'link' => 'https://portal.ornek-kvkk.com/'],
];

// Footer
$sosyalMedya = [
    ['ad' => 'Facebook',  'link' => 'https://www.facebook.com/ornekfirmacomtr',
     'ikon' => '<path d="M14 8.5V6.8c0-.7.5-.9.9-.9h2.3V2.5h-3.2c-3.1 0-3.8 2.3-3.8 3.8v2.2H8.1v3.5h2.1V21h3.8v-9h2.6l.4-3.5H14z"/>'],
    ['ad' => 'Twitter',   'link' => 'https://twitter.com/ornekfirmacomtr',
     'ikon' => '<path d="M22 5.9c-.7.3-1.5.5-2.3.6.8-.5 1.5-1.3 1.8-2.3-.8.5-1.7.8-2.6 1a4 4 0 0 0-6.9 3.7A11.5 11.5 0 0 1 3.6 4.6a4 4 0 0 0 1.2 5.4c-.6 0-1.3-.2-1.8-.5a4 4 0 0 0 3.2 4c-.6.2-1.2.2-1.8.1a4 4 0 0 0 3.7 2.8A8 8 0 0 1 2 18.1a11.4 11.4 0 0 0 17.6-10.2c.8-.6 1.6-1.3 2.1-2.1z"/>'],
    ['ad' => 'Instagram', 'link' => 'https://www.instagram.com/ornekfirmacomtr/',
     'ikon' => '<path d="M12 2.2c3.2 0 3.6 0 4.9.1 1.2.1 1.8.3 2.2.4.6.2 1 .5 1.4.9.4.4.7.8.9 1.4.2.4.4 1 .4 2.2.1 1.3.1 1.7.1 4.9s0 3.6-.1 4.9c0 1.2-.2 1.8-.4 2.2-.2.6-.5 1-.9 1.4-.4.4-.8.7-1.4.9-.4.2-1 .4-2.2.4-1.3.1-1.7.1-4.9.1s-3.6 0-4.9-.1c-1.2 0-1.8-.2-2.2-.4-.6-.2-1-.5-1.4-.9-.4-.4-.7-.8-.9-1.4-.2-.4-.4-1-.4-2.2C2.2 15.6 2.2 15.2 2.2 12s0-3.6.1-4.9c0-1.2.2-1.8.4-2.2.2-.6.5-1 .9-1.4.4-.4.8-.7 1.4-.9.4-.2 1-.4 2.2-.4 1.3-.1 1.7-.1 4.8-.1zm0 3.8a6 6 0 1 0 0 12 6 6 0 0 0 0-12zm0 9.9a3.9 3.9 0 1 1 0-7.8 3.9 3.9 0 0 1 0 7.8zm7.6-10.1a1.4 1.4 0 1 1-2.8 0 1.4 1.4 0 0 1 2.8 0z"/>'],
    ['ad' => 'LinkedIn',  'link' => 'https://www.linkedin.com/company/t%C3%BCrkbelge',
     'ikon' => '<path d="M6.9 21H3.4V9.5h3.5V21zM5.2 8a2 2 0 1 1 0-4.1 2 2 0 0 1 0 4.1zM21 21h-3.5v-5.6c0-1.3 0-3-1.9-3s-2.1 1.4-2.1 2.9V21H10V9.5h3.3v1.6h.1c.5-.9 1.6-1.8 3.3-1.8 3.5 0 4.2 2.3 4.2 5.3V21z"/>'],
];

$footerMenuler = [
    'HIZLI ERİŞİM' => [
        ['ad' => 'Anasayfa',         'link' => '/'],
        ['ad' => 'KVK Nedir?',       'link' => '/#sik-sorulan-sorular'],
        ['ad' => 'İzin Sistemi',     'link' => 'https://portal.ornek-kvkk.com/'],
        ['ad' => 'Envanter Sistemi', 'link' => 'https://portal.ornek-kvkk.com/'],
        ['ad' => 'Teklif Al',        'link' => '/#teklif-al'],
        ['ad' => 'API Dokümantasyonu', 'link' => '/api-dokuman'],
    ],
    'GİZLİLİK &amp; KULLANIM ŞARTLARI' => [
        ['ad' => 'KVKK',              'link' => '/kvkk'],
        ['ad' => 'Kalite Politikası', 'link' => '/kalite-politikasi'],
        ['ad' => 'Aydınlatma Metni',  'link' => '/aydinlatma-metni'],
    ],
];

$footerIletisim = [
    ['metin' => 'Şair Eşref Bulvarı No:31/A', 'link' => null],
    ['metin' => 'Konak-İzmir',                'link' => null],
    ['metin' => '+90 850 333 0 353',          'link' => 'tel:+908503330353'],
    ['metin' => 'destek@ornekfirma.com.tr',         'link' => 'mailto:destek@ornekfirma.com.tr'],
];
