/* Pavé numérique de connexion par PIN (clavier physique également pris en charge). */
(function () {
  const input = document.getElementById('pinInput');
  const dots = document.getElementById('pinDots');
  if (!input) return;
  const render = () => { dots.innerHTML = ''; for (let i = 0; i < Math.max(4, input.value.length); i++) { const s = document.createElement('span'); if (i < input.value.length) s.className = 'on'; dots.appendChild(s); } };
  document.querySelectorAll('[data-key]').forEach((b) => b.addEventListener('click', () => {
    const k = b.dataset.key;
    if (k === 'del') input.value = input.value.slice(0, -1); else if (input.value.length < 8) input.value += k;
    render();
  }));
  document.addEventListener('keydown', (e) => {
    if (!document.getElementById('t-pin').classList.contains('active')) return;
    if (/^\d$/.test(e.key) && input.value.length < 8) { input.value += e.key; render(); e.preventDefault(); }
    else if (e.key === 'Backspace') { input.value = input.value.slice(0, -1); render(); e.preventDefault(); }
    else if (e.key === 'Enter' && input.value.length >= 4) { document.getElementById('pinForm').requestSubmit(); e.preventDefault(); }
  });
  render();
  const badge = document.querySelector('[data-autofocus-badge]'); if (badge) badge.focus();
})();
