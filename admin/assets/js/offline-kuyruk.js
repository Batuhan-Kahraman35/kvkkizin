/**
 * OfflineKuyruk — çevrimdışı kayıt kuyruğu (IndexedDB + Background Sync)
 *
 * Kullanım:
 *   const s = await OfflineKuyruk.gonder('/admin/api/xyz.php', { action:'ekle', ... });
 *   if (s.kuyruklandi) showToast('Çevrimdışısınız — kuyruğa alındı', 'warning');
 *   else // s.veri = sunucu yanıtı
 *
 * Her kayda istemci_uuid eklenir → sunucu tarafı mükerrer önleme yapmalı.
 */
const OfflineKuyruk = (function () {
    'use strict';

    const DB_ADI = 'offline-kuyruk-db';
    const STORE = 'kuyruk';
    const SYNC_TAG = 'offline-kuyruk-sync';

    function dbAc() {
        return new Promise((resolve, reject) => {
            const istek = indexedDB.open(DB_ADI, 1);
            istek.onupgradeneeded = () => {
                if (!istek.result.objectStoreNames.contains(STORE)) {
                    istek.result.createObjectStore(STORE, { keyPath: 'id', autoIncrement: true });
                }
            };
            istek.onsuccess = () => resolve(istek.result);
            istek.onerror = () => reject(istek.error);
        });
    }

    function uuid() {
        if (crypto.randomUUID) return crypto.randomUUID();
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
            const r = (Math.random() * 16) | 0;
            return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
        });
    }

    async function kuyrugaEkle(url, veri) {
        const db = await dbAc();
        return new Promise((resolve, reject) => {
            const tx = db.transaction(STORE, 'readwrite');
            tx.objectStore(STORE).add({ url: url, veri: veri, eklenme: new Date().toISOString() });
            tx.oncomplete = () => resolve();
            tx.onerror = () => reject(tx.error);
        });
    }

    async function kuyrukSayisi() {
        const db = await dbAc();
        return new Promise((resolve) => {
            const istek = db.transaction(STORE, 'readonly').objectStore(STORE).count();
            istek.onsuccess = () => resolve(istek.result);
            istek.onerror = () => resolve(0);
        });
    }

    async function syncKaydet() {
        try {
            const kayit = await navigator.serviceWorker.ready;
            if ('sync' in kayit) {
                await kayit.sync.register(SYNC_TAG);
                return true;
            }
        } catch (e) { /* iOS vb. — foreground replay devreye girer */ }
        return false;
    }

    /**
     * Önce ağdan dener; başarısızsa kuyruğa alır.
     * @returns {Promise<{kuyruklandi:boolean, veri?:any}>}
     */
    async function gonder(url, veri) {
        veri = Object.assign({}, veri, { istemci_uuid: uuid() });

        try {
            const yanit = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(veri)
            });
            if (!yanit.ok) throw new Error('HTTP ' + yanit.status);
            return { kuyruklandi: false, veri: await yanit.json() };
        } catch (e) {
            await kuyrugaEkle(url, veri);
            await syncKaydet();
            return { kuyruklandi: true };
        }
    }

    /**
     * Foreground replay — background sync olmayan platformlar (iOS) için.
     * SW'deki boşaltıcı ile aynı işi ana thread'de yapar.
     */
    async function kuyruguBosalt() {
        const db = await dbAc();
        const kayitlar = await new Promise((resolve) => {
            const istek = db.transaction(STORE, 'readonly').objectStore(STORE).getAll();
            istek.onsuccess = () => resolve(istek.result);
            istek.onerror = () => resolve([]);
        });

        let gonderilen = 0;
        for (const kayit of kayitlar) {
            try {
                const yanit = await fetch(kayit.url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(kayit.veri)
                });
                if (yanit.ok) {
                    await new Promise((resolve) => {
                        const tx = db.transaction(STORE, 'readwrite');
                        tx.objectStore(STORE).delete(kayit.id);
                        tx.oncomplete = () => resolve();
                        tx.onerror = () => resolve();
                    });
                    gonderilen++;
                }
            } catch (e) {
                break; // ağ hâlâ yok
            }
        }
        return gonderilen;
    }

    // Bağlantı gelince ve sayfa açılışında kuyruğu boşaltmayı dene
    window.addEventListener('online', () => {
        kuyruguBosalt().then((n) => {
            if (n > 0 && typeof showToast === 'function') {
                showToast(n + ' bekleyen kayıt gönderildi', 'success');
            }
        });
    });

    document.addEventListener('DOMContentLoaded', () => {
        if (navigator.onLine) kuyruguBosalt();
    });

    return { gonder: gonder, kuyruguBosalt: kuyruguBosalt, kuyrukSayisi: kuyrukSayisi };
})();
