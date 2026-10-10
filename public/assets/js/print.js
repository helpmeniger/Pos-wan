/* Impression : bouton et impression automatique (après validation d'une vente). */
(function () {
  document.querySelectorAll('[data-print]').forEach((b) => b.addEventListener('click', () => window.print()));
  if (document.querySelector('[data-autoprint]')) { window.addEventListener('load', () => setTimeout(() => window.print(), 250)); }
  window.addEventListener('afterprint', () => { const r = document.querySelector('[data-return-after-print]'); if (r) location.href = r.getAttribute('href'); });
})();
