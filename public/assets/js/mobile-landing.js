/* Page d'arrivée du QR code : vérifie le serveur et indique si la caméra est utilisable. */
(function () {
  'use strict';
  const BASE = (document.querySelector('meta[name="base-url"]').content || '/').replace(/\/$/, '');
  const set = (k, ok, html) => {
    const li = document.querySelector(`[data-check="${k}"]`);
    li.className = 'list-group-item d-flex align-items-center gap-2 ' + (ok === true ? 'text-success' : ok === false ? 'text-danger' : 'text-warning');
    li.innerHTML = `<i class="bi ${ok === true ? 'bi-check-circle-fill' : ok === false ? 'bi-x-circle-fill' : 'bi-exclamation-triangle-fill'}"></i> <span>${html}</span>`;
  };
  fetch(BASE + '/health', { cache: 'no-store' }).then((r) => r.json()).then((d) => {
    set('server', !!d.ok, d.ok ? 'Serveur POS Marwa accessible (v' + d.version + ')' : 'Serveur joignable mais base de données indisponible : prévenez le responsable.');
  }).catch(() => set('server', false, 'Serveur injoignable : vérifiez le Wi-Fi (même réseau que l\'ordinateur).'));
  if (window.isSecureContext && navigator.mediaDevices && navigator.mediaDevices.getUserMedia) set('camera', true, 'Caméra utilisable pour scanner les codes-barres.');
  else set('camera', null, 'Caméra en direct indisponible (connexion non HTTPS) : la lecture d\'un code-barres par photo reste possible.');
})();
