/*
 * POS Marwa — module « Reconnaissance des articles par photo ».
 *
 * Fonctionne partout où POS Marwa attend un article (caisse, restaurant, inventaire, étiquettes,
 * pharmacie, recherche d'articles) : le bouton « Reconnaître » apparaît dans la fenêtre de la
 * caméra et à côté du bouton caméra de la caisse.
 *
 * Principe (tout se passe dans le navigateur, sans Internet) :
 *  1. le réseau de neurones MobileNet v2 (fourni avec le module) transforme l'image cadrée en
 *     « empreinte visuelle » (1 280 nombres, réduits à 512) ;
 *  2. l'empreinte est comparée à celles des photos apprises de chaque article ;
 *  3. les articles les plus ressemblants sont proposés ; le caissier confirme d'un geste, ou
 *     corrige — la photo est alors apprise et la reconnaissance s'améliore.
 * Le code-barres reste prioritaire : la lecture des codes continue pendant la reconnaissance.
 *
 * Version 1.2 — l'article plutôt que l'arrière-plan :
 *  - « fond appris » : une photo du comptoir vide (par poste et par caméra) permet de savoir s'il y a
 *    un article devant la caméra et de le détourer ; sans fond appris, les bords de l'image servent
 *    de modèle d'arrière-plan (vision-objet.js) ;
 *  - l'article détouré est posé sur un fond gris neutre (« transparence ») avant le calcul de son
 *    empreinte : le mur, le comptoir ou le rayon ne comptent plus ;
 *  - chaque photo apprise donne deux empreintes : « objet isolé » et « image entière » ;
 *  - en caisse, mode continu : un article sûr et vu sur 2 images de suite est ajouté tout seul,
 *    puis la caméra attend qu'il soit retiré avant de reconnaître le suivant (bouton « Annuler »).
 */
