/* Éditeur de ligne virtuelle + test de connexion */
(function () {
  'use strict';
  const ed = document.getElementById('editor');
  if (!ed) return;
  const camId = ed.dataset.id;
  const img = document.getElementById('editorImg');
  const cv = document.getElementById('editorCanvas');
  const form = document.getElementById('lineForm');
  let pts = [];
  const lines = JSON.parse(ed.dataset.lines || '[]');
  if (lines[0]) pts = [[lines[0].x1, lines[0].y1], [lines[0].x2, lines[0].y2]];

  function dir() { return form ? form.exit_direction.value : (lines[0]?.exit_direction || 'gauche_vers_droite'); }
  function draw() {
    cv.width = img.clientWidth; cv.height = img.clientHeight;
    const c = cv.getContext('2d');
    c.clearRect(0, 0, cv.width, cv.height);
    c.strokeStyle = '#ff2d2d'; c.fillStyle = '#ff2d2d'; c.lineWidth = 3;
    pts.forEach(([x, y]) => { c.beginPath(); c.arc(x * cv.width, y * cv.height, 5, 0, 7); c.fill(); });
    if (pts.length < 2) return;
    const [a, b] = pts.map(([x, y]) => [x * cv.width, y * cv.height]);
    c.beginPath(); c.moveTo(a[0], a[1]); c.lineTo(b[0], b[1]); c.stroke();
    // même convention que le service IA : normale vers le côté « gauche » = (-dy, dx)
    const dx = b[0] - a[0], dy = b[1] - a[1], n = Math.hypot(dx, dy) || 1;
    const nx = -dy / n, ny = dx / n, s = dir() === 'gauche_vers_droite' ? -1 : 1;
    const mx = (a[0] + b[0]) / 2, my = (a[1] + b[1]) / 2, tx = mx + s * nx * 50, ty = my + s * ny * 50;
    c.beginPath(); c.moveTo(mx, my); c.lineTo(tx, ty); c.stroke();
    const ang = Math.atan2(ty - my, tx - mx);
    c.beginPath(); c.moveTo(tx, ty); c.lineTo(tx - 12 * Math.cos(ang - .5), ty - 12 * Math.sin(ang - .5)); c.lineTo(tx - 12 * Math.cos(ang + .5), ty - 12 * Math.sin(ang + .5)); c.fill();
    c.font = 'bold 13px sans-serif'; c.fillText('SORTIE', tx + 6, ty);
    c.fillStyle = '#fff'; c.fillText('A', a[0] + 6, a[1] - 6); c.fillText('B', b[0] + 6, b[1] - 6);
  }
  cv.addEventListener('click', (e) => {
    if (!form) return;
    const r = cv.getBoundingClientRect();
    const p = [Math.min(1, Math.max(0, (e.clientX - r.left) / r.width)), Math.min(1, Math.max(0, (e.clientY - r.top) / r.height))];
    pts = pts.length >= 2 ? [p] : pts.concat([p]);
    if (pts.length === 2) { ['x1', 'y1', 'x2', 'y2'].forEach((k, i) => { form[k].value = pts[i >> 1][i & 1].toFixed(5); }); }
    draw();
  });
  form?.exit_direction.addEventListener('change', draw);
  img.addEventListener('load', draw);
  window.addEventListener('resize', draw);

  // aperçu : image en direct (sans annotations) si la caméra est démarrée
  const probe = new Image();
  probe.onload = () => { if (probe.naturalWidth > 1) img.src = probe.src; };
  probe.src = sgUrl(`live/${camId}/frame?overlay=0&t=${Date.now()}`);

  form?.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (pts.length < 2) { alert('Cliquez deux points sur l\'image pour tracer la ligne.'); return; }
    const data = Object.fromEntries(new FormData(form).entries());
    try {
      const r = await sgFetch(`cameras/${camId}/lines`, { json: data });
      form.line_id.value = r.line_id;
      alert('Ligne enregistrée' + (r.pushed ? ' et appliquée à l\'analyse en cours.' : '. Elle sera appliquée au prochain démarrage de l\'analyse.'));
    } catch (err) { alert(err.message); }
  });

  document.getElementById('btnTest')?.addEventListener('click', async (e) => {
    const out = document.getElementById('testOut');
    out.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Test en cours (jusqu\'à 25 s)…';
    try {
      const d = await sgFetch(`cameras/${camId}/test`, { method: 'POST' });
      out.innerHTML = `<span class="text-success"><i class="bi bi-check-circle"></i> Connexion réussie : ${d.width}×${d.height}, ouverture en ${d.open_ms} ms${d.quality.issues.length ? ' — remarques : ' + sgEsc(d.quality.issues.join(', ')) : ''}</span>`;
      if (d.snapshot_jpeg_b64) img.src = 'data:image/jpeg;base64,' + d.snapshot_jpeg_b64;
    } catch (err) { out.innerHTML = `<span class="text-danger"><i class="bi bi-x-circle"></i> ${sgEsc(err.message)}</span>`; }
  });
  draw();
})();
