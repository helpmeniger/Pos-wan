/* Inventaire : comptage par scan (douchette ou caméra) et saisie manuelle enregistrée immédiatement. */
(function () {
  'use strict';
  const box = document.querySelector('.inv-scan');
  const form = document.getElementById('invForm');
  if (!form) return;
  const T = (s) => (window.__ ? __(s) : s);
  const BASE = (window.PW && PW.BASE) || '';
  const CSRF = document.querySelector('meta[name="csrf-token"]').content;
  const invId = box ? box.dataset.inv : (form.action.match(/inventories\/(\d+)/) || [])[1];
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const fq = (n) => (n === null || n === undefined ? '—' : (Math.round(n * 1000) / 1000).toLocaleString('fr-FR'));

  async function post(path, body) {
    const r = await fetch(BASE + path, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': CSRF }, body: JSON.stringify(Object.assign({ _csrf: CSRF }, body)) });
    if (r.status === 401) { location.href = BASE + '/login'; return { ok: false }; }
    return r.json().catch(() => ({ ok: false, error: T('Réponse invalide du serveur.') }));
  }
  function beep(ok) {
    try { const c = beep.c || (beep.c = new (window.AudioContext || window.webkitAudioContext)()); const o = c.createOscillator(); const g = c.createGain(); o.frequency.value = ok ? 1250 : 260; g.gain.value = 0.07; o.connect(g); g.connect(c.destination); o.start(); o.stop(c.currentTime + (ok ? 0.07 : 0.25)); } catch (e) { /* */ }
  }
  /** Met à jour la ligne du tableau (si elle est affichée). */
  function paint(info) {
    const tr = form.querySelector(`tr[data-item="${info.item_id}"]`);
    if (!tr) return false;
    const inp = tr.querySelector('[data-count]');
    if (inp && document.activeElement !== inp) inp.value = info.counted === null ? '' : info.counted;
    const d = info.diff;
    tr.querySelector('[data-diff]').textContent = d === null ? '' : (d > 0 ? '+' : '') + fq(d);
    tr.classList.remove('inv-ok', 'inv-plus', 'inv-minus');
    if (d !== null) tr.classList.add(Math.abs(d) < 0.0005 ? 'inv-ok' : d > 0 ? 'inv-plus' : 'inv-minus');
    tr.classList.add('inv-flash'); setTimeout(() => tr.classList.remove('inv-flash'), 700);
    return true;
  }
  const last = document.getElementById('invLast');
  function pushLast(info, ok, text) {
    if (!last) return;
    const row = document.createElement('div');
    row.className = 'inv-last-row ' + (ok ? 'ok' : 'bad');
    row.innerHTML = ok ? `<b>${esc(info.name)}</b> <span class="text-secondary">${esc(info.barcode || info.reference || '')}</span><span class="ms-auto">${T('compté')} <b>${fq(info.counted)}</b> / ${T('théorique')} ${fq(info.expected)}</span>` : `<span>${esc(text)}</span>`;
    last.insertBefore(row, last.children[1] || null);
    while (last.children.length > 9) last.removeChild(last.lastChild);
  }
  async function scan(code) {
    const d = await post(`/inventories/${invId}/scan`, { code });
    beep(!!d.ok);
    if (!d.ok) { pushLast(null, false, d.error || T('Erreur')); return { ok: false, text: d.error }; }
    const shown = paint(d);
    pushLast(d, true);
    return { ok: true, html: `<b>${esc(d.name)}</b> · ${T('compté')} <b>${fq(d.counted)}</b> / ${T('théorique')} ${fq(d.expected)}${shown ? '' : ' · ' + T('(hors filtre affiché)')}` };
  }

  // Douchette USB / sans fil : elle « tape » le code puis Entrée
  const input = document.getElementById('invScanInput');
  if (input) {
    input.focus();
    input.addEventListener('keydown', (e) => {
      if (e.key !== 'Enter') return;
      e.preventDefault();
      const v = input.value.trim(); input.value = '';
      if (v) scan(v);
    });
  }
  const cam = document.getElementById('invCamera');
  if (cam && window.PMScanner) cam.addEventListener('click', () => PMScanner.open({ title: T('Inventaire : scanner les articles'), onCode: scan }));

  // Saisie manuelle : enregistrement immédiat (et formulaire « Enregistrer » en secours)
  const timers = {};
  function saveRow(tr, extra = {}) {
    const id = tr.dataset.item;
    clearTimeout(timers[id]);
    timers[id] = setTimeout(async () => {
      const inp = tr.querySelector('[data-count]');
      const note = tr.querySelector('[data-note]');
      const d = await post(`/inventories/${invId}/count`, Object.assign({ item_id: id, qty: inp ? inp.value : '', note: note ? note.value : null }, extra));
      if (!d.ok) { alert(d.error || T('Erreur')); return; }
      paint(d);
      const c = tr.querySelector('[data-counter]'); if (c) c.textContent = T('vous');
    }, extra.reset ? 0 : 500);
  }
  form.addEventListener('change', (e) => { const tr = e.target.closest('tr[data-item]'); if (tr && (e.target.matches('[data-count]') || e.target.matches('[data-note]'))) saveRow(tr); });
  form.addEventListener('click', (e) => {
    const tr = e.target.closest('tr[data-item]');
    if (!tr) return;
    if (e.target.closest('[data-zero]')) { tr.querySelector('[data-count]').value = 0; saveRow(tr); }
    if (e.target.closest('[data-reset]') && confirm(T('Recommencer le comptage de cet article ? La quantité comptée est effacée.'))) { tr.querySelector('[data-count]').value = ''; saveRow(tr, { reset: true }); }
  });
  // Entrée dans une case de quantité : passer à la ligne suivante au lieu de soumettre
  form.addEventListener('keydown', (e) => {
    if (e.key !== 'Enter' || !e.target.matches('[data-count]')) return;
    e.preventDefault();
    const all = Array.from(form.querySelectorAll('[data-count]'));
    const next = all[all.indexOf(e.target) + 1];
    e.target.dispatchEvent(new Event('change', { bubbles: true }));
    if (next) { next.focus(); next.select(); }
  });
})();
