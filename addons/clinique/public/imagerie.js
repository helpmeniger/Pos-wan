/* Clinique — visionneuse d'imagerie médicale (DICOM non compressé / RLE / JPEG, JPEG, PNG).
   Fenêtrage (contraste / luminosité) sur les valeurs d'origine (16 bits, unités Hounsfield au scanner),
   loupe, déplacement, rotation, miroir, inversion, images multiples (ciné), mesures calibrées
   (distance en mm, angle, zone : surface, moyenne, écart-type, min, max), flèches et textes enregistrés.
   Aucune interprétation automatique : outil d'aide à la lecture pour le médecin. */
(function () {
  'use strict';
  const V = document.getElementById('viewer');
  if (!V) return;
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
  const T = (s) => (window.__ ? __(s) : s);
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const IMGS = JSON.parse(V.dataset.images || '[]');
  const BASE = V.dataset.base;
  const CAN_ANN = V.dataset.canAnnotate === '1';
  const CSRF = ($('meta[name="csrf-token"]') || {}).content || '';
  const stage = $('#stage');
  const cv = $('#cv');
  const ctx = cv.getContext('2d');
  const off = document.createElement('canvas');
  const offCtx = off.getContext('2d', { willReadFrequently: true });
  const msg = (t) => { $('#msg').textContent = t || ''; $('#msg').style.display = t ? 'flex' : 'none'; };

  const S = {
    idx: -1, info: null, frame: 0, w: 0, h: 0, vals: null, rgb: null, mode: 'gray', min: 0, max: 1, wc: 0, ww: 1,
    invert: false, mono1: false, rot: 0, flip: false, zoom: 1, panX: 0, panY: 0, tool: 'wl', draft: null, ann: [],
    calib: null, cine: null, cursor: null, dirty: true, frames: new Map(),
  };

  // ------------------------------------------------------------------ chargement
  async function load(i) {
    if (i < 0 || i >= IMGS.length) return;
    stopCine();
    S.idx = i; S.frames.clear(); S.calib = null; S.draft = null;
    $$('.img-strip-item').forEach((b) => b.classList.toggle('active', +b.dataset.index === i));
    msg(T('Chargement…'));
    const im = IMGS[i];
    try {
      const r = await fetch(`${BASE}/images/${im.id}/infos`, { headers: { Accept: 'application/json' } });
      const d = await r.json();
      if (!d.ok) throw new Error(d.error || 'infos');
      S.info = d; S.ann = d.annotations || [];
      renderAnnList();
      if (!d.renderable) {
        S.vals = null; S.rgb = null; draw();
        msg(''); $('#msg').innerHTML = `<div class="text-center p-3">${esc(d.note || T('Image non affichable'))}<br><a class="btn btn-sm btn-outline-light mt-2" href="${BASE}/images/${im.id}/fichier"><i class="bi bi-download"></i> ${T('Télécharger le fichier d\'origine')}</a></div>`;
        $('#msg').style.display = 'flex';
        return;
      }
      $('#frameBox').classList.toggle('d-none', d.frames <= 1);
      $('#frame').max = Math.max(0, d.frames - 1); $('#frame').value = 0;
      await loadFrame(0, true);
      fit();
      msg('');
    } catch (e) {
      msg(T('Image illisible') + ' : ' + e.message);
    }
  }

  async function fetchFrame(f) {
    if (S.frames.has(f)) return S.frames.get(f);
    const im = IMGS[S.idx];
    const r = await fetch(`${BASE}/images/${im.id}/pixels?frame=${f}`);
    if (!r.ok) throw new Error('HTTP ' + r.status);
    const data = S.info.mode === 'raw' ? await r.arrayBuffer() : await r.blob();
    if (S.frames.size > 60) S.frames.clear();
    S.frames.set(f, data);
    return data;
  }

  async function loadFrame(f, first) {
    const d = S.info;
    S.frame = f;
    const data = await fetchFrame(f);
    if (d.mode === 'raw') {
      S.w = d.w; S.h = d.h;
      const n = d.w * d.h;
      if (d.samples === 3) {
        const u = new Uint8Array(data);
        const rgba = new Uint8ClampedArray(n * 4);
        const lum = new Float32Array(n);
        for (let i = 0; i < n; i++) {
          let r, g, b;
          if (d.planar === 1) { r = u[i]; g = u[n + i]; b = u[2 * n + i]; } else { r = u[3 * i]; g = u[3 * i + 1]; b = u[3 * i + 2]; }
          if (d.photometric === 'YBR_FULL') { const y = r, cb = g - 128, cr = b - 128; r = y + 1.402 * cr; g = y - 0.344136 * cb - 0.714136 * cr; b = y + 1.772 * cb; }
          rgba[4 * i] = r; rgba[4 * i + 1] = g; rgba[4 * i + 2] = b; rgba[4 * i + 3] = 255;
          lum[i] = 0.299 * rgba[4 * i] + 0.587 * rgba[4 * i + 1] + 0.114 * rgba[4 * i + 2];
        }
        S.mode = 'rgb'; S.rgb = rgba; S.vals = lum;
      } else {
        let raw;
        if (d.bits === 16) raw = d.signed ? new Int16Array(data, 0, n) : new Uint16Array(data, 0, n);
        else raw = d.signed ? new Int8Array(data, 0, n) : new Uint8Array(data, 0, n);
        const mask = !d.signed && d.bits_stored < d.bits ? (1 << d.bits_stored) - 1 : 0;
        const vals = new Float32Array(n);
        const sl = d.slope || 1, ic = d.intercept || 0;
        for (let i = 0; i < n; i++) vals[i] = (mask ? (raw[i] & mask) : raw[i]) * sl + ic;
        S.mode = 'gray'; S.vals = vals; S.rgb = null;
      }
    } else {
      const url = URL.createObjectURL(data);
      const img = await new Promise((ok, ko) => { const x = new Image(); x.onload = () => ok(x); x.onerror = () => ko(new Error(T('image illisible'))); x.src = url; });
      URL.revokeObjectURL(url);
      S.w = img.naturalWidth; S.h = img.naturalHeight;
      off.width = S.w; off.height = S.h;
      offCtx.drawImage(img, 0, 0);
      const px = offCtx.getImageData(0, 0, S.w, S.h).data;
      const n = S.w * S.h;
      const lum = new Float32Array(n);
      for (let i = 0; i < n; i++) lum[i] = 0.299 * px[4 * i] + 0.587 * px[4 * i + 1] + 0.114 * px[4 * i + 2];
      S.mode = 'rgb'; S.rgb = new Uint8ClampedArray(px); S.vals = lum;
    }
    off.width = S.w; off.height = S.h;
    if (first) {
      stats();
      S.mono1 = d.photometric === 'MONOCHROME1';
      S.invert = false;
      applyPreset(d.mode === 'raw' && S.mode === 'gray' && d.ww ? 'dicom' : (S.mode === 'gray' ? 'auto' : 'full'));
    } else {
      S.dirty = true; draw();
    }
    $('#frameLbl').textContent = (f + 1) + ' / ' + d.frames;
  }

  /** Minimum, maximum et centiles (1 % et 99 %) des valeurs, sur un échantillon. */
  function stats() {
    const v = S.vals;
    let mn = Infinity, mx = -Infinity;
    for (let i = 0; i < v.length; i++) { if (v[i] < mn) mn = v[i]; if (v[i] > mx) mx = v[i]; }
    const step = Math.max(1, Math.floor(v.length / 200000));
    const smp = [];
    for (let i = 0; i < v.length; i += step) smp.push(v[i]);
    smp.sort((a, b) => a - b);
    S.min = mn; S.max = mx;
    S.p1 = smp[Math.floor(smp.length * 0.01)] ?? mn; S.p99 = smp[Math.floor(smp.length * 0.99)] ?? mx;
  }

  function applyPreset(p) {
    const d = S.info;
    if (p === 'dicom' && d.ww) { S.wc = d.wc; S.ww = d.ww; }
    else if (p === 'auto') { S.wc = (S.p1 + S.p99) / 2; S.ww = Math.max(1, S.p99 - S.p1); }
    else if (p === 'full') { S.wc = S.mode === 'rgb' ? 127.5 : (S.min + S.max) / 2; S.ww = S.mode === 'rgb' ? 255 : Math.max(1, S.max - S.min); }
    else if (/^-?\d+,\d+$/.test(p)) { const [c, w] = p.split(',').map(Number); S.wc = c; S.ww = w; }
    S.dirty = true; draw();
  }

  // ------------------------------------------------------------------ rendu
  function renderOff() {
    if (!S.vals) return;
    const n = S.w * S.h;
    const out = offCtx.createImageData(S.w, S.h);
    const o = out.data;
    const lo = S.wc - S.ww / 2, k = 255 / Math.max(1e-6, S.ww);
    const inv = S.invert !== S.mono1;
    if (S.mode === 'gray') {
      const v = S.vals;
      for (let i = 0, j = 0; i < n; i++, j += 4) {
        let g = (v[i] - lo) * k;
        g = g < 0 ? 0 : (g > 255 ? 255 : g);
        if (inv) g = 255 - g;
        o[j] = o[j + 1] = o[j + 2] = g; o[j + 3] = 255;
      }
    } else {
      const s = S.rgb;
      for (let j = 0; j < n * 4; j += 4) {
        for (let c = 0; c < 3; c++) {
          let g = (s[j + c] - lo) * k;
          g = g < 0 ? 0 : (g > 255 ? 255 : g);
          o[j + c] = inv ? 255 - g : g;
        }
        o[j + 3] = 255;
      }
    }
    offCtx.putImageData(out, 0, 0);
    S.dirty = false;
  }

  /** Transformation image → écran (centre, déplacement, rotation, miroir, loupe). */
  function toScreen(x, y) {
    let dx = x - S.w / 2, dy = y - S.h / 2;
    if (S.flip) dx = -dx;
    for (let r = 0; r < S.rot; r++) { const t = dx; dx = -dy; dy = t; }
    return [cv.clientWidth / 2 + S.panX + dx * S.zoom, cv.clientHeight / 2 + S.panY + dy * S.zoom];
  }
  function toImage(sx, sy) {
    let dx = (sx - cv.clientWidth / 2 - S.panX) / S.zoom, dy = (sy - cv.clientHeight / 2 - S.panY) / S.zoom;
    for (let r = 0; r < S.rot; r++) { const t = dx; dx = dy; dy = -t; }
    if (S.flip) dx = -dx;
    return [dx + S.w / 2, dy + S.h / 2];
  }

  let raf = 0;
  function draw() {
    if (raf) return;
    raf = requestAnimationFrame(() => { raf = 0; paint(); });
  }
  function paint() {
    const dpr = window.devicePixelRatio || 1;
    const cw = stage.clientWidth, ch = stage.clientHeight;
    if (cv.width !== Math.round(cw * dpr) || cv.height !== Math.round(ch * dpr)) {
      cv.width = Math.round(cw * dpr); cv.height = Math.round(ch * dpr);
      cv.style.width = cw + 'px'; cv.style.height = ch + 'px';
    }
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    ctx.fillStyle = '#000'; ctx.fillRect(0, 0, cw, ch);
    if (!S.vals) { overlays(); return; }
    if (S.dirty) renderOff();
    ctx.save();
    ctx.translate(cw / 2 + S.panX, ch / 2 + S.panY);
    ctx.rotate(S.rot * Math.PI / 2);
    ctx.scale(S.flip ? -S.zoom : S.zoom, S.zoom);
    ctx.imageSmoothingEnabled = S.zoom < 4;
    ctx.drawImage(off, -S.w / 2, -S.h / 2);
    ctx.restore();
    S.ann.filter((a) => (a.data.frame || 0) === S.frame).forEach((a) => drawShape(a.kind, a.data.points, a.data.value, a.label, '#ffd54f'));
    if (S.draft) drawShape(S.draft.kind, S.draft.pts.concat(S.draft.kind === 'angle' && S.cursor && S.draft.pts.length < 3 ? [S.cursor] : []), measure(S.draft.kind, S.draft.pts), '', '#4fc3f7');
    overlays();
  }

  function drawShape(kind, pts, value, label, color) {
    if (!pts || !pts.length) return;
    const sp = pts.map((p) => toScreen(p[0], p[1]));
    ctx.save();
    ctx.strokeStyle = color; ctx.fillStyle = color; ctx.lineWidth = 2; ctx.font = '13px sans-serif';
    ctx.shadowColor = '#000'; ctx.shadowBlur = 3;
    if ((kind === 'distance' || kind === 'calib' || kind === 'fleche') && sp.length >= 2) {
      ctx.beginPath(); ctx.moveTo(sp[0][0], sp[0][1]); ctx.lineTo(sp[1][0], sp[1][1]); ctx.stroke();
      if (kind === 'fleche') {
        const a = Math.atan2(sp[1][1] - sp[0][1], sp[1][0] - sp[0][0]);
        ctx.beginPath(); ctx.moveTo(sp[1][0], sp[1][1]);
        ctx.lineTo(sp[1][0] - 14 * Math.cos(a - 0.4), sp[1][1] - 14 * Math.sin(a - 0.4));
        ctx.lineTo(sp[1][0] - 14 * Math.cos(a + 0.4), sp[1][1] - 14 * Math.sin(a + 0.4)); ctx.closePath(); ctx.fill();
      } else {
        [sp[0], sp[1]].forEach((p) => { ctx.beginPath(); ctx.arc(p[0], p[1], 3, 0, 7); ctx.fill(); });
      }
    } else if (kind === 'angle') {
      ctx.beginPath(); ctx.moveTo(sp[0][0], sp[0][1]);
      for (let i = 1; i < sp.length; i++) ctx.lineTo(sp[i][0], sp[i][1]);
      ctx.stroke();
    } else if (kind === 'roi' && sp.length >= 2) {
      const cx = (sp[0][0] + sp[1][0]) / 2, cy = (sp[0][1] + sp[1][1]) / 2;
      ctx.beginPath(); ctx.ellipse(cx, cy, Math.abs(sp[1][0] - sp[0][0]) / 2, Math.abs(sp[1][1] - sp[0][1]) / 2, 0, 0, 7); ctx.stroke();
    } else if (kind === 'texte') {
      ctx.fillText(label || value || '', sp[0][0], sp[0][1]);
      ctx.restore(); return;
    }
    const txt = [value, label].filter(Boolean).join(' — ');
    if (txt) {
      // Libellé gardé dans la zone visible (bord droit / haut)
      const p = sp[sp.length - 1];
      const tw = ctx.measureText(txt).width;
      const x = Math.max(4, Math.min(p[0] + 8, cv.clientWidth - tw - 6));
      ctx.fillText(txt, x, Math.max(14, p[1] - 8));
    }
    ctx.restore();
  }

  function overlays() {
    const d = S.info || {};
    const im = IMGS[S.idx] || {};
    $('#ovTr').textContent = [d.modality, d.series || im.series, d.instance ? '#' + d.instance : '', S.w ? S.w + '×' + S.h : ''].filter(Boolean).join('  ');
    $('#ovBl').textContent = S.vals ? `${T('Fenêtre')} ${Math.round(S.wc)} / ${Math.round(S.ww)}  ·  ${T('Loupe')} ${Math.round(S.zoom * 100)} %${S.invert ? '  · ' + T('inversé') : ''}` : '';
    let br = '';
    if (S.cursor && S.vals) {
      const [x, y] = S.cursor.map(Math.floor);
      if (x >= 0 && y >= 0 && x < S.w && y < S.h) br = `x ${x}  y ${y}  ·  ${valueLabel(S.vals[y * S.w + x])}`;
    }
    $('#ovBr').textContent = br;
    const sp = spacing();
    $('#ovTl').textContent = sp ? `${sp.col.toFixed(3)} mm/px${S.calib ? ' (' + T('calibrage manuel') + ')' : ''}` : T('Non calibrée : mesures en pixels');
    $('#calibInfo').textContent = sp ? sp.col.toFixed(3) + ' mm/px' : 'px';
  }

  function valueLabel(v) {
    const unit = S.info && S.info.modality === 'CT' && S.mode === 'gray' ? ' HU' : '';
    return (Math.abs(v) >= 100 ? Math.round(v) : Math.round(v * 10) / 10) + unit;
  }

  // ------------------------------------------------------------------ mesures
  function spacing() {
    if (S.calib) return { row: S.calib, col: S.calib };
    return S.info && S.info.spacing ? S.info.spacing : null;
  }
  function measure(kind, pts) {
    const sp = spacing();
    if (kind === 'distance' || kind === 'calib') {
      if (pts.length < 2) return '';
      const dx = (pts[1][0] - pts[0][0]) * (sp ? sp.col : 1), dy = (pts[1][1] - pts[0][1]) * (sp ? sp.row : 1);
      const l = Math.hypot(dx, dy);
      return sp ? (l >= 100 ? (l / 10).toFixed(1) + ' cm' : l.toFixed(1) + ' mm') : Math.round(l) + ' px';
    }
    if (kind === 'angle') {
      if (pts.length < 3) return '';
      const sx = sp ? sp.col : 1, sy = sp ? sp.row : 1;
      const a1 = Math.atan2((pts[0][1] - pts[1][1]) * sy, (pts[0][0] - pts[1][0]) * sx);
      const a2 = Math.atan2((pts[2][1] - pts[1][1]) * sy, (pts[2][0] - pts[1][0]) * sx);
      let deg = Math.abs(a1 - a2) * 180 / Math.PI;
      if (deg > 180) deg = 360 - deg;
      return deg.toFixed(1) + '°';
    }
    if (kind === 'roi') {
      if (pts.length < 2) return '';
      const x0 = Math.min(pts[0][0], pts[1][0]), x1 = Math.max(pts[0][0], pts[1][0]);
      const y0 = Math.min(pts[0][1], pts[1][1]), y1 = Math.max(pts[0][1], pts[1][1]);
      const cx = (x0 + x1) / 2, cy = (y0 + y1) / 2, rx = Math.max(0.5, (x1 - x0) / 2), ry = Math.max(0.5, (y1 - y0) / 2);
      let n = 0, sum = 0, sq = 0, mn = Infinity, mx = -Infinity;
      for (let y = Math.max(0, Math.floor(y0)); y <= Math.min(S.h - 1, Math.ceil(y1)); y++) {
        for (let x = Math.max(0, Math.floor(x0)); x <= Math.min(S.w - 1, Math.ceil(x1)); x++) {
          const u = (x + 0.5 - cx) / rx, w = (y + 0.5 - cy) / ry;
          if (u * u + w * w > 1) continue;
          const v = S.vals[y * S.w + x];
          n++; sum += v; sq += v * v; if (v < mn) mn = v; if (v > mx) mx = v;
        }
      }
      if (!n) return '';
      const mean = sum / n, sd = Math.sqrt(Math.max(0, sq / n - mean * mean));
      const area = Math.PI * rx * ry * (sp ? sp.row * sp.col : 1);
      const a = sp ? (area >= 100 ? (area / 100).toFixed(2) + ' cm²' : area.toFixed(1) + ' mm²') : Math.round(area) + ' px²';
      return `${a} · ${T('moy.')} ${valueLabel(mean)} · ${T('é.-t.')} ${valueLabel(sd)} · min ${valueLabel(mn)} · max ${valueLabel(mx)}`;
    }
    return '';
  }

  async function saveAnnotation(kind, pts, label) {
    if (!CAN_ANN) return;
    const value = kind === 'texte' ? label : measure(kind, pts);
    try {
      const r = await fetch(`${BASE}/images/${IMGS[S.idx].id}/annotations`, {
        method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': CSRF },
        body: JSON.stringify({ _csrf: CSRF, kind, points: pts, frame: S.frame, value, label: kind === 'texte' ? label : (label || '') }),
      });
      const d = await r.json();
      if (!d.ok) throw new Error(d.error || 'erreur');
      S.ann = d.annotations; renderAnnList(); draw();
    } catch (e) { alert(T('Annotation non enregistrée') + ' : ' + e.message); }
  }

  function renderAnnList() {
    const ul = $('#annList');
    const ic = { distance: 'bi-rulers', angle: 'bi-triangle', roi: 'bi-circle', fleche: 'bi-arrow-up-right', texte: 'bi-fonts' };
    ul.innerHTML = S.ann.length ? S.ann.map((a) => `<li class="list-group-item py-1 d-flex gap-2 align-items-start"><i class="bi ${ic[a.kind] || 'bi-dot'}"></i>
      <div class="flex-grow-1"><b>${esc(a.kind === 'texte' ? a.label : (a.data.value || ''))}</b>${a.label && a.kind !== 'texte' ? ' — ' + esc(a.label) : ''}<div class="text-secondary" style="font-size:.72rem">${esc(a.user)} · ${esc(a.at)}${(a.data.frame || 0) ? ' · ' + T('image') + ' ' + (a.data.frame + 1) : ''}</div></div>
      ${CAN_ANN ? `<button type="button" class="btn btn-sm btn-link text-danger p-0" data-del="${a.id}" title="${T('Supprimer')}"><i class="bi bi-trash"></i></button>` : ''}</li>`).join('')
      : `<li class="list-group-item text-secondary">${T('Aucune')}</li>`;
  }
  $('#annList').addEventListener('click', async (e) => {
    const b = e.target.closest('[data-del]');
    if (!b || !confirm(T('Supprimer cette annotation ?'))) return;
    const r = await fetch(`${BASE}/annotations/${b.dataset.del}/supprimer`, { method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-Token': CSRF, 'Content-Type': 'application/json' }, body: JSON.stringify({ _csrf: CSRF }) });
    const d = await r.json().catch(() => ({}));
    if (d.ok) { S.ann = d.annotations; renderAnnList(); draw(); }
  });

  // ------------------------------------------------------------------ souris / tactile
  let drag = null;
  cv.addEventListener('contextmenu', (e) => e.preventDefault());
  cv.addEventListener('pointerdown', (e) => {
    if (!S.vals) return;
    cv.setPointerCapture(e.pointerId);
    const p = toImage(e.offsetX, e.offsetY);
    const tool = e.button === 2 ? 'wl' : (e.button === 1 ? 'pan' : S.tool);
    drag = { tool, x: e.offsetX, y: e.offsetY, wc: S.wc, ww: S.ww, panX: S.panX, panY: S.panY, zoom: S.zoom };
    if (['distance', 'roi', 'fleche', 'calib'].includes(tool)) S.draft = { kind: tool, pts: [p, p] };
    else if (tool === 'angle') {
      if (!S.draft || S.draft.kind !== 'angle') S.draft = { kind: 'angle', pts: [] };
      S.draft.pts.push(p);
      if (S.draft.pts.length === 3) { const pts = S.draft.pts; S.draft = null; saveAnnotation('angle', pts, ''); }
      drag = null;
    } else if (tool === 'texte') {
      drag = null;
      const t = prompt(T('Texte à placer sur l\'image :'));
      if (t && t.trim()) saveAnnotation('texte', [p], t.trim().slice(0, 150));
    }
    draw();
  });
  cv.addEventListener('pointermove', (e) => {
    S.cursor = S.vals ? toImage(e.offsetX, e.offsetY) : null;
    if (drag) {
      const dx = e.offsetX - drag.x, dy = e.offsetY - drag.y;
      if (drag.tool === 'wl') {
        const k = Math.max(1, (S.max - S.min)) / Math.max(200, stage.clientWidth);
        S.ww = Math.max(1, drag.ww + dx * k * 2); S.wc = drag.wc + dy * k * 2; S.dirty = true;
      } else if (drag.tool === 'pan') { S.panX = drag.panX + dx; S.panY = drag.panY + dy; }
      else if (drag.tool === 'zoom') { S.zoom = Math.min(40, Math.max(0.05, drag.zoom * Math.exp(-dy / 150))); }
      else if (S.draft && S.draft.pts.length === 2) S.draft.pts[1] = toImage(e.offsetX, e.offsetY);
    }
    draw();
  });
  cv.addEventListener('pointerup', (e) => {
    if (!drag) return;
    drag = null;
    if (S.draft && ['distance', 'roi', 'fleche', 'calib'].includes(S.draft.kind)) {
      const pts = S.draft.pts; const kind = S.draft.kind; S.draft = null;
      const [a, b] = [toScreen(...pts[0]), toScreen(...pts[1])];
      if (Math.hypot(a[0] - b[0], a[1] - b[1]) < 4) { draw(); return; }
      if (kind === 'calib') {
        const px = Math.hypot(pts[1][0] - pts[0][0], pts[1][1] - pts[0][1]);
        const mm = parseFloat(String(prompt(T('Longueur réelle de ce repère en millimètres :'), '10') || '').replace(',', '.'));
        if (mm > 0 && px > 0) { S.calib = mm / px; }
      } else {
        saveAnnotation(kind, pts.map((p) => [Math.round(p[0] * 100) / 100, Math.round(p[1] * 100) / 100]), '');
      }
    }
    draw();
  });
  cv.addEventListener('pointerleave', () => { S.cursor = null; draw(); });
  cv.addEventListener('wheel', (e) => {
    if (!S.vals) return;
    e.preventDefault();
    if (S.info.frames > 1 && e.shiftKey) { stepFrame(e.deltaY > 0 ? 1 : -1); return; }
    const before = toImage(e.offsetX, e.offsetY);
    S.zoom = Math.min(40, Math.max(0.05, S.zoom * (e.deltaY < 0 ? 1.15 : 1 / 1.15)));
    const after = toScreen(...before);
    S.panX += e.offsetX - after[0]; S.panY += e.offsetY - after[1];
    draw();
  }, { passive: false });
  cv.addEventListener('dblclick', () => fit());

  // ------------------------------------------------------------------ boutons
  function fit() {
    if (!S.w) return;
    const rotated = S.rot % 2 === 1;
    const iw = rotated ? S.h : S.w, ih = rotated ? S.w : S.h;
    S.zoom = Math.min(stage.clientWidth / iw, stage.clientHeight / ih) * 0.97;
    S.panX = 0; S.panY = 0; draw();
  }
  $$('[data-tool]').forEach((b) => b.addEventListener('click', () => {
    S.tool = b.dataset.tool; S.draft = null;
    $$('[data-tool]').forEach((x) => x.classList.toggle('active', x === b));
    stage.dataset.tool = S.tool; draw();
  }));
  $$('[data-act]').forEach((b) => b.addEventListener('click', () => {
    const a = b.dataset.act;
    if (a === 'invert') { S.invert = !S.invert; S.dirty = true; }
    if (a === 'rotate') { S.rot = (S.rot + 1) % 4; fit(); }
    if (a === 'flip') S.flip = !S.flip;
    if (a === 'fit') fit();
    if (a === 'reset') { S.rot = 0; S.flip = false; S.invert = false; S.calib = null; applyPreset(S.info && S.info.ww ? 'dicom' : 'auto'); fit(); }
    if (a === 'full') { if (document.fullscreenElement) document.exitFullscreen(); else V.requestFullscreen().catch(() => {}); }
    if (a === 'png') { const l = document.createElement('a'); l.download = 'image-' + (IMGS[S.idx] || {}).id + '.png'; l.href = cv.toDataURL('image/png'); l.click(); }
    draw();
  }));
  $('#preset').addEventListener('change', (e) => { if (e.target.value) applyPreset(e.target.value); e.target.value = ''; });
  $$('.img-strip-item').forEach((b) => b.addEventListener('click', () => load(+b.dataset.index)));

  // Images multiples (échographie, scanner) : curseur et lecture ciné
  async function stepFrame(dir) {
    const n = S.info.frames;
    const f = (S.frame + dir + n) % n;
    $('#frame').value = f;
    await loadFrame(f);
  }
  function stopCine() { if (S.cine) { clearInterval(S.cine); S.cine = null; $('#cine').innerHTML = '<i class="bi bi-play-fill"></i>'; } }
  $('#frame').addEventListener('input', (e) => loadFrame(+e.target.value));
  $('#cine').addEventListener('click', () => {
    if (S.cine) { stopCine(); return; }
    $('#cine').innerHTML = '<i class="bi bi-pause-fill"></i>';
    let busy = false;
    S.cine = setInterval(async () => { if (busy) return; busy = true; try { await stepFrame(1); } finally { busy = false; } }, 100);
  });

  document.addEventListener('keydown', (e) => {
    if (/INPUT|TEXTAREA|SELECT/.test((e.target || {}).tagName || '')) return;
    if (e.key === 'ArrowRight' || e.key === 'PageDown') { load(Math.min(IMGS.length - 1, S.idx + 1)); e.preventDefault(); }
    else if (e.key === 'ArrowLeft' || e.key === 'PageUp') { load(Math.max(0, S.idx - 1)); e.preventDefault(); }
    else if ((e.key === 'ArrowDown' || e.key === 'ArrowUp') && S.info && S.info.frames > 1) { stepFrame(e.key === 'ArrowDown' ? 1 : -1); e.preventDefault(); }
    else if (e.key === 'Escape') { S.draft = null; draw(); }
    else if (e.key === 'i') { S.invert = !S.invert; S.dirty = true; draw(); }
  });
  new ResizeObserver(() => draw()).observe(stage);

  // Modèles de compte rendu
  const tpl = $('#tpl');
  if (tpl) tpl.addEventListener('change', () => {
    if (!tpl.value) return;
    const [a, b, c] = JSON.parse(tpl.value);
    const f = tpl.closest('form');
    const filled = ['technique', 'findings', 'conclusion'].some((n) => f[n].value.trim());
    if (!filled || confirm(T('Remplacer le texte du compte rendu par ce modèle ?'))) { f.technique.value = a; f.findings.value = b; f.conclusion.value = c; }
    tpl.value = '';
  });

  // Première image affichable
  const first = IMGS.findIndex((x) => x.ok);
  if (IMGS.length) load(first >= 0 ? first : 0); else msg(T('Aucune image dans cet examen.'));
})();
