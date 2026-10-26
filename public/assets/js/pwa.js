/* Enregistrement du service worker (installation de POS Marwa comme application, caisse hors connexion). */
(function () {
  if (!('serviceWorker' in navigator) || !window.isSecureContext) return;
  const m = document.querySelector('link[rel="manifest"]');
  if (!m) return;
  const sw = new URL('sw.js', m.href);
  navigator.serviceWorker.register(sw.href, { scope: new URL('./', m.href).pathname }).catch(() => { /* non bloquant */ });
  // Déconnexion : la copie locale de la caisse est oubliée (personne ne la rouvre hors ligne après vous)
  document.addEventListener('submit', (e) => {
    const f = e.target;
    if (!f || !/\/logout$/.test(f.getAttribute('action') || '')) return;
    if (navigator.serviceWorker.controller) navigator.serviceWorker.controller.postMessage({ type: 'pm-forget-pos' });
  }, true);
})();
