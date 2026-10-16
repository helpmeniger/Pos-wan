/* MARWA — simulateur de la passerelle de paiement de démonstration */
(function () {
  'use strict';
  document.addEventListener('DOMContentLoaded', () => {
    const box = document.getElementById('demo-pay');
    if (!box) return;
    box.querySelectorAll('[data-outcome]').forEach((btn) => btn.addEventListener('click', () => window.MW.busy(btn, async () => {
      const r = await window.MW.post('/payments/demo/' + box.dataset.uuid + '/simulate', { outcome: btn.dataset.outcome });
      const ok = r.data.status === 'confirmed';
      window.MW.toast(ok ? 'Paiement confirmé par le serveur.' : 'Paiement refusé (' + r.data.status + ').', ok ? 'success' : 'error');
      setTimeout(() => (location.href = window.MW.base + '/client#/commande/' + box.dataset.order), 1200);
    })));
  });
})();
