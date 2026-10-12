/* VERIFIA — utilitaires communs (aucun script en ligne : compatible avec la CSP) */
'use strict';
const V = (() => {
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';

  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  async function api(url, body = null) {
    const opt = { headers: { Accept: 'application/json' }, credentials: 'same-origin' };
    if (body !== null) {
      opt.method = 'POST';
      opt.headers['Content-Type'] = 'application/json';
      opt.headers['X-CSRF-Token'] = csrf;
      opt.body = JSON.stringify(body);
    }
    const r = await fetch(url, opt);
    let data = {};
    try { data = await r.json(); } catch (e) { /* réponse non JSON */ }
    if (r.status === 401) { location.href = 'login.php'; }
    if (!r.ok && !data.message) data.message = data.error || ('Erreur HTTP ' + r.status);
    data._status = r.status;
    return data;
  }

  function toast(html, cls = '', onClick = null, ttl = 7000) {
    const zone = document.getElementById('toast-zone');
    if (!zone) return;
    const el = document.createElement('div');
    el.className = 'vtoast ' + cls;
    el.innerHTML = html;
    if (onClick) el.addEventListener('click', onClick);
    zone.appendChild(el);
    setTimeout(() => el.remove(), ttl);
  }

  const pageData = () => {
    const el = document.getElementById('page-data');
    return el ? JSON.parse(el.textContent) : {};
  };

  function fmtDate(utc) {
    if (!utc) return '—';
    const d = new Date(utc.replace(' ', 'T') + 'Z');
    return d.toLocaleString('fr-FR');
  }

  const PRIO = { low: ['Faible', 'secondary'], normal: ['Normale', 'info'], high: ['Élevée', 'warning'], critical: ['Critique', 'danger'] };
  const prioBadge = (p) => `<span class="badge text-bg-${(PRIO[p] || ['', 'secondary'])[1]}">${esc((PRIO[p] || [p])[0])}</span>`;

  // Ouverture de vues dans des fenêtres séparées (multi-écrans)
  function openPopup(url, name, rect) {
    const f = rect ? `popup=yes,left=${rect.left},top=${rect.top},width=${rect.width},height=${rect.height}` : 'popup=yes,width=1200,height=850';
    const w = window.open(url, name, f);
    if (!w) toast('Le navigateur a bloqué l’ouverture de la fenêtre. Autorisez les fenêtres surgissantes pour ce site.', 'error');
    return w;
  }
  document.addEventListener('click', (ev) => {
    const a = ev.target.closest('[data-popup]');
    if (a) { ev.preventDefault(); openPopup(a.getAttribute('href'), a.dataset.popupName || '_blank'); }
  });

  // Arrêt immédiat
  document.getElementById('btn-emergency')?.addEventListener('click', async () => {
    if (!confirm('Arrêter immédiatement TOUTES les sessions de surveillance ?')) return;
    const r = await api('api/sessions.php', { op: 'emergency_stop' });
    toast(r.ok ? `Arrêt immédiat : ${r.stopped} session(s) arrêtée(s).` : esc(r.message), r.ok ? 'critical' : 'error');
  });

  // Indicateurs de la barre de navigation (non lues, état des services)
  let baseTitle = document.title;
  async function navStatus() {
    if (!document.getElementById('nav-unread') && !document.getElementById('monitor-status')) return;
    const r = await api('api/stats.php');
    if (!r.overview) return;
    const o = r.overview;
    const b = document.getElementById('nav-unread');
    if (b) { b.textContent = o.alerts_unread; b.hidden = !o.alerts_unread; }
    document.title = (o.alerts_unread ? `(${o.alerts_unread}) ` : '') + baseTitle;
    const svc = (id, key, label) => {
      const el = document.getElementById(id);
      if (!el) return;
      const s = o.services[key];
      const alive = s && s.alive;
      el.className = 'badge me-2 text-bg-' + (alive ? 'success' : 'danger');
      el.textContent = label + (alive ? ' actif' : ' arrêté');
      el.title = alive ? `Dernier signal il y a ${s.age_s} s` : 'Aucun signal récent : lancez start.bat';
    };
    svc('monitor-status', 'monitor', 'moteur');
    svc('worker-status', 'worker', 'analyse');
    document.dispatchEvent(new CustomEvent('verifia:stats', { detail: o }));
  }
  if (csrf) { navStatus(); setInterval(navStatus, 5000); }

  return { api, esc, toast, pageData, fmtDate, prioBadge, openPopup, PRIO };
})();
