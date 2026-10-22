/* MARWA REMOTE ASSIST — page « Assistance à distance » : formulaires repliés, copier. */
(function () {
  'use strict';
  document.addEventListener('click', (e) => {
    const s = e.target.closest('[data-show]');
    if (s) { const f = document.querySelector(s.dataset.show); if (f) { f.classList.toggle('d-none'); const i = f.querySelector('input'); if (i && !f.classList.contains('d-none')) i.focus(); } return; }
    const c = e.target.closest('[data-copy-from]');
    if (c) {
      const src = document.querySelector(c.dataset.copyFrom);
      if (!src) return;
      src.select && src.select();
      const done = () => { c.innerHTML = '<i class="bi bi-check2"></i> Copié'; };
      if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(src.value || src.textContent).then(done).catch(() => { document.execCommand('copy'); done(); });
      else { document.execCommand('copy'); done(); }
    }
  });
})();
