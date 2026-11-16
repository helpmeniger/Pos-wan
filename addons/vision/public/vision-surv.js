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
  const LABEL = { sortie: T('Sortie'), entree: T('Retour'), vu: T('Vu'), prise: T('Pris en rayon'), remis: T('Remis en rayon'), deplace: T('Déplacé sur le rayon'), depose: T('Posé hors de sa place') };
  const JOURNEY = { en_cours: T('suivi jusqu’à la caisse'), vu_caisse: T('vu en caisse, vente attendue'), encaisse: T('encaissé'), remis: T('suivi clos : remis'), alerte: T('vérification demandée') };
  const SHELF_STATE = { apprentissage: T('Apprentissage du rayon…'), mouvement: T('Mouvement devant le rayon…'), masque: T('Rayon caché : en attente'), calme: T('Rayon surveillé') };
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
        // Emplacement lié à un article : l'article est connu, pas besoin de reconnaissance
        const known = j.slot && j.slot.product_id && j.type !== 'depose';
        if (!known && j.type !== 'deplace' && recogReady && V.IDX.n) {
          const e = await V.embed(j.canvas, { x: 0, y: 0, s: j.canvas.width });
          const res = V.match(e.f);
          if (res.length) { pid = res[0].pid; score = res[0].score; margin = res[0].score - (res[1] ? res[1].score : -1); }
        }
      } catch (err) { /* reconnaissance impossible : passage non identifié */ }
      const d = await V.api('/vision/events', { camera_id: j.cam.id, direction: j.type, slot_id: j.slot ? j.slot.id : null, to_slot_id: j.to ? j.to.id : null, qty: j.qty || 1, product_id: pid, score, margin, thumb: j.thumb });
      let name = j.slot && j.slot.product && j.type !== 'depose' ? j.slot.product : '';
      if (!name && pid) { const [p] = await V.products([pid]).catch(() => []); name = p ? p.name : ''; }
      const extra = d.ok && d.journey ? JOURNEY[d.journey.status] || '' : '';
      const what = (j.qty > 1 ? j.qty + ' × ' : '') + (j.slot ? j.slot.label + (name ? ' · ' : '') : '') + name + (j.to ? ' → ' + j.to.label : '');
      j.cam.showEvent(j.type, j.thumb, d.ok ? d.status : 'erreur', what, d.ok ? '' : d.error, extra);
      if (d.ok && d.journey && d.journey.status === 'alerte' && window.PMControles) window.PMControles.refresh();
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
      this.mode = c.mode; this.draw = null; this.counts = { sortie: 0, entree: 0, vu: 0, prise: 0, remis: 0 }; this.blobs = [];
      this.role = c.role || 'passage'; this.slots = (c.slots || []).map((x) => Object.assign({}, x)); this.shelfOut = null;
      this.stopper = null; this.timer = null;
      const on = (sel, ev, fn) => { const b = $(sel, el); if (b) b.addEventListener(ev, fn); };
      on('[data-invert]', 'click', () => { this.geom.invert = !this.geom.invert; this.dirty(); });
      on('[data-mode]', 'change', (e) => { this.mode = e.target.value; this.dirty(); });
      $$('[data-draw]', el).forEach((b) => b.addEventListener('click', () => { this.draw = { kind: b.dataset.draw, pts: [] }; this.msg(b.dataset.draw === 'ligne' ? T('Cliquez le 1er point de la ligne sur l\'image…') : T('Faites glisser un rectangle sur l\'image…')); }));
      on('[data-save]', 'click', () => this.saveGeometry());
      on('[data-save-slots]', 'click', () => this.saveSlots());
      on('[data-full]', 'click', () => this.rememberFull());
      if (this.role === 'etagere') this.slotList();
      const dev = $('[data-device]', el);
      if (dev) dev.addEventListener('change', () => { try { localStorage.setItem('pm-surv-dev-' + this.id, dev.value); } catch (e) { /* */ } if (running) this.start(); });
      this.pointer();
      this.render();
    }
    msg(t, cls) { const m = $('[data-msg]', this.el); m.textContent = t || ''; m.className = cls || 'text-secondary'; }
    dot(state) { $('[data-dot]', this.el).className = 'surv-dot ' + state; }
    dirty() { const b = $('[data-save]', this.el); if (b) b.classList.remove('d-none'); this.newTracker(); this.render(); }
    newTracker() {
      const vw = this.video.videoWidth || 640, vh = this.video.videoHeight || 480;
      this.small.width = SW; this.small.height = Math.max(40, Math.round(SW * vh / vw));
      if (this.role === 'etagere') {
        // Seuls les emplacements enregistrés (avec un numéro) sont surveillés
        // Délais en temps réel, convertis en nombre d'images selon la cadence d'analyse de cette caméra
        const per = this.c.kind === 'ip' ? Math.max(100, Math.round(1000 / this.c.fps)) : TICK;
        const n = (ms) => Math.max(3, Math.round(ms / per));
        this.shelf = window.PMShelf ? PMShelf.create({ width: this.small.width, height: this.small.height, slots: this.slots.filter((x) => x.id), sensitivity: this.c.sensitivity,
          settleFrames: n(1500), learnFrames: n(1000), relearnFrames: n(20000), confirmFrames: n(5000) }) : null;
        this.sentCounts = '';
        const ref = this.loadFull();
        if (this.shelf && ref) this.shelf.restore(ref);
        this.tracker = null;
        return;
      }
      this.tracker = PMTrack.create({ width: this.small.width, height: this.small.height, mode: this.mode, line: this.geom.line, invert: this.geom.invert, zone: this.geom.zone, sensitivity: this.c.sensitivity });
    }
    // ---- étagère : état « rayon plein » gardé sur ce poste (image réduite en niveaux de gris)
    fullKey() { return 'pm-shelf-full-c-' + this.id + '-' + this.small.width + 'x' + this.small.height; }
    loadFull() {
      try { const b = localStorage.getItem(this.fullKey()); if (!b) return null; const bin = atob(b); const a = new Uint8Array(bin.length); for (let i = 0; i < bin.length; i++) a[i] = bin.charCodeAt(i); return a; } catch (e) { return null; }
    }
    rememberFull() {
      if (!this.shelf || !this.shelf.setFull()) { this.msg(T('Démarrez la surveillance et attendez que le rayon soit immobile.'), 'text-danger'); return; }
      try { const r = this.shelf.reference(); let bin = ''; for (let i = 0; i < r.length; i++) bin += String.fromCharCode(r[i]); localStorage.setItem(this.fullKey(), btoa(bin)); } catch (e) { /* stockage indisponible : gardé pour cette session */ }
      this.msg(T('Rayon plein mémorisé : un article qui revient à sa place sera compté « remis ».'), 'text-success');
    }
    // ---- étagère : liste des emplacements (nom, article attendu)
    slotList() {
      const box = $('[data-slots]', this.el); if (!box) return;
      if (!this.slots.length) { box.innerHTML = `<div class="small text-secondary">${esc(T('Aucun emplacement : cliquez « Tracer un emplacement » puis faites glisser un rectangle autour d\'un article sur l\'image.'))}</div>`; return; }
      box.innerHTML = this.slots.map((x, i) => `<div class="surv-slot" data-i="${i}"><span class="surv-slot-n">${i + 1}</span>
        <input class="form-control form-control-sm" data-k="label" maxlength="80" value="${esc(x.label || '')}" placeholder="${esc(T('Nom (ex. Coca 33 cl)'))}">
        <input class="form-control form-control-sm" data-k="product" list="survProducts" value="${esc(x.product || '')}" placeholder="${esc(T('Article attendu (facultatif)'))}">
        <label class="surv-slot-num" title="${esc(T('Nombre d\'articles visibles de face quand l\'emplacement est plein : ils seront comptés un par un'))}">${esc(T('Plein'))}
          <input type="number" min="1" max="30" class="form-control form-control-sm" data-k="units" value="${x.units || 1}"></label>
        <label class="surv-slot-num" title="${esc(T('Alerte « à réassortir » quand le nombre vu descend à ce seuil (vide : pas d\'alerte)'))}">${esc(T('Alerte si ≤'))}
          <input type="number" min="0" max="30" class="form-control form-control-sm" data-k="min" value="${x.min_count ?? ''}"></label>
        <span class="surv-slot-count" data-k="count">${x.seen_count != null ? esc(x.seen_count + ' / ' + (x.units || 1)) : ''}</span>
        <button type="button" class="btn btn-sm btn-link text-danger p-0" data-k="del" title="${esc(T('Retirer'))}"><i class="bi bi-x-lg"></i></button></div>`).join('');
      $$('.surv-slot', box).forEach((row) => {
        const x = this.slots[+row.dataset.i];
        $('[data-k="label"]', row).addEventListener('input', (e) => { x.label = e.target.value; this.slotsDirty(); });
        const prod = $('[data-k="product"]', row);
        prod.addEventListener('input', () => { x.product = prod.value; x.product_id = productIds[prod.value] || null; searchProducts(prod.value); this.slotsDirty(); });
        $('[data-k="units"]', row).addEventListener('input', (e) => { x.units = Math.max(1, Math.min(30, +e.target.value || 1)); this.slotsDirty(); });
        $('[data-k="min"]', row).addEventListener('input', (e) => { x.min_count = e.target.value === '' ? null : Math.max(0, +e.target.value); this.slotsDirty(); });
        $('[data-k="del"]', row).addEventListener('click', () => { this.slots.splice(+row.dataset.i, 1); this.slotList(); this.slotsDirty(); this.render(); });
      });
    }
    slotsDirty() { const b = $('[data-save-slots]', this.el); if (b) b.classList.remove('d-none'); }
    async saveSlots() {
      const bad = this.slots.find((x) => x.product && !x.product_id);
      if (bad) { this.msg(T('Article introuvable : choisissez-le dans la liste proposée, ou laissez le champ vide.') + ' (' + bad.product + ')', 'text-danger'); return; }
      const d = await V.api('/vision/cameras/' + this.id + '/slots', { slots: this.slots.map((x) => ({ id: x.id || null, label: x.label, product_id: x.product_id, units: x.units || 1, min_count: x.min_count ?? null, x: x.x, y: x.y, w: x.w, h: x.h })) });
      if (!d.ok) { this.msg(d.error, 'text-danger'); return; }
      this.slots = d.slots; this.slotList(); $('[data-save-slots]', this.el).classList.add('d-none');
      this.newTracker(); this.render();
      this.msg(T('Emplacements enregistrés : le rayon est réappris (quelques secondes d\'immobilité).'), 'text-success');
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
      if ((this.role === 'etagere' ? !this.shelf : !this.tracker) || Math.abs(this.small.height - Math.round(SW * v.videoHeight / v.videoWidth)) > 1) this.newTracker();
      this.sg.drawImage(v, 0, 0, this.small.width, this.small.height);
      const px = this.sg.getImageData(0, 0, this.small.width, this.small.height).data;
      this.gray = this.gray && this.gray.length === this.small.width * this.small.height ? this.gray : new Uint8Array(this.small.width * this.small.height);
      // Étagère : image en couleur (une main de même luminosité qu'un article se distingue par sa teinte)
      if (this.role === 'etagere') { this.analyseShelf(px); this.render(); return; }
      const gray = PMTrack.toGray(px, this.gray);
      const r = this.tracker.process(gray);
      this.blobs = r.blobs;
      for (const ev of r.events) this.event(ev);
      this.render();
    }
    /** Comptes d'articles vus à leur place : envoyés quand le rayon est calme et qu'ils ont changé (au plus toutes les 5 s). */
    async sendCounts(r) {
      if (r.state !== 'calme' || r.pending || (this.countsAt && Date.now() - this.countsAt < 5000)) return;
      const list = r.slots.filter((x) => x.id).map((x) => ({ id: x.id, count: x.count }));
      const key = JSON.stringify(list);
      if (!list.length || key === this.sentCounts) return;
      this.sentCounts = key; this.countsAt = Date.now();
      list.forEach((x) => { const sl = this.slots.find((y) => y.id === x.id); if (sl) sl.seen_count = x.count; });
      $$('.surv-slot', this.el).forEach((row) => { const sl = this.slots[+row.dataset.i]; const c = $('[data-k="count"]', row); if (sl && c && sl.seen_count != null) c.textContent = sl.seen_count + ' / ' + (sl.units || 1); });
      const d = await V.api('/vision/cameras/' + this.id + '/counts', { slots: list }).catch(() => null);
      if (d && d.ok && d.low && window.PMControles) window.PMControles.refresh();
    }
    /** Étagère : image « avant » gardée tant que le rayon est calme ; une prise montre l'article tel qu'il était. */
    analyseShelf(rgba) {
      if (!this.shelf) return;
      const r = this.shelf.process(rgba); this.shelfOut = r;
      const st = $('[data-shelf-state]', this.el); if (st) { st.textContent = SHELF_STATE[r.state] || ''; st.className = 'badge border ' + (r.state === 'calme' ? 'bg-light text-dark' : r.state === 'masque' ? 'bg-secondary' : 'bg-info text-dark'); }
      const v = this.video;
      for (const ev of r.events) {
        const slot = this.slots.find((x) => x.id === ev.slot); if (!slot) continue;
        // Vignette : l'article tel qu'il était avant d'être pris ou déplacé ; ce qui a été posé, tel qu'il est maintenant
        const src = (ev.type === 'prise' || ev.type === 'deplace') && this.before ? this.before : v;
        const k = v.videoWidth / this.small.width;
        const bx = slot.x * v.videoWidth, by = slot.y * v.videoHeight, bw = slot.w * v.videoWidth, bh = slot.h * v.videoHeight;
        const side = Math.min(Math.max(bw, bh, ev.box.w * k, ev.box.h * k) * 1.05, v.videoWidth, v.videoHeight);
        const cx = bx + bw / 2, cy = by + bh / 2;
        const x = Math.max(0, Math.min(v.videoWidth - side, cx - side / 2)), y = Math.max(0, Math.min(v.videoHeight - side, cy - side / 2));
        const crop = document.createElement('canvas'); crop.width = crop.height = Math.max(64, Math.min(448, Math.round(side)));
        crop.getContext('2d').drawImage(src, x, y, side, side, 0, 0, crop.width, crop.height);
        const thumb = V.thumbOf(crop, { x: 0, y: 0, s: crop.width });
        if (this.counts[ev.type] !== undefined) { this.counts[ev.type] += ev.qty || 1; const c = $(`[data-c="${ev.type}"]`, this.el); if (c) c.textContent = this.counts[ev.type]; }
        this.flash = Date.now();
        enqueue({ cam: this, type: ev.type, canvas: crop, thumb, slot, qty: ev.qty || 1, to: ev.to ? this.slots.find((x) => x.id === ev.to) : null });
      }
      this.sendCounts(r);
      // Image pleine résolution du rayon calme (environ toutes les secondes) : servira de vignette « avant la prise »
      if (r.state === 'calme' && !r.events.length && !r.pending && (!this.beforeAt || Date.now() - this.beforeAt > 1000) && v.videoWidth) {
        this.before = this.before || document.createElement('canvas');
        if (this.before.width !== v.videoWidth) { this.before.width = v.videoWidth; this.before.height = v.videoHeight; }
        this.before.getContext('2d').drawImage(v, 0, 0); this.beforeAt = Date.now();
      }
    }
    /** Passage : vignette carrée autour de l'objet (pleine résolution) puis reconnaissance. */
    event(ev) {
      const v = this.video; const k = v.videoWidth / this.small.width;
      const cx = (ev.box.x + ev.box.w / 2) * k, cy = (ev.box.y + ev.box.h / 2) * k;
      const side = Math.min(Math.max(ev.box.w, ev.box.h) * k * 1.15, v.videoWidth, v.videoHeight);
      const x = Math.max(0, Math.min(v.videoWidth - side, cx - side / 2)), y = Math.max(0, Math.min(v.videoHeight - side, cy - side / 2));
      const crop = document.createElement('canvas'); crop.width = crop.height = Math.max(64, Math.min(448, Math.round(side)));
      crop.getContext('2d').drawImage(v, x, y, side, side, 0, 0, crop.width, crop.height);
      const thumb = V.thumbOf(crop, { x: 0, y: 0, s: crop.width });
      this.counts[ev.type]++; $(`[data-c="${ev.type}"]`, this.el).textContent = this.counts[ev.type];
      this.flash = Date.now();
      enqueue({ cam: this, type: ev.type, canvas: crop, thumb });
    }
    showEvent(type, thumb, status, name, err, extra) {
      const box = $('[data-events]', this.el);
      if (box.querySelector('span.small')) box.innerHTML = '';
      const d = document.createElement('div');
      d.className = 'surv-ev ' + (status === 'identifie' ? 'ok' : status === 'erreur' ? 'bad' : 'unsure');
      d.title = err || '';
      d.innerHTML = `<img src="${esc(thumb)}" alt=""><span><b>${esc(LABEL[type])}</b> ${new Date().toLocaleTimeString('fr-FR')}<br>${esc(name || (status === 'erreur' ? T('non enregistré') : T('non identifié')))}${status === 'incertain' && name ? ' ?' : ''}${extra ? `<br><i>${esc(extra)}</i>` : ''}</span>`;
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
      if (this.role !== 'etagere' && this.mode === 'ligne' && L) {
        g.strokeStyle = '#e63946'; g.lineWidth = 4; g.beginPath(); g.moveTo(L.x1 * W, L.y1 * H); g.lineTo(L.x2 * W, L.y2 * H); g.stroke();
        const n = PMTrack.create({ width: W, height: H, line: L, invert: this.geom.invert }).exitNormal();
        const mx = (L.x1 + L.x2) / 2 * W, my = (L.y1 + L.y2) / 2 * H, a = 38;
        g.strokeStyle = g.fillStyle = '#e63946'; g.lineWidth = 3; g.beginPath(); g.moveTo(mx, my); g.lineTo(mx + n.x * a, my + n.y * a); g.stroke();
        g.font = 'bold 13px sans-serif'; g.fillText(T('SORTIE'), mx + n.x * (a + 8) - 22, my + n.y * (a + 8) + 4);
      }
      if (this.role === 'etagere') {
        const ch = {}; ((this.shelfOut && this.shelfOut.slots) || []).forEach((x) => { ch[x.id] = x; });
        this.slots.forEach((x, i) => {
          const o = ch[x.id] || {}; const hot = (o.change || 0) > 0.15;
          g.strokeStyle = !x.id ? '#adb5bd' : hot ? '#e63946' : o.touched ? '#fd7e14' : '#20c997'; g.lineWidth = 3; g.setLineDash(x.id ? [] : [6, 4]);
          g.strokeRect(x.x * W, x.y * H, x.w * W, x.h * H); g.setLineDash([]);
          const cells = this.shelf && x.id ? (this.shelf.cells().find((c) => c.id === x.id) || {}).cells : null;
          if (cells && cells.length > 1 && o.present) {
            cells.forEach((c, k) => { g.lineWidth = 2; g.strokeStyle = o.present[k] ? 'rgba(32,201,151,.9)' : 'rgba(230,57,70,.95)'; g.strokeRect(c.x * W + 2, c.y * H + 2, c.w * W - 4, c.h * H - 4);
              if (!o.present[k]) { g.fillStyle = 'rgba(230,57,70,.18)'; g.fillRect(c.x * W + 2, c.y * H + 2, c.w * W - 4, c.h * H - 4); } });
          }
          g.fillStyle = 'rgba(0,0,0,.6)'; const label = (i + 1) + ' ' + (x.label || '') + (o.units > 1 ? ' ' + o.count + '/' + o.units : ''); g.font = 'bold 12px sans-serif';
          g.fillRect(x.x * W, x.y * H, Math.min(x.w * W, g.measureText(label).width + 8), 16); g.fillStyle = '#fff'; g.fillText(label, x.x * W + 4, x.y * H + 12);
        });
        if (this.draw && this.draw.rect) { const z = this.draw.rect; g.strokeStyle = '#ffc107'; g.lineWidth = 2; g.setLineDash([6, 4]); g.strokeRect(z.x * W, z.y * H, z.w * W, z.h * H); g.setLineDash([]); }
        return;
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
        if (this.draw && this.draw.kind === 'slot' && this.draw.start) {
          const a = this.draw.start, b = rel(e);
          this.draw.rect = { x: Math.min(a.x, b.x), y: Math.min(a.y, b.y), w: Math.abs(b.x - a.x), h: Math.abs(b.y - a.y) }; this.render(); return;
        }
        if (!this.draw || this.draw.kind !== 'zone' || !this.draw.start) return;
        const a = this.draw.start, b = rel(e);
        this.geom.zone = { x: Math.min(a.x, b.x), y: Math.min(a.y, b.y), w: Math.abs(b.x - a.x), h: Math.abs(b.y - a.y) }; this.render();
      });
      this.ov.addEventListener('pointerup', () => {
        if (this.draw && this.draw.kind === 'slot' && this.draw.start) {
          const z = this.draw.rect; this.draw = null;
          if (!z || z.w < 0.03 || z.h < 0.03) { this.msg(T('Emplacement trop petit.'), 'text-danger'); this.render(); return; }
          this.slots.push({ id: null, label: '', product_id: null, product: '', x: z.x, y: z.y, w: z.w, h: z.h });
          this.slotList(); this.slotsDirty(); this.render();
          this.msg(T('Emplacement ajouté : nommez-le, indiquez l\'article attendu, puis enregistrez.'));
          return;
        }
        if (!this.draw || this.draw.kind !== 'zone' || !this.draw.start) return;
        this.draw = null;
        if (!this.geom.zone || this.geom.zone.w < 0.05 || this.geom.zone.h < 0.05) { this.msg(T('Zone trop petite.'), 'text-danger'); return; }
        this.mode = 'zone'; $('[data-mode]', this.el).value = 'zone'; this.msg(T('Zone tracée.')); this.dirty();
      });
      window.addEventListener('resize', () => this.render());
    }
  }

  // Recherche d'articles pour les emplacements : liste de suggestions partagée par toutes les caméras
  const productIds = {}; let searchTimer = null;
  const dl = document.createElement('datalist'); dl.id = 'survProducts'; document.body.appendChild(dl);
  function searchProducts(q) {
    clearTimeout(searchTimer);
    if (!q || q.length < 2 || productIds[q]) return;
    searchTimer = setTimeout(async () => {
      const d = await fetch((window.PW && PW.BASE || '') + '/vision/search?q=' + encodeURIComponent(q), { headers: { Accept: 'application/json' } }).then((r) => r.json()).catch(() => ({}));
      if (!d.ok) return;
      d.items.forEach((p) => { productIds[p.name] = p.id; });
      dl.innerHTML = d.items.map((p) => `<option value="${esc(p.name)}"></option>`).join('');
    }, 250);
  }
  D.cameras.forEach((c) => (c.slots || []).forEach((x) => { if (x.product) productIds[x.product] = x.product_id; }));

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
      cams.forEach((c) => ['sortie', 'entree', 'vu', 'prise', 'remis'].forEach((k) => { c.counts[k] = (by[c.id] || {})[k] || 0; const b = $(`[data-c="${k}"]`, c.el); if (b) b.textContent = c.counts[k]; }));
    }
    setTimeout(refresh, 30000);
  }
  refresh();
  setInterval(() => cams.forEach((c) => c.render()), 500);
})();
