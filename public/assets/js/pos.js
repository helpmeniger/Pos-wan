/* =====================================================================
   POS Marwa — logique de la caisse.
   Le navigateur calcule les totaux pour l'affichage ; le serveur recalcule
   TOUT (prix, remises, taxes, stock, droits) et fait foi.
   ===================================================================== */
(function () {
  'use strict';
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
  const CFG = JSON.parse($('#pos-config').textContent);
  const BASE = ($('meta[name="base-url"]').content || '/').replace(/\/$/, '');
  const STORE_KEY = 'pw-cart-' + CFG.userId;
  const fmt = (n) => PWMoney.fmt(n);
  const plain = (n) => PWMoney.plain(n);
  const fmtQ = (q) => (Math.round(q * 1000) / 1000).toLocaleString('fr-FR');
  const num = (v) => { const n = parseFloat(String(v ?? '').replace(/\s/g, '').replace(',', '.')); return isFinite(n) ? n : 0; };
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const modal = (id) => bootstrap.Modal.getOrCreateInstance(document.getElementById(id));

  // UUID v4 (fonctionne aussi en HTTP sur le réseau local, où crypto.randomUUID n'existe pas)
  const uuid = () => {
    const b = crypto.getRandomValues(new Uint8Array(16));
    b[6] = (b[6] & 0x0f) | 0x40; b[8] = (b[8] & 0x3f) | 0x80;
    const h = Array.from(b, (x) => x.toString(16).padStart(2, '0')).join('');
    return `${h.slice(0, 8)}-${h.slice(8, 12)}-${h.slice(12, 16)}-${h.slice(16, 20)}-${h.slice(20)}`;
  };

  // ------------------------------------------------------------------ état
  const newCart = () => ({ uuid: uuid(), lines: [], customer: null, discountType: 'amount', discountValue: 0, note: '', wholesale: false });
  let cart = (() => { try { const c = JSON.parse(localStorage.getItem(STORE_KEY)); if (c && Array.isArray(c.lines)) return c; } catch (e) { /* ignoré */ } return newCart(); })();
  // Panier vide : identifiant neuf (un autre onglet de caisse a pu charger le même panier enregistré)
  if (!cart.lines.length) cart.uuid = uuid();
  let selected = cart.lines.length ? cart.lines.length - 1 : -1;
  let pendingQty = 1;
  let scanMode = 'auto';
  let currentCat = 0;
  let payments = [];
  let payMethod = 'cash';
  let payProvider = null;
  let quickMode = false;
  let supervisor = null;
  let busy = false;
  let online = navigator.onLine !== false; // devient faux à la première coupure constatée
  const save = () => { try { localStorage.setItem(STORE_KEY, JSON.stringify(cart)); } catch (e) { /* stockage plein */ } };

  // ------------------------------------------------------------------ utilitaires
  function toast(msg, type = 'dark') {
    const el = document.createElement('div');
    el.className = `toast align-items-center text-bg-${type} border-0 show mb-2`;
    el.setAttribute('role', 'alert');
    el.innerHTML = `<div class="d-flex"><div class="toast-body">${esc(msg)}</div><button type="button" class="btn-close btn-close-white me-2 m-auto" aria-label="Fermer"></button></div>`;
    $('.btn-close', el).addEventListener('click', () => el.remove());
    $('#toasts').appendChild(el);
    setTimeout(() => el.remove(), type === 'danger' ? 6000 : 2800);
  }
  // Événements de caisse pour les modules (ex. assistant vocal MARWA VOICE) ; sans module : sans effet
  function emit(event, data) { try { document.dispatchEvent(new CustomEvent('pm:event', { detail: { event, data: data || {} } })); } catch (e) { /* ignoré */ } }
  function beep(ok = true) {
    try {
      const ctx = beep.ctx || (beep.ctx = new (window.AudioContext || window.webkitAudioContext)());
      const o = ctx.createOscillator(); const g = ctx.createGain();
      o.frequency.value = ok ? 1250 : 260; g.gain.value = 0.06; o.connect(g); g.connect(ctx.destination);
      o.start(); o.stop(ctx.currentTime + (ok ? 0.07 : 0.25));
    } catch (e) { /* audio indisponible */ }
  }
  // Coupure réseau ou serveur injoignable : réponse { ok: false, offline: true } au lieu d'une exception
  async function api(url, opts = {}) {
    const ctl = new AbortController();
    const timer = setTimeout(() => ctl.abort(), opts.timeout || 15000);
    let res;
    try {
      res = await fetch(BASE + url, {
        method: opts.method || 'GET',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': CFG.csrf },
        body: opts.body ? JSON.stringify(opts.body) : undefined,
        credentials: 'same-origin',
        signal: ctl.signal,
      });
    } catch (e) {
      setOnline(false);
      return { ok: false, offline: true, status: 0, error: __('Pas de connexion au serveur.') };
    } finally { clearTimeout(timer); }
    setOnline(res.status < 500);
    if (res.status === 401 && opts.noRedirect) return { ok: false, status: 401, error: __('Session expirée : reconnectez-vous.') };
    if (res.status === 401) { save(); location.href = BASE + '/login'; throw new Error('Session expirée'); }
    let data = {};
    try { data = await res.json(); } catch (e) { data = { ok: false, error: 'Réponse invalide du serveur.' }; }
    if (res.status === 419) data.error = 'Jeton de sécurité expiré : la page va être rechargée.';
    if (res.status >= 500) data.offline = true; // serveur indisponible (hébergeur en panne, passerelle) : traité comme une coupure
    data.status = res.status;
    return data;
  }

  // ------------------------------------------------------------------ prix (miroir du serveur)
  function unitPriceOf(l) {
    if (l.unitPrice !== null && l.unitPrice !== undefined) return l.unitPrice;
    if (l.packId) return l.packPrice;
    const baseQty = l.qty * (l.factor || 1);
    if (cart.wholesale && l.wholesale > 0 && (l.wholesaleMin === null || baseQty >= l.wholesaleMin)) return l.wholesale;
    return l.price;
  }
  function totals() {
    let gross = 0, lineDisc = 0;
    cart.lines.forEach((l) => { const g = unitPriceOf(l) * l.qty; gross += g; lineDisc += Math.min(l.discount || 0, g); });
    const after = gross - lineDisc;
    const global = cart.discountType === 'percent' ? after * Math.min(cart.discountValue, 100) / 100 : Math.min(cart.discountValue, after);
    const total = Math.round((after - global) * 100) / 100;
    return { gross, lineDisc, global, discount: lineDisc + global, total, items: cart.lines.reduce((a, l) => a + l.qty, 0) };
  }

  // ------------------------------------------------------------------ rendu du ticket
  function render() {
    const box = $('#cart');
    if (!cart.lines.length) {
      box.innerHTML = '<div class="cart-empty"><i class="bi bi-upc-scan fs-1 d-block mb-2"></i>' + esc(__('Scannez ou touchez un article')) + '</div>';
    } else {
      box.innerHTML = cart.lines.map((l, i) => {
        const up = unitPriceOf(l);
        const lt = up * l.qty - (l.discount || 0);
        const subs = [];
        if (l.packName) subs.push(l.packName);
        if (l.unitPrice !== null && l.unitPrice !== undefined) subs.push('prix modifié');
        if (l.discount > 0) subs.push('remise ' + fmt(l.discount));
        if (cart.wholesale && !l.packId && l.wholesale > 0 && up === l.wholesale) subs.push('prix de gros');
        if (l.rx) subs.push('ordonnance');
        if (l.track && l.qty * (l.factor || 1) > l.stock) subs.push('⚠ stock ' + fmtQ(l.stock));
        return `<div class="cart-line ${i === selected ? 'sel' : ''}" data-i="${i}">
          <span class="ln-name">${esc(l.name)}</span><span>${fmtQ(l.qty)}</span><span>${plain(up)}</span><span>${plain(lt)}</span>
          ${subs.length ? `<span class="ln-sub">${esc(subs.join(' · '))}</span>` : ''}</div>`;
      }).join('');
      const sel = $('.cart-line.sel', box); if (sel) sel.scrollIntoView({ block: 'nearest' });
    }
    const t = totals();
    $('#totalOut').textContent = fmt(t.total);
    $('#summary').innerHTML = `<div><span>${cart.lines.length} ${esc(__('ligne(s)'))} · ${fmtQ(t.items)} ${esc(__('article(s)'))}</span><span>${esc(__('Sous-total'))} ${fmt(t.gross)}</span></div>` +
      (t.discount > 0 ? `<div><span>Remises</span><span>− ${fmt(t.discount)}</span></div>` : '') +
      (cart.note ? `<div><span>Note : ${esc(cart.note)}</span></div>` : '');
    const c = cart.customer;
    $('#customerBadge span').textContent = c ? `${c.name}${c.phone ? ' · ' + c.phone : ''} — ${__('encours')} ${fmt(c.balance)}` : __('Client comptoir');
    const ws = $('#optWholesale'); if (ws) ws.checked = !!cart.wholesale;
    updateChange();
    save();
  }
  function updateChange() {
    const t = totals().total;
    const tendered = num($('#tenderedInput').value);
    $('#changeOut').textContent = fmt(Math.max(0, tendered - t));
  }

  // ------------------------------------------------------------------ ajout d'articles
  function addItem(p, packId = null, qty = null) {
    const pack = packId ? (p.packs || []).find((k) => k.id === packId) : null;
    let q = qty ?? pendingQty;
    if (!p.decimal && !pack) q = Math.max(1, Math.round(q));
    const key = p.id + ':' + (pack ? pack.id : 0);
    const idx = cart.lines.findIndex((l) => l.key === key && (l.unitPrice === null || l.unitPrice === undefined));
    if (idx >= 0) {
      cart.lines[idx].qty = Math.round((cart.lines[idx].qty + q) * 1000) / 1000;
      cart.lines[idx].stock = p.stock;
      selected = idx;
    } else {
      cart.lines.push({
        key, id: p.id, name: p.name + (pack ? ' (' + pack.name + ')' : ''), price: p.price, unitPrice: null, qty: q, discount: 0,
        packId: pack ? pack.id : null, packName: pack ? pack.name : null, packPrice: pack ? pack.price : null, factor: pack ? pack.factor : 1,
        wholesale: p.wholesale, wholesaleMin: p.wholesaleMin, stock: p.stock, track: p.track, decimal: p.decimal, rx: p.rx,
      });
      selected = cart.lines.length - 1;
    }
    const line = cart.lines[selected];
    const short = line.track && line.qty * (line.factor || 1) > line.stock;
    if (short) { toast(`Stock disponible insuffisant pour « ${p.name} » (${fmtQ(p.stock)}).`, 'warning'); emit('pos.stock.insufficient', { name: p.name, stock: p.stock }); }
    setPendingQty(1);
    beep(true);
    render();
    if (!short) emit('pos.product.added', { name: line.name, qty: q, total: totals().total });
  }
  // Recherche par code : serveur, ou catalogue gardé sur l'appareil quand la connexion est coupée
  async function lookup(code) {
    const d = await api('/pos/lookup?code=' + encodeURIComponent(code));
    if (!d.offline) return d;
    const hit = Offline.find(code);
    return hit ? { ok: true, item: hit.p, packaging_id: hit.packId, local: true } : { ok: false, error: Offline.ready() ? __('Aucun article pour le code « {c} » (catalogue hors connexion).', { c: code }) : d.error };
  }
  async function scan(code) {
    code = code.trim();
    if (!code) return;
    const d = await lookup(code);
    if (d.ok) { addItem(d.item, d.packaging_id); return; }
    beep(false);
    if (scanMode === 'manual') { $('#productSearch').value = code; loadProducts(); }
    toast(d.error || 'Article introuvable.', 'danger');
    emit('pos.product.notfound', {});
  }
  function setPendingQty(q) {
    pendingQty = q;
    $$('.qty-key').forEach((b) => b.classList.toggle('on', Number(b.dataset.qty) === q && q > 1));
  }

  // ------------------------------------------------------------------ grille d'articles
  let page = 1, loading = false, more = false, searchTimer = null, items = [];
  async function loadProducts(append = false) {
    if (loading) return;
    loading = true;
    if (!append) { page = 1; items = []; }
    const q = $('#productSearch').value.trim();
    let d = await api(`/pos/products?category=${currentCat}&q=${encodeURIComponent(q)}&page=${page}`);
    if (d.offline && Offline.ready()) d = Offline.query(currentCat, q, page);
    loading = false;
    if (!d.ok) { toast(d.error || 'Erreur de chargement', 'danger'); return; }
    more = d.more; items = items.concat(d.items);
    const grid = $('#productGrid');
    if (!items.length) { grid.innerHTML = '<div class="empty-msg"><i class="bi bi-inbox fs-1 d-block"></i>' + esc(__('Aucun article')) + '</div>'; return; }
    grid.innerHTML = items.map((p, i) => {
      const initials = esc(p.name.split(/\s+/).filter((w) => /^[\p{L}\d]/u.test(w)).slice(0, 2).map((w) => w[0]).join('').toUpperCase());
      const img = p.image ? `<img src="${esc(p.image)}" alt="" loading="lazy">` : initials;
      const packs = (p.packs || []).map((k) => `<button class="btn btn-sm btn-outline-secondary py-0 px-1 mt-1" data-pack="${k.id}" data-i="${i}">${esc(k.name)} · ${fmt(k.price)}</button>`).join(' ');
      return `<div class="p-card ${p.track && p.stock <= 0 ? 'out' : ''}" data-i="${i}" role="button" tabindex="0">
        ${p.promo ? '<span class="badge bg-danger badge-promo">PROMO</span>' : ''}${p.rx ? '<span class="badge bg-info badge-rx" title="' + esc(__('Ordonnance')) + '">Rx</span>' : ''}
        <div class="p-img" style="background:${p.image ? '#fff' : esc(p.color)}">${img}</div>
        <div class="p-name">${esc(p.name)}</div>
        <div class="p-meta"><span class="stk">${p.track ? __('Stock') + ' : ' + fmtQ(p.stock) : '&nbsp;'}</span><span>${esc(p.unit || '')}</span></div>
        <div class="p-price">${p.promo ? `<s class="text-secondary small me-1">${fmt(p.regular)}</s>` : ''}${fmt(p.price)}</div>${packs}</div>`;
    }).join('') + (more ? '<button class="btn btn-outline-secondary empty-msg" id="loadMore">Afficher plus…</button>' : '');
  }
  // ------------------------------------------------------------------ stock en temps réel
  // Les ventes des autres caisses, réceptions, retours, transferts et inventaires modifient le
  // stock : il est relu toutes les 15 s (et après chaque vente) sans recharger la page.
  async function refreshStock() {
    const ids = [...new Set(cart.lines.map((l) => l.id).concat(items.map((p) => p.id)))].slice(0, 300);
    if (!ids.length || document.hidden) return;
    let d;
    try { d = await api('/pos/stock?ids=' + ids.join(',')); } catch (e) { return; }
    if (!d || !d.ok) return;
    const st = d.stock || {};
    let changed = false;
    cart.lines.forEach((l) => { if (st[l.id] !== undefined && st[l.id] !== l.stock) { l.stock = st[l.id]; changed = true; } });
    items.forEach((p, i) => {
      if (st[p.id] === undefined || st[p.id] === p.stock) return;
      p.stock = st[p.id];
      const card = document.querySelector(`#productGrid .p-card[data-i="${i}"]`);
      if (card) {
        card.classList.toggle('out', p.track && p.stock <= 0);
        const s2 = card.querySelector('.stk');
        if (s2 && p.track) s2.textContent = __('Stock') + ' : ' + fmtQ(p.stock);
      }
    });
    if (changed) { save(); render(); }
  }
  setInterval(refreshStock, 15000);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) refreshStock(); });
  window.PosStock = { refresh: refreshStock };

  $('#productGrid').addEventListener('click', (e) => {
    if (e.target.id === 'loadMore') { page++; loadProducts(true); return; }
    const pk = e.target.closest('[data-pack]');
    if (pk) { e.stopPropagation(); addItem(items[Number(pk.dataset.i)], Number(pk.dataset.pack)); return; }
    const c = e.target.closest('.p-card'); if (c) addItem(items[Number(c.dataset.i)]);
  });
  $('#productGrid').addEventListener('keydown', (e) => { if (e.key === 'Enter') { const c = e.target.closest('.p-card'); if (c) addItem(items[Number(c.dataset.i)]); } });
  $('#catBar').addEventListener('click', (e) => {
    const t = e.target.closest('.cat-tile'); if (!t) return;
    $$('.cat-tile').forEach((x) => x.classList.toggle('active', x === t));
    currentCat = Number(t.dataset.cat); loadProducts();
  });
  $('#productSearch').addEventListener('input', () => { clearTimeout(searchTimer); searchTimer = setTimeout(() => loadProducts(), 250); });
  $$('[data-view]').forEach((b) => b.addEventListener('click', () => {
    $$('[data-view]').forEach((x) => x.classList.toggle('active', x === b));
    $('#productGrid').classList.toggle('list', b.dataset.view === 'list');
    localStorage.setItem('pw-pos-view', b.dataset.view);
  }));
  if (localStorage.getItem('pw-pos-view') === 'list') $('[data-view="list"]').click();

  // ------------------------------------------------------------------ code-barres
  // Caméra du smartphone / de la tablette : lecture successive, anti-doublon, retour visuel et sonore
  const cam = $('#cameraScan');
  if (cam && window.PMScanner) cam.addEventListener('click', () => PMScanner.open({
    title: __('Scanner des articles'),
    products: true,
    onCode: async (code) => {
      const d = await lookup(code);
      if (!d.ok) return { ok: false, text: d.error || __('Article introuvable.') };
      addItem(d.item, d.packaging_id);
      const it = d.item;
      const line = cart.lines[selected];
      const low = it.track && line && line.qty * (line.factor || 1) > it.stock;
      return { ok: true, html: `<b>${esc(it.name)}</b> · ${esc(it.reference || '')} · ${fmt(it.price)} · ${__('Stock')} ${fmtQ(it.stock)}${line ? ' · ' + __('Qté') + ' ' + fmtQ(line.qty) : ''}${low ? ' <span style="color:#c62828">⚠ ' + __('stock insuffisant') + '</span>' : ''}` };
    },

    // Annulation d'un ajout automatique (reconnaissance par photo) : une unité en moins
    onUndo: async (code) => {
      const d = await lookup(code);
      if (!d.ok) return { ok: false, text: d.error || __('Article introuvable.') };
      const idx = cart.lines.findIndex((l) => l.id === d.item.id && !l.packId);
      if (idx < 0) return { ok: false, text: __('Article absent du panier.') };
      const l = cart.lines[idx];
      if (l.qty > 1) l.qty = Math.round((l.qty - 1) * 1000) / 1000; else { cart.lines.splice(idx, 1); selected = Math.min(selected, cart.lines.length - 1); }
      render();
      return { ok: true };
    },
  }));
  const bi = $('#barcodeInput');
  bi.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); const v = bi.value; bi.value = ''; scan(v); } });
  $('#barcodeGo').addEventListener('click', () => { const v = bi.value; bi.value = ''; scan(v); bi.focus(); });
  $$('[data-mode]').forEach((b) => b.addEventListener('click', () => {
    scanMode = b.dataset.mode; $$('[data-mode]').forEach((x) => x.classList.toggle('active', x === b)); bi.focus();
  }));
  // Douchette « clavier » : une rafale de touches rapides terminée par Entrée hors champ de saisie
  let buf = '', lastKey = 0;
  document.addEventListener('keydown', (e) => {
    const tag = (document.activeElement && document.activeElement.tagName) || '';
    if (['INPUT', 'TEXTAREA', 'SELECT'].includes(tag) || document.querySelector('.modal.show')) return;
    const now = Date.now();
    if (now - lastKey > 60) buf = '';
    lastKey = now;
    if (e.key === 'Enter' && buf.length >= 3) { e.preventDefault(); const v = buf; buf = ''; scan(v); return; }
    if (e.key.length === 1) buf += e.key;
  }, true);

  // ------------------------------------------------------------------ panier : sélection & édition
  $('#cart').addEventListener('click', (e) => { const l = e.target.closest('.cart-line'); if (l) { selected = Number(l.dataset.i); render(); } });
  $('#cart').addEventListener('dblclick', (e) => { if (e.target.closest('.cart-line')) openNum('quantity'); });
  $$('.qty-key').forEach((b) => b.addEventListener('click', () => {
    const q = Number(b.dataset.qty);
    if (selected >= 0 && cart.lines[selected] && pendingQty === q) { cart.lines[selected].qty = q; setPendingQty(1); render(); return; }
    setPendingQty(pendingQty === q ? 1 : q);
    toast(pendingQty > 1 ? `Quantité ×${pendingQty} pour le prochain article (toucher à nouveau : appliquer à la ligne)` : 'Quantité normale');
  }));
  $$('[data-scroll]').forEach((b) => b.addEventListener('click', () => { $('#productGrid').scrollBy({ top: b.dataset.scroll === 'up' ? -300 : 300, behavior: 'smooth' }); }));

  let numMode = null;
  function openNum(mode) {
    if (mode !== 'discount' && (selected < 0 || !cart.lines[selected])) { toast(__('Sélectionnez une ligne du panier.'), 'warning'); return; }
    numMode = mode;
    const l = cart.lines[selected];
    $('#numDiscType').classList.toggle('d-none', mode !== 'discount');
    $('#numScope').classList.toggle('d-none', mode !== 'discount');
    if (mode === 'quantity') { $('#numTitle').textContent = __('Quantité') + ' — ' + l.name; $('#numInput').value = String(l.qty).replace('.', ','); }
    if (mode === 'price') { $('#numTitle').textContent = __('Prix unitaire') + ' — ' + l.name + (CFG.perms.price ? '' : ' (' + __('autorisation requise') + ')'); $('#numInput').value = String(unitPriceOf(l)); }
    if (mode === 'discount') {
      if (!CFG.perms.discount) toast(__('Remise : une autorisation sera demandée.'), 'warning');
      $('#numTitle').textContent = __('Remise') + (CFG.maxDiscount ? ` (${__('seuil')} ${CFG.maxDiscount} %)` : '');
      $('#scLine').checked = selected >= 0; $('#scTicket').checked = selected < 0;
      $('#numInput').value = '';
    }
    modal('numModal').show();
    setTimeout(() => { $('#numInput').focus(); $('#numInput').select(); }, 300);
  }
  $('#numKeypad').addEventListener('click', (e) => {
    const k = e.target.closest('[data-k]'); if (!k) return;
    const inp = $('#numInput');
    inp.value = k.dataset.k === '⌫' ? inp.value.slice(0, -1) : inp.value + k.dataset.k;
  });
  $('#numInput').addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); $('#numOk').click(); } });
  $('#numOk').addEventListener('click', () => {
    const v = num($('#numInput').value);
    const l = cart.lines[selected];
    if (numMode === 'quantity') {
      if (v <= 0) { cart.lines.splice(selected, 1); selected = cart.lines.length - 1; }
      else l.qty = l.decimal ? Math.round(v * 1000) / 1000 : Math.max(1, Math.round(v));
    } else if (numMode === 'price') {
      if (v < 0) return;
      l.unitPrice = Math.abs(v - (l.packId ? l.packPrice : l.price)) < 0.01 ? null : v;
    } else if (numMode === 'discount') {
      const type = $('#dtPercent').checked ? 'percent' : 'amount';
      if ($('#scLine').checked && l) {
        const g = unitPriceOf(l) * l.qty;
        l.discount = Math.min(g, type === 'percent' ? Math.round(g * Math.min(v, 100) / 100) : v);
      } else { cart.discountType = type; cart.discountValue = Math.max(0, v); }
    }
    modal('numModal').hide();
    render();
  });

  // ------------------------------------------------------------------ client
  let custTimer = null;
  async function searchCustomers() {
    const d = await api('/pos/customers?q=' + encodeURIComponent($('#customerSearch').value.trim()));
    if (d.offline) { $('#customerResults').innerHTML = '<div class="text-secondary small p-2">' + esc(__('Hors connexion : la recherche de clients reprendra au retour de la connexion. Le client déjà choisi reste sur la vente.')) + '</div>'; return; }
    $('#customerResults').innerHTML = (d.items || []).map((c) => `<button class="list-group-item list-group-item-action d-flex justify-content-between" data-c='${esc(JSON.stringify(c))}'><span>${esc(c.name)} <small class="text-secondary">${esc(c.phone || '')}</small></span><span class="small ${Number(c.balance) > 0 ? 'text-danger' : 'text-secondary'}">encours ${fmt(c.balance)}</span></button>`).join('') || '<div class="text-secondary small p-2">Aucun client.</div>';
  }
  function chooseCustomer(c) {
    cart.customer = c ? { id: Number(c.id), name: c.name, phone: c.phone, balance: Number(c.balance), credit_limit: Number(c.credit_limit) } : null;
    modal('customerModal').hide(); render(); refreshPay();
  }
  $('#customerSearch').addEventListener('input', () => { clearTimeout(custTimer); custTimer = setTimeout(searchCustomers, 250); });
  $('#customerResults').addEventListener('click', (e) => { const b = e.target.closest('[data-c]'); if (b) chooseCustomer(JSON.parse(b.dataset.c)); });
  $('#customerNone').addEventListener('click', () => chooseCustomer(null));
  const ncSave = $('#ncSave');
  if (ncSave) ncSave.addEventListener('click', async () => {
    const d = await api('/pos/customers', { method: 'POST', body: { name: $('#ncName').value, phone: $('#ncPhone').value } });
    if (!d.ok) { toast(d.error, 'danger'); return; }
    $('#ncName').value = ''; $('#ncPhone').value = ''; chooseCustomer(d.customer); toast(__('Client créé.'), 'success');
  });
  $('#customerBadge').addEventListener('click', () => action('customer'));

  // ------------------------------------------------------------------ paiement
  function paidSum() { return payments.reduce((a, p) => a + p.amount, 0); }
  function refreshPay() {
    const t = totals().total;
    const paid = paidSum();
    $('#payTotal').textContent = fmt(t);
    $('#payRemaining').textContent = fmt(Math.max(0, t - paid));
    $('#payChange').textContent = fmt(Math.max(0, paid - t));
    $('#payLines').innerHTML = payments.map((p, i) => `<tr><td>${esc(CFG.methods[p.method] || p.method)}${p.provider ? ' · ' + esc(p.provider) : ''}${p.reference ? ' <small class="text-secondary">' + esc(p.reference) + '</small>' : ''}</td><td class="text-end fw-bold">${fmt(p.amount)}</td><td class="text-end"><button class="btn btn-sm btn-link text-danger" data-del-pay="${i}"><i class="bi bi-x-circle"></i></button></td></tr>`).join('');
    $('#payCustomer').textContent = cart.customer ? cart.customer.name : __('comptoir');
    const rx = cart.lines.some((l) => l.rx);
    $('#rxBox').classList.toggle('d-none', !rx);
    const rest = Math.max(0, t - paid);
    // Billets courants FCFA pour un encaissement rapide
    const opts = new Set([rest]);
    [500, 1000, 2000, 5000, 10000].forEach((b) => { if (rest > 0) opts.add(Math.ceil(rest / b) * b); });
    $('#quickCash').innerHTML = Array.from(opts).filter((v) => v > 0).sort((a, b) => a - b).slice(0, 6).map((v) => `<button type="button" data-qc="${v}">${fmt(v)}</button>`).join('');
  }
  function selectMethod(m) {
    payMethod = m;
    $$('#payMethods button').forEach((b) => b.classList.toggle('active', b.dataset.m === m));
    $('#payMethodLabel').textContent = CFG.methods[m];
    $('#payRef').classList.toggle('d-none', m === 'cash');
    const prov = $('#payProviders');
    const list = CFG.mobileProviders || [];
    prov.classList.toggle('d-none', m !== 'mobile_money' || !list.length);
    if (m === 'mobile_money' && list.length) {
      if (!list.includes(payProvider)) payProvider = list[0];
      prov.innerHTML = list.map((n) => `<button type="button" data-prov="${esc(n)}" class="${n === payProvider ? 'active' : ''}"><i class="bi bi-phone"></i> ${esc(n)}</button>`).join('');
      $('#payMethodLabel').textContent = payProvider;
    }
    const rest = Math.max(0, totals().total - paidSum());
    $('#payAmount').value = rest ? String(Math.round(rest)) : '';
    $('#payAmount').focus(); $('#payAmount').select();
  }
  $('#payMethods').innerHTML = Object.entries(CFG.methods).map(([k, v]) => `<button type="button" data-m="${k}"><i class="bi ${{ cash: 'bi-cash', card: 'bi-credit-card', mobile_money: 'bi-phone', transfer: 'bi-bank', cheque: 'bi-journal' }[k] || 'bi-wallet'}"></i> ${esc(v)}</button>`).join('');
  $('#payMethods').addEventListener('click', (e) => { const b = e.target.closest('[data-m]'); if (b) selectMethod(b.dataset.m); });
  $('#payProviders').addEventListener('click', (e) => {
    const b = e.target.closest('[data-prov]'); if (!b) return;
    payProvider = b.dataset.prov;
    $$('#payProviders button').forEach((x) => x.classList.toggle('active', x === b));
    $('#payMethodLabel').textContent = payProvider;
    $('#payAmount').focus();
  });
  function addPayment(amount) {
    amount = Math.round(num(amount) * 100) / 100;
    if (amount <= 0) return false;
    const t = totals().total;
    if (payMethod !== 'cash' && paidSum() + amount > t + 0.01) { toast(__('Un paiement électronique ne peut pas dépasser le reste dû.'), 'warning'); return false; }
    payments.push({ method: payMethod, amount, reference: $('#payRef').value.trim() || null, provider: payMethod === 'mobile_money' ? payProvider : null });
    $('#payRef').value = '';
    refreshPay();
    const rest = Math.max(0, t - paidSum());
    $('#payAmount').value = rest ? String(Math.round(rest)) : '';
    return true;
  }
  $('#payAdd').addEventListener('click', () => addPayment($('#payAmount').value));
  $('#payAmount').addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); if ($('#payAmount').value) addPayment($('#payAmount').value); else $('#payConfirm').click(); } });
  $('#quickCash').addEventListener('click', (e) => { const b = e.target.closest('[data-qc]'); if (b) { selectMethod('cash'); addPayment(b.dataset.qc); } });
  $('#payLines').addEventListener('click', (e) => { const b = e.target.closest('[data-del-pay]'); if (b) { payments.splice(Number(b.dataset.delPay), 1); refreshPay(); } });
  $('#payKeypad').addEventListener('click', (e) => {
    const k = e.target.closest('[data-k]'); if (!k) return;
    const inp = $('#payAmount');
    if (inp.dataset.fresh !== '0') { inp.value = ''; inp.dataset.fresh = '0'; }
    inp.value = k.dataset.k === '⌫' ? inp.value.slice(0, -1) : inp.value + k.dataset.k;
  });
  function openPay(opts = {}) {
    if (!cart.lines.length) { toast(__('Le panier est vide.'), 'warning'); return; }
    if (CFG.requireSession && !CFG.sessionOpen) { toast(__('La caisse est fermée.'), 'danger'); emit('pos.register.closed'); return; }
    payments = [];
    supervisor = null;
    $('#payError').classList.add('d-none');
    const tendered = num($('#tenderedInput').value);
    refreshPay();
    selectMethod(CFG.methods.cash ? 'cash' : Object.keys(CFG.methods)[0]);
    if (tendered > 0 && CFG.methods.cash && !opts.credit) addPayment(tendered);
    if (opts.credit) { $('#payAmount').value = ''; if (!cart.customer) toast(__('Sélectionnez le client de la vente à crédit.'), 'warning'); }
    $('#payAmount').dataset.fresh = '1';
    modal('payModal').show();
    setTimeout(() => $('#payAmount').focus(), 350);
  }
  function payError(msg) { const el = $('#payError'); el.textContent = msg; el.classList.remove('d-none'); beep(false); }

  async function checkout() {
    if (busy) return;
    quickMode = false;
    // Montant saisi mais non ajouté : on l'ajoute automatiquement
    if ($('#payAmount').value && num($('#payAmount').value) > 0 && payments.length === 0) addPayment($('#payAmount').value);
    const t = totals().total;
    const rest = t - paidSum();
    if (rest > 0.009 && !cart.customer) { payError(__('Il manque {m}. Pour une vente à crédit, choisissez d\'abord un client.', { m: fmt(rest) })); return; }
    submitSale();
  }

  /*
   * Bouton « Valider » : encaissement automatique en espèces, sans la fenêtre
   * d'encaissement. Montant remis = « Argent client » s'il couvre le total,
   * sinon le montant exact. La fenêtre reste accessible avec le bouton « Espèces ».
   */
  function quickValidate() {
    if (busy) return;
    if (!cart.lines.length) { toast(__('Le panier est vide.'), 'warning'); return; }
    if (CFG.requireSession && !CFG.sessionOpen) { toast(__('La caisse est fermée.'), 'danger'); emit('pos.register.closed'); return; }
    if (!CFG.methods.cash || cart.lines.some((l) => l.rx)) { openPay(); return; }
    const t = totals().total;
    const tendered = num($('#tenderedInput').value);
    if (tendered > 0 && tendered + 0.009 < t) {
      // Montant insuffisant : on passe par la fenêtre (paiement mixte ou crédit)
      toast(__('Argent client insuffisant : complétez le paiement.'), 'warning'); emit('pos.payment.insufficient');
      openPay(); return;
    }
    payments = [{ method: 'cash', amount: Math.round((tendered > 0 ? tendered : t) * 100) / 100, reference: null, provider: null }];
    supervisor = null;
    quickMode = true;
    submitSale();
  }

  function saleError(msg) {
    if (quickMode) { toast(msg, 'danger'); beep(false); } else payError(msg);
  }

  async function submitSale() {
    const body = {
      _csrf: CFG.csrf,
      client_uuid: cart.uuid,
      items: cart.lines.map((l) => ({ product_id: l.id, packaging_id: l.packId, quantity: l.qty, unit_price: l.unitPrice, discount: l.discount || 0 })),
      customer_id: cart.customer ? cart.customer.id : null,
      discount_type: cart.discountType, discount_value: cart.discountValue,
      wholesale: !!cart.wholesale, payments, note: cart.note,
      supervisor,
      prescription: cart.lines.some((l) => l.rx) ? { prescriber: $('#rxPrescriber').value, patient_name: $('#rxPatient').value, prescription_ref: $('#rxRef').value, prescription_date: $('#rxDate').value } : null,
    };
    body.print_ticket = $('#optTicket').checked && CFG.autoPrint && CFG.serverPrinter;
    busy = true;
    $('#payConfirm').disabled = true;
    $$('.btn-validate').forEach((b) => { b.disabled = true; });
    let d;
    // Coupure réseau : la vente a pu être enregistrée ou non ; une nouvelle validation (même numéro
    // client_uuid) ne crée jamais de doublon et indique « déjà enregistrée » si elle l'était.
    // Coupure réseau : la vente est gardée sur l'appareil (même client_uuid) et envoyée au retour de
    // la connexion ; si le serveur l'avait déjà reçue, il répond « déjà enregistrée » (aucun doublon).
    if (!navigator.onLine) d = { ok: false, offline: true };
    else {
      // Après une coupure récente, attente courte : le caissier ne reste pas bloqué devant le client
      try { d = await api('/pos/checkout', { method: 'POST', body, timeout: online ? 20000 : 6000 }); } catch (e) { d = { ok: false, unknown: true, error: __('Connexion au serveur interrompue : la vente n\'est pas confirmée. Validez à nouveau (aucun doublon possible).') }; }
    }
    busy = false;
    $('#payConfirm').disabled = false;
    $$('.btn-validate').forEach((b) => { b.disabled = false; });
    if (d.offline) { queueSale(body); return; }
    if (!d.ok) {
      if (d.status === 419) { save(); setTimeout(() => location.reload(), 1500); }
      if (d.code === 'AUTH_REQUIRED') { emit('pos.auth.required'); $('#authReason').textContent = d.error + ' — ' + __('un responsable doit autoriser.'); $('#authUser').value = ''; $('#authSecret').value = ''; modal('authModal').show(); setTimeout(() => $('#authUser').focus(), 300); return; }
      if (d.code === 'PRESCRIPTION_REQUIRED') {
        if (quickMode) { openPay(); }
        $('#rxBox').classList.remove('d-none'); $('#rxPrescriber').focus();
        if (quickMode) { quickMode = false; payError(d.error); return; }
      }
      saleError(d.error || __('Erreur lors de l\'enregistrement.'));
      emit(d.unknown ? 'pos.sale.unknown' : 'pos.sale.refused', { error: d.error || '' });
      return;
    }
    if (!quickMode) modal('payModal').hide();
    finishSale(d);
    setTimeout(refreshStock, 300); // quantités affichées mises à jour après la vente
  }
  $('#payConfirm').addEventListener('click', checkout);
  $('#authOk').addEventListener('click', () => {
    supervisor = { username: $('#authUser').value.trim(), secret: $('#authSecret').value };
    $('#authSecret').value = '';
    modal('authModal').hide();
    submitSale();
  });
  $('#authSecret').addEventListener('keydown', (e) => { if (e.key === 'Enter') $('#authOk').click(); });

  function finishSale(d) {
    beep(true);
    $('#doneNumber').textContent = d.offline
      ? __('N° provisoire {n} — gardée sur cet appareil, elle sera enregistrée au retour de la connexion.', { n: d.number })
      : 'N° ' + d.number + (d.duplicate ? ' (' + __('déjà enregistrée') + ')' : '');
    $('#doneTitle').textContent = d.offline ? __('Vente gardée hors connexion') : __('Vente enregistrée');
    $('#doneChange').textContent = fmt(d.change);
    $('#doneTicket').href = d.offline ? '#' : d.ticket_url;
    $('#doneTicket').dataset.offline = d.offline ? d.entry.uuid : '';
    $('#doneInvoice').href = d.offline ? '#' : d.invoice_url;
    $('#doneInvoice').classList.toggle('d-none', !!d.offline); // facture A4 : après synchronisation
    if ($('#optTicket').checked && CFG.autoPrint && d.offline) printProvisional(d.entry);
    else if ($('#optTicket').checked && CFG.autoPrint && !d.duplicate && !(d.printed_server > 0)) printInFrame(d.ticket_url);
    if (d.print_errors && d.print_errors.length) toast(__('Imprimante de caisse') + ' : ' + d.print_errors.join(' '), 'warning');
    emit(d.offline ? 'pos.sale.offline' : 'pos.sale.saved', { total: d.total, change: d.change, duplicate: !!d.duplicate });
    if (d.print_errors && d.print_errors.length) emit('pos.print.failed', { errors: d.print_errors });
    else if (d.printed_server > 0) emit('pos.print.sent', {});
    if ($('#optA4').checked && d.offline) toast(__('Facture A4 disponible après synchronisation de la vente.'), 'warning');
    else if ($('#optA4').checked) window.open(d.invoice_url, '_blank', 'noopener');
    cart = newCart();
    selected = -1;
    $('#tenderedInput').value = '';
    render();
    if (quickMode) {
      // Encaissement automatique : pas de fenêtre, la monnaie s'affiche dans la barre de caisse
      quickMode = false;
      showLastSale(d);
      bi.focus();
      return;
    }
    modal('doneModal').show();
    setTimeout(() => $('#doneNew').focus(), 350);
  }
  function showLastSale(d) {
    const el = $('#lastSale');
    if (!el) return;
    el.innerHTML = `<i class="bi bi-check-circle-fill"></i> <b>${esc(d.number)}</b> · ${esc(__('Monnaie à rendre'))} : <b class="ls-change">${fmt(d.change)}</b>`;
    el.classList.remove('d-none');
    el.classList.toggle('has-change', d.change > 0);
    clearTimeout(showLastSale.t);
    showLastSale.t = setTimeout(() => el.classList.add('d-none'), 15000);
  }
  // Impression silencieuse du ticket via un cadre caché (pas de fenêtre surgissante bloquée)
  function printInFrame(url) {
    let f = $('#printFrame');
    if (f) f.remove();
    f = document.createElement('iframe');
    f.id = 'printFrame'; f.style.cssText = 'position:fixed;width:0;height:0;border:0;right:0;bottom:0';
    f.src = url;
    document.body.appendChild(f);
  }
  document.getElementById('doneModal').addEventListener('hidden.bs.modal', () => bi.focus());
  $('#doneTicket').addEventListener('click', (e) => {
    const id = e.currentTarget.dataset.offline;
    if (!id) return;
    e.preventDefault();
    const entry = Offline.queue.find((x) => x.uuid === id) || Offline.lastEntry;
    if (entry) printProvisional(entry);
  });

  // ------------------------------------------------------------------ caisse hors connexion
  /*
   * Une fois la caisse ouverte (au moins une fois avec la connexion), elle continue de vendre si
   * internet tombe : le catalogue est gardé sur l'appareil (IndexedDB), chaque vente validée est
   * mise en file (localStorage) avec son identifiant client_uuid, puis envoyée au serveur dès le
   * retour de la connexion. Le serveur reste seul juge : il recalcule prix, remises, stock et droits
   * et peut refuser une vente ; elle est alors présentée au caissier, jamais effacée en silence.
   */
  const Offline = (() => {
    const QKEY = 'pw-offline-sales-' + CFG.userId;
    const CACHE = 'pm-caisse-v1'; // même nom que dans public/sw.js
    let catalog = [];
    let index = new Map();
    let syncing = false;
    let authLost = false;
    let lastSync = null;
    const api2 = { queue: [], lastEntry: null };
    const read = () => { try { const q = JSON.parse(localStorage.getItem(QKEY)); return Array.isArray(q) ? q : []; } catch (e) { return []; } };
    api2.queue = read();

    // Lecture-modification-écriture d'un seul tenant : deux onglets de caisse ne s'écrasent pas leurs ventes
    function mutate(fn) {
      const q = read();
      fn(q);
      try { localStorage.setItem(QKEY, JSON.stringify(q)); } catch (e) { api2.queue = read(); return false; }
      api2.queue = q;
      return true;
    }
    const byId = (q, id) => q.find((x) => x.uuid === id);

    // --- catalogue local (IndexedDB : plusieurs milliers d'articles, au-delà de la limite de localStorage)
    function idb() {
      return new Promise((resolve, reject) => {
        if (!window.indexedDB) { reject(new Error('IndexedDB indisponible')); return; }
        const r = indexedDB.open('pm-caisse', 1);
        r.onupgradeneeded = () => r.result.createObjectStore('kv');
        r.onsuccess = () => resolve(r.result);
        r.onerror = () => reject(r.error);
      });
    }
    async function kv(mode, key, value) {
      const db = await idb();
      return new Promise((resolve, reject) => {
        const tx = db.transaction('kv', mode);
        const st = tx.objectStore('kv');
        const r = mode === 'readonly' ? st.get(key) : st.put(value, key);
        tx.oncomplete = () => { db.close(); resolve(r.result); };
        tx.onerror = () => { db.close(); reject(tx.error); };
      });
    }
    function setCatalog(items) {
      catalog = items;
      index = new Map();
      items.forEach((p) => {
        if (p.reference) index.set('r:' + p.reference, { p, packId: null });
        (p.packs || []).forEach((k) => { if (k.barcode) index.set('b:' + k.barcode, { p, packId: k.id }); });
      });
      items.forEach((p) => { if (p.barcode) index.set('b:' + p.barcode, { p, packId: null }); }); // code article prioritaire
    }
    async function loadCatalog() {
      try { const c = await kv('readonly', 'catalog-' + CFG.userId); if (c && Array.isArray(c.items) && !catalog.length) setCatalog(c.items); } catch (e) { /* ignoré */ }
      const d = await api('/pos/catalog', { timeout: 60000 });
      if (!d.ok || !Array.isArray(d.items)) return;
      setCatalog(d.items);
      try { await kv('readwrite', 'catalog-' + CFG.userId, { items: d.items, at: d.at }); } catch (e) { /* stockage indisponible : catalogue en mémoire seulement */ }
    }
    const norm = (v) => String(v ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
    api2.ready = () => catalog.length > 0;
    api2.find = (code) => index.get('b:' + code) || index.get('r:' + code) || null;
    api2.query = (cat, q, page) => {
      const n = norm(q);
      const list = catalog.filter((p) => (!cat || (p.cats || []).includes(cat))
        && (!n || norm(p.name).includes(n) || norm(p.reference).includes(n) || p.barcode === q || norm(p.dci).includes(n)));
      const per = 60;
      return { ok: true, items: list.slice((page - 1) * per, page * per), more: list.length > page * per, local: true };
    };
    // Stock affiché après une vente hors connexion (le vrai stock sera recalculé par le serveur)
    api2.consume = (lines) => {
      lines.forEach((l) => {
        if (!l.track) return;
        const used = l.qty * (l.factor || 1);
        catalog.filter((p) => p.id === l.id).forEach((p) => { p.stock = Math.round((p.stock - used) * 1000) / 1000; });
      });
    };

    // --- autorisations impossibles hors connexion (le mot de passe d'un responsable ne peut pas être vérifié)
    api2.authIssues = (body) => {
      const P = CFG.perms || {};
      const t = totals();
      const why = [];
      if (body.wholesale && !P.wholesale) why.push(__('prix de gros'));
      if (cart.lines.some((l) => l.unitPrice !== null && l.unitPrice !== undefined) && !P.price) why.push(__('prix modifié'));
      if (t.discount > 0 && !P.discount) why.push(__('remise'));
      else if (t.discount > 0 && t.gross > 0 && t.discount / t.gross * 100 > CFG.maxDiscount + 0.001 && !P.discountHigh) why.push(__('remise au-delà du seuil'));
      if (cart.lines.some((l) => l.rx) && !P.dispense) why.push(__('médicament sur ordonnance'));
      if (t.total - paidSum() > 0.009 && !P.credit) why.push(__('vente à crédit'));
      return why;
    };

    // --- synchronisation
    async function sync(manual = false) {
      api2.queue = read();
      const pending = api2.queue.filter((x) => x.state === 'pending');
      if (syncing || !pending.length) { if (manual && !pending.length) toast(__('Aucune vente à envoyer.')); return; }
      syncing = true; bar();
      let sent = 0, refused = 0;
      try {
        const p = await api('/pos/ping', { noRedirect: true });
        if (p.status === 401) { authLost = true; return; }
        if (!p.ok) { if (manual) toast(p.error || __('Pas de connexion au serveur.'), 'warning'); return; }
        if (Number(p.userId) !== Number(CFG.userId)) { authLost = true; return; }
        authLost = false;
        CFG.csrf = p.csrf;
        for (const entry of pending) {
          const d = await api('/pos/checkout', { method: 'POST', body: Object.assign({}, entry.body, { _csrf: CFG.csrf }), noRedirect: true, timeout: 30000 });
          if (d.offline || d.status === 401 || d.status === 419 || !d.status) { if (d.status === 401) authLost = true; break; }
          if (d.ok && d.duplicate && Math.abs(Number(d.total) - Number(entry.total)) > 0.01) {
            // Identifiant déjà pris par une AUTRE vente (total différent) : à revoir, jamais considérée comme envoyée
            mutate((q) => { const x = byId(q, entry.uuid); if (x) { x.state = 'refused'; x.code = 'UUID_TAKEN'; x.error = __('Le serveur a déjà une vente {n} de {m} avec cet identifiant (total différent). Vérifiez dans « Tickets » s\'il s\'agit de cette vente avant de la reprendre.', { n: d.number, m: fmt(d.total) }); } });
            refused++;
          } else if (d.ok) {
            mutate((q) => { const i = q.findIndex((x) => x.uuid === entry.uuid); if (i >= 0) q.splice(i, 1); });
            sent++;
            emit('pos.offline.synced', { number: d.number, provisional: entry.number });
          } else {
            mutate((q) => { const x = byId(q, entry.uuid); if (x) { x.state = 'refused'; x.error = d.error || __('Refusée par le serveur.'); x.code = d.code || null; } });
            refused++;
          }
        }
        lastSync = new Date();
      } finally {
        syncing = false; bar();
      }
      if (sent) { toast(__('{n} vente(s) faite(s) hors connexion enregistrée(s) sur le serveur.', { n: sent }), 'success'); setTimeout(refreshStock, 300); }
      if (refused) { toast(__('{n} vente(s) refusée(s) par le serveur : vérifiez-les (bandeau en haut).', { n: refused }), 'danger'); beep(false); }
    }
    api2.sync = sync;

    // --- bandeau d'état
    function bar() {
      const el = $('#netBar');
      if (!el) return;
      const pending = api2.queue.filter((x) => x.state === 'pending').length;
      const refused = api2.queue.filter((x) => x.state === 'refused').length;
      let cls = '', html = '';
      if (!online) {
        cls = 'off';
        html = `<i class="bi bi-wifi-off"></i> <b>${esc(__('Hors connexion'))}</b> — ${esc(__('la caisse continue de vendre.'))}` +
          (pending ? ` <b>${pending}</b> ${esc(__('vente(s) gardée(s) sur cet appareil : ne fermez pas le navigateur, rétablissez la connexion pour les enregistrer.'))}` : '');
      } else if (authLost && pending) {
        cls = 'warn';
        html = `<i class="bi bi-person-lock"></i> ${esc(__('Session expirée : {n} vente(s) attendent. Reconnectez-vous pour les enregistrer.', { n: pending }))} <a class="btn btn-sm btn-light ms-2" href="${BASE}/login">${esc(__('Se reconnecter'))}</a>`;
      } else if (syncing) {
        cls = 'sync';
        html = `<span class="spinner-border spinner-border-sm"></span> ${esc(__('Envoi des ventes hors connexion…'))} (${pending})`;
      } else if (pending) {
        cls = 'warn';
        html = `<i class="bi bi-cloud-arrow-up"></i> ${esc(__('{n} vente(s) à enregistrer sur le serveur.', { n: pending }))} <button type="button" class="btn btn-sm btn-light ms-2" data-net="sync">${esc(__('Envoyer maintenant'))}</button>`;
      }
      if (refused) {
        cls = cls || 'warn';
        html += `${html ? ' · ' : ''}<button type="button" class="btn btn-sm btn-danger ms-1" data-net="refused"><i class="bi bi-exclamation-triangle"></i> ${esc(__('{n} vente(s) refusée(s) — vérifier', { n: refused }))}</button>`;
      }
      el.className = 'net-bar ' + cls + (html ? '' : ' d-none');
      el.innerHTML = html;
    }
    api2.bar = bar;

    // --- ventes refusées : reprise dans le panier, nouvel essai ou suppression confirmée
    function showRefused() {
      const list = api2.queue.filter((x) => x.state === 'refused');
      $('#listTitle').textContent = __('Ventes hors connexion refusées par le serveur');
      $('#listBody').innerHTML = list.map((x) => `<div class="list-group-item">
        <div class="d-flex justify-content-between"><b>${esc(x.number)}</b><span class="fw-bold">${fmt(x.total)}</span></div>
        <small class="text-secondary">${esc(new Date(x.at).toLocaleString('fr-FR'))} · ${x.lines.length} ${esc(__('ligne(s)'))}${x.customer ? ' · ' + esc(x.customer) : ''}</small>
        <div class="text-danger small my-1">${esc(x.error || '')}</div>
        <div class="btn-group btn-group-sm">
          <button class="btn btn-outline-primary" data-off-retry="${x.uuid}"><i class="bi bi-arrow-repeat"></i> ${esc(__('Réessayer'))}</button>
          <button class="btn btn-outline-secondary" data-off-load="${x.uuid}"><i class="bi bi-cart"></i> ${esc(__('Reprendre dans le panier'))}</button>
          <button class="btn btn-outline-danger" data-off-drop="${x.uuid}"><i class="bi bi-trash"></i> ${esc(__('Supprimer'))}</button>
        </div></div>`).join('') || '<div class="p-3 text-secondary">' + esc(__('Aucune vente refusée.')) + '</div>';
      modal('listModal').show();
    }
    api2.onListClick = (e) => {
      const b = e.target.closest('[data-off-retry],[data-off-load],[data-off-drop]');
      if (!b) return false;
      const id = b.dataset.offRetry || b.dataset.offLoad || b.dataset.offDrop;
      const x = byId(read(), id);
      if (!x) { modal('listModal').hide(); bar(); return true; }
      if (b.dataset.offRetry) {
        mutate((q) => { const y = byId(q, id); if (y) { y.state = 'pending'; y.error = null; } });
        modal('listModal').hide(); sync(true);
      } else if (b.dataset.offLoad) {
        if (cart.lines.length && !confirm(__('Le panier actuel sera remplacé. Continuer ?'))) return true;
        // Même identifiant : la vente n'a pas été enregistrée, elle peut être corrigée puis validée
        cart = Object.assign(newCart(), x.cart, { uuid: x.code === 'UUID_TAKEN' ? uuid() : x.uuid });
        selected = cart.lines.length - 1;
        mutate((q) => { const i = q.findIndex((y) => y.uuid === id); if (i >= 0) q.splice(i, 1); });
        modal('listModal').hide(); render(); bar();
        toast(__('Vente reprise : corrigez-la puis validez-la à nouveau (paiement déjà reçu : {m}).', { m: fmt(x.paid) }), 'warning');
      } else if (b.dataset.offDrop) {
        if (!confirm(__('Supprimer définitivement la vente {n} de {m} ? Elle n\'existe que sur cet appareil et ne sera jamais enregistrée.', { n: x.number, m: fmt(x.total) }))) return true;
        mutate((q) => { const i = q.findIndex((y) => y.uuid === id); if (i >= 0) q.splice(i, 1); }); bar();
        if (api2.queue.some((q) => q.state === 'refused')) showRefused(); else modal('listModal').hide();
      }
      return true;
    };

    // --- page et fichiers de la caisse gardés pour un redémarrage sans connexion (HTTPS requis)
    async function warmCache() {
      if (!window.caches || !window.isSecureContext || !('serviceWorker' in navigator)) return;
      try {
        const c = await caches.open(CACHE);
        const here = location.origin;
        const urls = new Set();
        performance.getEntriesByType('resource').forEach((r) => { if (r.name.startsWith(here) && /\/assets\/|\/uploads\/products\//.test(r.name)) urls.add(r.name); });
        $$('script[src],link[rel="stylesheet"]').forEach((n) => urls.add(new URL(n.src || n.href, location.href).href));
        for (const u of urls) { if (!(await c.match(u))) await c.add(u).catch(() => {}); }
        if (!navigator.serviceWorker.controller) {
          // Première visite : le service worker ne contrôlait pas encore la page, on la garde ici
          const page = new URL(BASE + '/pos', location.href).href;
          const res = await fetch(page, { credentials: 'same-origin' });
          if (res.ok && !res.redirected) await c.put(page, res);
        }
        if (navigator.storage && navigator.storage.persist) navigator.storage.persist().catch(() => {});
      } catch (e) { /* non bloquant */ }
    }

    api2.start = () => {
      bar();
      loadCatalog().then(() => { if (!items.length && catalog.length && !online) loadProducts(); });
      setInterval(loadCatalog, 10 * 60 * 1000);
      setInterval(() => { if (navigator.onLine) sync(); }, 20000);
      window.addEventListener('online', () => { api('/pos/ping', { noRedirect: true }).then(() => sync()); });
      window.addEventListener('offline', () => setOnline(false));
      window.addEventListener('storage', (e) => { if (e.key === QKEY) { api2.queue = read(); bar(); } }); // autre onglet
      document.addEventListener('click', (e) => {
        const b = e.target.closest('[data-net]');
        if (!b) return;
        if (b.dataset.net === 'sync') sync(true);
        if (b.dataset.net === 'refused') showRefused();
      });
      // Fermeture du navigateur / de l'application avec des ventes non enregistrées : avertissement
      window.addEventListener('beforeunload', (e) => {
        api2.queue = read();
        if (!api2.queue.length) return;
        save();
        e.preventDefault();
        e.returnValue = __('Des ventes ne sont pas encore enregistrées sur le serveur. Rétablissez la connexion avant de fermer.');
        return e.returnValue;
      });
      setTimeout(warmCache, 2500);
      sync();
    };
    api2.has = (id) => !!byId(read(), id);
    api2.add = (entry) => { if (!mutate((q) => q.push(entry))) return false; api2.lastEntry = entry; bar(); return true; };
    api2.isSyncing = () => syncing;
    api2.lastSync = () => lastSync;
    return api2;
  })();

  function setOnline(v) {
    if (online === v) return;
    online = v;
    Offline.bar();
    emit(v ? 'pos.online' : 'pos.offline', {});
    if (v) Offline.sync();
  }

  // Vente validée sans connexion : gardée sur l'appareil, ticket provisoire, envoi automatique plus tard
  function queueSale(body) {
    const why = Offline.authIssues(body);
    if (why.length) {
      saleError(__('Hors connexion, impossible de faire autoriser : {w}. Retirez-la ou attendez le retour de la connexion.', { w: why.join(', ') }));
      return;
    }
    if (body.prescription && (!body.prescription.prescriber || !body.prescription.patient_name)) {
      if (quickMode) { quickMode = false; openPay(); }
      $('#rxBox').classList.remove('d-none');
      payError(__('Ce panier contient un médicament soumis à ordonnance : renseignez le prescripteur et le patient.'));
      return;
    }
    if (Offline.has(cart.uuid)) cart.uuid = uuid(); // déjà pris par une vente d'un autre onglet
    body.client_uuid = cart.uuid;
    const t = totals();
    const paid = paidSum();
    const now = new Date();
    const pad = (n) => String(n).padStart(2, '0');
    const number = 'HL-' + pad(now.getDate()) + pad(now.getMonth() + 1) + '-' + pad(now.getHours()) + pad(now.getMinutes()) + pad(now.getSeconds()) + '-' + cart.uuid.slice(0, 4).toUpperCase();
    const stamp = now.toLocaleString('fr-FR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
    const mark = `[Vente hors connexion du ${stamp}, ticket provisoire ${number}]`;
    const sendBody = Object.assign({}, body, { supervisor: null, print_ticket: false, note: ((body.note ? body.note + ' ' : '') + mark).slice(0, 255) });
    delete sendBody._csrf;
    const entry = {
      uuid: cart.uuid, number, at: now.toISOString(), state: 'pending', error: null,
      total: t.total, paid, change: Math.max(0, Math.round((paid - t.total) * 100) / 100),
      customer: cart.customer ? cart.customer.name : null,
      lines: cart.lines.map((l) => ({ name: l.name, qty: l.qty, price: unitPriceOf(l), total: unitPriceOf(l) * l.qty - (l.discount || 0) })),
      discount: t.discount, payments: payments.map((p) => ({ method: CFG.methods[p.method] || p.method, provider: p.provider, amount: p.amount })),
      body: sendBody, cart: JSON.parse(JSON.stringify(cart)),
    };
    if (!Offline.add(entry)) { saleError(__('Mémoire du navigateur pleine : la vente N\'EST PAS gardée. Rétablissez la connexion avant de vendre.')); return; }
    Offline.consume(cart.lines);
    if (!quickMode) modal('payModal').hide();
    finishSale({ offline: true, entry, number, change: entry.change, total: entry.total });
  }

  // Ticket provisoire imprimé depuis l'appareil (le ticket définitif est disponible après synchronisation)
  function printProvisional(x) {
    const row = (a, b) => `<tr><td>${a}</td><td style="text-align:right">${b}</td></tr>`;
    const html = `<!doctype html><html><head><meta charset="utf-8"><title>${esc(x.number)}</title><style>
      body{font:12px/1.35 monospace;width:72mm;margin:0 auto;padding:4px}h1{font-size:14px;text-align:center;margin:4px 0}
      table{width:100%;border-collapse:collapse}.c{text-align:center}.b{font-weight:bold}hr{border:0;border-top:1px dashed #000}
      .prov{border:1px solid #000;padding:3px;text-align:center;font-weight:bold;margin:4px 0}</style></head><body>
      <h1>${esc(CFG.shop || 'POS Marwa')}</h1>
      <div class="prov">TICKET PROVISOIRE<br>vente hors connexion</div>
      <div>N° ${esc(x.number)}<br>${esc(new Date(x.at).toLocaleString('fr-FR'))}<br>${esc(__('Caissier'))} : ${esc(CFG.userName || '')}${x.customer ? '<br>' + esc(__('Client')) + ' : ' + esc(x.customer) : ''}</div><hr>
      <table>${x.lines.map((l) => row(esc(l.name) + '<br>' + fmtQ(l.qty) + ' × ' + plain(l.price), plain(l.total))).join('')}</table><hr>
      <table>${x.discount > 0 ? row('Remises', '− ' + plain(x.discount)) : ''}${row('<b>TOTAL</b>', '<b>' + fmt(x.total) + '</b>')}
      ${x.payments.map((p) => row(esc(p.method) + (p.provider ? ' · ' + esc(p.provider) : ''), plain(p.amount))).join('')}${row('Rendu', fmt(x.change))}</table><hr>
      <div class="c">Ce ticket sera remplacé par le ticket définitif<br>après enregistrement sur le serveur.</div>
      </body></html>`;
    let f = $('#printFrame');
    if (f) f.remove();
    f = document.createElement('iframe');
    f.id = 'printFrame'; f.style.cssText = 'position:fixed;width:0;height:0;border:0;right:0;bottom:0';
    // Pas de script dans le ticket (politique de sécurité du site) : l'impression est lancée d'ici
    f.addEventListener('load', () => { try { f.contentWindow.focus(); f.contentWindow.print(); } catch (e) { /* impression indisponible */ } });
    f.srcdoc = html;
    document.body.appendChild(f);
  }

  // ------------------------------------------------------------------ attente / tickets récents
  async function refreshHeld() {
    const d = await api('/pos/held');
    if (d.offline) return null;
    $('#heldCount').textContent = d.ok && d.items.length ? d.items.length : '';
    return d.items || [];
  }
  async function showHeld() {
    const list = await refreshHeld();
    if (list === null) { toast(__('Ventes en attente indisponibles hors connexion.'), 'warning'); return; }
    $('#listTitle').textContent = __('Ventes en attente');
    $('#listBody').innerHTML = list.map((h) => `<button class="list-group-item list-group-item-action d-flex justify-content-between" data-resume="${h.id}"><span><b>${esc(h.label)}</b> · ${h.lines} ${esc(__('ligne(s)'))} <small class="text-secondary">${esc(h.full_name)}</small></span><small>${esc(h.created_at)}</small></button>`).join('') || '<div class="p-3 text-secondary">' + esc(__('Aucune vente en attente.')) + '</div>';
    modal('listModal').show();
  }
  async function showRecent() {
    const d = await api('/pos/recent');
    if (d.offline) { toast(__('Liste des ventes indisponible hors connexion.'), 'warning'); return; }
    $('#listTitle').textContent = __('Mes dernières ventes');
    $('#listBody').innerHTML = (d.items || []).map((s) => `<div class="list-group-item d-flex justify-content-between align-items-center"><span><b>${esc(s.number)}</b> ${s.status === 'cancelled' ? '<span class="badge bg-danger">' + esc(__('annulée')) + '</span>' : ''}<br><small class="text-secondary">${esc(s.created_at)} · ${esc(s.customer || __('comptoir'))}</small></span><span class="fw-bold">${fmt(s.total)}</span><span class="btn-group"><a class="btn btn-sm btn-outline-primary" target="_blank" rel="noopener" href="${BASE}/sales/${s.id}/ticket"><i class="bi bi-printer"></i></a><a class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener" href="${BASE}/sales/${s.id}"><i class="bi bi-eye"></i></a></span></div>`).join('') || '<div class="p-3 text-secondary">' + esc(__('Aucune vente.')) + '</div>';
    modal('listModal').show();
  }
  $('#listBody').addEventListener('click', async (e) => {
    if (Offline.onListClick(e)) return;
    const b = e.target.closest('[data-resume]'); if (!b) return;
    if (cart.lines.length && !confirm(__('Le panier actuel sera remplacé. Continuer ?'))) return;
    const d = await api(`/pos/held/${b.dataset.resume}/resume`, { method: 'POST', body: { _csrf: CFG.csrf } });
    if (!d.ok) { toast(d.error, 'danger'); return; }
    cart = Object.assign(newCart(), d.cart, { uuid: uuid() });
    selected = cart.lines.length - 1;
    modal('listModal').hide(); render(); refreshHeld();
  });
  async function hold() {
    if (!cart.lines.length) { toast(__('Panier vide.'), 'warning'); return; }
    const label = prompt(__('Nom de la vente en attente :'), cart.customer ? cart.customer.name : 'Client ' + new Date().toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }));
    if (label === null) return;
    const d = await api('/pos/hold', { method: 'POST', body: { _csrf: CFG.csrf, label, cart } });
    if (!d.ok) { toast(d.error, 'danger'); return; }
    cart = newCart(); selected = -1; render(); refreshHeld(); toast(__('Vente mise en attente.'), 'success'); emit('pos.cart.held');
  }

  // ------------------------------------------------------------------ actions
  function action(a) {
    switch (a) {
      case 'customer': $('#customerSearch').value = ''; searchCustomers(); modal('customerModal').show(); setTimeout(() => $('#customerSearch').focus(), 300); break;
      case 'quantity': case 'price': case 'discount': openNum(a); break;
      case 'remove': if (selected >= 0) { cart.lines.splice(selected, 1); selected = Math.min(selected, cart.lines.length - 1); render(); } break;
      case 'clear': if (cart.lines.length && confirm(__('Vider le panier ?'))) { cart = newCart(); selected = -1; render(); } break;
      case 'hold': hold(); break;
      case 'held': showHeld(); break;
      case 'recent': showRecent(); break;
      case 'pay': openPay(); break;
      case 'validate': if (CFG.quickValidate) quickValidate(); else openPay(); break;
      case 'credit': openPay({ credit: true }); break;
      case 'cashExact':
        // Fenêtre d'encaissement complète (espèces par défaut, mobile money, carte, paiement mixte)
        if (!cart.lines.length) return;
        openPay(); break;
      case 'note': { const n = prompt(__('Note sur la vente :'), cart.note || ''); if (n !== null) { cart.note = n.slice(0, 255); render(); } break; }
      case 'fullscreen': if (!document.fullscreenElement) document.documentElement.requestFullscreen().catch(() => {}); else document.exitFullscreen(); break;
      case 'lock':
        if (Offline.queue.length && !confirm(__('{n} vente(s) ne sont pas encore enregistrées sur le serveur. Elles restent gardées sur cet appareil et seront envoyées à votre prochaine connexion. Se déconnecter quand même ?', { n: Offline.queue.length }))) break;
        save(); $('form[action$="/logout"]').submit(); break;
      default: break;
    }
  }
  document.addEventListener('click', (e) => { const b = e.target.closest('[data-action]'); if (b) { e.preventDefault(); action(b.dataset.action); } });
  const ws = $('#optWholesale');
  if (ws) ws.addEventListener('change', () => { cart.wholesale = ws.checked; render(); });
  $('#tenderedInput').addEventListener('input', updateChange);
  $('#tenderedInput').addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); action('validate'); } });

  // Raccourcis clavier (configurables dans Paramètres)
  const SC = CFG.shortcuts || {};
  document.addEventListener('keydown', (e) => {
    const open = document.querySelector('.modal.show');
    if (open && open.id === 'doneModal' && e.key === 'Enter') { e.preventDefault(); modal('doneModal').hide(); return; }
    if (open && open.id === 'payModal' && e.key === (SC.pay || 'F9')) { e.preventDefault(); checkout(); return; }
    if (open) return;
    const map = { [SC.search || 'F2']: () => $('#productSearch').focus(), [SC.customer || 'F4']: () => action('customer'), [SC.quantity || 'F6']: () => action('quantity'),
      [SC.discount || 'F7']: () => action('discount'), [SC.hold || 'F8']: () => action('hold'), [SC.pay || 'F9']: () => action('validate'),
      [SC.cashExact || 'F10']: () => action('cashExact'), [SC.clear || 'F12']: () => action('clear') };
    if (map[e.key]) { e.preventDefault(); map[e.key](); return; }
    const tag = (document.activeElement && document.activeElement.tagName) || '';
    if (['INPUT', 'TEXTAREA', 'SELECT'].includes(tag)) return;
    if (selected >= 0 && cart.lines[selected]) {
      if (e.key === '+') { cart.lines[selected].qty += 1; render(); e.preventDefault(); }
      if (e.key === '-' && cart.lines[selected].qty > 1) { cart.lines[selected].qty -= 1; render(); e.preventDefault(); }
      if (e.key === 'Delete') { action('remove'); e.preventDefault(); }
      if (e.key === 'ArrowUp') { selected = Math.max(0, selected - 1); render(); e.preventDefault(); }
      if (e.key === 'ArrowDown') { selected = Math.min(cart.lines.length - 1, selected + 1); render(); e.preventDefault(); }
    }
  });

  // Horloge
  const clock = $('[data-clock]');
  const tick = () => { const d = new Date(); clock.textContent = d.toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit', year: '2-digit' }) + ' ' + d.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }); };
  tick(); setInterval(tick, 10000);

  // Démarrage
  render();
  loadProducts();
  refreshHeld();
  Offline.start();
  if (!$('#closedOverlay')) bi.focus();
})();
