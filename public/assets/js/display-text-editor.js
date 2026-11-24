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

/* Saisie du menu : articles déjà créés proposés pendant la frappe ; un clic écrit « Nom prix » sur la ligne en cours. */
(function () {
  'use strict';
  const ta = document.querySelector('[data-pub-body]');
  const box = document.querySelector('[data-pub-suggest]');
  if (!ta || !box) return;
  let items = [], sel = -1, timer = null, seq = 0;
  const lineAt = () => {
    const v = ta.value, pos = ta.selectionStart;
    const start = v.lastIndexOf('\n', pos - 1) + 1;
    let end = v.indexOf('\n', pos);
    if (end < 0) end = v.length;
    return { start, end, text: v.slice(start, end) };
  };
  const close = () => { box.classList.add('d-none'); box.innerHTML = ''; items = []; sel = -1; };
  const show = () => {
    box.innerHTML = '';
    items.forEach((it, i) => {
      const b = document.createElement('button');
      b.type = 'button';
      b.className = 'list-group-item list-group-item-action d-flex justify-content-between gap-3 py-1' + (i === sel ? ' active' : '');
      b.setAttribute('role', 'option');
      const n = document.createElement('span'); n.textContent = it.name;
      const p = document.createElement('b'); p.textContent = it.price_text;
      b.append(n, p);
      b.addEventListener('mousedown', (e) => { e.preventDefault(); pick(i); });
      box.appendChild(b);
    });
    box.classList.toggle('d-none', items.length === 0);
  };
  function pick(i) {
    const it = items[i];
    if (!it) return;
    const l = lineAt();
    const bullet = (l.text.match(/^\s*[-•*·]\s+/) || [''])[0]; // une puce écrite avant le nom est gardée
    const line = bullet + it.name + ' ' + it.price_text;
    const v = ta.value;
    const after = v.slice(l.end);
    ta.value = v.slice(0, l.start) + line + (after.startsWith('\n') ? after : '\n' + after);
    const caret = l.start + line.length + 1;
    ta.setSelectionRange(caret, caret);
    close();
    ta.focus();
    ta.dispatchEvent(new Event('input', { bubbles: true })); // aperçu mis à jour
  }
  function lookup() {
    const l = lineAt();
    const q = l.text.replace(/^\s*[-•*·]\s+/, '').trim();
    // Rien à proposer : ligne vide ou trop courte, rubrique (#), ligne qui a déjà son prix
    if (q.length < 2 || q.startsWith('#') || (window.PMTextSlide && window.PMTextSlide.parse(q)[0]?.t === 'item')) { close(); return; }
    const n = ++seq;
    fetch(ta.dataset.articlesUrl + '?q=' + encodeURIComponent(q), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then((r) => (r.ok ? r.json() : { items: [] }))
      .then((d) => { if (n !== seq) return; items = d.items || []; sel = items.length ? 0 : -1; show(); })
      .catch(() => close());
  }
  ta.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(lookup, 200); });
  ta.addEventListener('keydown', (e) => {
    if (box.classList.contains('d-none')) return;
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); sel = (sel + (e.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length; show(); }
    else if (e.key === 'Enter' || e.key === 'Tab') { if (sel >= 0) { e.preventDefault(); pick(sel); } }
    else if (e.key === 'Escape') { close(); }
  });
  ta.addEventListener('blur', () => setTimeout(close, 150));
  ta.addEventListener('click', close);
})();
