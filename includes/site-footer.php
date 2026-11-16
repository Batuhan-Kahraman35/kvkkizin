    <footer class="alt-bilgi">
        <div class="alt-govde">
            <div>
                <p class="alt-marka">KVKK İZİN SİSTEMİ</p>
                <p class="alt-aciklama">
                    <?= $esc($siteBaslik) ?> İzin Sistemi, firmalar için izinli verilerinizi oluşturup,
                    saklayabileceğiniz özel bir yazılımdır.
                </p>
                <div class="sosyal">
                    <?php foreach ($sosyalMedya as $hesap): ?>
                        <a href="<?= $esc($hesap['link']) ?>" target="_blank" rel="noopener"
                           aria-label="<?= $esc($hesap['ad']) ?>" title="<?= $esc($hesap['ad']) ?>">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><?= $hesap['ikon'] ?></svg>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php foreach ($footerMenuler as $baslik => $linkler): ?>
                <div class="alt-sutun">
                    <p class="alt-baslik"><?= $baslik ?></p>
                    <ul>
                        <?php foreach ($linkler as $link): ?>
                            <li><a href="<?= $esc($link['link']) ?>"><?= $esc($link['ad']) ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endforeach; ?>

            <div class="alt-sutun">
                <p class="alt-baslik">BİZE ULAŞIN</p>
                <ul>
                    <?php foreach ($footerIletisim as $bilgi): ?>
                        <li>
                            <?php if ($bilgi['link']): ?>
                                <a href="<?= $esc($bilgi['link']) ?>"><?= $esc($bilgi['metin']) ?></a>
                            <?php else: ?>
                                <span><?= $esc($bilgi['metin']) ?></span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </footer>

    <a href="#" class="yukari-cik" id="yukariCik">YUKARI ÇIK</a>

    <script>
        (function () {
            const menu   = document.querySelector('.ust-menu');
            const yukari = document.getElementById('yukariCik');
            const hero   = document.querySelector('.hero');
            const hepBir = menu.dataset.sabit === '1';

            function scrollDurumu() {
                if (!hepBir) {
                    const esik = hero ? hero.offsetHeight - 120 : 80;
                    const gecti = window.scrollY > esik;
                    menu.classList.toggle('sabit', gecti);
                    menu.classList.toggle('animasyonlu', gecti);
                }
                yukari.classList.toggle('gorunur', window.scrollY > 400);
            }

            window.addEventListener('scroll', scrollDurumu, { passive: true });
            window.addEventListener('resize', scrollDurumu);
            scrollDurumu();

            yukari.addEventListener('click', function (e) {
                e.preventDefault();
                window.scrollTo({ top: 0, behavior: 'smooth' });
            });

            // Akordeon: aynı anda tek soru açık kalır
            document.querySelectorAll('.sss-soru').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    const oge  = btn.parentElement;
                    const acik = oge.classList.contains('acik');

                    document.querySelectorAll('.sss-oge').forEach(function (o) {
                        o.classList.remove('acik');
                        o.querySelector('.sss-soru').setAttribute('aria-expanded', 'false');
                    });

                    if (!acik) {
                        oge.classList.add('acik');
                        btn.setAttribute('aria-expanded', 'true');
                    }
                });
            });
        })();
    </script>

</body>
</html>
