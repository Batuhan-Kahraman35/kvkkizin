<?php
/**
 * Kalite Politikası
 * Kurumsal kalite yönetim sistemi bilgilendirme sayfası
 */

$sayfaBaslik   = 'Kalite Politikası | KVKK İzin Sistemi';
$sayfaAciklama = 'ÖRNEKFİRMA kalite yönetim sistemi esasları ve sahip olduğu ISO sertifikasyonları.';
$menuSabit     = true;

require __DIR__ . '/includes/site-header.php';

// Sahip olunan sertifikasyonlar
$sertifikalar = [
    'ISO 9001'      => 'Kalite Yönetim Sistemi',
    'ISO 27001'     => 'Bilgi Güvenliği Yönetim Sistemi',
    'ISO 20000-1'   => 'Bilgi Teknolojileri Hizmet Yönetim Sistemi',
    'ISO 22301'     => 'İş Sürekliliği Yönetim Sistemi',
];
?>

    <section class="sayfa-basligi">
        <h1>Kalite Politikası</h1>
        <p class="sayfa-yolu"><a href="/">Anasayfa</a> &nbsp;/&nbsp; Kalite Politikası</p>
    </section>

    <section class="metin-sayfa">
        <div class="metin-govde">

            <p>
                ÖRNEKFİRMA; üretkenlik, verimlilik ve müşterileri ile paydaşları arasında mükemmel ilişkiler
                geliştirmeye odaklanarak, e-dönüşüm kapsamındaki hizmetleri yüksek kalitede sunmayı
                hedeflemektedir.
            </p>

            <p>
                Kalite politikamız; üst yönetimin katkıları ve tüm çalışanların katılımıyla Kalite Yönetim
                Sistemimizin esaslarına uymak, sistemin sürekliliğini sağlamak ve sistemin performansını
                sürekli geliştirmektir.
            </p>

            <p>
                Tüm faaliyetlerimizi, süreçleri başarıyla tamamlanarak alınmış aşağıdaki sertifikasyonlar
                çerçevesinde yürütmekteyiz:
            </p>

            <div class="metin-kutu">
                <dl>
                    <?php foreach ($sertifikalar as $kod => $ad): ?>
                        <dt><?= $esc($kod) ?></dt>
                        <dd><?= $esc($ad) ?></dd>
                    <?php endforeach; ?>
                </dl>
            </div>

            <p>
                Böylece hem Bilgi Güvenliği Kalite Yönetim Sistemine uygun olarak güvence altına alınmış bir
                operasyon yöntemi, hem de bunun sürekliliği ve iyileştirilmesi için periyodik olarak gözden
                geçirmeler sağlanmaktadır.
            </p>

        </div>
    </section>

<?php require __DIR__ . '/includes/site-footer.php'; ?>
