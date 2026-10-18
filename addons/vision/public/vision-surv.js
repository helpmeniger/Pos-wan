/*
 * Surveillance des articles : chaque caméra est analysée en continu sur ce poste.
 * Objets en mouvement → suivi → passage de la ligne rouge (ou présence dans la zone) →
 * vignette + reconnaissance de l'article → passage enregistré, comparé ensuite aux ventes.
 */
(function () {
  'use strict';
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
  const T = (s) => (window.__ ? __(s) : s);
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const dataEl = $('#surv-data');
  if (!dataEl || !window.PMTrack || !window.PMVision) return;
  const V = window.PMVision;
  const D = JSON.parse(dataEl.textContent);
  const SW = 160;            // largeur de l'image analysée (suffisante pour le mouvement, légère)
  const TICK = 120;          // ms entre deux analyses (caméras locales)
  const LABEL = { sortie: T('Sortie'), entree: T('Retour'), vu: T('Vu') };
  let running = false, wakeLock = null, recogReady = false;
  const queue = []; let busyQueue = false;

  // ------------------------------------------------------------------ reconnaissance (file d'attente)
  async function prepareRecognition() {
    const st = $('#survEngine');
    try {
      const idx = await V.loadIndex();
      if (!idx.n) { st.textContent = T('Aucun article appris : les passages seront comptés « non identifiés ». Apprenez les articles pour les reconnaître.'); return; }
      st.textContent = T('Chargement de l\'intelligence de reconnaissance…');
      await V.loadEngine();
      recogReady = true;
      st.textContent = `${T('Reconnaissance active')} (${idx.n} ${T('photos apprises')}, ${V.engine.backend === 'webgl' ? T('accélération graphique') : T('sans accélération graphique : plus lent')})`;
    } catch (e) { st.textContent = e.message; }
  }
  function enqueue(job) { queue.push(job); if (queue.length > 30) queue.shift(); runQueue(); }
  async function runQueue() {
    if (busyQueue) return; busyQueue = true;
    while (queue.length) {
      const j = queue.shift();
      let pid = null, score = null, margin = null;
      try {
        if (recogReady && V.IDX.n) {
          const e = await V.embed(j.canvas, { x: 0, y: 0, s: j.canvas.width });
          const res = V.match(e.f);
          if (res.length) { pid = res[0].pid; score = res[0].score; margin = res[0].score - (res[1] ? res[1].score : -1); }
        }
      } catch (err) { /* reconnaissance impossible : passage non identifié */ }
      const d = await V.api('/vision/events', { camera_id: j.cam.id, direction: j.type, product_id: pid, score, margin, thumb: j.thumb });
      let name = '';
      if (pid) { const [p] = await V.products([pid]).catch(() => []); name = p ? p.name : ''; }
      j.cam.showEvent(j.type, j.thumb, d.ok ? d.status : 'erreur', name, d.ok ? '' : d.error);
    }
    busyQueue = false;
  }

  // ------------------------------------------------------------------ une caméra
  class Cam {
    constructor(el, c) {
      this.el = el; this.c = c; this.id = c.id;
      this.video = $('video', el); this.ov = $('.surv-overlay', el); this.og = this.ov.getContext('2d');
      this.small = document.createElement('canvas'); this.sg = this.small.getContext('2d', { willReadFrequently: true });
      this.geom = Object.assign({ line: { x1: 0.5, y1: 0.05, x2: 0.5, y2: 0.95 }, invert: false }, c.geometry || {});
      this.mode = c.mode; this.draw = null; this.counts = { sortie: 0, entree: 0, vu: 0 }; this.blobs = [];
      this.stopper = null; this.timer = null;
      $('[data-invert]', el).addEventListener('click', () => { this.geom.invert = !this.geom.invert; this.dirty(); });
      $('[data-mode]', el).addEventListener('change', (e) => { this.mode = e.target.value; this.dirty(); });
      $$('[data-draw]', el).forEach((b) => b.addEventListener('click', () => { this.draw = { kind: b.dataset.draw, pts: [] }; this.msg(b.dataset.draw === 'ligne' ? T('Cliquez le 1er point de la ligne sur l\'image…') : T('Faites glisser un rectangle sur l\'image…')); }));
      $('[data-save]', el).addEventListener('click', () => this.saveGeometry());
      const dev = $('[data-device]', el);
      if (dev) dev.addEventListener('change', () => { try { localStorage.setItem('pm-surv-dev-' + this.id, dev.value); } catch (e) { /* */ } if (running) this.start(); });
      this.pointer();
      this.render();
    }
    msg(t, cls) { const m = $('[data-msg]', this.el); m.textContent = t || ''; m.className = cls || 'text-secondary'; }
    dot(state) { $('[data-dot]', this.el).className = 'surv-dot ' + state; }
    dirty() { $('[data-save]', this.el).classList.remove('d-none'); this.newTracker(); this.render(); }
    newTracker() {
      const vw = this.video.videoWidth || 640, vh = this.video.videoHeight || 480;
      this.small.width = SW; this.small.height = Math.max(40, Math.round(SW * vh / vw));
      this.tracker = PMTrack.create({ width: this.small.width, height: this.small.height, mode: this.mode, line: this.geom.line, invert: this.geom.invert, zone: this.geom.zone, sensitivity: this.c.sensitivity });
    }
    async saveGeometry() {
      const d = await V.api('/vision/cameras/' + this.id + '/geometry', { line: this.geom.line, invert: this.geom.invert, zone: this.geom.zone || null, mode: this.mode });
      if (d.ok) { $('[data-save]', this.el).classList.add('d-none'); this.msg(T('Tracé enregistré.'), 'text-success'); } else this.msg(d.error, 'text-danger');
    }
    async devices() {
      const sel = $('[data-device]', this.el); if (!sel || !navigator.mediaDevices) return [];
      const list = (await navigator.mediaDevices.enumerateDevices()).filter((d) => d.kind === 'videoinput');
      const keep = sel.value || (() => { try { return localStorage.getItem('pm-surv-dev-' + this.id) || ''; } catch (e) { return ''; } })();
      sel.innerHTML = `<option value="">${T('Caméra par défaut')}</option>` + list.map((d, i) => `<option value="${esc(d.deviceId)}">${esc(d.label || T('Caméra') + ' ' + (i + 1))}</option>`).join('');
      if (keep && list.some((d) => d.deviceId === keep)) sel.value = keep;
      else if (this.c.device_label) { const m = list.find((d) => d.label === this.c.device_label); if (m) sel.value = m.deviceId; }
      return list;
    }
    async start() {
      this.stop();
      $('[data-wait]', this.el).textContent = T('Connexion…'); $('[data-wait]', this.el).hidden = false;
      try {
        if (this.c.kind === 'ip') {
          const r = await V.ipStream(this.id, this.c.fps, (s) => this.dot(s === 'ok' ? 'on' : 'err'));
          this.stream = r.stream; this.stopper = r.stop;
        } else {
          if (!(window.isSecureContext && navigator.mediaDevices)) throw new Error(T('Caméra locale indisponible : ouvrez cette page sur le poste lui-même (localhost) ou en HTTPS.'));
          await this.devices();
          const sel = $('[data-device]', this.el);
          this.stream = await navigator.mediaDevices.getUserMedia({ video: Object.assign({ width: { ideal: 1280 }, height: { ideal: 720 } }, sel && sel.value ? { deviceId: { exact: sel.value } } : {}), audio: false });
          await this.devices(); // noms visibles après autorisation
        }
        this.video.srcObject = this.stream;
        await this.video.play().catch(() => {});
        for (let i = 0; i < 40 && !this.video.videoWidth; i++) await new Promise((r) => setTimeout(r, 100));
        $('[data-wait]', this.el).hidden = true;
        $('.surv-stage', this.el).style.aspectRatio = this.video.videoWidth + ' / ' + this.video.videoHeight;
        this.newTracker(); this.dot('on'); this.msg('');
        const period = this.c.kind === 'ip' ? Math.max(100, Math.round(1000 / this.c.fps)) : TICK;
        const loop = () => { if (!this.stream) return; this.analyse(); this.timer = setTimeout(loop, period); };
        loop();
      } catch (e) {
        this.dot('err'); $('[data-wait]', this.el).textContent = e.message || String(e); this.stream = null;
      }
    }
    stop() {
      clearTimeout(this.timer); this.timer = null;
      if (this.stopper) { this.stopper(); this.stopper = null; }
      if (this.stream) { this.stream.getTracks().forEach((t) => t.stop()); this.stream = null; }
      this.video.srcObject = null; this.dot(''); this.blobs = []; this.render();
      $('[data-wait]', this.el).textContent = T('Caméra arrêtée'); $('[data-wait]', this.el).hidden = false;
    }
    analyse() {
      const v = this.video; if (!v.videoWidth) return;
      if (!this.tracker || Math.abs(this.small.height - Math.round(SW * v.videoHeight / v.videoWidth)) > 1) this.newTracker();
      this.sg.drawImage(v, 0, 0, this.small.width, this.small.height);
      const px = this.sg.getImageData(0, 0, this.small.width, this.small.height).data;
      this.gray = this.gray && this.gray.length === this.small.width * this.small.height ? this.gray : new Uint8Array(this.small.width * this.small.height);
      const r = this.tracker.process(PMTrack.toGray(px, this.gray));
      this.blobs = r.blobs;
      for (const ev of r.events) this.event(ev);
      this.render();
    }
    /** Passage : vignette carrée autour de l'objet (pleine résolution) puis reconnaissance. */
    event(ev) {
      const v = this.video; const k = v.videoWidth / this.small.width;
      const cx = (ev.box.x + ev.box.w / 2) * k, cy = (ev.box.y + ev.box.h / 2) * k;
      const side = Math.min(Math.max(ev.box.w, ev.box.h) * k * 1.35, v.videoWidth, v.videoHeight);
      const x = Math.max(0, Math.min(v.videoWidth - side, cx - side / 2)), y = Math.max(0, Math.min(v.videoHeight - side, cy - side / 2));
      const crop = document.createElement('canvas'); crop.width = crop.height = Math.max(64, Math.min(448, Math.round(side)));
      crop.getContext('2d').drawImage(v, x, y, side, side, 0, 0, crop.width, crop.height);
      const thumb = V.thumbOf(crop, { x: 0, y: 0, s: crop.width });
      this.counts[ev.type]++; $(`[data-c="${ev.type}"]`, this.el).textContent = this.counts[ev.type];
      this.flash = Date.now();
      enqueue({ cam: this, type: ev.type, canvas: crop, thumb });
    }
    showEvent(type, thumb, status, name, err) {
      const box = $('[data-events]', this.el);
      if (box.querySelector('span.small')) box.innerHTML = '';
      const d = document.createElement('div');
      d.className = 'surv-ev ' + (status === 'identifie' ? 'ok' : status === 'erreur' ? 'bad' : 'unsure');
      d.title = err || '';
      d.innerHTML = `<img src="${esc(thumb)}" alt=""><span><b>${esc(LABEL[type])}</b> ${new Date().toLocaleTimeString('fr-FR')}<br>${esc(name || (status === 'erreur' ? T('non enregistré') : T('non identifié')))}${status === 'incertain' && name ? ' ?' : ''}</span>`;
      box.prepend(d);
      while (box.children.length > 8) box.lastChild.remove();
    }
    // Dessin : ligne rouge, sens de sortie, zone, objets suivis
    render() {
      const ov = this.ov, r = ov.getBoundingClientRect();
      if (!r.width) return;
      if (ov.width !== Math.round(r.width) || ov.height !== Math.round(r.height)) { ov.width = Math.round(r.width); ov.height = Math.round(r.height); }
      const g = this.og, W = ov.width, H = ov.height; g.clearRect(0, 0, W, H);
      if (this.flash && Date.now() - this.flash < 400) { g.fillStyle = 'rgba(220,53,69,.18)'; g.fillRect(0, 0, W, H); }
      const L = this.geom.line;
      if (this.mode === 'ligne' && L) {
        g.strokeStyle = '#e63946'; g.lineWidth = 4; g.beginPath(); g.moveTo(L.x1 * W, L.y1 * H); g.lineTo(L.x2 * W, L.y2 * H); g.stroke();
        const n = PMTrack.create({ width: W, height: H, line: L, invert: this.geom.invert }).exitNormal();
        const mx = (L.x1 + L.x2) / 2 * W, my = (L.y1 + L.y2) / 2 * H, a = 38;
        g.strokeStyle = g.fillStyle = '#e63946'; g.lineWidth = 3; g.beginPath(); g.moveTo(mx, my); g.lineTo(mx + n.x * a, my + n.y * a); g.stroke();
        g.font = 'bold 13px sans-serif'; g.fillText(T('SORTIE'), mx + n.x * (a + 8) - 22, my + n.y * (a + 8) + 4);
      }
      const Z = this.geom.zone;
      if (Z && (this.mode === 'zone' || this.draw)) { g.strokeStyle = '#0d6efd'; g.lineWidth = 3; g.setLineDash([8, 5]); g.strokeRect(Z.x * W, Z.y * H, Z.w * W, Z.h * H); g.setLineDash([]); }
      // L'image occupe toute la scène (même proportions) : coordonnées relatives directement transposables
      const kx = W / (this.small.width || W), ky = H / (this.small.height || H);
      g.strokeStyle = '#ffc107'; g.lineWidth = 2;
      for (const b of this.blobs || []) g.strokeRect(b.x * kx, b.y * ky, b.w * kx, b.h * ky);
    }
    pointer() {
      const rel = (e) => { const r = this.ov.getBoundingClientRect(); return { x: Math.max(0, Math.min(1, (e.clientX - r.left) / r.width)), y: Math.max(0, Math.min(1, (e.clientY - r.top) / r.height)) }; };
      this.ov.addEventListener('pointerdown', (e) => {
        if (!this.draw) return;
        const p = rel(e);
        if (this.draw.kind === 'ligne') {
          this.draw.pts.push(p);
          if (this.draw.pts.length === 1) this.msg(T('Cliquez le 2e point de la ligne…'));
          else { const [a, b] = this.draw.pts; this.geom.line = { x1: a.x, y1: a.y, x2: b.x, y2: b.y }; this.mode = 'ligne'; $('[data-mode]', this.el).value = 'ligne'; this.draw = null; this.msg(T('Ligne tracée : la flèche indique le sens « sortie » (bouton « Sens » pour l\'inverser).')); this.dirty(); }
        } else { this.draw.start = p; this.ov.setPointerCapture(e.pointerId); }
      });
      this.ov.addEventListener('pointermove', (e) => {
        if (!this.draw || this.draw.kind !== 'zone' || !this.draw.start) return;
        const a = this.draw.start, b = rel(e);
        this.geom.zone = { x: Math.min(a.x, b.x), y: Math.min(a.y, b.y), w: Math.abs(b.x - a.x), h: Math.abs(b.y - a.y) }; this.render();
      });
      this.ov.addEventListener('pointerup', () => {
        if (!this.draw || this.draw.kind !== 'zone' || !this.draw.start) return;
        this.draw = null;
        if (!this.geom.zone || this.geom.zone.w < 0.05 || this.geom.zone.h < 0.05) { this.msg(T('Zone trop petite.'), 'text-danger'); return; }
        this.mode = 'zone'; $('[data-mode]', this.el).value = 'zone'; this.msg(T('Zone tracée.')); this.dirty();
      });
      window.addEventListener('resize', () => this.render());
    }
  }

  const cams = D.cameras.map((c) => new Cam(document.querySelector(`.surv-cam[data-cam="${c.id}"]`), c));
  async function startAll() {
    running = true;
    $('#survStart').classList.add('d-none'); $('#survStop').classList.remove('d-none');
    try { if (navigator.wakeLock) wakeLock = await navigator.wakeLock.request('screen'); } catch (e) { /* non disponible */ }
    prepareRecognition();
    for (const c of cams) await c.start();
  }
  function stopAll() {
    running = false; cams.forEach((c) => c.stop());
    $('#survStart').classList.remove('d-none'); $('#survStop').classList.add('d-none');
    if (wakeLock) { wakeLock.release().catch(() => {}); wakeLock = null; }
  }
  $('#survStart').addEventListener('click', startAll);
  $('#survStop').addEventListener('click', stopAll);
  window.addEventListener('beforeunload', (e) => { if (running) { e.preventDefault(); e.returnValue = ''; } });
  document.addEventListener('visibilitychange', async () => { if (running && document.visibilityState === 'visible' && navigator.wakeLock && !wakeLock) { try { wakeLock = await navigator.wakeLock.request('screen'); } catch (e) { /* */ } } });

  // Compteurs du jour (toutes caméras), actualisés
  async function refresh() {
    const d = await V.api('/vision/surveillance/today').catch(() => ({}));
    if (d.ok) {
      Object.entries(d.totals).forEach(([k, v]) => { const el = document.querySelector(`[data-t="${k}"]`); if (el) el.textContent = typeof v === 'number' ? Math.round(v * 100) / 100 : v; });
      const by = {}; d.rows.forEach((r) => { by[r.camera_id] = by[r.camera_id] || {}; by[r.camera_id][r.direction] = +r.n; });
      cams.forEach((c) => ['sortie', 'entree', 'vu'].forEach((k) => { c.counts[k] = (by[c.id] || {})[k] || 0; $(`[data-c="${k}"]`, c.el).textContent = c.counts[k]; }));
    }
    setTimeout(refresh, 30000);
  }
  refresh();
  setInterval(() => cams.forEach((c) => c.render()), 500);
})();
