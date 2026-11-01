/* =====================================================================
   POS Marwa — écran client (second écran). Reçoit le panier de la caisse ouverte dans le
   même navigateur ; au repos, diffuse les images et vidéos choisies dans les paramètres.
   ===================================================================== */
(function () {
  'use strict';
  const $ = (s) => document.querySelector(s);
  const { cfg: CFG, playlist: LIST } = JSON.parse($('#cd-config').textContent);
  const fmt = (n) => (window.PWMoney ? PWMoney.fmt(n) : Math.round(n) + ' F');
  const fmtQ = (q) => (Math.round(q * 1000) / 1000).toLocaleString('fr-FR');
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const media = $('#cdMedia');
  let mode = 'idle';
  let idx = -1;
  let timer = null;

  // ------------------------------------------------------------------ diffusion images / vidéos
  function next() {
    clearTimeout(timer);
    if (!LIST.length) { media.innerHTML = ''; document.body.classList.add('no-media'); return; }
    idx = (idx + 1) % LIST.length;
    const m = LIST[idx];
    const el = document.createElement(m.kind === 'video' ? 'video' : 'img');
    el.className = 'cd-item';
    el.src = m.url;
    if (m.kind === 'video') {
      el.muted = !CFG.sound; el.autoplay = true; el.playsInline = true;
      el.addEventListener('ended', next);
      el.addEventListener('error', () => { timer = setTimeout(next, 1000); });
      // Une seule vidéo : elle tourne en boucle
      if (LIST.length === 1) el.loop = true;
      el.play().catch(() => { el.muted = true; el.play().catch(() => { timer = setTimeout(next, 3000); }); });
    } else {
      el.alt = m.title || '';
      el.addEventListener('error', () => { timer = setTimeout(next, 1000); });
      if (LIST.length > 1) timer = setTimeout(next, Math.max(3, m.seconds) * 1000);
    }
    const old = media.querySelector('.cd-item');
    media.appendChild(el);
    requestAnimationFrame(() => el.classList.add('show'));
    if (old) { old.classList.remove('show'); setTimeout(() => { if (old.tagName === 'VIDEO') old.pause(); old.remove(); }, 700); }
  }

  // ------------------------------------------------------------------ affichage du panier
  function show(state) {
    if (!state || !state.mode) return;
    mode = state.mode;
    document.body.dataset.mode = mode;
    $('#cdWelcome').classList.toggle('d-none', mode !== 'idle' || LIST.length > 0);
    $('#cdCart').classList.toggle('d-none', mode !== 'cart');
    $('#cdThanks').classList.toggle('d-none', mode !== 'thanks');
    if (mode === 'cart') {
      const lines = state.lines || [];
      $('#cdLines').innerHTML = CFG.showLines
        ? lines.map((l, i) => `<div class="cd-line${i === lines.length - 1 ? ' last' : ''}"><span class="n">${esc(l.name)}</span><span class="q">${fmtQ(l.qty)} × ${esc(fmt(l.price))}</span><span class="t">${esc(fmt(l.total))}</span></div>`).join('')
        : `<div class="cd-line last"><span class="n">${esc((lines[lines.length - 1] || {}).name || '')}</span><span class="t">${esc(fmt((lines[lines.length - 1] || {}).total || 0))}</span></div>`;
      const last = $('#cdLines .last'); if (last) last.scrollIntoView({ block: 'end' });
      $('#cdSum').innerHTML = `<span>${fmtQ(state.items || 0)} ${esc(window.__ ? __('article(s)') : 'article(s)')}</span>` + (state.discount > 0 ? `<span>${esc(window.__ ? __('Remises') : 'Remises')} − ${esc(fmt(state.discount))}</span>` : '');
      $('#cdTotal').textContent = fmt(state.total || 0);
      const paying = (state.tendered || 0) > 0;
      $('#cdPay').classList.toggle('d-none', !paying);
      if (paying) { $('#cdTendered').textContent = fmt(state.tendered); $('#cdChange').textContent = fmt(state.change || 0); }
    } else if (mode === 'thanks') {
      $('#thTotal').textContent = fmt(state.total || 0);
      $('#thPaid').textContent = fmt(state.paid || state.total || 0);
      $('#thChange').textContent = fmt(state.change || 0);
      $('.cd-change').classList.toggle('d-none', !(state.change > 0));
    }
  }

  // Réception : caisse du même navigateur (BroadcastChannel), sinon par le stockage local
  if ('BroadcastChannel' in window) {
    const ch = new BroadcastChannel('pm-customer-display');
    ch.onmessage = (e) => { if (e.data && e.data.type === 'state') show(e.data.state); };
    ch.postMessage({ type: 'hello' });
  }
  window.addEventListener('storage', (e) => { if (e.key === 'pm-display-state' && e.newValue) { try { show(JSON.parse(e.newValue)); } catch (x) { /* ignoré */ } } });
  try {
    const s = JSON.parse(localStorage.getItem('pm-display-state') || 'null');
    // Un panier de plus de 10 minutes n'est pas réaffiché (caisse fermée entre-temps)
    show(s && s.at && Date.now() - s.at < 600000 ? s : { mode: 'idle' });
  } catch (e) { show({ mode: 'idle' }); }

  // ------------------------------------------------------------------ plein écran
  const fs = $('#cdFs');
  const goFs = () => { const r = document.documentElement.requestFullscreen; if (r) document.documentElement.requestFullscreen().catch(() => {}); };
  fs.addEventListener('click', goFs);
  document.addEventListener('fullscreenchange', () => fs.classList.toggle('d-none', !!document.fullscreenElement));
  document.addEventListener('dblclick', goFs);
  setTimeout(() => fs.classList.add('d-none'), 20000); // l'invite disparaît ; double-toucher reste possible
  // Le son des vidéos n'est permis qu'après un geste sur cet écran
  document.addEventListener('click', () => { if (CFG.sound) document.querySelectorAll('video').forEach((v) => { v.muted = false; }); });

  next();
})();
