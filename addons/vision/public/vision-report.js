/* Rapport passages / ventes : revue des passages (confirmer, corriger et apprendre, ignorer). */
(function () {
  'use strict';
  const table = document.getElementById('revTable');
  if (!table || !window.PMVision) return;
  const V = window.PMVision;
  const T = (s) => (window.__ ? __(s) : s);
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const LABELS = { confirme: T('Confirmé'), corrige: T('Corrigé'), ignore: T('Ignoré') };

  async function send(tr, action, productId) {
    const d = await V.api('/vision/events/' + tr.dataset.ev, { action, product_id: productId || null });
    if (!d.ok) { alert(d.error); return false; }
    tr.querySelector('[data-status]').textContent = LABELS[d.status] || d.status;
    tr.classList.add('table-light');
    tr.querySelectorAll('[data-act]').forEach((b) => { b.disabled = true; });
    return true;
  }
  /** La vignette du passage est apprise pour l'article choisi (la reconnaissance s'améliore). */
  async function learn(tr, productId) {
    const img = tr.querySelector('.rep-thumb');
    if (!img) return;
    try {
      const e = await V.embed(img, { x: 0, y: 0, s: img.naturalWidth });
      await V.api('/vision/refs', { product_id: productId, vec: V.b64(e.q), thumb: img.src, source: 'surveillance' });
    } catch (err) { /* apprentissage facultatif */ }
  }

  table.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-act]'); const tr = e.target.closest('tr[data-ev]');
    if (b && tr) {
      if (b.dataset.act === 'confirm' || b.dataset.act === 'ignore') { await send(tr, b.dataset.act); return; }
      if (tr.nextElementSibling && tr.nextElementSibling.classList.contains('rev-pick')) { tr.nextElementSibling.remove(); return; }
      const row = document.createElement('tr'); row.className = 'rev-pick';
      row.innerHTML = `<td colspan="7"><div class="d-flex flex-wrap gap-2 align-items-center"><input type="search" class="form-control form-control-sm" style="max-width:280px" placeholder="${esc(T('Article : nom, référence, code-barres'))}" data-q>
        <label class="small"><input type="checkbox" data-learn checked> ${esc(T('apprendre cette vignette'))}</label><div class="d-flex flex-wrap gap-1" data-found></div></div></td>`;
      tr.after(row); row.querySelector('[data-q]').focus();
      let t = null;
      row.querySelector('[data-q]').addEventListener('input', (ev) => {
        clearTimeout(t); const q = ev.target.value.trim();
        t = setTimeout(async () => {
          if (q.length < 2) return;
          const d = await V.api('/vision/search?q=' + encodeURIComponent(q));
          row.querySelector('[data-found]').innerHTML = (d.items || []).map((it) => `<button type="button" class="btn btn-sm btn-outline-primary" data-pid="${it.id}">${esc(it.name)}</button>`).join('') || `<span class="small text-secondary">${esc(T('Aucun article.'))}</span>`;
        }, 250);
      });
      row.addEventListener('click', async (ev) => {
        const p = ev.target.closest('[data-pid]'); if (!p) return;
        const pid = Number(p.dataset.pid);
        if (await send(tr, 'correct', pid)) {
          const name = p.textContent;
          if (row.querySelector('[data-learn]').checked) { row.querySelector('[data-found]').textContent = T('Apprentissage…'); await V.loadEngine().catch(() => {}); await learn(tr, pid); }
          tr.children[4].textContent = name; row.remove();
        }
      });
    }
  });
})();
