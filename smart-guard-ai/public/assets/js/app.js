/* SMART GUARD AI — scripts communs */
(function () {
  'use strict';
  const meta = (n) => document.querySelector(`meta[name="${n}"]`)?.content || '';
  const BASE = meta('base-url').replace(/\/$/, '');

  /** Requête AJAX avec jeton CSRF ; renvoie le JSON (lève une erreur lisible sinon). */
  window.sgFetch = async function (path, opts = {}) {
    const o = Object.assign({ headers: {} }, opts);
    o.headers['X-CSRF-Token'] = meta('csrf-token');
    o.headers['Accept'] = 'application/json';
    o.headers['X-Requested-With'] = 'XMLHttpRequest';
    if (o.json !== undefined) { o.body = JSON.stringify(o.json); o.headers['Content-Type'] = 'application/json'; o.method = o.method || 'POST'; }
    o.credentials = 'same-origin';
    const r = await fetch(BASE + '/' + path.replace(/^\//, ''), o);
    let data = null;
    try { data = await r.json(); } catch (e) { /* réponse non JSON */ }
    if (!r.ok || (data && data.ok === false)) throw new Error((data && (data.error || data.message)) || ('Erreur HTTP ' + r.status));
    return data;
  };
  window.sgUrl = (p) => BASE + '/' + p.replace(/^\//, '');
  window.sgEsc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  document.getElementById('toggleSidebar')?.addEventListener('click', () => document.getElementById('sidebar').classList.toggle('show'));

  // Confirmation des actions sensibles
  document.addEventListener('submit', (e) => {
    const msg = e.target.dataset.confirm;
    if (msg && !confirm(msg)) e.preventDefault();
  });

  // Notifications (interrogation périodique, fonctionne hors Internet)
  const countEl = document.getElementById('notifCount');
  const listEl = document.getElementById('notifList');
  async function loadNotifs() {
    if (!countEl) return;
    try {
      const d = await sgFetch('notifications');
      countEl.textContent = d.count;
      countEl.classList.toggle('d-none', !d.count);
      listEl.innerHTML = d.items.length ? d.items.map((n) => `
        <a class="list-group-item list-group-item-action small" href="${n.link ? sgUrl(n.link) : '#'}" data-id="${n.id}">
          <span class="badge bg-${sgEsc(n.severity === 'danger' ? 'danger' : n.severity === 'warning' ? 'warning' : n.severity === 'success' ? 'success' : 'info')} me-1">&nbsp;</span>
          <strong>${sgEsc(n.title)}</strong><div class="text-muted">${sgEsc(n.created_at)}</div></a>`).join('')
        : '<div class="p-3 text-muted small">Aucune notification.</div>';
    } catch (e) { /* silencieux */ }
  }
  listEl?.addEventListener('click', (e) => {
    const a = e.target.closest('[data-id]');
    if (a) sgFetch('notifications/read', { json: { id: +a.dataset.id } }).catch(() => {});
  });
  document.getElementById('notifReadAll')?.addEventListener('click', async (e) => {
    e.preventDefault();
    await sgFetch('notifications/read', { json: {} }).catch(() => {});
    loadNotifs();
  });
  loadNotifs();
  setInterval(loadNotifs, 30000);
})();
