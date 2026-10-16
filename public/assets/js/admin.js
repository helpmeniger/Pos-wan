/* =====================================================================
   MARWA — console d'administration
   L'affichage des menus suit les permissions, mais chaque appel est
   de toute façon contrôlé côté serveur.
   ===================================================================== */
(function () {
  'use strict';
  const MW = window.MW;
  const can = (p) => (MW.user.permissions || []).includes(p);
  const today = () => MW.isoDate(new Date());
  const monthStart = () => { const d = new Date(); return MW.isoDate(new Date(d.getFullYear(), d.getMonth(), 1)); };
  const SERVICES = [['', '—'], ...Object.entries(MW.L.service)];
  const head = (title, actions = '') => `<div class="page-head"><h1>${MW.esc(title)}</h1><div class="row">${actions}</div></div>`;
  const yes = (v) => Number(v) ? '<span class="badge green">Oui</span>' : '<span class="badge gray">Non</span>';
  const verifBadge = (s) => `<span class="badge ${{ verified: 'green', pending: 'amber', suspended: 'red', refused: 'gray' }[s] || ''}">${MW.esc(MW.L.verif[s] || s)}</span>`;

  // ------------------------------------------------------------------ Tableau de bord
  async function dashboard(el, params) {
    const from = params.from || monthStart(); const to = params.to || today();
    const d = await MW.get(`/admin/dashboard?from=${from}&to=${to}`);
    const f = d.finance; const o = d.operations; const u = d.users;
    const stat = (label, value, sub = '', hl = false) => `<div class="stat ${hl ? 'highlight' : ''}"><p class="label">${label}</p><div class="value">${value}</div><div class="sub">${sub}</div></div>`;
    el.innerHTML = `<div class="container">${head('Tableau de bord de direction', `<form class="row" data-f><input class="input" type="date" name="from" value="${from}" style="width:auto"><input class="input" type="date" name="to" value="${to}" style="width:auto"><button class="btn btn-primary">Filtrer</button></form>`)}
      ${d.ledger_check ? '' : '<div class="alert alert-error">Alerte : le grand livre n\'est pas équilibré. Consultez le rapprochement.</div>'}
      <h2>Résultats financiers <span class="small muted">(${MW.date(from)} → ${MW.date(to)})</span></h2>
      <div class="grid-4">
        ${stat('CA brut des services', MW.fcfa(f.gross_revenue), `${f.settled_orders} commandes réglées`, true)}
        ${stat('Commissions acquises', MW.fcfa(f.commissions_earned), `+ frais ${MW.fcfa(f.fees_earned)} · abonnements ${MW.fcfa(f.subscriptions_earned)}`)}
        ${stat('Commissions encaissées', MW.fcfa(f.commissions_collected), 'électronique + reversements reçus')}
        ${stat('Marge estimée', MW.fcfa(f.margin), 'revenus − promos − frais − remboursements')}
        ${stat('Dû aux partenaires', MW.fcfa(f.owed_to_partners), 'soldes créditeurs actuels')}
        ${stat('Dû par les partenaires', MW.fcfa(f.owed_by_partners), 'commissions sur espèces à reverser')}
        ${stat('Paiements en attente', MW.fcfa(f.pending_payments.amount), `${f.pending_payments.count} paiement(s) · ${f.unpaid_finished_orders.count} commande(s) terminée(s) non payée(s)`)}
        ${stat('Bénéfice estimé', MW.fcfa(f.profit_estimate), `après dépenses saisies (${MW.fcfa(f.operating_expenses)})`, true)}
      </div>
      <div class="grid-4" style="margin-top:14px">
        ${stat('Remboursements', MW.fcfa(f.refunds_total), `dont à charge MARWA ${MW.fcfa(f.refunds_marwa)}`)}
        ${stat('Promotions financées', MW.fcfa(f.promotions))}
        ${stat('Frais de paiement', MW.fcfa(f.payment_fees))}
        ${stat('Créances entreprises', MW.fcfa(f.business_receivables), f.vat_collected ? 'TVA collectée ' + MW.fcfa(f.vat_collected) : '')}
      </div>
      <div class="grid-2" style="margin-top:14px"><div class="card"><h3>Évolution quotidienne</h3><div data-line></div></div><div class="card"><h3>CA par service</h3><div data-bars></div></div></div>
      <h2 style="margin-top:20px">Opérations</h2>
      <div class="grid-4">
        ${stat('Commandes du jour', MW.num(o.orders_today))}${stat('En cours maintenant', MW.num(o.in_progress), `${u.providers_online} prestataire(s) en ligne`)}
        ${stat('Terminées (période)', MW.num(o.finished), `${o.created} créées`)}${stat('Annulées / expirées', `${MW.num(o.cancelled)} / ${MW.num(o.expired)}`)}
        ${stat('Prise en charge moyenne', o.avg_pickup_minutes !== null ? o.avg_pickup_minutes + ' min' : '—', 'création → prise en charge')}${stat('Livraison moyenne', o.avg_delivery_minutes !== null ? o.avg_delivery_minutes + ' min' : '—', 'création → livraison')}
        ${stat('Réclamations ouvertes', MW.num(o.open_complaints), `<a href="#/reclamations">Traiter</a>`)}${stat('Alertes de sécurité', MW.num(o.open_alerts), `<a href="#/reclamations">Voir</a>`)}
      </div>
      <h2 style="margin-top:20px">Communauté</h2>
      <div class="grid-4">${stat('Clients', MW.num(u.clients_total), `${u.clients_active} actifs sur la période`)}${stat('Chauffeurs validés', MW.num(u.drivers_verified))}${stat('Livreurs validés', MW.num(u.couriers_verified))}${stat('Restaurants / commerces', MW.num(u.merchants_verified), `${u.pending_validations} validation(s) en attente`)}</div></div>`;
    el.querySelector('[data-f]').addEventListener('submit', (e) => { e.preventDefault(); const v = MW.values(e.target); location.hash = `#/?from=${v.from}&to=${v.to}`; });
    MW.chart.line(el.querySelector('[data-line]'), d.series.map((s) => s.date.slice(8, 10) + '/' + s.date.slice(5, 7)), [
      { label: 'CA brut', color: '#0b6e4f', values: d.series.map((s) => s.gmv) }, { label: 'Commissions', color: '#f4a623', values: d.series.map((s) => s.commission) }]);
    MW.chart.bars(el.querySelector('[data-bars]'), d.by_service.map((s) => ({ label: MW.L.service[s.service_type], value: s.gmv })));
  }

  // ------------------------------------------------------------------ Carte opérationnelle
  // ------------------------------------------------------------------ Suivi en direct de toutes les commandes
  const waLink = (phone, text) => phone ? `https://wa.me/${String(phone).replace(/\D/g, '')}?text=${encodeURIComponent(text || '')}` : null;
  const telLink = (phone) => phone ? `tel:${String(phone).replace(/[^\d+]/g, '')}` : null;
  const presence = (p) => p.in_app ? '<span class="badge green">dans l\'app</span>' : '<span class="badge gray">hors de l\'app</span>';
  const FLAG = { attente: ['amber', 'Attente longue'], sans_prestataire: ['red', 'Aucun prestataire'], gps: ['red', 'GPS non actualisé'], hors_app: ['amber', 'Prestataire hors de l\'app'], commerce: ['amber', 'Commerce sans réponse'] };

  /** Fenêtre de contact : notification (app + téléphone), SMS facultatif, proposition de la commande, liens directs. */
  function contactModal(person, order, onDone) {
    const isProvider = person.kind === 'provider';
    const def = order ? (isProvider
      ? (order.provider && order.provider.id === person.id ? `Commande ${order.reference} : merci d'ouvrir MARWA et de mettre à jour votre position et l'étape de la course.` : `Un client attend une prise en charge (${order.reference}, ${order.pickup.text}). Ouvrez MARWA pour accepter la demande.`)
      : `Bonjour, l'équipe MARWA suit votre commande ${order.reference}. Nous cherchons un prestataire, merci de patienter.`) : '';
    const m = MW.formModal('Contacter ' + person.name, [
      { html: `<p class="small">${presence(person)} ${person.phone ? `· <span translate="no">${MW.esc(person.phone)}</span>` : ''}</p>
        <div class="row contact-links">${person.phone ? `<a class="btn btn-outline btn-sm" href="${telLink(person.phone)}">${MW.icon('phone')} Appeler</a>
          <a class="btn btn-outline btn-sm" href="${waLink(person.phone, def)}" target="_blank" rel="noopener">WhatsApp</a>
          <a class="btn btn-outline btn-sm" href="sms:${MW.esc(person.phone)}?body=${encodeURIComponent(def)}">SMS depuis ce téléphone</a>` : '<span class="small muted">Pas de numéro enregistré.</span>'}</div>
        <p class="small muted">Ou envoyez un message par MARWA : il apparaît dans l'application et sur l'écran du téléphone (notification push), même application fermée si les notifications sont activées.</p>` },
      { name: 'message', label: 'Message', type: 'textarea', required: true, maxlength: 300, value: def },
      { name: 'sms', label: 'Envoyer aussi par SMS (fournisseur SMS configuré, payant)', type: 'checkbox', value: !person.in_app && !person.push_devices },
      ...(isProvider && order && !order.provider ? [{ name: 'offer', label: 'Proposer cette commande à ce prestataire (il peut l\'accepter depuis l\'application)', type: 'checkbox', value: true }] : [])],
    async (v) => {
      const r = await MW.post('/admin/live/contact', { user_id: person.id, order_id: order ? order.id : null, message: v.message, sms: v.sms, offer: v.offer });
      const o = r.data.offer;
      MW.toast(`Message envoyé${r.data.sms ? ' (application + SMS)' : ''}.${o ? (o.offered ? ' Commande proposée' + (o.online ? '.' : ' : le prestataire est hors ligne, il la verra en se connectant.') : ' (déjà proposée).') : ''}`, 'success', 6000);
      onDone && onDone();
    }, { ok: 'Envoyer' });
    return m;
  }

  async function candidatesModal(order, onDone) {
    const d = await MW.get(`/admin/live/orders/${order.id}/candidates`);
    const m = MW.modal('Prestataires proches — ' + order.reference, `<p class="small muted">Départ : ${MW.esc(order.pickup.text)}. Classés par distance (dernière position connue).</p>
      <div class="list">${d.candidates.map((p, i) => `<div class="list-item"><div class="grow"><div class="title">${MW.esc(p.name)} ${p.online ? (p.busy ? '<span class="badge blue">en course</span>' : '<span class="badge green">en ligne</span>') : '<span class="badge gray">hors ligne</span>'} ${presence(p)}</div>
        <div class="meta">${p.distance_m !== null ? MW.dist(p.distance_m) : 'position inconnue'}${p.gps_age_seconds !== null ? ' · GPS ' + MW.ago(p.gps_age_seconds) : ''} · ${p.push_devices ? 'notifications activées' : 'sans notifications'}${p.offer_status ? ' · proposition : ' + MW.esc(p.offer_status) : ''}</div></div>
        <div class="row" style="gap:6px;flex:none">${p.phone ? `<a class="icon-btn" href="${telLink(p.phone)}" aria-label="Appeler">${MW.icon('phone')}</a>` : ''}<button class="btn btn-sm btn-primary" data-c="${i}">Notifier</button></div></div>`).join('') || MW.empty('Aucun prestataire validé de ce type.')}</div>`, { wide: true });
    m.el.querySelectorAll('[data-c]').forEach((b) => b.addEventListener('click', () => { const p = d.candidates[Number(b.dataset.c)]; m.close(); contactModal(Object.assign({ kind: 'provider' }, p), order, onDone); }));
  }

  async function live(el, params) {
    const filter = params.f || 'tout';
    el.innerHTML = `<div class="container">${head('Suivi en direct', '<span class="small muted" data-clock></span>')}
      <div class="live-stats" data-stats></div>
      <div class="segmented" style="margin:10px 0" data-filters>${[['tout', 'Toutes'], ['attente', 'En attente'], ['cours', 'En cours'], ['alertes', 'Alertes']].map(([k, l]) => `<a href="#/direct?f=${k}" class="${k === filter ? 'active' : ''}">${l}</a>`).join('')}</div>
      <div class="live-layout"><div class="card" style="padding:8px"><div class="map-wrap"><div id="live-map" class="map tall"></div></div>
        <div class="small muted live-legend"><span class="dot-free"></span> libre · <span class="dot-busy"></span> en course · <span class="dot-off"></span> hors de l'app · <span class="dot-wait"></span> client en attente</div></div>
        <div data-list class="stack"></div></div></div>`;
    const map = await MW.createMap(el.querySelector('#live-map'), { zoom: 12 });
    const layer = map ? L.layerGroup().addTo(map) : null;
    let data = null; let fitted = false; let lastAlerts = 0;
    const keep = (o) => filter === 'tout' || (filter === 'attente' && !o.provider) || (filter === 'cours' && o.provider) || (filter === 'alertes' && o.flags.length);
    const render = () => {
      const d = data; const s = d.summary;
      el.querySelector('[data-stats]').innerHTML = [['Ouvertes', s.open, ''], ['En attente', s.waiting, s.waiting ? 'amber' : ''], ['En cours', s.in_progress, ''], ['Alertes', s.alerts, s.alerts ? 'red' : ''], ['Prestataires en ligne', `${s.providers_online} (${s.providers_free} libres)`, '']]
        .map(([l, v, c]) => `<div class="stat ${c ? 'alert-' + c : ''}"><p class="label">${l}</p><div class="value">${v}</div></div>`).join('');
      el.querySelector('[data-clock]').textContent = 'Mis à jour à ' + MW.time(new Date()) + ' · actualisation toutes les 5 s';
      const list = d.orders.filter(keep);
      el.querySelector('[data-list]').innerHTML = list.length ? list.map((o) => `<div class="card live-order ${o.flags.length ? 'has-alert' : ''}" data-o="${o.id}">
          <div class="row between"><div><strong>${MW.esc(MW.L.service[o.service_type] || o.service_type)} · <a href="#/commande/${o.id}" translate="no">${MW.esc(o.reference)}</a></strong>
            <div class="small muted">créée ${MW.ago(o.age_seconds)} · ${MW.fcfa(o.total)}${o.merchant ? ' · ' + MW.esc(o.merchant) : ''}</div></div>${MW.statusBadge(o.status)}</div>
          ${o.flags.length ? `<div class="row" style="gap:6px;margin-top:6px">${o.flags.map((f) => `<span class="badge ${FLAG[f][0]}">${FLAG[f][1]}</span>`).join('')}</div>` : ''}
          <div class="small" style="margin-top:6px">${MW.icon('pin')} ${MW.esc(o.pickup.text)} → ${MW.esc(o.dropoff.text)}</div>
          <div class="live-people">
            <div><div class="small muted">Client</div><strong>${MW.esc(o.customer.name)}</strong> ${presence(o.customer)}
              <div class="row" style="gap:6px;margin-top:4px">${o.customer.phone ? `<a class="btn btn-sm btn-outline" href="${telLink(o.customer.phone)}">${MW.icon('phone')} Appeler</a>` : ''}<button class="btn btn-sm btn-ghost" data-cc="${o.id}">Message</button></div></div>
            <div><div class="small muted">Prestataire</div>${o.provider ? `<strong>${MW.esc(o.provider.name)}</strong> ${presence(o.provider)}
              <div class="small muted">${o.provider.gps_age_seconds !== null ? 'GPS ' + MW.ago(o.provider.gps_age_seconds) : 'aucune position'}</div>
              <div class="row" style="gap:6px;margin-top:4px">${o.provider.phone ? `<a class="btn btn-sm btn-outline" href="${telLink(o.provider.phone)}">${MW.icon('phone')} Appeler</a>` : ''}<button class="btn btn-sm btn-primary" data-cp="${o.id}">Notifier</button></div>`
              : `<span class="small">${o.offers_open ? `${o.offers_open} proposition(s) en cours` : 'aucune proposition en cours'} · ${o.offers_total} envoyée(s)</span>
              <div style="margin-top:4px"><button class="btn btn-sm btn-primary" data-near="${o.id}">Prestataires proches</button></div>`}</div></div></div>`).join('')
        : MW.empty('Aucune commande dans cette catégorie.');
      const find = (id) => d.orders.find((x) => String(x.id) === String(id));
      el.querySelectorAll('[data-cp]').forEach((b) => b.addEventListener('click', () => { const o = find(b.dataset.cp); contactModal(Object.assign({ kind: 'provider' }, o.provider), o, refresh); }));
      el.querySelectorAll('[data-cc]').forEach((b) => b.addEventListener('click', () => { const o = find(b.dataset.cc); contactModal(Object.assign({ kind: 'customer' }, o.customer), o, refresh); }));
      el.querySelectorAll('[data-near]').forEach((b) => b.addEventListener('click', () => MW.busy(b, () => candidatesModal(find(b.dataset.near), refresh))));
      if (!layer) return;
      layer.clearLayers();
      const pts = [];
      d.providers.forEach((p) => { pts.push([p.lat, p.lng]); L.marker([p.lat, p.lng], { icon: MW.vehicleIcon(p.icon, p.heading, { stale: !p.in_app || p.gps_age_seconds > 120, color: p.busy ? '#1f6fb2' : '#0b6e4f' }) })
        .bindPopup(`<strong>${MW.esc(p.name)}</strong><br>${p.busy ? 'en course' : 'libre'} · ${p.in_app ? 'dans l\'app' : 'hors de l\'app'}<br>GPS ${MW.ago(p.gps_age_seconds)}${p.phone ? `<br><a href="${telLink(p.phone)}">Appeler</a> · <a href="${waLink(p.phone, '')}" target="_blank" rel="noopener">WhatsApp</a>` : ''}`).addTo(layer); });
      d.orders.filter(keep).forEach((o) => {
        pts.push([o.pickup.lat, o.pickup.lng]);
        if (!o.provider) L.circleMarker([o.pickup.lat, o.pickup.lng], { radius: 9, color: '#d97706', weight: 3, fillColor: '#f4a623', fillOpacity: .85, className: o.flags.length ? 'pulse-marker' : '' }).bindPopup(`<a href="#/commande/${o.id}">${MW.esc(o.reference)}</a><br>${MW.esc(o.status_label)} · ${MW.ago(o.age_seconds)}`).addTo(layer);
        else if (o.provider.position) {
          const target = ['assigned', 'en_route_pickup', 'accepted', 'preparing', 'ready'].includes(o.status) ? o.pickup : o.dropoff;
          L.polyline([[o.provider.position.lat, o.provider.position.lng], [target.lat, target.lng]], { color: '#1f6fb2', weight: 3, dashArray: '6 8', opacity: .8 }).addTo(layer);
          L.circleMarker([target.lat, target.lng], { radius: 6, color: '#1f6fb2', fillOpacity: .6 }).bindPopup(`<a href="#/commande/${o.id}">${MW.esc(o.reference)}</a> · ${MW.esc(o.status_label)}`).addTo(layer);
        }
      });
      if (!fitted && pts.length) { MW.fitTo(map, pts); fitted = true; }
    };
    const refresh = async () => {
      try { data = await MW.get('/admin/live', { retries: 0 }); } catch (e) { return; }
      if (data.summary.alerts > lastAlerts && lastAlerts !== 0 && navigator.vibrate) navigator.vibrate([150, 80, 150]);
      lastAlerts = data.summary.alerts; render();
    };
    await refresh();
    const t = setInterval(() => { if (!document.hidden && !document.querySelector('.modal-backdrop')) refresh(); }, 5000);
    const onEv = () => { if (!document.querySelector('.modal-backdrop')) refresh(); };
    MW.realtime.on(onEv); MW.realtime.setFast(true);
    MW.onLeave(() => { clearInterval(t); MW.realtime.setFast(false); MW.realtime.listeners = MW.realtime.listeners.filter((f) => f !== onEv); });
  }

  // ------------------------------------------------------------------ Utilisateurs
  async function users(el, params) {
    const q = new URLSearchParams(params); q.set('limit', 100);
    const r = await MW.api('GET', '/admin/users?' + q);
    el.innerHTML = `<div class="container">${head('Utilisateurs', can('settings.manage') ? `<button class="btn btn-primary" data-staff>${MW.icon('plus')} Compte du personnel</button>` : '')}
      <form class="card row" data-f style="margin-bottom:14px"><input class="input" name="q" placeholder="Nom, téléphone, e-mail" value="${MW.esc(params.q || '')}" style="flex:2;min-width:180px">
        <select class="input" name="role" style="flex:1;min-width:140px"><option value="">Tous les rôles</option>${Object.entries(MW.L.role).map(([k, l]) => `<option value="${k}" ${params.role === k ? 'selected' : ''}>${l}</option>`).join('')}</select>
        <select class="input" name="status" style="flex:1;min-width:140px"><option value="">Tous statuts</option>${['active', 'pending', 'suspended', 'deleted'].map((s) => `<option ${params.status === s ? 'selected' : ''}>${s}</option>`).join('')}</select><button class="btn btn-primary">Rechercher</button></form>
      <p class="small muted">${r.total} utilisateur(s)</p><div data-t></div></div>`;
    const t = el.querySelector('[data-t]');
    t.innerHTML = MW.table([{ key: 'full_name', label: 'Nom', fmt: (x) => `${MW.esc(x.full_name)}${Number(x.is_demo) ? ' <span class="badge amber">démo</span>' : ''}` }, { key: 'role_name', label: 'Rôle' },
      { key: 'phone', label: 'Téléphone' }, { key: 'email', label: 'E-mail' }, { key: 'status', label: 'Statut', fmt: (x) => `<span class="badge ${x.status === 'active' ? 'green' : x.status === 'suspended' ? 'red' : 'gray'}">${MW.esc(x.status)}</span>` },
      { key: 'created_at', label: 'Inscrit le', fmt: (x) => MW.date(x.created_at) }, { key: 'last_login_at', label: 'Dernière connexion', fmt: (x) => MW.datetime(x.last_login_at) }], r.data, { onRow: true });
    MW.bindRows(t, r.data, (x) => userDetail(x.id, () => users(el, params)));
    el.querySelector('[data-f]').addEventListener('submit', (e) => { e.preventDefault(); const v = MW.values(e.target); location.hash = '#/utilisateurs?' + new URLSearchParams(Object.entries(v).filter(([, x]) => x)); });
    el.querySelector('[data-staff]')?.addEventListener('click', () => MW.formModal('Nouveau compte du personnel', [
      { name: 'full_name', label: 'Nom', required: true }, { name: 'email', label: 'E-mail', type: 'email', required: true }, { name: 'phone', label: 'Téléphone', type: 'tel' },
      { name: 'role', label: 'Rôle', type: 'select', options: [['support', 'Assistance client'], ['finance', 'Finance / comptabilité'], ['admin', 'Administrateur']] },
      { name: 'password', label: 'Mot de passe initial', type: 'password', required: true }], async (v) => { await MW.post('/admin/staff', v); MW.toast('Compte créé.', 'success'); users(el, params); }));
  }
  async function userDetail(id, done) {
    const u = await MW.get('/admin/users/' + id);
    const m = MW.modal(u.full_name, `<dl class="kv"><dt>Rôle</dt><dd>${MW.esc(MW.L.role[u.role] || u.role)}</dd><dt>Téléphone</dt><dd>${MW.esc(u.phone || '—')}</dd><dt>E-mail</dt><dd>${MW.esc(u.email || '—')}</dd>
      <dt>Statut</dt><dd>${MW.esc(u.status)}</dd><dt>Échecs de connexion</dt><dd>${u.failed_logins}${u.locked_until ? ' · verrouillé jusqu\'à ' + MW.datetime(u.locked_until) : ''}</dd></dl>
      <h3 style="margin-top:14px">Commandes récentes</h3>${MW.table([{ key: 'reference', label: 'Réf.', fmt: (o) => `<a href="#/commande/${o.id}">${MW.esc(o.reference)}</a>` }, { key: 'status', label: 'Statut', fmt: (o) => MW.statusBadge(o.status) }, { key: 'total', label: 'Total', num: true, fmt: (o) => MW.fcfa(o.total) }, { key: 'created_at', label: 'Date', fmt: (o) => MW.date(o.created_at) }], u.orders)}
      <h3 style="margin-top:14px">Historique des décisions</h3>${u.decisions.length ? u.decisions.map((dd) => `<div class="small">${MW.datetime(dd.created_at)} — ${MW.esc(dd.action)}</div>`).join('') : '<p class="small muted">Aucune.</p>'}
      ${can('users.manage') && u.status !== 'deleted' ? `<div class="modal-actions">${u.status === 'suspended' ? '<button class="btn btn-primary" data-st="active">Réactiver</button>' : '<button class="btn btn-danger" data-st="suspended">Suspendre</button>'}</div>` : ''}`, { wide: true });
    m.el.querySelector('[data-st]')?.addEventListener('click', async (e) => {
      const reason = await MW.confirm(e.target.dataset.st === 'suspended' ? 'Suspendre ce compte ? L\'utilisateur ne pourra plus se connecter.' : 'Réactiver ce compte ?', { reason: 'Motif (communiqué à l\'utilisateur)', reasonRequired: true, danger: e.target.dataset.st === 'suspended' });
      if (!reason) return;
      try { await MW.post(`/admin/users/${id}/status`, { status: e.target.dataset.st, reason }); MW.toast('Statut mis à jour.', 'success'); m.close(); done(); } catch (err) { MW.error(err); }
    });
  }

  // ------------------------------------------------------------------ Partenaires
  async function providers(el, params, type) {
    const status = params.status || '';
    const list = await MW.get(`/admin/providers?type=${type}${status ? '&status=' + status : ''}`);
    el.innerHTML = `<div class="container">${head(type === 'driver' ? 'Chauffeurs' : 'Livreurs', `<div class="segmented">${[['', 'Tous'], ['pending', 'À valider'], ['verified', 'Validés'], ['suspended', 'Suspendus']].map(([k, l]) => `<a href="#/${type === 'driver' ? 'chauffeurs' : 'livreurs'}?status=${k}" class="${status === k ? 'active' : ''}" style="padding:8px 12px;border-radius:9px;text-decoration:none;color:inherit;font-weight:700">${l}</a>`).join('')}</div>`)}<div data-t></div></div>`;
    const t = el.querySelector('[data-t]');
    t.innerHTML = MW.table([{ key: 'full_name', label: 'Nom' }, { key: 'phone', label: 'Téléphone' }, { key: 'category', label: 'Véhicule', fmt: (r) => `${MW.esc(MW.L.vehicle[r.category] || '—')} ${MW.esc(r.plate || '')}` },
      { key: 'verification_status', label: 'Validation', fmt: (r) => verifBadge(r.verification_status) + (Number(r.pending_docs) ? ` <span class="badge amber">${r.pending_docs} doc.</span>` : '') },
      { key: 'license_expires_at', label: 'Permis', fmt: (r) => r.license_expires_at ? (r.license_expires_at < today() ? `<span class="badge red">expiré ${MW.date(r.license_expires_at)}</span>` : MW.date(r.license_expires_at)) : '—' },
      { key: 'is_online', label: 'En ligne', fmt: (r) => yes(r.is_online) + (r.last_seen_at && (Date.now() - new Date(String(r.last_seen_at).replace(' ', 'T'))) < 120000 ? ' <span class="badge green">dans l\'app</span>' : '') }, { key: 'rating_avg', label: 'Note', fmt: (r) => Number(r.rating_count) ? Number(r.rating_avg).toFixed(1) + ` (${r.rating_count})` : '—' },
      { key: 'id', label: '', fmt: (r) => `<button class="btn btn-sm btn-outline" data-contact="${r.id}">Contacter</button>` }], list, { onRow: true, empty: 'Aucun prestataire.' });
    MW.bindRows(t, list, (r) => providerDetail(r.id, () => providers(el, params, type)));
    t.querySelectorAll('[data-contact]').forEach((b) => b.addEventListener('click', (e) => {
      e.stopPropagation();
      const r = list.find((x) => String(x.id) === b.dataset.contact);
      contactModal({ kind: 'provider', id: r.id, name: r.full_name, phone: r.phone, in_app: !!(r.last_seen_at && (Date.now() - new Date(String(r.last_seen_at).replace(' ', 'T'))) < 120000), push_devices: 0 }, null);
    }));
  }
  async function providerDetail(id, done) {
    const p = await MW.get('/admin/providers/' + id);
    const v = p.vehicles[0] || {};
    const m = MW.modal(p.full_name, `<div class="grid-2"><dl class="kv"><dt>Type</dt><dd>${p.provider_type === 'driver' ? 'Chauffeur' : 'Livreur'}</dd><dt>Téléphone</dt><dd>${MW.esc(p.phone || '')}</dd><dt>Pièce d'identité</dt><dd>${MW.esc(p.id_number || '—')}</dd>
        <dt>Permis</dt><dd>${MW.esc(p.license_number || '—')} · exp. ${MW.date(p.license_expires_at)}</dd><dt>Statut</dt><dd>${verifBadge(p.verification_status)}</dd><dt>Solde</dt><dd>${MW.fcfa(p.balance)}</dd>
        <dt>Acceptation</dt><dd>${p.offers_received} reçues · ${p.offers_refused} refusées · ${p.offers_expired} sans réponse</dd></dl>
        <dl class="kv"><dt>Véhicule</dt><dd>${MW.esc(MW.L.vehicle[v.category] || '—')}</dd><dt>Marque / modèle</dt><dd>${MW.esc((v.make || '') + ' ' + (v.model || ''))}</dd><dt>Couleur</dt><dd>${MW.esc(v.color || '')}</dd><dt>Plaque</dt><dd>${MW.esc(v.plate || '')}</dd><dt>Places</dt><dd>${v.capacity || ''}</dd>
        ${v.photo_file_id ? `<dt>Photo</dt><dd><a target="_blank" href="${MW.base}/fichiers/${v.photo_file_id}">Voir</a></dd>` : ''}</dl></div>
      <h3 style="margin-top:14px">Documents</h3>${MW.table([{ key: 'doc_type', label: 'Type', fmt: (dd) => MW.esc(MW.L.doc[dd.doc_type]) }, { key: 'expires_at', label: 'Expiration', fmt: (dd) => MW.date(dd.expires_at) },
        { key: 'status', label: 'Statut', fmt: (dd) => MW.esc({ pending: 'À vérifier', approved: 'Approuvé', rejected: 'Refusé' }[dd.status]) }, { key: 'url', label: '', fmt: (dd) => `<a target="_blank" href="${MW.esc(dd.url)}">Ouvrir</a>` },
        { key: 'id', label: '', fmt: (dd) => dd.status === 'pending' ? `<button class="btn btn-sm btn-primary" data-doc="${dd.id}" data-s="approved">Approuver</button> <button class="btn btn-sm btn-outline" data-doc="${dd.id}" data-s="rejected">Refuser</button>` : '' }], p.documents, { empty: 'Aucun document.' })}
      <div class="modal-actions">${['verified', 'refused', 'suspended'].filter((s) => s !== p.verification_status).map((s) => `<button class="btn ${s === 'verified' ? 'btn-primary' : 'btn-outline'}" data-dec="${s}">${{ verified: 'Valider le compte', refused: 'Refuser', suspended: 'Suspendre' }[s]}</button>`).join('')}</div>`, { wide: true });
    m.el.querySelectorAll('[data-doc]').forEach((b) => b.addEventListener('click', async () => {
      const note = b.dataset.s === 'rejected' ? await MW.confirm('Refuser ce document ?', { reason: 'Motif (communiqué au prestataire)', reasonRequired: true }) : true;
      if (!note) return;
      try { await MW.post(`/admin/documents/${b.dataset.doc}/review`, { status: b.dataset.s, note: note === true ? null : note }); m.close(); providerDetail(id, done); } catch (e) { MW.error(e); }
    }));
    m.el.querySelectorAll('[data-dec]').forEach((b) => b.addEventListener('click', async () => {
      const note = await MW.confirm('Confirmer la décision ?', { reason: 'Note (facultative, envoyée au prestataire)' });
      if (note === false) return;
      try { await MW.post(`/admin/providers/${id}/verify`, { decision: b.dataset.dec, note: note === true ? null : note }); MW.toast('Décision enregistrée.', 'success'); m.close(); done(); } catch (e) { MW.error(e); }
    }));
  }

  async function merchants(el) {
    const list = await MW.get('/admin/merchants');
    el.innerHTML = `<div class="container">${head('Restaurants et commerces')}<div data-t></div></div>`;
    const t = el.querySelector('[data-t]');
    t.innerHTML = MW.table([{ key: 'name', label: 'Commerce' }, { key: 'owner', label: 'Responsable' }, { key: 'phone', label: 'Téléphone' }, { key: 'kind', label: 'Type' },
      { key: 'products', label: 'Produits', num: true }, { key: 'delivery_mode', label: 'Livraison' }, { key: 'status', label: 'Statut', fmt: (r) => verifBadge(r.status) }, { key: 'is_open', label: 'Ouvert', fmt: (r) => yes(r.is_open) }], list, { onRow: true });
    MW.bindRows(t, list, (r) => decide('Commerce : ' + r.name, `<dl class="kv"><dt>Adresse</dt><dd>${MW.esc(r.address_text)}</dd><dt>GPS</dt><dd>${r.lat}, ${r.lng}</dd><dt>RCCM / NIF</dt><dd>${MW.esc(r.rccm || '—')} / ${MW.esc(r.tax_id || '—')}</dd><dt>Statut</dt><dd>${verifBadge(r.status)}</dd></dl>`,
      r.status, (decision, note) => MW.post(`/admin/merchants/${r.id}/verify`, { decision, note }), () => merchants(el)));
  }
  function decide(title, html, current, fn, done) {
    const m = MW.modal(title, html + `<div class="modal-actions">${['verified', 'refused', 'suspended', 'pending'].filter((s) => s !== current).map((s) => `<button class="btn ${s === 'verified' ? 'btn-primary' : 'btn-outline'}" data-dec="${s}">${{ verified: 'Valider', refused: 'Refuser', suspended: 'Suspendre', pending: 'Remettre en attente' }[s]}</button>`).join('')}</div>`);
    m.el.querySelectorAll('[data-dec]').forEach((b) => b.addEventListener('click', async () => {
      const note = await MW.confirm('Confirmer la décision ?', { reason: 'Note (facultative)' }); if (note === false) return;
      try { await fn(b.dataset.dec, note === true ? null : note); m.close(); done(); } catch (e) { MW.error(e); }
    }));
  }

  async function businesses(el) {
    const list = await MW.get('/admin/businesses');
    el.innerHTML = `<div class="container">${head('Entreprises')}<div data-t></div></div>`;
    const t = el.querySelector('[data-t]');
    t.innerHTML = MW.table([{ key: 'name', label: 'Entreprise' }, { key: 'owner', label: 'Responsable' }, { key: 'members', label: 'Membres', num: true }, { key: 'status', label: 'Statut', fmt: (r) => verifBadge(r.status) },
      { key: 'postpaid_enabled', label: 'Paiement différé', fmt: (r) => yes(r.postpaid_enabled) }, { key: 'credit_limit', label: 'Encours max.', num: true, fmt: (r) => MW.fcfa(r.credit_limit) }, { key: 'outstanding', label: 'Encours', num: true, fmt: (r) => MW.fcfa(r.outstanding || 0) }], list, { onRow: true });
    MW.bindRows(t, list, (b) => {
      MW.formModal('Entreprise : ' + b.name, [
        { html: `<dl class="kv" style="margin-bottom:12px"><dt>NIF</dt><dd>${MW.esc(b.tax_id || '—')}</dd><dt>RCCM</dt><dd>${MW.esc(b.rccm || '—')}</dd><dt>E-mail</dt><dd>${MW.esc(b.billing_email || '—')}</dd><dt>Encours</dt><dd>${MW.fcfa(b.outstanding || 0)}</dd></dl>` },
        { name: 'decision', label: 'Statut', type: 'select', value: b.status, options: Object.entries(MW.L.verif) },
        { name: 'postpaid_enabled', label: 'Autoriser le paiement différé (facturation)', type: 'checkbox', value: Number(b.postpaid_enabled) },
        { name: 'credit_limit', label: 'Encours maximal autorisé (FCFA, 0 = sans limite)', type: 'number', value: b.credit_limit, min: 0 },
        { name: 'note', label: 'Note', maxlength: 255 },
        ...(can('payments.manage') ? [{ html: '<hr class="divider"><button type="button" class="btn btn-outline btn-sm" data-pay>Enregistrer un règlement de l\'entreprise</button>' }] : [])],
      async (v) => { await MW.post(`/admin/businesses/${b.id}/verify`, v); businesses(el); }, { onOpen: (form) => form.querySelector('[data-pay]')?.addEventListener('click', () => MW.formModal('Règlement reçu', [
        { name: 'amount', label: 'Montant (FCFA)', type: 'number', required: true, min: 1 }, { name: 'reference', label: 'Référence (virement, chèque…)', required: true }],
      async (pv) => { await MW.post(`/admin/businesses/${b.id}/payment`, pv); MW.toast('Règlement enregistré au grand livre.', 'success'); businesses(el); })) });
    });
  }

  // ------------------------------------------------------------------ Commandes
  async function orders(el, params) {
    const q = new URLSearchParams(params); q.set('limit', 100);
    const list = await MW.get('/admin/orders?' + q);
    el.innerHTML = `<div class="container">${head('Commandes')}
      <form class="card row" data-f style="margin-bottom:14px"><input class="input" name="q" placeholder="Référence ou client" value="${MW.esc(params.q || '')}" style="flex:2;min-width:160px">
        <select class="input" name="status" style="flex:1;min-width:150px"><option value="">Tous statuts</option>${Object.entries(MW.L.status).map(([k, l]) => `<option value="${k}" ${params.status === k ? 'selected' : ''}>${l}</option>`).join('')}</select>
        <select class="input" name="service_type" style="flex:1;min-width:130px">${SERVICES.map(([k, l]) => `<option value="${k}" ${params.service_type === k ? 'selected' : ''}>${l === '—' ? 'Tous services' : l}</option>`).join('')}</select>
        <label class="check"><input type="checkbox" name="active" ${params.active ? 'checked' : ''}> En cours</label><button class="btn btn-primary">Filtrer</button></form><div data-t></div></div>`;
    const t = el.querySelector('[data-t]');
    t.innerHTML = MW.table([{ key: 'reference', label: 'Réf.', fmt: (r) => `${MW.esc(r.reference)}${Number(r.is_demo) ? ' <span class="badge amber">démo</span>' : ''}` }, { key: 'created_at', label: 'Créée', fmt: (r) => MW.datetime(r.created_at) },
      { key: 'service_type', label: 'Service', fmt: (r) => MW.esc(MW.L.service[r.service_type]) }, { key: 'customer', label: 'Client' }, { key: 'provider', label: 'Prestataire', fmt: (r) => MW.esc(r.provider || r.merchant || '—') },
      { key: 'status', label: 'Statut', fmt: (r) => MW.statusBadge(r.status) + (r.dispatch_status === 'no_provider' ? ' <span class="badge red">sans prestataire</span>' : '') },
      { key: 'payment_status', label: 'Paiement', fmt: (r) => `${MW.esc(MW.L.payment[r.payment_method])}<br><span class="small muted">${MW.esc(MW.L.payStatus[r.payment_status])}</span>` }, { key: 'total', label: 'Total', num: true, fmt: (r) => MW.fcfa(r.total) }], list, { onRow: true, empty: 'Aucune commande.' });
    MW.bindRows(t, list, (r) => (location.hash = '#/commande/' + r.id));
    el.querySelector('[data-f]').addEventListener('submit', (e) => { e.preventDefault(); const v = MW.values(e.target); location.hash = '#/commandes?' + new URLSearchParams(Object.entries(v).filter(([, x]) => x).map(([k, x]) => [k, x === true ? '1' : x])); });
  }

  async function order(el, params) {
    const o = await MW.get('/admin/orders/' + params.id);
    el.innerHTML = `<div class="container">${head(`${MW.L.service[o.service_type]} · ${o.reference}`, '<a href="#/commandes">← Commandes</a>')}
      <div class="grid-2"><div class="stack">
        <div class="card"><div class="row between"><h2 style="margin:0">${MW.esc(o.status_label)}</h2>${MW.statusBadge(o.status)}</div>
          <dl class="kv" style="margin-top:10px"><dt>Client</dt><dd>${MW.esc(o.customer?.name || '')}</dd><dt>Prestataire</dt><dd>${MW.esc(o.provider?.name || '—')} ${MW.esc(o.provider?.vehicle?.plate || '')}</dd>${o.merchant ? `<dt>Commerce</dt><dd>${MW.esc(o.merchant.name)}</dd>` : ''}
            <dt>Départ</dt><dd>${MW.esc(o.pickup.text)}</dd><dt>Arrivée</dt><dd>${MW.esc(o.dropoff.text)}</dd><dt>Distance</dt><dd>${MW.dist(o.distance_m)} (${MW.esc(o.route_source || '')})</dd>
            <dt>Mode de prix</dt><dd>${MW.esc({ auto: 'Tarif MARWA', negotiated: 'Négocié', catalog: 'Catalogue' }[o.pricing_mode])}</dd><dt>Prix MARWA</dt><dd>${MW.fcfa(o.estimated_price)}</dd>
            ${o.offered_price ? `<dt>Offre client</dt><dd>${MW.fcfa(o.offered_price)}</dd>` : ''}<dt>Prix final</dt><dd>${MW.fcfa(o.final_price)}</dd>${o.items_subtotal ? `<dt>Articles</dt><dd>${MW.fcfa(o.items_subtotal)}</dd>` : ''}
            ${o.delivery_fee ? `<dt>Livraison</dt><dd>${MW.fcfa(o.delivery_fee)}</dd>` : ''}${o.service_fee + o.booking_fee ? `<dt>Frais MARWA</dt><dd>${MW.fcfa(o.service_fee + o.booking_fee)}</dd>` : ''}${o.discount ? `<dt>Réduction</dt><dd>−${MW.fcfa(o.discount)}</dd>` : ''}
            <dt><strong>Total client</strong></dt><dd><strong>${MW.fcfa(o.total)}</strong></dd><dt>Paiement</dt><dd>${MW.esc(MW.L.payment[o.payment_method])} · ${MW.esc(MW.L.payStatus[o.payment_status])}</dd>${o.cancel_reason ? `<dt>Motif</dt><dd>${MW.esc(o.cancel_reason)}</dd>` : ''}</dl>
          ${can('orders.manage') ? `<div class="row" style="margin-top:12px">${o.admin_actions.map((a) => `<button class="btn btn-sm btn-outline" data-act="${a}">${MW.esc(MW.L.status[a] || a)}</button>`).join('')}
            ${o.provider && ['assigned', 'en_route_pickup', 'accepted', 'preparing', 'ready'].includes(o.status) ? '<button class="btn btn-sm btn-outline" data-act="reassign">Réaffecter</button>' : ''}
            ${!o.provider && ['no_provider', 'none'].includes(o.dispatch_status) && ['searching', 'negotiating', 'accepted', 'preparing', 'ready'].includes(o.status) ? '<button class="btn btn-sm btn-outline" data-act="redispatch">Relancer la recherche</button>' : ''}
            ${['paid', 'disputed', 'refund_pending'].includes(o.status) ? '<button class="btn btn-sm btn-accent" data-refund>Rembourser</button>' : ''}</div>` : ''}</div>
        <div class="card"><h3>Répartition financière</h3>${MW.table([{ key: 'party', label: 'Partie', fmt: (c) => c.party === 'merchant' ? 'Commerce' : 'Prestataire' }, { key: 'model', label: 'Modèle' },
          { key: 'base_amount', label: 'Base', num: true, fmt: (c) => MW.fcfa(c.base_amount) }, { key: 'commission', label: 'Commission', num: true, fmt: (c) => MW.fcfa(c.commission) }, { key: 'partner_net', label: 'Net partenaire', num: true, fmt: (c) => MW.fcfa(c.partner_net) }], o.commissions, { empty: 'Calculée au règlement.' })}
          <h3 style="margin-top:12px">Paiements</h3>${MW.table([{ key: 'method', label: 'Moyen', fmt: (p) => MW.esc(MW.L.payment[p.method]) }, { key: 'amount', label: 'Attendu', num: true, fmt: (p) => MW.fcfa(p.amount) },
            { key: 'declared_amount', label: 'Déclaré', num: true, fmt: (p) => p.declared_amount !== null ? MW.fcfa(p.declared_amount) : '—' }, { key: 'fee_amount', label: 'Frais', num: true, fmt: (p) => MW.fcfa(p.fee_amount) }, { key: 'status', label: 'Statut' }], o.payments, { empty: 'Aucun paiement.' })}
          ${o.refunds.length ? `<h3 style="margin-top:12px">Remboursements</h3>${MW.table([{ key: 'amount', label: 'Montant', num: true, fmt: (r) => MW.fcfa(r.amount) }, { key: 'borne_by', label: 'À charge de' }, { key: 'status', label: 'Statut' }, { key: 'reason', label: 'Motif' }], o.refunds)}` : ''}</div>
      </div><div class="stack">
        <div class="card"><h3>Historique des statuts</h3><ul class="timeline">${o.history.map((h) => `<li><strong>${MW.esc(h.label)}</strong> <span class="small muted">(${MW.esc(h.actor_role)})</span>${h.note ? `<div class="small">${MW.esc(h.note)}</div>` : ''}<div class="t-time">${MW.datetime(h.created_at)}</div></li>`).join('')}</ul></div>
        <div class="card"><h3>Attribution</h3>${MW.table([{ key: 'full_name', label: 'Prestataire' }, { key: 'round_no', label: 'Vague', num: true }, { key: 'distance_m', label: 'Distance', num: true, fmt: (x) => MW.dist(x.distance_m) }, { key: 'status', label: 'Réponse', fmt: (x) => MW.esc(x.status) + (x.refusal_reason ? ' — ' + MW.esc(x.refusal_reason) : '') }], o.offers, { empty: 'Aucune sollicitation.' })}
          ${o.negotiations_log.length ? `<h3 style="margin-top:12px">Négociation</h3>${MW.table([{ key: 'proposed_by', label: 'Par' }, { key: 'amount', label: 'Montant', num: true, fmt: (x) => MW.fcfa(x.amount) }, { key: 'status', label: 'Statut' }, { key: 'created_at', label: 'Heure', fmt: (x) => MW.time(x.created_at) }], o.negotiations_log)}` : ''}
          ${o.assignments.length ? `<h3 style="margin-top:12px">Affectations</h3>${MW.table([{ key: 'full_name', label: 'Prestataire' }, { key: 'agreed_price', label: 'Prix', num: true, fmt: (x) => MW.fcfa(x.agreed_price) }, { key: 'assigned_at', label: 'Début', fmt: (x) => MW.time(x.assigned_at) }, { key: 'end_reason', label: 'Fin' }], o.assignments)}` : ''}</div>
        <div class="card"><h3>Messagerie (lecture)</h3><div data-chat></div></div></div></div></div>`;
    MW.chat(el.querySelector('[data-chat]'), o.id);
    el.querySelectorAll('[data-act]').forEach((b) => b.addEventListener('click', async () => {
      const reason = await MW.confirm(`Action administrative : « ${b.textContent.trim()} » sur ${o.reference}.`, { reason: 'Motif (journalisé)', reasonRequired: true, danger: b.dataset.act === 'cancelled' });
      if (!reason) return;
      const action = b.dataset.act === 'cancelled' ? 'cancel' : b.dataset.act;
      try { await MW.post(`/admin/orders/${o.id}/action`, { action, reason }); MW.toast('Action effectuée.', 'success'); order(el, params); } catch (e) { MW.error(e); }
    }));
    el.querySelector('[data-refund]')?.addEventListener('click', () => MW.formModal('Remboursement ' + o.reference, [
      { name: 'amount', label: 'Montant (FCFA)', type: 'number', required: true, value: o.total, min: 1 }, { name: 'reason', label: 'Motif', required: true, maxlength: 255 },
      { name: 'borne_by', label: 'À la charge de', type: 'select', options: [['marwa', 'MARWA'], ...(o.provider ? [['provider', 'Prestataire']] : []), ...(o.merchant ? [['merchant', 'Commerce']] : [])] },
      { html: `<p class="small muted">${can('refunds.manage') ? 'Le remboursement sera exécuté et comptabilisé immédiatement.' : 'La demande sera soumise à validation par la finance.'}</p>` }],
    async (v) => { v.order_id = o.id; await MW.post('/admin/refunds', v); MW.toast('Remboursement enregistré.', 'success'); order(el, params); }));
  }

  // ------------------------------------------------------------------ Paramétrage générique (tarifs, commissions, promotions)
  async function crud(el, cfg) {
    const list = await MW.get(cfg.path);
    el.innerHTML = `<div class="container">${head(cfg.title, `${cfg.extraButtons || ''}<button class="btn btn-primary" data-new>${MW.icon('plus')} Ajouter</button>`)}${cfg.intro || ''}<div data-t></div></div>`;
    const t = el.querySelector('[data-t]');
    t.innerHTML = MW.table(cfg.cols, list, { onRow: true, empty: 'Aucune règle.' });
    const edit = (row = {}) => {
      let pending = null;
      const fields = cfg.image ? [{ html: '<div data-img></div>' }, ...cfg.fields(row)] : cfg.fields(row);
      MW.formModal(row.id ? 'Modifier' : 'Ajouter', fields, async (v) => {
        cfg.normalize && cfg.normalize(v);
        let id = row.id;
        if (row.id) await MW.put(`${cfg.path}/${row.id}`, v); else id = (await MW.post(cfg.path, v)).data.id;
        if (pending && id) await MW.uploadImage(cfg.image.path(id), pending);
        MW.toast('Enregistré (action journalisée).', 'success'); crud(el, cfg);
      }, { values: row, wide: true, onOpen: (form) => {
        const box = form.querySelector('[data-img]'); if (!box || !cfg.image) return;
        if (row.id) MW.singleImage(box, { label: cfg.image.label, url: row[cfg.image.key], path: cfg.image.path(row.id), shape: 'wide' });
        else {
          box.innerHTML = `<div class="field"><span class="label">${MW.esc(cfg.image.label)}</span><div class="single-image wide" data-prev><span class="muted small">Aucune image</span></div>${MW.photoButtons(false)}</div>`;
          box.querySelectorAll('[data-camera],[data-gallery]').forEach((i) => i.addEventListener('change', () => { if (!i.files[0]) return; pending = i.files[0]; box.querySelector('[data-prev]').innerHTML = `<img src="${URL.createObjectURL(pending)}" alt="">`; }));
        }
      } });
    };
    MW.bindRows(t, list, edit);
    el.querySelector('[data-new]').addEventListener('click', () => edit(cfg.defaults || {}));
    cfg.after && cfg.after(el, list);
  }
  const intOrNull = (v, keys) => keys.forEach((k) => { v[k] = v[k] === null || v[k] === '' ? null : Number(v[k]); });

  const pricing = (el) => crud(el, {
    title: 'Tarification', path: '/admin/pricing',
    intro: '<div class="alert alert-info">Prix = max(minimum, prise en charge + prix/km × distance + prix/min × durée) × majoration de nuit, arrondi au pas configuré, + frais de réservation et de service. La règle la plus spécifique (zone > ville > catégorie > priorité) s\'applique.</div>',
    cols: [{ key: 'name', label: 'Règle' }, { key: 'service_type', label: 'Service', fmt: (r) => MW.esc(MW.L.service[r.service_type]) }, { key: 'vehicle_category', label: 'Véhicule', fmt: (r) => MW.esc(MW.L.vehicle[r.vehicle_category] || 'Tous') },
      { key: 'city', label: 'Ville / zone', fmt: (r) => MW.esc([r.city, r.zone].filter(Boolean).join(' / ') || 'Toutes') }, { key: 'base_fare', label: 'Base', num: true }, { key: 'per_km', label: '/km', num: true }, { key: 'per_min', label: '/min', num: true },
      { key: 'min_fare', label: 'Min.', num: true }, { key: 'night_multiplier_pct', label: 'Nuit %', num: true }, { key: 'is_active', label: 'Active', fmt: (r) => yes(r.is_active) }],
    defaults: { service_type: 'taxi', base_fare: 300, per_km: 150, per_min: 10, min_fare: 700, booking_fee: 0, service_fee: 0, night_multiplier_pct: 100, min_offer_pct: 60, max_offer_pct: 300, priority: 0, is_active: true },
    fields: () => [{ name: 'name', label: 'Nom', required: true }, { name: 'service_type', label: 'Service', type: 'select', options: Object.entries(MW.L.service) },
      { name: 'vehicle_category', label: 'Catégorie de véhicule', type: 'select', options: [['', 'Toutes'], ...Object.entries(MW.L.vehicle)] }, { name: 'city_id', label: 'ID ville (vide = toutes)', type: 'number' }, { name: 'zone_id', label: 'ID zone (vide = toutes)', type: 'number' },
      { name: 'base_fare', label: 'Prise en charge (FCFA)', type: 'number', required: true }, { name: 'per_km', label: 'Prix par km', type: 'number', required: true }, { name: 'per_min', label: 'Prix par minute', type: 'number', required: true },
      { name: 'min_fare', label: 'Tarif minimum', type: 'number', required: true }, { name: 'booking_fee', label: 'Frais de réservation', type: 'number', required: true }, { name: 'service_fee', label: 'Frais de service', type: 'number', required: true },
      { name: 'night_multiplier_pct', label: 'Majoration de nuit (%, 100 = aucune)', type: 'number', required: true }, { name: 'night_start', label: 'Début nuit', type: 'time' }, { name: 'night_end', label: 'Fin nuit', type: 'time' },
      { name: 'min_offer_pct', label: 'Prix négocié minimum (% de l\'estimation)', type: 'number', required: true }, { name: 'max_offer_pct', label: 'Prix négocié maximum (%)', type: 'number', required: true },
      { name: 'priority', label: 'Priorité', type: 'number' }, { name: 'valid_from', label: 'Valide à partir de', type: 'datetime-local' }, { name: 'valid_to', label: 'Valide jusqu\'au', type: 'datetime-local' }, { name: 'is_active', label: 'Active', type: 'checkbox' }],
    normalize: (v) => { intOrNull(v, ['city_id', 'zone_id']); v.vehicle_category = v.vehicle_category || null; ['night_start', 'night_end'].forEach((k) => { if (v[k] && v[k].length === 5) v[k] += ':00'; }); }
  });

  const commissions = (el) => crud(el, {
    title: 'Commissions', path: '/admin/commissions',
    intro: '<div class="alert alert-info">Pourcentage en points de base : 1000 = 10 %. Un contrat individuel (commerce, prestataire, entreprise) prime sur une règle de zone, de ville ou de service. Le modèle « abonnement » n\'applique aucune commission à la commande (facturer l\'abonnement séparément).</div>',
    extraButtons: '<button class="btn btn-outline" data-sim>Simulateur</button><button class="btn btn-outline" data-sub>Facturer un abonnement</button>',
    cols: [{ key: 'name', label: 'Règle' }, { key: 'applies_to', label: 'Sur', fmt: (r) => r.applies_to === 'merchant' ? 'Ventes du commerce' : 'Course / livraison' },
      { key: 'scope', label: 'Portée', fmt: (r) => MW.esc([r.service_type && MW.L.service[r.service_type], r.provider_type && (r.provider_type === 'driver' ? 'Chauffeurs' : 'Livreurs'), r.merchant, r.provider, r.business, r.city].filter(Boolean).join(' · ') || 'Générale') },
      { key: 'model', label: 'Modèle' }, { key: 'percent_bp', label: '%', num: true, fmt: (r) => (r.percent_bp / 100).toFixed(2) + ' %' }, { key: 'fixed_amount', label: 'Fixe', num: true, fmt: (r) => MW.fcfa(r.fixed_amount) },
      { key: 'priority', label: 'Priorité', num: true }, { key: 'is_active', label: 'Active', fmt: (r) => yes(r.is_active) }],
    defaults: { applies_to: 'provider', model: 'percent', percent_bp: 1000, fixed_amount: 0, priority: 0, is_active: true },
    fields: () => [{ name: 'name', label: 'Nom', required: true }, { name: 'applies_to', label: 'S\'applique à', type: 'select', options: [['provider', 'Course / frais de livraison (prestataire)'], ['merchant', 'Sous-total articles (commerce)']] },
      { name: 'model', label: 'Modèle', type: 'select', options: [['percent', 'Pourcentage'], ['fixed', 'Montant fixe'], ['mixed', 'Mixte (% + fixe)'], ['subscription', 'Abonnement (0 à la commande)']] },
      { name: 'percent_bp', label: 'Pourcentage (points de base, 1000 = 10 %)', type: 'number', required: true, min: 0, max: 10000 }, { name: 'fixed_amount', label: 'Montant fixe (FCFA)', type: 'number', required: true, min: 0 },
      { name: 'min_commission', label: 'Commission minimum', type: 'number' }, { name: 'max_commission', label: 'Commission maximum', type: 'number' },
      { name: 'service_type', label: 'Service', type: 'select', options: SERVICES }, { name: 'provider_type', label: 'Catégorie de prestataire', type: 'select', options: [['', 'Toutes'], ['driver', 'Chauffeurs'], ['courier', 'Livreurs']] },
      { name: 'merchant_id', label: 'Contrat commerce (ID)', type: 'number' }, { name: 'provider_id', label: 'Contrat prestataire (ID utilisateur)', type: 'number' }, { name: 'business_id', label: 'Entreprise (ID)', type: 'number' },
      { name: 'city_id', label: 'Ville (ID)', type: 'number' }, { name: 'zone_id', label: 'Zone (ID)', type: 'number' }, { name: 'min_order_amount', label: 'Montant de commande min.', type: 'number' }, { name: 'max_order_amount', label: 'Montant de commande max.', type: 'number' },
      { name: 'priority', label: 'Priorité', type: 'number' }, { name: 'valid_from', label: 'Valide à partir de (promotion temporaire)', type: 'datetime-local' }, { name: 'valid_to', label: 'Valide jusqu\'au', type: 'datetime-local' }, { name: 'is_active', label: 'Active', type: 'checkbox' }],
    normalize: (v) => { intOrNull(v, ['merchant_id', 'provider_id', 'business_id', 'city_id', 'zone_id', 'min_commission', 'max_commission', 'min_order_amount', 'max_order_amount']); v.service_type = v.service_type || null; v.provider_type = v.provider_type || null; },
    after: (el) => {
      el.querySelector('[data-sim]').addEventListener('click', () => MW.formModal('Simulateur de commission', [
        { name: 'applies_to', label: 'Sur', type: 'select', options: [['provider', 'Course / livraison'], ['merchant', 'Ventes commerce']] }, { name: 'amount', label: 'Montant (FCFA)', type: 'number', value: 3000, required: true },
        { name: 'service_type', label: 'Service', type: 'select', options: SERVICES }, { name: 'provider_type', label: 'Prestataire', type: 'select', options: [['', '—'], ['driver', 'Chauffeur'], ['courier', 'Livreur']] }, { name: 'merchant_id', label: 'Commerce (ID)', type: 'number' }],
      async (v) => { intOrNull(v, ['merchant_id']); v.service_type = v.service_type || null; v.provider_type = v.provider_type || null; const r = (await MW.post('/admin/commissions/preview', v)).data;
        MW.modal('Résultat', `<dl class="kv"><dt>Règle appliquée</dt><dd>${MW.esc(r.rule ? r.rule.name : 'aucune')}</dd><dt>Montant</dt><dd>${MW.fcfa(v.amount)}</dd><dt>Commission MARWA</dt><dd>${MW.fcfa(r.commission)}</dd><dt>dont TVA</dt><dd>${MW.fcfa(r.vat_part)}</dd><dt>Net partenaire</dt><dd>${MW.fcfa(r.partner_net)}</dd></dl>`); }, { ok: 'Calculer' }));
      el.querySelector('[data-sub]').addEventListener('click', () => MW.formModal('Facturer un abonnement professionnel', [
        { name: 'partner_type', label: 'Partenaire', type: 'select', options: [['merchant', 'Commerce (ID commerce)'], ['provider', 'Prestataire (ID utilisateur)'], ['business', 'Entreprise (ID entreprise)']] },
        { name: 'owner_id', label: 'Identifiant', type: 'number', required: true }, { name: 'plan_name', label: 'Formule', required: true }, { name: 'amount', label: 'Montant (FCFA)', type: 'number', required: true },
        { name: 'period_start', label: 'Début de période', type: 'date', required: true }, { name: 'period_end', label: 'Fin de période', type: 'date', required: true }],
      async (v) => { await MW.post('/admin/subscriptions', v); MW.toast('Abonnement facturé (débit du compte partenaire).', 'success'); }));
    }
  });

  const promotions = (el) => crud(el, {
    title: 'Promotions', path: '/admin/promotions',
    image: { key: 'image_url', label: 'Photo de la promotion (affichée aux clients)', path: (id) => `/admin/promotions/${id}/image` },
    cols: [{ key: 'image_url', label: '', fmt: (r) => r.image_url ? `<img src="${MW.esc(r.image_url)}" alt="" class="promo-thumb">` : '' }, { key: 'code', label: 'Code' }, { key: 'description', label: 'Description' }, { key: 'value', label: 'Valeur', fmt: (r) => r.discount_type === 'percent' ? (r.value / 100) + ' %' : MW.fcfa(r.value) },
      { key: 'funded_by', label: 'Financée par', fmt: (r) => r.funded_by === 'marwa' ? 'MARWA' : MW.esc(r.merchant || 'Commerce') }, { key: 'used_count', label: 'Utilisations', num: true, fmt: (r) => `${r.used_count}${r.max_uses ? ' / ' + r.max_uses : ''}` },
      { key: 'ends_at', label: 'Fin', fmt: (r) => MW.date(r.ends_at) }, { key: 'is_active', label: 'Active', fmt: (r) => yes(r.is_active) }],
    defaults: { discount_type: 'percent', value: 1000, funded_by: 'marwa', per_user_limit: 1, is_active: true, is_public: true, starts_at: today() + ' 00:00:00', ends_at: today() + ' 23:59:00' },
    fields: () => [{ name: 'code', label: 'Code', required: true }, { name: 'description', label: 'Description', required: true }, { name: 'discount_type', label: 'Type', type: 'select', options: [['percent', 'Pourcentage (points de base)'], ['fixed', 'Montant fixe (FCFA)']] },
      { name: 'value', label: 'Valeur (1000 = 10 % ; ou FCFA)', type: 'number', required: true }, { name: 'max_discount', label: 'Réduction maximum (FCFA)', type: 'number' }, { name: 'min_order', label: 'Commande minimum', type: 'number' },
      { name: 'service_type', label: 'Service', type: 'select', options: SERVICES }, { name: 'merchant_id', label: 'Commerce (ID)', type: 'number' }, { name: 'funded_by', label: 'Financée par', type: 'select', options: [['marwa', 'MARWA'], ['merchant', 'Le commerce']] },
      { name: 'starts_at', label: 'Début', type: 'datetime-local', required: true }, { name: 'ends_at', label: 'Fin', type: 'datetime-local', required: true }, { name: 'max_uses', label: 'Utilisations maximum', type: 'number' },
      { name: 'per_user_limit', label: 'Utilisations par client', type: 'number', required: true }, { name: 'is_public', label: 'Affichée publiquement', type: 'checkbox' }, { name: 'is_active', label: 'Active', type: 'checkbox' }],
    normalize: (v) => { intOrNull(v, ['merchant_id', 'max_discount', 'max_uses']); v.service_type = v.service_type || null; }
  });

  // ------------------------------------------------------------------ Zones, villes, repères
  async function zones(el) {
    const [zs, cities, lms, nbs] = await Promise.all([MW.get('/admin/zones'), MW.get('/admin/cities'), MW.get('/admin/landmarks'), MW.get('/admin/neighborhoods')]);
    el.innerHTML = `<div class="container">${head('Villes, zones et repères', '<button class="btn btn-outline" data-city>Ville</button><button class="btn btn-outline" data-lm>Point de repère</button><button class="btn btn-primary" data-draw>Dessiner une zone</button>')}
      <div class="card" style="padding:8px"><div id="zone-map" class="map tall"></div></div>
      <div class="alert alert-info" style="margin-top:10px">Pour dessiner une zone : cliquez sur « Dessiner », placez les sommets sur la carte puis « Terminer ». Les commandes hors zones actives sont refusées si le paramètre <code>zones.enforce</code> est activé. Les repères peuvent être importés depuis OpenStreetMap : <code>php bin/import-landmarks.php --city=Niamey</code>.</div>
      <div class="grid-2"><div><h2>Zones</h2>${MW.table([{ key: 'name', label: 'Zone' }, { key: 'city', label: 'Ville' }, { key: 'allow_rides', label: 'Courses', fmt: (z) => yes(z.allow_rides) }, { key: 'allow_delivery', label: 'Livraisons', fmt: (z) => yes(z.allow_delivery) }, { key: 'is_active', label: 'Active', fmt: (z) => yes(z.is_active) }, { key: 'id', label: 'ID', num: true }], zs, { onRow: true })}</div>
        <div><h2>Villes</h2>${MW.table([{ key: 'name', label: 'Ville' }, { key: 'region', label: 'Région' }, { key: 'is_active', label: 'Active', fmt: (c) => yes(c.is_active) }, { key: 'id', label: 'ID', num: true }], cities, { onRow: true })}</div></div>
      <h2 style="margin-top:14px">Quartiers</h2><div data-nb></div>
      <h2 style="margin-top:14px">Points de repère (${lms.length})</h2><div data-lm-t></div></div>`;
    const map = await MW.createMap(el.querySelector('#zone-map'), { zoom: 12 });
    zs.forEach((z) => L.polygon(z.polygon.coordinates[0].map((c) => [c[1], c[0]]), { color: Number(z.is_active) ? '#0b6e4f' : '#999', weight: 2, fillOpacity: .08 }).bindTooltip(z.name).addTo(map));
    lms.forEach((l) => L.circleMarker([l.lat, l.lng], { radius: 5, color: Number(l.verified) ? '#1f6fb2' : '#f4a623' }).bindTooltip(l.name).addTo(map));
    const cityOpts = cities.map((c) => [c.id, c.name]);
    const zoneForm = (z = {}, ring) => MW.formModal(z.id ? 'Zone ' + z.name : 'Nouvelle zone', [
      { name: 'name', label: 'Nom', required: true, value: z.name }, { name: 'city_id', label: 'Ville', type: 'select', options: cityOpts, value: z.city_id },
      { name: 'allow_rides', label: 'Courses autorisées', type: 'checkbox', value: z.id ? Number(z.allow_rides) : true }, { name: 'allow_delivery', label: 'Livraisons autorisées', type: 'checkbox', value: z.id ? Number(z.allow_delivery) : true },
      { name: 'is_active', label: 'Active', type: 'checkbox', value: z.id ? Number(z.is_active) : true }],
    async (v) => { v.city_id = Number(v.city_id); v.polygon = { type: 'Polygon', coordinates: [ring || z.polygon.coordinates[0]] }; if (z.id) await MW.put('/admin/zones/' + z.id, v); else await MW.post('/admin/zones', v); zones(el); });
    MW.bindRows(el.querySelectorAll('[data-t], .table-wrap')[0], zs, (z) => zoneForm(z));
    MW.bindRows(el.querySelectorAll('.table-wrap')[1], cities, (c) => cityForm(c));
    const cityForm = (c = {}) => MW.formModal(c.id ? c.name : 'Nouvelle ville', [{ name: 'name', label: 'Nom', required: true, value: c.name }, { name: 'region', label: 'Région', required: true, value: c.region },
      { name: 'lat', label: 'Latitude du centre', type: 'number', step: 'any', required: true, value: c.lat }, { name: 'lng', label: 'Longitude du centre', type: 'number', step: 'any', required: true, value: c.lng }, { name: 'is_active', label: 'Ville ouverte au service', type: 'checkbox', value: Number(c.is_active) }],
    async (v) => { if (c.id) await MW.put('/admin/cities/' + c.id, v); else await MW.post('/admin/cities', v); zones(el); });
    el.querySelector('[data-city]').addEventListener('click', () => cityForm());
    const lmForm = (l = {}, ll) => MW.formModal(l.id ? l.name : 'Nouveau point de repère', [{ name: 'name', label: 'Nom', required: true, value: l.name }, { name: 'category', label: 'Catégorie', required: true, value: l.category || 'autre' },
      { name: 'city_id', label: 'Ville', type: 'select', options: cityOpts, value: l.city_id }, { name: 'lat', label: 'Latitude', type: 'number', step: 'any', required: true, value: ll ? ll.lat.toFixed(6) : l.lat },
      { name: 'lng', label: 'Longitude', type: 'number', step: 'any', required: true, value: ll ? ll.lng.toFixed(6) : l.lng }, { name: 'verified', label: 'Vérifié sur le terrain', type: 'checkbox', value: Number(l.verified) },
      { name: 'is_meeting_point', label: 'Point de rencontre conseillé', type: 'checkbox', value: Number(l.is_meeting_point) }, { name: 'is_active', label: 'Actif', type: 'checkbox', value: l.id ? Number(l.is_active) : true }],
    async (v) => { v.city_id = Number(v.city_id); if (l.id) await MW.put('/admin/landmarks/' + l.id, v); else await MW.post('/admin/landmarks', v); zones(el); });
    el.querySelector('[data-lm]').addEventListener('click', () => { MW.toast('Touchez la carte pour placer le repère.'); map.once('click', (e) => lmForm({}, e.latlng)); });
    el.querySelector('[data-lm-t]').innerHTML = MW.table([{ key: 'name', label: 'Nom' }, { key: 'category', label: 'Catégorie' }, { key: 'source', label: 'Source' }, { key: 'verified', label: 'Vérifié', fmt: (l) => yes(l.verified) }], lms.slice(0, 300), { onRow: true, empty: 'Aucun repère. Importez-les depuis OpenStreetMap ou ajoutez-les manuellement.' });
    MW.bindRows(el.querySelector('[data-lm-t]'), lms, (l) => lmForm(l));
    el.querySelector('[data-nb]').innerHTML = MW.table([{ key: 'name', label: 'Quartier' }, { key: 'city', label: 'Ville' }, { key: 'lat', label: 'Position', fmt: (n) => n.lat ? `${n.lat}, ${n.lng}` : '<span class="badge amber">à renseigner</span>' }, { key: 'verified', label: 'Vérifié', fmt: (n) => yes(n.verified) }], nbs, { onRow: true });
    MW.bindRows(el.querySelector('[data-nb]'), nbs, (n) => MW.formModal(n.name, [{ name: 'name', label: 'Nom', required: true, value: n.name }, { name: 'lat', label: 'Latitude', type: 'number', step: 'any', value: n.lat }, { name: 'lng', label: 'Longitude', type: 'number', step: 'any', value: n.lng },
      { name: 'verified', label: 'Position vérifiée', type: 'checkbox', value: Number(n.verified) }, { html: '<p class="small muted">Astuce : cliquez sur la carte avant d\'ouvrir ce formulaire pour copier une position.</p>' }],
    async (v) => { v.city_id = Number(n.city_id); await MW.put('/admin/neighborhoods/' + n.id, v); zones(el); }));
    // Dessin de polygone
    el.querySelector('[data-draw]').addEventListener('click', (e) => {
      const btn = e.currentTarget;
      if (btn.dataset.drawing) return;
      btn.dataset.drawing = '1'; btn.textContent = 'Terminer la zone';
      const pts = []; const line = L.polyline([], { color: '#f4a623', weight: 3 }).addTo(map);
      const add = (ev) => { pts.push([ev.latlng.lat, ev.latlng.lng]); line.setLatLngs(pts); };
      map.on('click', add);
      btn.onclick = () => {
        map.off('click', add); line.remove(); delete btn.dataset.drawing; btn.textContent = 'Dessiner une zone'; btn.onclick = null;
        if (pts.length < 3) { MW.toast('Au moins 3 sommets sont nécessaires.', 'warn'); return; }
        zoneForm({}, pts.map((p) => [Number(p[1].toFixed(6)), Number(p[0].toFixed(6))]));
      };
    });
  }

  // ------------------------------------------------------------------ Finance
  async function payments(el, params) {
    const [list, rec] = await Promise.all([MW.get('/admin/payments?' + new URLSearchParams(params)), MW.get('/admin/reconciliation')]);
    el.innerHTML = `<div class="container">${head('Paiements et rapprochement', '<a class="btn btn-outline btn-sm" href="#/paiements?status=declared">Espèces à vérifier</a><a class="btn btn-outline btn-sm" href="#/paiements?discrepancy=1">Écarts</a><a class="btn btn-outline btn-sm" href="#/paiements">Tous</a>')}
      <div class="grid-4"><div class="stat ${rec.trial_balance.balanced ? '' : 'highlight'}"><p class="label">Grand livre</p><div class="value">${rec.trial_balance.balanced ? 'Équilibré' : 'DÉSÉQUILIBRÉ'}</div><div class="sub">${MW.fcfa(rec.trial_balance.debit)} au débit et au crédit</div></div>
        <div class="stat"><p class="label">Espèces non vérifiées</p><div class="value">${MW.fcfa(rec.unverified_cash.amount)}</div><div class="sub">${rec.unverified_cash.n} paiement(s)</div></div>
        <div class="stat"><p class="label">Doublons détectés</p><div class="value">${rec.duplicate_payments.length}</div></div><div class="stat"><p class="label">Payées non réglées</p><div class="value">${rec.paid_not_settled.length}</div><div class="sub">${rec.invalid_webhooks.length} notification(s) à signature invalide</div></div></div>
      <div style="margin-top:14px" data-t></div></div>`;
    const t = el.querySelector('[data-t]');
    t.innerHTML = MW.table([{ key: 'reference', label: 'Commande' }, { key: 'created_at', label: 'Date', fmt: (p) => MW.datetime(p.created_at) }, { key: 'method', label: 'Moyen', fmt: (p) => MW.esc(MW.L.payment[p.method]) },
      { key: 'collector_type', label: 'Collecteur' }, { key: 'amount', label: 'Attendu', num: true, fmt: (p) => MW.fcfa(p.amount) }, { key: 'declared_amount', label: 'Déclaré', num: true, fmt: (p) => p.declared_amount !== null ? `${MW.fcfa(p.declared_amount)}${Number(p.declared_amount) !== Number(p.amount) ? ' <span class="badge red">écart</span>' : ''}` : '—' },
      { key: 'verified_amount', label: 'Vérifié', num: true, fmt: (p) => p.verified_amount !== null ? MW.fcfa(p.verified_amount) : '—' }, { key: 'fee_amount', label: 'Frais', num: true, fmt: (p) => MW.fcfa(p.fee_amount) },
      { key: 'status', label: 'Statut', fmt: (p) => `<span class="badge ${p.status === 'confirmed' ? 'green' : p.status === 'declared' ? 'amber' : p.status === 'failed' ? 'red' : ''}">${MW.esc(p.status)}</span>` },
      { key: 'id', label: '', fmt: (p) => p.status === 'declared' && can('payments.manage') ? `<button class="btn btn-sm btn-primary" data-verify="${p.id}">Vérifier</button>` : '' }], list, { empty: 'Aucun paiement.' });
    t.querySelectorAll('[data-verify]').forEach((b) => b.addEventListener('click', () => {
      const p = list.find((x) => String(x.id) === b.dataset.verify);
      MW.formModal('Vérification des espèces — ' + p.reference, [{ html: `<dl class="kv"><dt>Attendu</dt><dd>${MW.fcfa(p.amount)}</dd><dt>Déclaré</dt><dd>${MW.fcfa(p.declared_amount)}</dd></dl>` },
        { name: 'verified_amount', label: 'Montant vérifié (FCFA)', type: 'number', required: true, value: p.declared_amount },
        { name: 'absorb_difference', label: 'MARWA prend l\'écart à sa charge (écriture d\'ajustement)', type: 'checkbox', hint: 'Sinon, le collecteur reste redevable de l\'écart.' }],
      async (v) => { await MW.post(`/admin/payments/${p.id}/verify-cash`, v); payments(el, params); });
    }));
  }

  async function payouts(el) {
    const [list, balances] = await Promise.all([MW.get('/admin/payouts'), MW.get('/admin/balances')]);
    el.innerHTML = `<div class="container">${head('Versements et soldes partenaires', can('payouts.manage') ? '<button class="btn btn-primary" data-remit>Enregistrer un reversement de commission</button>' : '')}
      <h2>Demandes</h2><div data-t></div>
      <h2 style="margin-top:18px">Soldes des partenaires</h2><p class="small muted">Positif : MARWA doit au partenaire · Négatif : le partenaire doit à MARWA (commissions sur espèces, abonnements, créances entreprise).</p><div data-b></div></div>`;
    const t = el.querySelector('[data-t]');
    t.innerHTML = MW.table([{ key: 'requested_at', label: 'Date', fmt: (p) => MW.datetime(p.requested_at) }, { key: 'full_name', label: 'Partenaire' }, { key: 'direction', label: 'Sens', fmt: (p) => p.direction === 'to_partner' ? 'Versement' : 'Reversement reçu' },
      { key: 'amount', label: 'Montant', num: true, fmt: (p) => MW.fcfa(p.amount) }, { key: 'method', label: 'Moyen' }, { key: 'destination', label: 'Destination' }, { key: 'status', label: 'Statut' }, { key: 'reference', label: 'Justificatif' },
      { key: 'id', label: '', fmt: (p) => p.direction === 'to_partner' && ['requested', 'approved'].includes(p.status) && can('payouts.manage') ? `${p.status === 'requested' ? `<button class="btn btn-sm btn-outline" data-po="${p.id}" data-d="approve">Approuver</button>` : ''} <button class="btn btn-sm btn-primary" data-po="${p.id}" data-d="pay">Payer</button> <button class="btn btn-sm btn-ghost" data-po="${p.id}" data-d="reject">Rejeter</button>` : '' }], list, { empty: 'Aucune demande.' });
    t.querySelectorAll('[data-po]').forEach((b) => b.addEventListener('click', () => {
      const d = b.dataset.d;
      MW.formModal({ approve: 'Approuver', pay: 'Confirmer le paiement', reject: 'Rejeter' }[d], [
        ...(d === 'pay' ? [{ name: 'reference', label: 'Référence du justificatif (n° transaction, reçu)', required: true }] : []), { name: 'note', label: 'Note', maxlength: 255 }],
      async (v) => { await MW.post(`/admin/payouts/${b.dataset.po}/review`, Object.assign({ decision: d }, v)); payouts(el); });
    }));
    el.querySelector('[data-b]').innerHTML = MW.table([{ key: 'name', label: 'Compte' }, { key: 'owner_type', label: 'Type' }, { key: 'balance', label: 'Solde', num: true, fmt: (b) => `<span style="color:${b.balance < 0 ? 'var(--red-600)' : 'inherit'}">${MW.fcfa(b.balance)}</span>` },
      { key: 'account_id', label: '', fmt: (b) => `<a href="#/grand-livre?compte=${b.account_id}">Relevé</a>` }], balances, { empty: 'Aucun compte partenaire.' });
    el.querySelector('[data-remit]')?.addEventListener('click', () => MW.formModal('Reversement reçu d\'un partenaire', [
      { name: 'partner_type', label: 'Type', type: 'select', options: [['provider', 'Prestataire (ID utilisateur)'], ['merchant', 'Commerce (ID commerce)']] }, { name: 'owner_id', label: 'Identifiant', type: 'number', required: true },
      { name: 'amount', label: 'Montant reçu (FCFA)', type: 'number', required: true }, { name: 'method', label: 'Moyen', type: 'select', options: [['cash', 'Espèces'], ['mobile_money', 'Mobile Money'], ['bank_transfer', 'Virement']] },
      { name: 'reference', label: 'Référence du reçu', required: true }], async (v) => { await MW.post('/admin/remittances', v); MW.toast('Reversement comptabilisé.', 'success'); payouts(el); }));
  }

  async function ledger(el, params) {
    if (params.compte) {
      const d = await MW.get('/admin/ledger/accounts/' + params.compte);
      el.innerHTML = `<div class="container">${head(d.account.code + ' — ' + d.account.name, '<a href="#/grand-livre">← Grand livre</a>')}<p>Solde (crédit − débit) : <strong>${MW.fcfa(d.balance)}</strong></p>
        ${MW.table([{ key: 'occurred_at', label: 'Date', fmt: (e) => MW.datetime(e.occurred_at) }, { key: 'kind', label: 'Type' }, { key: 'description', label: 'Libellé', fmt: (e) => MW.esc(e.memo || e.description) + (e.order_reference ? ` <span class="small muted">${MW.esc(e.order_reference)}</span>` : '') },
          { key: 'debit', label: 'Débit', num: true, fmt: (e) => e.debit > 0 ? MW.fcfa(e.debit) : '' }, { key: 'credit', label: 'Crédit', num: true, fmt: (e) => e.credit > 0 ? MW.fcfa(e.credit) : '' }], d.entries)}</div>`;
      return;
    }
    const d = await MW.get('/admin/ledger');
    el.innerHTML = `<div class="container">${head('Grand livre', can('payments.manage') ? '<button class="btn btn-outline" data-adj>Écriture d\'ajustement</button>' : '')}
      <div class="alert ${d.trial_balance.balanced ? 'alert-success' : 'alert-error'}">Balance : ${MW.fcfa(d.trial_balance.debit)} au débit, ${MW.fcfa(d.trial_balance.credit)} au crédit — ${d.trial_balance.balanced ? 'équilibrée' : 'DÉSÉQUILIBRÉE'}. Les écritures sont immuables : toute correction passe par une contre-passation ou un ajustement.</div>
      <div class="grid-2"><div><h2>Comptes MARWA</h2>${MW.table([{ key: 'code', label: 'Code' }, { key: 'name', label: 'Compte', fmt: (a) => `<a href="#/grand-livre?compte=${a.id}">${MW.esc(a.name)}</a>` }, { key: 'debit', label: 'Débit', num: true, fmt: (a) => MW.fcfa(a.debit) }, { key: 'credit', label: 'Crédit', num: true, fmt: (a) => MW.fcfa(a.credit) }], d.accounts)}</div>
      <div><h2>Dernières transactions</h2>${MW.table([{ key: 'id', label: '#', num: true }, { key: 'occurred_at', label: 'Date', fmt: (x) => MW.datetime(x.occurred_at) }, { key: 'kind', label: 'Type' }, { key: 'description', label: 'Libellé' }, { key: 'amount', label: 'Montant', num: true, fmt: (x) => MW.fcfa(x.amount) }], d.transactions, { onRow: true })}</div></div></div>`;
    MW.bindRows(el.querySelectorAll('.table-wrap')[1], d.transactions, async (x) => {
      const t = await MW.get('/admin/ledger/transactions/' + x.id);
      const m = MW.modal('Transaction #' + x.id, `<p>${MW.esc(t.transaction.description)}</p>${MW.table([{ key: 'code', label: 'Compte', fmt: (e) => MW.esc(e.code + ' ' + e.name) }, { key: 'memo', label: 'Libellé' }, { key: 'debit', label: 'Débit', num: true, fmt: (e) => e.debit > 0 ? MW.fcfa(e.debit) : '' }, { key: 'credit', label: 'Crédit', num: true, fmt: (e) => e.credit > 0 ? MW.fcfa(e.credit) : '' }], t.entries)}
        ${can('payments.manage') && !t.transaction.reverses_id ? '<div class="modal-actions"><button class="btn btn-danger" data-rev>Contre-passer</button></div>' : ''}`, { wide: true });
      m.el.querySelector('[data-rev]')?.addEventListener('click', async () => { const reason = await MW.confirm('Contre-passer intégralement cette transaction ?', { reason: 'Motif (journalisé)', reasonRequired: true, danger: true }); if (reason) { try { await MW.post(`/admin/ledger/transactions/${x.id}/reverse`, { reason }); m.close(); ledger(el, params); } catch (e) { MW.error(e); } } });
    });
    el.querySelector('[data-adj]')?.addEventListener('click', () => MW.formModal('Écriture d\'ajustement', [
      { name: 'debit_account_id', label: 'Compte débité (ID)', type: 'select', options: d.accounts.map((a) => [a.id, a.code + ' ' + a.name]) },
      { name: 'credit_account_id', label: 'Compte crédité (ID)', type: 'select', options: d.accounts.map((a) => [a.id, a.code + ' ' + a.name]) },
      { name: 'amount', label: 'Montant (FCFA)', type: 'number', required: true }, { name: 'description', label: 'Justification', required: true }],
    async (v) => { v.debit_account_id = Number(v.debit_account_id); v.credit_account_id = Number(v.credit_account_id); await MW.post('/admin/ledger/adjustments', v); ledger(el, params); }));
  }

  async function expenses(el) {
    const list = await MW.get('/admin/expenses');
    el.innerHTML = `<div class="container">${head('Dépenses opérationnelles', can('expenses.manage') ? `<button class="btn btn-primary" data-new>${MW.icon('plus')} Saisir une dépense</button>` : '')}${MW.table([
      { key: 'expense_date', label: 'Date', fmt: (e) => MW.date(e.expense_date) }, { key: 'category', label: 'Catégorie' }, { key: 'description', label: 'Description' }, { key: 'city', label: 'Ville' },
      { key: 'paid_from', label: 'Payé par' }, { key: 'amount', label: 'Montant', num: true, money: true, fmt: (e) => MW.fcfa(e.amount) }, { key: 'created_by_name', label: 'Saisi par' }], list, { empty: 'Aucune dépense saisie.', totals: list.length ? { expense_date: 'TOTAL', amount: list.reduce((s, e) => s + Number(e.amount), 0) } : null })}</div>`;
    el.querySelector('[data-new]')?.addEventListener('click', () => MW.formModal('Nouvelle dépense', [
      { name: 'category', label: 'Catégorie', type: 'select', options: ['Salaires', 'Loyer', 'Marketing', 'Hébergement et logiciels', 'SMS et télécoms', 'Carburant et transport', 'Frais bancaires', 'Assurance', 'Taxes et impôts', 'Autre'] },
      { name: 'description', label: 'Description', required: true }, { name: 'amount', label: 'Montant (FCFA)', type: 'number', required: true, min: 1 }, { name: 'expense_date', label: 'Date', type: 'date', required: true, value: today() },
      { name: 'paid_from', label: 'Payé par', type: 'select', options: [['cash', 'Caisse'], ['bank', 'Banque'], ['mobile_money', 'Mobile Money']] }],
    async (v) => { await MW.post('/admin/expenses', v); expenses(el); }));
  }

  async function refunds(el) {
    const list = await MW.get('/admin/refunds');
    el.innerHTML = `<div class="container">${head('Remboursements')}<div data-t></div></div>`;
    el.querySelector('[data-t]').innerHTML = MW.table([{ key: 'created_at', label: 'Date', fmt: (r) => MW.datetime(r.created_at) }, { key: 'reference', label: 'Commande', fmt: (r) => `<a href="#/commande/${r.order_id}">${MW.esc(r.reference)}</a>` },
      { key: 'amount', label: 'Montant', num: true, fmt: (r) => MW.fcfa(r.amount) }, { key: 'reason', label: 'Motif' }, { key: 'borne_by', label: 'À charge de' }, { key: 'requested_by_name', label: 'Demandé par' }, { key: 'status', label: 'Statut' },
      { key: 'id', label: '', fmt: (r) => r.status === 'requested' && can('refunds.manage') ? `<button class="btn btn-sm btn-primary" data-rf="${r.id}" data-d="approve">Approuver</button> <button class="btn btn-sm btn-ghost" data-rf="${r.id}" data-d="reject">Refuser</button>` : '' }], list, { empty: 'Aucun remboursement.' });
    el.querySelectorAll('[data-rf]').forEach((b) => b.addEventListener('click', () => MW.busy(b, async () => { await MW.post(`/admin/refunds/${b.dataset.rf}/review`, { decision: b.dataset.d }); refunds(el); })));
  }

  // ------------------------------------------------------------------ Support
  async function complaints(el, params) {
    const [list, alerts, ratings] = await Promise.all([MW.get('/admin/complaints' + (params.status ? '?status=' + params.status : '')), MW.get('/admin/alerts'), MW.get('/admin/ratings')]);
    const openAlerts = alerts.filter((a) => a.status !== 'closed');
    el.innerHTML = `<div class="container">${head('Réclamations, litiges et sécurité')}
      ${openAlerts.length ? `<div class="card request-card" style="border-color:var(--red-600)"><h2 style="color:var(--red-600)">${MW.icon('alert')} Alertes de sécurité ouvertes</h2>${MW.table([{ key: 'created_at', label: 'Heure', fmt: (a) => MW.datetime(a.created_at) }, { key: 'full_name', label: 'Utilisateur' }, { key: 'phone', label: 'Téléphone' }, { key: 'reference', label: 'Commande' },
        { key: 'lat', label: 'Position', fmt: (a) => a.lat ? `<a target="_blank" rel="noopener" href="https://www.openstreetmap.org/?mlat=${a.lat}&mlon=${a.lng}#map=17/${a.lat}/${a.lng}">Voir</a>` : '—' }, { key: 'status', label: 'Statut' },
        { key: 'id', label: '', fmt: (a) => `${a.status === 'open' ? `<button class="btn btn-sm btn-primary" data-al="${a.id}" data-s="acknowledged">Prise en charge</button>` : ''} <button class="btn btn-sm btn-outline" data-al="${a.id}" data-s="closed">Clore</button>` }], openAlerts)}</div>` : ''}
      <h2 style="margin-top:14px">Réclamations</h2><div data-t></div>
      <h2 style="margin-top:18px">Avis clients (modération)</h2><div data-r></div></div>`;
    el.querySelectorAll('[data-al]').forEach((b) => b.addEventListener('click', () => MW.busy(b, async () => { await MW.post('/admin/alerts/' + b.dataset.al, { status: b.dataset.s }); complaints(el, params); })));
    const t = el.querySelector('[data-t]');
    t.innerHTML = MW.table([{ key: 'reference', label: 'Réf.' }, { key: 'created_at', label: 'Date', fmt: (c) => MW.datetime(c.created_at) }, { key: 'full_name', label: 'Auteur' }, { key: 'category', label: 'Motif', fmt: (c) => MW.esc(MW.L.complaint[c.category]) },
      { key: 'subject', label: 'Objet' }, { key: 'order_reference', label: 'Commande' }, { key: 'priority', label: 'Priorité', fmt: (c) => `<span class="badge ${c.priority === 'urgent' ? 'red' : c.priority === 'high' ? 'amber' : ''}">${MW.esc(c.priority)}</span>` },
      { key: 'status', label: 'Statut' }, { key: 'assignee', label: 'Agent' }], list, { onRow: true, empty: 'Aucune réclamation.' });
    MW.bindRows(t, list, async (c0) => {
      const c = await MW.get('/admin/complaints/' + c0.id);
      MW.formModal(c.reference + ' — ' + c.subject, [
        { html: `<p class="small muted">${MW.esc(c.full_name)} · ${MW.esc(MW.L.complaint[c.category])}${c.order_reference ? ` · <a href="#/commande/${c.order_id}">${MW.esc(c.order_reference)}</a>` : ''}</p><p>${MW.esc(c.description)}</p>
          <div class="chat" style="max-height:220px">${c.messages.map((m) => `<div class="bubble ${m.author_id === c.user_id ? '' : 'mine'}"><div class="who">${MW.esc(m.full_name)}${Number(m.is_internal) ? ' (note interne)' : ''} · ${MW.datetime(m.created_at)}</div>${MW.esc(m.body)}</div>`).join('')}</div>` },
        { name: 'status', label: 'Statut', type: 'select', value: c.status, options: [['open', 'Ouverte'], ['in_progress', 'En cours'], ['resolved', 'Résolue'], ['rejected', 'Rejetée']] },
        { name: 'priority', label: 'Priorité', type: 'select', value: c.priority, options: ['low', 'normal', 'high', 'urgent'] },
        { name: 'message', label: 'Réponse / note', type: 'textarea' }, { name: 'internal', label: 'Note interne (non visible par l\'utilisateur)', type: 'checkbox' },
        { name: 'resolution', label: 'Décision (obligatoire pour clore)', type: 'textarea', value: c.resolution }, { name: 'assign_to_me', label: 'M\'attribuer cette réclamation', type: 'checkbox' },
        { html: c.order_id ? '<p class="small">Pour un remboursement, ouvrez la commande et utilisez « Rembourser ».</p>' : '' }],
      async (v) => { await MW.post('/admin/complaints/' + c.id, v); complaints(el, params); }, { wide: true });
    });
    el.querySelector('[data-r]').innerHTML = MW.table([{ key: 'created_at', label: 'Date', fmt: (r) => MW.date(r.created_at) }, { key: 'reference', label: 'Commande' }, { key: 'target_type', label: 'Cible' }, { key: 'score', label: 'Note', num: true },
      { key: 'comment', label: 'Commentaire' }, { key: 'status', label: 'Statut' }, { key: 'id', label: '', fmt: (r) => `<button class="btn btn-sm btn-ghost" data-rt="${r.id}" data-s="${r.status === 'hidden' ? 'published' : 'hidden'}">${r.status === 'hidden' ? 'Publier' : 'Masquer'}</button>` }], ratings, { empty: 'Aucun avis.' });
    el.querySelectorAll('[data-rt]').forEach((b) => b.addEventListener('click', () => MW.busy(b, async () => { await MW.post('/admin/ratings/' + b.dataset.rt, { status: b.dataset.s }); complaints(el, params); })));
  }

  // ------------------------------------------------------------------ Rapports
  async function reports(el, params) {
    const type = params.type || 'period'; const from = params.from || monthStart(); const to = params.to || today(); const gran = params.granularity || 'day';
    const q = new URLSearchParams({ from, to, granularity: gran }); if (params.city_id) q.set('city_id', params.city_id); if (params.service_type) q.set('service_type', params.service_type);
    const r = await MW.get(`/admin/reports/${type}?${q}`);
    const types = { period: 'Activité par période', city: 'Par ville', merchant: 'Par restaurant', driver: 'Par chauffeur', courier: 'Par livreur', payment_method: 'Par mode de paiement', commissions: 'Commissions', cash: 'Caisse (espèces)', unpaid: 'Impayés', refunds: 'Remboursements', payouts: 'Versements', expenses: 'Dépenses' };
    const money = (k) => !['label', 'date', 'service_type', 'party', 'partner', 'model', 'pct', 'orders', 'rating', 'status', 'kind', 'since', 'collector', 'reason', 'borne_by', 'partner_type', 'direction', 'method', 'reference', 'category', 'description', 'paid_from'].includes(k);
    el.innerHTML = `<div class="container">${head('Rapports', can('reports.export') ? ['csv', 'xlsx', 'pdf'].map((f) => `<a class="btn btn-outline btn-sm" href="${MW.base}/api/admin/reports/${type}?${q}&format=${f}">${{ csv: 'CSV', xlsx: 'Excel', pdf: 'PDF' }[f]}</a>`).join('') : '')}
      <form class="card row" data-f style="margin-bottom:14px"><select class="input" name="type" style="flex:2;min-width:180px">${Object.entries(types).map(([k, l]) => `<option value="${k}" ${k === type ? 'selected' : ''}>${l}</option>`).join('')}</select>
        <input class="input" type="date" name="from" value="${from}" style="flex:1;min-width:140px"><input class="input" type="date" name="to" value="${to}" style="flex:1;min-width:140px">
        <select class="input" name="granularity" style="flex:1;min-width:120px">${[['day', 'Jour'], ['week', 'Semaine'], ['month', 'Mois'], ['year', 'Année']].map(([k, l]) => `<option value="${k}" ${k === gran ? 'selected' : ''}>${l}</option>`).join('')}</select>
        <select class="input" name="service_type" style="flex:1;min-width:120px">${SERVICES.map(([k, l]) => `<option value="${k}" ${params.service_type === k ? 'selected' : ''}>${l === '—' ? 'Tous services' : l}</option>`).join('')}</select>
        <button class="btn btn-primary">Générer</button></form>
      <h2>${MW.esc(r.title)}</h2><p class="muted">${MW.esc(r.subtitle)}</p>
      ${MW.table(Object.entries(r.columns).map(([k, l]) => ({ key: k, label: l, num: typeof (r.rows[0] || {})[k] === 'number', money: money(k), fmt: (x) => typeof x[k] === 'number' && money(k) ? MW.fcfa(x[k]) : MW.esc(x[k] ?? '') })), r.rows, { totals: r.totals && r.totals.label ? r.totals : null, empty: 'Aucune donnée sur la période.' })}
      <p class="small muted" style="margin-top:10px">Tous les chiffres sont calculés à partir des commandes, paiements, commissions et du grand livre enregistrés en base.</p></div>`;
    el.querySelector('[data-f]').addEventListener('submit', (e) => { e.preventDefault(); const v = MW.values(e.target); location.hash = '#/rapports?' + new URLSearchParams(Object.entries(v).filter(([, x]) => x)); });
  }

  // ------------------------------------------------------------------ Paramètres, notifications, audit
  async function settings(el) {
    const [d, sys] = await Promise.all([MW.get('/admin/settings'), MW.get('/admin/system')]);
    const s = d.settings;
    const LABELS = { 'dispatch.radius_km': 'Rayon de recherche des prestataires (km)', 'dispatch.batch_size': 'Prestataires sollicités par vague', 'dispatch.offer_ttl_seconds': 'Délai de réponse d\'un prestataire (s)', 'dispatch.max_rounds': 'Nombre maximal de vagues',
      'dispatch.location_max_age_s': 'Âge maximal d\'une position GPS pour être sollicité (s)', 'dispatch.rating_weight': 'Poids de la note dans l\'attribution (0 = distance seule)', 'negotiation.counter_ttl_seconds': 'Validité par défaut d\'une contre-proposition (s)',
      'negotiation.max_counters_per_provider': 'Contre-propositions max. par prestataire', 'negotiation.max_customer_updates': 'Modifications de prix max. par le client', 'orders.max_open_per_customer': 'Commandes simultanées max. par client',
      'orders.merchant_accept_timeout_min': 'Délai de confirmation des commerces (min)', 'orders.customer_free_cancel_seconds': 'Annulation sans motif après affectation (s)', 'pricing.rounding_step': 'Arrondi des prix (FCFA)',
      'tracking.history_retention_days': 'Conservation de l\'historique GPS (jours)', 'tracking.stale_after_seconds': 'Position considérée obsolète après (s)', 'tracking.min_interval_seconds': 'Intervalle minimal entre deux positions (s)',
      'finance.vat_rate_bp': 'TVA sur commissions (points de base, 0 = non appliquée)', 'finance.cash_discrepancy_tolerance': 'Tolérance d\'écart de caisse (FCFA)', 'zones.enforce': 'Refuser les commandes hors zones actives', 'ratings.moderation': 'Modérer les avis avant publication',
      'payments.demo_fee_bp': 'Frais simulés de la passerelle démo (points de base)', 'support.phone': 'Téléphone de l\'assistance', 'support.email': 'E-mail de l\'assistance', 'emergency.message': 'Message affiché lors d\'une alerte',
      'nearby.radius_km': 'Rayon d\'affichage des véhicules proches (km)', 'nearby.max_vehicles': 'Véhicules proches affichés au maximum', 'nearby.location_max_age_s': 'Âge maximal de la position d\'un véhicule affiché (s)',
      'catalog.max_photos_per_product': 'Photos maximum par produit', 'security.staff_2fa_required': 'Double authentification obligatoire pour le personnel', 'push.enabled': 'Notifications push activées' };
    const simple = Object.keys(LABELS).filter((k) => k in s);
    el.innerHTML = `<div class="container">${head('Paramètres de la plateforme')}
      <div class="card"><h2>État des fournisseurs externes</h2>${MW.table([{ key: 'k', label: 'Service' }, { key: 'status', label: 'État', fmt: (r) => `<span class="badge">${MW.esc(r.status)}</span>` }, { key: 'detail', label: 'Détail' }], Object.entries(sys.providers).map(([k, v]) => ({ k: { maps: 'Cartographie', routing: 'Itinéraires', geocoding: 'Recherche d\'adresses', sms: 'SMS', email: 'E-mail', whatsapp: 'WhatsApp', payments: 'Paiements électroniques' }[k] || k, ...v })))}
        <p class="small muted" style="margin-top:8px">PHP ${MW.esc(sys.php)} · Base ${MW.esc(sys.database)} · environnement ${MW.esc(sys.env)} · cookies sécurisés : ${sys.https_cookies ? 'oui' : '<strong>non (activer en production HTTPS)</strong>'} · messages en échec : ${sys.outbox_failed}. La configuration des fournisseurs se fait dans le fichier <code>.env</code> (voir docs/SERVICES_EXTERNES.md).</p></div>
      <div class="card"><h2>Contrôle de sécurité</h2><div class="list">${sys.security.map((c) => `<div class="list-item"><span class="badge ${c.status === 'ok' ? 'green' : c.status === 'warn' ? 'amber' : 'red'}">${c.status === 'ok' ? 'OK' : c.status === 'warn' ? 'À voir' : 'Critique'}</span><div class="grow"><div class="title">${MW.esc(c.label)}</div>${c.detail ? `<div class="meta">${MW.esc(c.detail)}</div>` : ''}</div></div>`).join('')}</div>
        <p class="small muted">Notifications push : ${sys.push.subscriptions} appareil(s) abonné(s) pour ${sys.push.users} utilisateur(s). Contrôle complet depuis le serveur : <code>php bin/security-check.php --url=https://votre-domaine</code>.</p></div>
      <form class="card" data-form novalidate><h2>Règles métier</h2><div class="form-row">${simple.map((k) => typeof s[k] === 'boolean'
        ? MW.fields([{ name: k, label: LABELS[k], type: 'checkbox', value: s[k] }]) : MW.fields([{ name: k, label: LABELS[k], type: typeof s[k] === 'number' ? 'number' : 'text', value: s[k], step: 'any' }])).join('')}</div>
        <div class="field"><label>Moyens de paiement proposés</label>${['cash', 'demo_mobile_money', 'business_account'].map((m) => `<label class="check"><input type="checkbox" data-pm value="${m}" ${(s['payments.methods'] || []).includes(m) ? 'checked' : ''} ${m === 'cash' ? 'disabled' : ''}> ${MW.esc(MW.L.payment[m])}</label>`).join('')}</div>
        <div class="field"><label>Numéros d'urgence (un par ligne : Libellé;Numéro) — à vérifier auprès des autorités avant publication</label><textarea class="input" data-em>${MW.esc((s['emergency.numbers'] || []).map((n) => n.label + ';' + n.number).join('\n'))}</textarea></div>
        <div class="field"><label>Objets interdits (un par ligne)</label><textarea class="input" data-pi rows="6">${MW.esc((s.prohibited_items || []).join('\n'))}</textarea></div>
        <button class="btn btn-primary">Enregistrer</button></form>
      <div class="card"><h2>Contenus (CGU, confidentialité, sécurité)</h2>${d.content.map((c) => `<div class="list-item"><div class="grow"><div class="title">${MW.esc(c.title)}</div><div class="meta">/pages/${MW.esc(c.slug)} · ${MW.datetime(c.updated_at)}</div></div><button class="btn btn-sm btn-outline" data-ct="${MW.esc(c.slug)}">Modifier</button></div>`).join('')}</div></div>`;
    const f = el.querySelector('[data-form]');
    f.addEventListener('submit', async (e) => {
      e.preventDefault();
      const v = {}; simple.forEach((k) => { const i = f.querySelector(`[name="${k}"]`); v[k] = i.type === 'checkbox' ? i.checked : (i.type === 'number' ? Number(i.value) : i.value); });
      v['payments.methods'] = ['cash', ...[...f.querySelectorAll('[data-pm]:checked')].map((i) => i.value).filter((x) => x !== 'cash')];
      v['emergency.numbers'] = f.querySelector('[data-em]').value.split('\n').map((l) => l.split(';')).filter((p) => p.length === 2 && p[1].trim()).map(([label, number]) => ({ label: label.trim(), number: number.trim() }));
      v.prohibited_items = f.querySelector('[data-pi]').value.split('\n').map((x) => x.trim()).filter(Boolean);
      try { await MW.post('/admin/settings', { settings: v }); MW.toast('Paramètres enregistrés (journalisés).', 'success'); } catch (err) { MW.showErrors(f, err); }
    });
    el.querySelectorAll('[data-ct]').forEach((b) => b.addEventListener('click', () => { const c = d.content.find((x) => x.slug === b.dataset.ct);
      MW.formModal(c.title, [{ name: 'title', label: 'Titre', required: true, value: c.title }, { name: 'body', label: 'Texte', type: 'textarea', required: true, value: c.body }], async (v) => { v.slug = c.slug; await MW.post('/admin/content', v); settings(el); }, { wide: true }); }));
  }

  async function broadcast(el) {
    el.innerHTML = `<div class="container">${head('Notifications')}<form class="card" data-f novalidate>${MW.fields([
      { name: 'audience', label: 'Destinataires', type: 'select', options: [['all', 'Tous les utilisateurs'], ...['client', 'driver', 'courier', 'merchant', 'business'].map((r) => [r, MW.L.role[r] + 's'])] },
      { name: 'title', label: 'Titre', required: true, maxlength: 150 }, { name: 'body', label: 'Message', type: 'textarea', required: true, maxlength: 500 }])}
      <p class="small muted">Notification dans l'application (et dans le navigateur si autorisé). Les envois SMS de masse ne sont pas proposés : ils sont payants et soumis à consentement.</p><button class="btn btn-primary">Envoyer</button></form></div>`;
    const f = el.querySelector('[data-f]');
    f.addEventListener('submit', async (e) => { e.preventDefault(); if (!(await MW.confirm('Envoyer cette notification ?'))) return; try { const r = await MW.post('/admin/broadcast', MW.values(f)); MW.toast(r.message, 'success'); f.reset(); } catch (err) { MW.showErrors(f, err); } });
  }

  async function audit(el, params) {
    const list = await MW.get('/admin/audit' + (params.action ? '?action=' + encodeURIComponent(params.action) : ''));
    el.innerHTML = `<div class="container">${head('Journal d\'audit', `<form class="row" data-f><input class="input" name="action" placeholder="Filtrer (ex. refund, payout, settings)" value="${MW.esc(params.action || '')}"><button class="btn btn-primary">Filtrer</button></form>`)}
      <p class="small muted">Journal immuable des actions sensibles (connexions du personnel, tarifs, commissions, validations, remboursements, versements, suspensions…).</p>
      ${MW.table([{ key: 'created_at', label: 'Date', fmt: (a) => MW.datetime(a.created_at) }, { key: 'full_name', label: 'Auteur' }, { key: 'action', label: 'Action' }, { key: 'entity_type', label: 'Objet', fmt: (a) => MW.esc((a.entity_type || '') + (a.entity_id ? ' #' + a.entity_id : '')) },
        { key: 'new_values', label: 'Détail', fmt: (a) => `<code class="small" style="white-space:pre-wrap;word-break:break-all">${MW.esc((a.new_values || '').slice(0, 220))}</code>` }, { key: 'ip', label: 'IP' }], list)}</div>`;
    el.querySelector('[data-f]').addEventListener('submit', (e) => { e.preventDefault(); location.hash = '#/audit?action=' + encodeURIComponent(MW.values(e.target).action || ''); });
  }

  // ------------------------------------------------------------------ Langues et traductions
  async function translations(el, params) {
    const locale = params.langue || 'ha';
    const q = new URLSearchParams({ locale, q: params.q || '', missing: params.manquants ? '1' : '', page: params.page || '1' });
    const d = await MW.get('/admin/translations?' + q);
    const pct = d.progress.total ? Math.round(100 * d.progress.done / d.progress.total) : 0;
    const langs = Object.entries(d.all_languages).filter(([c]) => c !== 'fr');
    el.innerHTML = `<div class="container">${head('Langues et traductions', '')}
      <div class="card" style="margin-bottom:14px"><h2>Langues proposées aux utilisateurs</h2>
        <form data-langs class="row" style="flex-wrap:wrap;gap:14px">${Object.entries(d.all_languages).map(([c, n]) => `<label class="check"><input type="checkbox" name="${c}" ${d.languages.includes(c) ? 'checked' : ''} ${c === 'fr' ? 'disabled' : ''}> ${MW.esc(n)}</label>`).join('')}
        <button class="btn btn-primary btn-sm">Enregistrer</button></form>
        <p class="small muted">Une langue sans traduction s'affiche en français. Ajoutez ou corrigez les traductions ci-dessous : elles priment sur les fichiers livrés.</p></div>
      <div class="card"><div class="row between" style="flex-wrap:wrap;gap:10px"><div class="segmented" translate="no">${langs.map(([c, n]) => `<a class="${c === locale ? 'active' : ''}" href="#/traductions?langue=${c}">${MW.esc(n)}</a>`).join('')}</div>
        <span class="badge ${pct === 100 ? 'green' : 'amber'}">${d.progress.done} / ${d.progress.total} textes traduits (${pct} %)</span></div>
        ${['ha', 'dje'].includes(locale) && !d.reviewed_by_native ? '<div class="alert alert-warn" style="margin-top:10px">Traduction proposée automatiquement : faites-la relire par un locuteur natif avant l\'ouverture au public et corrigez ici les formulations.</div>' : ''}
        <form data-f class="row" style="margin:12px 0;flex-wrap:wrap"><input class="input" name="q" placeholder="Rechercher un texte" value="${MW.esc(params.q || '')}" style="flex:1;min-width:180px">
          <label class="check"><input type="checkbox" name="manquants" ${params.manquants ? 'checked' : ''}> Non traduits seulement</label><button class="btn btn-outline">Filtrer</button></form>
        <div class="tr-list" translate="no">${d.items.map((it, i) => `<div class="tr-row"><div class="tr-src">${MW.esc(it.source)}</div>
          <div class="tr-edit"><textarea class="input" rows="1" data-i="${i}">${MW.esc(it.target)}</textarea>${it.edited ? '<span class="badge blue">corrigé</span>' : ''}</div></div>`).join('') || MW.empty('Aucun texte.')}</div>
        <div class="row between" style="margin-top:12px"><span class="small muted">${d.count} texte(s)</span>
          <span class="row">${Number(params.page || 1) > 1 ? `<a class="btn btn-sm btn-outline" href="#/traductions?${new URLSearchParams({ ...params, page: Number(params.page || 1) - 1 })}">Précédent</a>` : ''}
          ${Number(params.page || 1) * 50 < d.count ? `<a class="btn btn-sm btn-outline" href="#/traductions?${new URLSearchParams({ ...params, langue: locale, page: Number(params.page || 1) + 1 })}">Suivant</a>` : ''}</span></div></div></div>`;
    el.querySelector('[data-f]').addEventListener('submit', (e) => { e.preventDefault(); const v = MW.values(e.target); location.hash = '#/traductions?' + new URLSearchParams({ langue: locale, q: v.q || '', ...(v.manquants ? { manquants: '1' } : {}) }); });
    el.querySelector('[data-langs]').addEventListener('submit', (e) => { e.preventDefault(); const v = MW.values(e.target); MW.busy(e.submitter || e.target.querySelector('button'), async () => { await MW.put('/admin/languages', { languages: ['fr', ...Object.keys(v).filter((k) => v[k])] }); MW.toast('Langues enregistrées.', 'success'); }); });
    el.querySelectorAll('[data-i]').forEach((ta) => {
      const it = d.items[Number(ta.dataset.i)];
      ta.addEventListener('change', async () => { try { await MW.put('/admin/translations', { locale, source: it.source, target: ta.value }); ta.classList.add('saved'); MW.toast('Traduction enregistrée.', 'success', 1500); } catch (e) { MW.error(e); } });
    });
  }

  document.addEventListener('DOMContentLoaded', () => {
    const items = [
      { key: '', label: 'Tableau de bord', short: 'Accueil', icon: 'chart', perm: 'admin.dashboard', primary: true, section: 'Pilotage' },
      { key: 'direct', label: 'Suivi en direct', short: 'Direct', icon: 'map', perm: 'orders.view', primary: true },
      { key: 'commandes', label: 'Commandes', icon: 'list', perm: 'orders.view', primary: true },
      { key: 'reclamations', label: 'Réclamations et sécurité', short: 'Support', icon: 'help', perm: 'complaints.manage', primary: true },
      { key: 'utilisateurs', label: 'Utilisateurs', icon: 'users', perm: 'users.view', section: 'Communauté' },
      { key: 'chauffeurs', label: 'Chauffeurs', icon: 'car', perm: 'partners.validate' },
      { key: 'livreurs', label: 'Livreurs', icon: 'moto', perm: 'partners.validate' },
      { key: 'commerces', label: 'Restaurants / commerces', icon: 'store', perm: 'partners.validate' },
      { key: 'entreprises', label: 'Entreprises', icon: 'briefcase', perm: 'partners.validate' },
      { key: 'paiements', label: 'Paiements', icon: 'cash', perm: 'finance.view', section: 'Finance' },
      { key: 'versements', label: 'Versements et soldes', icon: 'wallet', perm: 'finance.view' },
      { key: 'remboursements', label: 'Remboursements', icon: 'cash', perm: 'finance.view' },
      { key: 'grand-livre', label: 'Grand livre', icon: 'book', perm: 'finance.view' },
      { key: 'depenses', label: 'Dépenses', icon: 'file', perm: 'finance.view' },
      { key: 'rapports', label: 'Rapports', icon: 'chart', perm: 'finance.view' },
      { key: 'tarifs', label: 'Tarification', icon: 'tag', perm: 'pricing.manage', section: 'Configuration' },
      { key: 'commissions', label: 'Commissions', icon: 'percent', perm: 'commissions.manage' },
      { key: 'zones', label: 'Villes, zones, repères', icon: 'pin', perm: 'zones.manage' },
      { key: 'promotions', label: 'Promotions', icon: 'tag', perm: 'promotions.manage' },
      { key: 'notifications', label: 'Notifications', icon: 'bell', perm: 'notifications.send' },
      { key: 'parametres', label: 'Paramètres', icon: 'gear', perm: 'settings.manage' },
      { key: 'traductions', label: 'Langues et traductions', icon: 'book', perm: 'settings.manage' },
      { key: 'audit', label: 'Journal d\'audit', icon: 'shield', perm: 'audit.view' },
      { key: 'profil', label: 'Mon profil', icon: 'user' }
    ].filter((i) => !i.perm || can(i.perm));
    MW.buildNav(items);
    const first = items[0] ? items[0].key : 'profil';
    MW.router({
      '/': (el, p) => can('admin.dashboard') ? dashboard(el, p) : (location.hash = '#/' + first),
      '/carte': live, '/direct': live, '/utilisateurs': users, '/chauffeurs': (el, p) => providers(el, p, 'driver'), '/livreurs': (el, p) => providers(el, p, 'courier'),
      '/commerces': merchants, '/entreprises': businesses, '/commandes': orders, '/commande/:id': order, '/tarifs': pricing, '/commissions': commissions, '/zones': zones,
      '/promotions': promotions, '/paiements': payments, '/versements': payouts, '/grand-livre': ledger, '/depenses': expenses, '/remboursements': refunds,
      '/reclamations': complaints, '/rapports': reports, '/notifications': broadcast, '/parametres': settings, '/traductions': translations, '/audit': audit, '/profil': (el) => MW.views.profile(el)
    }, document.getElementById('view'));
  });
})();
