/* =====================================================================
   POS Wan — prise de commande restaurant.
   L'état de la commande vit sur le serveur : chaque action renvoie la
   commande à jour (plusieurs serveurs peuvent travailler sur la même table).
   ===================================================================== */
(function () {
  'use strict';
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
  const CFG = JSON.parse($('#rest-config').textContent);
  const BASE = ($('meta[name="base-url"]').content || '/').replace(/\/$/, '');
  const fmt = (n) => PWMoney.fmt(n);
  const plain = (n) => PWMoney.plain(n);
  const fmtQ = (q) => (Math.round(q * 1000) / 1000).toLocaleString('fr-FR');
  const num = (v) => { const n = parseFloat(String(v ?? '').replace(/\s/g, '').replace(',', '.')); return isFinite(n) ? n : 0; };
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const modal = (id) => bootstrap.Modal.getOrCreateInstance(document.getElementById(id));
  const ST = { new: __('à envoyer'), sent: __('envoyé'), preparing: __('en préparation'), ready: __('PRÊT'), served: __('servi'), cancelled: __('annulé') };

  let order = CFG.order;
  let selected = null;
  let pendingQty = 1;
  let course = null; // null = automatique (déduit du poste de préparation)
  let busy = false;

  function toast(msg, type = 'dark') {
    const el = document.createElement('div');
    el.className = `toast align-items-center text-bg-${type} border-0 show mb-2`;
    el.innerHTML = `<div class="d-flex"><div class="toast-body">${esc(msg)}</div></div>`;
    $('#toasts').appendChild(el);
    setTimeout(() => el.remove(), type === 'danger' ? 6000 : 2500);
  }
  async function api(url, body = null) {
    const res = await fetch(BASE + url, { method: body ? 'POST' : 'GET', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': CFG.csrf }, body: body ? JSON.stringify(Object.assign({ _csrf: CFG.csrf }, body)) : undefined });
    if (res.status === 401) { location.href = BASE + '/login'; throw new Error('session'); }
    const d = await res.json().catch(() => ({ ok: false, error: __('Réponse invalide du serveur.') }));
    if (d.order) { order = d.order; render(); }
    if (!d.ok && d.error) toast(d.error, 'danger');
    return d;
  }
  function printFeedback(d) { (d.print_errors || []).forEach((m) => toast(m, 'warning')); }
  function printUrls(urls) {
    (urls || []).forEach((u, i) => setTimeout(() => {
      const f = document.createElement('iframe'); f.style.cssText = 'position:fixed;width:0;height:0;border:0'; f.src = u; document.body.appendChild(f);
      setTimeout(() => f.remove(), 60000);
    }, i * 1500));
  }

  // ------------------------------------------------------------------ rendu
  function render() {
    if (!['open', 'billed'].includes(order.status)) { location.reload(); return; }
    $('#orderTitle').textContent = (order.table ? 'Table ' + order.table : (order.type === 'delivery' ? 'Livraison' : 'À emporter')) + ' · ' + order.number;
    $('#orderMeta').innerHTML = `<i class="bi bi-people"></i> ${order.covers} couvert(s) · <i class="bi bi-person"></i> ${esc(order.waiter)}` + (order.status === 'billed' ? ' · <span class="text-warning">addition demandée</span>' : '');
    $('#totalOut').textContent = fmt(order.due);
    $('#customerBadge').innerHTML = (order.customer ? `<i class="bi bi-person-circle"></i> ${esc(order.customer)} ${esc(order.customer_phone || '')}` : '') + (order.address ? ` · ${esc(order.address)}` : '') + (order.note ? ` · <i class="bi bi-chat-left-text"></i> ${esc(order.note)}` : '');
    const items = order.items;
    if (!items.length) {
      $('#cart').innerHTML = '<div class="cart-empty"><i class="bi bi-egg-fried fs-1 d-block mb-2"></i>Touchez les plats et boissons pour les ajouter</div>';
    } else {
      let last = null;
      $('#cart').innerHTML = items.map((i) => {
        const sep = i.course !== last ? `<div class="course-sep">${esc(CFG.courses[i.course] || '')}</div>` : '';
        last = i.course;
        const paid = i.paid >= i.qty - 0.0005 && i.status !== 'cancelled';
        const sub = [i.note ? '📝 ' + i.note : '', i.reason ? 'motif : ' + i.reason : '', i.paid > 0 && !paid ? 'payé : ' + fmtQ(i.paid) : ''].filter(Boolean).join(' · ');
        return sep + `<div class="cart-line ${i.id === selected ? 'sel' : ''} ${i.status === 'cancelled' ? 'cancelled' : ''}" data-id="${i.id}">
          <span class="ln-name">${esc(i.name)}<span class="st st-${paid ? 'paid' : i.status}">${paid ? 'payé' : ST[i.status]}</span></span>
          <span>${fmtQ(i.qty)}</span><span>${plain(i.price)}</span><span>${plain(i.qty * i.price)}</span>
          ${sub ? `<span class="ln-sub">${esc(sub)}</span>` : ''}</div>`;
      }).join('');
      const sel = $('.cart-line.sel'); if (sel) sel.scrollIntoView({ block: 'nearest' });
    }
    const unsent = items.filter((i) => i.status === 'new').length;
    const ready = items.filter((i) => i.status === 'ready').length;
    $('#summary').innerHTML = `<div><span>Total commande</span><span>${fmt(order.total)}</span></div>` + (order.paid > 0 ? `<div><span>Déjà payé</span><span>${fmt(order.paid)}</span></div>` : '') +
      (ready ? `<div class="text-warning"><span><i class="bi bi-bell-fill"></i> ${ready} article(s) prêt(s) à servir</span></div>` : '');
    $('.btn-send').disabled = !unsent;
    $('.btn-send').innerHTML = `<i class="bi bi-fire"></i> Envoyer en cuisine${unsent ? ' (' + unsent + ')' : ''}`;
    const pay = $('#payBtn'); if (pay) pay.classList.toggle('disabled', order.due <= 0);
  }

  // ------------------------------------------------------------------ articles
  let items = [], currentCat = 0, timer = null;
  async function loadProducts() {
    const q = $('#productSearch').value.trim();
    const r = await fetch(`${BASE}/restaurant/products?category=${currentCat}&q=${encodeURIComponent(q)}`, { headers: { Accept: 'application/json' } });
    const d = await r.json().catch(() => ({ items: [] }));
    items = d.items || [];
    $('#productGrid').innerHTML = items.map((p, i) => {
      const initials = esc(p.name.split(/\s+/).filter((w) => /^[\p{L}\d]/u.test(w)).slice(0, 2).map((w) => w[0]).join('').toUpperCase());
      return `<div class="p-card ${p.track && p.stock <= 0 ? 'out' : ''}" data-i="${i}" role="button" tabindex="0">
        <div class="p-img" style="background:${p.image ? '#fff' : esc(p.color)}">${p.image ? `<img src="${esc(p.image)}" alt="">` : initials}</div>
        <div class="p-name">${esc(p.name)}</div><div class="p-price">${fmt(p.price)}</div></div>`;
    }).join('') || '<div class="empty-msg">Aucun article</div>';
  }
  $('#productGrid').addEventListener('click', async (e) => {
    const c = e.target.closest('.p-card'); if (!c || busy) return;
    const p = items[Number(c.dataset.i)];
    busy = true;
    const q = pendingQty; setQty(1);
    const d = await api(`/restaurant/orders/${CFG.orderId}/items`, { product_id: p.id, quantity: q, course });
    busy = false;
    if (d.ok) { const last = order.items.filter((i) => i.status === 'new').pop(); selected = last ? last.id : null; render(); }
  });
  $('#catBar').addEventListener('click', (e) => { const t = e.target.closest('.cat-tile'); if (!t) return; $$('.cat-tile').forEach((x) => x.classList.toggle('active', x === t)); currentCat = Number(t.dataset.cat); loadProducts(); });
  $('#productSearch').addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(loadProducts, 250); });
  $('#courseSel').addEventListener('click', (e) => { const b = e.target.closest('[data-course]'); if (!b) return; course = b.dataset.course === '' ? null : Number(b.dataset.course); $$('#courseSel .btn').forEach((x) => x.classList.toggle('active', x === b)); });
  function setQty(q) { pendingQty = q; $$('.qty-key').forEach((b) => b.classList.toggle('on', Number(b.dataset.qty) === q && q > 1)); }
  $$('.qty-key').forEach((b) => b.addEventListener('click', () => setQty(pendingQty === Number(b.dataset.qty) ? 1 : Number(b.dataset.qty))));
  $('#cart').addEventListener('click', (e) => { const l = e.target.closest('.cart-line'); if (l) { selected = Number(l.dataset.id); render(); } });

  const sel = () => order.items.find((i) => i.id === selected);
  function needSel() { const i = sel(); if (!i) toast(__('Sélectionnez une ligne.'), 'warning'); return i; }

  // ------------------------------------------------------------------ saisies
  let numCb = null;
  function askNumber(title, value, cb) {
    $('#numTitle').textContent = title; $('#numInput').value = value; numCb = cb; modal('numModal').show();
    setTimeout(() => { $('#numInput').focus(); $('#numInput').select(); }, 300);
  }
  $('#numKeypad').addEventListener('click', (e) => { const k = e.target.closest('[data-k]'); if (!k) return; const inp = $('#numInput'); inp.value = k.dataset.k === '⌫' ? inp.value.slice(0, -1) : inp.value + k.dataset.k; });
  $('#numOk').addEventListener('click', () => { modal('numModal').hide(); if (numCb) numCb(num($('#numInput').value)); });
  $('#numInput').addEventListener('keydown', (e) => { if (e.key === 'Enter') $('#numOk').click(); });
  $('#noteChips').addEventListener('click', (e) => { const b = e.target.closest('button'); if (!b) return; const v = $('#noteInput').value.trim(); $('#noteInput').value = v ? v + ', ' + b.textContent : b.textContent; });
  $('#noteClear').addEventListener('click', () => { $('#noteInput').value = ''; });
  $('#noteOk').addEventListener('click', async () => {
    const i = sel(); modal('noteModal').hide(); if (!i) return;
    await api(`/restaurant/items/${i.id}`, { quantity: i.qty, note: $('#noteInput').value });
  });

  function openCancel(i) {
    $('#cancelName').textContent = `${fmtQ(i.qty - i.paid)} × ${i.name} (${ST[i.status]})`;
    $('#cancelQty').value = i.qty - i.paid; $('#cancelReason').value = ''; $('#cancelWaste').checked = ['preparing', 'ready', 'served'].includes(i.status);
    $('#cancelError').classList.add('d-none'); modal('cancelModal').show();
  }
  $('#cancelOk').addEventListener('click', async () => {
    const i = sel(); if (!i) return;
    const body = { quantity: num($('#cancelQty').value), reason: $('#cancelReason').value, waste: $('#cancelWaste').checked ? 1 : 0 };
    if ($('#supUser')) body.supervisor = { username: $('#supUser').value, secret: $('#supSecret').value };
    const d = await api(`/restaurant/items/${i.id}/cancel`, body);
    if ($('#supSecret')) $('#supSecret').value = '';
    if (!d.ok) { $('#cancelError').textContent = d.error; $('#cancelError').classList.remove('d-none'); return; }
    modal('cancelModal').hide(); selected = null; render(); printUrls(d.print); printFeedback(d); toast(__('Article annulé — la cuisine est prévenue.'), 'success');
  });

  // client
  let ct = null;
  $('#customerSearch').addEventListener('input', () => { clearTimeout(ct); ct = setTimeout(async () => {
    const r = await fetch(`${BASE}/restaurant/customers?q=${encodeURIComponent($('#customerSearch').value)}`, { headers: { Accept: 'application/json' } });
    const d = await r.json().catch(() => ({ items: [] }));
    $('#customerResults').innerHTML = (d.items || []).map((c) => `<button type="button" class="list-group-item list-group-item-action" data-cid="${c.id}" data-name="${esc(c.name)}" data-phone="${esc(c.phone || '')}">${esc(c.name)} <small class="text-secondary">${esc(c.phone || '')}</small></button>`).join('');
  }, 250); });
  let chosenCustomer = null;
  $('#customerResults').addEventListener('click', (e) => { const b = e.target.closest('[data-cid]'); if (!b) return; chosenCustomer = Number(b.dataset.cid); $('#cName').value = b.dataset.name; $('#cPhone').value = b.dataset.phone; $$('#customerResults .active').forEach((x) => x.classList.remove('active')); b.classList.add('active'); });
  $('#customerOk').addEventListener('click', async () => {
    const d = await api(`/restaurant/orders/${CFG.orderId}/update`, { customer_id: chosenCustomer ?? order.customer_id ?? 0, customer_name: $('#cName').value, customer_phone: $('#cPhone').value, delivery_address: $('#cAddress').value, note: $('#cNote').value });
    if (d.ok) modal('customerModal').hide();
  });

  // ------------------------------------------------------------------ actions
  async function action(a) {
    const i = sel();
    switch (a) {
      case 'send': {
        if (busy) return; busy = true;
        const d = await api(`/restaurant/orders/${CFG.orderId}/send`, {});
        busy = false;
        if (d.ok) {
          toast(d.sent ? __('Envoyé en cuisine : {n} bon(s) affiché(s) sur l\'écran cuisine.', { n: d.sent }) : __('Articles marqués comme servis.'), 'success');
          printUrls(d.print); printFeedback(d);
        }
        break;
      }
      case 'printKitchen': {
        const d = await api(`/restaurant/orders/${CFG.orderId}/print-kitchen`, {});
        if (d.ok) { printUrls(d.print); printFeedback(d); if (d.printed_server) toast(__('Bon(s) envoyé(s) à l\'imprimante.'), 'success'); }
        break;
      }
      case 'quantity':
        if (!needSel()) return;
        if (i.status !== 'new') { toast(__('Article déjà envoyé : utilisez « Annuler » pour réduire.'), 'warning'); return; }
        askNumber('Quantité — ' + i.name, String(i.qty), (v) => api(`/restaurant/items/${i.id}`, { quantity: v }));
        break;
      case 'note':
        if (!needSel()) return;
        if (i.status !== 'new') { toast(__('Note modifiable avant l\'envoi en cuisine uniquement.'), 'warning'); return; }
        $('#noteInput').value = i.note || ''; modal('noteModal').show(); break;
      case 'cancel':
        if (!i) {
          const r = prompt(__('Annuler TOUTE la commande ? Indiquez le motif :'));
          if (r && r.trim()) { $('#cancelOrderReason').value = r.trim(); $('#cancelOrderForm').submit(); }
          return;
        }
        if (i.status === 'cancelled') return;
        if (i.status === 'new') { await api(`/restaurant/items/${i.id}`, { quantity: 0 }); selected = null; render(); return; }
        openCancel(i); break;
      case 'covers': askNumber(__('Nombre de couverts'), String(order.covers), (v) => api(`/restaurant/orders/${CFG.orderId}/update`, { covers: Math.max(1, Math.round(v)) })); break;
      case 'serve': { const d = await api(`/restaurant/orders/${CFG.orderId}/serve`, {}); if (d.ok) toast(__('Plats prêts marqués comme servis.'), 'success'); break; }
      case 'transfer': if (!CFG.perms.transfer) { toast(__('Permission requise pour changer de table.'), 'warning'); return; } modal('tableModal').show(); break;
      case 'bill': {
        if (order.items.some((x) => x.status === 'new')) { toast(__('Des articles ne sont pas envoyés en cuisine.'), 'warning'); }
        const f = document.createElement('iframe'); f.style.cssText = 'position:fixed;width:0;height:0;border:0'; f.src = `${BASE}/restaurant/orders/${CFG.orderId}/bill?auto=1`; document.body.appendChild(f);
        setTimeout(() => api(`/restaurant/orders/${CFG.orderId}/data`), 1500); break;
      }
      case 'customer':
        chosenCustomer = null; $('#customerSearch').value = ''; $('#customerResults').innerHTML = '';
        $('#cName').value = order.customer || ''; $('#cPhone').value = order.customer_phone || ''; $('#cAddress').value = order.address || ''; $('#cNote').value = order.note || '';
        modal('customerModal').show(); break;
      default: break;
    }
  }
  document.addEventListener('click', (e) => { const b = e.target.closest('[data-action]'); if (b) { e.preventDefault(); action(b.dataset.action); } });
  $('#payBtn') && $('#payBtn').addEventListener('click', (e) => { if (order.items.some((x) => x.status === 'new') && !confirm(__('Des articles ne sont pas envoyés en cuisine. Encaisser quand même ?'))) e.preventDefault(); });

  render();
  loadProducts();
  // Mise à jour régulière (statuts envoyés par la cuisine, autres serveurs)
  setInterval(() => { if (!document.querySelector('.modal.show')) api(`/restaurant/orders/${CFG.orderId}/data`); }, 8000);
})();
