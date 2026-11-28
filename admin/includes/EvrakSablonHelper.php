<?php
/**
 * DOCX evrak şablonu yardımcı sınıfı
 *
 * Şablonlarda {{degisken_kod}} yer tutucuları bulunur. Word bir yer tutucuyu
 * birden fazla run'a (w:r) bölebildiği için değiştirme paragraf düzeyinde,
 * birleştirilmiş metin üzerinden yapılır; eşleşme ilk w:t düğümüne yazılır,
 * kapsanan diğer düğümlerden ilgili parça silinir. Böylece paragrafın geri
 * kalanındaki biçim (kalın, italik vb.) korunur.
 */

class EvrakSablonHelper {

    const W_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    const DESEN = '/\{\{\s*([a-z0-9_]+)\s*\}\}/';

    /**
     * Metin içeren XML parçaları (gövde, üst/alt bilgi, dipnot)
     */
    public static function metinParcasiMi($ad) {
        return (bool)preg_match('#^word/(document|header\d*|footer\d*|footnotes|endnotes)\.xml$#', $ad);
    }

    /**
     * İlişki dosyaları (bağlantı hedefleri: mailto, http)
     */
    public static function iliskiParcasiMi($ad) {
        return (bool)preg_match('#^word/_rels/[^/]+\.rels$#', $ad);
    }

    /**
     * XML parçasındaki her paragrafa kurallar uygular.
     *
     * @param string $xml
     * @param array  $kurallar [[regex, string|callable], ...] sırayla uygulanır
     * @return string
     */
    public static function xmlDegistir($xml, array $kurallar) {
        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = true;
        if (!@$dom->loadXML($xml, LIBXML_PARSEHUGE)) {
            throw new Exception('Şablon XML okunamadı.');
        }
        $xp = new DOMXPath($dom);
        $xp->registerNamespace('w', self::W_NS);

        foreach ($xp->query('//w:p') as $p) {
            // Yalnızca bu paragrafa ait w:t düğümleri (metin kutusu içindeki iç paragraflar hariç)
            $dugumler = [];
            foreach ($xp->query('.//w:t', $p) as $t) {
                $ata = $t->parentNode;
                while ($ata && !($ata->localName === 'p' && $ata->namespaceURI === self::W_NS)) {
                    $ata = $ata->parentNode;
                }
                if ($ata === $p) {
                    $dugumler[] = $t;
                }
            }
            if (!$dugumler) {
                continue;
            }
            foreach ($kurallar as $kural) {
                self::paragrafaUygula($dom, $dugumler, $kural[0], $kural[1]);
            }
        }
        return $dom->saveXML();
    }

