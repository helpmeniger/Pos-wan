/* =====================================================================
   MARWA — espace entreprise / client professionnel
   ===================================================================== */
(function () {
  'use strict';
  const MW = window.MW;

  async function overview(el) {
    let d;
    try { d = await MW.get('/business'); } catch (e) {
      el.innerHTML = `<div class="container"><div class="alert alert-info">${MW.esc(e.message)}</div><a class="btn btn-primary" href="${MW.base}/client">Commander</a></div>`; return;
    }
    const b = d.business;
    el.innerHTML = `<div class="container"><div class="page-head"><div><h1>${MW.esc(b.name)}</h1><span class="badge ${b.status === 'verified' ? 'green' : 'amber'}">${MW.esc(MW.L.verif[b.status])}</span> ${Number(b.postpaid_enabled) ? '<span class="badge blue">Paiement différé activé</span>' : '<span class="badge gray">Paiement différé non activé</span>'}</div>
      <div class="row"><a class="btn btn-primary" href="${MW.base}/client#/taxi">${MW.icon('car')} Commander une course</a><a class="btn btn-outline" href="${MW.base}/client#/colis">${MW.icon('box')} Livraison</a></div></div>
      <div class="grid-4"><div class="stat highlight"><p class="label">Dépenses du mois</p><div class="value">${MW.fcfa(d.month_spend)}</div><div class="sub">${b.monthly_budget ? 'budget ' + MW.fcfa(b.monthly_budget) : 'sans budget défini'}</div></div>
        <div class="stat"><p class="label">Encours à régler</p><div class="value">${MW.fcfa(d.outstanding)}</div><div class="sub">limite ${MW.fcfa(b.credit_limit)}</div></div>
        <div class="stat"><p class="label">Collaborateurs</p><div class="value">${d.members.length}</div></div>
        <div class="stat"><p class="label">Adresses</p><div class="value">${d.addresses.length}</div></div></div>
      ${!Number(b.postpaid_enabled) ? '<div class="alert alert-info" style="margin-top:14px">Le paiement sur compte entreprise (facturation périodique) est activé par MARWA après validation de votre dossier. En attendant, vos collaborateurs peuvent payer en espèces ou par Mobile Money.</div>' : ''}
      <div class="card" style="margin-top:14px"><h2>Profil de facturation</h2><form data-form novalidate class="form-row">${MW.fields([
        { name: 'name', label: 'Raison sociale', required: true, value: b.name }, { name: 'tax_id', label: 'NIF', value: b.tax_id }, { name: 'rccm', label: 'RCCM', value: b.rccm },
        { name: 'billing_email', label: 'E-mail de facturation', type: 'email', value: b.billing_email }, { name: 'billing_address', label: 'Adresse de facturation', value: b.billing_address },
        { name: 'monthly_budget', label: 'Budget mensuel (FCFA)', type: 'number', value: b.monthly_budget }])}<div><button class="btn btn-primary" ${b.member_role === 'owner' ? '' : 'disabled'}>Enregistrer</button></div></form></div></div>`;
    const f = el.querySelector('[data-form]');
    f.addEventListener('submit', async (e) => { e.preventDefault(); try { await MW.put('/business', MW.values(f)); MW.toast('Enregistré.', 'success'); } catch (err) { MW.showErrors(f, err); } });
  }

  async function members(el) {
    const d = await MW.get('/business');
    el.innerHTML = `<div class="container"><div class="page-head"><h1>Collaborateurs</h1><button class="btn btn-primary" data-add>${MW.icon('plus')} Ajouter</button></div>
      ${MW.table([{ key: 'full_name', label: 'Nom' }, { key: 'phone', label: 'Contact', fmt: (r) => MW.esc(r.phone || r.email || '') },
        { key: 'member_role', label: 'Rôle', fmt: (r) => MW.esc({ owner: 'Propriétaire', manager: 'Gestionnaire', employee: 'Collaborateur' }[r.member_role]) },
        { key: 'monthly_limit', label: 'Plafond mensuel', num: true, fmt: (r) => r.monthly_limit === null ? 'Illimité' : MW.fcfa(r.monthly_limit) },
        { key: 'month_spend', label: 'Dépensé ce mois', num: true, fmt: (r) => MW.fcfa(r.month_spend) },
        { key: 'status', label: 'Statut', fmt: (r) => `${r.status === 'active' ? '<span class="badge green">Actif</span>' : '<span class="badge gray">Désactivé</span>'} ${Number(r.can_order) ? '' : '<span class="badge amber">Sans commande</span>'}` },
        { key: 'id', label: '', fmt: (r) => r.member_role === 'owner' ? '' : `<button class="btn btn-sm btn-outline" data-edit="${r.id}">Modifier</button>` }], d.members)}</div>`;
    el.querySelector('[data-add]').addEventListener('click', () => MW.formModal('Ajouter un collaborateur', [
      { html: '<p class="small muted">Le collaborateur doit disposer d\'un compte client MARWA.</p>' },
      { name: 'identifier', label: 'Téléphone ou e-mail du compte MARWA', required: true },
      { name: 'member_role', label: 'Rôle', type: 'select', options: [['employee', 'Collaborateur (commande)'], ['manager', 'Gestionnaire (gère le compte)']] },
      { name: 'monthly_limit', label: 'Plafond mensuel (FCFA, vide = illimité)', type: 'number', min: 0 },
      { name: 'can_order', label: 'Autorisé à commander sur le compte', type: 'checkbox', value: true }],
    async (v) => { await MW.post('/business/members', v); members(el); }));
    el.querySelectorAll('[data-edit]').forEach((b) => b.addEventListener('click', () => {
      const m = d.members.find((x) => String(x.id) === b.dataset.edit);
      MW.formModal('Modifier ' + m.full_name, [{ name: 'monthly_limit', label: 'Plafond mensuel (vide = illimité)', type: 'number', value: m.monthly_limit },
        { name: 'can_order', label: 'Autorisé à commander', type: 'checkbox', value: Number(m.can_order) }, { name: 'status', label: 'Statut', type: 'select', value: m.status, options: [['active', 'Actif'], ['disabled', 'Désactivé']] }],
      async (v) => { await MW.put('/business/members/' + m.id, v); members(el); });
    }));
  }

  async function orders(el) {
    const list = await MW.get('/business/orders?limit=100');
    el.innerHTML = `<div class="container"><div class="page-head"><h1>Commandes de l'entreprise</h1></div>${MW.table([
      { key: 'reference', label: 'Référence' }, { key: 'created_at', label: 'Date', fmt: (r) => MW.datetime(r.created_at) }, { key: 'employee', label: 'Collaborateur' },
      { key: 'service_type', label: 'Service', fmt: (r) => MW.esc(MW.L.service[r.service_type]) }, { key: 'dropoff', label: 'Destination', fmt: (r) => `<span class="ellipsis" style="display:inline-block;max-width:220px">${MW.esc(r.dropoff.text)}</span>` },
      { key: 'status', label: 'Statut', fmt: (r) => MW.statusBadge(r.status) }, { key: 'payment_method', label: 'Paiement', fmt: (r) => MW.esc(MW.L.payment[r.payment_method]) },
      { key: 'total', label: 'Montant', num: true, fmt: (r) => MW.fcfa(r.total) }], list, { empty: 'Aucune commande.' })}</div>`;
  }

  async function statements(el, params) {
    const month = params.mois || new Date().toISOString().slice(0, 7);
    const d = await MW.get('/business/statement?month=' + month);
    el.innerHTML = `<div class="container"><div class="page-head"><h1>Relevés mensuels</h1><div class="row">
      <a class="btn btn-outline btn-sm" href="${MW.base}/api/business/statement?month=${month}&format=csv">CSV</a><a class="btn btn-outline btn-sm" href="${MW.base}/api/business/statement?month=${month}&format=xlsx">Excel</a><a class="btn btn-outline btn-sm" href="${MW.base}/api/business/statement?month=${month}&format=pdf">PDF</a></div></div>
      <form class="card row" data-f style="margin-bottom:14px"><label>Mois <input class="input" type="month" name="mois" value="${month}"></label><button class="btn btn-primary">Afficher</button></form>
      <div class="grid-2"><div>${MW.table([{ key: 'label', label: 'Réf.' }, { key: 'date', label: 'Date' }, { key: 'employee', label: 'Collaborateur' }, { key: 'total', label: 'Montant', num: true, money: true, fmt: (r) => MW.fcfa(r.total) }], d.rows, { totals: d.totals.label ? d.totals : null, empty: 'Aucune commande ce mois-ci.' })}</div>
      <div class="card"><h2>Par collaborateur</h2>${d.by_employee.map((r) => `<div class="list-item"><div class="grow">${MW.esc(r.full_name)}<div class="meta">${r.orders} commande(s)</div></div><strong>${MW.fcfa(r.amount)}</strong></div>`).join('') || MW.empty('—')}</div></div></div>`;
    el.querySelector('[data-f]').addEventListener('submit', (e) => { e.preventDefault(); location.hash = '#/releves?mois=' + MW.values(e.target).mois; });
  }

  async function addresses(el) {
    const d = await MW.get('/business');
    el.innerHTML = `<div class="container"><div class="page-head"><h1>Adresses de l'entreprise</h1><button class="btn btn-primary" data-add>${MW.icon('plus')} Ajouter</button></div>
      <div class="card">${d.addresses.map((a) => `<div class="list-item">${MW.icon('pin')}<div class="grow"><div class="title">${MW.esc(a.label)}</div><div class="meta">${MW.esc(a.address_text)}</div></div><button class="btn btn-sm btn-ghost" data-del="${a.id}">Supprimer</button></div>`).join('') || MW.empty('Aucune adresse (sièges, entrepôts, sites clients…).', 'pin')}</div></div>`;
    el.querySelector('[data-add]').addEventListener('click', () => MW.formModal('Nouvelle adresse', [{ name: 'label', label: 'Nom', required: true }, { name: 'address_text', label: 'Adresse / repère', required: true }], async (v) => { await MW.post('/business/addresses', v); addresses(el); }));
    el.querySelectorAll('[data-del]').forEach((b) => b.addEventListener('click', async () => { await MW.del('/business/addresses/' + b.dataset.del); addresses(el); }));
  }

  document.addEventListener('DOMContentLoaded', () => {
    MW.buildNav([
      { key: '', label: 'Vue d\'ensemble', short: 'Accueil', icon: 'briefcase', primary: true },
      { key: 'collaborateurs', label: 'Collaborateurs', icon: 'users', primary: true },
      { key: 'commandes', label: 'Commandes', icon: 'list', primary: true },
      { key: 'releves', label: 'Relevés et exports', short: 'Relevés', icon: 'file', primary: true },
      { key: 'adresses', label: 'Adresses', icon: 'pin' },
      { key: 'profil', label: 'Mon profil', icon: 'user' },
      { key: 'assistance', label: 'Assistance', icon: 'help' }
    ]);
    MW.router({ '/': overview, '/collaborateurs': members, '/commandes': orders, '/releves': statements, '/adresses': addresses,
      '/profil': (el) => MW.views.profile(el), '/assistance': (el, p) => MW.views.support(el, p), '/assistance/:id': (el, p) => MW.views.complaint(el, p) }, document.getElementById('view'));
  });
})();
