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
  const progress = $('#cdProgress');
  const dots = $('#cdDots');
  const EFFECTS = ['kenburns', 'fade', 'slide', 'zoom', 'blur', 'wipe'];
  const reduced = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
  let list = LIST.slice();
  const failed = new Set();
  let mode = 'idle';
  let idx = -1;
  let timer = null;
  let current = null;
  let hasMedia = false;
  let lastFx = '';
  let seq = 0;

  // ------------------------------------------------------------------ diffusion images / vidéos
  /**
   * Chaque fichier est entièrement chargé AVANT d'apparaître (avant : l'image s'affichait pendant
   * son téléchargement et l'ancienne disparaissait → écran noir avec des fichiers lourds ou un
   * réseau lent). Un fichier en erreur est sauté ; sans aucun fichier lisible, l'écran d'accueil
   * (logo, nom, message) remplace le noir.
   */
  function load(m) {
    return new Promise((resolve, reject) => {
      const t = setTimeout(() => reject(new Error('délai')), 45000);
      const done = (el) => { clearTimeout(t); resolve(el); };
      const fail = () => { clearTimeout(t); reject(new Error('illisible')); };
      if (m.kind === 'video') {
        const v = document.createElement('video');
        v.muted = !CFG.sound; v.playsInline = true; v.preload = 'auto';
        v.setAttribute('playsinline', ''); v.setAttribute('muted', '');
        v.addEventListener('canplay', () => done(v), { once: true });
        v.addEventListener('error', fail, { once: true });
        v.src = m.url;
        v.load();
      } else {
        const img = new Image();
        img.decoding = 'async';
        img.alt = m.title || '';
        img.src = m.url;
        (img.decode ? img.decode() : new Promise((r, j) => { img.onload = r; img.onerror = j; }))
          .then(() => (img.naturalWidth > 0 ? done(img) : fail()), fail);
      }
    });
  }

  function effect() {
    let fx = CFG.transition || 'kenburns';
    if (reduced) return 'fade';
    if (fx === 'random') {
      const pool = EFFECTS.filter((e) => e !== lastFx);
      fx = pool[Math.floor(Math.random() * pool.length)];
    }
    return (lastFx = fx);
  }

  function build(m, el) {
    const slide = document.createElement('div');
    const fx = effect();
    const fill = m.kind === 'video' && CFG.fill === 'blur' ? 'theme' : (CFG.fill || 'blur');
    slide.className = `cd-slide fx-${fx} fill-${fill}`;
    slide.style.setProperty('--dur', Math.max(3, m.seconds || 8) + 's');
    // Ken Burns : direction du mouvement variée d'une image à l'autre
    const dir = [[-2, -1.5], [2, -1.5], [-2, 1.5], [2, 1.5], [0, -2]][Math.floor(Math.random() * 5)];
    slide.style.setProperty('--kx', dir[0] + '%');
    slide.style.setProperty('--ky', dir[1] + '%');
    if (fill === 'blur') {
      const bg = document.createElement('img');
      bg.className = 'cd-bg'; bg.src = m.url; bg.alt = ''; bg.setAttribute('aria-hidden', 'true');
      slide.appendChild(bg);
    }
    el.classList.add('cd-item');
    slide.appendChild(el);
    if (CFG.captions && m.title) {
      const c = document.createElement('div');
      c.className = 'cd-caption';
      c.textContent = m.title;
      slide.appendChild(c);
    }
    return slide;
  }

  function playable() {
    return list.filter((m) => !failed.has(m.url));
  }

  function drawDots(active) {
    if (!dots) return;
    const ok = playable();
    dots.classList.toggle('d-none', !CFG.progress || ok.length < 2);
    dots.innerHTML = ok.map((m) => `<i class="${m.url === active ? 'on' : ''}"></i>`).join('');
  }

  function runProgress(seconds) {
    if (!progress) return;
    progress.classList.toggle('d-none', !CFG.progress || seconds <= 0);
    progress.style.transition = 'none';
    progress.style.transform = 'scaleX(0)';
    void progress.offsetWidth; // redémarre l'animation
    if (seconds > 0) {
      progress.style.transition = `transform ${seconds}s linear`;
      progress.style.transform = 'scaleX(1)';
    }
  }

  function empty() {
    clearTimeout(timer);
    hasMedia = false;
    media.querySelectorAll('.cd-slide').forEach((s) => { const v = s.querySelector('video'); if (v) v.pause(); s.remove(); });
    current = null;
    document.body.classList.add('no-media');
    runProgress(0);
    drawDots('');
    updateWelcome();
  }

  function present(slide, m, el) {
    const old = current;
    current = slide;
    hasMedia = true;
    document.body.classList.remove('no-media');
    updateWelcome();
    media.appendChild(slide);
    void slide.offsetWidth; // l'état de départ de l'effet est appliqué avant la transition
    slide.classList.add('in');
    if (old) {
      old.classList.add('out');
      setTimeout(() => { const v = old.querySelector('video'); if (v) v.pause(); old.remove(); }, 1600);
    }
    const alone = playable().length === 1;
    drawDots(m.url);
    if (m.kind === 'video') {
      el.loop = alone;
      el.addEventListener('ended', () => next(), { once: true });
      el.addEventListener('error', () => { failed.add(m.url); next(); }, { once: true });
      el.addEventListener('timeupdate', () => {
        if (progress && CFG.progress && el.duration) { progress.style.transition = 'none'; progress.style.transform = `scaleX(${el.currentTime / el.duration})`; }
      });
      progress && progress.classList.toggle('d-none', !CFG.progress);
      el.play().catch(() => { el.muted = true; el.play().catch(() => { timer = setTimeout(next, 3000); }); });
    } else {
      if (alone) { slide.classList.add('alone'); runProgress(0); return; }
      runProgress(Math.max(3, m.seconds));
      timer = setTimeout(next, Math.max(3, m.seconds) * 1000);
    }
  }

  async function next() {
    clearTimeout(timer);
    const my = ++seq;
    for (let tries = 0; tries < list.length; tries++) {
      if (!playable().length) break;
      idx = (idx + 1) % list.length;
      const m = list[idx];
      if (failed.has(m.url)) continue;
      // Une seule image déjà affichée : rien à recharger
      if (current && playable().length === 1 && current.dataset.url === m.url) return;
      try {
        const el = await load(m);
        if (my !== seq) return; // une liste plus récente a pris la main
        const slide = build(m, el);
        slide.dataset.url = m.url;
        present(slide, m, el);
        return;
      } catch (e) {
        failed.add(m.url);
        if (window.console) console.warn('Écran client : fichier ignoré', m.url, e.message);
      }
    }
    if (!current || !playable().length) empty();
  }

  // Liste relue régulièrement : un fichier ajouté ou retiré dans les paramètres est pris en
  // compte sans rouvrir l'écran ; les fichiers en échec sont réessayés (réseau revenu…).
  const playlistUrl = location.pathname.replace(/\/afficheur\/?$/, '') + '/afficheur/playlist';
  async function refresh() {
    try {
      const r = await fetch(playlistUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin', cache: 'no-store' });
      if (!r.ok) return;
      const d = await r.json();
      if (!d.ok) return;
      Object.assign(CFG, { transition: d.cfg.transition, fill: d.cfg.fill, captions: d.cfg.captions, progress: d.cfg.progress, sound: d.cfg.sound });
      const changed = JSON.stringify(d.playlist.map((m) => [m.url, m.seconds, m.title])) !== JSON.stringify(list.map((m) => [m.url, m.seconds, m.title]));
      const retry = failed.size > 0;
      failed.clear();
      if (changed) { list = d.playlist; idx = -1; }
      if (changed || (retry && !hasMedia)) next();
      else drawDots(current ? current.dataset.url : '');
    } catch (e) { /* hors connexion : on garde la liste actuelle */ }
  }
  setInterval(refresh, 90000);

  function updateWelcome() {
    $('#cdWelcome').classList.toggle('d-none', mode !== 'idle' || hasMedia);
  }

  // ------------------------------------------------------------------ affichage du panier
  function show(state) {
    if (!state || !state.mode) return;
    mode = state.mode;
    document.body.dataset.mode = mode;
    updateWelcome();
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

  if (list.length) next(); else empty();
})();
