/* MARWA GUARDIAN — console (données de l'agent local uniquement, aucun script extérieur). */
(function () {
  'use strict';
  const $ = (s) => document.querySelector(s);
  let csrf = '';
  let current = null;
  let firstTime = false;

  const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const fmtDate = (s) => { if (!s) return '—'; const d = new Date(s); return isNaN(d) || d.getFullYear() < 2000 ? '—' : d.toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' }); };
  const size = (b) => b >= 1073741824 ? (b / 1073741824).toFixed(1).replace('.', ',') + ' Go' : b >= 1048576 ? (b / 1048576).toFixed(1).replace('.', ',') + ' Mo' : Math.max(1, Math.round(b / 1024)) + ' Ko';
  const since = (s) => {
    const h = (Date.now() - new Date(s).getTime()) / 3600000;
    if (h < -0.1) return 'horloge à vérifier';
    if (h < 1) return 'il y a ' + Math.max(1, Math.round(h * 60)) + ' min';
    if (h < 48) return 'il y a ' + Math.round(h) + ' h';
    return 'il y a ' + Math.round(h / 24) + ' jours';
  };
  const SEV = { 1: ['Information', 'text-bg-primary'], 2: ['Avertissement', 'text-bg-warning'], 3: ['Critique', 'text-bg-danger'], 4: ['Urgence', 'text-bg-danger'] };
  const TITLES = { tableau: 'Tableau de bord', incidents: 'Alertes et incidents', sauvegardes: 'Sauvegardes', journal: 'Journal et intégrité', configuration: 'Configuration' };
  const REASONS = { auto: 'Automatique', manuel: 'Manuelle', support_externe: 'Avant copie sur support', avant_migration: 'Avant mise à niveau de la base', avant_reprise_installation: 'Avant reprise' };
  const reason = (r) => REASONS[r] || (r.startsWith('avant_maj_module') ? 'Avant mise à jour d\'un module' : r.startsWith('avant_maj') ? 'Avant mise à jour de POS Marwa' : r.startsWith('avant_') ? 'Avant une opération' : r);

  async function api(path, opts = {}) {
    const o = { method: opts.method || 'GET', headers: { Accept: 'application/json' }, credentials: 'same-origin' };
    if (opts.body !== undefined) { o.headers['Content-Type'] = 'application/json'; o.body = JSON.stringify(opts.body); }
    if (o.method !== 'GET') o.headers['X-Guardian-CSRF'] = csrf;
    const r = await fetch(path, o);
    const d = await r.json().catch(() => ({ ok: false, erreur: 'Réponse illisible de l\'agent.' }));
    if (r.status === 401 && !path.startsWith('/api/connexion')) { showAuth(); throw new Error('connexion requise'); }
    return d;
  }

  // ------------------------------------------------------------------ connexion
  async function boot() {
    const s = await api('/api/session');
    $('#sideVersion').textContent = 'MARWA GUARDIAN ' + (s.version || '');
    if (s.connecte) { csrf = s.csrf; showApp(); return; }
    showAuth(!s.mot_de_passe_defini);
  }
  function showAuth(first) {
    if (first !== undefined) firstTime = first;
    $('#app').classList.add('d-none'); $('#auth').classList.remove('d-none');
    $('#authHelp').textContent = firstTime ? 'Premier accès : choisissez le mot de passe administrateur (8 caractères au moins). Il protège la console de cet ordinateur.' : 'Console réservée à l\'administrateur de cet ordinateur.';
    $('#pw2Box').classList.toggle('d-none', !firstTime);
    $('#authBtn').textContent = firstTime ? 'Enregistrer et entrer' : 'Se connecter';
    $('#pw').autocomplete = firstTime ? 'new-password' : 'current-password';
    $('#pw').focus();
  }
  $('#authForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const err = $('#authErr'); err.classList.add('d-none');
    if (firstTime && $('#pw').value !== $('#pw2').value) { err.textContent = 'Les deux mots de passe sont différents.'; err.classList.remove('d-none'); return; }
    const d = await api(firstTime ? '/api/premier-mot-de-passe' : '/api/connexion', { method: 'POST', body: { mot_de_passe: $('#pw').value } });
    if (!d.ok) { err.textContent = d.erreur; err.classList.remove('d-none'); return; }
    csrf = d.csrf; $('#pw').value = ''; $('#pw2').value = '';
    showApp();
  });
  $('#logout').addEventListener('click', async () => { await api('/api/deconnexion', { method: 'POST' }); csrf = ''; showAuth(false); });
  function showApp() {
    $('#auth').classList.add('d-none'); $('#app').classList.remove('d-none'); route();
    api('/api/session').then((x) => applyMenu(x.menu)).catch(() => {});
  }

  // ------------------------------------------------------------------ menu (à droite par défaut, comme POS Marwa)
  function applyMenu(m) { document.body.classList.toggle('menu-right', m !== 'gauche'); document.body.classList.toggle('menu-left', m === 'gauche'); }
  const closeMenu = () => $('#sidebar').classList.remove('open');
  $('#menuToggle').addEventListener('click', () => $('#sidebar').classList.toggle('open'));
  $('#sideBackdrop').addEventListener('click', closeMenu);
  document.querySelectorAll('.side-link').forEach((a) => a.addEventListener('click', closeMenu));

  // ------------------------------------------------------------------ bilan imprimé (toujours le tableau de bord)
  $('#printBtn').addEventListener('click', async () => {
    if ((location.hash || '#tableau') !== '#tableau') { history.replaceState(null, '', '#tableau'); route(); }
    await dashboard().catch(() => {});
    $('#prHost').textContent = $('#poste').textContent; $('#prDate').textContent = 'Établi le ' + new Date().toLocaleString('fr-FR', { dateStyle: 'full', timeStyle: 'short' });
    window.print();
  });

  // ------------------------------------------------------------------ clé USB branchée : la proposer en un clic
  const IGN = 'guardian-usb-ignorees';
  const ignored = () => { try { return JSON.parse(localStorage.getItem(IGN) || '[]'); } catch (e) { return []; } };
  function usbHint(b, box, after) {
    const vols = (b.volumes || []).filter((v) => v.removable && !v.used && !ignored().includes(v.label));
    box.innerHTML = vols.slice(0, 1).map((v) => `<div class="alert alert-light border usb-hint d-flex flex-wrap align-items-center gap-2">
      <i class="bi bi-usb-drive fs-4 text-warning"></i><div class="flex-grow-1"><b>Clé ou disque « ${esc(v.label)} » branché.</b><br><span class="small">Voulez-vous y garder une copie de vos sauvegardes ? En cas de panne ou de vol du PC, vos ventes seront sauvées.</span></div>
      <button type="button" class="btn btn-sm btn-success" data-usb-use="${esc(v.label)}"><i class="bi bi-check2"></i> Oui, copier dessus</button>
      <button type="button" class="btn btn-sm btn-light" data-usb-ignore="${esc(v.label)}">Pas cette clé</button></div>`).join('');
    box.querySelectorAll('[data-usb-use]').forEach((x) => x.addEventListener('click', async () => {
      x.disabled = true;
      const d = await api('/api/destinations', { method: 'POST', body: { kind: 'support', label: x.dataset.usbUse, keep: 30 } });
      if (!d.ok) { alert(d.erreur); x.disabled = false; return; }
      await api('/api/sauvegardes/copier', { method: 'POST' });
      box.innerHTML = `<div class="alert alert-success small"><i class="bi bi-hourglass-split"></i> « ${esc(x.dataset.usbUse)} » ajoutée : la copie contrôlée est en cours. Laissez la clé branchée quelques minutes.</div>`;
      setTimeout(() => after().catch(() => {}), 4000);
    }));
    box.querySelectorAll('[data-usb-ignore]').forEach((x) => x.addEventListener('click', () => {
      try { localStorage.setItem(IGN, JSON.stringify(ignored().concat(x.dataset.usbIgnore))); } catch (e) { /* mémoire du navigateur indisponible */ }
      box.innerHTML = '';
    }));
  }

  // ------------------------------------------------------------------ « Votre protection » : chaque point vérifié, avec son action
  function protection(d, b) {
    const st = d.systeme || {}; const bk = b.etat || {}; const g = b.reglages || {}; const items = [];
    const H = (t) => (Date.now() - new Date(t).getTime()) / 3600000;
    const add = (state, title, detail, action) => items.push({ state, title, detail, action });
    const lv = bk.last_verified;
    if (lv) add(H(lv.date) <= g.backup_warn_hours ? 'ok' : 'ko', 'Sauvegarde récente et vérifiée', 'Dernière : ' + fmtDate(lv.date), H(lv.date) <= g.backup_warn_hours ? '' : '<a class="btn btn-sm btn-outline-primary" href="#sauvegardes">Voir</a>');
    else add('ko', 'Sauvegarde récente et vérifiée', bk.note || 'Aucune sauvegarde vérifiée : faites une sauvegarde dans POS Marwa (Paramètres → Sauvegardes).', '');
    const r = bk.restore; const days = g.restore_test_days || 30;
    const rOk = r && r.ok && H(r.at) <= days * 24 + 12;
    add(bk.restore_busy ? 'wait' : (rOk ? 'ok' : 'ko'), 'Sauvegarde qui se recharge vraiment', bk.restore_busy ? 'Test en cours…' : (r ? (r.ok ? 'Test réussi le ' : 'Dernier test en échec le ') + fmtDate(r.at) : 'Jamais testée'),
      rOk || bk.restore_busy || !lv ? '' : '<button type="button" class="btn btn-sm btn-primary" data-act="tester"><i class="bi bi-play-circle"></i> Tester maintenant</button>');
    const copies = bk.copies || []; const fresh = copies.filter((c) => c.last_ok && H(c.last_ok) <= 7 * 24);
    add(fresh.length ? 'ok' : 'ko', 'Copie hors de ce PC', fresh.length ? fresh.map((c) => c.name + ' : ' + fmtDate(c.last_ok)).join(' · ') : (copies.length ? 'Aucune copie depuis 7 jours : branchez votre support.' : 'Aucune destination : ajoutez une clé USB ou un disque externe.'),
      fresh.length ? '' : (copies.length ? '<button type="button" class="btn btn-sm btn-outline-primary" data-act="copier">Copier maintenant</button>' : '<a class="btn btn-sm btn-primary" href="#sauvegardes"><i class="bi bi-usb-drive"></i> Ajouter</a>'));
    const sys = (st.disks || []).find((x) => x.system) || (st.disks || [])[0];
    if (sys) { const p = 100 * sys.free / sys.total; add(p >= 15 ? 'ok' : 'ko', 'Espace disque suffisant', size(sys.free) + ' libres (' + Math.round(p) + ' %)', ''); } else add('na', 'Espace disque suffisant', 'Non vérifié pour le moment', '');
    if (st.shutdowns_30d != null) add(st.shutdowns_30d ? 'ko' : 'ok', 'Aucun arrêt anormal du PC (30 jours)', st.shutdowns_30d ? st.shutdowns_30d + ' arrêt(s) anormal(aux) : voir les alertes ; un onduleur protège des coupures de courant.' : 'Aucun', st.shutdowns_30d ? '<a class="btn btn-sm btn-outline-primary" href="#incidents">Voir</a>' : '');
    const svc = st.services || []; const down = svc.filter((x) => x.exists && !x.running);
    add(!svc.length ? 'na' : (down.length ? 'ko' : 'ok'), 'Services de sécurité Windows en marche', !svc.length ? 'Non vérifiés' : (down.length ? 'Arrêté(s) : ' + down.map((x) => x.label || x.name).join(', ') : 'Pare-feu, Defender, journal des événements…'), '');
    add(st.log_intact == null ? 'na' : (st.log_intact ? 'ok' : 'ko'), 'Historique de Guardian intact', st.log_intact == null ? 'Non vérifié' : (st.log_intact ? 'Aucune modification' : 'Modifié en dehors de Guardian'), st.log_intact === false ? '<a class="btn btn-sm btn-outline-danger" href="#journal">Voir</a>' : '');
    const checked = items.filter((i) => i.state === 'ok' || i.state === 'ko'); const ok = checked.filter((i) => i.state === 'ok').length;
    const ICON = { ok: 'bi-check-circle-fill text-success', ko: 'bi-exclamation-circle-fill text-warning', wait: 'bi-hourglass-split text-primary', na: 'bi-dash-circle text-secondary' };
    $('#protectScore').innerHTML = checked.length ? `<span class="prot-score ${ok === checked.length ? 'bg-g1' : (ok >= checked.length - 1 ? 'bg-g3' : 'bg-g5')}">${ok} / ${checked.length}</span>` : '';
    $('#protect').innerHTML = items.map((i) => `<div class="prot-row"><i class="bi ${ICON[i.state]}"></i><div class="prot-txt"><b>${esc(i.title)}</b><small>${esc(i.detail)}</small></div>${i.action}</div>`).join('');
    $('#protect').querySelectorAll('[data-act]').forEach((x) => x.addEventListener('click', async () => {
      x.disabled = true;
      const res = await api(x.dataset.act === 'tester' ? '/api/sauvegardes/tester' : '/api/sauvegardes/copier', { method: 'POST' });
      if (!res.ok) alert(res.erreur);
      setTimeout(() => dashboard().catch(() => {}), 2500);
    }));
  }

  // ------------------------------------------------------------------ navigation
  const pages = { tableau: dashboard, incidents: incidents, sauvegardes: backups, journal: journal, configuration: configuration };
  function route() {
    const p = (location.hash || '#tableau').slice(1);
    const name = pages[p] ? p : 'tableau';
    document.querySelectorAll('.page').forEach((s) => s.classList.toggle('d-none', s.id !== 'p-' + name));
    document.querySelectorAll('.side-link').forEach((a) => a.classList.toggle('active', a.dataset.page === name));
    $('#pageTitle').textContent = TITLES[name];
    pages[name]().catch(() => {});
  }
  window.addEventListener('hashchange', route);

  // ------------------------------------------------------------------ éléments communs
  const kpi = (bg, icon, label, value, sub) => `<div class="col-sm-6 col-xl-3"><div class="kpi ${bg}"><i class="bi ${icon} kpi-icon"></i><div class="kpi-label">${esc(label)}</div><div class="kpi-value">${esc(value)}</div><div class="kpi-sub">${esc(sub || '')}</div></div></div>`;
  function incRows(list, empty) {
    if (!list || !list.length) return `<div class="empty-row"><i class="bi bi-check2-circle text-success fs-4 d-block"></i>${esc(empty)}</div>`;
    return list.map((i) => `<div class="inc-row" data-id="${i.id}" tabindex="0" role="button">
      <span class="inc-dot ${i.status === 'resolved' ? 'dot-res' : 'dot-' + i.severity}"></span>
      <div class="flex-grow-1"><div><span class="badge ${SEV[i.severity][1]} me-1">${SEV[i.severity][0]}</span>${i.status === 'resolved' ? '<span class="badge text-bg-success me-1">Résolu</span>' : ''}<span class="inc-title">${esc(i.title)}</span></div>
      <div class="small">${esc(i.problem)}</div>
      <div class="inc-meta">Détecté le ${fmtDate(i.created_at)}${i.occurrences > 1 ? ' · ' + i.occurrences + ' fois, dernière ' + fmtDate(i.last_seen) : ''}${i.confidence && i.confidence !== 'certain' ? ' · cause ' + esc(i.confidence) : ''}</div></div></div>`).join('');
  }
  document.addEventListener('click', (e) => { const el = e.target.closest('.inc-row'); if (el) openDetail(el.dataset.id); if (e.target.closest('[data-close]')) $('#detail').close(); });
  document.addEventListener('keydown', (e) => { const el = e.target.closest && e.target.closest('.inc-row'); if (el && e.key === 'Enter') openDetail(el.dataset.id); });

  // ------------------------------------------------------------------ tableau de bord
  async function dashboard() {
    const [d, b] = await Promise.all([api('/api/etat'), api('/api/sauvegardes')]);
    const st = d.systeme || {}; const pos = st.pos || {}; const bk = (b && b.etat) || {};
    $('#poste').textContent = d.ordinateur || '';
    $('#navCount').textContent = d.alertes_ouvertes || '';
    const k = [];
    k.push(kpi({ bon: 'bg-g1', surveiller: 'bg-g3', critique: 'bg-g5', urgence: 'bg-g5' }[d.etat], 'bi-shield-check', 'État général du poste', d.etat_libelle, d.alertes_ouvertes + ' alerte(s) non résolue(s)'));
    if (!pos.kind || pos.kind === 'absent') k.push(kpi('bg-g7', 'bi-shop', 'POS Marwa', 'Non trouvé', pos.detail || 'Recherche en cours'));
    else if (!pos.running) k.push(kpi('bg-g7', 'bi-shop', 'POS Marwa', 'Fermé', pos.kind === 'wamp' ? 'WampServer arrêté' : 'Programme non lancé'));
    else k.push(kpi(pos.app_ok === false ? 'bg-g5' : 'bg-g1', 'bi-shop', 'POS Marwa', pos.app_ok === false ? 'Ne répond pas' : (pos.app_ok ? 'Fonctionnel' : 'Non vérifiable'), pos.db_ok === false ? 'Base de données arrêtée' : (pos.db_ok ? 'Base de données fonctionnelle' : '')));
    const lv = bk.last_verified;
    if (lv) {
      const h = (Date.now() - new Date(lv.date).getTime()) / 3600000;
      const late = h > ((b.reglages || {}).backup_warn_hours || 26);
      k.push(kpi(late ? 'bg-g3' : 'bg-g1', 'bi-hdd-stack', 'Dernière sauvegarde vérifiée', since(lv.date), fmtDate(lv.date) + ' · ' + size(lv.size)));
    } else k.push(kpi('bg-g5', 'bi-hdd-stack', 'Dernière sauvegarde vérifiée', 'Aucune', bk.note || (bk.count ? 'aucune sauvegarde n\'a passé la vérification' : 'aucune sauvegarde trouvée')));
    const sys = (st.disks || []).find((x) => x.system) || (st.disks || [])[0];
    if (sys) { const p = 100 * sys.free / sys.total; k.push(kpi(p < 5 ? 'bg-g5' : (p < 15 ? 'bg-g3' : 'bg-g2'), 'bi-device-hdd', 'Disque ' + sys.mount, size(sys.free) + ' libres', Math.round(p) + ' % de ' + size(sys.total))); }
    else k.push(kpi('bg-g7', 'bi-device-hdd', 'Disque système', 'Non vérifié', ''));
    $('#kpis').innerHTML = k.join('');
    protection(d, b); usbHint(b, $('#usbHint'), dashboard);
    const errs = Object.entries(st.errors || {});
    const list = await api('/api/incidents?statut=open');
    $('#openList').innerHTML = (errs.length ? `<div class="alert alert-secondary small m-2">Non vérifié pour le moment : ${errs.map(([n, v]) => esc(n) + ' (' + esc(v) + ')').join(' ; ')}. Rien n'est supposé.</div>` : '') + incRows((list.incidents || []).slice(0, 8), 'Aucune alerte : tout va bien.');
    const svc = st.services || []; const down = svc.filter((x) => x.exists && !x.running);
    const line = (label, val, cls) => `<div class="d-flex justify-content-between border-bottom py-2"><span class="text-secondary">${esc(label)}</span><span class="${cls || ''}">${esc(val)}</span></div>`;
    $('#machine').innerHTML = [
      line('Ordinateur', d.ordinateur),
      line('Dernier démarrage de Windows', fmtDate(st.boot)),
      line('Mémoire disponible', st.memory ? size(st.memory.available) + ' sur ' + size(st.memory.total) : 'non vérifiée'),
      line('Processeur', st.cpu != null ? Math.round(st.cpu) + ' %' : 'non vérifié'),
      line('Arrêts anormaux (30 jours)', String(st.shutdowns_30d ?? 0), st.shutdowns_30d >= 2 ? 'text-warning fw-semibold' : ''),
      line('Services Windows essentiels', svc.length ? (down.length ? down.length + ' arrêté(s)' : 'en marche') : 'non vérifiés', down.length ? 'text-danger fw-semibold' : 'text-success'),
      line('Historique de Guardian', st.log_intact === false ? 'modifié !' : (st.log_intact ? 'intact' : 'non vérifié'), st.log_intact === false ? 'text-danger fw-semibold' : 'text-success'),
    ].join('');
  }

  // ------------------------------------------------------------------ détail d'un incident
  async function openDetail(id) {
    const d = await api('/api/incidents/' + id);
    if (!d.ok) return;
    const i = d.incident; current = i;
    $('#dTitle').textContent = i.title;
    const det = Object.entries(i.details || {}).map(([k, v]) => `<dt>${esc(k)}</dt><dd>${esc(v)}</dd>`).join('');
    $('#dBody').innerHTML = `<p class="mb-2"><span class="badge ${SEV[i.severity][1]}">${SEV[i.severity][0]}</span> ${i.status === 'resolved' ? '<span class="badge text-bg-success">Résolu le ' + fmtDate(i.resolved_at) + ' (' + esc(i.resolved_by) + ')</span>' : ''}</p>
      <p>${esc(i.problem)}</p>
      ${i.consequences ? `<div class="alert alert-warning py-2 small"><b>Conséquences possibles :</b> ${esc(i.consequences)}</div>` : ''}
      ${(i.recommendations || []).length ? `<h6>Que faire ?</h6><ol class="small">${i.recommendations.map((r) => '<li>' + esc(r) + '</li>').join('')}</ol>` : ''}
      <h6 class="mt-3">Faits relevés</h6><dl class="facts small">
        <dt>Détecté le</dt><dd>${fmtDate(i.created_at)}${i.occurrences > 1 ? ' — ' + i.occurrences + ' fois, dernière ' + fmtDate(i.last_seen) : ''}</dd>
        <dt>Certitude</dt><dd>${esc(i.confidence || '—')}</dd>${det}</dl>`;
    $('#dFoot').classList.toggle('d-none', i.status !== 'open');
    $('#dNote').value = '';
    $('#detail').showModal();
  }
  $('#dResolve').addEventListener('click', async () => {
    if (!current) return;
    const d = await api('/api/incidents/' + current.id + '/resoudre', { method: 'POST', body: { note: $('#dNote').value } });
    if (!d.ok) { alert(d.erreur); return; }
    $('#detail').close(); route();
  });

  // ------------------------------------------------------------------ incidents
  async function incidents() {
    const d = await api('/api/incidents' + ($('#filter').value ? '?statut=' + $('#filter').value : ''));
    $('#incList').innerHTML = incRows(d.incidents, 'Aucun incident enregistré.');
  }
  $('#filter').addEventListener('change', () => incidents().catch(() => {}));

  // ------------------------------------------------------------------ sauvegardes
  let bkTimer = null;
  async function backups() {
    clearTimeout(bkTimer);
    const b = await api('/api/sauvegardes');
    const s = b.etat || {}; const lv = s.last_verified; const r = s.restore;
    const k = [];
    if (lv) {
      const h = (Date.now() - new Date(lv.date).getTime()) / 3600000;
      k.push(kpi(h > b.reglages.backup_crit_hours ? 'bg-g5' : (h > b.reglages.backup_warn_hours ? 'bg-g3' : 'bg-g1'), 'bi-check2-circle', 'Dernière sauvegarde vérifiée', since(lv.date), fmtDate(lv.date) + ' · ' + size(lv.size)));
    } else k.push(kpi('bg-g5', 'bi-x-circle', 'Dernière sauvegarde vérifiée', 'Aucune', s.count ? 'aucune n\'a passé la vérification' : 'aucune sauvegarde trouvée'));
    k.push(kpi('bg-g2', 'bi-calendar-check', 'Prochaine sauvegarde attendue', s.due_before ? 'avant le ' + fmtDate(s.due_before) : '—', 'sinon une alerte est affichée'));
    k.push(kpi(!r ? 'bg-g7' : (r.ok ? 'bg-g1' : 'bg-g5'), 'bi-arrow-counterclockwise', 'Test de restauration', s.restore_busy ? 'En cours…' : (!r ? 'Jamais fait' : (r.ok ? 'Réussi' : 'Échec')), r ? fmtDate(r.at) : ''));
    k.push(kpi('bg-g7', 'bi-folder2-open', 'Sauvegardes sur ce poste', String(s.count || 0), size(s.total_size || 0) + ' au total'));
    $('#bkKpis').innerHTML = k.join('');
    usbHint(b, $('#bkUsbHint'), backups);
    $('#bkDir').textContent = s.dir || '';
    $('#bkNote').innerHTML = (s.note ? `<div class="alert alert-warning small">${esc(s.note)}</div>` : '') + (s.auto_failure ? `<div class="alert alert-danger small"><i class="bi bi-exclamation-octagon"></i> ${esc(s.auto_failure)}</div>` : '');
    $('#bkList').innerHTML = (s.recent || []).map((x) => {
      const c = x.check;
      const v = !c ? '<span class="badge text-bg-secondary">Non vérifiée</span>'
        : (c.sha_ok && c.complete && c.tables > 0 ? `<span class="badge text-bg-success">Vérifiée</span> <span class="small text-secondary">${c.tables} tables${c.version ? ' · v' + esc(c.version) : ''}</span>`
          : `<span class="badge text-bg-danger">Abîmée</span> <span class="small text-danger">${esc(c.error)}</span>`);
      return `<tr><td>${fmtDate(x.date)}</td><td>${esc(reason(x.reason))}</td><td class="num">${size(x.size + (x.zip_size || 0))}${x.zip ? ' <i class="bi bi-images text-secondary" title="avec les photos"></i>' : ''}</td><td>${v}</td></tr>`;
    }).join('') || '<tr><td colspan="4" class="empty-row">Aucune sauvegarde trouvée.</td></tr>';
    const counts = r && r.counts ? Object.entries({ sales: 'ventes', products: 'articles', customers: 'clients', users: 'utilisateurs' }).filter(([t]) => t in r.counts).map(([t, l]) => `<b>${r.counts[t]}</b> ${l}`).join(', ') : '';
    $('#bkRestore').innerHTML = `<p class="small text-secondary">La dernière sauvegarde vérifiée est rejouée dans une base <b>temporaire et séparée</b>, puis effacée. La base de POS Marwa n'est jamais touchée. Fréquence : ${b.reglages.restore_test_days ? 'tous les ' + b.reglages.restore_test_days + ' jours' : 'à la demande seulement'}.</p>`
      + (s.restore_busy ? '<div class="alert alert-info small"><span class="spinner-border spinner-border-sm"></span> Test en cours (une à quelques minutes, en priorité basse : la caisse n\'est pas ralentie).</div>'
        : !r ? '<div class="alert alert-secondary small">Aucun test réalisé pour le moment.</div>'
          : r.ok ? `<div class="alert alert-success small"><i class="bi bi-check2-circle"></i> <b>Restauration réussie</b> le ${fmtDate(r.at)} (${Math.round(r.duration_s)} s) : ${r.tables} tables, ${counts}.<br><span class="text-secondary">${esc(r.backup)}</span></div>`
            : `<div class="alert alert-danger small"><i class="bi bi-x-circle"></i> <b>Échec</b> le ${fmtDate(r.at)} : ${esc(r.error)}<br><span class="text-secondary">${esc(r.backup)}</span></div>`)
      + `<button type="button" class="btn btn-sm btn-primary" id="restoreNow" ${s.restore_busy || !lv ? 'disabled' : ''}><i class="bi bi-play-circle"></i> Tester la restauration maintenant</button>`;
    $('#restoreNow').addEventListener('click', async () => {
      const d = await api('/api/sauvegardes/tester', { method: 'POST' });
      if (!d.ok) alert(d.erreur); backups();
    });
    const copies = s.copies || [];
    $('#bkCopies').innerHTML = copies.length ? '<ul class="list-group list-group-flush">' + copies.map((c) => `<li class="list-group-item">
      <div class="d-flex align-items-start gap-2"><i class="bi ${c.dest.kind === 'support' ? 'bi-usb-drive' : 'bi-folder'} fs-5 text-secondary"></i>
      <div class="flex-grow-1"><div class="fw-semibold">${esc(c.name)}</div>
      <div class="small ${c.error ? 'text-danger' : 'text-success'}">${c.error ? esc(c.error) : (c.last_ok ? 'À jour — dernière copie contrôlée ' + fmtDate(c.last_ok) : 'Première copie à venir')}</div>
      <div class="small text-secondary">${c.last_ok && c.error ? 'Dernière copie réussie : ' + fmtDate(c.last_ok) + ' · ' : ''}${c.held ? c.held + ' sauvegarde(s) · ' : ''}garde ${c.dest.keep}${c.free ? ' · ' + size(c.free) + ' libres' : ''}${c.config_saved ? ' · configuration copiée ' + fmtDate(c.config_saved) : ''}</div></div>
      <button type="button" class="btn btn-sm btn-outline-danger" data-remove="${esc(c.dest.id)}" title="Retirer (les copies déjà faites restent)"><i class="bi bi-x-lg"></i></button></div></li>`).join('') + '</ul>'
      : '<div class="empty-row small">Aucune copie hors de ce poste. Ajoutez une clé USB, un disque externe ou un dossier réseau : en cas de panne ou de vol du PC, les sauvegardes restées dessus seraient perdues avec lui.</div>';
    document.querySelectorAll('[data-remove]').forEach((x) => x.addEventListener('click', async () => {
      if (!confirm('Retirer cette destination ? Les copies déjà faites restent sur le support.')) return;
      const d = await api('/api/destinations/' + x.dataset.remove + '/retirer', { method: 'POST' });
      if (!d.ok) alert(d.erreur); backups();
    }));
    $('#copyNow').disabled = !copies.length;
    const sel = $('#dLabel'); const prev = sel.value;
    sel.innerHTML = (b.volumes || []).map((v) => `<option value="${esc(v.label)}">${esc(v.label)} (${esc(v.root)})${v.removable ? ' — amovible' : ''}</option>`).join('') || '<option value="">Aucun support branché</option>';
    if (prev) sel.value = prev;
    if (s.restore_busy) bkTimer = setTimeout(() => { if ((location.hash || '') === '#sauvegardes') backups().catch(() => {}); }, 5000);
  }
  document.querySelectorAll('input[name=dkind]').forEach((r) => r.addEventListener('change', () => {
    const f = document.querySelector('input[name=dkind]:checked').value === 'dossier';
    $('#dFolder').classList.toggle('d-none', !f); $('#dSupport').classList.toggle('d-none', f);
  }));
  $('#destForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const kind = document.querySelector('input[name=dkind]:checked').value;
    const d = await api('/api/destinations', { method: 'POST', body: { kind, label: $('#dLabel').value, path: $('#dPath').value, keep: Number($('#dKeep').value) || 30 } });
    const err = $('#destErr');
    if (!d.ok) { err.textContent = d.erreur; err.classList.remove('d-none'); return; }
    err.classList.add('d-none'); $('#dPath').value = '';
    setTimeout(() => backups().catch(() => {}), 1500);
  });
  $('#copyNow').addEventListener('click', async () => {
    const d = await api('/api/sauvegardes/copier', { method: 'POST' });
    if (!d.ok) { alert(d.erreur); return; }
    $('#copyNow').disabled = true; setTimeout(() => backups().catch(() => {}), 3000);
  });

  // ------------------------------------------------------------------ journal
  async function journal() {
    const d = await api('/api/journal');
    $('#integrity').innerHTML = d.intact ? '<div class="alert alert-success"><i class="bi bi-shield-check"></i> Historique intact : aucune entrée modifiée ni supprimée depuis son enregistrement.</div>'
      : `<div class="alert alert-danger"><i class="bi bi-shield-exclamation"></i> Historique modifié en dehors de MARWA GUARDIAN : ${esc((d.anomalies || []).join(' ; '))}</div>`;
    $('#journalBody').innerHTML = (d.journal || []).map((j) => `<tr><td class="text-nowrap">${fmtDate(j.ts)}</td><td>${esc(j.action)}</td><td>${esc(j.actor)}</td><td>${esc(j.note)}${j.incident_id ? ' <span class="text-secondary">(incident n° ' + j.incident_id + ')</span>' : ''}</td></tr>`).join('') || '<tr><td colspan="4" class="empty-row">Aucune action.</td></tr>';
  }

  // ------------------------------------------------------------------ configuration
  async function configuration() {
    const [d, b] = await Promise.all([api('/api/etat'), api('/api/sauvegardes')]);
    const st = d.systeme || {}; const pos = st.pos || {}; const g = b.reglages || {};
    $('#cfg').innerHTML = `<div class="row g-3">
      <div class="col-lg-6"><div class="card h-100"><div class="card-header"><i class="bi bi-info-circle"></i> Ce poste</div><div class="card-body"><dl class="facts small">
        <dt>Ordinateur</dt><dd>${esc(d.ordinateur)}</dd><dt>Version</dt><dd>MARWA GUARDIAN ${esc(d.version)}</dd>
        <dt>POS Marwa</dt><dd>${esc(pos.kind === 'bureau' ? 'Édition Bureau' : pos.kind === 'wamp' ? 'WampServer' : 'Non trouvé')}${pos.dir ? ' — ' + esc(pos.dir) : ''}${pos.web_port ? ' · ports ' + pos.web_port + ' / ' + pos.db_port : ''}</dd>
        <dt>Sauvegardes</dt><dd>${esc((b.etat || {}).dir || 'dossier introuvable')}</dd></dl></div></div></div>
      <div class="col-lg-6"><form class="card h-100" id="bkSet"><div class="card-header"><i class="bi bi-sliders"></i> Réglages des sauvegardes</div><div class="card-body row g-2">
        <div class="col-6"><label class="form-label" for="s1">Alerte si aucune sauvegarde depuis (h)</label><input id="s1" type="number" min="1" max="720" class="form-control form-control-sm" value="${g.backup_warn_hours}"></div>
        <div class="col-6"><label class="form-label" for="s2">Alerte critique après (h)</label><input id="s2" type="number" min="1" max="1440" class="form-control form-control-sm" value="${g.backup_crit_hours}"></div>
        <div class="col-6"><label class="form-label" for="s3">Test de restauration tous les (jours, 0 = à la demande)</label><input id="s3" type="number" min="0" max="90" class="form-control form-control-sm" value="${g.restore_test_days}"></div>
        <div class="col-12"><label class="form-label" for="s4">Dossier de l'application POS Marwa (WampServer seulement)</label><input id="s4" class="form-control form-control-sm" value="${esc(g.pos_app_dir)}" placeholder="ex. C:\\wamp64\\www\\pos"></div>
        <div class="col-12"><label class="form-label" for="s5">Dossier des programmes MariaDB/MySQL (vide = celui de POS Marwa Bureau)</label><input id="s5" class="form-control form-control-sm" value="${esc(g.mariadb_bin)}" placeholder="ex. C:\\wamp64\\bin\\mariadb\\mariadb11.4\\bin"></div>
        <div class="col-12"><div id="setMsg" class="small"></div><button class="btn btn-sm btn-primary mt-1"><i class="bi bi-check2"></i> Enregistrer</button></div></div></form></div>
      <div class="col-12"><div class="card"><div class="card-header"><i class="bi bi-layout-sidebar-reverse"></i> Affichage</div><div class="card-body d-flex flex-wrap align-items-center gap-3">
        <span class="small">Position du menu</span>
        <div class="btn-group btn-group-sm" role="group" aria-label="Position du menu">
          <input type="radio" class="btn-check" name="menuPos" id="mp1" value="droite" ${document.body.classList.contains('menu-left') ? '' : 'checked'}><label class="btn btn-outline-secondary" for="mp1"><i class="bi bi-layout-sidebar-reverse"></i> À droite (comme POS Marwa)</label>
          <input type="radio" class="btn-check" name="menuPos" id="mp2" value="gauche" ${document.body.classList.contains('menu-left') ? 'checked' : ''}><label class="btn btn-outline-secondary" for="mp2"><i class="bi bi-layout-sidebar"></i> À gauche</label>
        </div><span id="menuMsg" class="small"></span></div></div></div>
      <div class="col-12"><div class="card"><div class="card-header"><i class="bi bi-eye"></i> Ce que MARWA GUARDIAN surveille sur ce poste</div><div class="card-body small">
        <ul class="mb-2">
          <li>Espace des disques, mémoire et processeur (valeurs mesurées, gardées 7 jours).</li>
          <li>Journal « Système » de Windows : arrêts anormaux, écrans bleus, erreurs matérielles, erreurs de disque, redémarrages.</li>
          <li>Services Windows essentiels : journal des événements, WMI, pare-feu, Microsoft Defender, DHCP, DNS (état seulement).</li>
          <li>POS Marwa : programme, application et base de données qui répondent ; journal de récupération de la base après un arrêt brutal.</li>
          <li>Sauvegardes de POS Marwa : empreinte et lecture complète de chaque sauvegarde, ancienneté, échecs de la sauvegarde automatique ; test de restauration dans une base séparée ; copies vers les destinations choisies (avec la configuration de POS Marwa).</li>
        </ul>
        <p class="text-secondary mb-0">MARWA GUARDIAN ne lit ni le contenu des ventes ni les mots de passe, n'envoie rien sur Internet, ne modifie ni ne supprime les sauvegardes de POS Marwa et n'arrête aucun programme. Les seuls fichiers qu'il supprime sont ses propres copies anciennes (rotation), dans son dossier « MARWA GUARDIAN » sur chaque destination. La protection des fichiers, des accès, du réseau et de l'antivirus arrive aux phases suivantes.</p></div></div></div></div>`;
    document.querySelectorAll('input[name=menuPos]').forEach((x) => x.addEventListener('change', async () => {
      const r = await api('/api/affichage', { method: 'POST', body: { menu: x.value } });
      if (r.ok) applyMenu(r.menu);
      $('#menuMsg').className = 'small ' + (r.ok ? 'text-success' : 'text-danger'); $('#menuMsg').textContent = r.ok ? 'Enregistré.' : r.erreur;
    }));
    $('#bkSet').addEventListener('submit', async (e) => {
      e.preventDefault();
      const r = await api('/api/sauvegardes/reglages', { method: 'POST', body: { backup_warn_hours: Number($('#s1').value), backup_crit_hours: Number($('#s2').value), restore_test_days: Number($('#s3').value), pos_app_dir: $('#s4').value, mariadb_bin: $('#s5').value } });
      $('#setMsg').className = 'small ' + (r.ok ? 'text-success' : 'text-danger');
      $('#setMsg').textContent = r.ok ? 'Réglages enregistrés.' : r.erreur;
    });
  }

  boot().catch(() => showAuth(false));
  setInterval(() => { if (!$('#app').classList.contains('d-none') && (location.hash || '#tableau') === '#tableau' && !$('#detail').open) dashboard().catch(() => {}); }, 30000);
})();
