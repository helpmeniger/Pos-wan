'use strict';
(() => {
  const pd = V.pageData();
  const list = document.getElementById('alert-list');
  const filters = document.getElementById('filters');
  const store = (k, v) => { try { if (v === undefined) return localStorage.getItem(k); localStorage.setItem(k, v); } catch (e) { return null; } };
  const sound = document.getElementById('opt-sound');
  const desktop = document.getElementById('opt-desktop');
  sound.checked = store('verifia.sound') === '1';
  desktop.checked = store('verifia.desktop') === '1' && 'Notification' in window && Notification.permission === 'granted';
  let offset = 0;
  let maxId = 0;
  const baseTitle = document.title;

  // Pré-remplissage des filtres depuis l'URL (ex. alerts.php?session_id=3)
  const qs = new URLSearchParams(location.search);
  for (const el of filters.elements) if (el.name && qs.get(el.name)) el.value = qs.get(el.name);

  const params = () => { const p = new URLSearchParams(); for (const el of filters.elements) if (el.name && el.value) p.set(el.name, el.value); return p; };
  const matches = (a) => {
    const p = params();
    if (p.get('priority') && a.priority !== p.get('priority')) return false;
    if (p.get('status') ? a.status !== p.get('status') : a.status === 'ignored') return false;
    if (p.get('category') && a.category !== p.get('category')) return false;
    if (p.get('site') && a.site_name !== p.get('site')) return false;
    if (p.get('session_id') && String(a.session_id) !== p.get('session_id')) return false;
    if (p.get('q')) { const q = p.get('q').toLowerCase(); if (![a.excerpt, a.reason, a.site_name].some((x) => (x || '').toLowerCase().includes(q))) return false; }
    return true;
  };

  function item(a, fresh = false) {
    const thumb = a.thumbnail_path ? `<img class="thumb" src="media.php?f=${encodeURIComponent(a.thumbnail_path)}" alt="" loading="lazy">` : '<div class="thumb d-flex align-items-center justify-content-center small text-body-secondary">pas d’aperçu</div>';
    const act = (op, val, label, cls) => pd.canEdit ? `<button class="btn btn-outline-${cls}" data-op="${op}" data-val="${val}" data-id="${a.id}">${label}</button>` : '';
    const users = pd.canEdit ? `<select class="form-select form-select-sm d-inline-block w-auto" data-assign="${a.id}"><option value="">Affecter…</option>${pd.users.map((u) => `<option value="${u.id}" ${String(a.assigned_to) === String(u.id) ? 'selected' : ''}>${V.esc(u.display_name)}</option>`).join('')}</select>` : '';
    return `<div class="alert-item p-${V.esc(a.priority)} st-${V.esc(a.status)} ${fresh ? 'fresh' : ''}" data-alert="${a.id}">
      ${thumb}
      <div>
        <div class="a-title fw-semibold">${V.prioBadge(a.priority)} <span class="badge text-bg-dark border">${V.esc(pd.categories[a.category] || a.category)}</span>
          <a href="alert.php?id=${a.id}" class="ms-1">Alerte #${a.id}</a> — ${V.esc(a.reason)}</div>
        <div class="excerpt">${V.esc(a.excerpt)}</div>
        <div class="small text-body-secondary mt-1">${V.esc(a.site_name || '—')} · session « ${V.esc(a.session_name || '—')} »${a.session_available == 1 ? '' : ' (fermée ou arrêtée)'} · ${V.fmtDate(a.created_at)}
          · confiance ${Math.round(a.confidence * 100)} % · <b>${V.esc(pd.statuses[a.status])}</b>${a.assignee ? ' · affectée à ' + V.esc(a.assignee) : ''}
          ${a.permalink ? ` · <a href="${V.esc(a.permalink)}" target="_blank" rel="noopener noreferrer">lien de la publication</a>` : ''}</div>
      </div>
      <div class="actions d-flex flex-column gap-1 align-items-end">
        <a class="btn btn-sm btn-outline-light" href="alert.php?id=${a.id}&window=1" data-popup="1" data-popup-name="verifia_report_${a.id}">Fiche détaillée</a>
        <a class="btn btn-sm btn-outline-light" href="report.php?id=${a.id}">PDF</a>
        <div class="d-flex gap-1">${act('status', 'read', 'Lue', 'secondary')}${act('status', 'verified', 'Vérifiée', 'success')}${act('status', 'ignored', 'Ignorer', 'secondary')}</div>
        ${users}
        ${pd.canEdit && a.session_available == 1 ? `<button class="btn btn-outline-warning" data-focus="${a.session_id}">Session source</button>` : ''}
      </div></div>`;
  }

  async function load(append = false) {
    const p = params();
    p.set('limit', 50); p.set('offset', append ? offset : 0);
    const r = await V.api('api/alerts.php?' + p);
    if (!r.items) return;
    offset = (append ? offset : 0) + r.items.length;
    if (!append) list.innerHTML = '';
    list.insertAdjacentHTML('beforeend', r.items.map((a) => item(a)).join(''));
    r.items.forEach((a) => { maxId = Math.max(maxId, +a.id); });
    document.getElementById('total').textContent = r.total;
    document.getElementById('btn-more').hidden = offset >= r.total;
    setUnread(r.unread);
  }

  function setUnread(n) {
    document.getElementById('unread-badge').textContent = n;
    document.title = (n ? `(${n}) ` : '') + baseTitle;
  }

  function beep(priority) {
    if (!sound.checked) return;
    try {
      const ctx = new (window.AudioContext || window.webkitAudioContext)();
      const o = ctx.createOscillator(), g = ctx.createGain();
      o.frequency.value = priority === 'critical' ? 880 : 660;
      g.gain.value = 0.08;
      o.connect(g); g.connect(ctx.destination);
      o.start(); o.stop(ctx.currentTime + (priority === 'critical' ? 0.5 : 0.2));
    } catch (e) { /* audio indisponible */ }
  }

  function onNewAlert(a) {
    if (+a.id <= maxId && document.querySelector(`[data-alert="${a.id}"]`)) return;
    maxId = Math.max(maxId, +a.id);
    if (matches(a)) {
      list.insertAdjacentHTML('afterbegin', item(a, true));
      document.getElementById('total').textContent = +document.getElementById('total').textContent + 1;
    }
    V.toast(`${V.prioBadge(a.priority)} <b>Nouvelle alerte #${a.id}</b><br>${V.esc((a.reason || '').slice(0, 140))}<br><span class="text-body-secondary">${V.esc(a.site_name || '')}</span>`,
      a.priority, () => V.openPopup(`alert.php?id=${a.id}&window=1`, 'verifia_report_' + a.id));
    beep(a.priority);
    if (desktop.checked && document.hidden && 'Notification' in window && Notification.permission === 'granted') {
      const n = new Notification(`VERIFIA — alerte ${V.PRIO[a.priority]?.[0] || ''} #${a.id}`, { body: (a.reason || '').slice(0, 180), tag: 'verifia-' + a.id, silent: !sound.checked });
      n.onclick = () => { window.focus(); location.href = `alert.php?id=${a.id}`; };
    }
  }

  // Temps réel : SSE ; repli sur une interrogation périodique si le flux échoue
  let pollTimer = null;
  function startPolling() {
    if (pollTimer) return;
    document.getElementById('live-status').className = 'badge text-bg-warning';
    document.getElementById('live-status').textContent = 'mise à jour périodique';
    pollTimer = setInterval(async () => {
      const r = await V.api('api/alerts.php?include_ignored=1&since_id=' + maxId);
      (r.items || []).reverse().forEach(onNewAlert);
      if (r.unread !== undefined) setUnread(r.unread);
    }, 4000);
  }
  function startStream() {
    if (!('EventSource' in window)) return startPolling();
    let failures = 0;
    const es = new EventSource('api/stream.php?since_id=' + maxId);
    es.addEventListener('open', () => { failures = 0; const s = document.getElementById('live-status'); s.className = 'badge text-bg-success'; s.textContent = 'temps réel'; });
    es.addEventListener('alert', (e) => onNewAlert(JSON.parse(e.data)));
    es.addEventListener('unread', (e) => setUnread(JSON.parse(e.data).unread));
    es.addEventListener('error', () => { if (++failures > 3) { es.close(); startPolling(); } });
  }

  filters.addEventListener('input', () => { clearTimeout(filters._t); filters._t = setTimeout(() => load(), 300); });
  filters.addEventListener('reset', () => setTimeout(() => load(), 0));
  document.getElementById('btn-more').addEventListener('click', () => load(true));
  sound.addEventListener('change', () => { store('verifia.sound', sound.checked ? '1' : '0'); if (sound.checked) beep('high'); });
  desktop.addEventListener('change', async () => {
    if (desktop.checked && 'Notification' in window && Notification.permission !== 'granted') {
      desktop.checked = (await Notification.requestPermission()) === 'granted';
    }
    store('verifia.desktop', desktop.checked ? '1' : '0');
  });
  document.getElementById('btn-read-all')?.addEventListener('click', async () => { await V.api('api/alerts.php', { op: 'mark_all_read' }); load(); });

  list.addEventListener('click', async (ev) => {
    const b = ev.target.closest('[data-op],[data-focus]');
    if (!b) return;
    if (b.dataset.focus) {
      const r = await V.api('api/sessions.php', { op: 'action', id: +b.dataset.focus, action: 'focus' });
      V.toast(V.esc(r.message), r.ok ? '' : 'error');
      return;
    }
    const r = await V.api('api/alerts.php', { op: b.dataset.op, id: +b.dataset.id, status: b.dataset.val });
    if (r.ok) load(); else V.toast(V.esc(r.message), 'error');
  });
  list.addEventListener('change', async (ev) => {
    const s = ev.target.closest('[data-assign]');
    if (!s) return;
    const r = await V.api('api/alerts.php', { op: 'assign', id: +s.dataset.assign, user_id: s.value ? +s.value : null });
    V.toast(r.ok ? 'Alerte affectée.' : V.esc(r.message), r.ok ? '' : 'error');
    load();
  });

  load().then(startStream);
})();
