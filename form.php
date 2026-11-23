<?php
/**
 * Genel form sayfası — giriş gerektirmez
 *
 *   /form/<uniqid>                  → form (web.config: form.php?u=<uniqid>)
 *   /form/<uniqid>/<metin>          → aydinlatma | acik-riza | etk | gps metni (SMS'teki linkler)
 *
 * POST (AJAX, aynı adrese): ilceler · gonder · dogrula · tekrar_gonder
 */

require_once __DIR__ . '/includes/FormBasvuru.php';

session_name('KVKKFORM');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/form',
    'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

$servis = new FormBasvuru();
$uniqid = (string)($_GET['u'] ?? '');
$form = $servis->formGetir($uniqid);
$otp = $form ? FormBasvuru::otpAyarlari($form['firma_id']) : null;
$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

/* ======================================================================
 * AJAX
 * ==================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $cevap = function (array $d) {
        echo json_encode($d, JSON_UNESCAPED_UNICODE);
        exit;
    };

    if (!$form) {
        $cevap(['success' => false, 'message' => 'Form bulunamadı veya yayından kaldırılmış.']);
    }
    if (!hash_equals($_SESSION['form_token'] ?? '', (string)($_POST['token'] ?? ''))) {
        $cevap(['success' => false, 'yenile' => true, 'message' => 'Oturum süresi doldu. Sayfa yenileniyor...']);
    }

    $anahtar = 'bekleyen_' . $form['form_uniqid'];
    $metinDegistiMesaj = 'Onay metinlerinden biri siz formu doldururken güncellendi. Sayfa yenileniyor; lütfen metinleri tekrar okuyup formu yeniden gönderiniz.';
    $tesekkur = function () use ($form) {
        $metin = trim((string)$form['form_tesekkur_metni']);
        return $metin !== '' ? FormBasvuru::metinHtml($metin) : 'Bilgileriniz başarıyla kaydedildi. Teşekkür ederiz.';
    };

    try {
        switch ($_POST['action'] ?? '') {
            case 'ilceler':
                $cevap(['success' => true, 'data' => $servis->ilceler((int)($_POST['il_id'] ?? 0))]);

            case 'gonder':
                if (trim((string)($_POST['web_sitesi'] ?? '')) !== '') {   // bot tuzağı
                    $cevap(['success' => true, 'adim' => 'tamam', 'html' => $tesekkur()]);
                }
                $alanlar = $servis->alanlar($form['form_id']);
                $sonuc = $servis->dogrula($form, $alanlar, $_POST);
                if ($sonuc['metin_degisti']) {
                    $cevap(['success' => false, 'yenile' => true, 'message' => $metinDegistiMesaj]);
                }
                if ($sonuc['hatalar']) {
                    $cevap(['success' => false, 'hatalar' => $sonuc['hatalar'], 'message' => 'Lütfen işaretli alanları kontrol ediniz.']);
                }
                $veri = $sonuc['veri'];

                if ((int)$form['form_sms_onay'] !== 1) {
                    $servis->kaydet($form, $veri);
                    unset($_SESSION[$anahtar]);
                    $cevap(['success' => true, 'adim' => 'tamam', 'html' => $tesekkur()]);
                }

                $gonderim = $servis->kodGonder($form, $veri['baslik']['telefon']);
                if (!$gonderim['success']) {
                    $cevap(['success' => false, 'message' => $gonderim['message']]);
                }
                $_SESSION[$anahtar] = ['veri' => $veri, 'smslog_id' => $gonderim['smslog_id'], 'zaman' => time()];
                $cevap(['success' => true, 'adim' => 'kod', 'telefon' => FormBasvuru::telefonMaskele($veri['baslik']['telefon']),
                        'sure' => $otp['gecerlilik_sn'], 'tekrar' => $otp['tekrar_sn']]);

            case 'dogrula':
                $bek = $_SESSION[$anahtar] ?? null;
                if (!$bek) {
                    $cevap(['success' => false, 'bitti' => true, 'message' => 'Doğrulama oturumu bulunamadı. Lütfen formu tekrar gönderiniz.']);
                }
                $kod = preg_replace('/\D/', '', (string)($_POST['kod'] ?? ''));
                $d = $servis->kodDogrula($bek['smslog_id'], $bek['veri']['baslik']['telefon'], $kod);
                if (!$d['success']) {
                    $cevap(['success' => false, 'bitti' => $d['bitti'], 'message' => $d['message']]);
                }
                $servis->kaydet($form, $bek['veri'], $bek['smslog_id']);
                unset($_SESSION[$anahtar]);
                $cevap(['success' => true, 'adim' => 'tamam', 'html' => $tesekkur()]);

            case 'tekrar_gonder':
                $bek = $_SESSION[$anahtar] ?? null;
                if (!$bek) {
                    $cevap(['success' => false, 'bitti' => true, 'message' => 'Doğrulama oturumu bulunamadı. Lütfen formu tekrar gönderiniz.']);
                }
                $bekle = $otp['tekrar_sn'] - (time() - $bek['zaman']);
                if ($bekle > 0) {
                    $cevap(['success' => false, 'message' => "Yeni kod için $bekle saniye bekleyiniz."]);
                }
                $gonderim = $servis->kodGonder($form, $bek['veri']['baslik']['telefon']);
                if (!$gonderim['success']) {
                    $cevap(['success' => false, 'message' => $gonderim['message']]);
                }
                $_SESSION[$anahtar]['smslog_id'] = $gonderim['smslog_id'];
                $_SESSION[$anahtar]['zaman'] = time();
                $cevap(['success' => true, 'message' => 'Yeni doğrulama kodu gönderildi.',
                        'sure' => $otp['gecerlilik_sn'], 'tekrar' => $otp['tekrar_sn']]);

            default:
                $cevap(['success' => false, 'message' => 'Geçersiz işlem.']);
        }
    } catch (MetinDegistiException $ex) {
        unset($_SESSION[$anahtar]);
        $cevap(['success' => false, 'yenile' => true, 'bitti' => true, 'message' => $metinDegistiMesaj]);
    } catch (Throwable $ex) {
        error_log('Form ' . $form['form_uniqid'] . ': ' . $ex->getMessage());
        $cevap(['success' => false, 'message' => 'İşlem sırasında bir hata oluştu. Lütfen tekrar deneyiniz.']);
    }
}

/* ======================================================================
 * Sayfa
 * ==================================================================== */
