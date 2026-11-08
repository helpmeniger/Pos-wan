/* POS Marwa — boutique en ligne : alerte « nouvelle commande » dans l'application (bulle + son).
   Vérifie toutes les 45 s ; s'arrête aussitôt pour un utilisateur sans droit sur les commandes. */
(function () {
  'use strict';
  const baseMeta = document.querySelector('meta[name="base-url"]');
  if (!baseMeta || window.PMShopAlert) return;
  window.PMShopAlert = true;
  const base = baseMeta.content.replace(/\/$/, '');
  const KEY = 'pm-shop-last-seen';
  let pill = null, timer = null;

  function beep() {
    try {
      const AC = window.AudioContext || window.webkitAudioContext; if (!AC) return;
      const ctx = new AC(); const o = ctx.createOscillator(); const g = ctx.createGain();
      o.type = 'sine'; o.frequency.setValueAtTime(880, ctx.currentTime); o.frequency.setValueAtTime(1320, ctx.currentTime + 0.15);
      g.gain.setValueAtTime(0.0001, ctx.currentTime); g.gain.exponentialRampToValueAtTime(0.25, ctx.currentTime + 0.02); g.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 0.45);
      o.connect(g); g.connect(ctx.destination); o.start(); o.stop(ctx.currentTime + 0.5);
    } catch (e) { /* son indisponible */ }
  }
  function show(n) {
    if (!pill) {
      pill = document.createElement('a');
      pill.href = base + '/boutique-admin?f=new';
      pill.className = 'btn btn-danger shadow rounded-pill d-print-none';
      Object.assign(pill.style, { position: 'fixed', left: '1rem', bottom: '1rem', zIndex: '1080', fontWeight: '600' });
      document.body.appendChild(pill);
    }
    pill.innerHTML = '<i class="bi bi-bag-check"></i> ' + n + ' nouvelle' + (n > 1 ? 's' : '') + ' commande' + (n > 1 ? 's' : '') + ' en ligne';
    pill.hidden = n === 0;
  }
  async function check() {
    try {
      const r = await fetch(base + '/boutique-admin/feed', { headers: { Accept: 'application/json' }, cache: 'no-store', credentials: 'same-origin', pmNoProgress: true });
      if (r.status === 401 || r.status === 403 || r.status === 404) { clearInterval(timer); return; }
      const d = await r.json();
      if (!d.ok) return;
      show(d.new);
      let seen = 0; try { seen = Number(localStorage.getItem(KEY) || 0); } catch (e) { /* rien */ }
      if (d.last > seen) { if (seen > 0) beep(); try { localStorage.setItem(KEY, String(d.last)); } catch (e) { /* rien */ } }
    } catch (e) { /* hors connexion : nouvel essai au prochain passage */ }
  }
  check();
  timer = setInterval(check, 45000);
})();
