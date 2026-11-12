/*
 * Marwa Pay Connect — bouton « PAIEMENT ÉLECTRONIQUE » de la fenêtre d'encaissement.
 * La caisse ne fait que demander et suivre : le statut vient TOUJOURS du serveur, qui le vérifie auprès du
 * prestataire. Aucune donnée de carte n'est saisie ici (ni numéro, ni CVV, ni code PIN).
 */
(function () {
  'use strict';
  const P = window.PosPay;
  const btn = document.getElementById('payElectronic');
  if (!P || !btn) return;
  const base = (document.querySelector('meta[name="base-url"]') || {}).content || '';
  const url = (u) => base.replace(/\/$/, '') + u;
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const fmt = (n) => (window.PWMoney ? window.PWMoney.fmt(n) : Number(n).toLocaleString('fr-FR'));
  const uuid = () => (crypto.randomUUID ? crypto.randomUUID() : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => { const r = (Math.random() * 16) | 0; return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16); }));
  let methods = null; let current = null; let requestKey = null; let poll = null;

  const box = document.createElement('div');
  box.className = 'modal fade'; box.id = 'epayModal'; box.tabIndex = -1;
  box.innerHTML = `<div class="modal-dialog modal-dialog-centered"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title"><i class="bi bi-credit-card-2-front"></i> Paiement électronique</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button></div>
    <div class="modal-body"><div id="epStep1"><div class="d-grid gap-2 mb-2" id="epMethods"></div>
      <label class="form-label small mb-0" for="epAmount">Montant</label><input id="epAmount" class="form-control form-control-lg text-end fw-bold mb-2" inputmode="decimal" autocomplete="off">
      <div id="epPhoneBox" class="d-none"><label class="form-label small mb-0" for="epPhone">Téléphone du payeur</label><input id="epPhone" class="form-control mb-2" inputmode="tel" autocomplete="off" maxlength="20"></div>
      <div id="epAuthBox" class="d-none"><label class="form-label small mb-0" for="epAuth">N° d'autorisation imprimé par le TPE</label><input id="epAuth" class="form-control mb-2" autocomplete="off" maxlength="40">
        <div class="form-text">TPE autonome : la transaction restera « À vérifier » jusqu'au rapprochement avec le relevé de la banque.</div></div>
      <div class="small text-secondary">Ne saisissez jamais un numéro de carte, un CVV ou un code PIN dans POS Marwa.</div></div>
    <div id="epStep2" class="d-none text-center"><div id="epStatus" class="fs-5 fw-bold mb-2"></div><div id="epInfo" class="small mb-2"></div><div id="epLink" class="mb-2"></div></div>
    <div class="alert alert-danger d-none mt-2 mb-0" id="epError"></div></div>
    <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Fermer</button>
      <button type="button" class="btn btn-outline-danger d-none" id="epCancel">Annuler la demande</button>
      <button type="button" class="btn btn-primary" id="epGo"><i class="bi bi-send"></i> Envoyer la demande</button></div></div></div>`;
  document.body.appendChild(box);
  const $ = (s) => box.querySelector(s);
  const modal = () => bootstrap.Modal.getOrCreateInstance(box);
  let selected = null;

  async function api(u, opts = {}) {
    try {
      const r = await fetch(url(u), { method: opts.method || 'GET', credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': P.csrf() },
        body: opts.body ? JSON.stringify(Object.assign({ _csrf: P.csrf() }, opts.body)) : undefined });
      const d = await r.json().catch(() => ({ ok: false, error: 'Réponse illisible du serveur.' }));
      return d;
    } catch (e) { return { ok: false, network: true, error: 'Connexion au serveur interrompue.' }; }
  }
  function err(m) { const e = $('#epError'); e.textContent = m || ''; e.classList.toggle('d-none', !m); }
  function stopPoll() { if (poll) { clearTimeout(poll); poll = null; } }

  function pick(m) {
    selected = m;
    box.querySelectorAll('[data-epm]').forEach((b) => b.classList.toggle('active', Number(b.dataset.epm) === m.id));
    $('#epPhoneBox').classList.toggle('d-none', !m.needs_phone);
    $('#epAuthBox').classList.toggle('d-none', !m.manual);
  }
  function step(n) { $('#epStep1').classList.toggle('d-none', n !== 1); $('#epStep2').classList.toggle('d-none', n !== 2); $('#epGo').classList.toggle('d-none', n !== 1); }

  function show(t) {
    current = t; step(2);
    const cls = { succeeded: 'text-success', declined: 'text-danger', failed: 'text-danger', cancelled: 'text-secondary', expired: 'text-secondary', to_verify: 'text-warning' }[t.status] || 'text-primary';
    $('#epStatus').className = 'fs-5 fw-bold mb-2 ' + cls;
    $('#epStatus').textContent = t.status_label + ' — ' + fmt(t.amount) + (t.test ? ' (TEST)' : '');
    $('#epInfo').innerHTML = esc(t.number) + (t.message ? '<br>' + esc(t.message) : '') + (t.payer_hint ? '<br>' + esc(t.payer_hint) : '');
    $('#epLink').innerHTML = t.redirect_url ? `<a href="${esc(t.redirect_url)}" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-outline-primary">Ouvrir la page de paiement du client</a><div class="small text-secondary mt-1">Le paiement ne sera pris en compte qu'après confirmation du prestataire.</div>` : '';
    const open = t.status === 'pending' || t.status === 'processing';
    $('#epCancel').classList.toggle('d-none', !open);
    stopPoll();
    if (t.usable) {
      P.add({ method: t.sale_method, amount: t.amount, reference: t.reference, transaction: t.id, label: t.label });
      P.toast('Paiement électronique ' + (t.status === 'succeeded' ? 'confirmé' : 'enregistré (à vérifier)') + ' : ' + fmt(t.amount), t.status === 'succeeded' ? 'success' : 'warning');
      setTimeout(() => modal().hide(), 900);
    } else if (open) {
      poll = setTimeout(async () => { const d = await api('/pay/caisse/transactions/' + t.id); if (d.ok) show(d.transaction); else poll = setTimeout(() => show(current), 5000); }, 3000);
    } else {
      requestKey = null; // refus, annulation, expiration : une nouvelle demande pourra être faite
      $('#epGo').classList.remove('d-none'); $('#epGo').innerHTML = '<i class="bi bi-arrow-repeat"></i> Nouvelle demande';
    }
  }

  async function open() {
    err(''); step(1); current = null; requestKey = null; stopPoll();
    $('#epGo').innerHTML = '<i class="bi bi-send"></i> Envoyer la demande';
    if (!P.online()) { P.toast('Paiement électronique indisponible hors connexion.', 'warning'); return; }
    const rest = P.remaining();
    if (rest <= 0) { P.toast('Rien à payer.', 'warning'); return; }
    $('#epAmount').value = String(rest);
    if (!methods) { const d = await api('/pay/caisse/moyens'); methods = d.ok ? d.methods : []; }
    if (!methods.length) { P.toast('Aucun moyen de paiement électronique actif pour cette caisse.', 'warning'); return; }
    $('#epMethods').innerHTML = methods.map((m) => `<button type="button" class="btn btn-outline-primary text-start" data-epm="${m.id}"><i class="bi ${esc(m.icon)}"></i> ${esc(m.label)}${m.test ? ' <span class="badge text-bg-dark">TEST</span>' : ''}${m.currency !== 'XOF' ? ' <small>' + esc(m.currency) + '</small>' : ''}</button>`).join('');
    pick(methods[0]);
    modal().show();
  }

  box.addEventListener('click', (e) => { const b = e.target.closest('[data-epm]'); if (b) pick(methods.find((m) => m.id === Number(b.dataset.epm))); });
  $('#epGo').addEventListener('click', async () => {
    if (current && !current.usable && !['pending', 'processing'].includes(current.status)) { step(1); current = null; $('#epGo').innerHTML = '<i class="bi bi-send"></i> Envoyer la demande'; return; }
    err('');
    const amount = Number(String($('#epAmount').value).replace(/\s/g, '').replace(',', '.'));
    if (!(amount > 0) || amount > P.remaining() + 0.009) { err('Montant invalide : il ne peut pas dépasser le reste dû (' + fmt(P.remaining()) + ').'); return; }
    requestKey = requestKey || uuid(); // conservée en cas de nouvel essai réseau : aucun double débit
    $('#epGo').disabled = true;
    const d = await api('/pay/caisse/payer', { method: 'POST', body: { method_id: selected.id, amount, cart_uuid: P.cartUuid(), request_key: requestKey, customer_id: P.customerId(), payer_phone: $('#epPhone').value, authorization_code: $('#epAuth').value } });
    $('#epGo').disabled = false;
    if (!d.ok) { err(d.error + (d.network ? ' Renvoyez : la même demande sera reprise sans double débit.' : '')); if (!d.network) requestKey = null; return; }
    show(d.transaction);
  });
  $('#epCancel').addEventListener('click', async () => {
    if (!current || !confirm('Demander l\'annulation au prestataire ?')) return;
    const d = await api('/pay/caisse/transactions/' + current.id + '/annuler', { method: 'POST', body: {} });
    if (!d.ok) { err(d.error); return; }
    show(d.transaction);
  });
  // Fenêtre ouverte par-dessus la fenêtre d'encaissement : elle et son fond passent devant
  box.addEventListener('show.bs.modal', () => { box.style.zIndex = '1070'; });
  box.addEventListener('shown.bs.modal', () => { const bd = document.querySelectorAll('.modal-backdrop'); if (bd.length) bd[bd.length - 1].style.zIndex = '1065'; });
  box.addEventListener('hidden.bs.modal', () => {
    stopPoll();
    if (current && ['pending', 'processing'].includes(current.status)) P.toast('Demande ' + current.number + ' encore en attente : rouvrez « Paiement électronique » ou consultez Marwa Pay > Transactions.', 'warning');
  });
  btn.addEventListener('click', open);
})();
