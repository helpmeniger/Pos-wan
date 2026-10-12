/* =====================================================================
   MARWA — espace chauffeur / livreur
   Le suivi GPS provient exclusivement de navigator.geolocation (appareil réel).
   ===================================================================== */
(function () {
  'use strict';
  const MW = window.MW;
  const isDriver = MW.user.role === 'driver';
  const P = { profile: null, online: false };

  // ------------------------------------------------------------------ GPS
  const GPS = {
    watchId: null, last: null, lastSent: 0, lastSentPos: null, status: 'off', wakeLock: null, listeners: [],
    on(fn) { this.listeners.push(fn); },
    emit() { this.listeners.forEach((f) => { try { f(this); } catch (e) { /* vue fermée */ } }); },
    start() {
      if (this.watchId !== null || !navigator.geolocation) { if (!navigator.geolocation) { this.status = 'unsupported'; this.emit(); } return; }
      this.status = 'waiting'; this.emit();
      this.watchId = navigator.geolocation.watchPosition((p) => this.onPosition(p), (e) => { this.status = e.code === 1 ? 'denied' : 'error'; this.emit(); },
        { enableHighAccuracy: true, maximumAge: 5000, timeout: 20000 });
      if ('wakeLock' in navigator) navigator.wakeLock.request('screen').then((w) => (this.wakeLock = w)).catch(() => {});
    },
    stop() {
      if (this.watchId !== null) navigator.geolocation.clearWatch(this.watchId);
      this.watchId = null; this.status = 'off'; this.wakeLock && this.wakeLock.release && this.wakeLock.release(); this.wakeLock = null; this.emit();
    },
    async onPosition(p) {
      const c = p.coords;
      this.last = { lat: c.latitude, lng: c.longitude, heading: (c.heading !== null && !isNaN(c.heading)) ? c.heading : null, speed: c.speed, accuracy: c.accuracy, at: Date.now() };
      this.status = 'ok'; this.emit();
      // Limitation des envois : toutes les 5 s au plus, ou dès 15 m de déplacement (économie de données)
      const moved = this.lastSentPos ? distance(this.lastSentPos, this.last) : Infinity;
      if (Date.now() - this.lastSent < 5000 && moved < 15) return;
      if (Date.now() - this.lastSent < 3000) return;
      this.lastSent = Date.now(); this.lastSentPos = this.last;
      try {
        await MW.post('/provider/location', { lat: this.last.lat, lng: this.last.lng, heading: this.last.heading, speed: this.last.speed, accuracy: this.last.accuracy });
        this.sentOk = Date.now();
      } catch (e) { this.sendError = e.message; }
      this.emit();
    }
  };
  const distance = (a, b) => { const R = 6371000, dLat = (b.lat - a.lat) * Math.PI / 180, dLng = (b.lng - a.lng) * Math.PI / 180;
    const x = Math.sin(dLat / 2) ** 2 + Math.cos(a.lat * Math.PI / 180) * Math.cos(b.lat * Math.PI / 180) * Math.sin(dLng / 2) ** 2; return 2 * R * Math.asin(Math.sqrt(x)); };
  const gpsText = () => ({ off: 'GPS arrêté', waiting: 'Recherche du signal GPS…', ok: `GPS actif · précision ${GPS.last ? Math.round(GPS.last.accuracy) : '?'} m${GPS.sentOk ? ' · envoyé à ' + MW.time(new Date(GPS.sentOk)) : ''}`,
    denied: 'Localisation refusée : autorisez-la dans les réglages du navigateur.', error: 'Signal GPS indisponible.', unsupported: 'Appareil sans géolocalisation.' }[GPS.status]);

  async function loadProfile() { P.profile = await MW.get('/provider/profile'); P.online = P.profile.profile.is_online; if (P.online) GPS.start(); return P.profile; }

  // ------------------------------------------------------------------ Tableau de bord
  async function dashboard(el) {
    const prof = await loadProfile();
    const pr = prof.profile;
    const earn = await MW.get('/provider/earnings').catch(() => null);
    el.innerHTML = `<div class="container">
      ${pr.verification_status !== 'verified' ? `<div class="alert alert-warn"><strong>Compte ${MW.esc(MW.L.verif[pr.verification_status])}.</strong> ${MW.esc(pr.verification_note || 'Complétez votre véhicule et vos documents : l\'équipe MARWA les vérifiera avant activation.')} <a href="#/documents">Mes documents</a></div>` : ''}
      <div class="online-toggle ${P.online ? 'on' : ''}" data-toggle-box><div><div style="font-size:1.2rem;font-weight:800" data-toggle-label>${P.online ? 'En ligne' : 'Hors ligne'}</div><div class="small" data-gps>${MW.esc(gpsText())}</div></div>
        <label class="switch"><input type="checkbox" data-online ${P.online ? 'checked' : ''} ${pr.verification_status !== 'verified' ? 'disabled' : ''} aria-label="Disponibilité"><span></span></label></div>
      <p class="small muted" style="margin-top:6px">Gardez l'application ouverte au premier plan pendant vos courses : la plupart des navigateurs suspendent la localisation en arrière-plan.</p>
      <div data-current></div>
      <div class="card" style="margin-top:14px"><div class="card-title"><h2>Demandes à proximité</h2><span class="badge" data-reqcount>0</span></div><div data-requests>${P.online ? MW.empty('Aucune demande pour le moment.', 'bell') : MW.empty('Passez en ligne pour recevoir des demandes.', 'bell')}</div></div>
      ${earn ? `<div class="grid-3" style="margin-top:14px">
        <div class="stat highlight"><p class="label">Aujourd'hui (net)</p><div class="value">${MW.fcfa(earn.today.net)}</div><div class="sub">${earn.today.n} commande(s)</div></div>
        <div class="stat"><p class="label">Cette semaine (net)</p><div class="value">${MW.fcfa(earn.week.net)}</div><div class="sub">brut ${MW.fcfa(earn.week.gross)}</div></div>
        <div class="stat"><p class="label">${earn.summary.balance >= 0 ? 'MARWA vous doit' : 'Commission à reverser à MARWA'}</p><div class="value">${MW.fcfa(Math.abs(earn.summary.balance))}</div><div class="sub"><a href="#/revenus">Détails</a></div></div></div>` : ''}
    </div>`;
    const toggle = el.querySelector('[data-online]');
    toggle.addEventListener('change', async () => {
      try {
        let pos = {};
        if (toggle.checked) { try { const p = await MW.locate({ timeout: 10000 }); pos = { lat: p.lat, lng: p.lng }; } catch (e) { MW.toast(e.message + ' Vous ne recevrez des demandes qu\'une fois localisé.', 'warn', 8000); } }
        await MW.post('/provider/online', Object.assign({ online: toggle.checked }, pos));
        P.online = toggle.checked;
        if (P.online) GPS.start(); else GPS.stop();
        dashboard(el);
      } catch (e) { toggle.checked = !toggle.checked; MW.error(e); }
    });
    GPS.on(() => { const g = el.querySelector('[data-gps]'); if (g) g.textContent = gpsText(); });
    const cur = await MW.get('/provider/current').catch(() => null);
    if (cur) {
      el.querySelector('[data-current]').innerHTML = `<a class="card request-card" style="display:block;margin-top:14px;text-decoration:none;color:inherit" href="#/course"><div class="row between"><div><div class="small muted">Commande en cours</div><strong>${MW.esc(MW.L.service[cur.service_type])} · ${MW.esc(cur.reference)}</strong><div>${MW.statusBadge(cur.status)}</div></div><span class="btn btn-primary">Ouvrir</span></div></a>`;
    }
    let stops = [];
    const loadRequests = async () => {
      if (!P.online) return;
      let reqs = [];
      try { reqs = await MW.get('/provider/requests', { retries: 0 }); } catch (e) { return; }
      stops.forEach((f) => f()); stops = [];
      const box = el.querySelector('[data-requests]'); if (!box) return;
      el.querySelector('[data-reqcount]').textContent = reqs.length;
      box.innerHTML = reqs.length ? reqs.map((o) => requestCard(o)).join('') : MW.empty(cur ? 'Terminez votre commande en cours pour recevoir de nouvelles demandes.' : 'Aucune demande pour le moment. Restez à proximité des zones animées.', 'bell');
      box.querySelectorAll('[data-cd]').forEach((s) => stops.push(MW.countdown(s, s.dataset.cd, loadRequests)));
      box.querySelectorAll('[data-acc]').forEach((b) => b.addEventListener('click', () => MW.busy(b, async () => { await MW.post(`/provider/requests/${b.dataset.acc}/accept`); MW.toast('Commande acceptée !', 'success'); location.hash = '#/course'; })));
      box.querySelectorAll('[data-ref]').forEach((b) => b.addEventListener('click', () => MW.busy(b, async () => { await MW.post(`/provider/requests/${b.dataset.ref}/refuse`, {}); loadRequests(); })));
      box.querySelectorAll('[data-ctr]').forEach((b) => b.addEventListener('click', () => {
        const o = reqs.find((x) => String(x.id) === b.dataset.ctr);
        MW.formModal('Contre-proposition', [
          { name: 'amount', label: `Votre prix (entre ${MW.fcfa(o.offer_bounds.min)} et ${MW.fcfa(o.offer_bounds.max)})`, type: 'number', step: 50, required: true, value: o.offered_price },
          { name: 'ttl_seconds', label: 'Validité de votre proposition', type: 'select', value: 90, options: [[60, '1 minute'], [90, '1 min 30'], [120, '2 minutes'], [300, '5 minutes']] }],
        async (v) => { v.ttl_seconds = Number(v.ttl_seconds); await MW.post(`/provider/requests/${o.id}/counter`, v); MW.toast('Proposition envoyée au client.', 'success'); loadRequests(); }, { ok: 'Envoyer' });
      }));
    };
    await loadRequests();
    const t = setInterval(loadRequests, 6000);
    MW.realtime.setFast(true);
    const onEv = (ev) => { if (['notification', 'offer_updated', 'counter_rejected'].includes(ev.type)) { if (ev.payload && ev.payload.type === 'order_assigned') location.hash = '#/course'; else loadRequests(); } };
    MW.realtime.on(onEv);
    MW.onLeave(() => { clearInterval(t); stops.forEach((f) => f()); MW.realtime.setFast(false); });
  }

  const requestCard = (o) => {
    const neg = o.pricing_mode === 'negotiated';
    const price = neg ? o.offered_price : (o.flow === 'merchant' ? o.delivery_fee : (o.final_price || o.estimated_price));
    const myCounter = (o.my_counters || []).find((c) => c.status === 'pending');
    return `<div class="card request-card" style="margin-bottom:10px">
      <div class="row between"><strong>${MW.icon(o.flow === 'ride' ? 'car' : o.flow === 'merchant' ? 'food' : 'box')} ${MW.esc(MW.L.service[o.service_type])}${neg ? ' · <span class="badge amber">Prix proposé par le client</span>' : ''}</strong><span class="small">expire dans <span class="countdown" data-cd="${o.offer_expires_at}"></span></span></div>
      <dl class="kv" style="margin-top:8px"><dt>Distance jusqu'au départ</dt><dd>${MW.dist(o.offer_distance_m)}</dd><dt>Secteur de départ</dt><dd>${MW.esc(o.pickup.text)}</dd><dt>Secteur d'arrivée</dt><dd>${MW.esc(o.dropoff.text)}</dd>
        <dt>Trajet</dt><dd>${MW.dist(o.distance_m)} · ~${MW.dur(o.duration_s)}</dd>${o.passengers > 1 ? `<dt>Passagers</dt><dd>${o.passengers}</dd>` : ''}
        ${o.delivery ? `<dt>Colis</dt><dd>${MW.esc(MW.L.package[o.delivery.package_type] || '')}${o.delivery.weight_kg ? ' · ' + o.delivery.weight_kg + ' kg' : ''}</dd>` : ''}
        <dt>${neg ? 'Prix du client' : 'Prix'}</dt><dd style="font-size:1.15rem">${MW.fcfa(price)}</dd>
        ${neg ? `<dt>Estimation MARWA</dt><dd>${MW.fcfa(o.estimated_price)}</dd>` : ''}
        ${o.earnings_preview ? `<dt>Commission MARWA</dt><dd>${MW.fcfa(o.earnings_preview.commission)}</dd><dt>Votre gain</dt><dd style="color:var(--green-700)">${MW.fcfa(o.earnings_preview.partner_net)}</dd>` : ''}
        <dt>Paiement</dt><dd>${MW.esc(MW.L.payment[o.payment_method])}</dd></dl>
      ${myCounter ? `<div class="alert alert-info" style="margin-top:8px">Votre proposition de ${MW.fcfa(myCounter.amount)} attend la réponse du client.</div>` : ''}
      <div class="row" style="margin-top:10px"><button class="btn btn-primary" data-acc="${o.id}">Accepter ${neg ? MW.fcfa(price) : ''}</button>
        ${neg ? `<button class="btn btn-accent" data-ctr="${o.id}">Contre-proposer</button>` : ''}<button class="btn btn-outline" data-ref="${o.id}">Refuser</button></div></div>`;
  };

  // ------------------------------------------------------------------ Commande en cours
  const ACTION_LABEL = {
    en_route_pickup: (o) => o.flow === 'merchant' ? 'Je pars vers le commerce' : 'Je suis en route', arrived_pickup: (o) => o.flow === 'merchant' ? 'Je suis au commerce' : 'Je suis arrivé',
    in_progress: () => 'Client à bord : démarrer', completed: () => 'Terminer la course', picked_up: (o) => o.flow === 'merchant' ? 'Commande récupérée' : 'Colis récupéré',
    delivered: () => 'Livrer (preuve)', delivery_failed: () => 'Livraison impossible', in_delivery: () => 'Nouvelle tentative', returning: () => 'Retour à l\'expéditeur', returned: () => 'Retour effectué',
    release: () => 'Me désister', cancelled: () => 'Client absent : annuler', declare_cash: () => 'Déclarer l\'encaissement'
  };

  async function course(el) {
    const o0 = await MW.get('/provider/current');
    if (!o0) { el.innerHTML = `<div class="container"><div class="card">${MW.empty('Aucune commande en cours.', 'car')}<p style="text-align:center"><a class="btn btn-primary" href="#/">Voir les demandes</a></p></div></div>`; return; }
    let o = o0;
    el.innerHTML = `<div class="container"><div class="page-head"><h1>${MW.esc(MW.L.service[o.service_type])} · ${MW.esc(o.reference)}</h1><button class="btn btn-danger btn-sm" data-sos>${MW.icon('sos')} Urgence</button></div>
      <div class="order-layout"><div class="stack" data-side></div><div class="card" style="padding:8px"><div class="map-wrap"><div id="job-map" class="map tall"></div><div class="map-badge" data-gpsbadge></div></div></div></div></div>`;
    el.querySelector('[data-sos]').addEventListener('click', () => MW.sos(o.id));
    const map = await MW.createMap(el.querySelector('#job-map'));
    L.marker([o.pickup.lat, o.pickup.lng], { icon: MW.pinIcon(o.flow === 'merchant' ? 'merchant' : 'pickup') }).addTo(map).bindTooltip('Départ');
    L.marker([o.dropoff.lat, o.dropoff.lng], { icon: MW.pinIcon('dropoff') }).addTo(map).bindTooltip('Arrivée');
    const vehicle = isDriver ? (P.profile?.vehicle?.category === 'moto_taxi' ? 'moto' : 'car') : (['moto', 'bicycle'].includes(P.profile?.vehicle?.category) ? P.profile.vehicle.category : 'car');
    let me = null; let routeLine = null; let chat = null; let fitted = false;
    const updateMe = () => {
      const b = el.querySelector('[data-gpsbadge]'); if (b) b.textContent = gpsText();
      if (!GPS.last || !map) return;
      const ll = [GPS.last.lat, GPS.last.lng];
      if (!me) me = L.marker(ll, { icon: MW.vehicleIcon(vehicle, GPS.last.heading || 0), zIndexOffset: 1000 }).addTo(map); else { me.setIcon(MW.vehicleIcon(vehicle, GPS.last.heading || 0)); MW.glide(me, ll); }
      if (!fitted) { MW.fitTo(map, [ll, [o.pickup.lat, o.pickup.lng]]); fitted = true; }
    };
    GPS.start(); GPS.on(updateMe); updateMe();

    const render = () => {
      const toPickup = ['assigned', 'en_route_pickup', 'accepted', 'preparing', 'ready'].includes(o.status) || (o.flow === 'merchant' && ['accepted', 'preparing', 'ready'].includes(o.status));
      const target = toPickup ? o.pickup : o.dropoff;
      const acts = (o.actions || []).filter((a) => ACTION_LABEL[a]);
      const side = el.querySelector('[data-side]');
      side.innerHTML = `<div class="card"><div class="small muted">Statut</div><h2 style="margin:0 0 6px">${MW.esc(o.status_label)}</h2>
          ${o.flow === 'merchant' && o.provider_stage ? `<div class="small">Votre étape : ${MW.esc({ assigned: 'affecté', en_route_pickup: 'en route vers le commerce', arrived_pickup: 'au commerce', picked_up: 'commande récupérée' }[o.provider_stage] || '')}</div>` : ''}
          <div class="big-actions" style="margin-top:12px">${acts.map((a) => `<button class="btn ${['release', 'cancelled', 'delivery_failed'].includes(a) ? 'btn-outline' : 'btn-primary'} btn-lg" data-act="${a}">${MW.esc(ACTION_LABEL[a](o))}</button>`).join('')}</div></div>
        <div class="card"><h3>${toPickup ? (o.flow === 'merchant' ? 'Récupérer au commerce' : 'Prise en charge') : 'Destination'}</h3><p><strong>${MW.esc(target.text)}</strong>${target.notes ? `<br><span class="small">${MW.esc(target.notes)}</span>` : ''}</p>${MW.navLinks(target.lat, target.lng)}</div>
        <div class="card"><dl class="kv">
          ${o.customer ? `<dt>Client</dt><dd>${MW.esc(o.customer.name)}</dd>` : ''}
          ${o.delivery ? `<dt>Destinataire</dt><dd>${MW.esc(o.delivery.recipient_name)}${o.delivery.recipient_phone ? `<br><a href="tel:${MW.esc(o.delivery.recipient_phone)}">${MW.esc(o.delivery.recipient_phone)}</a>` : ''}</dd>
            <dt>Contenu</dt><dd>${MW.esc(o.delivery.description || '')}</dd>${o.delivery.instructions ? `<dt>Instructions</dt><dd>${MW.esc(o.delivery.instructions)}</dd>` : ''}<dt>Preuve exigée</dt><dd>${MW.esc({ otp: 'Code du destinataire', photo: 'Photo', signature: 'Signature' }[o.delivery.proof_type])}</dd>` : ''}
          ${(o.items || []).map((i) => `<dt>${i.quantity} × ${MW.esc(i.name)}</dt><dd>${MW.fcfa(i.line_total)}</dd>`).join('')}
          ${o.notes ? `<dt>Message</dt><dd>${MW.esc(o.notes)}</dd>` : ''}
          <dt>${o.flow === 'merchant' ? 'Frais de livraison' : 'Prix convenu'}</dt><dd>${MW.fcfa(o.flow === 'merchant' ? o.delivery_fee : o.final_price)}</dd>
          ${o.earnings_preview ? `<dt>Votre gain estimé</dt><dd style="color:var(--green-700)">${MW.fcfa(o.earnings_preview.partner_net)}</dd>` : ''}
          <dt>Paiement</dt><dd>${MW.esc(MW.L.payment[o.payment_method])}${o.payment_method === 'cash' ? ` — <strong>à encaisser : ${MW.fcfa(o.total)}</strong>` : ''}</dd></dl></div>
        <div class="card"><h3>Messagerie</h3><div data-chat></div></div>`;
      side.querySelectorAll('[data-act]').forEach((b) => b.addEventListener('click', () => act(b, b.dataset.act)));
      if (!chat) chat = MW.chat(side.querySelector('[data-chat]'), o.id); else chat = MW.chat(side.querySelector('[data-chat]'), o.id);
      if (routeLine) routeLine.remove();
      if (GPS.last) routeLine = L.polyline([[GPS.last.lat, GPS.last.lng], [target.lat, target.lng]], { color: '#1f6fb2', weight: 3, dashArray: '6 8' }).addTo(map);
    };

    const after = (r) => { o = r.data; if (['paid'].includes(o.status) || (o.payment_status === 'paid' && ['completed', 'delivered', 'returned'].includes(o.status))) { MW.toast('Commande terminée et réglée. Merci !', 'success'); location.hash = '#/'; return; } render(); };
    const post = (action, extra = {}) => MW.post(`/provider/orders/${o.id}/action`, Object.assign({ action }, extra));

    async function act(btn, a) {
      if (a === 'release' || a === 'cancelled') {
        const reason = await MW.confirm(a === 'release' ? 'Vous désister de cette commande ? Elle sera proposée à un autre prestataire.' : 'Le client est absent ? La commande sera annulée.', { reason: 'Motif (obligatoire)', reasonRequired: true, danger: true });
        if (!reason) return;
        return MW.busy(btn, async () => { await post(a, { reason }); MW.toast('C\'est noté.', 'success'); location.hash = '#/'; });
      }
      if (a === 'completed' || a === 'declare_cash' || (a === 'delivered' && o.payment_method === 'cash' && o.flow !== 'ride')) {
        if (a === 'delivered') return proofModal(true);
        if (o.payment_method !== 'cash') return MW.busy(btn, async () => after(await post(a)));
        return MW.formModal(a === 'completed' ? 'Terminer la course' : 'Encaissement en espèces', [
          { html: `<div class="alert alert-info">Montant convenu : <strong>${MW.fcfa(o.total)}</strong>. Déclarez le montant réellement reçu ; un écart sera vérifié par la finance.</div>` },
          { name: 'amount_collected', label: 'Montant encaissé (FCFA)', type: 'number', required: true, value: o.total, min: 0 }],
        async (v) => after(a === 'completed' ? await post('completed', v) : await MW.post(`/provider/orders/${o.id}/cash`, v)), { ok: 'Valider' });
      }
      if (a === 'delivered') return proofModal(false);
      if (a === 'delivery_failed') {
        const reason = await MW.confirm('Signaler une livraison impossible ?', { reason: 'Raison (destinataire absent, adresse introuvable…)', reasonRequired: true });
        if (!reason) return;
        return MW.busy(btn, async () => after(await post(a, { reason })));
      }
      return MW.busy(btn, async () => after(await post(a)));
    }

    function proofModal(withCash) {
      const type = o.delivery ? o.delivery.proof_type : 'otp';
      const fields = [];
      if (type === 'otp') fields.push({ name: 'delivery_code', label: 'Code à 4 chiffres donné par le destinataire', required: true, maxlength: 4, type: 'text' });
      if (type === 'photo') fields.push({ html: '<div class="field" data-field="proof_file_id"><label>Photo de la remise</label><input type="file" accept="image/*" capture="environment" class="input" data-photo></div>' });
      if (type === 'signature') fields.push({ html: '<div class="field" data-field="signature"><label>Signature du destinataire</label><canvas data-sig width="320" height="160" style="border:1.5px dashed var(--sand-200);border-radius:10px;touch-action:none;width:100%;background:#fff"></canvas><button type="button" class="btn btn-sm btn-ghost" data-clear>Effacer</button></div>' });
      if (withCash) fields.push({ html: `<div class="alert alert-info">À encaisser : <strong>${MW.fcfa(o.total)}</strong></div>` }, { name: 'amount_collected', label: 'Montant encaissé (FCFA)', type: 'number', required: true, value: o.total, min: 0 });
      let sigDirty = false;
      MW.formModal('Confirmer la livraison', fields, async (v, form) => {
        if (type === 'photo') {
          const f = form.querySelector('[data-photo]').files[0];
          if (!f) throw new MW.ApiError(422, { error: { message: 'Ajoutez une photo.', fields: { proof_file_id: 'Photo obligatoire.' } } });
          const fd = new FormData(); fd.append('file', f);
          v.proof_file_id = (await MW.api('POST', '/provider/proof', fd)).data.file_id;
        }
        if (type === 'signature') {
          if (!sigDirty) throw new MW.ApiError(422, { error: { message: 'La signature est requise.', fields: { signature: 'Faites signer le destinataire.' } } });
          v.signature = form.querySelector('[data-sig]').toDataURL('image/png');
        }
        after(await post('delivered', v));
      }, { ok: 'Confirmer la livraison', onOpen: (form) => {
        const c = form.querySelector('[data-sig]'); if (!c) return;
        const ctx = c.getContext('2d'); ctx.lineWidth = 2.4; ctx.lineCap = 'round'; ctx.strokeStyle = '#17201d';
        let drawing = false;
        const pt = (e) => { const r = c.getBoundingClientRect(); return [(e.clientX - r.left) * c.width / r.width, (e.clientY - r.top) * c.height / r.height]; };
        c.addEventListener('pointerdown', (e) => { drawing = true; sigDirty = true; ctx.beginPath(); ctx.moveTo(...pt(e)); });
        c.addEventListener('pointermove', (e) => { if (drawing) { ctx.lineTo(...pt(e)); ctx.stroke(); } });
        ['pointerup', 'pointerleave'].forEach((n) => c.addEventListener(n, () => (drawing = false)));
        form.querySelector('[data-clear]').addEventListener('click', () => { ctx.clearRect(0, 0, c.width, c.height); sigDirty = false; });
      } });
    }

    render();
    const refresh = async () => { try { const n = await MW.get('/provider/current', { retries: 0 }); if (!n) { location.hash = '#/'; return; } if (n.status !== o.status || n.provider_stage !== o.provider_stage || n.payment_status !== o.payment_status) { o = n; render(); } } catch (e) { /* réseau */ } };
    const t = setInterval(refresh, 8000);
    MW.realtime.setFast(true);
    MW.onLeave(() => { clearInterval(t); MW.realtime.setFast(false); chat && chat.stop(); GPS.listeners = GPS.listeners.filter((f) => f !== updateMe); });
  }

  // ------------------------------------------------------------------ Historique, revenus, documents
  async function history(el) {
    const list = await MW.get('/provider/history?limit=50', { cache: true });
    el.innerHTML = `<div class="container"><div class="page-head"><h1>Historique</h1></div>${MW.table([
      { key: 'reference', label: 'Commande' }, { key: 'created_at', label: 'Date', fmt: (r) => MW.datetime(r.created_at) },
      { key: 'service_type', label: 'Service', fmt: (r) => MW.esc(MW.L.service[r.service_type]) }, { key: 'status', label: 'Statut', fmt: (r) => MW.statusBadge(r.status) },
      { key: 'final_price', label: 'Prix', num: true, fmt: (r) => MW.fcfa(r.flow === 'merchant' ? r.delivery_fee : r.final_price) },
      { key: 'commission', label: 'Commission', num: true, fmt: (r) => r.net !== null ? MW.fcfa(r.commission) : '—' }, { key: 'net', label: 'Net', num: true, fmt: (r) => r.net !== null ? MW.fcfa(r.net) : '—' }], list, { empty: 'Aucune commande.' })}</div>`;
  }

  async function earnings(el) {
    const d = await MW.get('/provider/earnings');
    const s = d.summary;
    el.innerHTML = `<div class="container"><div class="page-head"><h1>Revenus et versements</h1><button class="btn btn-primary" data-payout ${s.available > 0 ? '' : 'disabled'}>Demander un retrait</button></div>
      <div class="grid-4">
        <div class="stat highlight"><p class="label">Disponible au retrait</p><div class="value">${MW.fcfa(s.available)}</div><div class="sub">${s.pending_payouts ? 'dont ' + MW.fcfa(s.pending_payouts) + ' en cours' : 'aucune demande en cours'}</div></div>
        <div class="stat"><p class="label">Commission à reverser</p><div class="value">${MW.fcfa(s.owed_to_marwa)}</div><div class="sub">sur encaissements en espèces</div></div>
        <div class="stat"><p class="label">Prestations (brut)</p><div class="value">${MW.fcfa(s.gross)}</div><div class="sub">${s.orders} commande(s)</div></div>
        <div class="stat"><p class="label">Commissions MARWA</p><div class="value">${MW.fcfa(s.commission)}</div><div class="sub">net perçu ${MW.fcfa(s.net)}</div></div></div>
      ${s.owed_to_marwa > 0 ? '<div class="alert alert-warn" style="margin-top:14px">Vous avez encaissé des courses en espèces : la commission MARWA correspondante est à reverser auprès de l\'équipe MARWA (un reçu vous sera remis).</div>' : ''}
      <div class="grid-2" style="margin-top:14px"><div><h2>Relevé de compte</h2>${MW.table([
        { key: 'occurred_at', label: 'Date', fmt: (r) => MW.datetime(r.occurred_at) }, { key: 'description', label: 'Opération', fmt: (r) => `${MW.esc(r.memo || r.description)}${r.order_reference ? `<br><span class="small muted">${MW.esc(r.order_reference)}</span>` : ''}` },
        { key: 'credit', label: 'Crédit', num: true, fmt: (r) => r.credit > 0 ? MW.fcfa(r.credit) : '' }, { key: 'debit', label: 'Débit', num: true, fmt: (r) => r.debit > 0 ? MW.fcfa(r.debit) : '' }], d.statement, { empty: 'Aucune opération.' })}</div>
      <div><h2>Retraits et reversements</h2>${MW.table([
        { key: 'requested_at', label: 'Date', fmt: (r) => MW.date(r.requested_at) }, { key: 'direction', label: 'Type', fmt: (r) => r.direction === 'to_partner' ? 'Retrait' : 'Reversement' },
        { key: 'amount', label: 'Montant', num: true, fmt: (r) => MW.fcfa(r.amount) }, { key: 'status', label: 'Statut', fmt: (r) => `<span class="badge ${r.status === 'paid' ? 'green' : r.status === 'rejected' ? 'red' : 'amber'}">${MW.esc({ requested: 'Demandé', approved: 'Approuvé', paid: 'Payé', rejected: 'Rejeté', cancelled: 'Annulé' }[r.status])}</span>` },
        { key: 'reference', label: 'Justificatif', fmt: (r) => MW.esc(r.reference || '') }], d.payouts, { empty: 'Aucun versement.' })}</div></div></div>`;
    el.querySelector('[data-payout]').addEventListener('click', () => MW.formModal('Demande de retrait', [
      { name: 'amount', label: `Montant (max. ${MW.fcfa(s.available)})`, type: 'number', required: true, max: s.available, min: 500 },
      { name: 'method', label: 'Moyen', type: 'select', options: [['mobile_money', 'Mobile Money'], ['cash', 'Espèces (agence MARWA)'], ['bank_transfer', 'Virement bancaire']] },
      { name: 'destination', label: 'Numéro / compte de destination', maxlength: 120 }],
    async (v) => { await MW.post('/provider/payouts', v); MW.toast('Demande envoyée. Elle sera validée par la finance MARWA.', 'success'); earnings(el); }, { ok: 'Envoyer la demande' }));
  }

  async function documents(el) {
    const d = await loadProfile();
    const pr = d.profile; const v = d.vehicle || {};
    el.innerHTML = `<div class="container"><div class="page-head"><h1>Véhicule et documents</h1><span class="badge ${pr.verification_status === 'verified' ? 'green' : 'amber'}">Compte ${MW.esc(MW.L.verif[pr.verification_status])}</span></div>
      <div class="grid-2">
        <div class="card"><h2>Identité professionnelle</h2><form data-prof novalidate>${MW.fields([
          { name: 'id_number', label: 'N° de pièce d\'identité', value: pr.id_number, maxlength: 60 },
          { name: 'license_number', label: 'N° de permis de conduire', value: pr.license_number, maxlength: 60 },
          { name: 'license_expires_at', label: 'Expiration du permis', type: 'date', value: pr.license_expires_at }])}<button class="btn btn-primary">Enregistrer</button></form>
          <p class="small muted" style="margin-top:8px">Toute modification d'un compte validé déclenche une nouvelle vérification.</p></div>
        <div class="card"><h2>Véhicule</h2><form data-veh novalidate>${MW.fields([
          { name: 'category', label: 'Type', type: 'select', value: v.category, options: d.vehicle_categories.map((c) => [c, MW.L.vehicle[c]]) },
          { name: 'make', label: 'Marque', value: v.make, maxlength: 60 }, { name: 'model', label: 'Modèle', value: v.model, maxlength: 60 },
          { name: 'color', label: 'Couleur', value: v.color, maxlength: 40 }, { name: 'plate', label: 'Immatriculation', value: v.plate, maxlength: 30 },
          { name: 'capacity', label: 'Places passagers', type: 'number', value: v.capacity || (isDriver ? 4 : 1), min: 1, max: 30 }])}
          <div class="row"><button class="btn btn-primary">Enregistrer</button><label class="btn btn-outline btn-sm">Photo du véhicule<input type="file" accept="image/*" hidden data-vphoto></label></div></form></div>
      </div>
      <div class="card" style="margin-top:14px"><div class="card-title"><h2>Documents</h2><button class="btn btn-primary btn-sm" data-upload>${MW.icon('plus')} Ajouter</button></div>
        ${MW.table([{ key: 'doc_type', label: 'Document', fmt: (r) => MW.esc(MW.L.doc[r.doc_type]) }, { key: 'expires_at', label: 'Expiration', fmt: (r) => MW.date(r.expires_at) },
          { key: 'status', label: 'Statut', fmt: (r) => `<span class="badge ${r.status === 'approved' ? 'green' : r.status === 'rejected' ? 'red' : 'amber'}">${MW.esc({ pending: 'En vérification', approved: 'Approuvé', rejected: 'Refusé' }[r.status])}</span>${r.review_note ? `<div class="small muted">${MW.esc(r.review_note)}</div>` : ''}` },
          { key: 'created_at', label: 'Envoyé le', fmt: (r) => MW.date(r.created_at) }], d.documents, { empty: 'Aucun document envoyé.' })}
        <p class="small muted" style="margin-top:10px">Requis : pièce d'identité, ${isDriver ? 'permis de conduire, carte grise, assurance' : 'pièce du véhicule (si motorisé)'}. Formats JPEG, PNG, WebP ou PDF, 4 Mo max.</p></div></div>`;
    const prof = el.querySelector('[data-prof]');
    prof.addEventListener('submit', async (e) => { e.preventDefault(); try { const r = await MW.put('/provider/profile', MW.values(prof)); MW.toast(r.message, 'success'); } catch (err) { MW.showErrors(prof, err); } });
    const veh = el.querySelector('[data-veh]');
    veh.addEventListener('submit', async (e) => { e.preventDefault(); try { await MW.post('/provider/vehicle', MW.values(veh)); MW.toast('Véhicule enregistré.', 'success'); documents(el); } catch (err) { MW.showErrors(veh, err); } });
    el.querySelector('[data-vphoto]').addEventListener('change', async (e) => { const fd = new FormData(); fd.append('file', e.target.files[0]); try { await MW.api('POST', '/provider/vehicle/photo', fd); MW.toast('Photo enregistrée.', 'success'); } catch (err) { MW.error(err); } });
    el.querySelector('[data-upload]').addEventListener('click', () => MW.formModal('Ajouter un document', [
      { name: 'doc_type', label: 'Type', type: 'select', options: Object.entries(MW.L.doc) },
      { name: 'expires_at', label: 'Date d\'expiration (si applicable)', type: 'date' },
      { html: '<div class="field" data-field="file"><label>Fichier *</label><input class="input" type="file" accept="image/jpeg,image/png,image/webp,application/pdf" data-file></div>' }],
    async (v, form) => {
      const f = form.querySelector('[data-file]').files[0];
      if (!f) throw new MW.ApiError(422, { error: { message: 'Choisissez un fichier.', fields: { file: 'Fichier obligatoire.' } } });
      const fd = new FormData(); fd.append('file', f); fd.append('doc_type', v.doc_type); if (v.expires_at) fd.append('expires_at', v.expires_at);
      await MW.api('POST', '/provider/documents', fd); MW.toast('Document envoyé pour vérification.', 'success'); documents(el);
    }, { ok: 'Envoyer' }));
  }

  document.addEventListener('DOMContentLoaded', () => {
    MW.buildNav([
      { key: '', label: 'Tableau de bord', short: 'Accueil', icon: 'home', primary: true },
      { key: 'course', label: 'Commande en cours', short: 'En cours', icon: isDriver ? 'car' : 'moto', primary: true },
      { key: 'revenus', label: 'Revenus', icon: 'wallet', primary: true },
      { key: 'historique', label: 'Historique', icon: 'list', primary: true },
      { key: 'documents', label: 'Véhicule et documents', icon: 'file' },
      { key: 'profil', label: 'Profil', icon: 'user' },
      { key: 'assistance', label: 'Assistance', icon: 'help' }
    ]);
    MW.router({
      '/': dashboard, '/course': course, '/revenus': earnings, '/historique': history, '/documents': documents,
      '/profil': (el) => MW.views.profile(el), '/assistance': (el, p) => MW.views.support(el, p), '/assistance/:id': (el, p) => MW.views.complaint(el, p)
    }, document.getElementById('view'));
  });
})();
