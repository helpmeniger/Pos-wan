/* =====================================================================
   MARWA — vues partagées entre les espaces : profil, assistance,
   messagerie de commande, bouton d'urgence, évaluation
   ===================================================================== */
(function () {
  'use strict';
  const MW = window.MW;
  MW.views = MW.views || {};

  // ------------------------------------------------------------------ Profil
  MW.views.profile = async function (el) {
    const d = await MW.get('/auth/me');
    const u = d.user;
    el.innerHTML = `<div class="container"><div class="page-head"><h1>Mon profil</h1></div>
      <div class="grid-2">
        <div class="card"><h2>Informations</h2>
          <div class="row" style="margin-bottom:14px"><span class="avatar" style="width:64px;height:64px;font-size:1.4rem">${u.photo_url ? `<img src="${MW.esc(u.photo_url)}" alt="">` : MW.esc(u.full_name[0])}</span>
            <label class="btn btn-outline btn-sm">Changer la photo<input type="file" accept="image/jpeg,image/png,image/webp" hidden data-photo></label></div>
          <form data-profile novalidate>${MW.fields([
            { name: 'full_name', label: 'Nom complet', required: true, value: u.full_name, maxlength: 120 },
            { name: 'phone', label: 'Téléphone', type: 'tel', value: u.phone || '' },
            { name: 'email', label: 'E-mail', type: 'email', value: u.email || '' }])}
            <button class="btn btn-primary" type="submit">Enregistrer</button></form>
          <hr class="divider">
          <div class="row between"><span>Téléphone ${u.phone_verified ? '<span class="badge green">vérifié</span>' : '<span class="badge amber">non vérifié</span>'}</span>
            ${u.phone && !u.phone_verified ? '<button class="btn btn-sm btn-outline" data-otp>Vérifier par SMS</button>' : ''}</div>
        </div>
        <div class="card"><h2>Sécurité</h2>
          <form data-password novalidate>${MW.fields([
            { name: 'current_password', label: 'Mot de passe actuel', type: 'password', required: true },
            { name: 'password', label: 'Nouveau mot de passe', type: 'password', required: true, hint: '8 caractères minimum, lettre et chiffre.' }])}
            <button class="btn btn-primary" type="submit">Changer le mot de passe</button></form>
          <hr class="divider">
          <h3>Notifications du navigateur</h3>
          <p class="small muted">Recevez une alerte lorsque l'application est en arrière-plan (selon le navigateur et l'appareil).</p>
          <button class="btn btn-outline btn-sm" data-notif>${'Notification' in window && Notification.permission === 'granted' ? 'Activées' : 'Activer'}</button>
          ${u.is_staff ? '' : `<hr class="divider"><h3>Supprimer mon compte</h3>
          <p class="small muted">Vos données personnelles seront anonymisées. Les écritures financières sont conservées de manière anonyme conformément aux obligations légales.</p>
          <button class="btn btn-danger btn-sm" data-delete>Supprimer mon compte</button>`}
        </div>
      </div></div>`;
    const pf = el.querySelector('[data-profile]');
    pf.addEventListener('submit', async (e) => { e.preventDefault(); try { await MW.put('/account/profile', MW.values(pf)); MW.toast('Profil enregistré.', 'success'); } catch (err) { MW.showErrors(pf, err); } });
    const pw = el.querySelector('[data-password]');
    pw.addEventListener('submit', async (e) => { e.preventDefault(); try { await MW.post('/account/password', MW.values(pw)); pw.reset(); MW.toast('Mot de passe modifié.', 'success'); } catch (err) { MW.showErrors(pw, err); } });
    el.querySelector('[data-photo]').addEventListener('change', async (e) => {
      const fd = new FormData(); fd.append('file', e.target.files[0]);
      try { await MW.api('POST', '/account/photo', fd); MW.toast('Photo mise à jour.', 'success'); MW.views.profile(el); } catch (err) { MW.error(err); }
    });
    el.querySelector('[data-notif]').addEventListener('click', () => 'Notification' in window ? Notification.requestPermission().then((p) => MW.toast(p === 'granted' ? 'Notifications activées.' : 'Notifications refusées par le navigateur.')) : MW.toast('Non pris en charge par ce navigateur.'));
    el.querySelector('[data-otp]')?.addEventListener('click', async (e) => MW.busy(e.currentTarget, async () => {
      const r = await MW.post('/auth/otp/send');
      if (r.debug_code) MW.toast('Mode développement (aucun SMS réel) — code : ' + r.debug_code, 'warn', 15000);
      MW.formModal('Vérifier mon numéro', [{ name: 'code', label: 'Code reçu par SMS', required: true, maxlength: 6 }], async (v) => { await MW.post('/auth/otp/verify', v); MW.toast('Numéro vérifié.', 'success'); MW.views.profile(el); }, { ok: 'Vérifier' });
    }));
    el.querySelector('[data-delete]')?.addEventListener('click', () => MW.formModal('Supprimer mon compte', [
      { html: '<div class="alert alert-error">Cette action est définitive.</div>' }, { name: 'password', label: 'Confirmez avec votre mot de passe', type: 'password', required: true }],
    async (v) => { await MW.post('/account/delete', v); location.href = MW.base + '/'; }, { ok: 'Supprimer définitivement' }));
  };

  // ------------------------------------------------------------------ Assistance & réclamations
  MW.views.support = async function (el, params = {}) {
    const [list, cfg] = await Promise.all([MW.get('/complaints'), MW.geoConfig()]);
    el.innerHTML = `<div class="container"><div class="page-head"><h1>Assistance</h1><button class="btn btn-primary" data-new>${MW.icon('plus')} Nouvelle demande</button></div>
      <div class="grid-2">
        <div class="card"><h2>Mes demandes</h2>${list.length ? `<div class="list">${list.map((c) => `<a class="list-item" href="#/assistance/${c.id}"><div class="grow"><div class="title">${MW.esc(c.subject)}</div>
          <div class="meta">${MW.esc(c.reference)} · ${MW.esc(MW.L.complaint[c.category])}${c.order_reference ? ' · ' + MW.esc(c.order_reference) : ''} · ${MW.date(c.created_at)}</div></div>
          <span class="badge ${c.status === 'resolved' ? 'green' : c.status === 'rejected' ? 'gray' : 'amber'}">${{ open: 'Ouverte', in_progress: 'En cours', resolved: 'Résolue', rejected: 'Clôturée' }[c.status]}</span></a>`).join('')}</div>` : MW.empty('Aucune demande.', 'help')}</div>
        <div class="card"><h2>Urgence et sécurité</h2>
          <div class="alert alert-warn">${MW.esc((cfg.emergency && cfg.emergency.message) || '')}</div>
          ${(cfg.emergency && cfg.emergency.numbers && cfg.emergency.numbers.length) ? `<div class="list">${cfg.emergency.numbers.map((n) => `<a class="list-item" href="tel:${MW.esc(n.number)}">${MW.icon('phone')}<div class="grow"><div class="title">${MW.esc(n.label)}</div><div class="meta">${MW.esc(n.number)}</div></div></a>`).join('')}</div>` : '<p class="small muted">Numéros d\'urgence non encore configurés par l\'administration.</p>'}
          ${cfg.support && (cfg.support.phone || cfg.support.email) ? `<p>Assistance MARWA : ${MW.esc(cfg.support.phone)} ${MW.esc(cfg.support.email)}</p>` : ''}
          <p class="small"><a href="${MW.base}/pages/securite">Règles de sécurité</a> · <a href="${MW.base}/pages/cgu">Conditions d'utilisation</a></p>
        </div>
      </div></div>`;
    el.querySelector('[data-new]').addEventListener('click', () => MW.complaintForm({ order_id: params.commande }));
    if (params.nouveau) MW.complaintForm({ order_id: params.commande, category: params.categorie });
  };

  MW.complaintForm = function (pre = {}) {
    MW.formModal('Nouvelle demande d\'assistance', [
      { name: 'category', label: 'Motif', type: 'select', required: true, value: pre.category || 'other', options: Object.entries(MW.L.complaint) },
      { name: 'order_id', label: 'N° interne de commande (facultatif)', type: 'number', value: pre.order_id || '' },
      { name: 'subject', label: 'Objet', required: true, maxlength: 150 },
      { name: 'description', label: 'Description détaillée', type: 'textarea', required: true, maxlength: 4000, hint: 'Pour un objet perdu : décrivez l\'objet, la date et le trajet.' }],
    async (v) => { const r = await MW.post('/complaints', v); MW.toast('Demande ' + r.data.reference + ' envoyée.', 'success'); location.hash = '#/assistance/' + r.data.id; }, { ok: 'Envoyer' });
  };

  MW.views.complaint = async function (el, params) {
    const c = await MW.get('/complaints/' + params.id);
    el.innerHTML = `<div class="container"><div class="page-head"><h1>${MW.esc(c.subject)}</h1><a href="#/assistance">← Retour</a></div>
      <div class="card"><p class="muted">${MW.esc(c.reference)} · ${MW.esc(MW.L.complaint[c.category])} · ${MW.datetime(c.created_at)}</p><p>${MW.esc(c.description)}</p>
      ${c.resolution ? `<div class="alert alert-success"><strong>Décision :</strong> ${MW.esc(c.resolution)}</div>` : ''}
      <div class="chat" style="max-height:none">${c.messages.map((m) => `<div class="bubble ${Number(m.mine) ? 'mine' : ''}"><div class="who">${Number(m.mine) ? 'Vous' : 'MARWA'} · ${MW.datetime(m.created_at)}</div>${MW.esc(m.body)}</div>`).join('')}</div>
      <form data-msg class="input-group" style="margin-top:12px"><input class="input" name="body" placeholder="Votre message…" maxlength="2000" required><button class="btn btn-primary">${MW.icon('send')}</button></form></div></div>`;
    const f = el.querySelector('[data-msg]');
    f.addEventListener('submit', async (e) => { e.preventDefault(); try { await MW.post(`/complaints/${c.id}/messages`, MW.values(f)); MW.views.complaint(el, params); } catch (err) { MW.error(err); } });
  };

  // ------------------------------------------------------------------ Messagerie liée à une commande
  MW.chat = function (container, orderId) {
    let since = 0; let stopped = false;
    container.innerHTML = `<div class="chat" data-list><div class="small muted">Les numéros de téléphone et liens sont masqués automatiquement.</div></div>
      <form class="input-group" data-form style="margin-top:8px"><input class="input" name="body" maxlength="500" placeholder="Écrire un message…" autocomplete="off" required><button class="btn btn-primary" aria-label="Envoyer">${MW.icon('send')}</button></form>`;
    const list = container.querySelector('[data-list]');
    const load = async () => {
      try {
        const msgs = await MW.get(`/orders/${orderId}/messages?since=${since}`, { retries: 0 });
        msgs.forEach((m) => { since = Math.max(since, m.id); list.insertAdjacentHTML('beforeend', `<div class="bubble ${m.mine ? 'mine' : ''}"><div class="who">${m.mine ? 'Vous' : MW.esc(m.author)} · ${MW.time(m.created_at)}</div>${MW.esc(m.body)}</div>`); });
        if (msgs.length) list.scrollTop = list.scrollHeight;
      } catch (e) { /* réseau */ }
    };
    const form = container.querySelector('[data-form]');
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const v = MW.values(form); if (!v.body) return;
      try { const r = await MW.post(`/orders/${orderId}/messages`, v); form.reset(); if (r.data.filtered) MW.toast('Votre message a été filtré (numéro ou lien masqué).', 'warn'); load(); } catch (err) { MW.error(err); }
    });
    load();
    const handler = (ev) => { if (!stopped && ev.type === 'message' && Number(ev.order_id) === Number(orderId)) load(); };
    MW.realtime.on(handler);
    return { refresh: load, stop: () => { stopped = true; } };
  };

  // ------------------------------------------------------------------ Bouton d'urgence
  MW.sos = async function (orderId) {
    const ok = await MW.confirm('Déclencher une alerte de sécurité ? L\'équipe MARWA sera immédiatement prévenue avec votre position si vous l\'autorisez. Cette alerte n\'appelle PAS automatiquement la police ni les secours.', { title: 'Alerte de sécurité', ok: 'Alerter MARWA', danger: true });
    if (!ok) return;
    let pos = {};
    try { const p = await MW.locate({ timeout: 6000 }); pos = { lat: p.lat, lng: p.lng }; } catch (e) { /* sans position */ }
    try {
      const r = await MW.post('/emergency', Object.assign({ order_id: orderId || null }, pos));
      const nums = (r.data.numbers || []).map((n) => `<a class="btn btn-danger btn-block" href="tel:${MW.esc(n.number)}">${MW.icon('phone')} ${MW.esc(n.label)} — ${MW.esc(n.number)}</a>`).join('');
      MW.modal('Alerte envoyée', `<div class="alert alert-success">L'équipe MARWA a été alertée.</div><p>${MW.esc(r.data.message)}</p><div class="stack">${nums}</div>`);
    } catch (e) { MW.error(e); }
  };

  // ------------------------------------------------------------------ Compte à rebours
  MW.countdown = function (el, until, onEnd) {
    const end = new Date(String(until).replace(' ', 'T')).getTime();
    const tick = () => {
      const s = Math.max(0, Math.round((end - Date.now()) / 1000));
      el.textContent = `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;
      if (s <= 0) { clearInterval(t); onEnd && onEnd(); }
    };
    const t = setInterval(tick, 1000); tick();
    return () => clearInterval(t);
  };
})();
