/**
 * PWA istemci — SW kaydı, install butonu, iOS banner, güncelleme, push abonelik
 */
(function () {
    'use strict';

    if (!('serviceWorker' in navigator)) return;

    let swKayit = null;
    let installPrompt = null;

    // ---- Service Worker kaydı ----
    navigator.serviceWorker.register('/admin/service-worker.js', { scope: '/admin/' })
        .then((kayit) => {
            swKayit = kayit;
            pushKurulum(kayit);

            // Güncelleme takibi
            kayit.addEventListener('updatefound', () => {
                const yeni = kayit.installing;
                if (!yeni) return;
                yeni.addEventListener('statechange', () => {
                    if (yeni.state === 'installed' && navigator.serviceWorker.controller) {
                        guncellemeBildir();
                    }
                });
            });
        })
        .catch((e) => console.warn('SW kaydı başarısız:', e));

    // ---- Install (Android/Windows Chrome-Edge) ----
    window.addEventListener('beforeinstallprompt', (e) => {
        e.preventDefault();
        installPrompt = e;
        installButonuGoster();
    });

    window.addEventListener('appinstalled', () => {
        installPrompt = null;
        const btn = document.getElementById('pwaInstallBtn');
        if (btn) btn.remove();
    });

    function installButonuGoster() {
        if (document.getElementById('pwaInstallBtn')) return;
        const btn = document.createElement('button');
        btn.id = 'pwaInstallBtn';
        btn.className = 'btn btn-primary btn-sm';
        btn.style.cssText = 'position:fixed;bottom:20px;right:20px;z-index:1050;box-shadow:0 2px 8px rgba(0,0,0,.3);';
        btn.innerHTML = '<i class="bi bi-download me-1"></i>Uygulamayı Kur';
        btn.onclick = async () => {
            if (!installPrompt) return;
            installPrompt.prompt();
            await installPrompt.userChoice;
            installPrompt = null;
            btn.remove();
        };
        document.body.appendChild(btn);
    }

    // ---- iOS banner (Safari otomatik prompt vermez) ----
    const iOS = /iphone|ipad|ipod/i.test(navigator.userAgent);
    const standalone = window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
    if (iOS && !standalone && !localStorage.getItem('pwaIosBannerKapali')) {
        const banner = document.createElement('div');
        banner.style.cssText = 'position:fixed;bottom:0;left:0;right:0;background:#0d6efd;color:#fff;padding:12px 16px;z-index:1060;font-size:14px;display:flex;align-items:center;gap:10px;';
        banner.innerHTML = '<span style="flex:1">📲 Uygulamayı kurmak için: <b>Paylaş</b> → <b>Ana Ekrana Ekle</b></span><button style="background:none;border:0;color:#fff;font-size:20px;line-height:1" aria-label="Kapat">×</button>';
        banner.querySelector('button').onclick = () => {
            localStorage.setItem('pwaIosBannerKapali', '1');
            banner.remove();
        };
        document.body.appendChild(banner);
    }

    // ---- Güncelleme bildirimi ----
    function guncellemeBildir() {
        if (document.getElementById('pwaGuncelleme')) return;
        const kutu = document.createElement('div');
        kutu.id = 'pwaGuncelleme';
        kutu.style.cssText = 'position:fixed;top:70px;right:20px;background:#212529;color:#fff;padding:12px 16px;border-radius:8px;z-index:1060;font-size:14px;box-shadow:0 2px 8px rgba(0,0,0,.3);';
        kutu.innerHTML = 'Yeni sürüm hazır. <a href="#" style="color:#6ea8fe">Yenile</a>';
        kutu.querySelector('a').onclick = (e) => {
            e.preventDefault();
            location.reload();
        };
        document.body.appendChild(kutu);
    }

    // ---- Push abonelik ----
    async function pushKurulum(kayit) {
        if (!('PushManager' in window) || !('Notification' in window)) return;

        if (Notification.permission === 'granted') {
            aboneOl(kayit); // sessiz abone
        } else if (Notification.permission === 'default') {
            pushButonuGoster(kayit);
        }
        // denied → hiçbir şey gösterme
    }

    function pushButonuGoster(kayit) {
        if (document.getElementById('pwaPushBtn')) return;
        const btn = document.createElement('button');
        btn.id = 'pwaPushBtn';
        btn.className = 'btn btn-warning btn-sm';
        btn.style.cssText = 'position:fixed;bottom:20px;left:20px;z-index:1050;box-shadow:0 2px 8px rgba(0,0,0,.3);';
        btn.innerHTML = '🔔 Bildirimleri Aç';
        btn.onclick = async () => {
            const izin = await Notification.requestPermission();
            if (izin === 'granted') {
                await aboneOl(kayit);
                btn.remove();
            } else if (izin === 'denied') {
                btn.remove();
            }
        };
        document.body.appendChild(btn);
    }

    async function aboneOl(kayit) {
        try {
            const mevcut = await kayit.pushManager.getSubscription();
            if (mevcut) {
                await sunucuyaKaydet(mevcut);
                return;
            }

            const yanit = await fetch('/admin/api/pwa-anahtar.php');
            if (!yanit.ok) return;
            const anahtar = await yanit.json();
            if (!anahtar.success || !anahtar.public_key) return;

            const abonelik = await kayit.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: base64UrlToUint8(anahtar.public_key)
            });
            await sunucuyaKaydet(abonelik);
        } catch (e) {
            console.warn('Push abonelik hatası:', e);
        }
    }

    async function sunucuyaKaydet(abonelik) {
        const j = abonelik.toJSON();
        await fetch('/admin/api/pwa-abone.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'kaydet',
                endpoint: j.endpoint,
                p256dh: j.keys.p256dh,
                auth: j.keys.auth,
                tarayici: navigator.userAgent
            })
        }).catch(() => {});
    }

    function base64UrlToUint8(b64) {
        const padding = '='.repeat((4 - (b64.length % 4)) % 4);
        const base64 = (b64 + padding).replace(/-/g, '+').replace(/_/g, '/');
        const ham = atob(base64);
        const dizi = new Uint8Array(ham.length);
        for (let i = 0; i < ham.length; i++) dizi[i] = ham.charCodeAt(i);
        return dizi;
    }
})();
