<?php
/**
 * Aydınlatma Metni
 * KVKK 10. madde kapsamında bilgilendirme sayfası
 */

$sayfaBaslik   = 'Aydınlatma Metni | KVKK İzin Sistemi';
$sayfaAciklama = '6698 Sayılı Kişisel Verilerin Korunması Kanunu\'nun 10. maddesi uyarınca kişisel veri işleme faaliyetlerimiz hakkında aydınlatma metni.';
$menuSabit     = true;

require __DIR__ . '/includes/site-header.php';

// Kişisel veri kategorileri
$veriKategorileri = [
    'Çalışan Kişisel Verileri' => 'İletişim bilgileri, adres bilgileri, banka hesap bilgileri, maaş bilgisi, eğitim bilgileri, sertifika bilgileri, diploma notları, sabıka kayıtları, fotoğraf, ofis güvenliği için parmak izi, personel çağrı görüşme kayıtları, kamera görüntüleri, personel mail ve KEP adresleri, kimlik bilgileri, referans bilgisi',
    'Müşteri Kişisel Verileri' => 'İletişim bilgileri, kimlik bilgileri, KEP adresleri, adres bilgisi, mail adresleri, anne kızlık soyadı, uyruğu, vergi numarası, TC veya Pasaport No, imza, tarife bilgisi, Çağrı merkezi ses kayıtları. E-Bordro için; müşterilerin çalışan kimlik bilgileri, iletişim bilgileri, mail bilgileri',
];

// İlgili kişinin hakları
$haklar = [
    'Kişisel verinizin işlenip işlenmediğini öğrenme',
    'Kişisel veriniz işlenmişse buna ilişkin bilgi talep etme',
    'Kişisel verinizin işlenme amacını ve bunların amacına uygun kullanılıp kullanılmadığını öğrenme',
    'Yurt içinde veya yurt dışında kişisel verinizin aktarıldığı üçüncü kişileri bilme',
    'Kişisel verinizin eksik veya yanlış işlenmiş olması hâlinde bunların düzeltilmesini isteme',
    'Kişisel verinizin silinmesini veya yok edilmesini isteme',
    'Kişisel verinizin düzeltilmesi, silinmesi ya da yok edilmesi halinde bu işlemlerin, kişisel verinizin aktarıldığı üçüncü kişilere bildirilmesini isteme',
    'İşlenen verinizin münhasıran otomatik sistemler vasıtasıyla analiz edilmesi suretiyle aleyhinize bir sonucun ortaya çıkmasına itiraz etme',
    'Kişisel verinizin kanuna aykırı olarak işlenmesi sebebiyle zarara uğramanız hâlinde zararın giderilmesini talep etme',
];

