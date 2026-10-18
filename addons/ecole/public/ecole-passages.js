/*
 * POS Marwa — module Établissement : vérification des passages devant les caméras
 * (attribuer un visage inconnu ou douteux à la bonne personne, ou l'ignorer).
 */
(function () {
  'use strict';
  const meta = (n) => { const m = document.querySelector(`meta[name="${n}"]`); return m ? m.content : ''; };
  const BASE = meta('base-url').replace(/\/$/, '');
  const CSRF = () => meta('csrf-token');
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const root = document.getElementById('ecPassages');
  if (!root) return;

  async function post(id, data) {
    const fd = new FormData(); fd.append('_csrf', CSRF());
    Object.entries(data).forEach(([k, v]) => fd.append(k, v));
    const r = await fetch(BASE + '/ecole/passages/' + id, { method: 'POST', body: fd, headers: { Accept: 'application/json', 'X-CSRF-Token': CSRF() }, credentials: 'same-origin' });
    const j = await r.json().catch(() => ({ ok: false, error: 'Erreur ' + r.status }));
    if (!j.ok) throw new Error(j.error || 'Erreur');
    return j;
  }
  const LIB = { confirme: ['Confirmé', 'bg-primary'], corrige: ['Corrigé', 'bg-info text-dark'], ignore: ['Ignoré', 'bg-secondary'] };
  function statut(card, st, nom) {
    const b = card.querySelector('[data-badge]'); const [l, c] = LIB[st] || [st, 'bg-secondary'];
    b.className = 'badge ' + c; b.textContent = l;
    if (nom) { let n = card.querySelector('[data-nom]'); if (!n) { n = document.createElement('div'); n.className = 'fw-bold'; n.dataset.nom = ''; card.querySelector('[data-qui]').parentNode.before(n); } n.textContent = nom; }
    const ig = card.querySelector('[data-ignorer]'); if (ig) ig.remove();
    if (st === 'ignore') { const q = card.querySelector('[data-qui]'); if (q) q.parentNode.remove(); }
  }

  let t = null;
  root.addEventListener('input', (e) => {
    const q = e.target.closest('[data-qui]'); if (!q) return;
    const box = q.parentNode.querySelector('[data-sugg]');
    clearTimeout(t);
    t = setTimeout(async () => {
      if (q.value.trim().length < 2) { box.innerHTML = ''; return; }
      const r = await fetch(BASE + '/ecole/eleves/recherche?personnel=1&q=' + encodeURIComponent(q.value.trim()), { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
      const j = await r.json().catch(() => ({ items: [] }));
      box.innerHTML = (j.items || []).slice(0, 8).map((x) => `<button type="button" class="list-group-item list-group-item-action py-1" data-type="${x.type || 'eleve'}" data-id="${x.id}" data-n="${esc(x.nom)}">${esc(x.nom)} <span class="text-secondary">${esc(x.matricule)} · ${esc(x.classe || '')}</span></button>`).join('');
    }, 250);
  });
  root.addEventListener('click', async (e) => {
    const card = e.target.closest('[data-passage]'); if (!card) return;
    const err = card.querySelector('[data-err]'); err.textContent = '';
    const s = e.target.closest('[data-sugg] [data-id]');
    try {
      if (s) {
        const j = await post(card.dataset.passage, { action: 'attribuer', type: s.dataset.type, id: s.dataset.id });
        s.parentNode.innerHTML = ''; card.querySelector('[data-qui]').value = '';
        statut(card, j.statut, s.dataset.n);
      } else if (e.target.closest('[data-ignorer]')) {
        const j = await post(card.dataset.passage, { action: 'ignorer' });
        statut(card, j.statut);
      }
    } catch (x) { err.textContent = x.message; }
  });
})();