(function () {
  'use strict';
  if (!window.PMScanner) return;
  const T = (s) => (window.__ ? __(s) : s);
  const meta = (n) => { const m = document.querySelector(`meta[name="${n}"]`); return m ? m.content : ''; };
  const BASE = ((window.PW && PW.BASE) || meta('base-url') || '').replace(/\/$/, '');
  const CSRF = () => meta('csrf-token') || ((document.querySelector('input[name="_csrf"]') || {}).value || '');
  const SELF = (document.currentScript && document.currentScript.src) || '';
  const DIR = SELF.replace(/vision\.js.*$/, '');
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const fmt = (n) => (window.PWMoney ? PWMoney.fmt(n) : Math.round(n) + ' F');

  const D = 1280, P = 512, SIZE = 224;
  const CROPS = [1.0, 0.8];        // recadrages moyennés (stabilité)
  const CENTER_MIN_REFS = 12;      // centrage des empreintes dès qu'il y a assez de photos apprises
  const CENTER_MIN_PRODS = 3;      // … et au moins 3 articles (avec 2, le centrage les rendrait opposés)
  // wholePenalty : l'image entière (avec son arrière-plan) compte un peu moins qu'un article isolé.
  // sizeWeight : taille apparente — mesurée sans gain sur le banc (distance à la caméra variable) : désactivée.
  const TUNE = { wholePenalty: 0.02, sizeWeight: 0 }; // réglé par les mesures (voir README)

  // ------------------------------------------------------------------ moteur
  const E = { model: null, loading: null, backend: '' };
  function loadScript(src) {
    return new Promise((res, rej) => { const s = document.createElement('script'); s.src = src; s.onload = res; s.onerror = () => rej(new Error(T('Fichier introuvable : ') + src)); document.head.appendChild(s); });
  }
  async function loadEngine(step) {
    if (E.model) return E;
    if (!E.loading) {
      E.loading = (async () => {
        if (step) step(T('Chargement de l\'intelligence de reconnaissance (≈ 10 Mo, une seule fois par appareil)…'));
        // TensorFlow.js essaie « regeneratorRuntime = … » puis, en cas d'échec, une évaluation de code
        // que la politique de sécurité de POS Marwa interdit (pas d'« unsafe-eval ») : déclarer la
        // variable avant le chargement suffit, sans modifier la bibliothèque ni affaiblir la sécurité.
        if (!('regeneratorRuntime' in window)) window.regeneratorRuntime = undefined;
        if (!window.tf) await loadScript(DIR + 'tf.min.js');
        const tf = window.tf;
        for (const b of ['webgl', 'cpu']) {
          try { if (await tf.setBackend(b)) { await tf.ready(); break; } } catch (e) { /* essai suivant */ }
        }
        E.backend = tf.getBackend();
        E.model = await tf.loadGraphModel(DIR + 'modele/modele.txt');
        const w = E.model.predict(tf.zeros([1, SIZE, SIZE, 3])); await w.data(); w.dispose(); // préchauffage
        return E;
      })();
    }
    try { return await E.loading; } catch (e) { E.loading = null; throw e; }
  }

  // Projection aléatoire fixe 1 280 → 512 (identique sur tous les appareils)
  let R = null;
  function projection() {
    if (R) return R;
    R = new Int8Array(D * P); let s = 0x504d5631;
    for (let i = 0; i < R.length; i++) { s ^= s << 13; s ^= s >>> 17; s ^= s << 5; R[i] = (s & 1) ? 1 : -1; }
    return R;
  }
  function normalize(v) { let n = 0; for (let i = 0; i < v.length; i++) n += v[i] * v[i]; n = Math.sqrt(n) || 1; for (let i = 0; i < v.length; i++) v[i] /= n; return v; }
  function dot(a, b, off = 0) { let d = 0; for (let i = 0; i < P; i++) d += a[i] * b[off + i]; return d; }
  function quantize(f) { let mx = 0; for (const x of f) mx = Math.max(mx, Math.abs(x)); return Int8Array.from(f, (x) => Math.round(x / (mx || 1) * 127)); }
  function b64(q) { const u = new Uint8Array(q.buffer, q.byteOffset, q.byteLength); let s = ''; for (let i = 0; i < u.length; i++) s += String.fromCharCode(u[i]); return btoa(s); }

  /** Région carrée (pixels de la source) correspondant au cadre affiché sur la vidéo. */
  function aimRegion(video, aim) {
    const vw = video.videoWidth, vh = video.videoHeight;
    const vr = video.getBoundingClientRect(), ar = aim ? aim.getBoundingClientRect() : null;
    if (ar && vr.width && ar.width) {
      const k = Math.max(vr.width / vw, vr.height / vh); // object-fit: cover
      const ox = (vr.width - vw * k) / 2, oy = (vr.height - vh * k) / 2;
      let side = Math.min(ar.width / k, vw, vh);
      let x = (ar.left - vr.left - ox) / k, y = (ar.top - vr.top - oy) / k;
      x = Math.max(0, Math.min(vw - side, x)); y = Math.max(0, Math.min(vh - side, y));
      return { x, y, s: side };
    }
    const side = Math.min(vw, vh) * 0.85;
    return { x: (vw - side) / 2, y: (vh - side) / 2, s: side };
  }
  function centerRegion(w, h, frac = 1) { const s = Math.min(w, h) * frac; return { x: (w - s) / 2, y: (h - s) / 2, s }; }

  /** Empreinte d'une région carrée d'une image, vidéo ou canvas → { f: Float32Array(512), q: Int8Array(512) }. */
  async function embed(source, region) {
    const { model } = await loadEngine();
    const tf = window.tf;
    const cv = document.createElement('canvas'); cv.width = SIZE; cv.height = SIZE;
    const g = cv.getContext('2d', { willReadFrequently: true });
    const batch = CROPS.map((c) => {
      const s = region.s * c, x = region.x + (region.s - s) / 2, y = region.y + (region.s - s) / 2;
      g.drawImage(source, x, y, s, s, 0, 0, SIZE, SIZE);
      return tf.tidy(() => tf.browser.fromPixels(cv).toFloat().mul(2 / 255).sub(1));
    });
    const out = tf.tidy(() => model.predict(tf.stack(batch)).reshape([CROPS.length, D]));
    const data = await out.data();
    out.dispose(); batch.forEach((t) => t.dispose());
    const avg = new Float32Array(D);
    for (let c = 0; c < CROPS.length; c++) {
      let n = 0; for (let i = 0; i < D; i++) n += data[c * D + i] ** 2; n = Math.sqrt(n) || 1;
      for (let i = 0; i < D; i++) avg[i] += data[c * D + i] / n;
    }
    return project(avg);
  }
  function project(avg) {
    const Rm = projection(); const f = new Float32Array(P);
    for (let i = 0; i < D; i++) { const x = avg[i]; if (!x) continue; const r = i * P; for (let j = 0; j < P; j++) f[j] += x * Rm[r + j]; }
    normalize(f);
    return { f, q: quantize(f) };
  }
  /** Empreinte d'une image déjà préparée (224 × 224), par exemple l'article détouré. */
  async function embedCanvas(cv) {
    const { model } = await loadEngine(); const tf = window.tf;
    const t = tf.tidy(() => model.predict(tf.browser.fromPixels(cv).toFloat().mul(2 / 255).sub(1).expandDims(0)).reshape([D]));
    const d = await t.data(); t.dispose();
    let n = 0; for (let i = 0; i < D; i++) n += d[i] * d[i]; n = Math.sqrt(n) || 1;
    const avg = new Float32Array(D); for (let i = 0; i < D; i++) avg[i] = d[i] / n;
    return project(avg);
  }
  function thumbOf(source, region, size = 112) {
    const c = document.createElement('canvas'); c.width = size; c.height = size;
    c.getContext('2d').drawImage(source, region.x, region.y, region.s, region.s, 0, 0, size, size);
    return c.toDataURL('image/jpeg', 0.72);
  }

  // ------------------------------------------------------------------ article isolé de l'arrière-plan (1.2)
  const O = window.PMObjet || null;
  const MODEL_OBJ = 'mnv2-p512-obj';
  const GRID = O ? O.G : 112;
  const gridCanvas = document.createElement('canvas');
  function grid(source, region) {
    gridCanvas.width = gridCanvas.height = GRID;
    const g = gridCanvas.getContext('2d', { willReadFrequently: true });
    g.drawImage(source, region.x, region.y, region.s, region.s, 0, 0, GRID, GRID);
    return g.getImageData(0, 0, GRID, GRID).data;
  }
  /**
   * Détoure l'article dans la région : { present, frac, objets, mode, cur, box, cut (fond transparent),
   * canvas (fond gris neutre, prêt pour l'empreinte) }. present = false : rien devant la caméra
   * (avec fond appris) ; null : on ne sait pas (sans fond, détourage impossible).
   */
  function isolate(source, region, fond) {
    if (!O) return { present: null, frac: 0, objets: 0, mode: 'aucun', cur: null };
    const cur = grid(source, region);
    const bg = fond ? fond.grid(region) : null;
    const s = O.segment(cur, bg, GRID, GRID);
    const ok = s.objets > 0 && s.frac >= (bg ? 0.015 : 0.06) && s.frac <= (bg ? 0.97 : 0.9);
    const out = { present: bg ? ok : (ok ? true : null), frac: s.frac, objets: s.objets, mode: s.mode, cur };
    if (!ok) return out;
    const q = O.squareBox(s.bbox, GRID, GRID);
    const box = { x: region.x + q.x * region.s, y: region.y + q.y * region.s, s: q.s * region.s };
    const mc = document.createElement('canvas'); mc.width = mc.height = GRID; const mg = mc.getContext('2d');
    const md = mg.createImageData(GRID, GRID);
    for (let i = 0; i < GRID * GRID; i++) md.data[i * 4 + 3] = s.mask[i] ? 255 : 0;
    mg.putImageData(md, 0, 0);
    const cut = document.createElement('canvas'); cut.width = cut.height = SIZE; const cg = cut.getContext('2d');
    cg.drawImage(source, box.x, box.y, box.s, box.s, 0, 0, SIZE, SIZE);
    cg.globalCompositeOperation = 'destination-in'; cg.filter = 'blur(2px)'; // bords adoucis
    cg.drawImage(mc, q.x * GRID, q.y * GRID, q.s * GRID, q.s * GRID, 0, 0, SIZE, SIZE);
    cg.filter = 'none'; cg.globalCompositeOperation = 'source-over';
    const cv = document.createElement('canvas'); cv.width = cv.height = SIZE; const g = cv.getContext('2d');
    g.fillStyle = '#808080'; g.fillRect(0, 0, SIZE, SIZE); g.drawImage(cut, 0, 0);
    return Object.assign(out, { box, cut, canvas: cv });
  }
  /** Fond appris : photo du comptoir vide, gardée sur ce poste pour cette caméra. */
  const Fond = {
    cache: new Map(),
    key(video) {
      let l = '';
      try { const tr = video.srcObject && video.srcObject.getVideoTracks()[0]; l = (tr && tr.label) || ''; } catch (e) { /* flux sans piste */ }
      return 'pm-vision-fond:' + (l || 'camera').slice(0, 80) + ':' + video.videoWidth + 'x' + video.videoHeight;
    },
    wrap(img, vw) {
      const sc = img.naturalWidth / vw;
      return { img, grid: (r) => {
        const c = document.createElement('canvas'); c.width = c.height = GRID; const g = c.getContext('2d', { willReadFrequently: true });
        g.drawImage(img, r.x * sc, r.y * sc, r.s * sc, r.s * sc, 0, 0, GRID, GRID); return g.getImageData(0, 0, GRID, GRID).data;
      } };
    },
    async get(video) {
      if (!video || !video.videoWidth) return null;
      const k = this.key(video);
      if (this.cache.has(k)) return this.cache.get(k);
      let v = null;
      try { const u = localStorage.getItem(k); if (u) v = this.wrap(await loadImage(u), video.videoWidth); } catch (e) { v = null; }
      this.cache.set(k, v); return v;
    },
    async learn(video) {
      const w = Math.min(480, video.videoWidth), h = Math.round(video.videoHeight * w / video.videoWidth);
      const c = document.createElement('canvas'); c.width = w; c.height = h; c.getContext('2d').drawImage(video, 0, 0, w, h);
      const u = c.toDataURL('image/jpeg', 0.92); const k = this.key(video);
      try { localStorage.setItem(k, u); } catch (e) { /* stockage plein : fond gardé jusqu'à la fermeture */ }
      const v = this.wrap(await loadImage(u), video.videoWidth); this.cache.set(k, v); return v;
    },
    forget(video) { const k = this.key(video); try { localStorage.removeItem(k); } catch (e) { /* rien */ } this.cache.set(k, null); },
  };
  /**
   * Analyse d'une image : article détouré (si possible) et image entière.
   * Renvoie { present, iso, obj:{f,q}|null, whole:{f,q}|null, thumb, thumbObj }.
   * Détourage utilisé : avec un fond appris (fiable), ou d'après les bords si opts.bords
   * (apprentissage : photos sur fond uni). Mesuré sur photos réelles en rayon : sans fond appris,
   * le détourage d'après les bords n'améliore pas la reconnaissance — la caisse ne l'utilise pas.
   */
  async function analyse(source, region, fond, opts = {}) {
    const iso = fond || opts.bords ? isolate(source, region, fond) : { present: null, frac: 0, objets: 0, mode: 'aucun', cur: O ? grid(source, region) : null };
    // taille apparente de l'article (racine de sa surface dans le cadre) : significative avec un fond
    // appris (caméra fixe) ; distingue deux formats d'un même article (33 cl / 50 cl, 0,5 L / 1,5 L)
    const a = { present: iso.present, iso, obj: null, whole: null, thumb: null, thumbObj: null, size: iso.mode === 'fond' && iso.canvas ? Math.round(Math.sqrt(iso.frac) * 1000) / 1000 : 0 };
    if (fond && !iso.present) return a;
    if (iso.canvas) { a.obj = await embedCanvas(iso.canvas); a.thumbObj = thumbOf(iso.canvas, { x: 0, y: 0, s: SIZE }); }
    a.whole = await embed(source, region); a.thumb = thumbOf(source, region);
    return a;
  }
  // ------------------------------------------------------------------ serveur
  async function api(path, body) {
    const o = { headers: { Accept: 'application/json' }, credentials: 'same-origin' };
    if (body) { o.method = 'POST'; o.headers['Content-Type'] = 'application/json'; o.headers['X-CSRF-Token'] = CSRF(); o.body = JSON.stringify(Object.assign({ _csrf: CSRF() }, body)); }
    const r = await fetch(BASE + path, o);
    const d = await r.json().catch(() => ({ ok: false, error: T('Réponse invalide du serveur.') }));
    if (!r.ok && d.ok !== false) d.ok = false;
    return d;
  }
  let cfg = null, cfgP = null;
  function config(force) {
    if (!force && cfgP) return cfgP;
    cfgP = fetch(BASE + '/vision/config', { headers: { Accept: 'application/json' }, credentials: 'same-origin', cache: 'no-store' })
      .then((r) => (r.ok ? r.json() : { ok: false })).catch(() => ({ ok: false })).then((d) => (cfg = d));
    return cfgP;
  }

  // ------------------------------------------------------------------ index des photos apprises
  // modes : 0 = image entière, 1 = objet isolé (une moyenne de centrage par mode)
  const IDX = { version: -1, n: 0, pids: [], ids: [], modes: [], sizes: [], raw: new Float32Array(0), mat: new Float32Array(0), mean: [null, null] };
  function rebuild() {
    const n = IDX.n, raw = IDX.raw;
    IDX.mean = [null, null];
    for (const m of [0, 1]) {
      const rows = []; for (let r = 0; r < n; r++) if ((IDX.modes[r] || 0) === m) rows.push(r);
      if (rows.length >= CENTER_MIN_REFS && new Set(rows.map((r) => IDX.pids[r])).size >= CENTER_MIN_PRODS) {
        // Centrage : on retire ce que toutes les photos ont en commun (fond, éclairage de la caisse)
        const mean = new Float32Array(P);
        for (const r of rows) for (let j = 0; j < P; j++) mean[j] += raw[r * P + j] / rows.length;
        IDX.mean[m] = mean;
      }
    }
    const mat = new Float32Array(n * P);
    for (let r = 0; r < n; r++) {
      const v = mat.subarray(r * P, r * P + P), mean = IDX.mean[IDX.modes[r] || 0];
      for (let j = 0; j < P; j++) v[j] = raw[r * P + j] - (mean ? mean[j] : 0);
      normalize(v);
    }
    IDX.mat = mat;
  }
  function decodeRefs(b64s) {
    const bin = atob(b64s || ''); const n = bin.length / P; const raw = new Float32Array(n * P);
    for (let r = 0; r < n; r++) {
      const v = raw.subarray(r * P, r * P + P);
      for (let j = 0; j < P; j++) { const b = bin.charCodeAt(r * P + j); v[j] = b > 127 ? b - 256 : b; }
      normalize(v);
    }
    return raw;
  }
  async function loadIndex(force) {
    const c = await config(force);
    if (!c || c.ok === false) throw new Error(T('Reconnaissance par photo non autorisée pour votre compte.'));
    if (!force && IDX.version === c.version) return IDX;
    const d = await api('/vision/index?v=' + c.version);
    if (!d.ok) throw new Error(d.error || T('Index des photos indisponible.'));
    IDX.version = d.version; IDX.pids = d.pids; IDX.ids = d.ids; IDX.modes = d.modes || d.pids.map(() => 0); IDX.sizes = d.sizes || d.pids.map(() => 0); IDX.raw = decodeRefs(d.vecs); IDX.n = d.pids.length;
    rebuild();
    return IDX;
  }
  function addLocal(pid, q, mode = 0, size = 0) {
    const v = normalize(Float32Array.from(q));
    const raw = new Float32Array((IDX.n + 1) * P); raw.set(IDX.raw); raw.set(v, IDX.n * P);
    IDX.raw = raw; IDX.pids = IDX.pids.concat([pid]); IDX.ids = IDX.ids.concat([0]); IDX.modes = IDX.modes.concat([mode]); IDX.sizes = IDX.sizes.concat([size || 0]); IDX.n++;
    rebuild();
  }
  function prepareQuery(f, mode = 0) {
    const v = Float32Array.from(f), mean = IDX.mean[mode];
    if (mean) for (let j = 0; j < P; j++) v[j] -= mean[j];
    return normalize(v);
  }
  /**
   * Articles classés par ressemblance : [{ pid, score }] (score = meilleure photo, renforcée par la 2ᵉ).
   * query : empreinte (Float32Array, image entière) ou analyse { whole, obj } ; chaque photo apprise
   * est comparée à l'empreinte de même nature (objet isolé avec objet isolé).
   */
  function match(query, exclude) {
    const qs = query instanceof Float32Array ? { whole: query, obj: null, size: 0 } : { whole: query.whole && (query.whole.f || query.whole), obj: query.obj && (query.obj.f || query.obj), size: query.size || 0 };
    const base = qs.whole || qs.obj; if (!base) return [];
    const Q = [prepareQuery(qs.whole || qs.obj, 0), prepareQuery(qs.obj || qs.whole, 1)];
    // ressemblance brute (sans centrage) : un article jamais appris reste loin de toutes les photos
    const R = [normalize(Float32Array.from(qs.whole || qs.obj)), normalize(Float32Array.from(qs.obj || qs.whole))];
    // Article détouré disponible : l'image entière (avec son arrière-plan) compte un peu moins
    const pen = qs.obj && qs.whole ? TUNE.wholePenalty : 0;
    const per = new Map();
    for (let r = 0; r < IDX.n; r++) {
      const pid = IDX.pids[r]; if (exclude && pid === exclude) continue;
      const m = IDX.modes[r] || 0;
      if (m === 1 && !qs.obj) continue; // pas d'article détouré : on ne compare pas à des empreintes détourées
      let s = dot(Q[m], IDX.mat, r * P) - (m === 0 ? pen : 0);
      // tailles connues des deux côtés : un format différent (33 cl / 50 cl) est pénalisé
      if (m === 1 && qs.size && IDX.sizes[r]) s -= TUNE.sizeWeight * Math.abs(Math.log(qs.size / IDX.sizes[r]));
      const raw = dot(R[m], IDX.raw, r * P);
      const a = per.get(pid);
      if (!a) { const rw = [-2, -2]; rw[m] = raw; per.set(pid, [s, -2, m, rw]); } else { if (s > a[0]) { a[1] = a[0]; a[0] = s; a[2] = m; } else if (s > a[1]) a[1] = s; if (raw > a[3][m]) a[3][m] = raw; }
    }
    // obj : le meilleur score vient d'une empreinte « article isolé » (seuils propres, voir sure())
    return [...per.entries()].map(([pid, [b, s2, m, raw]]) => ({ pid, score: s2 > -2 ? 0.75 * b + 0.25 * s2 : b, obj: m === 1, raw: raw[m] })).sort((a, b) => b.score - a.score);
  }
  const productCache = new Map();
  async function products(ids) {
    const miss = ids.filter((id) => !productCache.has(id));
    if (miss.length) { const d = await api('/vision/products?ids=' + miss.join(',')); Object.values(d.items || {}).forEach((p) => productCache.set(p.id, p)); }
    return ids.map((id) => productCache.get(id)).filter(Boolean);
  }

  // ------------------------------------------------------------------ caméras IP (images relayées par le serveur)
  /**
   * Flux vidéo d'une caméra IP : les images lues par le serveur sont dessinées dans un canevas
   * qui fournit un flux vidéo ordinaire (lu par le scanner, la reconnaissance et la surveillance).
   * Fonctionne aussi sans HTTPS : aucune autorisation de caméra du navigateur n'est nécessaire.
   */
  function ipStream(camId, fps = 3, onStatus) {
    return new Promise((resolve, reject) => {
      const cv = document.createElement('canvas'); const g = cv.getContext('2d');
      let stopped = false, timer = null, first = true, fails = 0, stream = null;
      const period = Math.max(100, Math.round(1000 / Math.max(1, Math.min(10, fps))));
      const tick = () => {
        if (stopped) return;
        const img = new Image(); const t0 = Date.now();
        img.onload = () => {
          if (stopped) return;
          fails = 0;
          if (cv.width !== img.naturalWidth || cv.height !== img.naturalHeight) { cv.width = img.naturalWidth; cv.height = img.naturalHeight; }
          g.drawImage(img, 0, 0);
          if (first) {
            first = false;
            stream = cv.captureStream ? cv.captureStream(Math.max(1, fps)) : null;
            if (!stream) { reject(new Error(T('Ce navigateur ne sait pas afficher une caméra IP (utilisez Chrome, Edge ou Firefox récents).'))); return; }
            resolve({ stream, canvas: cv, stop });
          }
          if (onStatus) onStatus('ok');
          timer = setTimeout(tick, Math.max(0, period - (Date.now() - t0)));
        };
        img.onerror = () => {
          if (stopped) return;
          fails++;
          if (onStatus) onStatus('error', fails);
          if (first && fails >= 3) { stop(); reject(new Error(T('Caméra IP injoignable : vérifiez son adresse dans Configuration → Caméras (bouton « Tester »).'))); return; }
          timer = setTimeout(tick, Math.min(5000, period * (1 + fails)));
        };
        img.src = BASE + '/vision/cameras/' + camId + '/frame?t=' + Date.now();
      };
      function stop() { stopped = true; clearTimeout(timer); if (stream) stream.getTracks().forEach((tr) => tr.stop()); }
      tick();
    });
  }
  let sourcesP = null;
  function cameraSources() {
    if (!sourcesP) sourcesP = api('/vision/sources').then((d) => (d.ok ? d.items : [])).catch(() => []);
    return sourcesP;
  }

  // ------------------------------------------------------------------ outil du scanner
  const S = { ctx: null, busy: false, auto: false, autoAdd: false, timer: null, votes: [], wait: null, panelPid: null, last: null, fond: null };
  const ctxName = () => (location.pathname.replace(BASE, '').split('/')[1] || 'accueil').replace(/[^a-z_]/g, '').slice(0, 30);
  const store = (k, v) => { try { if (v === undefined) return localStorage.getItem(k); localStorage.setItem(k, v); } catch (e) { return null; } return null; };

  async function prepare(ctx) {
    ctx.msg(T('Préparation de la reconnaissance…'));
    await Promise.all([loadEngine((t) => ctx.msg(t)), loadIndex()]);
    S.fond = ctx.live() ? await Fond.get(ctx.video) : null;
  }
  const region = (ctx) => aimRegion(ctx.video, ctx.el.querySelector('.pm-scan-aim'));
  async function captureLive(ctx, frames = 2) {
    const video = ctx.video;
    for (let i = 0; i < 30 && (video.readyState < 2 || !video.videoWidth); i++) await new Promise((r) => setTimeout(r, 100));
    if (!video.videoWidth) throw new Error(T('La caméra ne donne pas d\'image : « Relancer la caméra ».'));
    let acc = null;
    for (let i = 0; i < frames; i++) {
      const a = await analyse(video, region(ctx), S.fond);
      if (S.fond && !a.present) return a;
      if (!acc) acc = a; else {
        // moyenne des empreintes des images successives (stabilité)
        for (const k of ['whole', 'obj']) if (acc[k] && a[k]) { const f = new Float32Array(P); for (let j = 0; j < P; j++) f[j] = acc[k].f[j] + a[k].f[j]; normalize(f); acc[k] = { f, q: quantize(f) }; }
      }
      if (i < frames - 1) await new Promise((r) => setTimeout(r, 120));
    }
    return acc;
  }
  function pickPhoto(ctx) {
    const inp = document.createElement('input'); inp.type = 'file'; inp.accept = 'image/*'; inp.setAttribute('capture', 'environment');
    inp.addEventListener('change', async () => {
      const file = inp.files && inp.files[0]; if (!file) return;
      try {
        const img = await loadImage(URL.createObjectURL(file));
        ctx.msg(T('Analyse de la photo…'));
        const a = await analyse(img, centerRegion(img.naturalWidth, img.naturalHeight, 0.9), null);
        URL.revokeObjectURL(img.src);
        await propose(ctx, a);
      } catch (err) { ctx.msg(err.message || String(err), 'bad'); }
    });
    inp.click();
  }
  function loadImage(src) { return new Promise((res, rej) => { const i = new Image(); i.onload = () => res(i); i.onerror = () => rej(new Error(T('Image illisible.'))); i.src = src; }); }

  function panel(ctx) {
    let p = ctx.extra.querySelector('.pm-vis-panel');
    if (!p) {
      p = document.createElement('div'); p.className = 'pm-vis-panel';
      const canAuto = ctx.live();
      p.innerHTML = `<div class="pm-vis-head"><i class="bi bi-eye"></i> <span data-h>${T('Reconnaissance par photo')}</span>
        ${canAuto ? `<label title="${esc(T('Analyse en continu ce qui est devant la caméra'))}"><input type="checkbox" data-auto ${S.auto ? 'checked' : ''}> ${T('En continu')}</label>
        <label title="${esc(T('Ajoute tout seul chaque article reconnu avec certitude, puis attend qu\'il soit retiré'))}"><input type="checkbox" data-autoadd ${S.autoAdd ? 'checked' : ''}> ${T('Ajout automatique')}</label>` : ''}</div>
        <div class="pm-vis-state" data-state></div>
        ${canAuto ? `<div class="pm-vis-fond"><button type="button" class="pm-btn" data-fond title="${esc(T('Videz le cadre (comptoir vide), puis touchez ce bouton'))}">🧱 ${T('Apprendre le fond')}</button> <span data-fondst></span></div>` : ''}
        <div class="pm-vis-list" data-list></div>
        <div class="pm-vis-other"><input type="search" data-q placeholder="${esc(T('Autre article : nom, référence…'))}" autocomplete="off"><button type="button" class="pm-btn" data-none>${T('Aucun')}</button></div>
        <div class="pm-vis-found" data-found></div><div class="pm-vis-note" data-note></div>`;
      ctx.extra.appendChild(p);
      const a = p.querySelector('[data-auto]');
      if (a) a.addEventListener('change', () => { S.auto = a.checked; store('pm-vision-continu', S.auto ? '1' : '0'); if (S.auto) loop(); else { stopLoop(); state(ctx, ''); } });
      const aa = p.querySelector('[data-autoadd]');
      if (aa) aa.addEventListener('change', () => { S.autoAdd = aa.checked; store('pm-vision-ajout', S.autoAdd ? '1' : '0'); if (S.autoAdd && !S.auto) { S.auto = true; p.querySelector('[data-auto]').checked = true; loop(); } });
      const fb = p.querySelector('[data-fond]');
      if (fb) fb.addEventListener('click', async () => {
        if (!ctx.live()) return;
        if (S.fond && !confirm(T('Remplacer le fond appris ? Le cadre doit être vide (comptoir sans article).'))) return;
        S.fond = await Fond.learn(ctx.video); S.votes = []; S.wait = null; fondState(ctx);
        note(ctx, T('Fond appris : seuls les articles posés devant ce fond seront analysés.'));
      });
      let t = null;
      p.querySelector('[data-q]').addEventListener('input', (e) => {
        clearTimeout(t); const v = e.target.value.trim();
        t = setTimeout(async () => {
          if (v.length < 2) { p.querySelector('[data-found]').innerHTML = ''; return; }
          const d = await api('/vision/search?q=' + encodeURIComponent(v));
          (d.items || []).forEach((it) => productCache.set(it.id, it));
          p.querySelector('[data-found]').innerHTML = (d.items || []).map((it) => `<button type="button" data-pick="${it.id}">${esc(it.name)} <small class="text-secondary">${esc(it.code)} · ${fmt(it.price)}</small></button>`).join('') || `<small>${T('Aucun article trouvé.')}</small>`;
        }, 250);
      });
      p.addEventListener('click', (e) => {
        const c = e.target.closest('[data-pick]');
        if (c) { const pr = productCache.get(Number(c.dataset.pick)); if (pr) choose(ctx, pr, c.closest('[data-found]') ? 'corrige' : null); return; }
        const u = e.target.closest('[data-undo]');
        if (u) { undo(ctx, Number(u.dataset.undo), u.dataset.code); return; }
        if (e.target.closest('[data-none]')) { if (S.last && ctx.opts.visionLog !== false) logUse('aucun', null); p.querySelector('[data-list]').innerHTML = ''; note(ctx, T('D\'accord. Recadrez l\'article puis touchez « Reconnaître », ou cherchez-le par son nom.')); S.last = null; S.panelPid = null; }
      });
      fondState(ctx);
    }
    return p;
  }
  function note(ctx, html) { const n = panel(ctx).querySelector('[data-note]'); n.innerHTML = html; }
  function state(ctx, html) { const n = panel(ctx).querySelector('[data-state]'); if (n && n.innerHTML !== html) n.innerHTML = html; }
  function fondState(ctx) {
    const st = panel(ctx).querySelector('[data-fondst]'); if (!st) return;
    st.innerHTML = S.fond ? `<span class="pm-vis-ok">✔ ${T('fond appris : l\'arrière-plan est ignoré')}</span>` : `<span class="pm-vis-warn">${T('Conseillé : apprenez le fond (cadre vide) pour que le comptoir ou le mur ne soient jamais pris pour un article.')}</span>`;
  }

  /** Marge exigée : doublée entre deux articles déjà confondus (journal des corrections). */
  function marginNeeded(a, b, base) {
    const c = cfg && cfg.confusions;
    if (!c || !b) return base;
    return (c.pairs || []).some(([x, y]) => (x === a && y === b) || (x === b && y === a)) || (c.risky || []).includes(a) ? base * 2 : base;
  }
  /**
   * Reconnaissance « sûre » : note et écart avec le 2ᵉ article suffisants. Les empreintes d'articles
   * isolés n'ont pas la même échelle que celles d'images entières : seuils propres, calibrés par mesures.
   */
  function sure(res) {
    const best = res[0]; if (!best) return { sure: false, margin: 0 };
    const margin = best.score - (res[1] ? res[1].score : -1);
    const seuil = best.obj ? cfg.autoScoreObj : cfg.autoScore, marge = best.obj ? cfg.marginObj : cfg.margin;
    return { sure: best.score >= seuil && margin >= marginNeeded(best.pid, res[1] && res[1].pid, marge), margin };
  }

  async function propose(ctx, shot, fromLoop) {
    const p = panel(ctx);
    if (S.fond && shot.present === false) { if (!fromLoop) { note(ctx, T('Aucun article dans le cadre : posez l\'article devant la caméra.')); ctx.msg(T('Aucun article dans le cadre.'), 'warn'); } return; }
    const res = match(shot).slice(0, 4);
    if (!res.length) { note(ctx, T('Aucun article appris pour le moment.')); return; }
    const best = res[0], { sure: ok, margin } = sure(res);
    if (fromLoop && best.pid === S.panelPid) return; // déjà affiché
    const info = await products(res.map((r) => r.pid));
    S.last = { shot, res, best, margin, sure: ok };
    S.panelPid = best.pid;
    p.querySelector('[data-h]').textContent = ok ? T('Article reconnu :') : T('Est-ce l\'un de ces articles ?');
    p.querySelector('[data-list]').innerHTML = res.map((r, i) => {
      const it = info.find((x) => x.id === r.pid); if (!it) return '';
      // Barre relative au meilleur candidat (la note brute n'a pas de sens pour le caissier)
      const pct = Math.max(4, Math.min(100, Math.round(100 * Math.max(0, r.score) / Math.max(0.0001, res[0].score))));
      return `<button type="button" class="pm-vis-card ${i === 0 && ok ? 'best' : ''}" data-pick="${it.id}" title="${esc(T('Touchez pour ajouter'))}">
        ${it.image ? `<img src="${esc(it.image)}" alt="">` : '<span class="pm-vis-noimg">📦</span>'}
        <b>${esc(it.name)}</b><small>${fmt(it.price)}${it.track ? ' · ' + T('stock') + ' ' + (Math.round(it.stock * 100) / 100) : ''}</small>
        <span class="pm-vis-score" title="${esc(T('Ressemblance comparée au 1er article'))} : ${pct} %"><span style="width:${pct}%"></span></span></button>`;
    }).join('');
    p.querySelector('[data-found]').innerHTML = ''; p.querySelector('[data-q]').value = '';
    note(ctx, ok ? T('Touchez l\'article pour l\'ajouter.') : T('Pas sûr : touchez le bon article, ou cherchez-le ci-dessous (il sera appris).'));
    ctx.msg(ok ? T('Article reconnu.') : T('Plusieurs articles possibles.'), ok ? 'good' : 'warn');
    if (ok && !fromLoop && cfg.auto && ctx.opts.visionAuto !== false) {
      const it = info.find((x) => x.id === best.pid);
      if (it) await choose(ctx, it, 'auto');
    }
  }

  async function choose(ctx, it, outcome) {
    const last = S.last;
    const out = outcome || (last && last.best.pid === it.id ? 'confirme' : 'corrige');
    const r = await ctx.deliver(it.code);
    if (r && r.ok === false) { note(ctx, esc(r.text || T('Article refusé.'))); return; }
    // En continu : attendre que l'article soit retiré avant d'en reconnaître un autre
    S.wait = S.fond ? { fond: true } : (last && last.shot.iso && last.shot.iso.cur ? { cur: last.shot.iso.cur } : { t: Date.now() });
    S.votes = [];
    if (last && ctx.opts.visionLog !== false) logUse(out, it.id);
    let learned = false;
    if (last && out !== 'auto' && cfg.learn && ctx.opts.visionLearn !== false) {
      // Photo confirmée par le caissier : apprise (l'article détouré si possible)
      const sh = last.shot, obj = sh.obj && sh.obj.q, whole = sh.whole && sh.whole.q;
      const vec = obj || whole || (sh.q);
      if (vec) {
        const d = await api('/vision/refs', { product_id: it.id, vec: b64(vec), thumb: (obj ? sh.thumbObj : sh.thumb) || null, source: 'vente', model: obj ? MODEL_OBJ : undefined, size: obj && sh.size ? sh.size : undefined });
        if (d.ok && d.id) { addLocal(it.id, vec, obj ? 1 : 0, obj ? sh.size : 0); IDX.version = d.version; learned = true; }
      }
    }
    const canUndo = out === 'auto' && typeof ctx.undo === 'function';
    note(ctx, `✔ <b>${esc(it.name)}</b> ${out === 'auto' ? T('ajouté automatiquement') : T('ajouté')}${learned ? ' · ' + T('photo apprise pour la prochaine fois') : ''}`
      + (canUndo ? ` <button type="button" class="pm-btn pm-vis-undo" data-undo="${it.id}" data-code="${esc(it.code)}">↶ ${T('Annuler')}</button>` : ''));
    panel(ctx).querySelector('[data-list]').innerHTML = '';
    S.last = null; S.panelPid = null;
  }
  async function undo(ctx, pid, code) {
    try {
      const r = await ctx.undo(code);
      if (r && r.ok === false) { note(ctx, esc(r.text || T('Impossible d\'annuler.'))); return; }
      api('/vision/log', { outcome: 'annule', chosen_id: null, suggested_id: pid, context: ctxName() }).catch(() => {});
      note(ctx, T('Ajout annulé. Cet article sera désormais plus difficile à ajouter automatiquement s\'il est souvent annulé.'));
    } catch (e) { note(ctx, esc(e.message || String(e))); }
  }
  function logUse(outcome, chosen) {
    const l = S.last;
    api('/vision/log', { outcome, chosen_id: chosen, suggested_id: l ? l.best.pid : null, score: l ? l.best.score : null, margin: l ? l.margin : null, context: ctxName() }).catch(() => {});
  }

  function stopLoop() { clearTimeout(S.timer); S.timer = null; }
  /**
   * Analyse en continu :
   *  1. avec fond appris : rien n'est analysé tant qu'aucun article n'est posé dans le cadre ;
   *  2. un article n'est ajouté automatiquement que s'il est sûr (note et écart suffisants) sur
   *     2 images de suite ; sinon les propositions s'affichent et le caissier choisit ;
   *  3. après un ajout, on attend que l'article soit retiré (ou que la scène change) avant le suivant.
   */
  function loop() {
    stopLoop();
    const tick = async () => {
      const ctx = S.ctx;
      if (!S.auto || !ctx || !PMScanner.isOpen()) return;
      if (!S.busy && ctx.live() && IDX.n && ctx.video.videoWidth) {
        S.busy = true;
        try { await step(ctx); } catch (err) { /* image non prête */ } finally { S.busy = false; }
      }
      S.timer = setTimeout(tick, E.backend === 'webgl' ? 300 : 900);
    };
    S.timer = setTimeout(tick, 200);
  }
  async function step(ctx) {
    const a = await analyse(ctx.video, region(ctx), S.fond);
    if (S.fond) {
      if (!a.present) { S.votes = []; S.wait = null; S.panelPid = null; state(ctx, '⬚ ' + T('Posez un article dans le cadre.')); return; }
      if (a.iso.frac > 0.85) { S.votes = []; state(ctx, '⚠ ' + T('Le fond semble avoir changé (lumière, caméra déplacée) : touchez « Apprendre le fond » avec le cadre vide.')); return; }
      if (S.wait) { state(ctx, '↺ ' + T('Retirez l\'article pour passer au suivant.')); return; }
    } else if (S.wait) {
      // sans fond appris : on attend que l'image change nettement
      if (S.wait.cur && a.iso.cur && O && O.change(a.iso.cur, S.wait.cur) < 14) { state(ctx, '↺ ' + T('Changez d\'article pour passer au suivant.')); return; }
      if (S.wait.t && Date.now() - S.wait.t < 2500) return;
      S.wait = null;
    }
    const res = match(a).slice(0, 2);
    if (!res.length) return;
    const best = res[0], { sure: ok, margin } = sure(res);
    S.votes.push(ok ? best.pid : -best.pid); if (S.votes.length > 3) S.votes.shift();
    const stable = S.votes.length >= 2 && S.votes[S.votes.length - 1] === S.votes[S.votes.length - 2];
    state(ctx, ok ? '◉ ' + T('Article reconnu, vérification…') : '◌ ' + T('Analyse…'));
    if (!stable) return;
    if (ok && S.autoAdd && ctx.opts.visionAuto !== false) {
      const [it] = await products([best.pid]);
      if (!it) return;
      S.last = { shot: a, res, best, margin, sure: ok };
      state(ctx, '');
      await choose(ctx, it, 'auto');
      return;
    }
    await propose(ctx, a, true); // le caissier choisit (ou confirme)
  }

  async function run(ctx, btn) {
    S.ctx = ctx;
    ctx.el.classList.add('pm-vision-on');
    if (S.busy) return;
    S.busy = true; if (btn) btn.disabled = true;
    try {
      await prepare(ctx);
      if (!IDX.n) {
        note(ctx, T('Aucun article n\'a encore été appris.') + (cfg.teach ? ` <a href="${esc(BASE)}/vision">${T('Apprendre les articles')}</a>` : ' ' + T('Demandez au responsable de les apprendre (Gestion des articles → Reconnaissance par photo).')));
        ctx.msg(T('Reconnaissance par photo : aucun article appris.'), 'warn');
        return;
      }
      if (ctx.live()) {
        // Réglages mémorisés sur ce poste : continu et ajout automatique
        if (S.timer === null && store('pm-vision-continu') === '1') { S.auto = true; S.autoAdd = cfg.auto ? store('pm-vision-ajout') !== '0' : store('pm-vision-ajout') === '1'; }
        panel(ctx);
        const pa = panel(ctx).querySelector('[data-auto]'); if (pa) pa.checked = S.auto;
        const pb = panel(ctx).querySelector('[data-autoadd]'); if (pb) pb.checked = S.autoAdd;
        if (S.auto) { loop(); return; }
        ctx.msg(T('Analyse de l\'image…'));
        await propose(ctx, await captureLive(ctx));
      } else {
        ctx.msg(T('Caméra en direct indisponible : prenez une photo de l\'article.'), 'warn');
        pickPhoto(ctx);
      }
    } catch (e) {
      ctx.msg(e.message || String(e), 'bad');
    } finally { S.busy = false; if (btn) btn.disabled = false; }
  }

  PMScanner.addTool({
    id: 'vision', className: 'pm-tool-vision',
    label: '🔍 ' + T('Reconnaître l\'article'), title: T('Reconnaître l\'article par photo (sans code-barres)'),
    when: (o) => o.products === true && !(cfg && cfg.ok === false),
    run,
    onClose: (ctx) => { stopLoop(); S.auto = false; S.ctx = null; S.last = null; S.wait = null; S.votes = []; S.panelPid = null; if (ctx.el) ctx.el.classList.remove('pm-vision-on'); },
  });

  // Bouton « Reconnaître » à côté de la caméra de la caisse et du restaurant
  function quickButtons() {
    ['#cameraScan', '#restCamera'].forEach((sel) => {
      const cam = document.querySelector(sel);
      if (!cam || cam.nextElementSibling && cam.nextElementSibling.classList.contains('btn-vision')) return;
      const b = document.createElement('button');
      b.type = 'button'; b.className = cam.className + ' btn-vision'; b.title = T('Reconnaître un article par photo');
      b.innerHTML = '<i class="bi bi-eye"></i>';
      b.addEventListener('click', () => PMScanner.clickWith(cam, 'vision'));
      cam.insertAdjacentElement('afterend', b);
    });
  }
  config().then((c) => {
    if (!c || c.ok === false) return;
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', quickButtons); else quickButtons();
    // Caméras IP déclarées pour la caisse : proposées dans le choix des caméras du scanner
    cameraSources().then((items) => items.filter((cam) => cam.kind === 'ip' && cam.use_caisse).forEach((cam) => PMScanner.addSource({
      id: 'ip' + cam.id, label: '📡 ' + cam.name, open: () => ipStream(cam.id, cam.fps),
    })));
  });

  window.PMVision = { ipStream, cameraSources, loadEngine, embed, embedCanvas, analyse, isolate, Fond, MODEL_OBJ, aimRegion, centerRegion, thumbOf, loadImage, loadIndex, match, addLocal, products, config, api, b64, quantize, IDX, engine: E, P, TUNE, sure: (res, c) => { const old = cfg; if (c) cfg = Object.assign({}, cfg, c); try { return sure(res); } finally { cfg = old; } } };
})();
