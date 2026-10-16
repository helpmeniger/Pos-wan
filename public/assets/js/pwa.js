/* Enregistrement du service worker (installation de POS Marwa comme application, si le navigateur le permet). */
(function () {
  if (!('serviceWorker' in navigator) || !window.isSecureContext) return;
  const m = document.querySelector('link[rel="manifest"]');
  if (!m) return;
  const sw = new URL('sw.js', m.href);
  navigator.serviceWorker.register(sw.href, { scope: new URL('./', m.href).pathname }).catch(() => { /* non bloquant */ });
})();
