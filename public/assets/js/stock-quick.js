/* POS Marwa — ajout de stock rapide : recherche / scan, stock par entrepôt, quantité, Entrée. */
(function () {
  'use strict';
  const { $, BASE, CSRF } = window.PW;
  const search = $('#qsSearch');
  if (!search) return;
  const results = $('#qsResults');
  const card = $('#qsCard');
  const form = $('#qsForm');
  const qty = $('#qsQty');
  const msg = $('#qsMsg');
  const fmt = (n) => Number(n).toLocaleString('fr-FR', { maximumFractionDigits: 3 });
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  let product = null;
  let t;

  function say(text, ok) {
    msg.className = 'alert mb-0 py-2 ' + (ok ? 'alert-success' : 'alert-danger');
    msg.textContent = text;
  }

  function preview() {
    if (!product) return;
    const lv = product.levels.find((l) => String(l.id) === $('#qsWh').value);
    const q = Number(String(qty.value).replace(',', '.')) || 0;
    $('#qsPreview').textContent = lv ? `${lv.name} : ${fmt(lv.quantity)} → ${fmt(Number(lv.quantity) + q)} ${product.unit || ''}` : '';
  }

  function render(p, keepMsg) {
    product = p;
    card.classList.remove('d-none');
    $('#qsName').textContent = p.name;
    $('#qsRef').textContent = [p.reference, p.barcode].filter(Boolean).join(' · ');
    $('#qsTotal').textContent = fmt(p.total) + (p.unit ? ' ' + p.unit : '');
    $('#qsUnit').textContent = p.unit ? '(' + p.unit + ')' : '';
    $('#qsLevels').innerHTML = p.levels.map((l) => `<tr data-wh="${l.id}"><td>${esc(l.name)}</td><td class="text-end fw-semibold">${fmt(l.quantity)}</td></tr>`).join('');
    qty.step = Number(p.decimal_ok) ? 'any' : '1';
    document.querySelectorAll('.qs-lot').forEach((e) => e.classList.toggle('d-none', Number(p.track_lots) !== 1));
    form.querySelectorAll('.qs-lot input').forEach((i) => { i.required = Number(p.track_lots) === 1 && i.name === 'lot'; });
    if (!keepMsg) msg.classList.add('d-none');
    preview();
    qty.focus();
  }

  async function open(id) {
    results.innerHTML = '';
    const r = await fetch(`${BASE}/stock/quick/product/${id}`, { headers: { Accept: 'application/json' } });
    const d = await r.json();
    if (!d.ok) { say(d.error || 'Article introuvable.', false); msg.classList.remove('d-none'); return; }
    render(d.product);
  }

  search.addEventListener('input', () => {
    clearTimeout(t);
    t = setTimeout(async () => {
      const q = search.value.trim();
      if (!q) { results.innerHTML = ''; return; }
      const r = await fetch(`${BASE}/api/products?q=${encodeURIComponent(q)}`, { headers: { Accept: 'application/json' } });
      const d = await r.json();
      const items = d.items || [];
      results.innerHTML = items.slice(0, 12).map((p) => `<button type="button" class="list-group-item list-group-item-action" data-id="${p.id}"><b>${esc(p.name)}</b> <span class="small text-secondary">${esc(p.reference || '')}${p.barcode ? ' · ' + esc(p.barcode) : ''}</span></button>`).join('')
        || '<div class="list-group-item small text-secondary">Aucun article.</div>';
      // Code-barres scanné : un seul résultat → ouvert directement
      if (items.length === 1 && /^\d{6,}$/.test(q)) open(items[0].id);
    }, 200);
  });
  search.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') { e.preventDefault(); const first = results.querySelector('[data-id]'); if (first) open(first.dataset.id); }
  });
  results.addEventListener('click', (e) => { const b = e.target.closest('[data-id]'); if (b) open(b.dataset.id); });
  qty.addEventListener('input', preview);
  $('#qsWh').addEventListener('change', preview);

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (!product) return;
    const btn = form.querySelector('button');
    btn.disabled = true;
    const body = Object.fromEntries(new FormData(form).entries());
    body.product_id = product.id;
    try {
      const r = await fetch(`${BASE}/stock/quick`, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': CSRF }, body: JSON.stringify(body) });
      const d = await r.json();
      msg.classList.remove('d-none');
      if (!d.ok) { say(d.error || 'Ajout refusé.', false); return; }
      const added = Number(d.after) - Number(d.before);
      say(`+${fmt(added)} ${product.unit || ''} ajouté(s) à « ${d.product.name} » (${d.warehouse}) : stock ${fmt(d.before)} → ${fmt(d.after)}. Enregistré au nom de ${d.user}.`, true);
      const tb = $('#qsSession');
      const empty = tb.querySelector('.qs-empty'); if (empty) empty.remove();
      tb.insertAdjacentHTML('afterbegin', `<tr><td class="small">${esc(d.at.split(' ')[1])}</td><td>${esc(d.product.name)}</td><td class="small">${esc(d.warehouse)}</td><td class="text-end text-success fw-semibold">+${fmt(added)}</td><td class="text-end">${fmt(d.after)}</td></tr>`);
      render(Object.assign(product, { total: d.product.total, levels: d.product.levels }), true);
      form.reset();
      $('#qsWh').value = body.warehouse_id;
      // Article suivant : retour à la recherche (le scanner peut enchaîner)
      search.value = '';
      search.focus();
    } catch (err) {
      msg.classList.remove('d-none');
      say('Connexion au serveur impossible : l\'ajout n\'a pas été enregistré.', false);
    } finally { btn.disabled = false; }
  });

  const preset = $('#qsPreset');
  if (preset) open(preset.dataset.id);
})();
