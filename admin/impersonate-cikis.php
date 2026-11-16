<?php
/**
 * Admin Panel - Impersonate Çıkış
 * Impersonate modundan çıkıp orijinal admin hesabına döner.
 */

require_once __DIR__ . '/auth.php';

requireAuth();

// Impersonate modunda değilse anasayfaya dön
if (!Auth::isImpersonating()) {
    redirect('/admin/anasayfa');
}

Auth::stopImpersonate();

// Admin kullanıcı yönetimine geri döner
redirect('/admin/pages/personel-yonetimi.php');
