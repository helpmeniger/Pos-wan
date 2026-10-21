/* Plan de salle : rendu des tables, rafraîchissement automatique, ouverture de commande. */
(function () {
  'use strict';
  const { $, $$, BASE, CSRF } = window.PW;
  let data = JSON.parse($('#floor-data').textContent);
  let area = Number(localStorage.getItem('pw-area') || 0);
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const fmt = (n) => PWMoney.fmt(n);
  const since = (d) => { const m = Math.max(0, Math.round((Date.parse(data.now.replace(' ', 'T')) - Date.parse(String(d).replace(' ', 'T'))) / 60000)); return m < 60 ? m + ' min' : Math.floor(m / 60) + ' h ' + (m % 60) + ' min'; };
  const modal = () => bootstrap.Modal.getOrCreateInstance($('#openModal'));

  function render() {
    $$('#areaTabs [data-area]').forEach((b) => { const on = Number(b.dataset.area) === area; b.classList.toggle('active', on); b.classList.toggle('btn-dark', on); b.classList.toggle('btn-outline-dark', !on); });
    $('#floorGrid').innerHTML = data.tables.filter((t) => !area || t.area === area).map((t) => {
      const st = t.order ? (t.status === 'billed' ? 'billed' : 'busy') : 'free';
      const badges = (t.ready ? `<span class="t-badge ready" title="Plats prêts"><i class="bi bi-bell-fill"></i> ${t.ready}</span>` : '') + (t.unsent ? `<span class="t-badge unsent" title="Lignes non envoyées">${t.unsent}</span>` : '');
      const body = t.order
        ? `<div class="t-meta"><i class="bi bi-people"></i> ${t.covers} · ${esc(t.waiter)}<br><i class="bi bi-clock"></i> ${since(t.opened)}</div><div class="t-due">${fmt(t.due)}</div>`
        : `<div class="t-meta"><i class="bi bi-people"></i> ${t.seats} places</div><div class="t-meta">Libre</div>`;
      return `<button class="r-table ${st} ${t.shape}" data-table="${t.id}" data-order="${t.order || ''}"><div class="t-badges">${badges}</div><div class="t-name">${esc(t.name)}</div>${body}</button>`;
    }).join('');
    $('#othersGrid').innerHTML = data.others.map((o) => `<button class="r-table ${o.status === 'billed' ? 'billed' : 'busy'}" data-order="${o.id}"><div class="t-badges">${o.ready ? `<span class="t-badge ready"><i class="bi bi-bell-fill"></i> ${o.ready}</span>` : ''}</div>
      <div class="t-name" style="font-size:1.1rem"><i class="bi ${o.type === 'delivery' ? 'bi-scooter' : 'bi-bag'}"></i> ${esc(o.number)}</div>
      <div class="t-meta">${esc(o.name || '')} ${esc(o.phone || '')}<br><i class="bi bi-clock"></i> ${since(o.opened)}</div><div class="t-due">${fmt(o.due)}</div></button>`).join('') || '<p class="text-secondary small">Aucune.</p>';
  }
  // Plats prêts : signalés aux modules (ex. annonce vocale aux serveurs) quand leur nombre augmente
  const readyKey = (o) => 'o' + o;
  function readyMap(d) {
    const m = {};
    d.tables.forEach((t) => { if (t.order) m[readyKey(t.order)] = { n: t.ready || 0, table: t.name }; });
    d.others.forEach((o) => { m[readyKey(o.id)] = { n: o.ready || 0, order_number: o.number, order_type: o.type, customer: o.name || '' }; });
    return m;
  }
  let lastReady = readyMap(data);
  function announceReady(d) {
    const now = readyMap(d);
    Object.keys(now).forEach((k) => {
      const before = lastReady[k] ? lastReady[k].n : 0;
      if (now[k].n > before) {
        try { document.dispatchEvent(new CustomEvent('pm:event', { detail: { event: 'restaurant.table.ready', data: Object.assign({ key: 'ready-' + k + '-' + now[k].n }, now[k]) } })); } catch (e) { /* ignoré */ }
      }
    });
    lastReady = now;
  }
  async function refresh() {
    try { const r = await fetch(BASE + '/restaurant/floor', { headers: { Accept: 'application/json' } }); if (r.status === 401) { location.href = BASE + '/login'; return; } const d = await r.json(); if (d.ok) { data = d; announceReady(d); render(); } } catch (e) { /* réseau */ }
  }
  $('#areaTabs').addEventListener('click', (e) => { const b = e.target.closest('[data-area]'); if (b) { area = Number(b.dataset.area); localStorage.setItem('pw-area', area); render(); } });
  function openModal(type, table) {
    $('#openType').value = type; $('#openTable').value = table || '';
    $('#coversBox').classList.toggle('d-none', type !== 'dine_in');
    $('#custBox').classList.toggle('d-none', type === 'dine_in');
    $('#openAddress').classList.toggle('d-none', type !== 'delivery');
    $('#openTitle').textContent = type === 'dine_in' ? 'Table ' + data.tables.find((t) => t.id === table).name : (type === 'delivery' ? 'Livraison' : 'À emporter');
    $('#openError').classList.add('d-none');
    modal().show();
  }
  document.addEventListener('click', (e) => {
    const t = e.target.closest('.r-table');
    if (t) { if (t.dataset.order) location.href = BASE + '/restaurant/orders/' + t.dataset.order; else openModal('dine_in', Number(t.dataset.table)); return; }
    const n = e.target.closest('[data-new]'); if (n) openModal(n.dataset.new, null);
    const c = e.target.closest('.cover-key'); if (c) { $('#openCovers').value = c.dataset.c; $('#openGo').click(); }
  });
  $('#openGo').addEventListener('click', async () => {
    const body = { _csrf: CSRF, type: $('#openType').value, table_id: $('#openTable').value || null, covers: $('#openCovers').value, customer_name: $('#openName').value, customer_phone: $('#openPhone').value, address: $('#openAddress').value };
    const r = await fetch(BASE + '/restaurant/orders', { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': CSRF }, body: JSON.stringify(body) });
    const d = await r.json().catch(() => ({ ok: false, error: 'Erreur serveur.' }));
    if (d.ok) { location.href = d.url; return; }
    $('#openError').textContent = d.error; $('#openError').classList.remove('d-none'); refresh();
  });
  render();
  setInterval(refresh, 10000);
})();
