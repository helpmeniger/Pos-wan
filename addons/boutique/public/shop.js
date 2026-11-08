/* =====================================================================
   POS Marwa — boutique en ligne (pages publiques) : catalogue, recherche,
   fiche article, panier (gardé dans le navigateur), commande.
   Les prix et le stock sont toujours revérifiés par le serveur à la commande.
   ===================================================================== */
(function () {
  'use strict';
  const $ = (s, r) => (r || document).querySelector(s);
  const $$ = (s, r) => Array.from((r || document).querySelectorAll(s));
  const json = (id) => { const el = document.getElementById(id); try { return el ? JSON.parse(el.textContent) : null; } catch (e) { return null; } };

  // Impression du reçu (aucun script en ligne : CSP)
  $$('[data-print]').forEach((b) => b.addEventListener('click', () => window.print()));

  const cfg = json('shop-config');
  if (!cfg) return;
  const cur = cfg.currency || { symbol: 'F', position: 'after', decimals: 0 };
  const num = (n, d) => (Number(n) || 0).toLocaleString('fr-FR', { minimumFractionDigits: d, maximumFractionDigits: d }).replace(/ | /g, ' ');
  const money = (n) => (cur.position === 'before' ? cur.symbol + ' ' + num(n, cur.decimals) : num(n, cur.decimals) + ' ' + cur.symbol);
  const qtyTxt = (q) => num(q, Math.abs(q - Math.round(q)) > 0.0001 ? 3 : 0).replace(/,?0+$/, '');
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
  const KEY = 'pm-shop-cart:' + (cfg.base || '/');

  // ------------------------------------------------------------ panier
  let cart = {};
  try { cart = JSON.parse(localStorage.getItem(KEY) || '{}') || {}; } catch (e) { cart = {}; }
  const save = () => { try { localStorage.setItem(KEY, JSON.stringify(cart)); } catch (e) { /* navigation privée */ } };
  const catalog = json('shop-catalog') || [];
  const byId = {};
  catalog.forEach((p) => { byId[p.id] = p; });
  // Sur le catalogue : informations à jour des articles du panier
  Object.keys(cart).forEach((id) => { if (byId[id]) Object.assign(cart[id], { name: byId[id].name, price: byId[id].price, img: byId[id].img, avail: byId[id].avail, decimal: byId[id].decimal }); });

  const lines = () => Object.values(cart).filter((l) => l.qty > 0);
  const subtotal = () => lines().reduce((s, l) => s + l.price * l.qty, 0);
  const count = () => lines().reduce((s, l) => s + (l.decimal ? 1 : l.qty), 0);
  function maxQty(l) { return l.avail === null || l.avail === undefined ? 1000 : l.avail; }
  function setQty(id, q) {
    const l = cart[id];
    if (!l) return;
    q = l.decimal ? Math.round(q * 1000) / 1000 : Math.round(q);
    if (q <= 0) { delete cart[id]; } else {
      const m = maxQty(l);
      if (q > m) { q = m; toast(m > 0 ? `Il reste ${qtyTxt(m)} « ${l.name} »` : `« ${l.name} » est en rupture`); }
      if (q <= 0) delete cart[id]; else l.qty = q;
    }
    save(); refresh();
  }
  function add(p, q) {
    if (!cfg.open) { toast('Les commandes en ligne sont suspendues pour le moment'); return; }
    if (p.avail !== null && p.avail <= 0) { toast('Article en rupture de stock'); return; }
    const l = cart[p.id] || (cart[p.id] = { id: p.id, name: p.name, price: p.price, img: p.img, avail: p.avail, decimal: p.decimal, qty: 0 });
    setQty(p.id, l.qty + q);
    if (cart[p.id]) { toast(`Ajouté : ${p.name}`); $$('[data-cart-count]').forEach((b) => { b.classList.remove('bump'); void b.offsetWidth; b.classList.add('bump'); }); }
  }

  let toastT = null;
  function toast(msg) {
    const t = $('[data-toast]');
    if (!t) return;
    t.textContent = msg; t.classList.add('show');
    clearTimeout(toastT); toastT = setTimeout(() => t.classList.remove('show'), 2200);
  }

  const imgHtml = (p) => (p.img ? `<img src="${esc(p.img)}" alt="" loading="lazy">` : `<div class="shop-ph">${esc((p.name || '?').trim().charAt(0).toUpperCase())}</div>`);
  function stockHtml(p) {
    if (cfg.stock === 'none' || p.avail === null) return '';
    if (p.avail <= 0) return '<div class="shop-stock out"><i class="bi bi-x-circle"></i> Rupture de stock</div>';
    if (cfg.stock === 'qty') return `<div class="shop-stock${p.avail <= 5 ? ' low' : ''}"><i class="bi bi-check-circle"></i> ${qtyTxt(p.avail)} en stock</div>`;
    return p.avail <= 5 ? `<div class="shop-stock low"><i class="bi bi-hourglass-split"></i> Plus que ${qtyTxt(p.avail)}</div>` : '<div class="shop-stock"><i class="bi bi-check-circle"></i> En stock</div>';
  }
  const priceHtml = (p) => `${money(p.price)}${p.old ? `<del>${money(p.old)}</del>` : ''}${p.unit && p.decimal ? ` <small class="text-secondary">/ ${esc(p.unit)}</small>` : ''}`;

  function lineHtml(l) {
    const warn = l.avail !== null && l.avail !== undefined && l.qty > l.avail ? `<div class="shop-line-warn">${l.avail > 0 ? `Plus que ${qtyTxt(l.avail)} disponible(s)` : 'Plus disponible'}</div>` : '';
    return `<div class="shop-line" data-line="${l.id}">
      <div class="shop-line-img">${imgHtml(l)}</div>
      <div class="flex-grow-1 min-w-0"><div class="shop-line-name">${esc(l.name)}</div><div class="small text-secondary">${money(l.price)}</div>${warn}
        <div class="d-flex align-items-center justify-content-between mt-1"><div class="shop-qty"><button type="button" data-dec aria-label="Moins">−</button><input type="number" value="${qtyTxt(l.qty).replace(',', '.').replace(/\s/g, '')}" min="0" step="${l.decimal ? '0.1' : '1'}" data-q aria-label="Quantité"><button type="button" data-inc aria-label="Plus">+</button></div>
        <b class="text-nowrap">${money(l.price * l.qty)}</b></div></div>
      <button type="button" class="shop-del" data-del aria-label="Retirer"><i class="bi bi-trash3"></i></button></div>`;
  }

  function refresh() {
    const n = count();
    $$('[data-cart-count]').forEach((b) => { b.textContent = n > 99 ? '99+' : String(Math.round(n)); b.hidden = n === 0; });
    const tf = $('[data-cart-total-fab]'); if (tf) tf.textContent = n ? money(subtotal()) : '';
    const ls = lines();
    $$('[data-cart-lines]').forEach((box) => {
      box.innerHTML = ls.length ? ls.map(lineHtml).join('') : '<div class="shop-empty py-4"><i class="bi bi-bag"></i><p>Votre panier est vide</p></div>';
    });
    const sub = subtotal();
    $$('[data-cart-total]').forEach((e) => { e.textContent = money(sub); });
    const short = cfg.min > 0 && sub < cfg.min;
    $$('[data-cart-hint]').forEach((e) => { e.textContent = short && ls.length ? `Minimum de commande : ${money(cfg.min)}` : ''; });
    $$('[data-checkout]').forEach((b) => { b.classList.toggle('disabled', !ls.length || short || !cfg.open); });
    // Cartes du catalogue : bouton « Ajouter » ou sélecteur de quantité
    $$('.shop-item').forEach((card) => {
      const id = card.dataset.id; const l = cart[id]; const foot = card.querySelector('[data-foot]');
      if (!foot) return;
      const want = l && l.qty > 0 ? 'qty' : 'add';
      if (foot.dataset.mode === want && want === 'add') return;
      foot.dataset.mode = want;
      const p = byId[id];
      foot.innerHTML = want === 'qty'
        ? `<div class="shop-qty"><button type="button" data-dec aria-label="Moins">−</button><input type="number" value="${qtyTxt(l.qty).replace(',', '.').replace(/\s/g, '')}" min="0" step="${l.decimal ? '0.1' : '1'}" data-q aria-label="Quantité"><button type="button" data-inc aria-label="Plus">+</button></div>`
        : `<button type="button" class="btn btn-shop btn-sm shop-add w-100" data-add ${p && p.avail !== null && p.avail <= 0 ? 'disabled' : ''}><i class="bi bi-bag-plus"></i> Ajouter</button>`;
    });
    if (typeof onRefresh === 'function') onRefresh();
  }
  let onRefresh = null;

  // Boutons +/− et saisie : délégation (catalogue, panier, récapitulatif)
  document.addEventListener('click', (e) => {
    const t = e.target.closest('[data-dec],[data-inc],[data-del],[data-add]');
    if (!t) return;
    const holder = t.closest('[data-line],.shop-item');
    const id = holder && (holder.dataset.line || holder.dataset.id);
    if (!id) return;
    e.stopPropagation();
    if (t.matches('[data-add]')) { const p = byId[id]; if (p) add(p, 1); return; }
    const l = cart[id]; if (!l) return;
    const step = l.decimal ? 0.5 : 1;
    if (t.matches('[data-del]')) setQty(id, 0);
    else setQty(id, l.qty + (t.matches('[data-inc]') ? step : -step));
  }, true);
  document.addEventListener('change', (e) => {
    const inp = e.target.closest('[data-q]');
    if (!inp) return;
    const holder = inp.closest('[data-line],.shop-item');
    const id = holder && (holder.dataset.line || holder.dataset.id);
    if (id) setQty(id, parseFloat(String(inp.value).replace(',', '.')) || 0);
  });
  document.addEventListener('click', (e) => { if (e.target.closest('[data-q]')) e.stopPropagation(); }, true);

  const drawerEl = document.getElementById('shopCart');
  $$('[data-open-cart]').forEach((b) => b.addEventListener('click', () => { if (drawerEl && window.bootstrap) bootstrap.Offcanvas.getOrCreateInstance(drawerEl).show(); }));

  // ------------------------------------------------------------ catalogue
  const gridAll = $('[data-grid="all"]');
  if (gridAll) {
    const cats = json('shop-categories') || [];
    const parentOf = {}; cats.forEach((c) => { parentOf[c.id] = c.parent; });
    const inCat = (p, cat) => !cat || p.cat === cat || p.root === cat || parentOf[p.cat] === cat;
    const norm = (s) => String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
    let state = { cat: 0, q: '', sort: '' };
    const card = (p, i) => `<article class="shop-item" data-id="${p.id}" style="animation-delay:${Math.min(i, 20) * 25}ms" tabindex="0" aria-label="${esc(p.name)}">
        <div class="shop-img">${imgHtml(p)}${p.old ? `<span class="shop-tag">-${Math.round((1 - p.price / p.old) * 100)} %</span>` : ''}${p.avail !== null && p.avail <= 0 ? '<span class="shop-tag out">Épuisé</span>' : ''}</div>
        <div class="shop-body"><div class="shop-title">${esc(p.name)}</div><div class="shop-price">${priceHtml(p)}</div>${stockHtml(p)}<div data-foot class="mt-auto pt-1"></div></div></article>`;
    function render() {
      let list = catalog.filter((p) => inCat(p, state.cat) && (!state.q || norm(p.name + ' ' + p.desc).includes(state.q)));
      if (state.sort) {
        const k = state.sort.replace('-', ''); const dir = state.sort.startsWith('-') ? -1 : 1;
        list = list.slice().sort((a, b) => (k === 'name' ? a.name.localeCompare(b.name, 'fr') : a.price - b.price) * dir);
      }
      gridAll.innerHTML = list.map(card).join('');
      $('[data-no-result]').classList.toggle('d-none', list.length > 0);
      const feat = $('[data-featured-section]');
      if (feat) {
        const show = !state.cat && !state.q;
        feat.classList.toggle('d-none', !show);
        if (show) $('[data-grid="featured"]').innerHTML = catalog.filter((p) => p.featured).slice(0, 10).map(card).join('');
      }
      const title = $('[data-list-title]');
      if (title) title.textContent = state.q ? `Résultats (${list.length})` : (state.cat ? ($(`[data-cat="${state.cat}"]`) || {}).textContent || 'Articles' : 'Tous les articles');
      refresh();
    }
    $$('[data-cat]').forEach((b) => b.addEventListener('click', () => {
      $$('[data-cat]').forEach((x) => x.classList.toggle('active', x === b));
      state.cat = Number(b.dataset.cat) || 0; render();
    }));
    let qt = null;
    $$('[data-search]').forEach((inp) => inp.addEventListener('input', () => {
      $$('[data-search]').forEach((o) => { if (o !== inp) o.value = inp.value; });
      clearTimeout(qt); qt = setTimeout(() => { state.q = norm(inp.value.trim()); render(); }, 150);
    }));
    const sortSel = $('[data-sort]'); if (sortSel) sortSel.addEventListener('change', () => { state.sort = sortSel.value; render(); });

    // Fiche article
    const modalEl = document.getElementById('shopItem');
    let current = null;
    function openItem(id) {
      const p = byId[id]; if (!p || !modalEl || !window.bootstrap) return;
      current = p;
      $('[data-m-img]').innerHTML = imgHtml(p);
      $('[data-m-name]').textContent = p.name;
      $('[data-m-price]').innerHTML = priceHtml(p);
      $('[data-m-stock]').innerHTML = stockHtml(p);
      $('[data-m-desc]').textContent = p.desc || '';
      const q = $('[data-m-qty]'); q.value = 1; q.step = p.decimal ? '0.1' : '1';
      $('[data-m-add]').disabled = !cfg.open || (p.avail !== null && p.avail <= 0);
      bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }
    document.addEventListener('click', (e) => {
      const c = e.target.closest('.shop-item');
      if (c && !e.target.closest('[data-foot]')) openItem(c.dataset.id);
    });
    document.addEventListener('keydown', (e) => { const c = e.target.closest && e.target.closest('.shop-item'); if (c && e.key === 'Enter') openItem(c.dataset.id); });
    if (modalEl) {
      const q = $('[data-m-qty]');
      $('[data-m-minus]').addEventListener('click', () => { q.value = Math.max(current && current.decimal ? 0.1 : 1, (parseFloat(q.value) || 1) - 1); });
      $('[data-m-plus]').addEventListener('click', () => { q.value = (parseFloat(q.value) || 0) + 1; });
      $('[data-m-add]').addEventListener('click', () => { if (current) { add(current, parseFloat(q.value) || 1); bootstrap.Modal.getInstance(modalEl).hide(); } });
    }
    render();
  }

  // ------------------------------------------------------------ commande
  const form = $('[data-checkout-form]');
  if (form) {
    const addr = $('[data-address]');
    const fee = () => (cfg.delivery && form.delivery && form.delivery.value === 'delivery' ? cfg.fee : 0);
    onRefresh = () => {
      const empty = !lines().length;
      $('[data-empty]').classList.toggle('d-none', !empty);
      form.classList.toggle('d-none', empty);
      const sub = subtotal(); const f = fee();
      $('[data-sub]').textContent = money(sub);
      $('[data-fee-row]').classList.toggle('d-none', !f);
      $('[data-fee]').textContent = money(f);
      $('[data-grand]').textContent = money(sub + f);
      $('[data-grand2]').textContent = money(sub + f);
    };
    $$('input[name="delivery"]', form).forEach((r) => r.addEventListener('change', () => { addr.classList.toggle('d-none', form.delivery.value !== 'delivery'); onRefresh(); }));
    // Coordonnées retenues pour la prochaine commande (sur cet appareil seulement)
    try { const me = JSON.parse(localStorage.getItem('pm-shop-me') || '{}'); ['name', 'phone', 'email', 'address'].forEach((k) => { if (me[k] && form[k]) form[k].value = me[k]; }); } catch (e) { /* rien */ }
    // Prix et disponibilités à jour avant de commander
    const ids = Object.keys(cart);
    if (ids.length) {
      fetch(`${cfg.base}/boutique/stock?ids=${ids.join(',')}`, { headers: { Accept: 'application/json' }, cache: 'no-store' })
        .then((r) => r.json()).then((d) => {
          Object.keys(cart).forEach((id) => {
            const s = d.items && d.items[id];
            if (!s) { cart[id].avail = 0; return; }
            Object.assign(cart[id], { price: s.price, avail: s.avail, name: s.name });
          });
          cfg.open = d.open; save(); refresh();
        }).catch(() => { /* hors connexion : le serveur revérifiera */ });
    }
    const err = $('[data-error]');
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      err.classList.add('d-none');
      if (!form.reportValidity()) return;
      const btn = $('[data-submit]');
      btn.disabled = true;
      const old = btn.innerHTML;
      btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Envoi de la commande…';
      const body = { _csrf: cfg.csrf, name: form.name.value, phone: form.phone.value, email: form.email.value, delivery: form.delivery ? form.delivery.value : 'pickup',
        address: form.address.value, note: form.note.value, website: form.website.value, items: lines().map((l) => ({ id: l.id, qty: l.qty })) };
      try {
        const r = await fetch(`${cfg.base}/boutique/commande`, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': cfg.csrf }, body: JSON.stringify(body) });
        const d = await r.json().catch(() => ({ ok: false, error: r.status === 419 ? 'Page expirée : rechargez la page puis validez de nouveau.' : 'Réponse invalide du serveur.' }));
        if (!d.ok) throw new Error(d.error || 'Commande impossible.');
        try { localStorage.setItem('pm-shop-me', JSON.stringify({ name: body.name, phone: body.phone, email: body.email, address: body.address })); } catch (x) { /* rien */ }
        cart = {}; save();
        location.href = d.url;
      } catch (x) {
        err.textContent = x.message || 'Connexion impossible : vérifiez Internet puis réessayez.';
        err.classList.remove('d-none');
        btn.disabled = false; btn.innerHTML = old;
        err.scrollIntoView({ behavior: 'smooth', block: 'center' });
      }
    });
  }
  refresh();
})();
