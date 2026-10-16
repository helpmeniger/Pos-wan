/* POS Marwa — service worker minimal : rend l'application installable (fenêtre dédiée).
   Aucune donnée n'est mise en cache : tout passe par le serveur (ventes et stock toujours à jour). */
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (e) => e.waitUntil(self.clients.claim()));
self.addEventListener('fetch', () => { /* réseau uniquement */ });
