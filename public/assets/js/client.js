/* =====================================================================
   MARWA — espace client
   ===================================================================== */
(function () {
  'use strict';
  const MW = window.MW;
  const view = () => document.getElementById('view');
  let favorites = [];
  const loadFavorites = async () => { try { favorites = await MW.get('/addresses', { cache: true }); } catch (e) { favorites = []; } return favorites; };

  const paymentOptions = async () => {
    const cfg = await MW.geoConfig();
    const opts = (cfg.payment_methods || ['cash']).filter((m) => m !== 'business_account' || (MW.user.businesses || []).some((b) => b.postpaid_enabled && b.status === 'verified'));
    return opts.map((m) => [m, MW.L.payment[m] || m]);
  };
  const businessField = () => {
    const biz = (MW.user.businesses || []).filter((b) => b.postpaid_enabled && b.status === 'verified');
    return biz.length ? { name: 'business_id', label: 'Compte entreprise', type: 'select', options: [['', '—'], ...biz.map((b) => [b.id, b.name])] } : null;
  };

  // ------------------------------------------------------------------ Accueil
  async function home(el) {
    const [orders, promos] = await Promise.all([MW.get('/orders?status=active', { cache: true }).catch(() => []), MW.get('/promotions').catch(() => [])]);
    await loadFavorites();
    el.innerHTML = `<div class="container">
      <h1>Bonjour ${MW.esc(MW.user.full_name.split(' ')[0])} 👋</h1>
      <div class="services" style="margin-bottom:16px">
        ${[['taxi', 'car', 'Taxi'], ['colis', 'box', 'Livraison'], ['repas', 'food', 'Repas'], ['colis?type=document', 'doc', 'Documents'], ['colis?type=errand', 'bag', 'Courses'], ['taxi?service=pro', 'briefcase', 'Pro']]
          .map(([h, i, l]) => `<a class="service-btn" href="#/${h}"><span class="ic">${MW.icon(i)}</span>${l}</a>`).join('')}
      </div>
      ${orders.length ? `<div class="card"><div class="card-title"><h2>En cours</h2></div><div class="list">${orders.map(orderRow).join('')}</div></div>` : ''}
      <div class="grid-2" style="margin-top:14px">
        <div class="card"><div class="card-title"><h2>Adresses favorites</h2><a href="#/adresses">Gérer</a></div>
          ${favorites.length ? `<div class="list">${favorites.map((f) => `<a class="list-item" href="#/taxi?to=${f.id}">${MW.icon('pin')}<div class="grow"><div class="title">${MW.esc(f.label)}</div><div class="meta ellipsis">${MW.esc(f.address_text)}</div></div></a>`).join('')}</div>` : MW.empty('Enregistrez vos lieux habituels (maison, bureau…).', 'pin')}</div>
        <div class="card"><div class="card-title"><h2>Promotions</h2></div>
          ${promos.length ? promos.map((p) => `<div class="list-item"><span class="badge amber">${MW.esc(p.code)}</span><div class="grow"><div class="title">${MW.esc(p.description)}</div><div class="meta">Jusqu'au ${MW.date(p.ends_at)}</div></div></div>`).join('') : MW.empty('Aucune promotion en ce moment.', 'tag')}</div>
      </div>
      <div class="card" style="margin-top:14px"><div class="row between"><div><h3 style="margin:0">Besoin d'aide ?</h3><p class="small muted" style="margin:0">Réclamation, objet perdu, question de paiement.</p></div><a class="btn btn-outline" href="#/assistance">Assistance</a></div></div>
    </div>`;
  }
  const orderRow = (o) => `<a class="list-item" href="#/commande/${o.id}"><span class="veh-ic" style="width:42px;height:42px;border-radius:12px;background:var(--green-50);display:grid;place-items:center;color:var(--green-700)">${MW.icon(o.flow === 'ride' ? 'car' : o.flow === 'merchant' ? 'food' : 'box')}</span>
    <div class="grow"><div class="title">${MW.esc(MW.L.service[o.service_type])} · ${MW.esc(o.reference)}</div><div class="meta ellipsis">${MW.esc(o.merchant_name || o.dropoff.text)} · ${MW.datetime(o.created_at)}</div></div>
    <div style="text-align:right">${MW.statusBadge(o.status)}<div class="small" style="margin-top:4px;font-weight:700">${MW.fcfa(o.total)}</div></div></a>`;

  // ------------------------------------------------------------------ Commande taxi
  async function taxi(el, params) {
    await loadFavorites();
    const payments = await paymentOptions();
    const biz = businessField();
    const service = params.service === 'pro' ? 'pro' : 'taxi';
    el.innerHTML = `<div class="container"><div class="page-head"><h1>${service === 'pro' ? 'Transport professionnel' : 'Commander un taxi'}</h1></div>
      <div class="order-layout">
        <form class="card" data-form novalidate>
          <div data-pickup></div><div data-dropoff></div>
          <div data-estimates class="stack"></div>
          <div data-rest hidden>
            <hr class="divider">
            <div class="field"><span class="label">Prix</span><div class="segmented" data-mode>
              <button type="button" class="active" data-m="auto">Tarif MARWA</button><button type="button" data-m="negotiated">Proposer mon prix</button></div></div>
            <div class="field" data-field="offered_price" data-offer hidden><label>Votre prix (FCFA)</label><input class="input" type="number" name="offered_price" inputmode="numeric" step="50"><div class="hint" data-bounds></div></div>
            <div class="form-row">${MW.fields([
              { name: 'passengers', label: 'Passagers', type: 'number', value: 1, min: 1, max: 15 },
              { name: 'payment_method', label: 'Paiement', type: 'select', options: payments, value: 'cash' }])}</div>
            ${biz ? MW.fields([biz]) : ''}
            <details><summary class="small" style="cursor:pointer;margin-bottom:10px">Options : programmer, code promo, instructions</summary>
              ${MW.fields([
                { name: 'scheduled_at', label: 'Programmer (facultatif)', type: 'datetime-local', hint: 'Au moins 10 minutes à l\'avance.' },
                { name: 'promo_code', label: 'Code promotionnel', maxlength: 30 },
                { name: 'pickup_notes', label: 'Point de rencontre précis', maxlength: 255, placeholder: 'Devant la pharmacie, portail bleu…' },
                { name: 'notes', label: 'Message au chauffeur', type: 'textarea', maxlength: 500 },
                { name: 'share_tracking', label: 'Partager ma position pendant la course (suivi par mes proches)', type: 'checkbox', value: true }])}
            </details>
            <button class="btn btn-accent btn-block btn-lg" type="submit">Confirmer la demande</button>
            <p class="small muted" data-disclaimer style="margin-top:8px"></p>
          </div>
        </form>
        <div class="card" style="padding:8px"><div class="map-wrap"><div id="taxi-map" class="map tall"></div><div class="nearby-badge" data-nearby hidden></div></div></div>
      </div></div>`;
    const map = await MW.createMap(el.querySelector('#taxi-map'));
    let estimates = []; let selected = null; let mode = 'auto'; let line = null;
    const form = el.querySelector('[data-form]');
    const refresh = async () => {
      if (!pickup.valid() || !dropoff.valid()) return;
      const p = pickup.get(); const d = dropoff.get();
      const box = el.querySelector('[data-estimates]');
      box.innerHTML = '<div class="skeleton"></div><div class="skeleton"></div>';
      try {
        const r = await MW.post('/estimate', { service_type: service, vehicle_category: service === 'pro' ? 'sedan' : null, pickup_lat: p.lat, pickup_lng: p.lng, dropoff_lat: d.lat, dropoff_lng: d.lng });
        estimates = r.data; selected = selected && estimates.find((e) => e.vehicle_category === selected.vehicle_category) || estimates[0];
        if (line) line.remove();
        line = L.polyline(estimates[0].geometry, { color: '#0b6e4f', weight: 5, opacity: .8, dashArray: estimates[0].approximate ? '8 8' : null }).addTo(map);
        MW.fitTo(map, estimates[0].geometry);
        renderEstimates();
      } catch (e) { box.innerHTML = `<div class="alert alert-error">${MW.esc(e.message)}</div>`; }
    };
    const renderEstimates = () => {
      const box = el.querySelector('[data-estimates]');
      box.innerHTML = `<div class="small muted">${MW.dist(estimates[0].distance_m)} · environ ${MW.dur(estimates[0].duration_s)}${estimates[0].night ? ' · <span class="badge amber">tarif de nuit</span>' : ''}</div>`
        + estimates.map((x, i) => `<button type="button" class="vehicle-option ${x === selected ? 'active' : ''}" data-i="${i}"><span class="veh-ic">${MW.icon(x.vehicle_category === 'moto_taxi' ? 'moto' : 'car')}</span>
          <span><strong>${MW.esc(MW.L.vehicle[x.vehicle_category])}</strong><br><span class="small muted">${x.booking_fee ? 'dont réservation ' + MW.fcfa(x.booking_fee) : 'Estimation'}</span></span><span class="price">${MW.fcfa(x.total)}</span></button>`).join('');
      box.querySelectorAll('[data-i]').forEach((b) => b.addEventListener('click', () => { selected = estimates[Number(b.dataset.i)]; renderEstimates(); }));
      el.querySelector('[data-rest]').hidden = false;
      el.querySelector('[data-bounds]').textContent = `Entre ${MW.fcfa(selected.min_offer)} et ${MW.fcfa(selected.max_offer)}. Prix MARWA : ${MW.fcfa(selected.fare)}.`;
      const offer = form.querySelector('[name=offered_price]'); if (!offer.value) offer.value = selected.fare;
      el.querySelector('[data-disclaimer]').textContent = selected.disclaimer;
    };
    const pickup = new MW.PlaceField(el.querySelector('[data-pickup]'), { kind: 'pickup', label: 'Départ', map, favorites, onChange: refresh });
    const dropoff = new MW.PlaceField(el.querySelector('[data-dropoff]'), { kind: 'dropoff', label: 'Destination', map, favorites, onChange: refresh });
    el.querySelectorAll('[data-m]').forEach((b) => b.addEventListener('click', () => {
      mode = b.dataset.m; el.querySelectorAll('[data-m]').forEach((x) => x.classList.toggle('active', x === b));
      el.querySelector('[data-offer]').hidden = mode !== 'negotiated';
    }));
    const draft = JSON.parse(sessionStorage.getItem('mw:draft') || 'null');
    if (draft && draft.pickup) { sessionStorage.removeItem('mw:draft'); pickup.set(draft.pickup); dropoff.set(draft.dropoff, true); }
    else {
      const fav = params.to && favorites.find((f) => String(f.id) === params.to);
      if (fav && fav.lat) dropoff.set({ text: fav.address_text, lat: fav.lat, lng: fav.lng }, true);
      MW.askLocation({ reason: 'MARWA utilise votre position comme point de départ et pour afficher les taxis libres autour de vous.', silent: true })
        .then((p) => { if (p && !pickup.valid()) { pickup.set({ text: 'Ma position', lat: p.lat, lng: p.lng }, true); pickup.reverse(p.lat, p.lng); nearby && nearby.refresh(); } });
    }
    // Taxis libres autour du point de départ (ou du centre de la carte), mis à jour toutes les 10 s
    const nearby = map ? new MW.NearbyLayer(map, { service, badge: el.querySelector('[data-nearby]'),
      center: () => { if (pickup.valid()) { const p = pickup.get(); return [p.lat, p.lng]; } const c = map.getCenter(); return [c.lat, c.lng]; } }) : null;
    MW.onLeave(() => nearby && nearby.stop());
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      if (!selected) { MW.toast('Choisissez le départ et la destination.', 'warn'); return; }
      const v = MW.values(form); const p = pickup.get(); const d = dropoff.get();
      const body = { service_type: service, vehicle_category: selected.vehicle_category, pickup_text: p.text, pickup_lat: p.lat, pickup_lng: p.lng, pickup_notes: v.pickup_notes,
        dropoff_text: d.text, dropoff_lat: d.lat, dropoff_lng: d.lng, pricing_mode: mode, offered_price: mode === 'negotiated' ? v.offered_price : null,
        passengers: v.passengers, payment_method: v.payment_method, business_id: v.business_id || null, promo_code: v.promo_code, scheduled_at: v.scheduled_at, notes: v.notes, share_tracking: v.share_tracking };
      const btn = form.querySelector('[type=submit]');
      await MW.busy(btn, async () => {
        try { const r = await MW.post('/orders', body); location.hash = '#/commande/' + r.data.id; } catch (err) { MW.showErrors(form, err); }
      });
    });
  }

  // ------------------------------------------------------------------ Colis, documents, courses, urgent
  async function parcel(el, params) {
    await loadFavorites();
    const cfg = await MW.geoConfig();
    const payments = await paymentOptions();
    const biz = businessField();
    const type = ['parcel', 'document', 'errand', 'urgent'].includes(params.type) ? params.type : 'parcel';
    el.innerHTML = `<div class="container"><div class="page-head"><h1>Envoyer / faire livrer</h1></div>
      <div class="order-layout"><form class="card" data-form novalidate>
        ${MW.fields([{ name: 'service_type', label: 'Type de livraison', type: 'select', value: type, options: [['parcel', 'Colis'], ['document', 'Documents'], ['errand', 'Courses pour moi'], ['urgent', 'Livraison urgente']] }])}
        <div data-pickup></div><div data-dropoff></div>
        <div class="form-row">${MW.fields([
          { name: 'vehicle_category', label: 'Moyen de livraison', type: 'select', value: 'moto', options: [['moto', 'Moto'], ['bicycle', 'Vélo'], ['car', 'Voiture'], ['van', 'Utilitaire']] },
          { name: 'package_type', label: 'Nature', type: 'select', value: type === 'document' ? 'documents' : type === 'errand' ? 'courses' : 'petit_colis', options: Object.entries(MW.L.package) }])}</div>
        ${MW.fields([{ name: 'description', label: 'Description du contenu', type: 'textarea', required: true, maxlength: 500, placeholder: 'Ex. : enveloppe A4, carton de 5 kg…' }])}
        <div class="form-row three">${MW.fields([
          { name: 'weight_kg', label: 'Poids (kg)', type: 'number', step: '0.1', min: 0 },
          { name: 'dimensions', label: 'Dimensions', placeholder: '40×30×20 cm', maxlength: 60 },
          { name: 'declared_value', label: 'Valeur déclarée (FCFA)', type: 'number', min: 0 }])}</div>
        <h3>Destinataire</h3>
        <div class="form-row">${MW.fields([{ name: 'recipient_name', label: 'Nom', required: true, maxlength: 120 }, { name: 'recipient_phone', label: 'Téléphone', type: 'tel', required: true }])}</div>
        <details><summary class="small" style="cursor:pointer;margin-bottom:10px">Expéditeur (si différent de vous)</summary>
          <div class="form-row">${MW.fields([{ name: 'sender_name', label: 'Nom', maxlength: 120 }, { name: 'sender_phone', label: 'Téléphone', type: 'tel' }])}</div></details>
        ${MW.fields([
          { name: 'instructions', label: 'Instructions particulières', type: 'textarea', maxlength: 500 },
          { name: 'proof_type', label: 'Preuve de livraison', type: 'select', value: 'otp', options: [['otp', 'Code remis au destinataire'], ['photo', 'Photo de la remise'], ['signature', 'Signature du destinataire']] }])}
        <div class="form-row">${MW.fields([
          { name: 'pricing_mode', label: 'Prix', type: 'select', value: 'auto', options: [['auto', 'Tarif MARWA'], ['negotiated', 'Proposer mon prix']] },
          { name: 'offered_price', label: 'Votre prix (si négocié)', type: 'number', step: 50 }])}</div>
        <div class="form-row">${MW.fields([
          { name: 'payment_method', label: 'Paiement', type: 'select', options: payments, value: 'cash' },
          { name: 'cash_payer', label: 'Espèces payées par', type: 'select', options: [['customer', 'Moi (au départ)'], ['recipient', 'Le destinataire']] }])}</div>
        ${biz ? MW.fields([biz]) : ''}
        ${MW.fields([{ name: 'promo_code', label: 'Code promotionnel', maxlength: 30 }])}
        <div class="alert alert-warn"><strong>Objets interdits :</strong><ul style="margin:6px 0 0;padding-left:18px">${(cfg.prohibited_items || []).map((i) => `<li>${MW.esc(i)}</li>`).join('')}</ul></div>
        ${MW.fields([{ name: 'accept_rules', label: 'Je certifie que l\'envoi ne contient aucun objet interdit ou réglementé.', type: 'checkbox' }])}
        <div data-estimate class="alert alert-info" hidden></div>
        <button class="btn btn-accent btn-block btn-lg" type="submit">Demander un livreur</button>
      </form><div class="card" style="padding:8px"><div class="map-wrap"><div id="parcel-map" class="map tall"></div><div class="nearby-badge" data-nearby hidden></div></div></div></div></div>`;
    const map = await MW.createMap(el.querySelector('#parcel-map'));
    const form = el.querySelector('[data-form]');
    let line = null;
    const estimate = async () => {
      if (!pickup.valid() || !dropoff.valid()) return;
      const v = MW.values(form); const p = pickup.get(); const d = dropoff.get();
      try {
        const r = await MW.post('/estimate', { service_type: v.service_type, vehicle_category: v.vehicle_category, pickup_lat: p.lat, pickup_lng: p.lng, dropoff_lat: d.lat, dropoff_lng: d.lng });
        const x = r.data[0];
        const box = el.querySelector('[data-estimate]'); box.hidden = false;
        box.innerHTML = `<strong>${MW.fcfa(x.total)}</strong> · ${MW.dist(x.distance_m)} · ~${MW.dur(x.duration_s)}<br><span class="small">Prix négociable entre ${MW.fcfa(x.min_offer)} et ${MW.fcfa(x.max_offer)}. ${MW.esc(x.disclaimer)}</span>`;
        if (!form.querySelector('[name=offered_price]').value) form.querySelector('[name=offered_price]').value = x.fare;
        if (line) line.remove(); line = L.polyline(x.geometry, { color: '#d98a0b', weight: 5, dashArray: x.approximate ? '8 8' : null }).addTo(map); MW.fitTo(map, x.geometry);
      } catch (e) { MW.error(e); }
    };
    const pickup = new MW.PlaceField(el.querySelector('[data-pickup]'), { kind: 'pickup', label: 'Lieu de récupération', map, favorites, onChange: estimate });
    const dropoff = new MW.PlaceField(el.querySelector('[data-dropoff]'), { kind: 'dropoff', label: 'Lieu de livraison', map, favorites, onChange: estimate });
    form.querySelector('[name=service_type]').addEventListener('change', estimate);
    form.querySelector('[name=vehicle_category]').addEventListener('change', estimate);
    const draft = JSON.parse(sessionStorage.getItem('mw:draft') || 'null');
    if (draft && draft.pickup) { sessionStorage.removeItem('mw:draft'); pickup.set(draft.pickup); dropoff.set(draft.dropoff, true); }
    else MW.askLocation({ reason: 'MARWA utilise votre position comme lieu de récupération et pour afficher les livreurs disponibles autour de vous.', silent: true })
      .then((p) => { if (p && !pickup.valid()) { pickup.set({ text: 'Ma position', lat: p.lat, lng: p.lng }, true); pickup.reverse(p.lat, p.lng); } });
    const nearby = map ? new MW.NearbyLayer(map, { service: 'parcel', badge: el.querySelector('[data-nearby]'),
      center: () => { if (pickup.valid()) { const p = pickup.get(); return [p.lat, p.lng]; } const c = map.getCenter(); return [c.lat, c.lng]; } }) : null;
    MW.onLeave(() => nearby && nearby.stop());
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      if (!pickup.valid() || !dropoff.valid()) { MW.toast('Indiquez les lieux de récupération et de livraison (recherche, GPS ou carte).', 'warn'); return; }
      const v = MW.values(form); const p = pickup.get(); const d = dropoff.get();
      Object.assign(v, { pickup_text: p.text, pickup_lat: p.lat, pickup_lng: p.lng, dropoff_text: d.text, dropoff_lat: d.lat, dropoff_lng: d.lng });
      if (v.pricing_mode !== 'negotiated') v.offered_price = null;
      await MW.busy(form.querySelector('[type=submit]'), async () => {
        try { const r = await MW.post('/orders', v); location.hash = '#/commande/' + r.data.id; } catch (err) { MW.showErrors(form, err); }
      });
    });
  }

  // ------------------------------------------------------------------ Restaurants
  async function restaurants(el, params) {
    el.innerHTML = `<div class="container"><div class="page-head"><h1>Repas et commerces</h1></div>
      <div class="card" style="margin-bottom:14px"><form class="row" data-filters>
        <input class="input" name="q" placeholder="Rechercher un plat, un restaurant…" style="flex:2;min-width:200px" value="${MW.esc(params.q || '')}">
        <select class="input" name="kind" style="flex:1;min-width:140px"><option value="">Tout</option><option value="restaurant">Restaurants</option><option value="shop">Commerces</option></select>
        <input class="input" type="number" name="max_price" placeholder="Prix max" style="flex:1;min-width:110px">
        <label class="check" style="flex:none"><input type="checkbox" name="open_only"> Ouverts</label>
        <button class="btn btn-primary">Filtrer</button></form></div>
      <div class="grid-3" data-list><div class="skeleton"></div></div></div>`;
    let pos = null;
    const load = async () => {
      const v = MW.values(el.querySelector('[data-filters]'));
      const q = new URLSearchParams(Object.entries(v).filter(([, x]) => x !== null && x !== false && x !== '').map(([k, x]) => [k, x === true ? '1' : x]));
      if (pos) { q.set('lat', pos.lat); q.set('lng', pos.lng); }
      const list = await MW.get('/merchants?' + q, { cache: true });
      el.querySelector('[data-list]').innerHTML = list.length ? list.map((m) => `<a class="merchant-card" href="#/repas/${m.id}">
        <span class="logo" ${m.logo_url ? `style="background-image:url('${MW.esc(m.logo_url)}')"` : ''}>${m.logo_url ? '' : MW.esc(m.name[0])}</span>
        <span class="grow" style="min-width:0"><strong>${MW.esc(m.name)}</strong><br><span class="small muted">${MW.esc(m.cuisine || MW.L.service[m.kind === 'shop' ? 'shop' : 'food'])}${m.distance_m !== undefined ? ' · ' + MW.dist(m.distance_m) : ''} · ${m.prep_time_min} min</span><br>
          ${m.open ? '<span class="badge green">Ouvert</span>' : '<span class="badge gray">Fermé</span>'} ${m.rating_count ? `<span class="badge">★ ${m.rating.toFixed(1)}</span>` : ''} ${m.has_promo ? '<span class="badge amber">Promo</span>' : ''} ${m.delivers_here === false ? '<span class="badge red">Hors zone</span>' : ''}</span>
        ${m.min_price ? `<span class="small muted">dès ${MW.fcfa(m.min_price)}</span>` : ''}</a>`).join('') : MW.empty('Aucun établissement ne correspond.', 'food');
    };
    el.querySelector('[data-filters]').addEventListener('submit', (e) => { e.preventDefault(); load(); });
    await load();
    MW.locate({ timeout: 6000 }).then((p) => { pos = p; load(); }).catch(() => {});
  }

  const cartKey = (id) => 'mw:cart:' + MW.user.id + ':' + id;
  const getCart = (id) => JSON.parse(localStorage.getItem(cartKey(id)) || '[]');
  const setCart = (id, c) => localStorage.setItem(cartKey(id), JSON.stringify(c));

  async function menu(el, params) {
    const m = await MW.get('/merchants/' + params.id, { cache: true });
    const byCat = {};
    m.products.forEach((p) => { (byCat[p.category_id || 0] = byCat[p.category_id || 0] || []).push(p); });
    const cats = [...m.categories, { id: 0, name: 'Autres' }].filter((c) => byCat[c.id]);
    el.innerHTML = `<div class="container"><a href="#/repas">← Restaurants</a>
      <div class="card" style="margin:10px 0 14px"><div class="row"><span class="logo merchant-card" style="padding:0;border:0"><span class="logo" ${m.logo_url ? `style="background-image:url('${MW.esc(m.logo_url)}')"` : ''}>${m.logo_url ? '' : MW.esc(m.name[0])}</span></span>
        <div class="grow"><h1 style="margin:0">${MW.esc(m.name)}</h1><div class="muted small">${MW.esc(m.cuisine || '')} · ${MW.esc(m.address)} · préparation ~${m.prep_time_min} min${m.min_order ? ' · minimum ' + MW.fcfa(m.min_order) : ''}</div>
        <div style="margin-top:6px">${m.open ? '<span class="badge green">Ouvert</span>' : '<span class="badge gray">Fermé actuellement</span>'} ${m.rating_count ? `<span class="badge">★ ${m.rating.toFixed(1)} (${m.rating_count})</span>` : ''}</div></div></div>
        ${m.description ? `<p class="small muted" style="margin:10px 0 0">${MW.esc(m.description)}</p>` : ''}</div>
      ${cats.map((c) => `<h2>${MW.esc(c.name)}</h2><div class="grid-2" style="margin-bottom:18px">${byCat[c.id].map((p) => `<div class="product-card">
        <div class="thumb" ${p.photo_url ? `style="background-image:url('${MW.esc(p.photo_url)}')"` : ''}>${p.photo_url ? '' : MW.icon('food')}</div>
        <div class="grow"><strong>${MW.esc(p.name)}</strong><div class="small muted">${MW.esc(p.description || '')}</div>
          <div style="margin-top:4px"><strong>${MW.fcfa(p.promo_price ?? p.price)}</strong>${p.promo_price ? `<span class="price-old">${MW.fcfa(p.price)}</span>` : ''}</div></div>
        ${p.available && m.open ? `<button class="btn btn-primary btn-sm" data-add="${p.id}" aria-label="Ajouter">${MW.icon('plus')}</button>` : '<span class="badge gray">Indisponible</span>'}</div>`).join('')}</div>`).join('')}
      ${m.reviews.length ? `<div class="card"><h2>Avis</h2>${m.reviews.map((r) => `<div class="list-item"><span class="badge amber">★ ${r.score}</span><div class="grow">${MW.esc(r.comment)}<div class="meta">${MW.esc(r.date)}</div></div></div>`).join('')}</div>` : ''}
      <div class="cart-bar" data-cartbar hidden></div></div>`;
    const renderBar = () => {
      const cart = getCart(m.id);
      const n = cart.reduce((s, i) => s + i.quantity, 0);
      const total = cart.reduce((s, i) => s + i.unit * i.quantity, 0);
      const bar = el.querySelector('[data-cartbar]');
      bar.hidden = n === 0;
      bar.innerHTML = `<a class="btn btn-accent btn-lg btn-block" href="#/panier/${m.id}">Voir le panier (${n}) · ${MW.fcfa(total)}</a>`;
    };
    el.querySelectorAll('[data-add]').forEach((b) => b.addEventListener('click', () => {
      const p = m.products.find((x) => String(x.id) === b.dataset.add);
      const add = (optIds) => {
        const opts = p.options.filter((o) => optIds.includes(o.id));
        const unit = (p.promo_price ?? p.price) + opts.reduce((s, o) => s + o.price_delta, 0);
        const cart = getCart(m.id);
        const key = p.id + ':' + optIds.sort().join(',');
        const ex = cart.find((i) => i.key === key);
        if (ex) ex.quantity++; else cart.push({ key, product_id: p.id, name: p.name, option_ids: optIds, options: opts.map((o) => o.name), unit, quantity: 1 });
        setCart(m.id, cart); renderBar(); MW.toast(p.name + ' ajouté au panier.', 'success', 1800);
      };
      if (!p.options.length) return add([]);
      const groups = {};
      p.options.forEach((o) => (groups[o.group] = groups[o.group] || []).push(o));
      const md = MW.modal(p.name, `<form>${Object.entries(groups).map(([g, os]) => `<div class="field"><span class="label">${MW.esc(g)}${os[0].kind === 'variant' ? ' (un choix)' : ''}</span>
        ${os.map((o, i) => `<label class="check" style="margin-bottom:6px"><input type="${o.kind === 'variant' ? 'radio' : 'checkbox'}" name="g_${MW.esc(g)}" value="${o.id}" ${o.kind === 'variant' && i === 0 ? 'checked' : ''}> ${MW.esc(o.name)} ${o.price_delta ? `<span class="muted">(${o.price_delta > 0 ? '+' : ''}${MW.fcfa(o.price_delta)})</span>` : ''}</label>`).join('')}</div>`).join('')}
        <button class="btn btn-primary btn-block">Ajouter</button></form>`);
      md.el.querySelector('form').addEventListener('submit', (e) => { e.preventDefault(); add([...md.el.querySelectorAll('input:checked')].map((i) => Number(i.value))); md.close(); });
    }));
    renderBar();
  }

  async function checkout(el, params) {
    const m = await MW.get('/merchants/' + params.id, { cache: true });
    await loadFavorites();
    const payments = await paymentOptions();
    const biz = businessField();
    let cart = getCart(m.id);
    if (!cart.length) { location.hash = '#/repas/' + m.id; return; }
    el.innerHTML = `<div class="container"><a href="#/repas/${m.id}">← ${MW.esc(m.name)}</a><h1 style="margin-top:8px">Mon panier</h1>
      <div class="order-layout"><form class="card" data-form novalidate>
        <div data-items></div><hr class="divider">
        <div data-dropoff></div>
        <div class="form-row">${MW.fields([{ name: 'recipient_name', label: 'Nom du destinataire', value: MW.user.full_name, maxlength: 120 }, { name: 'recipient_phone', label: 'Téléphone du destinataire', type: 'tel', value: MW.user.phone || '' }])}</div>
        ${MW.fields([{ name: 'dropoff_notes', label: 'Indications pour le livreur', maxlength: 255, placeholder: 'Repère, couleur du portail…' }, { name: 'notes', label: 'Instructions au restaurant', type: 'textarea', maxlength: 500, placeholder: 'Sans piment, bien cuit…' }])}
        <div class="form-row">${MW.fields([{ name: 'payment_method', label: 'Paiement', type: 'select', options: payments, value: 'cash' }, { name: 'promo_code', label: 'Code promotionnel', maxlength: 30 }])}</div>
        ${biz ? MW.fields([biz]) : ''}
        <dl class="kv" data-totals></dl>
        <button class="btn btn-accent btn-block btn-lg" type="submit" style="margin-top:12px">Commander</button>
        <p class="small muted">Le total définitif est calculé par le serveur. Paiement électronique : la commande est transmise au restaurant après confirmation du paiement.</p>
      </form><div class="card" style="padding:8px"><div id="co-map" class="map tall"></div></div></div></div>`;
    const map = await MW.createMap(el.querySelector('#co-map'));
    L.marker([m.lat, m.lng], { icon: MW.pinIcon('merchant') }).addTo(map).bindTooltip(m.name);
    let fee = null;
    const renderItems = () => {
      const sub = cart.reduce((s, i) => s + i.unit * i.quantity, 0);
      el.querySelector('[data-items]').innerHTML = cart.map((i, k) => `<div class="list-item"><div class="grow"><div class="title">${MW.esc(i.name)}</div><div class="meta">${MW.esc(i.options.join(', '))} · ${MW.fcfa(i.unit)}</div></div>
        <div class="row" style="gap:4px"><button type="button" class="btn btn-sm btn-outline" data-dec="${k}">−</button><strong style="min-width:22px;text-align:center">${i.quantity}</strong><button type="button" class="btn btn-sm btn-outline" data-inc="${k}">+</button></div></div>`).join('');
      el.querySelector('[data-totals]').innerHTML = `<dt>Sous-total</dt><dd>${MW.fcfa(sub)}</dd><dt>Livraison</dt><dd>${fee ? MW.fcfa(fee.fare) : 'choisissez l\'adresse'}</dd>${fee && fee.service_fee ? `<dt>Frais de service</dt><dd>${MW.fcfa(fee.service_fee)}</dd>` : ''}<dt><strong>Total estimé</strong></dt><dd><strong>${MW.fcfa(sub + (fee ? fee.fare + fee.service_fee + fee.booking_fee : 0))}</strong></dd>`;
      el.querySelectorAll('[data-inc]').forEach((b) => b.onclick = () => { cart[b.dataset.inc].quantity++; setCart(m.id, cart); renderItems(); });
      el.querySelectorAll('[data-dec]').forEach((b) => b.onclick = () => { const it = cart[b.dataset.dec]; it.quantity--; if (it.quantity <= 0) cart.splice(b.dataset.dec, 1); setCart(m.id, cart); if (!cart.length) location.hash = '#/repas/' + m.id; else renderItems(); });
    };
    const dropoff = new MW.PlaceField(el.querySelector('[data-dropoff]'), { kind: 'dropoff', label: 'Adresse de livraison', map, favorites, onChange: async (d) => {
      if (!dropoff.valid()) return;
      try { fee = (await MW.post('/estimate', { service_type: m.kind === 'shop' ? 'shop' : 'food', pickup_lat: m.lat, pickup_lng: m.lng, dropoff_lat: d.lat, dropoff_lng: d.lng })).data[0]; } catch (e) { fee = null; MW.error(e); }
      renderItems(); MW.fitTo(map, [[m.lat, m.lng], [d.lat, d.lng]]);
    } });
    renderItems();
    MW.locate({ timeout: 6000 }).then((p) => { if (!dropoff.valid()) { dropoff.set({ text: 'Ma position', lat: p.lat, lng: p.lng }, true); dropoff.reverse(p.lat, p.lng); } }).catch(() => {});
    const form = el.querySelector('[data-form]');
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      if (!dropoff.valid()) { MW.toast('Indiquez l\'adresse de livraison sur la carte.', 'warn'); return; }
      const v = MW.values(form); const d = dropoff.get();
      const body = Object.assign(v, { service_type: m.kind === 'shop' ? 'shop' : 'food', merchant_id: m.id, items: cart.map((i) => ({ product_id: i.product_id, quantity: i.quantity, option_ids: i.option_ids })),
        dropoff_text: d.text, dropoff_lat: d.lat, dropoff_lng: d.lng });
      await MW.busy(form.querySelector('[type=submit]'), async () => {
        try {
          const r = await MW.post('/orders', body);
          localStorage.removeItem(cartKey(m.id));
          if (['demo_mobile_money', 'mobile_money', 'card'].includes(body.payment_method)) return pay(r.data.id);
          location.hash = '#/commande/' + r.data.id;
        } catch (err) { MW.showErrors(form, err); }
      });
    });
  }

  async function pay(orderId) {
    const k = 'mw:idem:' + orderId;
    let key = sessionStorage.getItem(k);
    if (!key) { key = 'pay-' + MW.uid(); sessionStorage.setItem(k, key); }
    const r = await MW.post(`/orders/${orderId}/pay`, {}, { headers: { 'Idempotency-Key': key } });
    location.href = r.data.redirect_url;
  }

  // ------------------------------------------------------------------ Suivi de commande
  async function track(el, params) {
    const id = params.id;
    let order = await MW.get('/orders/' + id);
    el.innerHTML = `<div class="container"><div class="page-head"><div><a href="#/historique">← Mes commandes</a><h1 style="margin-top:6px">${MW.esc(MW.L.service[order.service_type])} · ${MW.esc(order.reference)}</h1></div>
        <button class="btn btn-danger btn-sm" data-sos>${MW.icon('sos')} Urgence</button></div>
      <div class="order-layout"><div class="stack" data-side></div>
      <div class="card" style="padding:8px"><div class="map-wrap"><div id="track-map" class="map tall"></div><div class="map-badge" data-mapbadge hidden></div><button class="map-fab" data-follow hidden aria-label="Suivre le véhicule" title="Suivre le véhicule">${MW.icon('locate')}</button></div></div></div></div>`;
    el.querySelector('[data-sos]').addEventListener('click', () => MW.sos(id));
    const map = await MW.createMap(el.querySelector('#track-map'));
    const layers = { route: null, trail: null, vehicle: null, pickup: null, dropoff: null, live: null };
    // Suivi du véhicule : la carte le garde au centre jusqu'à ce que le client la déplace
    let follow = true;
    if (map) map.on('dragstart', () => { follow = false; const b = el.querySelector('[data-follow]'); if (b && layers.vehicle) b.hidden = false; });
    el.querySelector('[data-follow]').addEventListener('click', (e) => { follow = true; e.currentTarget.hidden = true; if (layers.vehicle) map.setView(layers.vehicle.getLatLng(), Math.max(map.getZoom(), 15)); });
    layers.pickup = L.marker([order.pickup.lat, order.pickup.lng], { icon: MW.pinIcon(order.flow === 'merchant' ? 'merchant' : 'pickup') }).addTo(map).bindTooltip(order.flow === 'merchant' ? 'Commerce' : 'Départ');
    layers.dropoff = L.marker([order.dropoff.lat, order.dropoff.lng], { icon: MW.pinIcon('dropoff') }).addTo(map).bindTooltip('Arrivée');
    MW.fitTo(map, [[order.pickup.lat, order.pickup.lng], [order.dropoff.lat, order.dropoff.lng]]);
    let chat = null; let fitted = false; let lastStatus = null; let stopCountdowns = [];

    const renderSide = () => {
      stopCountdowns.forEach((f) => f()); stopCountdowns = [];
      const o = order; const a = o.actions || [];
      const active = !['paid', 'cancelled', 'expired', 'refused', 'refunded'].includes(o.status);
      const side = el.querySelector('[data-side]');
      side.innerHTML = `<div class="card"><div class="status-hero">${active ? '<span class="pulse"></span>' : ''}<div><div class="small muted">Statut</div><h2 style="margin:0">${MW.esc(o.status_label)}</h2>
          ${o.flow === 'merchant' && o.provider_stage && ['accepted', 'preparing', 'ready'].includes(o.status) ? `<div class="small">Livreur : ${MW.esc({ assigned: 'affecté', en_route_pickup: 'en route vers le commerce', arrived_pickup: 'arrivé au commerce' }[o.provider_stage] || '')}</div>` : ''}
          ${o.dispatch_status === 'no_provider' ? '<div class="small" style="color:var(--red-600)">Aucun prestataire disponible pour le moment.</div>' : ''}</div></div>
          <div data-eta class="small" style="margin-top:8px"></div></div>
        ${o.delivery_code ? `<div class="card"><div class="small muted">Code de livraison à donner au livreur <strong>uniquement</strong> à la réception :</div><div class="code-box">${MW.esc(o.delivery_code)}</div></div>` : ''}
        ${o.status === 'negotiating' ? negotiationPanel(o) : ''}
        ${o.provider ? `<div class="card"><div class="row"><span class="avatar" style="width:52px;height:52px">${o.provider.photo_url ? `<img src="${MW.esc(o.provider.photo_url)}" alt="">` : MW.esc(o.provider.name[0])}</span>
          <div class="grow"><strong>${MW.esc(o.provider.name)}</strong> ${o.provider.rating_count ? `<span class="badge">★ ${o.provider.rating.toFixed(1)}</span>` : '<span class="badge gray">Nouveau</span>'}
          <div class="small muted">${MW.esc(o.provider.vehicle.label || MW.L.vehicle[o.provider.vehicle.category] || '')} ${MW.esc(o.provider.vehicle.color || '')}</div>
          ${o.provider.vehicle.plate ? `<div><span class="badge blue">${MW.esc(o.provider.vehicle.plate)}</span></div>` : ''}</div></div>
          <p class="small muted" style="margin:8px 0 0">Vérifiez la plaque et le nom avant de monter ou de remettre un colis.</p></div>` : ''}
        <div class="card"><dl class="kv">
          <dt>Départ</dt><dd>${MW.esc(o.pickup.text)}</dd><dt>Arrivée</dt><dd>${MW.esc(o.dropoff.text)}</dd>
          ${o.distance_m ? `<dt>Distance</dt><dd>${MW.dist(o.distance_m)}${o.route_source === 'estimate' ? ' (approx.)' : ''}</dd>` : ''}
          ${(o.items || []).map((i) => `<dt>${i.quantity} × ${MW.esc(i.name)}</dt><dd>${MW.fcfa(i.line_total)}</dd>`).join('')}
          ${o.flow !== 'merchant' ? `<dt>Prix MARWA</dt><dd>${MW.fcfa(o.estimated_price)}</dd>${o.offered_price ? `<dt>Votre offre</dt><dd>${MW.fcfa(o.offered_price)}</dd>` : ''}${o.final_price ? `<dt>Prix convenu</dt><dd>${MW.fcfa(o.final_price)}</dd>` : ''}` : `<dt>Livraison</dt><dd>${MW.fcfa(o.delivery_fee)}</dd>`}
          ${o.booking_fee ? `<dt>Réservation</dt><dd>${MW.fcfa(o.booking_fee)}</dd>` : ''}${o.service_fee ? `<dt>Frais de service</dt><dd>${MW.fcfa(o.service_fee)}</dd>` : ''}${o.discount ? `<dt>Réduction</dt><dd>−${MW.fcfa(o.discount)}</dd>` : ''}
          <dt><strong>Total</strong></dt><dd><strong>${MW.fcfa(o.total)}</strong></dd>
          <dt>Paiement</dt><dd>${MW.esc(MW.L.payment[o.payment_method])} · ${MW.esc(MW.L.payStatus[o.payment_status])}</dd></dl>
          <div class="row" style="margin-top:12px">
            ${a.includes('pay') ? '<button class="btn btn-accent" data-pay>Payer maintenant</button>' : ''}
            ${a.includes('cancelled') ? '<button class="btn btn-outline" data-cancel>Annuler</button>' : ''}
            ${o.receipt_number ? `<a class="btn btn-outline" target="_blank" href="${MW.base}/api/orders/${o.id}/receipt">Reçu ${MW.esc(o.receipt_number)}</a>` : ''}
            <a class="btn btn-ghost" href="#/assistance?nouveau=1&commande=${o.id}&categorie=${o.flow === 'ride' ? 'ride' : 'order'}">Signaler un problème</a></div></div>
        ${a.includes('rate') ? ratingCard(o) : ''}
        ${o.provider && active ? '<div class="card"><h3>Messagerie</h3><div data-chat></div></div>' : ''}
        <div class="card"><h3>Historique</h3><ul class="timeline">${(o.history || []).map((h) => `<li><strong>${MW.esc(h.label)}</strong>${h.note ? `<div class="small">${MW.esc(h.note)}</div>` : ''}<div class="t-time">${MW.datetime(h.created_at)}</div></li>`).join('')}</ul></div>`;
      side.querySelector('[data-cancel]')?.addEventListener('click', async () => {
        const reason = await MW.confirm('Annuler cette commande ?', { reason: 'Motif (facultatif au début, obligatoire après l\'affectation d\'un prestataire)', danger: true, ok: 'Annuler la commande' });
        if (reason === false) return;
        try { order = (await MW.post(`/orders/${id}/cancel`, { reason: reason === true ? null : reason })).data; renderSide(); } catch (e) { MW.error(e); }
      });
      side.querySelector('[data-pay]')?.addEventListener('click', (e) => MW.busy(e.currentTarget, () => pay(id)));
      side.querySelectorAll('[data-accept]').forEach((b) => b.addEventListener('click', () => MW.busy(b, async () => { order = (await MW.post(`/orders/${id}/negotiations/${b.dataset.accept}/accept`)).data; MW.toast('Prix accepté : le prestataire est en route.', 'success'); renderSide(); })));
      side.querySelectorAll('[data-reject]').forEach((b) => b.addEventListener('click', () => MW.busy(b, async () => { await MW.post(`/orders/${id}/negotiations/${b.dataset.reject}/reject`); refreshOrder(); })));
      side.querySelectorAll('[data-cd]').forEach((s) => stopCountdowns.push(MW.countdown(s, s.dataset.cd)));
      const of = side.querySelector('[data-offerform]');
      of?.addEventListener('submit', async (e) => { e.preventDefault(); try { order = (await MW.post(`/orders/${id}/offer`, MW.values(of))).data; MW.toast('Nouveau prix envoyé aux chauffeurs.', 'success'); renderSide(); } catch (err) { MW.showErrors(of, err); } });
      bindRating(side, o);
      if (o.provider && active && side.querySelector('[data-chat]')) { chat = MW.chat(side.querySelector('[data-chat]'), id); }
    };

    const negotiationPanel = (o) => {
      const counters = (o.negotiations || []).filter((n) => n.proposed_by === 'provider' && n.status === 'pending');
      return `<div class="card request-card"><h3>Propositions des chauffeurs</h3>
        ${counters.length ? counters.map((n) => `<div class="list-item"><div class="grow"><div class="title">${MW.fcfa(n.amount)}</div><div class="meta">${MW.esc(n.provider.name)} · ${n.provider.rating_count ? '★ ' + n.provider.rating.toFixed(1) : 'nouveau'} · ${MW.esc(n.provider.vehicle)} · à ${MW.dist(n.provider.distance_m)} · expire dans <span class="countdown" data-cd="${n.expires_at}"></span></div></div>
          <div class="row" style="gap:6px"><button class="btn btn-sm btn-primary" data-accept="${n.id}">Accepter</button><button class="btn btn-sm btn-outline" data-reject="${n.id}">Refuser</button></div></div>`).join('')
        : '<p class="muted small">En attente des réponses des chauffeurs à proximité… Un chauffeur qui accepte votre prix est affecté immédiatement.</p>'}
        <form data-offerform class="input-group" style="margin-top:10px"><input class="input" type="number" step="50" name="amount" value="${o.offered_price}" aria-label="Nouveau prix"><button class="btn btn-outline">Modifier mon prix</button></form>
        <div class="small muted" style="margin-top:6px">Entre ${MW.fcfa(o.offer_bounds.min)} et ${MW.fcfa(o.offer_bounds.max)}. Demande valable jusqu'à ${MW.time(o.expires_at)}.</div></div>`;
    };

    const ratingCard = (o) => `<div class="card" data-rating><h3>Votre avis</h3>
      ${o.provider ? `<div class="label">${o.flow === 'ride' ? 'Chauffeur' : 'Livreur'}</div><div class="rating-stars" data-stars="provider">${[1, 2, 3, 4, 5].map((n) => `<button type="button" data-v="${n}" aria-label="${n} étoiles">★</button>`).join('')}</div><textarea class="input" data-comment="provider" maxlength="500" placeholder="Commentaire (facultatif)" style="margin:6px 0 10px"></textarea>` : ''}
      ${o.merchant ? `<div class="label">Restaurant / commerce</div><div class="rating-stars" data-stars="merchant">${[1, 2, 3, 4, 5].map((n) => `<button type="button" data-v="${n}">★</button>`).join('')}</div><textarea class="input" data-comment="merchant" maxlength="500" placeholder="Commentaire (facultatif)" style="margin:6px 0 10px"></textarea>` : ''}
      <div class="label">Qualité du service MARWA</div><div class="rating-stars" data-stars="service">${[1, 2, 3, 4, 5].map((n) => `<button type="button" data-v="${n}">★</button>`).join('')}</div>
      <button class="btn btn-primary" data-sendrating style="margin-top:10px">Envoyer</button></div>`;
    const bindRating = (side) => {
      const box = side.querySelector('[data-rating]'); if (!box) return;
      const scores = {};
      box.querySelectorAll('[data-stars]').forEach((g) => g.querySelectorAll('button').forEach((b) => b.addEventListener('click', () => {
        scores[g.dataset.stars] = Number(b.dataset.v);
        g.querySelectorAll('button').forEach((x) => x.classList.toggle('on', Number(x.dataset.v) <= scores[g.dataset.stars]));
      })));
      box.querySelector('[data-sendrating]').addEventListener('click', (e) => MW.busy(e.currentTarget, async () => {
        const body = {};
        Object.entries(scores).forEach(([k, v]) => { body[k + '_score'] = v; const c = box.querySelector(`[data-comment="${k}"]`); if (c && c.value.trim()) body[k + '_comment'] = c.value.trim(); });
        if (!Object.keys(body).length) { MW.toast('Choisissez au moins une note.', 'warn'); return; }
        await MW.post(`/orders/${id}/rate`, body); MW.toast('Merci pour votre avis !', 'success'); refreshOrder();
      }));
    };

    const refreshOrder = async () => { try { order = await MW.get('/orders/' + id); renderSide(); } catch (e) { /* réseau */ } };

    const tick = async () => {
      let t;
      try { t = await MW.get(`/orders/${id}/tracking`, { retries: 0 }); } catch (e) {
        const b = el.querySelector('[data-mapbadge]'); if (b) { b.hidden = false; b.classList.add('stale'); b.textContent = 'Réseau indisponible — dernière position affichée'; }
        return;
      }
      if (t.order.status !== lastStatus || (t.order.status === 'negotiating')) {
        if (lastStatus !== null && t.order.status !== lastStatus) MW.toast(t.order.status_label, 'info');
        lastStatus = t.order.status;
        await refreshOrder();
      }
      if (t.route && t.route.length > 1 && !layers.route) layers.route = L.polyline(t.route, { color: '#0b6e4f', weight: 4, opacity: .5, dashArray: t.route_source === 'estimate' ? '8 8' : null }).addTo(map);
      const badge = el.querySelector('[data-mapbadge]');
      if (t.position) {
        const ll = [t.position.lat, t.position.lng];
        if (!layers.vehicle) layers.vehicle = L.marker(ll, { icon: MW.vehicleIcon(t.vehicle.icon, t.position.heading, { stale: t.position.stale }), zIndexOffset: 1000 }).addTo(map);
        else { layers.vehicle.setIcon(MW.vehicleIcon(t.vehicle.icon, t.position.heading, { stale: t.position.stale })); MW.glide(layers.vehicle, ll); }
        if (t.trail && t.trail.length > 1) { if (layers.trail) layers.trail.setLatLngs(t.trail); else layers.trail = L.polyline(t.trail, { color: '#f4a623', weight: 4 }).addTo(map); }
        // Itinéraire restant en temps réel, du véhicule jusqu'au point à atteindre
        const tgt = t.eta && t.eta.target === 'pickup' ? [t.pickup.lat, t.pickup.lng] : [t.dropoff.lat, t.dropoff.lng];
        const live = t.remaining_route && t.remaining_route.length > 1 ? t.remaining_route : [ll, tgt];
        const liveStyle = { color: '#1f6fb2', weight: 6, opacity: .9, dashArray: t.remaining_route ? null : '8 10' };
        if (layers.live) { layers.live.setLatLngs(live); layers.live.setStyle(liveStyle); } else layers.live = L.polyline(live, liveStyle).addTo(map);
        map.getContainer().dataset.liveRoute = t.remaining_route ? 'road' : 'direct';
        if (layers.route) layers.route.setStyle({ opacity: .25 });
        if (!fitted) { MW.fitTo(map, [ll, tgt]); fitted = true; } else if (follow && !t.position.stale) map.panTo(ll, { animate: true });
        badge.hidden = false; badge.classList.toggle('stale', t.position.stale);
        badge.textContent = t.position.stale ? `⚠ Position non actualisée — dernière mise à jour à ${MW.time(t.position.updated_at)} (${MW.ago(t.position.age_seconds)})` : `Position mise à jour à ${MW.time(t.position.updated_at)}`;
        const etaEl = el.querySelector('[data-eta]');
        if (etaEl && t.eta) etaEl.innerHTML = `${MW.icon('clock', '')} ${t.eta.target === 'pickup' ? (order.flow === 'merchant' ? 'Arrivée au commerce' : 'Arrivée au point de départ') : 'Arrivée à destination'} vers <strong>${MW.time(t.eta.arrival_at)}</strong> · ${MW.dist(t.eta.remaining_m)} restants${t.eta.approximate ? ' (estimation approximative)' : ''}`;
      } else if (layers.vehicle && !['assigned', 'en_route_pickup', 'arrived_pickup', 'in_progress', 'picked_up', 'in_delivery', 'accepted', 'preparing', 'ready'].includes(t.order.status)) {
        layers.vehicle.remove(); layers.vehicle = null; badge.hidden = true;
        if (layers.live) { layers.live.remove(); layers.live = null; }
      } else if (order.provider && !t.position) {
        badge.hidden = false; badge.classList.add('stale'); badge.textContent = 'En attente de la position GPS du prestataire…';
      }
    };
    renderSide();
    lastStatus = order.status;
    await tick();
    const live = !['paid', 'cancelled', 'expired', 'refused', 'refunded'].includes(order.status);
    MW.realtime.setFast(live);
    const timer = setInterval(tick, 4000);
    const onEv = (ev) => { if (Number(ev.order_id) === Number(id) && ev.type !== 'message') tick(); };
    MW.realtime.on(onEv);
    MW.onLeave(() => { clearInterval(timer); MW.realtime.setFast(false); chat && chat.stop(); stopCountdowns.forEach((f) => f()); });
  }

  // ------------------------------------------------------------------ Historique, adresses
  async function history(el) {
    el.innerHTML = '<div class="container"><div class="page-head"><h1>Mes commandes</h1></div><div class="card" data-list></div></div>';
    const list = await MW.get('/orders?limit=50', { cache: true });
    el.querySelector('[data-list]').innerHTML = list.length ? `<div class="list">${list.map(orderRow).join('')}</div>` : MW.empty('Aucune commande pour le moment.');
  }

  async function addresses(el) {
    const list = await loadFavorites();
    el.innerHTML = `<div class="container"><div class="page-head"><h1>Adresses favorites</h1><button class="btn btn-primary" data-add>${MW.icon('plus')} Ajouter</button></div>
      <div class="card">${list.length ? `<div class="list">${list.map((a) => `<div class="list-item">${MW.icon('pin')}<div class="grow"><div class="title">${MW.esc(a.label)}</div><div class="meta">${MW.esc(a.address_text)}${a.landmark ? ' · ' + MW.esc(a.landmark) : ''}${a.lat ? '' : ' · <span class="badge amber">sans position GPS</span>'}</div></div>
        <button class="btn btn-sm btn-outline" data-edit="${a.id}">Modifier</button><button class="btn btn-sm btn-ghost" data-del="${a.id}">Supprimer</button></div>`).join('')}</div>` : MW.empty('Aucune adresse enregistrée.', 'pin')}</div></div>`;
    const edit = (a = {}) => {
      let place = null;
      MW.formModal(a.id ? 'Modifier l\'adresse' : 'Nouvelle adresse', [
        { name: 'label', label: 'Nom (Maison, Bureau…)', required: true, value: a.label, maxlength: 60 },
        { html: '<div data-place></div><div class="map small" data-pmap style="margin-bottom:12px"></div>' },
        { name: 'landmark', label: 'Point de repère', value: a.landmark, maxlength: 150, placeholder: 'Près de la mosquée, à côté de l\'école…' },
        { name: 'instructions', label: 'Instructions', value: a.instructions, maxlength: 255 }],
      async (v) => {
        const p = place.get();
        if (!p) throw new MW.ApiError(422, { error: { message: 'Indiquez l\'adresse.', fields: { dropoff_text: 'Adresse obligatoire.' } } });
        Object.assign(v, { address_text: p.text, lat: place.valid() ? p.lat : null, lng: place.valid() ? p.lng : null });
        if (a.id) await MW.put('/addresses/' + a.id, v); else await MW.post('/addresses', v);
        MW.toast('Adresse enregistrée.', 'success'); addresses(el);
      }, { onOpen: async (form) => {
        const map = await MW.createMap(form.querySelector('[data-pmap]'));
        place = new MW.PlaceField(form.querySelector('[data-place]'), { kind: 'dropoff', label: 'Adresse (saisie libre possible sans GPS)', map });
        if (a.address_text) { if (a.lat) place.set({ text: a.address_text, lat: a.lat, lng: a.lng }, true); else { place.input.value = a.address_text; place.value = { text: a.address_text, lat: null, lng: null }; } }
      } });
    };
    el.querySelector('[data-add]').addEventListener('click', () => edit());
    el.querySelectorAll('[data-edit]').forEach((b) => b.addEventListener('click', () => edit(list.find((a) => String(a.id) === b.dataset.edit))));
    el.querySelectorAll('[data-del]').forEach((b) => b.addEventListener('click', async () => { if (await MW.confirm('Supprimer cette adresse ?', { danger: true })) { await MW.del('/addresses/' + b.dataset.del); addresses(el); } }));
  }

  document.addEventListener('DOMContentLoaded', () => {
    MW.buildNav([
      { key: '', label: 'Accueil', icon: 'home', primary: true },
      { key: 'taxi', label: 'Taxi', icon: 'car', primary: true },
      { key: 'repas', label: 'Repas', icon: 'food', primary: true },
      { key: 'colis', label: 'Colis et courses', icon: 'box' },
      { key: 'historique', label: 'Mes commandes', short: 'Commandes', icon: 'list', primary: true },
      { key: 'adresses', label: 'Adresses favorites', icon: 'pin' },
      { key: 'profil', label: 'Profil', icon: 'user' },
      { key: 'assistance', label: 'Assistance', icon: 'help' }
    ]);
    MW.router({
      '/': home, '/taxi': taxi, '/colis': parcel, '/repas': restaurants, '/repas/:id': menu, '/panier/:id': checkout,
      '/commande/:id': track, '/historique': history, '/adresses': addresses,
      '/profil': (el) => MW.views.profile(el), '/assistance': (el, p) => MW.views.support(el, p), '/assistance/:id': (el, p) => MW.views.complaint(el, p)
    }, view());
  });
})();