// Başvuru kanalları
$basvuruKanallari = [
    'Mail'  => ['metin' => 'destek@ornekfirma.com.tr',  'link' => 'mailto:destek@ornekfirma.com.tr'],
    'KEP'   => ['metin' => 'ornek@hs01.kep.tr', 'link' => null],
    'Adres' => ['metin' => 'Şair Eşref Bulvarı No:31-A İsmet Kaptan Mahallesi Konak – İzmir', 'link' => null],
];
?>

    <section class="sayfa-basligi">
        <h1>Aydınlatma Metni</h1>
        <p class="sayfa-yolu"><a href="/">Anasayfa</a> &nbsp;/&nbsp; Aydınlatma Metni</p>
    </section>

    <section class="metin-sayfa">
        <div class="metin-govde">

            <p>
                ÖRNEK MÜŞTERİ A.Ş. olarak, ORNEKFIRMA markasıyla 6698 Sayılı Kişisel Verilerin Korunması
                Kanunu'nun 10. maddesi uyarınca sizi, kişisel veri işleme faaliyetlerimiz hakkında
                bilgilendirmek ve aydınlatmak isteriz.
            </p>

            <h2>1. Veri Sorumlusu</h2>
            <p>
                6698 sayılı Kişisel Verilerin Korunması Kanunu uyarınca, kişisel verileriniz; veri sorumlusu
                olarak ÖRNEK MÜŞTERİ A.Ş. tarafından aşağıda açıklanan amaçlar kapsamında; hukuka ve dürüstlük
                kurallarına uygun bir şekilde işleyebilecek, kaydedebilecek, saklayabilecek, sınıflandırabilecek,
                güncelleyebilecek ve mevzuatın izin verdiği hallerde ve/veya işlendikleri amaçla sınırlı olarak
                3. kişilere açıklayabilecek/aktarabilecektir.
            </p>

            <h2>2. Kişisel Verilerin İşlenme Amacı</h2>
            <p>
                Müşterilerimize ait kişisel veriler; E-İmza, E-Fatura, E-Arşiv Fatura, E-Muhasebe, EBYS,
                E-Defter, E-İrsaliye, E-Serbest Meslek Makbuzu, E-Müstahsil, E-Bilet, KEP Kurumsal, KEP Bireysel,
                E-Mutabakat, E-Beyanname, E-Bordro, E-Yedekleme satış ve destek hizmetlerinin gerçekleştirilmesi
                amacıyla alınıp, KVKK'ya ve ilgili diğer kanunlarda belirtilen sürelere uygun olarak fiziksel
                veya elektronik ortamda güvenli bir şekilde saklanmaktadır.
            </p>
            <p>
                Önceden çalışmış, çalışmaya devam eden ve stajyer olarak görev alan personellere ait kişisel
                veriler; iş başvuru süreçleri ve çalışma süreçleri, SGK bildirimleri, İŞ-KUR bilgilendirmeleri,
                KOSGEB müracaatları, ÖRNEK MÜŞTERİ A.Ş. fiziksel güvenlik önlemlerinin yerine getirilmesi için
                alınmaktadır. Alınan kişisel veriler ÖRNEK MÜŞTERİ A.Ş. tarafından KVKK başta olmak üzere ilgili
                tüm mevzuatta öngörülen yükümlülüklere uygun olarak korunmaktadır.
            </p>
            <p>
                Kişisel verinin sahibi ilgili kişi, kişisel verilerinin işlenme amaçları konusunda detaylı
                bilgilere; <a href="http://www.ornekfirma.com.tr" target="_blank" rel="noopener">www.ornekfirma.com.tr</a>
                internet adresinden Kurumsal başlığı altındaki Kurumsal Politikalarımız menüsünde beyan edilen
                Kişisel Verilerin İşlenmesi ve Korunması Politikası'ndan ulaşabilir.
            </p>

            <h2>3. Kişisel Verilerin Silinmesi</h2>
            <p>
                Kişisel verinin sahibi ilgili kişi, kişisel verilerinin silinmesi konusunda detaylı bilgilere;
                <a href="http://www.ornekfirma.com.tr" target="_blank" rel="noopener">www.ornekfirma.com.tr</a>
                internet adresinden Kurumsal başlığı altındaki Kurumsal Politikalarımız menüsünde beyan edilen
                <a href="http://ornekfirma.com.tr/kvkk" target="_blank" rel="noopener">ornekfirma.com.tr/kvkk</a>
                adresinden ulaşabilir.
            </p>

            <h2>4. Kişisel Verilerinizin Paylaşılması</h2>
            <p>
                Müşterilerimize ve çalışanlarımıza ait kişisel veriler kamu faydası gözetilmesi kaydı ile
                KVKK'da öngörülen temel ilkelere uygun olarak ve KVKK'nın 8. ve 9. maddelerinde belirtilen
                kişisel veri işleme şartları ve amaçları dâhilinde; ÖRNEK MÜŞTERİ A.Ş., faaliyetlerini
                yürütebilmek için iş birliği yaptığı çözüm ortaklarına, bayilerine, kurum ve kuruluşlarla ve
                anlaşmalı olduğu bankalarla paylaşılabilmektedir.
            </p>
            <p>
                Bunun yanında, kişisel veriler mevzuat gereği, hukuki yükümlülük sebebiyle kamu kurumları ve
                diğer kuruluşlarla paylaşılabilecektir. ÖRNEK MÜŞTERİ A.Ş. bünyesindeki tüm kişisel verilerin
                yurt dışına aktarımı yapılmamaktadır.
            </p>

            <h2>5. Alınan Kişisel Veriler</h2>
            <p>
                ÖRNEK MÜŞTERİ A.Ş. olarak çalışan ve müşteri kişisel verileri barındırılmaktadır. Alınan
                kişisel verilerin kategorileri aşağıda belirtilmiştir:
            </p>
            <?php foreach ($veriKategorileri as $kategori => $detay): ?>
                <h3><?= $esc($kategori) ?></h3>
                <p><?= $esc($detay) ?></p>
            <?php endforeach; ?>

            <h2>6. Kişisel Verilerinizin Toplanmasında Yöntemler ve Hukuki Sebepler</h2>
            <p>
                Müşterilerimize ait kişisel veriler; kurum faaliyetlerini yürütmek amacıyla ÖRNEK MÜŞTERİ A.Ş.
                tarafından farklı kanallar vasıtasıyla ve hukuki sebeplere dayanarak; danışman veya bayilerimiz
                aracılığı ile başvuru formları ya da mail yoluyla alınmaktadır. Bunun haricinde online başvuru,
                telefon destek faaliyetleri ve elektronik haberleşme kanalları ile toplanabilmektedir.
            </p>
            <p>
                Bu çerçevede kişisel verileriniz, KVKK'nın 4(2) maddesinde öngörülen ilkeler ışığında açık rıza
                temini suretiyle veya 5(2) ve 6(3) maddelerinde öngörülen durumların varlığı halinde, açık rıza
                temin edilmeksizin işlenebilmekte ve aktarılabilmektedir.
            </p>
            <p>
                Müşterilerimizden, ÖRNEK MÜŞTERİ A.Ş. faaliyetlerinin yürütülmesi için kişisel verilerinizi
                almamızı gerektiren hukuki dayanaklar; 5070 Sayılı Elektronik İmza Kanunu, 213 Sayılı Vergi Usul
                Kanunu ve buna bağlı tebliğler, 6102 Sayılı Türk Ticaret Kanunu, 6321 Sayılı EBYS yazışmalar
                yönetmeliği, 4857 Sayılı İş Kanunu ve bunlara bağlı diğer yönetmeliklerdir.
            </p>
            <p>
                Önceden çalışmış, çalışmaya devam eden ve stajyer olarak görev alan personellere ait kişisel
                veriler; online iş başvuru portalleri, iş başvuru formları ve İŞ-KUR aracılığı ile elde
                edilmektedir.
            </p>

            <h2>7. Kişisel Verisi İşlenen İlgili Kişinin Hakları</h2>
            <p>
                Kişisel verilerinizin işlenmesine ilişkin aşağıdaki hakları, ÖRNEK MÜŞTERİ A.Ş.'ye yapacağınız
                bir talep ile kullanabilirsiniz. Bu kapsamda iletilen talepler ÖRNEK MÜŞTERİ A.Ş. tarafından en
                geç otuz gün içinde ücretsiz olarak sonuçlandırılacaktır. Ancak, Kişisel Verileri Koruma
                Kurulunca bir ücret öngörülmesi halinde, ÖRNEK MÜŞTERİ A.Ş. tarafından belirlenen tarifedeki
                ücret alınabilmektedir. Bu kapsamda kişisel veri sahibi olarak;
            </p>
            <ul>
                <?php foreach ($haklar as $hak): ?>
                    <li><?= $esc($hak) ?></li>
                <?php endforeach; ?>
            </ul>
            <p>haklarınız bulunmaktadır.</p>

            <h2>8. İletişim Yöntemleri</h2>
            <p>
                6698 Sayılı Kanun kapsamında kişisel verilerinize ilişkin haklarınızı aşağıda belirtilen
                yöntemleri kullanarak gerçekleştirebilirsiniz.
            </p>

            <h3>ÖRNEK MÜŞTERİ A.Ş. İrtibat Bilgileri</h3>
            <div class="metin-kutu">
                <dl>
                    <dt>Veri Sorumlusu</dt>
                    <dd>ÖRNEK EĞİTİM DANIŞMANLIK VE MESLEKİ YETERLİLİK BELGELENDİRME A.Ş.</dd>
                </dl>
            </div>

            <h3>Aydınlatma Başvuru Yöntemleri</h3>
            <div class="metin-kutu">
                <dl>
                    <?php foreach ($basvuruKanallari as $kanal => $bilgi): ?>
                        <dt><?= $esc($kanal) ?></dt>
                        <dd>
                            <?php if ($bilgi['link']): ?>
                                <a href="<?= $esc($bilgi['link']) ?>"><?= $esc($bilgi['metin']) ?></a>
                            <?php else: ?>
                                <?= $esc($bilgi['metin']) ?>
                            <?php endif; ?>
                        </dd>
                    <?php endforeach; ?>
                </dl>
            </div>

            <p>
                Adreslere gönderilerek yapılacak postalarda otuz günlük yasal hakkınızın sorunsuz takibi için
                iadeli taahhütlü işlem yapılması gerekmektedir. Bu kapsamdaki başvurular tarafımızdan yapılacak
                olan kimlik doğrulamasını takiben kabul edilecek olup, ilgili kişilere yasal süreler içerisinde
                yazılı olarak veya elektronik ortamda cevap verilecektir.
            </p>

        </div>
    </section>

<?php require __DIR__ . '/includes/site-footer.php'; ?>
