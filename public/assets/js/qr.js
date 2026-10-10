/* Rendu des QR codes : <div data-qr="contenu" data-qr-size="3"></div> (SVG, imprimable). */
(function () {
  if (typeof qrcode !== 'function') return;
  document.querySelectorAll('[data-qr]').forEach((el) => {
    try {
      const q = qrcode(0, 'M');
      q.addData(el.dataset.qr);
      q.make();
      el.innerHTML = q.createSvgTag({ cellSize: Number(el.dataset.qrSize || 3), margin: 2, scalable: true });
      const svg = el.querySelector('svg'); if (svg) { svg.setAttribute('role', 'img'); svg.setAttribute('aria-label', 'QR code'); }
    } catch (e) { el.textContent = el.dataset.qr; }
  });
})();
