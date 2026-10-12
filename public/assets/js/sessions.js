'use strict';
(() => {
  const { canEdit } = V.pageData();
  const box = document.getElementById('sessions');
  const tabsBar = document.getElementById('tabs-bar');
  const store = (k, v) => { try { if (v === undefined) return localStorage.getItem(k); localStorage.setItem(k, v); } catch (e) { return null; } };
  let view = store('verifia.view') || 'grid';
  let cols = store('verifia.cols') || 'auto';
  let activeTab = null;
  let sessions = [];
  const modal = new bootstrap.Modal(document.getElementById('modal-session'));
  const histModal = new bootstrap.Modal(document.getElementById('modal-history'));

  const RUN = { stopped: ['Arrêtée', 'secondary'], starting: ['Démarrage…', 'info'], running: ['En cours', 'success'], paused: ['Suspendue', 'warning'], error: ['Erreur', 'danger'], closed: ['Fermée', 'dark'] };
  const CONN = { unknown: ['Connexion : inconnue', 'secondary'], ok: ['Connexion : OK', 'success'], login_required: ['Connexion requise', 'warning'], captcha: ['CAPTCHA : intervention manuelle', 'danger'], blocked: ['Automatisation bloquée', 'danger'], offline: ['Hors ligne', 'danger'] };
  const badge = (m, k) => { const [l, c] = m[k] || [k, 'secondary']; return `<span class="badge text-bg-${c}">${V.esc(l)}</span>`; };

  function applyLayout() {
    box.className = 'session-grid' + (view === 'list' ? ' list' : '') + (view === 'grid' && cols !== 'auto' ? ' cols-' + cols : '');
    document.querySelectorAll('[data-view]').forEach((b) => b.classList.toggle('active', b.dataset.view === view));
    document.getElementById('grid-cols').value = cols;
    document.getElementById('grid-cols').disabled = view !== 'grid';
    tabsBar.hidden = view !== 'tabs';
  }

  function card(s) {
    const run = s.run_state, desired = s.desired_state;
    const blocked = ['captcha', 'login_required', 'blocked'].includes(s.connection_state);
    // Transition en cours d'application par le moteur (hors suspension automatique pour intervention manuelle)
    const pending = run !== desired && !(desired === 'stopped' && run === 'error') && desired !== 'closed' && !(blocked && run === 'paused')
      && !(desired === 'running' && run === 'paused' && /Limite|Durée maximale/.test(s.status_message || ''));
    const act = (a, label, cls = 'outline-light', title = '') => canEdit ? `<button class="btn btn-${cls}" data-act="${a}" data-id="${s.id}" title="${V.esc(title)}">${label}</button>` : '';
    let buttons = '';
    if (desired === 'closed') {
      buttons = act('reopen', 'Rouvrir');
    } else {
      if (desired === 'stopped') buttons += act('start', '▶ Démarrer', 'success', 'Ouvre le navigateur de la session');
      if (desired === 'running') buttons += act('pause', '⏸ Suspendre', 'warning');
      if (desired === 'paused') buttons += act('resume', '▶ Reprendre', 'success');
      if (desired !== 'stopped') buttons += act('stop', '⏹ Arrêter', 'outline-danger', 'Ferme le navigateur');
      if (run === 'error' || desired !== 'stopped') buttons += act('restart', '↻ Redémarrer', 'outline-warning');
      if (run === 'running' || run === 'paused') {
        buttons += act('scan_once', 'Analyser maintenant', 'outline-info', 'Analyse les publications visibles sans défiler');
        buttons += act('focus', 'Afficher la fenêtre', 'outline-secondary', 'Ramène la fenêtre du navigateur au premier plan');
      }
      buttons += act(s.analysis_enabled ? 'analysis_off' : 'analysis_on', s.analysis_enabled ? 'Analyse : ON' : 'Analyse : OFF', s.analysis_enabled ? 'outline-success' : 'outline-secondary');
      buttons += act(s.autoscroll_enabled ? 'autoscroll_off' : 'autoscroll_on', s.autoscroll_enabled ? 'Défilement : ON' : 'Défilement : OFF', s.autoscroll_enabled ? 'outline-success' : 'outline-secondary');
      buttons += canEdit ? `<button class="btn btn-outline-light" data-edit="${s.id}">Paramètres</button>` : '';
      buttons += act('close', 'Fermer', 'outline-danger', 'Ferme définitivement la session (ses alertes restent consultables)');
    }
    const hb = s.last_heartbeat_at ? V.fmtDate(s.last_heartbeat_at) : 'jamais';
    const preview = (run === 'running' || run === 'paused' || run === 'error') ? `<img src="screen.php?id=${s.id}&t=${Math.floor(Date.now() / 10000)}" alt="Aperçu de la page surveillée">` : '<span class="text-body-secondary small">Navigateur fermé</span>';
    return `<div class="card session-card state-${V.esc(run)}" data-session="${s.id}">
      <div class="preview">${preview}</div>
      <div class="card-body p-2">
        <div class="d-flex justify-content-between align-items-start">
          <div><b class="session-name" ${canEdit ? `data-rename="${s.id}" title="Cliquer pour renommer" role="button"` : ''}>${V.esc(s.name)}</b> <span class="text-body-secondary small">#${s.id}</span><br>
          <a class="small text-break" href="${V.esc(s.start_url)}" target="_blank" rel="noopener noreferrer">${V.esc(s.domain || s.start_url)}</a></div>
          <div class="text-end">${badge(RUN, run)}${pending ? ' <span class="badge text-bg-dark border" title="État demandé, en cours d\'application par le moteur">→ ' + V.esc((RUN[desired] || [desired])[0]) + '</span>' : ''}<br>${badge(CONN, s.connection_state)}</div>
        </div>
        ${s.status_message ? `<div class="small mt-1 ${run === 'error' || ['captcha', 'blocked', 'login_required'].includes(s.connection_state) ? 'text-warning' : 'text-body-secondary'}">${V.esc(s.status_message)}</div>` : ''}
        <div class="small counters mt-1"><span>Publications examinées : <b>${s.posts_seen}</b></span><span>Alertes : <b>${s.alerts_count}</b></span>
          <span>Défilement : ${s.scroll_step_px}px / ${s.scroll_delay_ms}ms</span><span>Max : ${s.max_posts} pub. / ${s.max_duration_min} min</span></div>
        <div class="small text-body-secondary">Dernier signal : ${hb} · <a href="#" data-history="${s.id}">Historique et erreurs</a> · <a href="alerts.php?session_id=${s.id}">Alertes</a></div>
        <div class="d-flex flex-wrap gap-1 mt-2">${buttons}</div>
      </div></div>`;
  }

  function render() {
    const list = view === 'tabs' && sessions.length ? sessions.filter((s) => String(s.id) === String(activeTab ?? sessions[0].id)) : sessions;
    if (view === 'tabs') {
      if (!sessions.find((s) => String(s.id) === String(activeTab))) activeTab = sessions[0]?.id ?? null;
      tabsBar.innerHTML = sessions.map((s) => `<a href="#" class="nav-link ${String(s.id) === String(activeTab) ? 'active' : ''}" data-tab="${s.id}">${V.esc(s.name)} ${badge(RUN, s.run_state)}</a>`).join('');
    }
    box.innerHTML = list.map(card).join('');
    document.getElementById('no-session').hidden = sessions.length > 0;
  }

  async function load() {
    const r = await V.api('api/sessions.php' + (document.getElementById('show-closed').checked ? '?closed=1' : ''));
    if (!r.sessions) return;
    sessions = r.sessions;
    const c = r.capacity;
    document.getElementById('capacity-line').innerHTML = `Capacité : <b>${c.active}</b> session(s) active(s) sur une limite de <b>${c.limit}</b>` +
      (c.memory_limit !== null ? ` (limite configurée ${c.configured_max}, limite selon la mémoire libre ${c.memory_limit})` : ` (limite configurée ; mémoire inconnue tant que le moteur n'est pas lancé)`) +
      (r.emergency_stop ? ' — <b class="text-danger">ARRÊT IMMÉDIAT ACTIF</b> (levez-le depuis le tableau de bord)' : '');
    if (!document.querySelector('.modal.show')) render();
  }

  document.addEventListener('click', async (ev) => {
    const t = ev.target;
    if (t.dataset.view) { view = t.dataset.view; store('verifia.view', view); applyLayout(); render(); }
    if (t.closest('[data-tab]')) { ev.preventDefault(); activeTab = t.closest('[data-tab]').dataset.tab; render(); }
    if (t.dataset.act) {
      t.disabled = true;
      const r = await V.api('api/sessions.php', { op: 'action', id: +t.dataset.id, action: t.dataset.act });
      V.toast(V.esc(r.message || r.error || ''), r.ok ? '' : 'error');
      load();
    }
    if (t.dataset.edit) openForm(sessions.find((s) => String(s.id) === t.dataset.edit));
    if (t.dataset.rename) {
      const s = sessions.find((x) => String(x.id) === t.dataset.rename);
      const name = prompt('Nouveau nom de la session', s.name);
      if (name && name.trim()) { const r = await V.api('api/sessions.php', { op: 'update', id: s.id, name: name.trim() }); if (!r.ok) V.toast(V.esc(r.message), 'error'); load(); }
    }
    if (t.dataset.history) {
      ev.preventDefault();
      const r = await V.api('api/sessions.php?id=' + t.dataset.history);
      document.getElementById('history-body').innerHTML = (r.errors || []).map((e) =>
        `<tr class="lvl-${V.esc(e.level)}"><td class="text-nowrap">${V.fmtDate(e.created_at)}</td><td>${V.esc(e.level)}</td><td>${V.esc(e.component)}</td><td>${V.esc(e.message)}</td></tr>`).join('') || '<tr><td>Aucun événement.</td></tr>';
      histModal.show();
    }
  });

  const form = document.getElementById('form-session');
  function openForm(s) {
    form.reset();
    document.getElementById('form-error').textContent = '';
    document.getElementById('modal-title').textContent = s ? 'Paramètres de la session' : 'Nouvelle session';
    if (s) {
      for (const el of form.elements) {
        if (!el.name || !(el.name in s)) continue;
        if (el.type === 'checkbox') el.checked = !!+s[el.name]; else el.value = s[el.name] ?? '';
      }
    } else form.elements.id.value = '';
    modal.show();
  }
  document.getElementById('btn-new')?.addEventListener('click', () => openForm(null));
  form.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const data = {};
    for (const el of form.elements) {
      if (!el.name) continue;
      data[el.name] = el.type === 'checkbox' ? el.checked : el.value;
    }
    data.op = data.id ? 'update' : 'create';
    const r = await V.api('api/sessions.php', data);
    if (r.ok) { modal.hide(); V.toast(data.op === 'create' ? 'Session créée. Cliquez sur « Démarrer » pour ouvrir le navigateur.' : 'Paramètres enregistrés.'); load(); }
    else document.getElementById('form-error').textContent = r.message || r.error;
  });
  document.getElementById('grid-cols').addEventListener('change', (e) => { cols = e.target.value; store('verifia.cols', cols); applyLayout(); });
  document.getElementById('show-closed').addEventListener('change', load);

  // Aperçu indisponible : on retire l'image (pas de gestionnaire en ligne, CSP stricte)
  box.addEventListener('error', (e) => { if (e.target.tagName === 'IMG') e.target.replaceWith(Object.assign(document.createElement('span'), { className: 'text-body-secondary small', textContent: 'Aperçu indisponible' })); }, true);

  applyLayout();
  load();
  setInterval(load, 3000);
})();
