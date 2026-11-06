/* Aperçu d'une publication : reçoit la publication de la page des paramètres (même origine). */
(function () {
  'use strict';
  window.addEventListener('message', (e) => {
    if (e.origin !== location.origin || !e.data || e.data.type !== 'pm-text-preview') return;
    document.body.innerHTML = '';
    document.body.appendChild(window.PMTextSlide.render(e.data.item));
  });
  if (window.parent !== window) window.parent.postMessage({ type: 'pm-text-preview-ready' }, location.origin);
})();
