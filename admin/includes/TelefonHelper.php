<?php
/**
 * Telefon Numarası Helper
 * Kullanıcının girdiği her formattaki numarayı 90XXXXXXXXXX biçimine çevirir
 *
 * Kullanım:
 * 1. Exception ile: $telefon = telefonNormalizeEt($_POST['telefon']);
 * 2. Hatasız:       $telefon = telefonNormalizeEtGuvenli($_POST['telefon'], $hata);
 */

/**
 * Telefon numarasını 90XXXXXXXXXX formatına çevirir.
 *
 * Kabul edilen girdiler: +90 501 000 00 00, 0090-501-000-00-00, 0 501 000 00 00,
 * 90 501 000 0000, (0501) 000-00-00, 501 000 00 00 vb.
 *
 * @param string|null $deger Ham telefon girdisi
 * @return string|null 90XXXXXXXXXX formatında numara, boş girdide null
 * @throws Exception Geçerli bir cep numarası çıkarılamazsa
 */
function telefonNormalizeEt($deger)
{
    // Rakam dışı tüm karakterleri temizle (+, boşluk, tire, parantez vb.)
    $telefon = preg_replace('/[^0-9]/', '', trim((string)$deger));

    if ($telefon === '') {
        return null;
    }

    // Uluslararası çıkış öneki (00) ve ülke kodu (90) baştan temizlenir
    if (strpos($telefon, '00') === 0) {
        $telefon = substr($telefon, 2);
    }
    if (strlen($telefon) > 10 && strpos($telefon, '90') === 0) {
        $telefon = substr($telefon, 2);
    }

    // Şehirlerarası önek sıfırları temizlenir (0, 00 vb.)
    $telefon = ltrim($telefon, '0');

    // Geriye 10 haneli, 5 ile başlayan cep numarası kalmalı
    if (strlen($telefon) !== 10 || $telefon[0] !== '5') {
        throw new Exception('Geçersiz telefon numarası. Örnek: 0501 000 00 00');
    }

    return '90' . $telefon;
}

/**
 * telefonNormalizeEt()'in exception fırlatmayan sürümü.
 * Hata mesajı ikinci parametreye yazılır, dönüş değeri null olur.
 *
 * @param string|null $deger Ham telefon girdisi
 * @param string|null $hata  Hata mesajı (referans)
 * @return string|null Normalize numara veya null
 */
function telefonNormalizeEtGuvenli($deger, &$hata = null)
{
    $hata = null;

    try {
        return telefonNormalizeEt($deger);
    } catch (Exception $e) {
        $hata = $e->getMessage();
        return null;
    }
}
