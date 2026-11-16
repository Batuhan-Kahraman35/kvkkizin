<?php
/**
 * Admin Panel - Header Component
 * Portal Örnek Yazılım
 */

// $user değişkeni ana sayfada tanımlanmalı
if (!isset($user)) {
    die('Header component requires $user variable to be set.');
}

// Impersonate durumu kontrolü
$isImpersonating = Auth::isImpersonating();
$impersonator = Auth::impersonator();

// Çan tüm kullanıcılara gösterilir (bildirimler Bildirimler tablosundan beslenir;
// türetilmiş uyarıların yetki kontrolü notifications.php içinde yapılır)
$hasNotificationAccess = true;

// Header menüleri: menuler_header_goster=1 olan menüler + alt menüleri + sayfaları
// Sadece departman_id=1 (Administrator) kullanıcıları görebilir
$headerMenuTree = [];
if (isset($db) && ($user['departman_id'] ?? null) == 1) {
    try {
        $headerMenus = $db->fetchAll("
            SELECT menuler_id, menuler_menu_adi, menuler_ikon, menuler_parent_id, menuler_sira_no
            FROM Menuler
            WHERE menuler_durum = 1
              AND (
                  menuler_header_goster = 1
                  OR menuler_parent_id IN (
                      SELECT menuler_id FROM Menuler
                      WHERE menuler_durum = 1 AND menuler_header_goster = 1
                  )
              )
            ORDER BY ISNULL(menuler_parent_id, 0), menuler_sira_no
        ");

        if (!empty($headerMenus)) {
            $headerMenuIds = array_column($headerMenus, 'menuler_id');
            $placeholders = implode(',', array_fill(0, count($headerMenuIds), '?'));

            $headerPages = $db->fetchAll("
                SELECT sayfalar_id, sayfalar_menu_id, sayfalar_sayfa_adi, sayfalar_sayfa_url, sayfalar_ikon, sayfalar_sira_no
                FROM Menu_Sayfalar
                WHERE sayfalar_durum = 1
                  AND sayfalar_menu_id IN ($placeholders)
                ORDER BY sayfalar_sira_no
            ", $headerMenuIds);

            // Ağaç yapısı: ana menüler -> alt menüler -> sayfalar
            foreach ($headerMenus as $hm) {
                if ($hm['menuler_parent_id'] === null) {
                    $hm['children'] = [];
                    $hm['pages'] = [];
                    $headerMenuTree[$hm['menuler_id']] = $hm;
                }
            }
            foreach ($headerMenus as $hm) {
                if ($hm['menuler_parent_id'] !== null && isset($headerMenuTree[$hm['menuler_parent_id']])) {
                    $hm['pages'] = [];
                    $headerMenuTree[$hm['menuler_parent_id']]['children'][$hm['menuler_id']] = $hm;
                }
            }
            foreach ($headerPages as $hp) {
                $mid = $hp['sayfalar_menu_id'];
                if (isset($headerMenuTree[$mid])) {
                    $headerMenuTree[$mid]['pages'][] = $hp;
                } else {
                    foreach ($headerMenuTree as $pid => &$hParent) {
                        if (isset($hParent['children'][$mid])) {
                            $hParent['children'][$mid]['pages'][] = $hp;
                            break;
                        }
                    }
                    unset($hParent);
                }
            }
        }
    } catch (Exception $e) {
        error_log("Header Menu Query Error: " . $e->getMessage());
        $headerMenuTree = [];
    }
}

// Sayfa URL'sini temizle (sidebar ile aynı mantık)
function headerMenuPageUrl($url) {
    $cleanUrl = str_replace('.php', '', $url);
    $cleanUrl = preg_replace('/^pages\//', '', $cleanUrl);
    return '/admin/' . $cleanUrl;
}
?>
<!--begin::Header-->
<nav class="app-header navbar navbar-expand bg-body">
    <!--begin::Container-->
    <div class="container-fluid">
        <!--begin::Start Navbar Links-->
        <ul class="navbar-nav">
            <li class="nav-item">
                <a class="nav-link" data-lte-toggle="sidebar" href="#" role="button">
                    <i class="bi bi-list"></i>
                </a>
            </li>
            <li class="nav-item d-none d-md-block">
                <a href="/admin/anasayfa" class="nav-link">Ana Sayfa</a>
            </li>
            <?php foreach ($headerMenuTree as $hMenu): ?>
                <?php
                $hHasItems = !empty($hMenu['pages']) || !empty($hMenu['children']);
                if (!$hHasItems) continue;
                $hMenuIcon = $hMenu['menuler_ikon'] ?: 'bi bi-folder';
                ?>
                <li class="nav-item dropdown d-none d-md-block">
                    <a class="nav-link dropdown-toggle" data-bs-toggle="dropdown" href="#">
                        <i class="<?= htmlspecialchars($hMenuIcon) ?>"></i>
                        <?= htmlspecialchars($hMenu['menuler_menu_adi']) ?>
                    </a>
                    <ul class="dropdown-menu">
                        <?php foreach ($hMenu['pages'] as $hPage): ?>
                            <li>
                                <a class="dropdown-item" href="<?= htmlspecialchars(headerMenuPageUrl($hPage['sayfalar_sayfa_url'])) ?>">
                                    <i class="<?= htmlspecialchars($hPage['sayfalar_ikon'] ?: 'bi bi-circle') ?>"></i>
                                    <?= htmlspecialchars($hPage['sayfalar_sayfa_adi']) ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                        <?php foreach ($hMenu['children'] as $hChild): ?>
                            <?php if (empty($hChild['pages'])) continue; ?>
                            <?php if (!empty($hMenu['pages']) || array_key_first($hMenu['children']) !== $hChild['menuler_id']): ?>
                                <li><hr class="dropdown-divider"></li>
                            <?php endif; ?>
                            <li>
                                <h6 class="dropdown-header">
                                    <i class="<?= htmlspecialchars($hChild['menuler_ikon'] ?: 'bi bi-folder') ?>"></i>
                                    <?= htmlspecialchars($hChild['menuler_menu_adi']) ?>
                                </h6>
                            </li>
                            <?php foreach ($hChild['pages'] as $hPage): ?>
                                <li>
                                    <a class="dropdown-item" href="<?= htmlspecialchars(headerMenuPageUrl($hPage['sayfalar_sayfa_url'])) ?>">
                                        <i class="<?= htmlspecialchars($hPage['sayfalar_ikon'] ?: 'bi bi-circle') ?>"></i>
                                        <?= htmlspecialchars($hPage['sayfalar_sayfa_adi']) ?>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </ul>
                </li>
            <?php endforeach; ?>
        </ul>
        <!--end::Start Navbar Links-->
        
        <!--begin::End Navbar Links-->
        <ul class="navbar-nav ms-auto">
            <!--begin::Destek Bildirim Dropdown-->
            <li class="nav-item dropdown">
                <a class="nav-link" data-bs-toggle="dropdown" href="#" id="destekDropdown">
                    <i class="bi bi-headset"></i>
                    <span class="navbar-badge badge text-bg-danger" id="destekHeaderBadge" style="display: none;">0</span>
                </a>
                <div class="dropdown-menu dropdown-menu-lg dropdown-menu-end">
                    <span class="dropdown-item dropdown-header"><i class="bi bi-headset"></i> Destek Talepleri</span>
                    <div class="dropdown-divider"></div>
                    <div id="destekHeaderListe">
                        <div class="dropdown-item text-center text-muted py-3">Yeni bildirim yok</div>
                    </div>
                    <div class="dropdown-divider"></div>
                    <a href="/admin/pages/destek.php" class="dropdown-item dropdown-footer">Tüm Talepleri Gör</a>
                </div>
            </li>
            <!--end::Destek Bildirim Dropdown-->
            <?php if ($hasNotificationAccess): ?>
            <!--begin::Notifications Dropdown Menu-->
            <li class="nav-item dropdown">
                <a class="nav-link" data-bs-toggle="dropdown" href="#" id="notificationDropdown">
                    <i class="bi bi-bell"></i>
                    <span class="navbar-badge badge text-bg-warning" id="notificationBadge" style="display: none;">0</span>
                </a>
                <div class="dropdown-menu dropdown-menu-lg dropdown-menu-end" id="notificationMenu">
                    <span class="dropdown-item dropdown-header" id="notificationHeader">
                        <i class="bi bi-bell"></i> Bildirimler yükleniyor...
                    </span>
                    <div class="dropdown-divider"></div>
                    <div id="notificationList">
                        <!-- Bildirimler buraya gelecek -->
                    </div>
                    <div class="dropdown-divider"></div>
                    <a href="#" class="dropdown-item dropdown-footer" id="notificationMarkAllRead">
                        Tümünü Okundu Yap
                    </a>
                </div>
            </li>
            <!--end::Notifications Dropdown Menu-->
            <?php endif; ?>
            
            <!--begin::Fullscreen Toggle-->
            <li class="nav-item">
                <a class="nav-link" href="#" data-lte-toggle="fullscreen">
                    <i data-lte-icon="maximize" class="bi bi-arrows-fullscreen"></i>
                    <i data-lte-icon="minimize" class="bi bi-fullscreen-exit" style="display: none"></i>
                </a>
            </li>
            <!--end::Fullscreen Toggle-->
            
            <!--begin::User Menu Dropdown-->
            <li class="nav-item dropdown user-menu">
                <a href="#" class="nav-link dropdown-toggle" data-bs-toggle="dropdown">
                    <img
                        src="/admin/assets/images/user-avatar.png"
                        class="user-image rounded-circle shadow"
                        alt="<?= htmlspecialchars($user['name']) ?>"
                        onerror="this.src='data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%22160%22 height=%22160%22%3E%3Crect fill=%22%23667eea%22 width=%22160%22 height=%22160%22/%3E%3Ctext fill=%22%23fff%22 font-family=%22Arial%22 font-size=%2260%22 x=%2250%25%22 y=%2250%25%22 text-anchor=%22middle%22 dy=%22.3em%22%3E<?= strtoupper(substr($user['name'] ?? 'U', 0, 1)) ?>%3C/text%3E%3C/svg%3E'"
                    />
                    <span class="d-none d-md-inline"><?= htmlspecialchars($user['name']) ?></span>
                </a>
                <ul class="dropdown-menu dropdown-menu-lg dropdown-menu-end">
                    <!--begin::User Image-->
                    <li class="user-header text-bg-primary">
                        <img
                            src="/admin/assets/images/user-avatar.png"
                            class="rounded-circle shadow"
                            alt="<?= htmlspecialchars($user['name']) ?>"
                            onerror="this.src='data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%22160%22 height=%22160%22%3E%3Crect fill=%22%23ffffff%22 width=%22160%22 height=%22160%22/%3E%3Ctext fill=%22%23667eea%22 font-family=%22Arial%22 font-size=%2260%22 x=%2250%25%22 y=%2250%25%22 text-anchor=%22middle%22 dy=%22.3em%22%3E<?= strtoupper(substr($user['name'] ?? 'U', 0, 1)) ?>%3C/text%3E%3C/svg%3E'"
                        />
                        <p>
                            <?= htmlspecialchars($user['name']) ?>
                            <small><?= htmlspecialchars($user['email']) ?></small>
                        </p>
                    </li>
                    <!--end::User Image-->
                    
                    <!--begin::Menu Body-->
                    <li class="user-body">
                        <div class="row">
                            <div class="col-12 text-center">
                                <small class="text-muted">
                                    <i class="bi bi-clock"></i> Son giriş: <?= date('d.m.Y H:i:s', $_SESSION['login_time']) ?>
                                </small>
                            </div>
                        </div>
                    </li>
                    <!--end::Menu Body-->

                    <?php if ($isImpersonating && $impersonator): ?>
                    <!--begin::Impersonate Bildirimi-->
                    <li style="background:#fff3e0; border-top:1px solid #ffe0b2; padding:8px 12px;">
                        <div style="font-size:0.78rem; color:#e65100; line-height:1.4;">
                            <i class="bi bi-person-fill-badge"></i>
                            <strong><?= htmlspecialchars($user['name']) ?></strong> olarak giriş yapılmış durumdasınız.<br>
                            <span class="text-muted">
                                Orijinal: <strong><?= htmlspecialchars($impersonator['user_name']) ?></strong>
                            </span>
                        </div>
                        <a href="/admin/impersonate-cikis.php"
                           class="btn btn-sm btn-warning w-100 mt-2"
                           style="font-size:0.8rem;">
                            <i class="bi bi-box-arrow-left"></i> Kendi Hesabıma Dön
                        </a>
                    </li>
                    <!--end::Impersonate Bildirimi-->
                    <?php endif; ?>

                    <!--begin::Menu Footer-->
                    <li class="user-footer">
                        <a href="/admin/profil" class="btn btn-default btn-flat">Profil</a>
                        <a href="/admin/logout.php" class="btn btn-default btn-flat float-end">Çıkış Yap</a>
                    </li>
                    <!--end::Menu Footer-->
                </ul>
            </li>
            <!--end::User Menu Dropdown-->
        </ul>
        <!--end::End Navbar Links-->
    </div>
    <!--end::Container-->
</nav>
<!--end::Header-->

<!-- ═══ Destek Bildirim Polling ═══
     - Frontend API'ye asla doğrudan istek atmaz; /admin/api/destek-bildirim.php proxy'si
       API Key'i sunucu tarafında koruyarak CURL ile veriyi çeker.
     - localStorage'daki destek_son_kontrol sunucu tarihi formatında (Y-m-d H:i:s) tutulur,
       kıyaslama String olarak yapılır → timezone farkları tamamen devre dışı. -->
<script>
document.addEventListener("DOMContentLoaded", function() {
    var DESTEK_LS_KEY = 'destek_son_kontrol';
    var sunucuSimdi = <?= json_encode(date('Y-m-d H:i:s')) ?>;

    // İlk kullanımda son kontrol zamanını sunucu tarihiyle başlat
    if (!localStorage.getItem(DESTEK_LS_KEY)) {
        localStorage.setItem(DESTEK_LS_KEY, sunucuSimdi);
    }

    function destekKontrolEt() {
        var sonKontrol = localStorage.getItem(DESTEK_LS_KEY) || '';

        fetch('/admin/api/destek-bildirim.php?son_kontrol=' + encodeURIComponent(sonKontrol))
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (!data.success) return;

                var badge = document.getElementById('destekHeaderBadge');
                var liste = document.getElementById('destekHeaderListe');
                if (!badge || !liste) return;

                if (data.yeni_sayisi > 0) {
                    badge.textContent = data.yeni_sayisi > 9 ? '9+' : data.yeni_sayisi;
                    badge.style.display = '';

                    var html = '';
                    data.ticketler.forEach(function(t) {
                        html += '<a class="dropdown-item" href="/admin/pages/destek-detay.php?id=' + t.id + '">' +
                                '<i class="bi bi-ticket-detailed me-2 text-primary"></i>' +
                                '<div class="d-inline-block text-truncate" style="max-width: 250px;">' +
                                '<strong>#' + t.no + '</strong> ' + t.konu +
                                '</div>' +
                                '<span class="float-end text-muted text-sm">' + t.son_yanit + '</span>' +
                                '</a><div class="dropdown-divider"></div>';
                    });
                    liste.innerHTML = html;
                } else {
                    badge.style.display = 'none';
                    liste.innerHTML = '<div class="dropdown-item text-center text-muted py-3">Yeni bildirim yok</div>';
                }
            })
            .catch(function(err) { console.error('Destek bildirim proxy hatası:', err); });
    }

    // Sayfa yüklendiğinde bir kez kontrol et, ardından 60 sn'de bir polling yap
    destekKontrolEt();
    setInterval(destekKontrolEt, 60000);
});
</script>
