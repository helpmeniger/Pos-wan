/* MARWA GUARDIAN — console (aucun script externe ; données de l'API locale uniquement). */
(function () {
  'use strict';
  const $ = (s) => document.querySelector(s);
  let csrf = '';
  let current = null;

  const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const fmtDate = (s) => { if (!s) return '—'; const d = new Date(s); return isNaN(d) ? '—' : d.toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' }); };
  const gb = (b) => (b / 1073741824).toFixed(1).replace('.', ',') + ' Go';
  const SEV = { 1: 'Information', 2: 'Avertissement', 3: 'Critique', 4: 'Urgence' };

  async function api(path, opts = {}) {
    const o = { method: opts.method || 'GET', headers: { Accept: 'application/json' }, credentials: 'same-origin' };
    if (opts.body !== undefined) { o.headers['Content-Type'] = 'application/json'; o.body = JSON.stringify(opts.body); }
    if (o.method !== 'GET') o.headers['X-Guardian-CSRF'] = csrf;
    const r = await fetch(path, o);
    const d = await r.json().catch(() => ({ ok: false, erreur: 'Réponse illisible' }));
    if (r.status === 401 && !path.startsWith('/api/connexion')) { showAuth(); throw new Error('connexion requise'); }
    return d;
  }

  // ------------------------------------------------------------------ connexion
  async function boot() {
    const s = await api('/api/session');
    $('#poste').textContent = 'MARWA GUARDIAN ' + (s.version || '');
    if (s.connecte) { csrf = s.csrf; showApp(); return; }
    showAuth(!s.mot_de_passe_defini);
  }
  let firstTime = false;
  function showAuth(first) {
    if (first !== undefined) firstTime = first;
    $('#app').classList.add('hidden'); $('#logout').classList.add('hidden'); $('#auth').classList.remove('hidden');
    $('#authTitle').textContent = firstTime ? 'Premier accès' : 'Connexion';
    $('#authHelp').textContent = firstTime ? 'Choisissez le mot de passe administrateur de MARWA GUARDIAN (8 caractères au moins). Il protège la console de cet ordinateur.' : 'Console réservée à l\'administrateur de cet ordinateur.';
    $('#pw2Box').classList.toggle('hidden', !firstTime);
    $('#authBtn').textContent = firstTime ? 'Enregistrer et entrer' : 'Se connecter';
    $('#pw').autocomplete = firstTime ? 'new-password' : 'current-password';
    $('#pw').focus();
  }
  $('#authForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const err = $('#authErr'); err.classList.add('hidden');
    if (firstTime && $('#pw').value !== $('#pw2').value) { err.textContent = 'Les deux mots de passe sont différents.'; err.classList.remove('hidden'); return; }
    const d = await api(firstTime ? '/api/premier-mot-de-passe' : '/api/connexion', { method: 'POST', body: { mot_de_passe: $('#pw').value } });
    if (!d.ok) { err.textContent = d.erreur; err.classList.remove('hidden'); return; }
    csrf = d.csrf; $('#pw').value = ''; $('#pw2').value = '';
    showApp();
  });
  $('#logout').addEventListener('click', async () => { await api('/api/deconnexion', { method: 'POST' }); csrf = ''; showAuth(false); });

  function showApp() {
    $('#auth').classList.add('hidden'); $('#app').classList.remove('hidden'); $('#logout').classList.remove('hidden');
    route();
  }

  // ------------------------------------------------------------------ navigation
  const pages = { tableau: dashboard, incidents: incidents, journal: journal, configuration: configuration };
  function route() {
    const p = (location.hash || '#tableau').slice(1);
    const name = pages[p] ? p : 'tableau';
    document.querySelectorAll('.page').forEach((s) => s.classList.toggle('hidden', s.id !== 'p-' + name));
    document.querySelectorAll('.side a').forEach((a) => a.classList.toggle('active', a.dataset.page === name));
    pages[name]().catch(() => {});
  }
  window.addEventListener('hashchange', route);

  // ------------------------------------------------------------------ tableau de bord
  function kpi(t, v, cls, sub) { return `<div class="kpi"><div class="t">${esc(t)}</div><div class="v ${cls}">${esc(v)}</div><div class="s">${esc(sub || '')}</div></div>`; }
  async function dashboard() {
    const d = await api('/api/etat');
    const st = d.systeme || {};
    $('#poste').textContent = (d.ordinateur || '') + ' · MARWA GUARDIAN ' + (d.version || '');
    $('#health').className = 'health h-' + d.etat;
    $('#health').innerHTML = `<div><small>État général du poste</small><div class="big">${esc(d.etat_libelle)}</div></div><div><small>Alertes non résolues</small><div class="big">${d.alertes_ouvertes}</div></div>`;
    const cards = [];
    const pos = st.pos || {};
    if (pos.kind === 'absent' || !pos.kind) cards.push(kpi('POS Marwa', 'Non trouvé', 'na', pos.detail || 'Vérification en cours'));
    else if (!pos.running) cards.push(kpi('POS Marwa', 'Fermé', 'na', pos.kind === 'wamp' ? 'WampServer arrêté' : 'Le programme n\'est pas lancé'));
    else cards.push(kpi('POS Marwa', pos.app_ok === false ? 'Ne répond pas' : (pos.app_ok ? 'Fonctionnel' : 'Non vérifiable'), pos.app_ok === false ? 'bad' : (pos.app_ok ? 'ok' : 'na'), pos.kind === 'bureau' ? 'Édition Bureau' : 'WampServer'));
    if (pos.running) cards.push(kpi('Base de données', pos.db_ok === false ? 'Arrêtée' : (pos.db_ok ? 'Fonctionnelle' : 'Non vérifiable'), pos.db_ok === false ? 'bad' : (pos.db_ok ? 'ok' : 'na'), pos.db_port ? 'Port ' + pos.db_port : (pos.detail || '')));
    else cards.push(kpi('Base de données', 'Non vérifiée', 'na', 'POS Marwa est fermé'));
    const sys = (st.disks || []).find((x) => x.system) || (st.disks || [])[0];
    if (sys) { const p = 100 * sys.free / sys.total; cards.push(kpi('Disque système ' + sys.mount, gb(sys.free) + ' libres', p < 5 ? 'bad' : (p < 15 ? 'warn' : 'ok'), Math.round(p) + ' % de ' + gb(sys.total))); }
    else cards.push(kpi('Disque système', 'Non vérifié', 'na', st.errors && st.errors.disques ? st.errors.disques : ''));
    if (st.memory) { const p = 100 * st.memory.available / st.memory.total; cards.push(kpi('Mémoire disponible', gb(st.memory.available), p < 5 ? 'bad' : (p < 10 ? 'warn' : 'ok'), 'sur ' + gb(st.memory.total))); }
    if (st.cpu !== null && st.cpu !== undefined) cards.push(kpi('Processeur', Math.round(st.cpu) + ' %', st.cpu > 90 ? 'warn' : 'ok', 'dernière mesure'));
    cards.push(kpi('Arrêts anormaux (30 j)', String(st.shutdowns_30d ?? 0), st.shutdowns_30d >= 2 ? 'warn' : 'ok', 'd\'après les journaux Windows'));
    const svc = st.services || [];
    const down = svc.filter((x) => x.exists && !x.running);
    cards.push(kpi('Services Windows essentiels', svc.length ? (down.length ? down.length + ' arrêté(s)' : 'En marche') : 'Non vérifiés', svc.length ? (down.length ? 'bad' : 'ok') : 'na', svc.filter((x) => x.exists).length + ' surveillé(s)'));
    cards.push(kpi('Historique de Guardian', st.log_intact === false ? 'Modifié !' : (st.log_intact ? 'Intact' : 'Non vérifié'), st.log_intact === false ? 'bad' : (st.log_intact ? 'ok' : 'na'), 'contrôle d\'intégrité'));
    $('#cards').innerHTML = cards.join('');
    const errs = Object.entries(st.errors || {});
    const list = await api('/api/incidents?statut=open');
    $('#openList').innerHTML = (errs.length ? `<div class="note">Contrôles impossibles pour le moment : ${errs.map(([k, v]) => esc(k) + ' (' + esc(v) + ')').join(' ; ')}. Leur état est affiché « non vérifié », jamais supposé.</div>` : '') + renderList(list.incidents, 'Aucune alerte non résolue.');
  }

  function renderList(list, empty) {
    if (!list || !list.length) return `<div class="empty">${esc(empty)}</div>`;
    return list.map((i) => `<div class="inc sev-${i.severity}" data-id="${i.id}" tabindex="0" role="button"><div><span class="badge b-${i.severity}">${SEV[i.severity]}</span>${i.status === 'resolved' ? '<span class="badge b-res">Résolu</span>' : ''}<span class="ti">${esc(i.title)}</span>
      <div>${esc(i.problem)}</div><div class="me">${fmtDate(i.created_at)}${i.occurrences > 1 ? ' · détecté ' + i.occurrences + ' fois (dernière : ' + fmtDate(i.last_seen) + ')' : ''}${i.confidence && i.confidence !== 'certain' ? ' · cause ' + esc(i.confidence) : ''}</div></div></div>`).join('');
  }
  document.addEventListener('click', (e) => { const el = e.target.closest('.inc'); if (el) openDetail(el.dataset.id); });
  document.addEventListener('keydown', (e) => { const el = e.target.closest && e.target.closest('.inc'); if (el && e.key === 'Enter') openDetail(el.dataset.id); });

  // ------------------------------------------------------------------ détail
  async function openDetail(id) {
    const d = await api('/api/incidents/' + id);
    if (!d.ok) return;
    const i = d.incident; current = i;
    $('#dTitle').textContent = i.title;
    const det = Object.entries(i.details || {}).map(([k, v]) => `<dt>${esc(k)}</dt><dd>${esc(v)}</dd>`).join('');
    $('#dBody').innerHTML = `<dl>
      <dt>Gravité</dt><dd><span class="badge b-${i.severity}">${SEV[i.severity]}</span>${i.status === 'resolved' ? '<span class="badge b-res">Résolu le ' + fmtDate(i.resolved_at) + ' (' + esc(i.resolved_by) + ')</span>' : ''}</dd>
      <dt>Détecté le</dt><dd>${fmtDate(i.created_at)}${i.occurrences > 1 ? ' — détecté ' + i.occurrences + ' fois, dernière ' + fmtDate(i.last_seen) : ''}</dd>
      <dt>Problème</dt><dd>${esc(i.problem)}</dd>
      <dt>Certitude</dt><dd>${esc(i.confidence || '—')}</dd>
      <dt>Conséquences possibles</dt><dd>${esc(i.consequences || '—')}</dd>
      <dt>Que faire</dt><dd><ul>${(i.recommendations || []).map((r) => '<li>' + esc(r) + '</li>').join('')}</ul></dd>
      ${det}</dl>`;
    $('#dResolve').classList.toggle('hidden', i.status !== 'open');
    $('#dNote').parentElement.querySelector('label[for=dNote]').classList.toggle('hidden', i.status !== 'open');
    $('#dNote').classList.toggle('hidden', i.status !== 'open');
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
    $('#incList').innerHTML = renderList(d.incidents, 'Aucun incident enregistré.');
  }
  $('#filter').addEventListener('change', () => incidents().catch(() => {}));

  // ------------------------------------------------------------------ journal
  async function journal() {
    const d = await api('/api/journal');
    $('#integrity').innerHTML = d.intact ? '<div class="note ok">✔ Historique intact : aucune entrée modifiée ni supprimée depuis son enregistrement.</div>'
      : `<div class="note bad">✖ Historique modifié en dehors de MARWA GUARDIAN : ${esc((d.anomalies || []).join(' ; '))}</div>`;
    $('#journalBody').innerHTML = (d.journal || []).map((j) => `<tr><td>${fmtDate(j.ts)}</td><td>${esc(j.action)}</td><td>${esc(j.actor)}</td><td>${esc(j.note)}${j.incident_id ? ' (incident n° ' + j.incident_id + ')' : ''}</td></tr>`).join('') || '<tr><td colspan="4" class="muted">Aucune action.</td></tr>';
  }

  // ------------------------------------------------------------------ configuration (transparence)
  async function configuration() {
    const d = await api('/api/etat');
    const st = d.systeme || {}; const pos = st.pos || {};
    $('#cfg').innerHTML = `<div class="card"><dl class="cfgdl">
      <dt>Ordinateur</dt><dd>${esc(d.ordinateur)}</dd>
      <dt>Version</dt><dd>MARWA GUARDIAN ${esc(d.version)}</dd>
      <dt>POS Marwa</dt><dd>${esc(pos.kind === 'bureau' ? 'Édition Bureau' : pos.kind === 'wamp' ? 'WampServer' : 'Non trouvé')} ${esc(pos.dir || '')} ${pos.web_port ? '· ports ' + pos.web_port + ' / ' + pos.db_port : ''}</dd>
      <dt>Dernier démarrage de Windows</dt><dd>${fmtDate(st.boot)}</dd></dl></div>
      <h2>Ce que MARWA GUARDIAN surveille sur ce poste</h2>
      <div class="card"><ul>
        <li>Espace des disques, mémoire et processeur (valeurs mesurées, gardées 7 jours).</li>
        <li>Journal « Système » de Windows : arrêts anormaux, écrans bleus, erreurs matérielles, erreurs de disque, redémarrages.</li>
        <li>Services Windows essentiels : journal des événements, WMI, pare-feu, Microsoft Defender, DHCP, DNS (état seulement).</li>
        <li>POS Marwa : programme lancé, application et base de données qui répondent, journal de récupération de la base après un arrêt brutal.</li>
      </ul>
      <p class="muted">MARWA GUARDIAN ne lit ni les ventes, ni les clients, ni les mots de passe, n'envoie rien sur Internet, ne supprime aucun fichier et n'arrête aucun programme. La protection des fichiers, la surveillance des accès et des clés USB, du réseau, de l'antivirus et des sauvegardes arrivent dans les phases suivantes : elles ne sont pas encore actives.</p></div>`;
  }

  boot().catch(() => showAuth(false));
  setInterval(() => { if (!$('#app').classList.contains('hidden') && (location.hash || '#tableau') === '#tableau' && !$('#detail').open) dashboard().catch(() => {}); }, 30000);
})();
