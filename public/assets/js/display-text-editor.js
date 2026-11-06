/* Paramètres → Écran client : éditeur de publication avec aperçu réel en direct. */
(function () {
  'use strict';
  const form = document.getElementById('pubForm');
  if (!form || !window.PMTextSlide) return;
  const frameBox = form.querySelector('[data-pub-frame]');
  const frame = frameBox.querySelector('iframe');
  const pal = form.querySelector('[data-pub-palette]');
  const photo = form.querySelector('[data-pub-photo]');
  const noPhoto = form.querySelector('[data-pub-nophoto]');
  const current = form.querySelector('[data-pub-current]');
  const col = (n) => form.querySelector(`[name="${n}"]`);
  let photoUrl = current ? current.dataset.pubCurrent : null;
  let ready = false;
  let t;

  function fit() { frame.style.transform = `scale(${frameBox.clientWidth / 1920})`; }
  window.addEventListener('resize', fit);
  fit();

  function item() {
    const style = { palette: pal.value, layout: col('layout').value };
    if (pal.value === 'perso') Object.assign(style, { bg: col('bg').value, fg: col('fg').value, accent: col('accent').value });
    return { title: col('title').value.trim(), body: col('body').value, style, url: noPhoto && noPhoto.checked ? null : photoUrl };
  }
  function send() {
    clearTimeout(t);
    t = setTimeout(() => { if (ready) frame.contentWindow.postMessage({ type: 'pm-text-preview', item: item() }, location.origin); }, 250);
  }
  window.addEventListener('message', (e) => { if (e.origin === location.origin && e.data && e.data.type === 'pm-text-preview-ready') { ready = true; send(); } });

  // Palette choisie : les trois couleurs prennent ses valeurs ; une couleur modifiée → « personnalisées »
  function applyPalette() {
    const p = window.PMTextSlide.PALETTES[pal.value];
    if (p) { col('bg').value = p.bg; col('fg').value = p.fg; col('accent').value = p.accent; }
  }
  pal.addEventListener('change', () => { applyPalette(); send(); });
  ['bg', 'fg', 'accent'].forEach((n) => col(n).addEventListener('input', () => { pal.value = 'perso'; send(); }));
  if (pal.value !== 'perso') applyPalette();
  form.addEventListener('input', send);
  form.addEventListener('change', send);
  const usePhoto = () => {
    const f = photo.files && photo.files[0];
    if (f) { if (photoUrl && photoUrl.startsWith('blob:')) URL.revokeObjectURL(photoUrl); photoUrl = URL.createObjectURL(f); }
    send();
  };
  photo.addEventListener('change', usePhoto);
  photo.addEventListener('pm-prepared', usePhoto);
  form.querySelector('[data-pub-replay]').addEventListener('click', () => { if (ready) frame.contentWindow.postMessage({ type: 'pm-text-preview', item: item() }, location.origin); });
})();
