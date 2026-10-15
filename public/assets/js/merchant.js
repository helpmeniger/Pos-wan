/* =====================================================================
   MARWA — espace restaurant / commerce
   ===================================================================== */
(function () {
  'use strict';
  const MW = window.MW;
  const DAYS = ['', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];

  async function overview(el) {
    const d = await MW.get('/merchant/store');
    const s = d.store;
    el.innerHTML = `<div class="container">
      ${s.status !== 'verified' ? `<div class="alert alert-warn"><strong>Commerce ${MW.esc(MW.L.verif[s.status])}.</strong> Complétez votre catalogue et vos paramètres : l'équipe MARWA validera votre établissement.</div>` : ''}
      <div class="online-toggle ${s.is_open ? 'on' : ''}"><div><div style="font-size:1.2rem;font-weight:800">${MW.esc(s.name)} — ${s.is_open ? 'Ouvert' : 'Fermé'}</div><div class="small">${s.open_now ? 'Visible et commandable par les clients' : 'Non commandable actuellement (fermé, hors horaires ou en attente de validation)'}</div></div>
        <label class="switch"><input type="checkbox" data-open ${s.is_open ? 'checked' : ''} ${s.status !== 'verified' ? 'disabled' : ''}><span></span></label></div>
      <div class="grid-4" style="margin-top:14px">
        <div class="stat highlight"><p class="label">À confirmer</p><div class="value">${MW.num(d.stats.pending)}</div><div class="sub"><a href="#/commandes" style="color:#fff">Voir</a></div></div>
        <div class="stat"><p class="label">En préparation</p><div class="value">${MW.num(d.stats.in_preparation)}</div></div>
        <div class="stat"><p class="label">Commandes du jour</p><div class="value">${MW.num(d.stats.today)}</div></div>
        <div class="stat"><p class="label">Ventes réglées aujourd'hui</p><div class="value">${MW.fcfa(d.stats.sales_today)}</div></div></div>
      <div class="card" style="margin-top:14px"><h2>Livraison</h2><p>${s.delivery_mode === 'marwa' ? 'Les livreurs MARWA sont sollicités automatiquement en fonction de votre temps de préparation.' : 'Vous livrez avec vos propres moyens : vous validez vous-même la récupération et la livraison (code client).'}</p><a href="#/parametres">Modifier les paramètres</a></div></div>`;
    el.querySelector('[data-open]').addEventListener('change', async (e) => { try { await MW.post('/merchant/store/open', { is_open: e.target.checked }); overview(el); } catch (err) { e.target.checked = !e.target.checked; MW.error(err); } });
  }

  // ------------------------------------------------------------------ Commandes
  async function orders(el, params) {
    const filter = params.filtre || 'active';
    el.innerHTML = `<div class="container"><div class="page-head"><h1>Commandes</h1><div class="segmented" style="min-width:300px">
      ${[['active', 'En cours'], ['done', 'Terminées'], ['cancelled', 'Annulées']].map(([k, l]) => `<a class="${filter === k ? 'active' : ''}" style="flex:1;text-align:center;padding:9px;border-radius:9px;text-decoration:none;font-weight:700;color:inherit" href="#/commandes?filtre=${k}">${l}</a>`).join('')}</div></div><div data-board></div></div>`;
    const load = async () => {
      const list = await MW.get('/merchant/orders?filter=' + filter, { retries: 0 }).catch(() => null);
      if (!list) return;
      const board = el.querySelector('[data-board]'); if (!board) return;
      if (filter !== 'active') {
        board.innerHTML = MW.table([{ key: 'reference', label: 'Commande' }, { key: 'created_at', label: 'Date', fmt: (r) => MW.datetime(r.created_at) },
          { key: 'customer', label: 'Client', fmt: (r) => MW.esc(r.customer ? r.customer.name : '') }, { key: 'status', label: 'Statut', fmt: (r) => MW.statusBadge(r.status) },
          { key: 'items_subtotal', label: 'Articles', num: true, fmt: (r) => MW.fcfa(r.items_subtotal) }, { key: 'net', label: 'Net commerce', num: true, fmt: (r) => r.settlement ? MW.fcfa(r.settlement.net) : '—' }], list, { empty: 'Aucune commande.', onRow: true });
        MW.bindRows(board, list, detail);
        return;
      }
      const col = (title, sts) => `<div class="board-col"><h3>${title} (${list.filter((o) => sts.includes(o.status)).length})</h3>${list.filter((o) => sts.includes(o.status)).map(ticket).join('') || '<div class="small muted" style="padding:6px">—</div>'}</div>`;
      board.innerHTML = `<div class="board">${col('À confirmer', ['pending'])}${col('En préparation', ['accepted', 'preparing'])}${col('Prêtes', ['ready'])}${col('En livraison', ['picked_up', 'in_delivery', 'delivery_failed', 'returning'])}</div>`;
      board.querySelectorAll('[data-open]').forEach((b) => b.addEventListener('click', () => detail(list.find((o) => String(o.id) === b.dataset.open))));
      board.querySelectorAll('[data-act]').forEach((b) => b.addEventListener('click', () => action(list.find((o) => String(o.id) === b.dataset.id), b.dataset.act, b)));
    };
    const ticket = (o) => `<div class="order-ticket"><div class="row between"><strong>${MW.esc(o.reference)}</strong><span class="small muted">${MW.time(o.created_at)}</span></div>
      <div class="small">${(o.items || []).map((i) => `${i.quantity}× ${MW.esc(i.name)}${i.options.length ? ` <span class="muted">(${MW.esc(i.options.map((x) => x.name).join(', '))})</span>` : ''}`).join('<br>')}</div>
      ${o.notes ? `<div class="small" style="margin-top:4px"><strong>Note :</strong> ${MW.esc(o.notes)}</div>` : ''}
      <div class="row between" style="margin-top:6px"><strong>${MW.fcfa(o.items_subtotal)}</strong>${MW.statusBadge(o.status)}</div>
      ${o.provider ? `<div class="small" style="margin-top:4px">${MW.icon('moto')} ${MW.esc(o.provider.name)} — ${MW.esc({ assigned: 'affecté', en_route_pickup: 'en route', arrived_pickup: 'arrivé', picked_up: 'parti' }[o.provider_stage] || '')}</div>`
        : (['accepted', 'preparing', 'ready'].includes(o.status) && o.merchant && o.merchant.delivery_mode === 'marwa' ? `<div class="small muted" style="margin-top:4px">${o.dispatch_status === 'searching' ? 'Recherche d\'un livreur…' : o.dispatch_status === 'no_provider' ? '<span style="color:var(--red-600)">Aucun livreur disponible</span>' : 'Livreur sollicité selon le temps de préparation'}</div>` : '')}
      <div class="row" style="margin-top:8px;gap:6px">${actionButtons(o)}<button class="btn btn-sm btn-ghost" data-open="${o.id}">Détails</button></div></div>`;
    const actionButtons = (o) => {
      const a = o.actions || [];
      const map = { accepted: ['accept', 'Accepter', 'btn-primary'], refused: ['refuse', 'Refuser', 'btn-outline'], preparing: ['preparing', 'En préparation', 'btn-primary'], ready: ['ready', 'Prête', 'btn-accent'],
        picked_up: ['picked_up', 'Partie en livraison', 'btn-primary'], delivered: ['delivered', 'Livrée', 'btn-primary'] };
      return a.filter((x) => map[x]).map((x) => `<button class="btn btn-sm ${map[x][2]}" data-act="${map[x][0]}" data-id="${o.id}">${map[x][1]}</button>`).join('');
    };
    const action = async (o, act, btn) => {
      const post = (extra = {}) => MW.post(`/merchant/orders/${o.id}/action`, Object.assign({ action: act }, extra)).then(load);
      if (act === 'accept') return MW.formModal('Accepter ' + o.reference, [{ name: 'prep_time_min', label: 'Temps de préparation (minutes)', type: 'number', required: true, value: 20, min: 1, max: 240 }], (v) => post(v), { ok: 'Accepter' });
      if (act === 'refuse') { const reason = await MW.confirm('Refuser cette commande ? Le client sera prévenu.', { reason: 'Motif (rupture, fermeture…)', reasonRequired: true, danger: true, ok: 'Refuser' }); if (reason) post({ reason }); return; }
      if (act === 'delivered') return MW.formModal('Confirmer la livraison', [{ name: 'delivery_code', label: 'Code donné par le client', required: true, maxlength: 4 }].concat(o.payment_method === 'cash' ? [{ name: 'amount_collected', label: 'Montant encaissé (FCFA)', type: 'number', value: o.total, required: true }] : []), (v) => post(v), { ok: 'Confirmer' });
      MW.busy(btn, () => post());
    };
    const detail = (o) => {
      const m = MW.modal('Commande ' + o.reference, `<dl class="kv"><dt>Statut</dt><dd>${MW.statusBadge(o.status)}</dd><dt>Client</dt><dd>${MW.esc(o.customer ? o.customer.name : '')}</dd>
        <dt>Livraison</dt><dd>${MW.esc(o.dropoff.text)}</dd><dt>Paiement</dt><dd>${MW.esc(MW.L.payment[o.payment_method])} · ${MW.esc(MW.L.payStatus[o.payment_status])}</dd>
        ${(o.items || []).map((i) => `<dt>${i.quantity} × ${MW.esc(i.name)}</dt><dd>${MW.fcfa(i.line_total)}</dd>`).join('')}<dt>Sous-total</dt><dd>${MW.fcfa(o.items_subtotal)}</dd>
        ${o.discount ? `<dt>Réduction</dt><dd>−${MW.fcfa(o.discount)}</dd>` : ''}${o.settlement ? `<dt>Commission MARWA</dt><dd>${MW.fcfa(o.settlement.commission)}</dd><dt>Net commerce</dt><dd>${MW.fcfa(o.settlement.net)}</dd>` : ''}</dl>
        <h3 style="margin-top:14px">Historique</h3><ul class="timeline">${(o.history || []).map((h) => `<li><strong>${MW.esc(h.label)}</strong>${h.note ? ` — ${MW.esc(h.note)}` : ''}<div class="t-time">${MW.datetime(h.created_at)}</div></li>`).join('')}</ul>
        ${['accepted', 'preparing', 'ready', 'picked_up', 'in_delivery'].includes(o.status) ? '<h3>Messagerie</h3><div data-chat></div>' : ''}
        ${(o.actions || []).includes('cancelled') ? '<button class="btn btn-danger btn-sm" data-cancel style="margin-top:12px">Annuler la commande</button>' : ''}`, { wide: true });
      const c = m.el.querySelector('[data-chat]'); if (c) MW.chat(c, o.id);
      m.el.querySelector('[data-cancel]')?.addEventListener('click', async () => { const r = await MW.confirm('Annuler cette commande acceptée ?', { reason: 'Motif', reasonRequired: true, danger: true }); if (r) { await MW.post(`/merchant/orders/${o.id}/action`, { action: 'cancel', reason: r }); m.close(); load(); } });
    };
    await load();
    const t = setInterval(load, 10000);
    MW.realtime.setFast(true);
    const onEv = (ev) => { if (['order_updated', 'notification'].includes(ev.type)) load(); };
    MW.realtime.on(onEv);
    MW.onLeave(() => { clearInterval(t); MW.realtime.setFast(false); });
  }

  // ------------------------------------------------------------------ Catalogue
  async function catalog(el) {
    const d = await MW.get('/merchant/catalog');
    const catName = (id) => (d.categories.find((c) => c.id === id) || {}).name || '—';
    el.innerHTML = `<div class="container"><div class="page-head"><h1>Catalogue</h1><div class="row">
        <button class="btn btn-outline" data-cat>${MW.icon('plus')} Catégorie</button><button class="btn btn-outline" data-import>Importer (CSV)</button><button class="btn btn-primary" data-prod>${MW.icon('plus')} Produit</button></div></div>
      <div class="card" style="margin-bottom:14px"><strong>Catégories :</strong> ${d.categories.map((c) => `<span class="chip">${c.photo_file_id ? '🖼 ' : ''}${MW.esc(c.name)} <button class="btn-ghost" style="border:0;background:none;cursor:pointer" data-catphoto="${c.id}" aria-label="Photo de la catégorie">📷</button><button class="btn-ghost" style="border:0;background:none;cursor:pointer" data-delcat="${c.id}" aria-label="Supprimer">×</button></span>`).join(' ') || '<span class="muted">aucune</span>'}</div>
      <div class="grid-2">${d.products.map((p) => `<div class="product-card"><div class="thumb" style="position:relative;${p.photo_url ? `background-image:url('${MW.esc(p.photo_url)}')` : ''}" data-edit="${p.id}">${p.photo_url ? (p.photos.length > 1 ? `<span class="thumb-count">${p.photos.length}</span>` : '') : MW.icon('food')}</div>
        <div class="grow"><strong>${MW.esc(p.name)}</strong> ${Number(p.is_available) ? '<span class="badge green">Disponible</span>' : '<span class="badge red">Rupture</span>'}
          <div class="small muted">${MW.esc(catName(p.category_id))}${p.stock_qty !== null ? ' · stock ' + p.stock_qty : ''} · ${p.options.length} option(s)</div>
          <div><strong>${MW.fcfa(p.promo_price ?? p.price)}</strong>${p.promo_price ? `<span class="price-old">${MW.fcfa(p.price)}</span>` : ''}</div></div>
        <div class="stack" style="flex:none"><button class="btn btn-sm btn-outline" data-edit="${p.id}">Modifier</button><button class="btn btn-sm btn-ghost" data-toggle="${p.id}">${Number(p.is_available) ? 'Rupture' : 'Remettre'}</button></div></div>`).join('') || MW.empty('Ajoutez vos premiers produits.', 'food')}</div></div>`;
    const reload = () => catalog(el);
    el.querySelector('[data-cat]').addEventListener('click', () => MW.formModal('Nouvelle catégorie', [{ name: 'name', label: 'Nom', required: true, maxlength: 80 }, { name: 'sort_order', label: 'Ordre', type: 'number', value: 0 }], async (v) => { await MW.post('/merchant/categories', v); reload(); }));
    el.querySelectorAll('[data-catphoto]').forEach((b) => b.addEventListener('click', () => {
      const c = d.categories.find((x) => String(x.id) === b.dataset.catphoto);
      const md = MW.modal('Photo de la catégorie « ' + c.name + ' »', '<div data-img></div>');
      MW.singleImage(md.el.querySelector('[data-img]'), { label: 'Image affichée dans le menu', url: c.photo_file_id ? `${MW.base}/fichiers/${c.photo_file_id}` : null, path: `/merchant/categories/${c.id}/photo`, shape: 'wide', onDone: () => { md.close(); reload(); } });
    }));
    el.querySelectorAll('[data-delcat]').forEach((b) => b.addEventListener('click', async () => { if (await MW.confirm('Supprimer cette catégorie ?', { danger: true })) { await MW.del('/merchant/categories/' + b.dataset.delcat); reload(); } }));
    el.querySelectorAll('[data-toggle]').forEach((b) => b.addEventListener('click', () => MW.busy(b, async () => { const p = d.products.find((x) => String(x.id) === b.dataset.toggle); await MW.post(`/merchant/products/${p.id}/availability`, { is_available: !Number(p.is_available) }); reload(); })));
    const edit = (p = {}) => {
      const m = MW.formModal(p.id ? 'Modifier ' + p.name : 'Nouveau produit', [
        { name: 'name', label: 'Nom', required: true, value: p.name, maxlength: 120 },
        { name: 'category_id', label: 'Catégorie', type: 'select', value: p.category_id, options: [['', '—'], ...d.categories.map((c) => [c.id, c.name])] },
        { name: 'description', label: 'Description', type: 'textarea', value: p.description, maxlength: 500 },
        { name: 'price', label: 'Prix (FCFA)', type: 'number', required: true, value: p.price, min: 1 },
        { name: 'promo_price', label: 'Prix promotionnel (facultatif)', type: 'number', value: p.promo_price },
        { name: 'stock_qty', label: 'Stock (si suivi du stock activé)', type: 'number', value: p.stock_qty, min: 0 },
        { name: 'is_available', label: 'Disponible', type: 'checkbox', value: p.id ? Number(p.is_available) : true },
        { html: '<div data-photos></div>' },
        ...(p.id ? [{ html: `<h3>Variantes et suppléments</h3><div class="list">${p.options.map((o) => `<div class="list-item"><div class="grow">${MW.esc(o.group_name)} · ${MW.esc(o.name)} <span class="muted">(${o.kind === 'variant' ? 'variante' : 'supplément'}, ${o.price_delta >= 0 ? '+' : ''}${MW.fcfa(o.price_delta)})</span></div><button type="button" class="btn btn-sm btn-ghost" data-delopt="${o.id}">Supprimer</button></div>`).join('') || '<p class="small muted">Aucune option.</p>'}</div>
          <button type="button" class="btn btn-sm btn-outline" data-addopt>${MW.icon('plus')} Ajouter une option</button>
          <hr class="divider"><button type="button" class="btn btn-sm btn-danger" data-delprod>Supprimer le produit</button>` }] : [])],
      async (v, form) => {
        v.category_id = v.category_id ? Number(v.category_id) : null;
        const r = p.id ? await MW.put('/merchant/products/' + p.id, v) : await MW.post('/merchant/products', v);
        if (photos.pending.length) await photos.flush(r.data.id);
        photos.changed = false;
        MW.toast('Produit enregistré.', 'success'); reload();
      }, { wide: true, onClose: () => { if (photos && photos.changed) reload(); } });
      const photos = new MW.PhotoManager(m.el.querySelector('[data-photos]'), { productId: p.id || null, photos: p.photos || [], max: d.max_photos || 6 });
      m.el.querySelector('[data-addopt]')?.addEventListener('click', () => MW.formModal('Nouvelle option', [
        { name: 'kind', label: 'Type', type: 'select', options: [['extra', 'Supplément (cumulable)'], ['variant', 'Variante (un seul choix par groupe)']] },
        { name: 'group_name', label: 'Groupe (ex. Taille, Accompagnement)', required: true, maxlength: 60 }, { name: 'name', label: 'Nom', required: true, maxlength: 80 },
        { name: 'price_delta', label: 'Supplément de prix (FCFA)', type: 'number', value: 0, required: true }],
      async (v) => { await MW.post(`/merchant/products/${p.id}/options`, v); m.close(); reload(); }));
      m.el.querySelectorAll('[data-delopt]').forEach((b) => b.addEventListener('click', async () => { await MW.del(`/merchant/products/${p.id}/options/${b.dataset.delopt}`); m.close(); reload(); }));
      m.el.querySelector('[data-delprod]')?.addEventListener('click', async () => { if (await MW.confirm('Supprimer ce produit du catalogue ?', { danger: true })) { await MW.del('/merchant/products/' + p.id); m.close(); reload(); } });
    };
    el.querySelector('[data-prod]').addEventListener('click', () => edit());
    el.querySelectorAll('[data-edit]').forEach((b) => b.addEventListener('click', () => edit(d.products.find((x) => String(x.id) === b.dataset.edit))));
    el.querySelector('[data-import]').addEventListener('click', () => MW.formModal('Importer des produits (CSV)', [
      { html: '<p class="small">Colonnes : <code>nom;categorie;prix;description;prix_promo</code> — une ligne par produit, séparateur « ; » ou « , ». La première ligne peut être un en-tête.</p><div class="field" data-field="file"><input type="file" accept=".csv,text/csv" class="input" data-csv></div>' }],
    async (v, form) => {
      const f = form.querySelector('[data-csv]').files[0]; if (!f) throw new MW.ApiError(422, { error: { message: 'Choisissez un fichier CSV.' } });
      const fd = new FormData(); fd.append('file', f);
      const r = await MW.api('POST', '/merchant/products/import', fd);
      MW.toast(`${r.data.created} produit(s) importé(s).${r.data.errors.length ? ' ' + r.data.errors.length + ' ligne(s) ignorée(s).' : ''}`, 'success', 7000); reload();
    }, { ok: 'Importer' }));
  }

  // ------------------------------------------------------------------ Finances, rapports
  async function finance(el) {
    const d = await MW.get('/merchant/finance');
    const s = d.summary;
    el.innerHTML = `<div class="container"><div class="page-head"><h1>Finances</h1><button class="btn btn-primary" data-payout ${s.available > 0 ? '' : 'disabled'}>Demander un versement</button></div>
      <div class="grid-4"><div class="stat highlight"><p class="label">Disponible</p><div class="value">${MW.fcfa(s.available)}</div><div class="sub">solde dû par MARWA</div></div>
        <div class="stat"><p class="label">Ventes réglées</p><div class="value">${MW.fcfa(s.gross)}</div><div class="sub">${s.orders} commande(s)</div></div>
        <div class="stat"><p class="label">Commissions MARWA</p><div class="value">${MW.fcfa(s.commission)}</div></div>
        <div class="stat"><p class="label">À reverser à MARWA</p><div class="value">${MW.fcfa(s.owed_to_marwa)}</div><div class="sub">espèces encaissées en livraison propre</div></div></div>
      <div class="grid-2" style="margin-top:14px"><div><h2>Relevé</h2>${MW.table([{ key: 'occurred_at', label: 'Date', fmt: (r) => MW.datetime(r.occurred_at) },
        { key: 'memo', label: 'Opération', fmt: (r) => `${MW.esc(r.memo || r.description)}${r.order_reference ? `<br><span class="small muted">${MW.esc(r.order_reference)}</span>` : ''}` },
        { key: 'credit', label: 'Crédit', num: true, fmt: (r) => r.credit > 0 ? MW.fcfa(r.credit) : '' }, { key: 'debit', label: 'Débit', num: true, fmt: (r) => r.debit > 0 ? MW.fcfa(r.debit) : '' }], d.statement, { empty: 'Aucune opération.' })}</div>
      <div><h2>Versements</h2>${MW.table([{ key: 'requested_at', label: 'Date', fmt: (r) => MW.date(r.requested_at) }, { key: 'amount', label: 'Montant', num: true, fmt: (r) => MW.fcfa(r.amount) },
        { key: 'status', label: 'Statut', fmt: (r) => MW.esc({ requested: 'Demandé', approved: 'Approuvé', paid: 'Payé', rejected: 'Rejeté', cancelled: 'Annulé' }[r.status]) }, { key: 'reference', label: 'Réf.', fmt: (r) => MW.esc(r.reference || '') }], d.payouts, { empty: 'Aucun versement.' })}</div></div></div>`;
    el.querySelector('[data-payout]').addEventListener('click', () => MW.formModal('Demande de versement', [
      { name: 'amount', label: `Montant (max. ${MW.fcfa(s.available)})`, type: 'number', required: true, max: s.available, min: 500 },
      { name: 'method', label: 'Moyen', type: 'select', options: [['bank_transfer', 'Virement bancaire'], ['mobile_money', 'Mobile Money'], ['cash', 'Espèces']] },
      { name: 'destination', label: 'Compte / numéro de destination', maxlength: 120 }],
    async (v) => { await MW.post('/merchant/payouts', v); MW.toast('Demande envoyée.', 'success'); finance(el); }));
  }

  async function reports(el, params) {
    const today = new Date(); const from = params.from || MW.isoDate(new Date(today.getFullYear(), today.getMonth(), 1)); const to = params.to || MW.isoDate(today);
    const d = await MW.get(`/merchant/report?from=${from}&to=${to}`);
    const q = `from=${from}&to=${to}`;
    el.innerHTML = `<div class="container"><div class="page-head"><h1>Rapports de ventes</h1><div class="row">
      <a class="btn btn-outline btn-sm" href="${MW.base}/api/merchant/report?${q}&format=csv">CSV</a><a class="btn btn-outline btn-sm" href="${MW.base}/api/merchant/report?${q}&format=xlsx">Excel</a><a class="btn btn-outline btn-sm" href="${MW.base}/api/merchant/report?${q}&format=pdf">PDF</a></div></div>
      <form class="card row" data-f style="margin-bottom:14px"><label>Du <input class="input" type="date" name="from" value="${from}"></label><label>au <input class="input" type="date" name="to" value="${to}"></label><button class="btn btn-primary">Afficher</button></form>
      ${MW.table([{ key: 'label', label: 'Jour' }, { key: 'orders', label: 'Commandes', num: true }, { key: 'sales', label: 'Ventes', num: true, money: true, fmt: (r) => MW.fcfa(r.sales) },
        { key: 'commission', label: 'Commission', num: true, money: true, fmt: (r) => MW.fcfa(r.commission) }, { key: 'net', label: 'Net', num: true, money: true, fmt: (r) => MW.fcfa(r.net) }], d.rows, { totals: d.totals.label ? d.totals : null, empty: 'Aucune vente réglée sur la période.' })}
      <div class="grid-2" style="margin-top:14px"><div class="card"><h2>Meilleures ventes</h2>${d.top_products.map((p) => `<div class="list-item"><div class="grow">${MW.esc(p.name)}<div class="meta">${MW.num(p.qty)} vendus</div></div><strong>${MW.fcfa(p.revenue)}</strong></div>`).join('') || MW.empty('—')}</div>
        <div class="card"><h2>Annulations</h2><dl class="kv"><dt>Refusées</dt><dd>${d.cancellations.refused || 0}</dd><dt>Annulées</dt><dd>${d.cancellations.cancelled || 0}</dd><dt>Expirées (sans réponse)</dt><dd>${d.cancellations.expired || 0}</dd></dl></div></div></div>`;
    el.querySelector('[data-f]').addEventListener('submit', (e) => { e.preventDefault(); const v = MW.values(e.target); location.hash = `#/rapports?from=${v.from}&to=${v.to}`; });
  }

  async function couriers(el) {
    const d = await MW.get('/merchant/couriers');
    el.innerHTML = `<div class="container"><div class="page-head"><h1>Livreurs</h1><button class="btn btn-primary" data-link>${MW.icon('plus')} Rattacher un livreur</button></div>
      <div class="alert alert-info">Pour protéger la vie privée, la position GPS des livreurs n'est visible que sur la carte de suivi d'une commande en préparation qui leur est affectée.</div>
      <div class="grid-2"><div><h2>Livreurs internes</h2>${MW.table([{ key: 'full_name', label: 'Nom' }, { key: 'verification_status', label: 'Compte', fmt: (r) => MW.esc(MW.L.verif[r.verification_status]) },
        { key: 'is_online', label: 'Disponibilité', fmt: (r) => Number(r.is_online) ? (r.current_order_id ? '<span class="badge amber">En course</span>' : '<span class="badge green">En ligne</span>') : '<span class="badge gray">Hors ligne</span>' },
        { key: 'id', label: '', fmt: (r) => `<button class="btn btn-sm btn-ghost" data-unlink="${r.id}">Détacher</button>` }], d.internal, { empty: 'Aucun livreur interne.' })}</div>
      <div><h2>Livreurs MARWA récents</h2>${MW.table([{ key: 'full_name', label: 'Nom' }, { key: 'deliveries', label: 'Livraisons', num: true }, { key: 'last_delivery', label: 'Dernière', fmt: (r) => MW.datetime(r.last_delivery) }, { key: 'rating_avg', label: 'Note', fmt: (r) => Number(r.rating_avg).toFixed(1) }], d.recent, { empty: '—' })}</div></div></div>`;
    el.querySelector('[data-link]').addEventListener('click', () => MW.formModal('Rattacher un livreur', [{ name: 'phone', label: 'Téléphone du livreur (compte MARWA existant)', type: 'tel', required: true }], async (v) => { await MW.post('/merchant/couriers', v); couriers(el); }));
    el.querySelectorAll('[data-unlink]').forEach((b) => b.addEventListener('click', async () => { if (await MW.confirm('Détacher ce livreur ?')) { await MW.del('/merchant/couriers/' + b.dataset.unlink); couriers(el); } }));
  }

  // ------------------------------------------------------------------ Paramètres
  async function settings(el) {
    const d = await MW.get('/merchant/store');
    const s = d.store;
    let hours = d.hours.map((h) => ({ weekday: Number(h.weekday), open_time: h.open_time.slice(0, 5), close_time: h.close_time.slice(0, 5) }));
    let closures = d.closures.map((c) => ({ closed_on: c.closed_on, reason: c.reason }));
    el.innerHTML = `<div class="container"><div class="page-head"><h1>Paramètres du commerce</h1></div>
      <form class="grid-2" data-form novalidate><div class="card"><h2>Informations</h2>
        <div class="form-row"><div data-logo></div><div data-cover></div></div>
        ${MW.fields([{ name: 'name', label: 'Nom commercial', required: true, value: s.name, maxlength: 150 }, { name: 'cuisine', label: 'Type de cuisine / activité', value: s.cuisine, maxlength: 80 },
          { name: 'description', label: 'Description', type: 'textarea', value: s.description, maxlength: 500 }, { name: 'phone', label: 'Téléphone', type: 'tel', value: s.phone },
          { name: 'rccm', label: 'RCCM', value: s.rccm, maxlength: 60 }, { name: 'tax_id', label: 'NIF', value: s.tax_id, maxlength: 60 }])}
        <div data-place></div><div class="map small" data-map style="margin-bottom:12px"></div>
        <p class="small muted">Un déplacement important de l'adresse d'un commerce validé nécessite une nouvelle validation.</p></div>
      <div class="card"><h2>Commandes et livraison</h2>${MW.fields([
          { name: 'prep_time_min', label: 'Temps de préparation habituel (min)', type: 'number', required: true, value: s.prep_time_min, min: 1, max: 240 },
          { name: 'delivery_mode', label: 'Livraison', type: 'select', value: s.delivery_mode, options: [['marwa', 'Livreurs MARWA'], ['own', 'Mes propres livreurs']] },
          { name: 'delivery_radius_km', label: 'Rayon de livraison (km)', type: 'number', required: true, value: s.delivery_radius_km, step: '0.5', min: 0.5, max: 50 },
          { name: 'min_order', label: 'Commande minimum (FCFA)', type: 'number', value: s.min_order, min: 0 },
          { name: 'track_stock', label: 'Suivre les stocks (décrémentés à chaque commande)', type: 'checkbox', value: Number(s.track_stock) },
          { name: 'is_open', label: 'Ouvert aux commandes', type: 'checkbox', value: Number(s.is_open) }])}
        <h3>Horaires</h3><div data-hours></div><button type="button" class="btn btn-sm btn-outline" data-addhour>${MW.icon('plus')} Ajouter un créneau</button>
        <h3 style="margin-top:14px">Fermetures exceptionnelles</h3><div data-closures></div><button type="button" class="btn btn-sm btn-outline" data-addclosure>${MW.icon('plus')} Ajouter une date</button>
        <hr class="divider"><button class="btn btn-primary btn-block" type="submit">Enregistrer</button></div></form></div>`;
    const form = el.querySelector('[data-form]');
    const map = await MW.createMap(el.querySelector('[data-map]'), { center: [s.lat, s.lng], zoom: 15 });
    const place = new MW.PlaceField(el.querySelector('[data-place]'), { kind: 'pickup', label: 'Adresse', map });
    place.set({ text: s.address_text, lat: s.lat, lng: s.lng });
    const renderHours = () => {
      el.querySelector('[data-hours]').innerHTML = hours.length ? hours.map((h, i) => `<div class="row" style="margin-bottom:6px"><select class="input" style="flex:1" data-h="${i}" data-k="weekday">${DAYS.slice(1).map((d, k) => `<option value="${k + 1}" ${h.weekday === k + 1 ? 'selected' : ''}>${d}</option>`).join('')}</select>
        <input class="input" type="time" style="width:120px" data-h="${i}" data-k="open_time" value="${h.open_time}"><input class="input" type="time" style="width:120px" data-h="${i}" data-k="close_time" value="${h.close_time}"><button type="button" class="btn btn-sm btn-ghost" data-delh="${i}">×</button></div>`).join('') : '<p class="small muted">Aucun horaire : l\'interrupteur « ouvert » fait foi.</p>';
      el.querySelectorAll('[data-h]').forEach((i) => i.addEventListener('change', () => { const h = hours[i.dataset.h]; h[i.dataset.k] = i.dataset.k === 'weekday' ? Number(i.value) : i.value; }));
      el.querySelectorAll('[data-delh]').forEach((b) => b.addEventListener('click', () => { hours.splice(b.dataset.delh, 1); renderHours(); }));
    };
    const renderClosures = () => {
      el.querySelector('[data-closures]').innerHTML = closures.map((c, i) => `<div class="row" style="margin-bottom:6px"><input class="input" type="date" style="width:170px" data-c="${i}" data-k="closed_on" value="${c.closed_on}"><input class="input" style="flex:1" placeholder="Motif" data-c="${i}" data-k="reason" value="${MW.esc(c.reason || '')}"><button type="button" class="btn btn-sm btn-ghost" data-delc="${i}">×</button></div>`).join('');
      el.querySelectorAll('[data-c]').forEach((i) => i.addEventListener('change', () => { closures[i.dataset.c][i.dataset.k] = i.value; }));
      el.querySelectorAll('[data-delc]').forEach((b) => b.addEventListener('click', () => { closures.splice(b.dataset.delc, 1); renderClosures(); }));
    };
    renderHours(); renderClosures();
    el.querySelector('[data-addhour]').addEventListener('click', () => { hours.push({ weekday: 1, open_time: '08:00', close_time: '22:00' }); renderHours(); });
    el.querySelector('[data-addclosure]').addEventListener('click', () => { closures.push({ closed_on: MW.isoDate(new Date()), reason: '' }); renderClosures(); });
    MW.singleImage(el.querySelector('[data-logo]'), { label: 'Logo', url: s.logo_url, path: '/merchant/store/logo' });
    MW.singleImage(el.querySelector('[data-cover]'), { label: 'Photo de couverture (vitrine)', url: s.cover_url, path: '/merchant/store/cover', shape: 'wide' });
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const v = {}; form.querySelectorAll('[name]').forEach((i) => { v[i.name] = i.type === 'checkbox' ? i.checked : (i.type === 'number' ? Number(i.value) : i.value); });
      if (!place.valid()) { MW.toast('Placez le commerce sur la carte.', 'warn'); return; }
      const p = place.get();
      Object.assign(v, { address_text: p.text, lat: p.lat, lng: p.lng, hours, closures: closures.filter((c) => c.closed_on) });
      try { await MW.put('/merchant/store', v); MW.toast('Paramètres enregistrés.', 'success'); } catch (err) { MW.showErrors(form, err); }
    });
  }

  document.addEventListener('DOMContentLoaded', () => {
    MW.buildNav([
      { key: '', label: 'Vue d\'ensemble', short: 'Accueil', icon: 'home', primary: true },
      { key: 'commandes', label: 'Commandes', icon: 'list', primary: true },
      { key: 'catalogue', label: 'Catalogue', icon: 'food', primary: true },
      { key: 'finances', label: 'Finances', icon: 'wallet', primary: true },
      { key: 'rapports', label: 'Rapports', icon: 'chart' },
      { key: 'livreurs', label: 'Livreurs', icon: 'moto' },
      { key: 'parametres', label: 'Paramètres', icon: 'gear' },
      { key: 'profil', label: 'Profil', icon: 'user' },
      { key: 'assistance', label: 'Assistance', icon: 'help' }
    ]);
    MW.router({
      '/': overview, '/commandes': orders, '/catalogue': catalog, '/finances': finance, '/rapports': reports, '/livreurs': couriers, '/parametres': settings,
      '/profil': (el) => MW.views.profile(el), '/assistance': (el, p) => MW.views.support(el, p), '/assistance/:id': (el, p) => MW.views.complaint(el, p)
    }, document.getElementById('view'));
  });
})();