    private static function paragrafaUygula(DOMDocument $dom, array $dugumler, $desen, $yeni) {
        $metin = '';
        $araliklar = [];
        foreach ($dugumler as $i => $t) {
            $bas = strlen($metin);
            $metin .= $t->textContent;
            $araliklar[$i] = [$bas, strlen($metin)];
        }
        if ($metin === '' || !preg_match_all($desen, $metin, $eslesmeler, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return;
        }

        // Sondan başa uygula, önceki ofsetler bozulmasın
        for ($k = count($eslesmeler) - 1; $k >= 0; $k--) {
            $m = $eslesmeler[$k];
            $s = $m[0][1];
            $e = $s + strlen($m[0][0]);
            if (is_callable($yeni)) {
                $gruplar = array_map(function ($g) { return $g[0]; }, $m);
                $deger = $yeni($gruplar);
                if ($deger === null) {
                    continue; // bilinmeyen yer tutucu: olduğu gibi kalsın
                }
            } else {
                $deger = preg_replace($desen, $yeni, $m[0][0]);
            }

            $ilk = true;
            foreach ($dugumler as $i => $t) {
                list($db, $ds) = $araliklar[$i];
                if ($ds <= $s || $db >= $e) {
                    continue;
                }
                $icerik = $t->textContent;
                $yerelBas = max(0, $s - $db);
                $yerelSon = min(strlen($icerik), $e - $db);
                $onek = substr($icerik, 0, $yerelBas);
                $sonek = substr($icerik, $yerelSon);
                self::metinYaz($dom, $t, $ilk ? $onek . $deger . $sonek : $onek . $sonek);
                $ilk = false;
            }

            // Sonraki eşleşmeler için aralıkları yeniden hesapla
            $metin = '';
            foreach ($dugumler as $i => $t) {
                $bas = strlen($metin);
                $metin .= $t->textContent;
                $araliklar[$i] = [$bas, strlen($metin)];
            }
        }
    }

    private static function metinYaz(DOMDocument $dom, DOMElement $t, $metin) {
        while ($t->firstChild) {
            $t->removeChild($t->firstChild);
        }
        $t->appendChild($dom->createTextNode($metin));
        $t->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
    }

    /**
     * Şablonda kullanılan değişken kodları
     *
     * @param string $sablonYol
     * @return string[]
     */
    public static function degiskenler($sablonYol) {
        $zip = new ZipArchive();
        if ($zip->open($sablonYol) !== true) {
            throw new Exception('Şablon dosyası açılamadı.');
        }
        $kodlar = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $ad = $zip->getNameIndex($i);
            if (!self::metinParcasiMi($ad) && !self::iliskiParcasiMi($ad)) {
                continue;
            }
            $xml = $zip->getFromIndex($i);
            // Run sınırlarını yok saymak için etiketleri at
            $duz = preg_replace('/<[^>]+>/', '', $xml);
            if (preg_match_all(self::DESEN, $duz, $m)) {
                $kodlar = array_merge($kodlar, $m[1]);
            }
            if (preg_match_all(self::DESEN, $xml, $m)) {
                $kodlar = array_merge($kodlar, $m[1]);
            }
        }
        $zip->close();
        $kodlar = array_values(array_unique($kodlar));
        sort($kodlar);
        return $kodlar;
    }

    /**
     * Şablonu değerlerle doldurup hedef dosyaya yazar.
     *
     * @param string $sablonYol
     * @param string $hedefYol
     * @param array  $degerler ['firma_adi' => '...']
     */
    public static function doldur($sablonYol, $hedefYol, array $degerler) {
        if (!copy($sablonYol, $hedefYol)) {
            throw new Exception('Evrak dosyası oluşturulamadı.');
        }
        $zip = new ZipArchive();
        if ($zip->open($hedefYol) !== true) {
            throw new Exception('Evrak dosyası açılamadı.');
        }
        $geriCagir = function ($g) use ($degerler) {
            return array_key_exists($g[1], $degerler) ? (string)$degerler[$g[1]] : null;
        };
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $ad = $zip->getNameIndex($i);
            if (self::metinParcasiMi($ad)) {
                $xml = $zip->getFromIndex($i);
                $zip->addFromString($ad, self::xmlDegistir($xml, [[self::DESEN, $geriCagir]]));
            } elseif (self::iliskiParcasiMi($ad)) {
                $xml = $zip->getFromIndex($i);
                $xml = preg_replace_callback(self::DESEN, function ($m) use ($degerler) {
                    if (!array_key_exists($m[1], $degerler)) {
                        return $m[0];
                    }
                    return htmlspecialchars(str_replace(' ', '%20', (string)$degerler[$m[1]]), ENT_XML1 | ENT_QUOTES, 'UTF-8');
                }, $xml);
                $zip->addFromString($ad, $xml);
            }
        }
        $zip->close();
    }

    /**
     * Çıktı dosya adını üretir (geçersiz karakterler temizlenir)
     */
    public static function dosyaAdi($desen, array $degerler) {
        $ad = preg_replace_callback(self::DESEN, function ($m) use ($degerler) {
            return isset($degerler[$m[1]]) ? $degerler[$m[1]] : '';
        }, (string)$desen);
        $ad = preg_replace('/[\\\\\/:*?"<>|\x00-\x1F]+/u', ' ', $ad);
        $ad = trim(preg_replace('/\s+/u', ' ', $ad), " .-");
        if (mb_strlen($ad) > 150) {
            $ad = mb_substr($ad, 0, 150);
        }
        return $ad !== '' ? $ad : 'evrak';
    }
}
