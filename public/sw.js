/*
 * POS Marwa — service worker.
 * Seule la CAISSE (/pos) fonctionne hors connexion : sa page et les fichiers dont elle a besoin
 * (styles, scripts, polices, icônes) sont gardés dans le cache du navigateur. Toutes les autres
 * pages, les envois de données (POST) et les réponses JSON passent par le réseau uniquement :
 * rien de sensible n'est mis en cache ici. Les ventes hors connexion sont gérées par pos.js.
 */
const CACHE = 'pm-caisse-v1';
const SCOPE = new URL(self.registration.scope).pathname;          // ex. « / » ou « /posmarwa/ »
const POS_PAGE = SCOPE + 'pos';

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (e) => e.waitUntil((async () => {
  for (const k of await caches.keys()) if (k.startsWith('pm-caisse-') && k !== CACHE) await caches.delete(k);
  await self.clients.claim();
})()));

// La page de caisse demande l'oubli de sa copie (déconnexion) : plus aucune caisse servie hors ligne
self.addEventListener('message', (e) => {
  if (e.data && e.data.type === 'pm-forget-pos') e.waitUntil(caches.open(CACHE).then((c) => c.delete(POS_PAGE)));
});

self.addEventListener('fetch', (e) => {
  const req = e.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;

  // Page de caisse : réseau d'abord (copie à jour), copie locale si le réseau ne répond pas
  if (req.mode === 'navigate' && url.pathname === POS_PAGE) {
    e.respondWith((async () => {
      try {
        const res = await fetch(req);
        // Seule une vraie page de caisse est gardée (pas une redirection vers la connexion)
        if (res.ok && !res.redirected && (res.headers.get('content-type') || '').includes('text/html')) {
          const copy = res.clone();
          e.waitUntil(caches.open(CACHE).then((c) => c.put(POS_PAGE, copy)));
        }
        return res;
      } catch (err) {
        const cached = await caches.match(POS_PAGE);
        if (cached) return cached;
        throw err;
      }
    })());
    return;
  }

  // Fichiers statiques versionnés (?v=…) et photos des articles : copie locale d'abord
  if (url.pathname.startsWith(SCOPE + 'assets/') || url.pathname.startsWith(SCOPE + 'uploads/products/')) {
    e.respondWith((async () => {
      const cached = await caches.match(req);
      if (cached) return cached;
      const res = await fetch(req);
      if (res.ok && res.type === 'basic') {
        const copy = res.clone();
        e.waitUntil(caches.open(CACHE).then((c) => c.put(req, copy)));
      }
      return res;
    })());
  }
  // Tout le reste : réseau uniquement
});
