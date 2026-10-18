/*
 * POS Marwa — surveillance : détection des objets en mouvement, suivi, comptage.
 * Calcul pur (aucune dépendance au navigateur) : testé tel quel par la suite de tests.
 *
 *   const t = PMTrack.create({ width: 160, height: 120, mode: 'ligne' | 'zone',
 *                              line: { x1, y1, x2, y2 }, invert, zone: { x, y, w, h }, sensitivity: 1..10 });
 *   const r = t.process(gris);   // Uint8Array(width × height), niveaux de gris
 *   r.blobs  : objets détectés [{ id, x, y, w, h, cx, cy }]
 *   r.events : passages [{ type: 'sortie' | 'entree' | 'vu', id, box: { x, y, w, h } }]
 *
 * 1. Fond appris en continu (moyenne glissante) ; un pixel qui s'en écarte est « en mouvement ».
 * 2. Regroupement des pixels en objets (composantes connexes), petits bruits ignorés.
 * 3. Suivi d'un objet d'une image à la suivante (plus proche voisin).
 * 4. Ligne : un objet compte quand il passe franchement d'un côté à l'autre (bande neutre
 *    autour de la ligne : un objet qui hésite sur la ligne ne compte pas plusieurs fois).
 *    Zone : un objet compte une fois quand il reste dans la zone quelques images.
 */
