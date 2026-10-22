/*
 * MARWA REMOTE ASSIST — bandeau permanent pendant une assistance à distance (toutes les pages,
 * caisse comprise). Une requête légère toutes les 20 s ; si elle échoue (droit absent, réseau),
 * rien n'est affiché et la page continue de fonctionner normalement.
 */
(function () {
  'use strict';
  const $ = (s, r = document) => r.querySelector(s);
  const BASE = (($('meta[name="base-url"]') || {}).content || '/').replace(/\/$/, '');
  const CSRF = () => (($('meta[name="csrf-token"]') || {}).content || '');
  let el = null, timer = null, state = null;
  const fmt = (s) => { s = Math.max(0, Math.round(s)); return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0'); };

  function render() {
    const a = state && state.active && state.active[0];
    if (!a) { if (el) { el.remove(); el = null; } return; }
    if (!el) {
      el = document.createElement('div');
      el.className = 'pm-assist d-print-none';
      el.setAttribute('role', 'status');
      const dock = document.querySelector('.head-icons');
      if (dock) { el.classList.add('docked'); dock.insertBefore(el, dock.firstChild); } else document.body.appendChild(el);
    }
    const ctrl = a.status === 'controle';
    el.classList.toggle('control', ctrl);
    const left = ctrl && a.seconds_left !== null ? a.seconds_left - (Date.now() - state.at) / 1000 : null;
    const txt = a.status === 'demandee' ? 'Assistance demandée' : ctrl ? 'CONTRÔLE À DISTANCE · ' + fmt(left) : 'Assistance : écran observé';
    el.innerHTML = '<a href="' + BASE + '/assist" title="Assistance à distance n° ' + a.id + ' — ouvrir"><i class="bi bi-headset"></i> <span>' + txt + '</span></a>'
      + '<button type="button" data-assist-end title="Terminer l\'assistance"><i class="bi bi-x-lg"></i></button>';
    el.querySelector('[data-assist-end]').addEventListener('click', async () => {
      if (!confirm('Terminer l\'assistance à distance ? Fermez aussi la fenêtre RustDesk (Déconnecter).')) return;
      await fetch(BASE + '/assist/' + a.id + '/terminer.json', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': CSRF() }, body: JSON.stringify({ _csrf: CSRF() }) }).catch(() => null);
      poll();
    });
  }
  async function poll() {
    try {
      const r = await fetch(BASE + '/assist/etat', { headers: { Accept: 'application/json' }, credentials: 'same-origin', cache: 'no-store' });
      if (r.status === 401 || r.status === 403 || r.status === 404) { clearInterval(timer); return; } // pas de droit : silence
      const d = await r.json();
      if (d.ok) { state = Object.assign(d, { at: Date.now() }); render(); }
    } catch (e) { /* réseau : on garde l'affichage et on réessaie */ }
  }
  poll();
  timer = setInterval(poll, 20000);
  setInterval(() => { if (state && state.active && state.active[0] && state.active[0].status === 'controle') render(); }, 1000);
})();
