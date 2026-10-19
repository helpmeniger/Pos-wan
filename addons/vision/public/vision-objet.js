/*
 * POS Marwa — Reconnaissance par photo : isolement de l'objet (sans Internet, sans bibliothèque).
 *
 * Sépare l'article de l'arrière-plan (mur, comptoir, rayon) sur une petite grille de l'image :
 *  - avec un FOND APPRIS (photo du comptoir vide prise une fois par poste et par caméra) :
 *    différence avec le fond, compensée pour l'exposition automatique de la caméra, en
 *    ignorant les ombres portées (même teinte, seulement plus sombre) ;
 *  - sans fond appris : les couleurs des BORDS de l'image servent de modèle d'arrière-plan
 *    (ce qui ressemble aux bords et les touche est considéré comme du fond).
 * Ensuite : nettoyage (petits points, trous), sélection de l'objet principal, cadre carré.
 * Le résultat sert à (1) savoir s'il y a un objet devant la caméra, (2) recadrer sur l'objet,
 * (3) remplacer le fond par un gris neutre avant le calcul de l'empreinte.
 * Fonctions pures sur des tableaux RGBA : utilisables dans le navigateur et testées sous Node.
 */
(function (root) {
  'use strict';
  const G = 112; // taille de la grille d'analyse (G × G)

  function rgb(data, n) {
    const o = new Float32Array(n * 3);
    for (let i = 0; i < n; i++) { o[i * 3] = data[i * 4]; o[i * 3 + 1] = data[i * 4 + 1]; o[i * 3 + 2] = data[i * 4 + 2]; }
    return o;
  }
  /** Flou 3 × 3 (réduit le bruit du capteur et la compression JPEG). */
  function blur(src, w, h) {
    const o = new Float32Array(src.length);
    for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) {
      let r = 0, g = 0, b = 0, n = 0;
      for (let dy = -1; dy <= 1; dy++) { const yy = y + dy; if (yy < 0 || yy >= h) continue;
        for (let dx = -1; dx <= 1; dx++) { const xx = x + dx; if (xx < 0 || xx >= w) continue; const k = (yy * w + xx) * 3; r += src[k]; g += src[k + 1]; b += src[k + 2]; n++; } }
      const k = (y * w + x) * 3; o[k] = r / n; o[k + 1] = g / n; o[k + 2] = b / n;
    }
    return o;
  }
  function quantile(arr, q) {
    if (!arr.length) return 0;
    const a = Float32Array.from(arr).sort(); return a[Math.min(a.length - 1, Math.max(0, Math.floor(q * (a.length - 1))))];
  }
  const lum = (c, k) => 0.299 * c[k] + 0.587 * c[k + 1] + 0.114 * c[k + 2];

  /** Masque « objet » par différence avec le fond appris. */
  function fromBackground(a, b, w, h) {
    const n = w * h, ratios = [];
    for (let i = 0; i < n; i++) { const lb = lum(b, i * 3); if (lb > 25) ratios.push(lum(a, i * 3) / lb); }
    const gain = Math.min(1.6, Math.max(0.6, quantile(ratios, 0.5) || 1)); // exposition automatique
    const diff = new Float32Array(n), shadow = new Uint8Array(n);
    for (let i = 0; i < n; i++) {
      const k = i * 3;
      const dr = Math.abs(a[k] - gain * b[k]), dg = Math.abs(a[k + 1] - gain * b[k + 1]), db = Math.abs(a[k + 2] - gain * b[k + 2]);
      diff[i] = Math.max(dr, dg, db);
      const sa = a[k] + a[k + 1] + a[k + 2] + 1, sb = b[k] + b[k + 1] + b[k + 2] + 1;
      const cd = Math.abs(a[k] / sa - b[k] / sb) + Math.abs(a[k + 1] / sa - b[k + 1] / sb);
      const lr = lum(a, k) / (gain * lum(b, k) + 1);
      shadow[i] = cd < 0.045 && lr > 0.4 && lr < 0.93 ? 1 : 0; // ombre : même teinte, plus sombre
    }
    const t = Math.max(26, quantile(diff, 0.5) * 2 + 12);
    const m = new Uint8Array(n);
    for (let i = 0; i < n; i++) m[i] = diff[i] > t && !shadow[i] ? 1 : 0;
    return m;
  }

  /** Masque « objet » sans fond appris : modèle de couleurs des bords + remplissage depuis les bords. */
  function fromBorder(a, w, h) {
    const n = w * h, bw = Math.max(3, Math.round(w * 0.06));
    const border = [];
    for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) if (x < bw || y < bw || x >= w - bw || y >= h - bw) border.push(y * w + x);
    // k-moyennes (4 couleurs) sur les pixels du bord
    const K = 4, c = new Float32Array(K * 3);
    for (let j = 0; j < K; j++) { const p = border[Math.floor((j + 0.5) * border.length / K)] * 3; c[j * 3] = a[p]; c[j * 3 + 1] = a[p + 1]; c[j * 3 + 2] = a[p + 2]; }
    const near = (k) => { let best = 1e9; for (let j = 0; j < K; j++) { const d = (a[k] - c[j * 3]) ** 2 + (a[k + 1] - c[j * 3 + 1]) ** 2 + (a[k + 2] - c[j * 3 + 2]) ** 2; if (d < best) best = d; } return Math.sqrt(best); };
    for (let it = 0; it < 8; it++) {
      const s = new Float32Array(K * 4);
      for (const p of border) { const k = p * 3; let bj = 0, bd = 1e9; for (let j = 0; j < K; j++) { const d = (a[k] - c[j * 3]) ** 2 + (a[k + 1] - c[j * 3 + 1]) ** 2 + (a[k + 2] - c[j * 3 + 2]) ** 2; if (d < bd) { bd = d; bj = j; } } s[bj * 4] += a[k]; s[bj * 4 + 1] += a[k + 1]; s[bj * 4 + 2] += a[k + 2]; s[bj * 4 + 3]++; }
      for (let j = 0; j < K; j++) if (s[j * 4 + 3]) { c[j * 3] = s[j * 4] / s[j * 4 + 3]; c[j * 3 + 1] = s[j * 4 + 1] / s[j * 4 + 3]; c[j * 3 + 2] = s[j * 4 + 2] / s[j * 4 + 3]; }
    }
    const dist = new Float32Array(n); for (let i = 0; i < n; i++) dist[i] = near(i * 3);
    const t = Math.max(30, quantile(border.map((p) => dist[p]), 0.9) * 1.3 + 8);
    // Ombre portée : même teinte qu'une couleur du fond, seulement plus sombre → fond
    for (let i = 0; i < n; i++) {
      if (dist[i] < t) continue;
      const k = i * 3, sa = a[k] + a[k + 1] + a[k + 2] + 1, la = lum(a, k);
      for (let j = 0; j < K; j++) {
        const sb = c[j * 3] + c[j * 3 + 1] + c[j * 3 + 2] + 1, lb = 0.299 * c[j * 3] + 0.587 * c[j * 3 + 1] + 0.114 * c[j * 3 + 2];
        const cd = Math.abs(a[k] / sa - c[j * 3] / sb) + Math.abs(a[k + 1] / sa - c[j * 3 + 1] / sb), lr = la / (lb + 1);
        if (cd < 0.045 && lr > 0.4 && lr < 0.93) { dist[i] = 0; break; }
      }
    }
    // fond = ce qui ressemble aux bords ET est relié aux bords
    const bg = new Uint8Array(n), stack = [];
    for (const p of border) if (dist[p] < t) { bg[p] = 1; stack.push(p); }
    while (stack.length) {
      const p = stack.pop(), x = p % w, y = (p - x) / w;
      const nb = [x > 0 ? p - 1 : -1, x < w - 1 ? p + 1 : -1, y > 0 ? p - w : -1, y < h - 1 ? p + w : -1];
      for (const q of nb) if (q >= 0 && !bg[q] && dist[q] < t) { bg[q] = 1; stack.push(q); }
    }
    const m = new Uint8Array(n); for (let i = 0; i < n; i++) m[i] = bg[i] ? 0 : 1;
    return m;
  }

  function morph(m, w, h, dilate) {
    const o = new Uint8Array(m.length);
    for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) {
      let v = dilate ? 0 : 1;
      for (let dy = -1; dy <= 1 && (dilate ? !v : v); dy++) for (let dx = -1; dx <= 1; dx++) {
        const xx = x + dx, yy = y + dy;
        const s = xx < 0 || yy < 0 || xx >= w || yy >= h ? 0 : m[yy * w + xx];
        if (dilate && s) { v = 1; break; } if (!dilate && !s) { v = 0; break; }
      }
      o[y * w + x] = v;
    }
    return o;
  }
  /** Trous de l'objet (étiquette claire sur un objet sombre…) rebouchés. */
  function fillHoles(m, w, h) {
    const out = new Uint8Array(m.length), stack = [];
    const push = (p) => { if (!m[p] && !out[p]) { out[p] = 1; stack.push(p); } };
    for (let x = 0; x < w; x++) { push(x); push((h - 1) * w + x); }
    for (let y = 0; y < h; y++) { push(y * w); push(y * w + w - 1); }
    while (stack.length) { const p = stack.pop(), x = p % w, y = (p - x) / w; if (x > 0) push(p - 1); if (x < w - 1) push(p + 1); if (y > 0) push(p - w); if (y < h - 1) push(p + w); }
    const r = new Uint8Array(m.length); for (let i = 0; i < m.length; i++) r[i] = out[i] ? 0 : 1; // tout ce qui n'est pas du fond relié au bord
    return r;
  }
  function components(m, w, h) {
    const lab = new Int32Array(m.length), out = [];
    let id = 0;
    for (let s = 0; s < m.length; s++) {
      if (!m[s] || lab[s]) continue;
      id++; const st = [s]; lab[s] = id; let area = 0, x0 = w, y0 = h, x1 = 0, y1 = 0;
      while (st.length) {
        const p = st.pop(), x = p % w, y = (p - x) / w; area++;
        if (x < x0) x0 = x; if (x > x1) x1 = x; if (y < y0) y0 = y; if (y > y1) y1 = y;
        const nb = [x > 0 ? p - 1 : -1, x < w - 1 ? p + 1 : -1, y > 0 ? p - w : -1, y < h - 1 ? p + w : -1];
        for (const q of nb) if (q >= 0 && m[q] && !lab[q]) { lab[q] = id; st.push(q); }
      }
      out.push({ id, area, x0, y0, x1, y1 });
    }
    out.sort((A, B) => B.area - A.area);
    return { lab, list: out };
  }

  /**
   * Segmente une image RGBA w × h (grille d'analyse). bg : RGBA du fond appris (même cadrage) ou null.
   * Renvoie { mode, frac, objets, bbox:{x0,y0,x1,y1}|null, mask:Uint8Array (objet principal) }.
   */
  function segment(cur, bg, w, h) {
    const n = w * h;
    const a = blur(rgb(cur, n), w, h);
    let m = bg ? fromBackground(a, blur(rgb(bg, n), w, h), w, h) : fromBorder(a, w, h);
    m = morph(morph(m, w, h, false), w, h, true);   // ouverture : supprime les points isolés
    m = morph(morph(m, w, h, true), w, h, false);   // fermeture : relie les morceaux de l'objet
    m = fillHoles(m, w, h);
    const { lab, list } = components(m, w, h);
    const big = list.filter((c) => c.area >= n * 0.015);
    if (!big.length) return { mode: bg ? 'fond' : 'bords', frac: 0, objets: 0, bbox: null, mask: new Uint8Array(n) };
    const main = big[0], mask = new Uint8Array(n);
    for (let i = 0; i < n; i++) mask[i] = lab[i] === main.id ? 1 : 0;
    return { mode: bg ? 'fond' : 'bords', frac: main.area / n, objets: big.length, bbox: { x0: main.x0, y0: main.y0, x1: main.x1, y1: main.y1 }, mask };
  }

  /** Cadre carré (fractions 0..1 de la grille) autour de l'objet, avec une marge, sans sortir de l'image. */
  function squareBox(bbox, w, h, marge = 0.12) {
    const bw = bbox.x1 - bbox.x0 + 1, bh = bbox.y1 - bbox.y0 + 1;
    const s = Math.min(Math.max(w, h), Math.max(bw, bh) * (1 + 2 * marge));
    let cx = (bbox.x0 + bbox.x1 + 1) / 2, cy = (bbox.y0 + bbox.y1 + 1) / 2;
    let x = cx - s / 2, y = cy - s / 2;
    x = Math.max(0, Math.min(w - s, x)); y = Math.max(0, Math.min(h - s, y));
    return { x: x / w, y: y / h, s: s / w };
  }

  /** Différence moyenne entre deux images (0..255) : sert à savoir si la scène a changé. */
  function change(a, b, n) { let s = 0; for (let i = 0; i < n; i++) { const k = i * 4; s += Math.abs(a[k] - b[k]) + Math.abs(a[k + 1] - b[k + 1]) + Math.abs(a[k + 2] - b[k + 2]); } return s / (3 * n); }

  const api = { G, segment, squareBox, change, _fromBorder: fromBorder, _fromBackground: fromBackground };
  if (typeof module !== 'undefined' && module.exports) module.exports = api; else root.PMObjet = api;
})(typeof window !== 'undefined' ? window : this);
