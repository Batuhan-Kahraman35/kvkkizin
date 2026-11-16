<?php
/**
 * Ana Sayfa
 * DB'deki site_ayarlari_anasayfa_yonlendirme = 1 ise /admin'e yönlendirir
 */

require_once __DIR__ . '/admin/db.php';

try {
    $yonlendir = Database::getInstance()->fetchOne(
        "SELECT TOP 1 site_ayarlari_anasayfa_yonlendirme
         FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC"
    );
    if ($yonlendir && (int)$yonlendir['site_ayarlari_anasayfa_yonlendirme'] === 1) {
        header('Location: /admin/anasayfa');
        exit;
    }
} catch (Exception $e) {
    // yönlendirme yapılmadan ana sayfa gösterilir
}

// İzin alma yöntemleri — ileride tanim_izin_yontemleri tablosuna taşınabilir
$izinYontemleri = [
    [
        'baslik'   => 'SMS Aracılığıyla',
        'aciklama' => 'İzin formunu dolduran müşteri şahsi telefonuna gelen SMS kodu, Aydınlatma ve Açık Rıza Metinlerini göz önüne alarak bilgilerini onaylar.',
        'renk'     => 'sari',
        'ikon'     => '<rect x="3" y="4" width="18" height="13" rx="2"/><path d="M8 21h8M12 17v4"/><path d="m8 11 2.5 2.5L16 8"/>',
    ],
    [
        'baslik'   => 'QR Kodu İle',
        'aciklama' => 'Yazılım aracılığıyla otomatik olarak oluşturulan QR kodu kişiye ait cihaz aracılığıyla okutulur ve izin işlemlerine devam edilir.',
        'renk'     => 'kirmizi',
        'ikon'     => '<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 8h3v3H8zM8 15h8M8 18h5"/><path d="m14 8 3 3-3 3"/>',
    ],
    [
        'baslik'   => 'Mağaza Temsilcisi Vasıtası İle',
        'aciklama' => 'Mağazada görevlisi, web üzerinde firmaya özel oluşturulan form sayesinde kişi bilgilerini doldurur. Kişinin şahsi telefonuna giden SMS aracılığıyla bilgiler doğrulanır.',
        'renk'     => 'sari',
        'ikon'     => '<path d="M4 7h12l-2.5-2.5M20 13H8l2.5 2.5"/><circle cx="7" cy="18" r="2"/><circle cx="17" cy="6" r="2"/>',
    ],
];

// Sık sorulan sorular — ileride tanim_sss tablosuna taşınabilir
$sorular = [
    [
        'soru'  => 'Kişisel Verilerin Korunması Ne Demektir?',
        'cevap' => 'Kişisel verilerin korunması, kişisel verilerin işlenmesinin disiplin altına alınması ile temel hak ve özgürlüklerin korunmasıdır.',
    ],
    [
        'soru'  => 'Kişisel Verilerin Korunması Kanununun Amacı Nedir?',
        'cevap' => 'Uluslararası belgeler, mukayeseli hukuk uygulamaları ve ülkemiz ihtiyaçları göz önüne alınmak suretiyle hazırlanan Kanun ile kişisel verilerin çağdaş standartlarda işlenmesi ve koruma altına alınması amaçlanmaktadır. Bu kapsamda Kanunun amacı, kişisel verilerin işlenme şartlarını, kişisel verilerin işlenmesinde kişilerin temel hak ve özgürlüklerinin korunmasını ve kişisel verileri işleyen gerçek ve tüzel kişilerin yükümlülükleri ile uyacakları usul ve esasları düzenlemektir.',
    ],
    [
        'soru'  => 'Kişisel Veri Nedir?',
        'cevap' => 'Kimliği belirli ya da belirlenebilir nitelikteki gerçek kişiye ilişkin her türlü bilgidir. Kişisel veriden söz edebilmek için, verinin gerçek kişiye ilişkin olması ve bu kişinin de belirli ya da belirlenebilir nitelikte olması gerekmektedir.',
    ],
    [
        'soru'  => 'Açık Rıza Nedir?',
        'cevap' => 'Açık rıza, belirli bir konuya ilişkin bilgilendirilmeye dayanan ve özgür irade ile açıklanan rızadır. Başka bir ifade ile ilgili kişinin verilerinin işlenmesine özgürce, konu hakkında yeterli bilgi sahibi olarak ve sadece o işlemle sınırlı kalmak kaydıyla verdiği onay beyanıdır.',
    ],
];

