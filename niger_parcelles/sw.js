/*
 * Niger Parcelles - service worker (application installable, mode hors connexion).
 *
 * Mise en cache :
 *  - interface (CSS, JS, icônes, bibliothèques) : servie depuis le cache, mise à jour en arrière-plan ;
 *  - pages consultées : réseau d'abord, copie en cache en secours (hors connexion) ;
 *  - tuiles de fond de carte DÉJÀ CONSULTÉES : conservées (aucun téléchargement en masse,
 *    conformément aux conditions d'OpenStreetMap et d'Esri) ;
 *  - jamais : API, documents privés, exports, formulaires envoyés (POST).
 */
'use strict';
const VERSION = 'np-v3';
const SHELL = 'np-shell-' + VERSION;
const PAGES = 'np-pages';
const TILES = 'np-tiles';
const MAX_TILES = 4000;
const SHELL_FILES = [
    'offline.html', 'manifest.webmanifest',
    'assets/css/app.css', 'assets/js/app.js', 'assets/js/map.js', 'assets/js/parcel-assist.js', 'assets/js/offline.js',
    'assets/js/assist-worker.js', 'assets/img/logo.svg', 'assets/img/icon-192.png',
    'assets/vendor/bootstrap/css/bootstrap.min.css', 'assets/vendor/bootstrap/js/bootstrap.bundle.min.js',
    'assets/vendor/bootstrap-icons/bootstrap-icons.min.css', 'assets/vendor/bootstrap-icons/fonts/bootstrap-icons.woff2',
    'assets/vendor/leaflet/leaflet.css', 'assets/vendor/leaflet/leaflet.js',
    'assets/vendor/geoman/leaflet-geoman.css', 'assets/vendor/geoman/leaflet-geoman.js', 'assets/vendor/turf/turf.min.js',
];
const TILE_HOSTS = /(^|\.)tile\.openstreetmap\.(org|fr)$|basemaps\.cartocdn\.com$|arcgisonline\.com$/;

self.addEventListener('install', (e) => {
    e.waitUntil(caches.open(SHELL).then((c) => c.addAll(SHELL_FILES.map((f) => new Request(f, { cache: 'reload' })))).then(() => self.skipWaiting()));
});
self.addEventListener('activate', (e) => {
    e.waitUntil(caches.keys().then((keys) => Promise.all(keys.filter((k) => k.startsWith('np-shell-') && k !== SHELL).map((k) => caches.delete(k))))
        .then(() => self.clients.claim()));
});
// Déconnexion : les pages en cache (données) sont effacées
self.addEventListener('message', (e) => {
    if (e.data && e.data.type === 'clear-private') e.waitUntil(caches.delete(PAGES));
});

async function trimTiles() {
    const c = await caches.open(TILES);
    const keys = await c.keys();
    for (let i = 0; i < keys.length - MAX_TILES; i++) await c.delete(keys[i]);
}

self.addEventListener('fetch', (e) => {
    const req = e.request;
    if (req.method !== 'GET') return;
    const url = new URL(req.url);

    if (url.origin !== self.location.origin) {
        if (!TILE_HOSTS.test(url.hostname)) return;
        e.respondWith(caches.open(TILES).then(async (c) => {
            const hit = await c.match(req);
            const net = fetch(req).then((res) => {
                if (res.ok) { c.put(req, res.clone()); trimTiles(); }
                return res;
            }).catch(() => hit || Response.error());
            return hit || net;
        }));
        return;
    }
    const path = url.pathname;
    if (path.includes('/api/') || path.includes('/storage/') || path.endsWith('/logout.php') || path.endsWith('/install.php')) return;

    if (path.includes('/assets/') || path.endsWith('/manifest.webmanifest')) {
        e.respondWith(caches.open(SHELL).then(async (c) => {
            const hit = await c.match(req, { ignoreSearch: true });
            const net = fetch(req).then((res) => { if (res.ok) c.put(req, res.clone()); return res; }).catch(() => hit);
            return hit || net;
        }));
        return;
    }
    if (req.mode === 'navigate') {
        e.respondWith(fetch(req).then((res) => {
            if (res.ok && !res.redirected && !path.endsWith('/login.php')) {
                const copy = res.clone();
                caches.open(PAGES).then((c) => c.put(req, copy));
            }
            return res;
        }).catch(async () => (await caches.match(req, { cacheName: PAGES })) || (await caches.match('offline.html', { cacheName: SHELL }))
            || new Response('Hors connexion', { status: 503 })));
    }
});