if (empty($_SESSION['form_token'])) {
    $_SESSION['form_token'] = bin2hex(random_bytes(32));
}

$metinler = $form ? FormBasvuru::onayMetinleri($form) : [];
$metinSayfa = (string)($_GET['metin'] ?? '');
$firmaAdi = $form ? trim($form['firma_ticari_unvan'] ?: $form['firma_adi']) : '';

if (!$form || ($metinSayfa !== '' && !isset($metinler[$metinSayfa]))) {
    http_response_code(404);
}
$alanlar = ($form && $metinSayfa === '') ? $servis->alanlar($form['form_id']) : [];
$ilVar = (bool)array_filter($alanlar, fn($a) => $a['form_alan_kod'] === 'il');
$iller = $ilVar ? $servis->iller() : [];

$baslik = !$form ? 'Form bulunamadı'
    : ($metinSayfa !== '' ? ($metinler[$metinSayfa]['baslik'] ?? 'Metin bulunamadı') : $form['form_adi']);
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $e($baslik) ?><?= $firmaAdi ? ' - ' . $e($firmaAdi) : '' ?></title>
    <link rel="icon" href="/favicon.ico">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css">
    <style>
        body { background: #f1f4f9; }
        .kart { max-width: 640px; margin: 24px auto; }
        .kart .card { border: 0; border-radius: 14px; box-shadow: 0 6px 24px rgba(20, 40, 80, .08); }
        .firma { font-size: .9rem; color: #6c757d; }
        .onay-kutu { border: 1px solid #dee2e6; border-radius: 10px; padding: 12px 14px; margin-bottom: 12px; background: #fafbfd; }
        .onay-kutu.is-invalid { border-color: #dc3545; }
        .metin-govde { max-height: 60vh; overflow-y: auto; font-size: .92rem; line-height: 1.6; }
        .kod-giris { letter-spacing: .5em; font-size: 1.6rem; text-align: center; font-weight: 600; }
        .tuzak { position: absolute; left: -9999px; }
        .zorunlu { color: #dc3545; }
    </style>
</head>
<body>
<div class="kart px-3">
    <div class="card">
        <div class="card-body p-4">
<?php if (!$form): ?>
            <div class="text-center py-4">
                <i class="bi bi-exclamation-circle text-warning" style="font-size:3rem"></i>
                <h4 class="mt-3">Form bulunamadı</h4>
                <p class="text-muted mb-0">Bu bağlantı geçersiz veya form yayından kaldırılmış.</p>
            </div>

<?php elseif ($metinSayfa !== ''): ?>
            <div class="firma mb-1"><?= $e($firmaAdi) ?></div>
            <h4 class="mb-3"><?= $e($baslik) ?></h4>
            <?php if (isset($metinler[$metinSayfa])): ?>
                <div class="metin-govde" style="max-height:none"><?= FormBasvuru::metinHtml($metinler[$metinSayfa]['metin']) ?></div>
            <?php else: ?>
                <p class="text-muted">Bu formda istenen metin bulunmuyor.</p>
            <?php endif; ?>

<?php else: ?>
            <div class="firma mb-1"><?= $e($firmaAdi) ?></div>
            <h4 class="mb-4"><?= $e($form['form_adi']) ?></h4>

            <!-- 1. adım: form -->
            <form id="basvuruForm" novalidate autocomplete="on">
                <input type="hidden" name="token" value="<?= $e($_SESSION['form_token']) ?>">
                <input type="text" name="web_sitesi" class="tuzak" tabindex="-1" autocomplete="off" aria-hidden="true">

                <?php foreach ($alanlar as $a):
                    $kod = $a['form_alan_kod'];
                    $ad = 'alan[' . $kod . ']';
                    $zor = $a['zorunlu'] ? ' <span class="zorunlu">*</span>' : '';
                    $req = $a['zorunlu'] ? ' required' : '';
                    ?>
                    <div class="mb-3" data-alan="<?= $e($kod) ?>">
                        <label class="form-label" for="f_<?= $e($kod) ?>"><?= $e($a['etiket']) ?><?= $zor ?></label>
                        <?php if ($kod === 'il'): ?>
                            <select class="form-select secimli" id="f_il" name="<?= $e($ad) ?>"<?= $req ?>>
                                <option value="">İl seçiniz</option>
                                <?php foreach ($iller as $il): ?>
                                    <option value="<?= (int)$il['il_id'] ?>"><?= $e($il['il_adi']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php elseif ($kod === 'ilce'): ?>
                            <select class="form-select secimli" id="f_ilce" name="<?= $e($ad) ?>"<?= $req ?>>
                                <option value="">Önce il seçiniz</option>
                            </select>
                        <?php elseif ($a['form_alan_tipi'] === 'select'): ?>
                            <select class="form-select secimli ust-secim" id="f_<?= $e($kod) ?>" name="<?= $e($ad) ?>" data-kod="<?= $e($kod) ?>"<?= $req ?>>
                                <option value="">Seçiniz</option>
                                <?php foreach ($a['secenekler'] as $s): ?>
                                    <option value="<?= (int)$s['form_alan_secenek_id'] ?>"><?= $e($s['form_alan_secenek_adi']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php foreach ($a['alt_secenekler'] as $ustId => $altlar):
                                $ust = array_values(array_filter($a['secenekler'], fn($s) => (int)$s['form_alan_secenek_id'] === (int)$ustId))[0] ?? null; ?>
                                <div class="mt-2 d-none alt-kutu" data-kod="<?= $e($kod) ?>" data-ust="<?= (int)$ustId ?>" data-alan="<?= $e($kod) ?>_alt">
                                    <label class="form-label"><?= $e($ust['form_alan_secenek_alt_baslik'] ?? 'Alt seçim') ?> <span class="zorunlu">*</span></label>
                                    <select class="form-select secimli" name="alan[<?= $e($kod) ?>_alt]" disabled>
                                        <option value="">Seçiniz</option>
                                        <?php foreach ($altlar as $s): ?>
                                            <option value="<?= (int)$s['form_alan_secenek_id'] ?>"><?= $e($s['form_alan_secenek_adi']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            <?php endforeach; ?>
                        <?php elseif ($a['form_alan_tipi'] === 'textarea'): ?>
                            <textarea class="form-control" id="f_<?= $e($kod) ?>" name="<?= $e($ad) ?>" rows="3" maxlength="1000"<?= $req ?>></textarea>
                        <?php elseif ($a['form_alan_tipi'] === 'date'): ?>
                            <input type="date" class="form-control" id="f_<?= $e($kod) ?>" name="<?= $e($ad) ?>" max="<?= date('Y-m-d') ?>"<?= $req ?>>
                        <?php elseif ($kod === 'cep_telefonu' || $a['form_alan_tipi'] === 'tel'): ?>
                            <input type="tel" class="form-control" id="f_<?= $e($kod) ?>" name="<?= $e($ad) ?>" inputmode="tel" autocomplete="tel" placeholder="05xx xxx xx xx" maxlength="20"<?= $req ?>>
                        <?php elseif ($kod === 'eposta' || $a['form_alan_tipi'] === 'email'): ?>
                            <input type="email" class="form-control" id="f_<?= $e($kod) ?>" name="<?= $e($ad) ?>" autocomplete="email" maxlength="255"<?= $req ?>>
                        <?php elseif ($kod === 'tc_kimlik' || $kod === 'vergi_no'): ?>
                            <input type="text" class="form-control" id="f_<?= $e($kod) ?>" name="<?= $e($ad) ?>" inputmode="numeric" maxlength="11"<?= $req ?>>
                        <?php else: ?>
                            <input type="text" class="form-control" id="f_<?= $e($kod) ?>" name="<?= $e($ad) ?>" maxlength="250"
                                   <?= $kod === 'ad' ? 'autocomplete="given-name"' : ($kod === 'soyad' ? 'autocomplete="family-name"' : '') ?><?= $req ?>>
                        <?php endif; ?>
                        <div class="invalid-feedback"></div>
                    </div>
                <?php endforeach; ?>

                <?php foreach ($metinler as $anahtar => $m): ?>
                    <div class="onay-kutu" data-alan="onay_<?= $e($anahtar) ?>">
                        <input type="hidden" name="metin_hash[<?= $e($anahtar) ?>]" value="<?= $e($m['hash']) ?>">
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <a href="#" class="fw-semibold text-decoration-none" data-bs-toggle="modal" data-bs-target="#metin_<?= $e($anahtar) ?>">
                                <i class="bi bi-file-earmark-text"></i> <?= $e($m['baslik']) ?>
                            </a>
                            <div>
                                <div class="form-check form-check-inline mb-0">
                                    <input class="form-check-input" type="radio" name="onay[<?= $e($anahtar) ?>]" id="o_<?= $e($anahtar) ?>_1" value="1">
                                    <label class="form-check-label" for="o_<?= $e($anahtar) ?>_1">Kabul ediyorum</label>
                                </div>
                                <div class="form-check form-check-inline mb-0 me-0">
                                    <input class="form-check-input" type="radio" name="onay[<?= $e($anahtar) ?>]" id="o_<?= $e($anahtar) ?>_0" value="0">
                                    <label class="form-check-label" for="o_<?= $e($anahtar) ?>_0">Kabul etmiyorum</label>
                                </div>
                            </div>
                        </div>
                        <div class="invalid-feedback d-block small"></div>
                    </div>

                    <div class="modal fade" id="metin_<?= $e($anahtar) ?>" tabindex="-1">
                        <div class="modal-dialog modal-lg modal-dialog-scrollable">
                            <div class="modal-content">
                                <div class="modal-header"><h5 class="modal-title"><?= $e($m['baslik']) ?></h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                                <div class="modal-body metin-govde"><?= FormBasvuru::metinHtml($m['metin']) ?></div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Kapat</button>
                                    <button type="button" class="btn btn-success metin-onayla" data-anahtar="<?= $e($anahtar) ?>" data-bs-dismiss="modal">Kabul ediyorum</button>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>

                <div class="alert alert-danger d-none" id="genelHata"></div>
                <button type="submit" class="btn btn-primary w-100 py-2" id="btnGonder">
                    <span class="spinner-border spinner-border-sm d-none me-1"></span>
                    <?= (int)$form['form_sms_onay'] === 1 ? 'Devam Et' : 'Gönder' ?>
                </button>
                <?php if ((int)$form['form_sms_onay'] === 1): ?>
                    <p class="small text-muted text-center mt-2 mb-0"><i class="bi bi-phone"></i> Cep telefonunuza doğrulama kodu gönderilecektir.</p>
                <?php endif; ?>
            </form>

            <!-- 2. adım: SMS kodu -->
            <div id="kodAdim" class="d-none text-center">
                <i class="bi bi-chat-dots text-primary" style="font-size:2.6rem"></i>
                <h5 class="mt-2">Doğrulama Kodu</h5>
                <p class="text-muted"><b id="kodTelefon"></b> numarasına gönderilen <?= (int)$otp['hane'] ?> haneli kodu giriniz.</p>
                <input type="text" class="form-control kod-giris mb-2" id="kodInput" inputmode="numeric" autocomplete="one-time-code" maxlength="<?= (int)$otp['hane'] ?>">
                <div class="small text-muted mb-3">Kalan süre: <span id="kodSure">--:--</span></div>
                <div class="alert alert-danger d-none" id="kodHata"></div>
                <button type="button" class="btn btn-primary w-100 py-2 mb-2" id="btnDogrula">
                    <span class="spinner-border spinner-border-sm d-none me-1"></span> Onayla
                </button>
                <div class="d-flex justify-content-between">
                    <button type="button" class="btn btn-link px-0" id="btnGeri"><i class="bi bi-arrow-left"></i> Bilgileri düzenle</button>
                    <button type="button" class="btn btn-link px-0" id="btnTekrar" disabled>Kodu tekrar gönder</button>
                </div>
            </div>

            <!-- 3. adım: tamam -->
            <div id="tamamAdim" class="d-none text-center py-3">
                <i class="bi bi-check-circle-fill text-success" style="font-size:3rem"></i>
                <div class="mt-3" id="tamamMetin"></div>
            </div>
<?php endif; ?>
        </div>
    </div>
    <p class="text-center small text-muted">KVKK İzin Sistemi</p>
</div>

<?php if ($form && $metinSayfa === ''): ?>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
(() => {
    const token = <?= json_encode($_SESSION['form_token']) ?>;
    const select2Dil = { noResults: () => 'Sonuç bulunamadı', searching: () => 'Aranıyor...' };
    let sayac = null, tekrarSayac = null;

    const s2 = el => $(el).select2({ theme: 'bootstrap-5', width: '100%', language: select2Dil, placeholder: $(el).find('option:first').text() });
    $('.secimli').each((_, el) => s2(el));

    const yukleniyor = (btn, durum) => { $(btn).prop('disabled', durum).find('.spinner-border').toggleClass('d-none', !durum); };
    const post = veri => $.post(location.pathname, veri, null, 'json');

    // İl → ilçe
    $('#f_il').on('change', function () {
        const $ilce = $('#f_ilce');
        if (!$ilce.length) return;
        $ilce.empty().append('<option value="">' + (this.value ? 'Yükleniyor...' : 'Önce il seçiniz') + '</option>').trigger('change');
        if (!this.value) return;
        post({ action: 'ilceler', il_id: this.value, token }).done(r => {
            $ilce.empty().append('<option value="">İlçe seçiniz</option>');
            (r.data || []).forEach(x => $ilce.append(new Option(x.ilce_adi, x.ilce_id)));
            $ilce.trigger('change');
        });
    });

    // Üst seçenek → alt seçenek (ör. Geliş nedeni: İptal → İptal nedeni)
    $('.ust-secim').on('change', function () {
        const kod = $(this).data('kod'), deger = this.value;
        $(`.alt-kutu[data-kod="${kod}"]`).each((_, kutu) => {
            const acik = String($(kutu).data('ust')) === deger;
            $(kutu).toggleClass('d-none', !acik).find('select').prop('disabled', !acik).val('').trigger('change');
        });
    });

    // Cep telefonu: her biçim kabul edilir (+90, 0090, 0, boşluk, tire, parantez), 0XXX XXX XX XX'e düzeltilir
    const telefonCoz = v => {
        let d = String(v || '').replace(/\D/g, '');
        if (d.startsWith('00')) d = d.slice(2);
        if (d.length > 10 && d.startsWith('90')) d = d.slice(2);
        d = d.replace(/^0+/, '');
        return d.length === 10 && d[0] === '5' ? d : null;
    };
    const telefonBicim = d => `0${d.slice(0, 3)} ${d.slice(3, 6)} ${d.slice(6, 8)} ${d.slice(8)}`;
    const $tel = $('input[type=tel]');
    const telefonKontrol = (el, sessiz) => {
        const d = telefonCoz(el.value);
        const $k = $(el).closest('[data-alan]');
        if (d) {
            el.value = telefonBicim(d);
            $(el).removeClass('is-invalid').addClass('is-valid');
            $k.find('.invalid-feedback').text('');
            return true;
        }
        $(el).removeClass('is-valid');
        if (!sessiz && el.value.trim() !== '') {
            $(el).addClass('is-invalid');
            $k.find('.invalid-feedback').text('Geçerli bir cep telefonu giriniz. Numara 5 ile başlayan 10 haneden oluşmalıdır (örn. 0532 123 45 67).');
        }
        return el.value.trim() === '' || !!d;
    };
    $tel.on('blur change', function () { telefonKontrol(this, false); })
        .on('input', function () { if (telefonCoz(this.value)) telefonKontrol(this, true); else $(this).removeClass('is-valid'); })
        .on('paste', function () { setTimeout(() => telefonKontrol(this, false), 0); });

    // Metin modalındaki "Kabul ediyorum"
    $('.metin-onayla').on('click', function () { $(`#o_${$(this).data('anahtar')}_1`).prop('checked', true).trigger('change'); });

    // Hataları temizle / göster
    const hataTemizle = () => {
        $('#basvuruForm .is-invalid').removeClass('is-invalid');
        $('#basvuruForm .invalid-feedback').text('');
        $('#genelHata').addClass('d-none');
    };
    const hataGoster = hatalar => {
        let ilk = null;
        Object.entries(hatalar).forEach(([alan, mesaj]) => {
            const $k = $(`[data-alan="${alan}"]`);
            $k.find('.form-control, .form-select').addClass('is-invalid');
            $k.filter('.onay-kutu').addClass('is-invalid');
            $k.find('.invalid-feedback').first().text(mesaj);
            ilk = ilk || $k;
        });
        if (ilk) ilk[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
    };
    $('#basvuruForm').on('input change', '.is-invalid, input[type=radio]', function () {
        $(this).removeClass('is-invalid').closest('[data-alan]').removeClass('is-invalid').find('.invalid-feedback').first().text('');
    });

    const adim = ad => {
        $('#basvuruForm').toggleClass('d-none', ad !== 'form');
        $('#kodAdim').toggleClass('d-none', ad !== 'kod');
        $('#tamamAdim').toggleClass('d-none', ad !== 'tamam');
        window.scrollTo({ top: 0, behavior: 'smooth' });
    };
    const tamam = html => { clearInterval(sayac); clearInterval(tekrarSayac); $('#tamamMetin').html(html); adim('tamam'); };
    const sunucuHatasi = () => ({ success: false, message: 'Bağlantı hatası. Lütfen tekrar deneyiniz.' });
    const yenileGerekirse = r => { if (r.yenile) setTimeout(() => location.reload(), 1500); };

    const sureBaslat = (sure, tekrar) => {
        clearInterval(sayac); clearInterval(tekrarSayac);
        let kalan = sure, t = tekrar;
        const yaz = () => $('#kodSure').text(`${String(Math.floor(kalan / 60)).padStart(2, '0')}:${String(kalan % 60).padStart(2, '0')}`);
        yaz();
        sayac = setInterval(() => { kalan = Math.max(0, kalan - 1); yaz(); if (!kalan) clearInterval(sayac); }, 1000);
        $('#btnTekrar').prop('disabled', true).text(`Kodu tekrar gönder (${t})`);
        tekrarSayac = setInterval(() => {
            t--;
            if (t <= 0) { clearInterval(tekrarSayac); $('#btnTekrar').prop('disabled', false).text('Kodu tekrar gönder'); }
            else $('#btnTekrar').text(`Kodu tekrar gönder (${t})`);
        }, 1000);
    };

    // 1. adım: gönder
    $('#basvuruForm').on('submit', function (ev) {
        ev.preventDefault();
        hataTemizle();
        let telHatali = null;
        $tel.each((_, el) => { if (!telefonKontrol(el, false)) telHatali = telHatali || el; });
        if (telHatali) { telHatali.scrollIntoView({ behavior: 'smooth', block: 'center' }); telHatali.focus(); return; }
        const btn = $('#btnGonder');
        yukleniyor(btn, true);
        post($(this).serialize() + '&action=gonder').then(r => r, sunucuHatasi).then(r => {
            yukleniyor(btn, false);
            if (!r.success) {
                if (r.hatalar) hataGoster(r.hatalar);
                $('#genelHata').text(r.message).removeClass('d-none');
                yenileGerekirse(r);
                return;
            }
            if (r.adim === 'tamam') return tamam(r.html);
            $('#kodTelefon').text(r.telefon);
            $('#kodInput').val('');
            $('#kodHata').addClass('d-none');
            adim('kod');
            sureBaslat(r.sure, r.tekrar);
            setTimeout(() => $('#kodInput').trigger('focus'), 300);
        });
    });

    // 2. adım: kod doğrula
    const dogrula = () => {
        const kod = $('#kodInput').val().replace(/\D/g, '');
        if (kod.length !== <?= (int)$otp['hane'] ?>) {
            $('#kodHata').text('Lütfen <?= (int)$otp['hane'] ?> haneli kodu giriniz.').removeClass('d-none');
            return;
        }
        const btn = $('#btnDogrula');
        yukleniyor(btn, true);
        post({ action: 'dogrula', kod, token }).then(r => r, sunucuHatasi).then(r => {
            yukleniyor(btn, false);
            if (r.success) return tamam(r.html);
            $('#kodHata').text(r.message).removeClass('d-none');
            yenileGerekirse(r);
            if (r.bitti) { $('#kodInput').val(''); $('#btnTekrar').prop('disabled', false).text('Yeni kod iste'); clearInterval(tekrarSayac); }
        });
    };
    $('#btnDogrula').on('click', dogrula);
    $('#kodInput').on('input', function () {
        this.value = this.value.replace(/\D/g, '').slice(0, <?= (int)$otp['hane'] ?>);
        if (this.value.length === <?= (int)$otp['hane'] ?>) dogrula();
    }).on('keydown', e => { if (e.key === 'Enter') dogrula(); });

    $('#btnTekrar').on('click', function () {
        $(this).prop('disabled', true);
        $('#kodHata').addClass('d-none');
        post({ action: 'tekrar_gonder', token }).then(r => r, sunucuHatasi).then(r => {
            if (!r.success) {
                $('#kodHata').text(r.message).removeClass('d-none');
                $(this).prop('disabled', false);
                if (r.bitti) adim('form');
                return;
            }
            $('#kodInput').val('').trigger('focus');
            sureBaslat(r.sure, r.tekrar);
        });
    });

    $('#btnGeri').on('click', () => { clearInterval(sayac); clearInterval(tekrarSayac); adim('form'); });
})();
</script>
<?php endif; ?>
</body>
</html>
