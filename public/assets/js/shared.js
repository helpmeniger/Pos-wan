/* =====================================================================
   MARWA — vues partagées entre les espaces : profil, assistance,
   messagerie de commande, bouton d'urgence, évaluation
   ===================================================================== */
(function () {
  'use strict';
  const MW = window.MW;
  MW.views = MW.views || {};


  // ------------------------------------------------------------------ Photos (catalogue, logos)
  /**
   * Réduit une photo sur le téléphone avant l'envoi (max. 1600 px, JPEG ~82 %) :
   * envoi plus rapide et moins de données mobiles consommées. Le serveur revérifie et ré-encode.
   */
  MW.compressImage = async function (file, maxPx = 1600, quality = 0.82) {
    if (!file || !/^image\/(jpeg|png|webp)$/.test(file.type)) return file;
    try {
      const bmp = await (window.createImageBitmap ? createImageBitmap(file) : new Promise((res, rej) => { const i = new Image(); i.onload = () => res(i); i.onerror = rej; i.src = URL.createObjectURL(file); }));
      const ratio = Math.min(1, maxPx / Math.max(bmp.width, bmp.height));
      if (ratio === 1 && file.size < 400 * 1024) return file;
      const c = document.createElement('canvas'); c.width = Math.round(bmp.width * ratio); c.height = Math.round(bmp.height * ratio);
      const ctx = c.getContext('2d'); ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, c.width, c.height); ctx.drawImage(bmp, 0, 0, c.width, c.height);
      const blob = await new Promise((res) => c.toBlob(res, 'image/jpeg', quality));
      return blob && blob.size < file.size ? new File([blob], (file.name || 'photo').replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg' }) : file;
    } catch (e) { return file; }
  };
  MW.uploadImage = async function (path, file) {
    const fd = new FormData(); fd.append('file', await MW.compressImage(file));
    return MW.api('POST', path, fd);
  };
  /** Boutons « Appareil photo » et « Galerie » ; onFiles(FileList) */
  MW.photoButtons = (multiple = true) => `<div class="photo-buttons">
      <label class="btn btn-outline btn-sm">${MW.icon('plus')} Prendre une photo<input type="file" accept="image/*" capture="environment" hidden data-camera></label>
      <label class="btn btn-outline btn-sm">Choisir dans la galerie<input type="file" accept="image/jpeg,image/png,image/webp" ${multiple ? 'multiple' : ''} hidden data-gallery></label></div>`;
  const bindPhotoInputs = (root, onFiles) => root.querySelectorAll('[data-camera],[data-gallery]').forEach((i) => i.addEventListener('change', () => { if (i.files.length) onFiles([...i.files]); i.value = ''; }));

  /**
   * Gestion des photos d'un produit : ajout (appareil photo ou galerie), aperçu,
   * choix de la photo principale, suppression. Si le produit n'existe pas encore,
   * les photos sont gardées en attente et envoyées après l'enregistrement (flush).
   */
  MW.PhotoManager = class {
    constructor(container, { productId = null, photos = [], max = 6 }) {
      this.el = container; this.productId = productId; this.photos = photos; this.max = max; this.pending = [];
      this.render();
    }
    render() {
      const items = this.photos.map((p, i) => `<div class="photo-tile"><img src="${MW.esc(p.url)}" alt="" loading="lazy">${i === 0 ? '<span class="photo-main">Principale</span>' : ''}
          <div class="photo-actions">${i ? `<button type="button" class="btn btn-sm btn-ghost" data-main="${p.id}" title="Mettre en principale">★</button>` : ''}<button type="button" class="btn btn-sm btn-ghost" data-del="${p.id}" aria-label="Supprimer la photo">${MW.icon('x')}</button></div></div>`)
        .concat(this.pending.map((f, i) => `<div class="photo-tile pending"><img src="${f.preview}" alt=""><span class="photo-main">${this.productId ? 'Envoi…' : 'À envoyer'}</span>
          <div class="photo-actions"><button type="button" class="btn btn-sm btn-ghost" data-unpend="${i}" aria-label="Retirer">${MW.icon('x')}</button></div></div>`));
      const count = this.photos.length + this.pending.length;
      this.el.innerHTML = `<div class="field"><span class="label">Photos du produit <span class="muted small">(${count}/${this.max})</span></span>
        <div class="photo-grid">${items.join('') || '<p class="small muted" style="margin:0">Aucune photo. Les produits avec photo se vendent mieux.</p>'}</div>
        ${count < this.max ? MW.photoButtons(true) : '<p class="small muted">Nombre maximal de photos atteint.</p>'}
        <div class="hint">JPEG, PNG ou WebP. Les photos sont réduites automatiquement avant l'envoi.</div></div>`;
      bindPhotoInputs(this.el, (files) => this.add(files));
      this.el.querySelectorAll('[data-del]').forEach((b) => b.addEventListener('click', () => MW.busy(b, async () => { this.photos = (await MW.del(`/merchant/products/${this.productId}/photos/${b.dataset.del}`)).data.photos; this.render(); this.changed = true; })));
      this.el.querySelectorAll('[data-main]').forEach((b) => b.addEventListener('click', () => MW.busy(b, async () => { this.photos = (await MW.post(`/merchant/products/${this.productId}/photos/${b.dataset.main}/main`)).data.photos; this.render(); this.changed = true; })));
      this.el.querySelectorAll('[data-unpend]').forEach((b) => b.addEventListener('click', () => { this.pending.splice(Number(b.dataset.unpend), 1); this.render(); }));
    }
    async add(files) {
      const room = this.max - this.photos.length - this.pending.length;
      files.slice(0, room).forEach((f) => this.pending.push(Object.assign(f, { preview: URL.createObjectURL(f) })));
      if (files.length > room) MW.toast(`Maximum ${this.max} photos par produit.`, 'warn');
      this.render();
      if (this.productId) await this.flush(this.productId);
    }
    /** Envoie les photos en attente (après création du produit). */
    async flush(productId) {
      this.productId = productId;
      while (this.pending.length) {
        const f = this.pending[0];
        try { this.photos = (await MW.uploadImage(`/merchant/products/${productId}/photos`, f)).data.photos; this.changed = true; } catch (e) { MW.error(e); this.pending.shift(); this.render(); return false; }
        this.pending.shift(); this.render();
      }
      return true;
    }
  };

  /** Une seule image (logo, couverture, photo de catégorie) : aperçu + appareil photo / galerie. */
  MW.singleImage = function (container, { label, url, path, onDone, shape = 'square' }) {
    const render = (u) => {
      container.innerHTML = `<div class="field"><span class="label">${MW.esc(label)}</span><div class="single-image ${shape}">
        ${u ? `<img src="${MW.esc(u)}" alt="">` : `<span class="muted small">Aucune image</span>`}</div>${MW.photoButtons(false)}</div>`;
      bindPhotoInputs(container, async (files) => {
        container.querySelector('.single-image').classList.add('loading');
        try { const r = await MW.uploadImage(path, files[0]); const nu = Object.values(r.data).find((x) => typeof x === 'string'); render(nu); MW.toast('Image enregistrée.', 'success'); onDone && onDone(nu); }
        catch (e) { MW.error(e); render(u); }
      });
    };
    render(url);
  };

  /** Galerie de photos défilable (fiche produit côté client). */
  MW.gallery = (photos, alt = '') => photos.length ? `<div class="gallery" tabindex="0">${photos.map((u) => `<img src="${MW.esc(u)}" alt="${MW.esc(alt)}" loading="lazy">`).join('')}</div>
    ${photos.length > 1 ? `<div class="gallery-dots">${photos.map((_, i) => `<span class="${i ? '' : 'on'}"></span>`).join('')}</div>` : ''}` : '';
  MW.bindGallery = (root) => root.querySelectorAll('.gallery').forEach((g) => {
    const dots = g.nextElementSibling && g.nextElementSibling.classList.contains('gallery-dots') ? [...g.nextElementSibling.children] : [];
    g.addEventListener('scroll', () => { const i = Math.round(g.scrollLeft / g.clientWidth); dots.forEach((d, k) => d.classList.toggle('on', k === i)); }, { passive: true });
  });

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
          <h3>Double authentification</h3>
          <div data-twofa class="small muted">…</div>
          <hr class="divider">
          <h3>Appareils connectés</h3>
          <p class="small muted">En cas de perte du téléphone ou de doute, déconnectez tous les autres appareils (sessions, applications et notifications).</p>
          <button class="btn btn-outline btn-sm" data-everywhere>Déconnecter tous les autres appareils</button>
          <hr class="divider">
          <h3>Notifications sur cet appareil</h3>
          <p class="small muted">Alertes reçues même lorsque MARWA est fermé ou le téléphone en veille (nouvelle demande, chauffeur arrivé, commande prête…).</p>
          <div data-pushbox class="small muted">…</div>
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
    el.querySelector('[data-everywhere]').addEventListener('click', async (e) => {
      if (!(await MW.confirm('Déconnecter tous les autres appareils ? Vous restez connecté sur celui-ci.', { ok: 'Déconnecter' }))) return;
      MW.busy(e.target, async () => { await MW.post('/account/logout-everywhere'); MW.toast('Tous les autres appareils ont été déconnectés.', 'success'); });
    });
    const twofa = el.querySelector('[data-twofa]');
    const loadQr = () => new Promise((res) => { if (window.qrcode) return res(); const s = document.createElement('script'); s.src = MW.publicBase + '/assets/vendor/qrcode/qrcode.js'; s.onload = res; s.onerror = res; document.head.appendChild(s); });
    const renderTwofa = async () => {
      const sec = await MW.get('/account/security');
      twofa.innerHTML = sec.two_factor
        ? `<p><span class="badge green">Activée</span> Un code est demandé à chaque connexion. Codes de secours restants : <strong>${sec.recovery_codes_left}</strong>.</p>${sec.two_factor_required ? '<p class="small">Obligatoire pour les comptes de l\'équipe MARWA.</p>' : '<button class="btn btn-ghost btn-sm" data-off>Désactiver</button>'}`
        : `<p>${sec.two_factor_required ? '<span class="badge red">Obligatoire pour votre compte</span> ' : ''}Protégez votre compte : en plus du mot de passe, un code à 6 chiffres de votre téléphone (Google Authenticator, Microsoft Authenticator, Aegis…) sera demandé.</p><button class="btn btn-primary btn-sm" data-on>Activer la double authentification</button>`;
      twofa.querySelector('[data-on]')?.addEventListener('click', (e) => MW.busy(e.currentTarget, async () => {
        const r = (await MW.post('/account/2fa/begin')).data;
        await loadQr();
        let qr = '';
        try { const q = window.qrcode(0, 'M'); q.addData(r.uri); q.make(); qr = q.createSvgTag({ cellSize: 4, margin: 2, scalable: true }); } catch (x) { qr = ''; }
        MW.formModal('Activer la double authentification', [
          { html: `<ol class="small" style="padding-left:18px"><li>Installez une application d'authentification sur votre téléphone.</li><li>Scannez ce code QR (ou saisissez la clé).</li><li>Entrez le code à 6 chiffres affiché.</li></ol>
            <div class="qr-box">${qr || ''}</div><p class="small">Clé : <code translate="no" style="word-break:break-all">${MW.esc(r.secret)}</code></p>
            <p><a class="btn btn-sm btn-outline" href="${MW.esc(r.uri)}">Ouvrir dans l'application d'authentification</a></p>` },
          { name: 'code', label: 'Code à 6 chiffres', required: true, maxlength: 6 }],
        async (v) => {
          const c = (await MW.post('/account/2fa/confirm', v)).data.recovery_codes;
          MW.modal('Codes de secours', `<div class="alert alert-warn">Notez ces codes et gardez-les en lieu sûr. Chacun permet une connexion si vous perdez votre téléphone. Ils ne seront plus affichés.</div>
            <div class="recovery-codes" translate="no">${c.map((x) => `<code>${MW.esc(x)}</code>`).join('')}</div>`);
          renderTwofa();
        }, { ok: 'Activer' });
      }));
      twofa.querySelector('[data-off]')?.addEventListener('click', () => MW.formModal('Désactiver la double authentification', [
        { name: 'password', label: 'Mot de passe', type: 'password', required: true }, { name: 'code', label: 'Code de vérification', required: true, maxlength: 9 }],
      async (v) => { await MW.post('/account/2fa/disable', v); MW.toast('Double authentification désactivée.', 'success'); renderTwofa(); }, { ok: 'Désactiver' }));
    };
    renderTwofa();
    const pushBox = el.querySelector('[data-pushbox]');
    const renderPush = async () => {
      const st = await MW.push.state();
      const label = { on: '<span class="badge green">Activées</span>', off: '<span class="badge gray">Désactivées</span>', denied: '<span class="badge red">Bloquées par le navigateur</span>',
        unsupported: '<span class="badge gray">Non prises en charge par ce navigateur</span>', 'ios-install': '<span class="badge amber">Installez d\'abord MARWA sur l\'écran d\'accueil</span>' }[st];
      pushBox.innerHTML = `<div style="margin-bottom:8px">${label}</div><div class="row">
        ${st === 'off' ? '<button class="btn btn-primary btn-sm" data-pon>Activer</button>' : ''}
        ${st === 'on' ? '<button class="btn btn-outline btn-sm" data-ptest>Envoyer un essai</button><button class="btn btn-ghost btn-sm" data-poff>Désactiver</button>' : ''}</div>
        ${st === 'denied' ? '<p class="small">Pour les débloquer : cadenas à gauche de l\'adresse → Autorisations → Notifications → Autoriser.</p>' : ''}
        ${st === 'ios-install' ? '<p class="small">Sur iPhone (iOS 16.4 ou plus) : bouton Partager → « Sur l\'écran d\'accueil », puis ouvrez MARWA depuis cette icône.</p>' : ''}`;
      pushBox.querySelector('[data-pon]')?.addEventListener('click', (e) => MW.busy(e.currentTarget, async () => { await MW.push.enable(); MW.toast('Notifications activées sur cet appareil.', 'success'); renderPush(); }));
      pushBox.querySelector('[data-poff]')?.addEventListener('click', (e) => MW.busy(e.currentTarget, async () => { await MW.push.disable(); renderPush(); }));
      pushBox.querySelector('[data-ptest]')?.addEventListener('click', (e) => MW.busy(e.currentTarget, async () => { const r = await MW.post('/push/test'); MW.toast(`Notification d'essai envoyée (${r.data.devices} appareil(s)).`, 'success'); }));
    };
    renderPush();
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
