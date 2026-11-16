<?php
/**
 * Ön yüz üst kısım (head + üst menü)
 *
 * Kullanmadan önce tanımlanabilecek değişkenler:
 *   $sayfaBaslik    — <title> içeriği
 *   $sayfaAciklama  — meta description
 *   $menuSabit      — true ise menü baştan koyu zeminli (hero'suz iç sayfalar)
 */

require_once __DIR__ . '/site-veri.php';

$sayfaBaslik   = $sayfaBaslik   ?? 'KVKK İzin Sistemi';
$sayfaAciklama = $sayfaAciklama ?? $siteBaslik . ' İzin Sistemi, firmaların izinli verilerini oluşturup saklayabileceği özel bir yazılımdır.';
$menuSabit     = $menuSabit     ?? false;
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $esc($sayfaBaslik) ?></title>
    <meta name="description" content="<?= $esc($sayfaAciklama) ?>">
    <link rel="icon" href="<?= $esc($siteFavicon) ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/site.css?v=1">
</head>
<body>

    <header class="ust-menu<?= $menuSabit ? ' sabit' : '' ?>" data-sabit="<?= $menuSabit ? '1' : '0' ?>">
        <a href="/" class="marka">KVKK İZİN SİSTEMİ</a>

        <nav class="menu-linkler">
            <?php foreach ($ustMenu as $link): ?>
                <a href="<?= $esc($link['link']) ?>"><?= $esc($link['ad']) ?></a>
            <?php endforeach; ?>
        </nav>

        <div class="menu-butonlar">
            <?php foreach ($girisButonlari as $buton): ?>
                <a href="<?= $esc($buton['link']) ?>" class="btn-giris"><?= $esc($buton['ad']) ?></a>
            <?php endforeach; ?>
        </div>
    </header>
