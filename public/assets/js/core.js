/* =====================================================================
   MARWA — noyau JavaScript commun (aucune dépendance)
   - client API (CSRF, reprises sur GET, cache hors ligne, erreurs lisibles)
   - composants UI (toasts, modales, confirmations, tableaux, formulaires)
   - routeur par ancre (#/...), flux temps réel, notifications
   ===================================================================== */
(function () {
  'use strict';

  const meta = (n) => (document.querySelector(`meta[name="${n}"]`) || {}).content || '';
  const MW = (window.MW = window.MW || {});
  MW.base = meta('mw-base').replace(/\/$/, '');
  MW.publicBase = meta('mw-public').replace(/\/$/, '');
  /** Chemin de la page courante relatif à l'application (ex. « /client »). */
  MW.here = () => {
    const p = location.pathname;
    return MW.base && p.startsWith(MW.base) ? (p.slice(MW.base.length) || '/') : p;
  };
  MW.csrf = meta('csrf-token');
  MW.user = null;
  try { MW.user = JSON.parse(document.getElementById('mw-user')?.textContent || 'null'); } catch (e) { MW.user = null; }

  // ------------------------------------------------------------------ Libellés
  MW.L = {
    status: {
      created: 'Demande créée', searching: 'Recherche d\'un prestataire', negotiating: 'Négociation du prix', pending: 'En attente du commerce',
      accepted: 'Acceptée', refused: 'Refusée', preparing: 'En préparation', ready: 'Prête', assigned: 'Prestataire affecté',
      en_route_pickup: 'En route vers le départ', arrived_pickup: 'Arrivé au point de départ', in_progress: 'Course en cours',
      picked_up: 'Récupérée', in_delivery: 'En livraison', delivered: 'Livrée', delivery_failed: 'Livraison échouée', returning: 'Retour en cours',
      returned: 'Retournée', completed: 'Course terminée', paid: 'Paiement confirmé', cancelled: 'Annulée', expired: 'Expirée',
      disputed: 'Contestée', refund_pending: 'Remboursement en cours', refunded: 'Remboursée'
    },
    service: { taxi: 'Taxi', pro: 'Transport pro', food: 'Repas', shop: 'Commerce', parcel: 'Colis', document: 'Documents', errand: 'Courses', urgent: 'Urgent' },
    vehicle: { taxi: 'Taxi', sedan: 'Berline confort', family: 'Véhicule familial', van: 'Utilitaire / minibus', moto_taxi: 'Moto-taxi', moto: 'Moto', bicycle: 'Vélo', car: 'Voiture', other: 'Autre' },
    payment: { cash: 'Espèces', demo_mobile_money: 'Mobile Money (démo)', mobile_money: 'Mobile Money', card: 'Carte bancaire', business_account: 'Compte entreprise' },
    payStatus: { unpaid: 'Non payé', pending: 'Paiement en attente', paid: 'Payé', partially_refunded: 'Remb. partiel', refunded: 'Remboursé', failed: 'Échec' },
    verif: { pending: 'En attente', verified: 'Validé', suspended: 'Suspendu', refused: 'Refusé' },
    doc: { id_card: 'Pièce d\'identité', driving_license: 'Permis de conduire', vehicle_registration: 'Carte grise', insurance: 'Assurance', technical_inspection: 'Visite technique', photo: 'Photo', other: 'Autre' },
    package: { petit_colis: 'Petit colis', colis_moyen: 'Colis moyen', gros_colis: 'Gros colis', documents: 'Documents', courses: 'Courses', repas: 'Repas', autre: 'Autre' },
    complaint: { ride: 'Course', order: 'Commande', payment: 'Paiement', lost_item: 'Objet perdu', safety: 'Sécurité', account: 'Compte', report_user: 'Signalement', other: 'Autre' },
    role: { client: 'Client', driver: 'Chauffeur', courier: 'Livreur', merchant: 'Commerce', business: 'Entreprise', admin: 'Administrateur', support: 'Assistance', finance: 'Finance' }
  };
  MW.statusColor = (s) => ({ paid: 'green', delivered: 'green', completed: 'green', cancelled: 'gray', expired: 'gray', refused: 'red', disputed: 'red', delivery_failed: 'red', refund_pending: 'amber', refunded: 'gray', negotiating: 'amber', searching: 'amber', pending: 'amber' }[s] || 'blue');
  MW.statusBadge = (s) => `<span class="badge ${MW.statusColor(s)}">${MW.esc(MW.L.status[s] || s)}</span>`;

  // ------------------------------------------------------------------ Icônes (SVG en ligne)
  const P = {
    home: 'M3 11l9-8 9 8v9a1 1 0 01-1 1h-5v-6H9v6H4a1 1 0 01-1-1z',
    car: 'M5 11l1.5-4.5A2 2 0 018.4 5h7.2a2 2 0 011.9 1.5L19 11m-14 0h14m-14 0a2 2 0 00-2 2v3h2m14-5a2 2 0 012 2v3h-2M5 16v2m14-2v2M7.5 14h.01M16.5 14h.01',
    moto: 'M5 17a3 3 0 100-6 3 3 0 000 6zm14 0a3 3 0 100-6 3 3 0 000 6zM8 14h5l3-5h-3m3 0l3 5M10 9h3',
    food: 'M4 11h16a8 8 0 01-16 0zm8-7v3m-4-2l1 2m7-2l-1 2M3 20h18',
    box: 'M21 8l-9-5-9 5 9 5 9-5zm0 0v8l-9 5m0-8v8m-9-13v8l9 5',
    doc: 'M7 3h7l5 5v13H7zM14 3v5h5M10 13h6m-6 4h6',
    bag: 'M6 8h12l-1 13H7zm3 0V6a3 3 0 016 0v2',
    bolt: 'M13 2L4 14h7l-1 8 9-12h-7z',
    briefcase: 'M4 8h16v11H4zm5 0V5h6v3M4 13h16',
    list: 'M8 6h12M8 12h12M8 18h12M4 6h.01M4 12h.01M4 18h.01',
    pin: 'M12 21s-7-6.3-7-11a7 7 0 0114 0c0 4.7-7 11-7 11zm0-8a3 3 0 100-6 3 3 0 000 6z',
    user: 'M12 12a4 4 0 100-8 4 4 0 000 8zm-7 9a7 7 0 0114 0',
    help: 'M12 21a9 9 0 100-18 9 9 0 000 18zm-2.5-11.5a2.5 2.5 0 115 0c0 1.7-2.5 2-2.5 4m0 3h.01',
    bell: 'M18 16v-5a6 6 0 10-12 0v5l-2 2h16zM10 20a2 2 0 004 0',
    wallet: 'M3 7h16a2 2 0 012 2v9a2 2 0 01-2 2H3zm0 0V5a2 2 0 012-2h11v4m1 7h.01',
    file: 'M6 3h9l4 4v14H6zm9 0v4h4',
    chart: 'M4 20V10m6 10V4m6 16v-7m4 7H2',
    map: 'M9 4l-6 2v14l6-2 6 2 6-2V4l-6 2zm0 0v14m6-12v14',
    users: 'M9 11a4 4 0 100-8 4 4 0 000 8zm-6 9a6 6 0 0112 0m3-9a3 3 0 100-6m3 15a5 5 0 00-4-5',
    store: 'M4 10v10h16V10M3 6l2-3h14l2 3v2a3 3 0 01-6 0 3 3 0 01-6 0 3 3 0 01-6 0z',
    tag: 'M3 12V4h8l10 10-8 8zM7.5 7.5h.01',
    percent: 'M19 5L5 19M7 9a2 2 0 100-4 2 2 0 000 4zm10 10a2 2 0 100-4 2 2 0 000 4z',
    cash: 'M3 6h18v12H3zm9 9a3 3 0 100-6 3 3 0 000 6zM6 9h.01M18 15h.01',
    book: 'M4 4h11a3 3 0 013 3v13H7a3 3 0 01-3-3zm0 13a3 3 0 013-3h11',
    alert: 'M12 9v4m0 4h.01M10.3 3.9L2 18a2 2 0 001.7 3h16.6a2 2 0 001.7-3L13.7 3.9a2 2 0 00-3.4 0z',
    gear: 'M12 15a3 3 0 100-6 3 3 0 000 6zm7.4-3a7.4 7.4 0 00-.1-1.3l2-1.6-2-3.4-2.4 1a7.5 7.5 0 00-2.2-1.3L14.4 3h-4l-.4 2.5a7.5 7.5 0 00-2.2 1.3l-2.4-1-2 3.4 2 1.6a7.4 7.4 0 000 2.6l-2 1.6 2 3.4 2.4-1a7.5 7.5 0 002.2 1.3l.4 2.5h4l.4-2.5a7.5 7.5 0 002.2-1.3l2.4 1 2-3.4-2-1.6c.1-.4.1-.9.1-1.3z',
    shield: 'M12 3l8 3v6c0 5-3.4 8.4-8 9-4.6-.6-8-4-8-9V6z',
    clock: 'M12 21a9 9 0 100-18 9 9 0 000 18zm0-13v4l3 2',
    locate: 'M12 8a4 4 0 100 8 4 4 0 000-8zm0-6v3m0 14v3m10-10h-3M5 12H2',
    star: 'M12 3l2.7 5.6 6.3.9-4.5 4.4 1 6.1-5.5-2.9-5.5 2.9 1-6.1L3 9.5l6.3-.9z',
    send: 'M4 12l16-8-6 16-2-7z',
    x: 'M6 6l12 12M18 6L6 18',
    plus: 'M12 5v14M5 12h14',
    more: 'M5 12h.01M12 12h.01M19 12h.01',
    logout: 'M15 4h4v16h-4M10 8l-4 4 4 4m-4-4h12',
    phone: 'M5 4h4l2 5-2.5 1.5a11 11 0 005 5L15 13l5 2v4a2 2 0 01-2 2A16 16 0 013 6a2 2 0 012-2z',
    nav: 'M3 11l18-8-8 18-2-8z',
    sos: 'M12 3a9 9 0 100 18 9 9 0 000-18zm0 5v5m0 3h.01'
  };
  MW.icon = (name, cls = '') => `<svg class="${cls}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="${P[name] || P.more}"/></svg>`;

  // ------------------------------------------------------------------ Formatage
  MW.esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', '\'': '&#39;' }[c]));
  MW.fcfa = (n) => (n === null || n === undefined || n === '') ? '—' : Math.round(Number(n)).toLocaleString('fr-FR').replace(/ | /g, ' ') + ' FCFA';
  MW.num = (n) => Math.round(Number(n || 0)).toLocaleString('fr-FR');
  const pad = (n) => String(n).padStart(2, '0');
  const parse = (s) => s instanceof Date ? s : new Date(String(s).replace(' ', 'T'));
  MW.date = (s) => { if (!s) return '—'; const d = parse(s); return isNaN(d) ? MW.esc(s) : `${pad(d.getDate())}/${pad(d.getMonth() + 1)}/${d.getFullYear()}`; };
  MW.datetime = (s) => { if (!s) return '—'; const d = parse(s); return isNaN(d) ? MW.esc(s) : `${MW.date(d)} ${pad(d.getHours())}:${pad(d.getMinutes())}`; };
  MW.time = (s) => { if (!s) return '—'; const d = parse(s); return `${pad(d.getHours())}:${pad(d.getMinutes())}`; };
  MW.dist = (m) => m === null || m === undefined ? '—' : (m < 1000 ? `${Math.round(m)} m` : `${(m / 1000).toFixed(1).replace('.', ',')} km`);
  MW.dur = (s) => { if (s === null || s === undefined) return '—'; const m = Math.max(1, Math.round(s / 60)); return m < 60 ? `${m} min` : `${Math.floor(m / 60)} h ${pad(m % 60)}`; };
  MW.ago = (sec) => sec < 60 ? `il y a ${sec} s` : sec < 3600 ? `il y a ${Math.round(sec / 60)} min` : `il y a ${Math.round(sec / 3600)} h`;
  MW.isoDate = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
  MW.uid = () => (crypto.randomUUID ? crypto.randomUUID() : 'k' + Date.now() + Math.random().toString(16).slice(2)).replace(/[^A-Za-z0-9-]/g, '');

  // ------------------------------------------------------------------ Client API
  class ApiError extends Error {
    constructor(status, payload) {
      const e = (payload && payload.error) || {};
      super(e.message || (status === 0 ? 'Connexion impossible. Vérifiez votre réseau.' : 'Erreur inattendue.'));
      this.status = status; this.code = e.code || 'error'; this.fields = e.fields || {};
    }
  }
  MW.ApiError = ApiError;

  const cacheKey = (p) => 'mw:cache:' + (MW.user ? MW.user.id : 0) + ':' + p;
  MW.api = async function (method, path, body, opts = {}) {
    const url = (path.startsWith('http') ? '' : MW.base + '/api') + path;
    const headers = { Accept: 'application/json' };
    let payload;
    if (body instanceof FormData) payload = body;
    else if (body !== undefined && body !== null) { headers['Content-Type'] = 'application/json'; payload = JSON.stringify(body); }
    if (method !== 'GET') headers['X-CSRF-Token'] = MW.csrf;
    Object.assign(headers, opts.headers || {});
    const attempts = method === 'GET' ? (opts.retries ?? 2) + 1 : 1;
    for (let i = 0; i < attempts; i++) {
      let res;
      try {
        res = await fetch(url, { method, headers, body: payload, credentials: 'same-origin', cache: 'no-store' });
      } catch (netErr) {
        MW.net.down();
        if (i < attempts - 1) { await new Promise((r) => setTimeout(r, 800 * (i + 1) * (i + 1))); continue; }
        if (method === 'GET' && opts.cache) {
          const c = localStorage.getItem(cacheKey(path));
          if (c) { const d = JSON.parse(c); d.__stale = true; return d; }
        }
        throw new ApiError(0, null);
      }
      MW.net.up();
      if (res.status === 419 && !opts._csrfRetried) {
        const t = await fetch(MW.base + '/api/auth/csrf', { credentials: 'same-origin' }).then((r) => r.json());
        MW.csrf = t.data.csrf;
        return MW.api(method, path, body, Object.assign({}, opts, { _csrfRetried: true }));
      }
      if (res.status === 401 && !opts.silent401) {
        location.href = MW.base + '/connexion?retour=' + encodeURIComponent(MW.here());
        throw new ApiError(401, { error: { message: 'Session expirée.' } });
      }
      const ct = res.headers.get('Content-Type') || '';
      const data = ct.includes('application/json') ? await res.json() : await res.text();
      if (!res.ok) {
        if (res.status >= 500 && method === 'GET' && i < attempts - 1) { await new Promise((r) => setTimeout(r, 1000)); continue; }
        throw new ApiError(res.status, data);
      }
      if (method === 'GET' && opts.cache) { try { localStorage.setItem(cacheKey(path), JSON.stringify(data)); } catch (e) { /* quota */ } }
      return data;
    }
  };
  MW.get = (p, o) => MW.api('GET', p, null, o).then((r) => r && r.data !== undefined ? r.data : r);
  MW.post = (p, b, o) => MW.api('POST', p, b || {}, o);
  MW.put = (p, b) => MW.api('PUT', p, b || {});
  MW.del = (p) => MW.api('DELETE', p);

  // ------------------------------------------------------------------ État du réseau
  MW.net = {
    isDown: false,
    el: null,
    banner(text, warn) {
      if (!this.el) { this.el = document.createElement('div'); this.el.className = 'net-banner'; this.el.setAttribute('role', 'status'); document.body.appendChild(this.el); }
      this.el.textContent = text; this.el.classList.toggle('warn', !!warn); this.el.classList.add('show');
    },
    down() { if (!this.isDown) { this.isDown = true; this.banner('Connexion perdue — les dernières données connues sont affichées. Reconnexion automatique…', true); } },
    up() { if (this.isDown) { this.isDown = false; this.banner('Connexion rétablie.'); setTimeout(() => this.el && this.el.classList.remove('show'), 2500); document.dispatchEvent(new Event('mw:online')); } }
  };
  window.addEventListener('offline', () => MW.net.down());
  window.addEventListener('online', () => MW.net.up());

  // ------------------------------------------------------------------ UI : toasts, modales
  MW.toast = function (msg, type = 'info', ms = 4200) {
    let box = document.querySelector('.toasts');
    if (!box) { box = document.createElement('div'); box.className = 'toasts'; box.setAttribute('aria-live', 'polite'); document.body.appendChild(box); }
    const t = document.createElement('div'); t.className = 'toast ' + type; t.textContent = msg; box.appendChild(t);
    setTimeout(() => t.remove(), ms);
  };
  MW.error = (e) => MW.toast(e && e.message ? e.message : String(e), 'error', 6000);

  MW.modal = function (title, html, opts = {}) {
    const back = document.createElement('div'); back.className = 'modal-backdrop';
    back.innerHTML = `<div class="modal ${opts.wide ? 'wide' : ''}" role="dialog" aria-modal="true" aria-label="${MW.esc(title)}">
      <div class="modal-head"><h2>${MW.esc(title)}</h2><button class="icon-btn" data-close aria-label="Fermer">${MW.icon('x')}</button></div>
      <div class="modal-body">${html}</div></div>`;
    const close = () => { back.remove(); document.removeEventListener('keydown', esc); opts.onClose && opts.onClose(); };
    const esc = (e) => { if (e.key === 'Escape') close(); };
    back.addEventListener('click', (e) => { if (e.target === back || e.target.closest('[data-close]')) close(); });
    document.addEventListener('keydown', esc);
    document.body.appendChild(back);
    const first = back.querySelector('input, select, textarea, button:not([data-close])'); if (first) setTimeout(() => first.focus(), 50);
    return { el: back.querySelector('.modal'), close };
  };

  MW.confirm = (message, opts = {}) => new Promise((resolve) => {
    const m = MW.modal(opts.title || 'Confirmation', `<p>${MW.esc(message)}</p>
      ${opts.reason ? `<div class="field"><label>${MW.esc(opts.reason)}</label><textarea class="input" data-reason maxlength="255" ${opts.reasonRequired ? 'required' : ''}></textarea></div>` : ''}
      <div class="modal-actions"><button class="btn btn-outline" data-no>Annuler</button><button class="btn ${opts.danger ? 'btn-danger' : 'btn-primary'}" data-yes>${MW.esc(opts.ok || 'Confirmer')}</button></div>`,
    { onClose: () => resolve(false) });
    m.el.querySelector('[data-no]').onclick = () => { m.close(); };
    m.el.querySelector('[data-yes]').onclick = () => {
      const r = m.el.querySelector('[data-reason]');
      if (r && opts.reasonRequired && !r.value.trim()) { r.focus(); r.closest('.field').classList.add('has-error'); return; }
      const val = r ? r.value.trim() : true;
      resolve(val === '' ? true : val); m.close();
    };
  });

  /** Formulaire en modale : fields = [{name,label,type,options,required,value,hint}] ; onSubmit(values) → Promise */
  MW.formModal = function (title, fields, onSubmit, opts = {}) {
    const m = MW.modal(title, `<form novalidate>${MW.fields(fields, opts.values || {})}
      <div class="modal-actions"><button type="button" class="btn btn-outline" data-close>Annuler</button><button class="btn btn-primary" type="submit">${MW.esc(opts.ok || 'Enregistrer')}</button></div></form>`, { wide: opts.wide });
    const form = m.el.querySelector('form');
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const btn = form.querySelector('[type=submit]');
      MW.clearErrors(form); btn.classList.add('loading'); btn.disabled = true;
      try { await onSubmit(MW.values(form), form); m.close(); } catch (err) { MW.showErrors(form, err); } finally { btn.classList.remove('loading'); btn.disabled = false; }
    });
    opts.onOpen && opts.onOpen(form, m);
    return m;
  };

  MW.fields = function (fields, values = {}) {
    return fields.map((f) => {
      if (f.html) return f.html;
      const v = values[f.name] ?? f.value ?? '';
      const id = 'f_' + f.name + '_' + Math.random().toString(36).slice(2, 7);
      const req = f.required ? 'required' : '';
      const attrs = `${f.min !== undefined ? `min="${f.min}"` : ''} ${f.max !== undefined ? `max="${f.max}"` : ''} ${f.step ? `step="${f.step}"` : ''} ${f.maxlength ? `maxlength="${f.maxlength}"` : ''} ${f.placeholder ? `placeholder="${MW.esc(f.placeholder)}"` : ''}`;
      let input;
      if (f.type === 'select') {
        input = `<select class="input" id="${id}" name="${f.name}" ${req}>${(f.options || []).map((o) => { const [ov, ol] = Array.isArray(o) ? o : [o, o]; return `<option value="${MW.esc(ov)}" ${String(ov) === String(v ?? '') ? 'selected' : ''}>${MW.esc(ol)}</option>`; }).join('')}</select>`;
      } else if (f.type === 'textarea') {
        input = `<textarea class="input" id="${id}" name="${f.name}" ${req} ${attrs}>${MW.esc(v)}</textarea>`;
      } else if (f.type === 'checkbox') {
        return `<div class="field"><label class="check"><input type="checkbox" name="${f.name}" ${v === true || v === 1 || v === '1' ? 'checked' : ''}> <span>${MW.esc(f.label)}</span></label>${f.hint ? `<div class="hint">${MW.esc(f.hint)}</div>` : ''}</div>`;
      } else {
        const type = f.type || 'text';
        const val = type === 'datetime-local' && v ? String(v).replace(' ', 'T').slice(0, 16) : v;
        input = `<input class="input" id="${id}" type="${type}" name="${f.name}" value="${MW.esc(val)}" ${req} ${attrs} ${type === 'number' ? 'inputmode="numeric"' : ''}>`;
      }
      return `<div class="field" data-field="${f.name}"><label for="${id}">${MW.esc(f.label)}${f.required ? ' *' : ''}</label>${input}${f.hint ? `<div class="hint">${MW.esc(f.hint)}</div>` : ''}</div>`;
    }).join('');
  };

  MW.values = function (form) {
    const out = {};
    form.querySelectorAll('[name]').forEach((el) => {
      if (el.type === 'checkbox') out[el.name] = el.checked;
      else if (el.type === 'radio') { if (el.checked) out[el.name] = el.value; }
      else if (el.type === 'number') out[el.name] = el.value === '' ? null : Number(el.value);
      else if (el.type === 'datetime-local') out[el.name] = el.value ? el.value.replace('T', ' ') + ':00' : null;
      else if (el.type === 'file') { /* géré séparément */ }
      else out[el.name] = el.value === '' ? null : el.value;
    });
    return out;
  };

  MW.clearErrors = (form) => { form.querySelectorAll('.has-error').forEach((f) => f.classList.remove('has-error')); form.querySelectorAll('.error-text').forEach((e) => e.remove()); form.querySelector('.alert-error')?.remove(); };
  MW.showErrors = function (form, err) {
    MW.clearErrors(form);
    let shown = false;
    Object.entries((err && err.fields) || {}).forEach(([name, msg]) => {
      const field = form.querySelector(`[data-field="${name}"]`) || form.querySelector(`[name="${name}"]`)?.closest('.field');
      if (field) { field.classList.add('has-error'); field.insertAdjacentHTML('beforeend', `<div class="error-text">${MW.esc(msg)}</div>`); shown = true; }
    });
    form.insertAdjacentHTML('afterbegin', `<div class="alert alert-error" role="alert">${MW.esc(err.message || 'Erreur.')}</div>`);
    if (!shown) form.querySelector('.alert-error').scrollIntoView({ block: 'nearest' });
  };

  /** Bouton avec état de chargement : MW.busy(btn, async () => ...) */
  MW.busy = async function (btn, fn) {
    if (btn.disabled) return;
    btn.disabled = true; btn.classList.add('loading');
    try { return await fn(); } catch (e) { MW.error(e); } finally { btn.disabled = false; btn.classList.remove('loading'); }
  };

  /** Tableau : cols = [{key,label,num,fmt(row)}] */
  MW.table = function (cols, rows, opts = {}) {
    if (!rows || !rows.length) return `<div class="table-wrap"><div class="empty">${MW.icon('list')}${MW.esc(opts.empty || 'Aucune donnée.')}</div></div>`;
    const head = cols.map((c) => `<th class="${c.num ? 'num' : ''}">${MW.esc(c.label)}</th>`).join('');
    const body = rows.map((r, i) => `<tr ${opts.onRow ? `class="clickable" data-row="${i}"` : ''}>${cols.map((c) => `<td class="${c.num ? 'num' : ''}">${c.fmt ? c.fmt(r) : MW.esc(r[c.key])}</td>`).join('')}</tr>`).join('');
    const foot = opts.totals ? `<tfoot><tr>${cols.map((c) => `<td class="${c.num ? 'num' : ''}">${opts.totals[c.key] !== undefined ? (c.money ? MW.fcfa(opts.totals[c.key]) : MW.esc(opts.totals[c.key])) : ''}</td>`).join('')}</tr></tfoot>` : '';
    return `<div class="table-wrap"><table class="table"><thead><tr>${head}</tr></thead><tbody>${body}</tbody>${foot}</table></div>`;
  };
  MW.bindRows = (root, rows, fn) => root.querySelectorAll('tr[data-row]').forEach((tr) => tr.addEventListener('click', () => fn(rows[Number(tr.dataset.row)])));

  MW.loading = (el) => { el.innerHTML = '<div class="card"><div class="skeleton" style="width:40%"></div><div class="skeleton" style="margin-top:12px"></div><div class="skeleton" style="margin-top:8px;width:80%"></div></div>'; };
  MW.empty = (msg, icon = 'list') => `<div class="empty">${MW.icon(icon)}${MW.esc(msg)}</div>`;

  // ------------------------------------------------------------------ Routeur par ancre
  MW.router = function (routes, outlet) {
    const run = async () => {
      const hash = location.hash.replace(/^#/, '') || '/';
      const [path, query] = hash.split('?');
      const params = Object.fromEntries(new URLSearchParams(query || ''));
      for (const [pattern, handler] of Object.entries(routes)) {
        const keys = [];
        const re = new RegExp('^' + pattern.replace(/:([a-z]+)/g, (_, k) => { keys.push(k); return '([^/]+)'; }) + '$');
        const m = path.match(re);
        if (m) {
          keys.forEach((k, i) => (params[k] = decodeURIComponent(m[i + 1])));
          document.querySelectorAll('[data-nav]').forEach((a) => a.classList.toggle('active', a.dataset.nav === pattern.split('/')[1] || (pattern === '/' && a.dataset.nav === '')));
          MW.cleanup && MW.cleanup(); MW.cleanup = null;
          outlet.scrollTop = 0; window.scrollTo(0, 0);
          // Chaque affichage reçoit son propre conteneur : un écran encore en chargement
          // (réseau lent) ne peut plus écraser l'écran demandé ensuite.
          const view = document.createElement('div'); view.className = 'view-page';
          outlet.replaceChildren(view);
          try { await handler(view, params); } catch (e) { if (view.isConnected) view.innerHTML = `<div class="alert alert-error">${MW.esc(e.message || e)}</div>`; }
          return;
        }
      }
      outlet.replaceChildren(); outlet.innerHTML = '<div class="card">Page introuvable. <a href="#/">Retour à l\'accueil</a></div>';
    };
    window.addEventListener('hashchange', run);
    run();
    return { refresh: run };
  };
  /** Enregistre une fonction de nettoyage (intervalles, géolocalisation) au changement de page. */
  MW.onLeave = (fn) => { const prev = MW.cleanup; MW.cleanup = () => { prev && prev(); fn(); }; };

  // ------------------------------------------------------------------ Temps réel (interrogation incrémentale)
  MW.realtime = {
    cursor: 0, timer: null, fast: false, listeners: [],
    on(fn) { this.listeners.push(fn); },
    setFast(v) { this.fast = v; },
    async poll() {
      try {
        const d = await MW.get('/events?since=' + this.cursor, { retries: 0, silent401: true });
        this.cursor = d.cursor || this.cursor;
        (d.events || []).forEach((ev) => {
          this.listeners.forEach((fn) => { try { fn(ev); } catch (e) { console.error(e); } });
          if (ev.type === 'notification') MW.notify(ev.payload);
        });
      } catch (e) { /* réseau : on réessaie au prochain cycle */ }
      const delay = document.hidden ? 30000 : (this.fast ? 4000 : 12000);
      this.timer = setTimeout(() => this.poll(), delay);
    },
    async start() {
      if (!MW.user) return;
      try { const d = await MW.get('/events?since=0', { silent401: true }); this.cursor = d.cursor; } catch (e) { /* hors ligne */ }
      this.timer = setTimeout(() => this.poll(), 3000);
      document.addEventListener('visibilitychange', () => { if (!document.hidden) { clearTimeout(this.timer); this.poll(); } });
    }
  };

  // ------------------------------------------------------------------ Notifications
  MW.notify = function (n) {
    MW.toast(`${n.title} — ${n.body}`, 'info', 6000);
    MW.notifCenter.bump();
    if ('Notification' in window && Notification.permission === 'granted' && document.hidden) {
      try { new Notification(n.title, { body: n.body, icon: MW.publicBase + '/assets/img/icon-192.png', tag: 'mw-' + (n.order_id || n.id) }); } catch (e) { /* mobile */ }
    }
    if (navigator.vibrate && ['new_request', 'order_assigned', 'new_order', 'counter_offer'].includes(n.type)) navigator.vibrate([200, 100, 200]);
  };
  MW.notifCenter = {
    count: 0,
    bump() { this.count++; this.render(); },
    render() { const b = document.querySelector('[data-notif-count]'); if (b) { b.textContent = this.count > 99 ? '99+' : this.count; b.hidden = this.count === 0; } },
    async load() { try { const d = await MW.get('/notifications', { silent401: true }); this.count = d.unread; this.render(); return d; } catch (e) { return null; } },
    async open(anchor) {
      document.querySelector('.dropdown')?.remove();
      const d = await this.load();
      const box = document.createElement('div'); box.className = 'dropdown';
      box.innerHTML = `<div class="row between" style="padding:12px 14px;border-bottom:1px solid var(--sand-200)"><strong>Notifications</strong>
        <span class="row">${'Notification' in window && Notification.permission === 'default' ? '<button class="btn btn-sm btn-outline" data-perm>Activer</button>' : ''}<button class="btn btn-sm btn-ghost" data-readall>Tout lire</button></span></div>
        ${d && d.items.length ? d.items.map((n) => `<div class="list-item ${n.read_at ? '' : 'unread'}"><div class="grow"><div class="title">${MW.esc(n.title)}</div><div class="meta">${MW.esc(n.body)}</div><div class="meta">${MW.datetime(n.created_at)}</div></div></div>`).join('') : MW.empty('Aucune notification.', 'bell')}`;
      document.body.appendChild(box);
      box.querySelector('[data-readall]').onclick = async () => { await MW.post('/notifications/read', {}); this.count = 0; this.render(); box.remove(); };
      const perm = box.querySelector('[data-perm]'); if (perm) perm.onclick = () => Notification.requestPermission().then(() => box.remove());
      setTimeout(() => document.addEventListener('click', function h(e) { if (!box.contains(e.target)) { box.remove(); document.removeEventListener('click', h); } }), 0);
    }
  };

  // ------------------------------------------------------------------ Menu utilisateur
  MW.userMenu = function () {
    const html = `<div class="list">
      ${MW.user.role === 'business' ? `<a class="list-item" href="${MW.base}/client">${MW.icon('car')} Commander</a><a class="list-item" href="${MW.base}/entreprise">${MW.icon('briefcase')} Espace entreprise</a>` : ''}
      ${MW.user.businesses && MW.user.businesses.length && MW.user.role === 'client' ? `<a class="list-item" href="${MW.base}/entreprise">${MW.icon('briefcase')} Espace entreprise</a>` : ''}
      <a class="list-item" href="${MW.base}/pages/securite">${MW.icon('shield')} Règles de sécurité</a>
      <a class="list-item" href="${MW.base}/pages/confidentialite">${MW.icon('file')} Confidentialité</a>
      <button class="list-item btn-ghost" style="width:100%;border:0;background:none;font:inherit;cursor:pointer" data-logout>${MW.icon('logout')} Se déconnecter</button></div>`;
    const m = MW.modal(MW.user.full_name, `<p class="muted">${MW.esc(MW.L.role[MW.user.role] || MW.user.role)} · ${MW.esc(MW.user.phone || MW.user.email || '')}</p>${html}`);
    m.el.querySelector('[data-logout]').onclick = MW.logout;
  };
  MW.logout = async function () {
    try { await MW.post('/auth/logout'); } catch (e) { /* ignore */ }
    Object.keys(localStorage).filter((k) => k.startsWith('mw:')).forEach((k) => localStorage.removeItem(k));
    location.href = MW.base + '/';
  };

  // ------------------------------------------------------------------ Navigation des espaces
  /** items : [{key, label, icon, section?, primary?}] — primary = affiché dans la barre mobile */
  MW.buildNav = function (items) {
    const side = document.getElementById('side-nav');
    const bottom = document.getElementById('bottom-nav');
    let lastSection = null;
    side.innerHTML = items.map((i) => {
      const sec = i.section && i.section !== lastSection ? `<div class="nav-section">${MW.esc(i.section)}</div>` : '';
      lastSection = i.section || lastSection;
      return `${sec}<a href="#/${i.key}" data-nav="${i.key}">${MW.icon(i.icon)}<span>${MW.esc(i.label)}</span></a>`;
    }).join('');
    const primary = items.filter((i) => i.primary).slice(0, 4);
    bottom.innerHTML = primary.map((i) => `<a href="#/${i.key}" data-nav="${i.key}">${MW.icon(i.icon)}<span>${MW.esc(i.short || i.label)}</span></a>`).join('')
      + (items.length > primary.length ? `<a href="#" data-more>${MW.icon('more')}<span>Plus</span></a>` : '');
    bottom.querySelector('[data-more]')?.addEventListener('click', (e) => {
      e.preventDefault();
      const m = MW.modal('Menu', `<div class="nav-more-sheet">${items.filter((i) => !i.primary).map((i) => `<a href="#/${i.key}">${MW.icon(i.icon)} ${MW.esc(i.label)}</a>`).join('')}</div>`);
      m.el.querySelectorAll('a').forEach((a) => a.addEventListener('click', () => m.close()));
    });
  };

  // ------------------------------------------------------------------ Démarrage commun
  document.addEventListener('DOMContentLoaded', () => {
    document.querySelector('[data-notif-btn]')?.addEventListener('click', (e) => { e.stopPropagation(); MW.notifCenter.open(); });
    document.querySelector('[data-user-btn]')?.addEventListener('click', MW.userMenu);
    if (MW.user) { MW.notifCenter.load(); MW.realtime.start(); }
    const sw = meta('mw-sw');
    if (sw && 'serviceWorker' in navigator && location.protocol !== 'file:') {
      navigator.serviceWorker.register(sw, { scope: MW.base + '/' }).catch(() => { /* non bloquant */ });
    }
  });
})();
