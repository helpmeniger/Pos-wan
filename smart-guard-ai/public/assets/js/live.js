/* Surveillance en direct : rafraîchissement des images via PHP (droits vérifiés côté serveur) */
(function () {
  'use strict';
  let paused = false;
  const overlay = document.getElementById('chkOverlay');
  const tiles = [...document.querySelectorAll('.cam-col')];

  function refresh(col) {
    if (paused) return setTimeout(() => refresh(col), 1000);
    const id = col.dataset.id;
    const img = col.querySelector('img');
    const off = col.querySelector('.cam-offline');
    const next = new Image();
    const t0 = Date.now();
    next.onload = () => { img.src = next.src; off.classList.add('d-none'); setTimeout(() => refresh(col), Math.max(150, 500 - (Date.now() - t0))); };
    next.onerror = () => { off.classList.remove('d-none'); setTimeout(() => refresh(col), 3000); };
    next.src = sgUrl(`live/${id}/frame?overlay=${overlay.checked ? 1 : 0}&t=${t0}`);
  }
  async function stats(col) {
    try {
      const d = await sgFetch(`live/${col.dataset.id}/state`);
      const s = d.state;
      col.querySelector('.cam-info').textContent = s.status === 'arretee' ? 'arrêtée' : `${s.fps_in} img/s · ${s.tracked_objects} objet(s) suivi(s)`;
      col.querySelector('.cam-stats').textContent = s.counters ? `Session : ${s.counters.sortie} sortie(s), ${s.counters.entree} entrée(s) · ${s.proc_ms} ms` : '';
    } catch (e) { col.querySelector('.cam-info').textContent = 'service IA indisponible'; }
  }
  tiles.forEach((col) => {
    refresh(col);
    stats(col);
    setInterval(() => !paused && stats(col), 3000);
    col.querySelector('.chk-analysis')?.addEventListener('change', async (e) => {
      try { await sgFetch(`live/${col.dataset.id}/analysis`, { json: { enabled: e.target.checked ? 1 : 0 } }); }
      catch (err) { alert(err.message); e.target.checked = !e.target.checked; }
    });
    col.querySelector('.btn-capture').addEventListener('click', async () => {
      try { const d = await sgFetch(`live/${col.dataset.id}/capture`, { method: 'POST' }); window.open(d.url, '_blank'); }
      catch (err) { alert(err.message); }
    });
  });
  document.getElementById('btnPause').addEventListener('click', (e) => {
    paused = !paused;
    e.currentTarget.innerHTML = paused ? '<i class="bi bi-play-fill"></i> Reprendre l\'affichage' : '<i class="bi bi-pause-fill"></i> Pause de l\'affichage';
  });
  document.getElementById('gridSize').addEventListener('change', (e) => {
    const cls = { 1: 'col-12', 2: 'col-md-6', 3: 'col-md-6 col-xl-4' }[e.target.value];
    tiles.forEach((c) => { c.className = cls + ' cam-col'; });
  });
})();
