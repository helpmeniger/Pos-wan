/* MARWA — service worker : mise en cache des ressources statiques uniquement.
   Les appels API ne sont jamais servis depuis le cache par le service worker :
   aucune commande ni aucun paiement ne peut être « confirmé » hors ligne. */
const VERSION = 'marwa-v1';
const STATIC = ['assets/css/app.css', 'assets/js/core.js', 'assets/js/map.js', 'assets/vendor/leaflet/leaflet.js', 'assets/vendor/leaflet/leaflet.css', 'assets/img/logo.svg', 'assets/img/icon-192.png', 'hors-ligne'];

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(VERSION).then((c) => c.addAll(STATIC.map((p) => new URL(p, self.registration.scope).toString()))).then(() => self.skipWaiting()));
});
self.addEventListener('activate', (e) => {
  e.waitUntil(caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== VERSION).map((k) => caches.delete(k)))).then(() => self.clients.claim()));
});
self.addEventListener('fetch', (e) => {
  const url = new URL(e.request.url);
  if (e.request.method !== 'GET' || url.origin !== location.origin || url.pathname.includes('/api/')) return;
  if (url.pathname.includes('/assets/')) {
    // Ressources statiques : cache d'abord, mise à jour en arrière-plan
    e.respondWith(caches.open(VERSION).then(async (c) => {
      const hit = await c.match(e.request, { ignoreSearch: true });
      const net = fetch(e.request).then((r) => { if (r.ok) c.put(e.request, r.clone()); return r; }).catch(() => hit);
      return hit || net;
    }));
    return;
  }
  if (e.request.mode === 'navigate') {
    e.respondWith(fetch(e.request).catch(async () => (await caches.match(new URL('hors-ligne', self.registration.scope).toString())) || Response.error()));
  }
});
