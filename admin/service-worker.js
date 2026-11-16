/**
 * Service Worker — PWA cache + offline + push + background sync
 * CACHE_VERSION artırılınca eski cache'ler silinir.
 */
const CACHE_VERSION = 'v2';
const CACHE_NAME = 'kvkkizin-admin-' + CACHE_VERSION;
const OFFLINE_URL = '/admin/offline.html';

const ON_YUKLEME = [
    OFFLINE_URL,
    '/admin/manifest.php',
    '/admin/icon.php?size=192'
];

// ---- Kurulum ----
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => cache.addAll(ON_YUKLEME)).then(() => self.skipWaiting())
    );
});

// ---- Aktivasyon: eski cache temizliği ----
self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) =>
            Promise.all(keys.filter((k) => k.startsWith('kvkkizin-admin-') && k !== CACHE_NAME).map((k) => caches.delete(k)))
        ).then(() => self.clients.claim())
    );
});

// ---- Fetch stratejileri ----
self.addEventListener('fetch', (event) => {
    const req = event.request;
    const url = new URL(req.url);

    // Sadece http/https istekleri (chrome-extension: gibi şemalar cache'lenemez)
    if (url.protocol !== 'http:' && url.protocol !== 'https:') {
        return;
    }

    // POST ve /api/ → daima ağ (cache yok)
    if (req.method !== 'GET' || url.pathname.includes('/api/')) {
        return;
    }

    // Gezinme: NetworkFirst → offline.html
    if (req.mode === 'navigate') {
        event.respondWith(
            fetch(req)
                .then((yanit) => {
                    const kopya = yanit.clone();
                    caches.open(CACHE_NAME).then((c) => c.put(req, kopya));
                    return yanit;
                })
                .catch(() => caches.match(req).then((c) => c || caches.match(OFFLINE_URL)))
        );
        return;
    }

    // Statik varlıklar: StaleWhileRevalidate
    if (/\.(css|js|png|jpg|jpeg|gif|svg|woff2?|ttf|ico)(\?.*)?$/.test(url.pathname + url.search) || url.pathname.startsWith('/admin/icon.php')) {
        event.respondWith(
            caches.match(req).then((cevap) => {
                const agdan = fetch(req)
                    .then((yanit) => {
                        if (yanit && yanit.status === 200) {
                            const kopya = yanit.clone();
                            caches.open(CACHE_NAME).then((c) => c.put(req, kopya));
                        }
                        return yanit;
                    })
                    .catch(() => cevap);
                return cevap || agdan;
            })
        );
    }
});

// ---- Push bildirimi ----
self.addEventListener('push', (event) => {
    let veri = { baslik: 'Bildirim', govde: '', url: '/admin/' };
    try {
        if (event.data) veri = Object.assign(veri, event.data.json());
    } catch (e) {
        if (event.data) veri.govde = event.data.text();
    }
    event.waitUntil(
        self.registration.showNotification(veri.baslik, {
            body: veri.govde || '',
            icon: '/admin/icon.php?size=192',
            badge: '/admin/icon.php?size=96',
            data: { url: veri.url || '/admin/' }
        })
    );
});

// ---- Bildirime tıklama: ilgili URL'e odaklan/aç ----
self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const hedef = (event.notification.data && event.notification.data.url) || '/admin/';
    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then((liste) => {
            for (const w of liste) {
                if (w.url.includes('/admin/') && 'focus' in w) {
                    w.navigate(hedef);
                    return w.focus();
                }
            }
            return clients.openWindow(hedef);
        })
    );
});

// ---- Background Sync: çevrimdışı kuyruk boşaltma ----
const KUYRUK_DB = 'offline-kuyruk-db';
const KUYRUK_STORE = 'kuyruk';

function kuyrukDbAc() {
    return new Promise((resolve, reject) => {
        const istek = indexedDB.open(KUYRUK_DB, 1);
        istek.onupgradeneeded = () => {
            if (!istek.result.objectStoreNames.contains(KUYRUK_STORE)) {
                istek.result.createObjectStore(KUYRUK_STORE, { keyPath: 'id', autoIncrement: true });
            }
        };
        istek.onsuccess = () => resolve(istek.result);
        istek.onerror = () => reject(istek.error);
    });
}

async function kuyruguBosalt() {
    const db = await kuyrukDbAc();
    const kayitlar = await new Promise((resolve, reject) => {
        const tx = db.transaction(KUYRUK_STORE, 'readonly');
        const istek = tx.objectStore(KUYRUK_STORE).getAll();
        istek.onsuccess = () => resolve(istek.result);
        istek.onerror = () => reject(istek.error);
    });

    for (const kayit of kayitlar) {
        try {
            const yanit = await fetch(kayit.url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(kayit.veri)
            });
            if (yanit.ok) {
                await new Promise((resolve, reject) => {
                    const tx = db.transaction(KUYRUK_STORE, 'readwrite');
                    const sil = tx.objectStore(KUYRUK_STORE).delete(kayit.id);
                    sil.onsuccess = () => resolve();
                    sil.onerror = () => reject(sil.error);
                });
            }
        } catch (e) {
            // ağ hâlâ yok — sonraki sync'te tekrar denenir
            break;
        }
    }
}

self.addEventListener('sync', (event) => {
    if (event.tag === 'offline-kuyruk-sync') {
        event.waitUntil(kuyruguBosalt());
    }
});