(function (root) {
  'use strict';
  const clamp = (v, a, b) => Math.max(a, Math.min(b, v));

  function create(o) {
    const W = o.width | 0, H = o.height | 0, N = W * H;
    const sens = clamp(Number(o.sensitivity) || 5, 1, 10);
    const thr = 46 - sens * 3.5;                                   // écart de gris significatif
    const minArea = Math.max(6, Math.round(N * (0.012 - sens * 0.001)));
    const maxDist = 0.3 * Math.max(W, H);
    const band = Math.max(2, 0.04 * Math.min(W, H));                // bande neutre autour de la ligne
    const mode = o.mode === 'zone' ? 'zone' : 'ligne';
    const L = o.line || { x1: 0.5, y1: 0.05, x2: 0.5, y2: 0.95 };
    const lx1 = L.x1 * W, ly1 = L.y1 * H, lx2 = L.x2 * W, ly2 = L.y2 * H;
    const llen = Math.hypot(lx2 - lx1, ly2 - ly1) || 1;
    const Z = o.zone ? { x: o.zone.x * W, y: o.zone.y * H, w: o.zone.w * W, h: o.zone.h * H } : null;
    const invert = !!o.invert;

    let bg = null, frames = 0, nextId = 1, tracks = [];
    const fg = new Uint8Array(N), dil = new Uint8Array(N), lab = new Int32Array(N), stack = new Int32Array(N);

    const inZone = (x, y) => !Z || (x >= Z.x && x <= Z.x + Z.w && y >= Z.y && y <= Z.y + Z.h);
    /** Côté de la ligne : +1, −1, ou 0 dans la bande neutre ; t = position le long de la ligne (0 à 1). */
    function side(cx, cy) {
      const d = ((lx2 - lx1) * (cy - ly1) - (ly2 - ly1) * (cx - lx1)) / llen;
      const t = ((cx - lx1) * (lx2 - lx1) + (cy - ly1) * (ly2 - ly1)) / (llen * llen);
      return { s: d > band ? 1 : d < -band ? -1 : 0, t };
    }

    function blobsOf(gray) {
      let moving = 0;
      for (let i = 0; i < N; i++) {
        const diff = Math.abs(gray[i] - bg[i]);
        fg[i] = diff > thr ? 1 : 0;
        moving += fg[i];
      }
      // Changement brutal de toute l'image (lumière, caméra déplacée) : on réapprend le fond
      if (moving > N * 0.5) { for (let i = 0; i < N; i++) bg[i] = gray[i]; return null; }
      // Dilatation 3 × 3 : réunit les morceaux d'un même objet
      dil.fill(0);
      for (let y = 0; y < H; y++) for (let x = 0; x < W; x++) {
        if (!fg[y * W + x]) continue;
        for (let dy = -1; dy <= 1; dy++) for (let dx = -1; dx <= 1; dx++) {
          const xx = x + dx, yy = y + dy;
          if (xx >= 0 && yy >= 0 && xx < W && yy < H) dil[yy * W + xx] = 1;
        }
      }
      // Composantes connexes (4 voisins)
      lab.fill(0);
      let label = 0; const out = [];
      for (let i = 0; i < N; i++) {
        if (!dil[i] || lab[i]) continue;
        label++; let sp = 0; stack[sp++] = i; lab[i] = label;
        let area = 0, sx = 0, sy = 0, minx = W, miny = H, maxx = 0, maxy = 0;
        while (sp) {
          const p = stack[--sp]; const x = p % W, y = (p - x) / W;
          area++; sx += x; sy += y;
          if (x < minx) minx = x; if (x > maxx) maxx = x; if (y < miny) miny = y; if (y > maxy) maxy = y;
          if (x > 0 && dil[p - 1] && !lab[p - 1]) { lab[p - 1] = label; stack[sp++] = p - 1; }
          if (x < W - 1 && dil[p + 1] && !lab[p + 1]) { lab[p + 1] = label; stack[sp++] = p + 1; }
          if (y > 0 && dil[p - W] && !lab[p - W]) { lab[p - W] = label; stack[sp++] = p - W; }
          if (y < H - 1 && dil[p + W] && !lab[p + W]) { lab[p + W] = label; stack[sp++] = p + W; }
        }
        if (area >= minArea) out.push({ x: minx, y: miny, w: maxx - minx + 1, h: maxy - miny + 1, cx: sx / area, cy: sy / area, area });
      }
      // Fusion des objets très proches (un article tenu en main = souvent deux morceaux)
      for (let merged = true; merged;) {
        merged = false;
        for (let a = 0; a < out.length && !merged; a++) for (let b = a + 1; b < out.length && !merged; b++) {
          const A = out[a], B = out[b], g = 3;
          if (A.x - g <= B.x + B.w && B.x - g <= A.x + A.w && A.y - g <= B.y + B.h && B.y - g <= A.y + A.h) {
            const x = Math.min(A.x, B.x), y = Math.min(A.y, B.y), area = A.area + B.area;
            out[a] = { x, y, w: Math.max(A.x + A.w, B.x + B.w) - x, h: Math.max(A.y + A.h, B.y + B.h) - y, cx: (A.cx * A.area + B.cx * B.area) / area, cy: (A.cy * A.area + B.cy * B.area) / area, area };
            out.splice(b, 1); merged = true;
          }
        }
      }
      return out;
    }

    function process(gray) {
      frames++;
      if (!bg) { bg = Float32Array.from(gray); return { blobs: [], events: [] }; }
      const found = frames > 3 ? blobsOf(gray) : [];
      // Mise à jour du fond : rapide là où rien ne bouge, très lente sous les objets
      for (let i = 0; i < N; i++) bg[i] += (gray[i] - bg[i]) * (fg[i] ? 0.004 : 0.06);
      if (!found) { tracks = []; return { blobs: [], events: [] }; }
      const events = [];
      // Association objet ↔ piste (plus proches d'abord)
      const pairs = [];
      found.forEach((b, bi) => tracks.forEach((t, ti) => { const d = Math.hypot(b.cx - t.cx, b.cy - t.cy); if (d <= maxDist) pairs.push([d, bi, ti]); }));
      pairs.sort((a, b) => a[0] - b[0]);
      const usedB = new Set(), usedT = new Set();
      for (const [, bi, ti] of pairs) {
        if (usedB.has(bi) || usedT.has(ti)) continue;
        usedB.add(bi); usedT.add(ti);
        Object.assign(tracks[ti], found[bi], { missed: 0, age: tracks[ti].age + 1 });
        found[bi].id = tracks[ti].id;
      }
      tracks.forEach((t, ti) => { if (!usedT.has(ti)) t.missed++; });
      tracks = tracks.filter((t) => t.missed <= 6);
      found.forEach((b, bi) => {
        if (usedB.has(bi)) return;
        const s = side(b.cx, b.cy).s;
        const t = Object.assign({ id: nextId++, age: 1, missed: 0, lastSide: s, inZone: 0, counted: false }, b);
        b.id = t.id; tracks.push(t);
      });
      for (const t of tracks) {
        if (t.missed) continue;
        if (mode === 'ligne') {
          const { s, t: along } = side(t.cx, t.cy);
          if (s !== 0) {
            if (t.lastSide !== 0 && s !== t.lastSide && t.age >= 2 && along >= -0.1 && along <= 1.1 && inZone(t.cx, t.cy)) {
              const out = (t.lastSide === 1 && s === -1) !== invert;
              events.push({ type: out ? 'sortie' : 'entree', id: t.id, box: { x: t.x, y: t.y, w: t.w, h: t.h } });
            }
            t.lastSide = s;
          }
        } else {
          t.inZone = inZone(t.cx, t.cy) ? t.inZone + 1 : 0;
          if (!t.counted && t.inZone >= 3) {
            t.counted = true;
            events.push({ type: 'vu', id: t.id, box: { x: t.x, y: t.y, w: t.w, h: t.h } });
          }
        }
      }
      return { blobs: found, events };
    }

    /** Sens « sortie » pour l'affichage : du côté +1 vers le côté −1 (ou l'inverse si inversé). */
    function exitNormal() {
      const nx = (ly2 - ly1) / llen, ny = -(lx2 - lx1) / llen; // pointe vers le côté −1
      return invert ? { x: -nx, y: -ny } : { x: nx, y: ny };
    }

    return { process, exitNormal, params: { thr, minArea, band } };
  }

  /** Image RGBA → niveaux de gris. */
  function toGray(rgba, out) {
    for (let i = 0, j = 0; j < out.length; i += 4, j++) out[j] = (rgba[i] * 77 + rgba[i + 1] * 150 + rgba[i + 2] * 29) >> 8;
    return out;
  }

  const api = { create, toGray };
  if (typeof module === 'object' && module.exports) module.exports = api;
  else root.PMTrack = api;
})(typeof window !== 'undefined' ? window : this);
