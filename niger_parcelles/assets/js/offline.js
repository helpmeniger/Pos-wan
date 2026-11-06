/*
 * Niger Parcelles - mode hors connexion (IndexedDB, sans dépendance).
 *  - zone préparée : parcelles d'une emprise, consultables sans réseau ;
 *  - file d'attente : parcelles saisies hors connexion, envoyées au retour du réseau
 *    (identifiant unique par saisie : un envoi répété ne crée jamais de doublon).
 * Les données personnelles des propriétaires ne sont pas copiées sur l'appareil.
 */
(function () {
    'use strict';
    const DB = 'np-offline', VER = 1;
    let dbp = null;
    function open() {
        if (dbp) return dbp;
        dbp = new Promise((resolve, reject) => {
            if (!window.indexedDB) { reject(new Error('Stockage local indisponible sur ce navigateur.')); return; }
            const r = indexedDB.open(DB, VER);
            r.onupgradeneeded = () => {
                const db = r.result;
                if (!db.objectStoreNames.contains('queue')) db.createObjectStore('queue', { keyPath: 'uuid' });
                if (!db.objectStoreNames.contains('zone')) db.createObjectStore('zone', { keyPath: 'id' });
                if (!db.objectStoreNames.contains('meta')) db.createObjectStore('meta', { keyPath: 'k' });
            };
            r.onsuccess = () => resolve(r.result);
            r.onerror = () => reject(r.error);
        });
        return dbp;
    }
    async function tx(store, mode, fn) {
        const db = await open();
        return new Promise((resolve, reject) => {
            const t = db.transaction(store, mode);
            const s = t.objectStore(store);
            let out;
            Promise.resolve(fn(s)).then((v) => { out = v; });
            t.oncomplete = () => resolve(out);
            t.onerror = () => reject(t.error);
        });
    }
    const all = (store) => tx(store, 'readonly', (s) => new Promise((res) => { const r = s.getAll(); r.onsuccess = () => res(r.result); }));
    const uuid = () => (crypto.randomUUID ? crypto.randomUUID()
        : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => { const r = crypto.getRandomValues(new Uint8Array(1))[0] % 16; return (c === 'x' ? r : (r & 3) | 8).toString(16); }));

    const NPOffline = {
        /** Copie sur l'appareil les parcelles de l'emprise (zoom 17 : polygones). */
        async prepareZone(bounds) {
            const q = new URLSearchParams({ action: 'list', west: bounds.getWest(), south: bounds.getSouth(), east: bounds.getEast(), north: bounds.getNorth(), zoom: 18 });
            const fc = await window.NP.api('api/parcelles.php?' + q.toString());
            await tx('zone', 'readwrite', (s) => { fc.features.forEach((f) => s.put({ id: f.properties.id, f })); });
            await tx('meta', 'readwrite', (s) => s.put({ k: 'zone', at: Date.now(), bounds: [bounds.getSouth(), bounds.getWest(), bounds.getNorth(), bounds.getEast()], n: fc.features.length }));
            return fc.features.length;
        },
        /** Parcelles préparées dans l'emprise demandée (FeatureCollection). */
        async localFeatures(bounds) {
            const rows = await all('zone');
            const feats = rows.map((r) => r.f).filter((f) => {
                const ring = f.geometry.type === 'Polygon' ? f.geometry.coordinates[0] : [f.geometry.coordinates];
                return ring.some(([lng, lat]) => bounds.contains([lat, lng]));
            });
            return { type: 'FeatureCollection', features: feats, total: feats.length, truncated: false, offline: true };
        },
        async zoneInfo() {
            return tx('meta', 'readonly', (s) => new Promise((res) => { const r = s.get('zone'); r.onsuccess = () => res(r.result || null); }));
        },
        async clearZone() { await tx('zone', 'readwrite', (s) => s.clear()); await tx('meta', 'readwrite', (s) => s.delete('zone')); },

        /** Ajoute une parcelle saisie hors connexion à la file d'envoi. */
        async enqueue(attributes, geometry) {
            const item = { uuid: uuid(), attributes, geometry, created: Date.now(), status: 'en_attente', message: null };
            await tx('queue', 'readwrite', (s) => s.put(item));
            return item;
        },
        queue: () => all('queue'),
        async remove(id) { await tx('queue', 'readwrite', (s) => s.delete(id)); },
        async count() { return (await all('queue')).length; },

        /**
         * Envoie la file au serveur. acknowledgeIds : saisies dont l'utilisateur a accepté
         * les avertissements (chevauchements). Retourne un bilan.
         */
        async sync(acknowledgeIds) {
            const items = await all('queue');
            const report = { sent: [], warnings: [], errors: [], needLogin: false };
            if (!items.length) return report;
            let csrf;
            try {
                const r = await fetch(window.NP.url('api/session.php?action=token'), { credentials: 'same-origin', headers: { Accept: 'application/json' } });
                if (r.status === 401) { report.needLogin = true; return report; }
                csrf = (await r.json()).csrf;
            } catch (e) {
                report.errors.push('Serveur injoignable.');
                return report;
            }
            for (const it of items) {
                try {
                    const res = await fetch(window.NP.url('api/parcelles.php?action=create'), {
                        method: 'POST', credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': csrf },
                        body: JSON.stringify({ attributes: it.attributes, geometry: it.geometry, client_uuid: it.uuid,
                            acknowledge: (acknowledgeIds || []).includes(it.uuid) }),
                    });
                    const data = await res.json().catch(() => ({}));
                    if (res.ok) {
                        report.sent.push(data.parcel_reference);
                        await this.remove(it.uuid);
                    } else if (res.status === 409) {
                        it.status = 'avertissement'; it.message = (data.warnings || []).join(' ');
                        await tx('queue', 'readwrite', (s) => s.put(it));
                        report.warnings.push(it);
                    } else if (res.status === 401) {
                        report.needLogin = true; break;
                    } else {
                        it.status = 'erreur'; it.message = data.error || ('Erreur ' + res.status);
                        await tx('queue', 'readwrite', (s) => s.put(it));
                        report.errors.push(it.message);
                    }
                } catch (e) {
                    report.errors.push('Connexion interrompue : la synchronisation reprendra plus tard.');
                    break;
                }
            }
            return report;
        },
    };
    window.NPOffline = NPOffline;

    // Application installable : enregistrement du service worker (HTTPS ou localhost requis)
    if ('serviceWorker' in navigator && window.isSecureContext && window.NP) {
        navigator.serviceWorker.register(window.NP.url('sw.js'), { scope: window.NP.url('') }).catch(() => { /* mode hors ligne indisponible */ });
    }
})();
