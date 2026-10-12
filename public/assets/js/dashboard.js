'use strict';
(() => {
  const nf = new Intl.NumberFormat('fr-FR');
  document.addEventListener('verifia:stats', (e) => render(e.detail));

  function render(o) {
    document.querySelectorAll('[data-stat]').forEach((el) => { el.textContent = nf.format(o[el.dataset.stat] ?? 0); });
    const q = o.queue;
    document.getElementById('queue').innerHTML =
      `En attente : <b>${q.pending}</b> · En cours : <b>${q.running}</b> · Terminées : <b>${q.done}</b> · Échouées : <b class="${q.failed ? 'text-danger' : ''}">${q.failed}</b>`;
    const c = o.capacity;
    const h = c.host;
    document.getElementById('capacity').innerHTML =
      `Sessions actives : <b>${c.active}</b> / limite <b>${c.limit}</b> (configurée : ${c.configured_max}${c.memory_limit !== null ? ', selon mémoire libre : ' + c.memory_limit : ''})<br>` +
      (h ? `Mémoire : <b>${nf.format(h.free_mem_mb)}</b> Mo libres sur ${nf.format(h.total_mem_mb)} Mo · Charge CPU : ${h.cpu_load ?? 'n/d'} · Navigateurs ouverts : ${h.open_browsers} · Mémoire du moteur : ${h.rss_mb} Mo`
         : '<span class="text-warning">Ressources inconnues : le moteur de surveillance ne répond pas.</span>') +
      `<br>Mémoire du processus web : ${o.server_memory_mb} Mo`;
    const s = o.services;
    const line = (k, label) => {
      const v = s[k];
      if (!v) return `<div>${label} : <span class="text-danger">jamais démarré</span></div>`;
      return `<div>${label} : <span class="text-${v.alive ? 'success' : 'danger'}">${v.alive ? 'actif' : 'arrêté'}</span> (PID ${V.esc(v.pid)}, dernier signal il y a ${v.age_s} s)</div>`;
    };
    document.getElementById('services').innerHTML = line('monitor', 'Moteur de surveillance (Playwright)') + line('worker', "Moteur d'analyse");
  }

  async function loadTimeline() {
    const r = await V.api('api/stats.php?timeline=1&events=1');
    if (!r.timeline) return;
    draw(r.timeline);
    document.getElementById('events').innerHTML = r.events.map((e) =>
      `<tr class="lvl-${V.esc(e.level)}"><td class="text-nowrap">${V.fmtDate(e.created_at)}</td><td>${V.esc(e.level)}</td><td>${V.esc(e.component)}</td><td>${V.esc(e.session_name || '')}</td><td>${V.esc(e.message)}</td></tr>`).join('');
    const banner = document.getElementById('emergency-banner');
    const sr = await V.api('api/sessions.php');
    banner.hidden = !sr.emergency_stop;
  }

  function draw(t) {
    const cv = document.getElementById('chart');
    const dpr = window.devicePixelRatio || 1;
    const W = cv.clientWidth, H = 220;
    cv.width = W * dpr; cv.height = H * dpr;
    const g = cv.getContext('2d');
    g.scale(dpr, dpr);
    g.clearRect(0, 0, W, H);
    const all = [...t.series.publications, ...t.series.alerts, ...t.series.errors];
    const max = Math.max(1, ...all);
    const pad = { l: 34, r: 8, t: 8, b: 22 };
    const n = t.labels.length;
    const x = (i) => pad.l + (i * (W - pad.l - pad.r)) / Math.max(1, n - 1);
    const y = (v) => H - pad.b - (v * (H - pad.t - pad.b)) / max;
    g.strokeStyle = '#333'; g.fillStyle = '#888'; g.font = '10px sans-serif';
    for (let k = 0; k <= 4; k++) {
      const v = Math.round((max * k) / 4);
      g.beginPath(); g.moveTo(pad.l, y(v)); g.lineTo(W - pad.r, y(v)); g.stroke();
      g.fillText(String(v), 2, y(v) + 3);
    }
    t.labels.forEach((l, i) => { if (i % 4 === 0) g.fillText(new Date(l.replace(' ', 'T') + ':00Z').getHours() + 'h', x(i) - 6, H - 6); });
    const line = (data, color) => {
      g.strokeStyle = color; g.lineWidth = 2; g.beginPath();
      data.forEach((v, i) => (i ? g.lineTo(x(i), y(v)) : g.moveTo(x(i), y(v))));
      g.stroke();
    };
    line(t.series.publications, '#4dabf7');
    line(t.series.alerts, '#ff922b');
    line(t.series.errors, '#fa5252');
  }

  document.getElementById('btn-release')?.addEventListener('click', async () => {
    const r = await V.api('api/sessions.php', { op: 'emergency_release' });
    if (r.ok) { V.toast('Arrêt immédiat levé. Les sessions doivent être redémarrées manuellement.'); loadTimeline(); }
  });

  loadTimeline();
  setInterval(loadTimeline, 15000);
  window.addEventListener('resize', loadTimeline);
})();