// SSS bölümü iletişim kutusu
$iletisim = [
    ['tip' => 'saat',    'ust' => "09:00'dan 19:00'a kadar", 'alt' => 'Pazartesi - Cumartesi', 'link' => null],
    ['tip' => 'eposta',  'ust' => 'destek@ornekfirma.com.tr',      'alt' => 'Pazartesi - Cumartesi', 'link' => 'mailto:destek@ornekfirma.com.tr'],
    ['tip' => 'telefon', 'ust' => '+90 850 333 0 353',       'alt' => 'Pazartesi - Cumartesi', 'link' => 'tel:+908503330353'],
];

$sssIkonlar = [
    'saat'    => '<rect x="3" y="4" width="18" height="17" rx="2"/><path d="M8 2v4M16 2v4M3 10h18"/><path d="M12 14v3l2 1"/>',
    'eposta'  => '<path d="M4 18v-6a8 8 0 0 1 16 0v6"/><path d="M4 15h3v5H5a1 1 0 0 1-1-1zM20 15h-3v5h2a1 1 0 0 0 1-1z"/>',
    'telefon' => '<path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 1.9.7 2.8a2 2 0 0 1-.5 2.1L8.1 9.9a16 16 0 0 0 6 6l1.3-1.2a2 2 0 0 1 2.1-.5c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.7 2z"/>',
];

$sssGorsel = '/assets/images/about-sec1.png';

$sayfaBaslik = 'KVKK İzin Sistemi';
require __DIR__ . '/includes/site-header.php';
?>

    <section class="hero">
        <div class="hero-gorsel"></div>
        <div class="hero-icerik">
            <h1 class="hero-baslik">İzin Sistemi</h1>
            <p class="hero-aciklama">
                <?= $esc($siteBaslik) ?> İzin Sistemi, firmaların izinli verilerini oluşturup saklayabileceği özel bir yazılımdır.
            </p>
        </div>
    </section>

    <section class="yontemler" id="nasil-izin-alinir">
        <div class="bolum-basligi">
            <p class="bolum-ust-yazi">İzin Sistemi Sayesinde İzin Alabileceğiniz Yöntemler</p>
            <h2 class="bolum-baslik">Nasıl İzin Alınır?</h2>
            <p class="bolum-aciklama">
                ETK ve KVKK yasalarına uygun, elektronik ortamda kişisel verileri
                işleyebilmeniz için kullanabileceğiniz iletişim izni yöntemleri.
            </p>
        </div>

        <div class="kartlar">
            <?php foreach ($izinYontemleri as $yontem): ?>
                <article class="kart">
                    <div class="kart-ikon <?= $esc($yontem['renk']) ?>">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><?= $yontem['ikon'] ?></svg>
                    </div>
                    <h3 class="kart-baslik"><?= $esc($yontem['baslik']) ?></h3>
                    <p class="kart-aciklama"><?= $esc($yontem['aciklama']) ?></p>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="sss" id="sik-sorulan-sorular">
        <div class="bolum-basligi">
            <p class="bolum-ust-yazi">Önemli Sorular</p>
            <h2 class="bolum-baslik">Sık Sorulan Sorular</h2>
            <p class="bolum-aciklama">KVKK hakkında sıkça sorulan soruları sizler için derledik.</p>
        </div>

        <div class="sss-govde">
            <div class="sss-gorsel-alan">
                <img src="<?= $esc($sssGorsel) ?>" alt="Sık sorulan sorular" class="sss-gorsel" loading="lazy">

                <div class="iletisim-kutu">
                    <?php foreach ($iletisim as $bilgi): ?>
                        <?php $etiket = $bilgi['link'] ? 'a' : 'div'; ?>
                        <<?= $etiket ?> class="iletisim-satir"<?= $bilgi['link'] ? ' href="' . $esc($bilgi['link']) . '"' : '' ?>>
                            <svg viewBox="0 0 24 24" aria-hidden="true"><?= $sssIkonlar[$bilgi['tip']] ?></svg>
                            <span>
                                <span class="iletisim-ust"><?= $esc($bilgi['ust']) ?></span><br>
                                <span class="iletisim-alt"><?= $esc($bilgi['alt']) ?></span>
                            </span>
                        </<?= $etiket ?>>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="sss-liste">
                <?php foreach ($sorular as $i => $sss): ?>
                    <div class="sss-oge<?= $i === 0 ? ' acik' : '' ?>">
                        <button type="button" class="sss-soru" aria-expanded="<?= $i === 0 ? 'true' : 'false' ?>">
                            <?= $esc($sss['soru']) ?>
                        </button>
                        <div class="sss-cevap">
                            <p><?= $esc($sss['cevap']) ?></p>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

<?php require __DIR__ . '/includes/site-footer.php'; ?>
