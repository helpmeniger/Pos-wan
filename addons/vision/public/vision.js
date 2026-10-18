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
    const Rm = projection(); const f = new Float32Array(P);
    for (let i = 0; i < D; i++) { const x = avg[i]; if (!x) continue; const r = i * P; for (let j = 0; j < P; j++) f[j] += x * Rm[r + j]; }
    normalize(f);
    return { f, q: quantize(f) };
  }
  function thumbOf(source, region, size = 112) {
    const c = document.createElement('canvas'); c.width = size; c.height = size;
    c.getContext('2d').drawImage(source, region.x, region.y, region.s, region.s, 0, 0, size, size);
    return c.toDataURL('image/jpeg', 0.72);
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
  const IDX = { version: -1, n: 0, pids: [], ids: [], raw: new Float32Array(0), mat: new Float32Array(0), mean: null };
  function rebuild() {
    const n = IDX.n, raw = IDX.raw;
    IDX.mean = null;
    if (n >= CENTER_MIN_REFS && new Set(IDX.pids).size >= 3) {
      // Centrage : on retire ce que toutes les photos ont en commun (fond, éclairage de la caisse)
      const m = new Float32Array(P);
      for (let r = 0; r < n; r++) for (let j = 0; j < P; j++) m[j] += raw[r * P + j] / n;
      IDX.mean = m;
    }
    const mat = new Float32Array(n * P);
    for (let r = 0; r < n; r++) {
      const v = mat.subarray(r * P, r * P + P);
      for (let j = 0; j < P; j++) v[j] = raw[r * P + j] - (IDX.mean ? IDX.mean[j] : 0);
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
    IDX.version = d.version; IDX.pids = d.pids; IDX.ids = d.ids; IDX.raw = decodeRefs(d.vecs); IDX.n = d.pids.length;
    rebuild();
    return IDX;
  }
  function addLocal(pid, q) {
    const v = normalize(Float32Array.from(q));
    const raw = new Float32Array((IDX.n + 1) * P); raw.set(IDX.raw); raw.set(v, IDX.n * P);
    IDX.raw = raw; IDX.pids = IDX.pids.concat([pid]); IDX.ids = IDX.ids.concat([0]); IDX.n++;
    rebuild();
  }
  function prepareQuery(f) {
    const v = Float32Array.from(f);
    if (IDX.mean) for (let j = 0; j < P; j++) v[j] -= IDX.mean[j];
    return normalize(v);
  }
  /** Articles classés par ressemblance : [{ pid, score }] (score = meilleure photo, renforcée par la 2ᵉ). */
  function match(f, exclude) {
    const q = prepareQuery(f); const per = new Map();
    for (let r = 0; r < IDX.n; r++) {
      const pid = IDX.pids[r]; if (exclude && pid === exclude) continue;
      const s = dot(q, IDX.mat, r * P); const a = per.get(pid);
      if (!a) per.set(pid, [s, -2]); else if (s > a[0]) { a[1] = a[0]; a[0] = s; } else if (s > a[1]) a[1] = s;
    }
    return [...per.entries()].map(([pid, [b, s2]]) => ({ pid, score: s2 > -2 ? 0.75 * b + 0.25 * s2 : b })).sort((a, b) => b.score - a.score);
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
  const S = { ctx: null, busy: false, auto: false, timer: null, sceneVec: null, panelPid: null, last: null };
  const ctxName = () => (location.pathname.replace(BASE, '').split('/')[1] || 'accueil').replace(/[^a-z_]/g, '').slice(0, 30);

  async function prepare(ctx) {
    ctx.msg(T('Préparation de la reconnaissance…'));
    await Promise.all([loadEngine((t) => ctx.msg(t)), loadIndex()]);
  }
  async function captureLive(ctx, frames = 2) {
    const video = ctx.video; const aim = ctx.el.querySelector('.pm-scan-aim');
    for (let i = 0; i < 30 && (video.readyState < 2 || !video.videoWidth); i++) await new Promise((r) => setTimeout(r, 100));
    if (!video.videoWidth) throw new Error(T('La caméra ne donne pas d\'image : « Relancer la caméra ».'));
    const region = aimRegion(video, aim); const acc = new Float32Array(P); let thumb = null;
    for (let i = 0; i < frames; i++) {
      const e = await embed(video, region);
      for (let j = 0; j < P; j++) acc[j] += e.f[j];
      if (!thumb) thumb = thumbOf(video, region);
      if (i < frames - 1) await new Promise((r) => setTimeout(r, 120));
    }
    const f = normalize(acc);
    return { f, q: quantize(f), thumb };
  }
  function pickPhoto(ctx) {
    const inp = document.createElement('input'); inp.type = 'file'; inp.accept = 'image/*'; inp.setAttribute('capture', 'environment');
    inp.addEventListener('change', async () => {
      const file = inp.files && inp.files[0]; if (!file) return;
      try {
        const img = await loadImage(URL.createObjectURL(file));
        const region = centerRegion(img.naturalWidth, img.naturalHeight, 0.9);
        ctx.msg(T('Analyse de la photo…'));
        const e = await embed(img, region);
        URL.revokeObjectURL(img.src);
        await propose(ctx, { f: e.f, q: e.q, thumb: thumbOf(img, region) });
      } catch (err) { ctx.msg(err.message || String(err), 'bad'); }
    });
    inp.click();
  }
  function loadImage(src) { return new Promise((res, rej) => { const i = new Image(); i.onload = () => res(i); i.onerror = () => rej(new Error(T('Image illisible.'))); i.src = src; }); }

  function panel(ctx) {
    let p = ctx.extra.querySelector('.pm-vis-panel');
    if (!p) {
      p = document.createElement('div'); p.className = 'pm-vis-panel';
      const canAuto = ctx.live() && E.backend === 'webgl';
      p.innerHTML = `<div class="pm-vis-head"><i class="bi bi-eye"></i> <span data-h>${T('Reconnaissance par photo')}</span>
        ${canAuto ? `<label title="${esc(T('Analyse en continu ce qui est devant la caméra'))}"><input type="checkbox" data-auto ${S.auto ? 'checked' : ''}> ${T('En continu')}</label>` : ''}</div>
        <div class="pm-vis-list" data-list></div>
        <div class="pm-vis-other"><input type="search" data-q placeholder="${esc(T('Autre article : nom, référence…'))}" autocomplete="off"><button type="button" class="pm-btn" data-none>${T('Aucun')}</button></div>
        <div class="pm-vis-found" data-found></div><div class="pm-vis-note" data-note></div>`;
      ctx.extra.appendChild(p);
      const a = p.querySelector('[data-auto]');
      if (a) a.addEventListener('change', () => { S.auto = a.checked; if (S.auto) loop(); else stopLoop(); });
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
        if (e.target.closest('[data-none]')) { if (S.last && ctx.opts.visionLog !== false) logUse('aucun', null); p.querySelector('[data-list]').innerHTML = ''; note(ctx, T('D\'accord. Recadrez l\'article puis touchez « Reconnaître », ou cherchez-le par son nom.')); S.last = null; }
      });
    }
    return p;
  }
  function note(ctx, html) { const n = panel(ctx).querySelector('[data-note]'); n.innerHTML = html; }

  async function propose(ctx, shot, fromLoop) {
    const res = match(shot.f).slice(0, 4);
    const p = panel(ctx);
    if (!res.length) { note(ctx, T('Aucun article appris pour le moment.')); return; }
    const best = res[0], margin = best.score - (res[1] ? res[1].score : -1);
    const sure = best.score >= cfg.autoScore && margin >= cfg.margin;
    if (fromLoop && (!sure || best.pid === S.panelPid)) return; // en continu : on ne dérange que pour un article sûr et nouveau
    const info = await products(res.map((r) => r.pid));
    S.last = { shot, res, best, margin, sure };
    S.panelPid = best.pid;
    p.querySelector('[data-h]').textContent = sure ? T('Article reconnu :') : T('Est-ce l\'un de ces articles ?');
    p.querySelector('[data-list]').innerHTML = res.map((r, i) => {
      const it = info.find((x) => x.id === r.pid); if (!it) return '';
      // Barre relative au meilleur candidat (la note brute n'a pas de sens pour le caissier)
      const pct = Math.max(4, Math.min(100, Math.round(100 * Math.max(0, r.score) / Math.max(0.0001, res[0].score))));
      return `<button type="button" class="pm-vis-card ${i === 0 && sure ? 'best' : ''}" data-pick="${it.id}" title="${esc(T('Touchez pour ajouter'))}">
        ${it.image ? `<img src="${esc(it.image)}" alt="">` : '<span class="pm-vis-noimg">📦</span>'}
        <b>${esc(it.name)}</b><small>${fmt(it.price)}${it.track ? ' · ' + T('stock') + ' ' + (Math.round(it.stock * 100) / 100) : ''}</small>
        <span class="pm-vis-score" title="${esc(T('Ressemblance comparée au 1er article'))} : ${pct} %"><span style="width:${pct}%"></span></span></button>`;
    }).join('');
    p.querySelector('[data-found]').innerHTML = ''; p.querySelector('[data-q]').value = '';
    note(ctx, sure ? T('Touchez l\'article pour l\'ajouter.') : T('Pas sûr : touchez le bon article, ou cherchez-le ci-dessous (il sera appris).'));
    ctx.msg(sure ? T('Article reconnu.') : T('Plusieurs articles possibles.'), sure ? 'good' : 'warn');
    if (sure && cfg.auto && ctx.opts.visionAuto !== false) {
      const it = info.find((x) => x.id === best.pid);
      if (it) await choose(ctx, it, 'auto');
    }
  }

  async function choose(ctx, it, outcome) {
    const last = S.last;
    const out = outcome || (last && last.best.pid === it.id ? 'confirme' : 'corrige');
    const r = await ctx.deliver(it.code);
    if (r && r.ok === false) { note(ctx, esc(r.text || T('Article refusé.'))); return; }
    S.sceneVec = last ? last.shot.f : null; // en continu : attendre qu'on retire l'article
    if (last && ctx.opts.visionLog !== false) logUse(out, it.id);
    let learned = false;
    if (last && out !== 'auto' && cfg.learn && ctx.opts.visionLearn !== false) {
      const d = await api('/vision/refs', { product_id: it.id, vec: b64(last.shot.q), thumb: last.shot.thumb, source: 'vente' });
      if (d.ok && d.id) { addLocal(it.id, last.shot.q); IDX.version = d.version; learned = true; }
    }
    note(ctx, `✔ <b>${esc(it.name)}</b> ${out === 'auto' ? T('ajouté automatiquement') : T('ajouté')}${learned ? ' · ' + T('photo apprise pour la prochaine fois') : ''}`);
    panel(ctx).querySelector('[data-list]').innerHTML = '';
    S.last = null; S.panelPid = null;
  }
  function logUse(outcome, chosen) {
    const l = S.last;
    api('/vision/log', { outcome, chosen_id: chosen, suggested_id: l ? l.best.pid : null, score: l ? l.best.score : null, margin: l ? l.margin : null, context: ctxName() }).catch(() => {});
  }

  function stopLoop() { clearTimeout(S.timer); S.timer = null; }
  function loop() {
    stopLoop();
    const tick = async () => {
      const ctx = S.ctx;
      if (!S.auto || !ctx || !PMScanner.isOpen()) return;
      if (!S.busy && ctx.live() && IDX.n) {
        S.busy = true;
        try {
          const e = await embed(ctx.video, aimRegion(ctx.video, ctx.el.querySelector('.pm-scan-aim')));
          if (S.sceneVec && dot(e.f, S.sceneVec) > 0.9) {
            // même scène que l'article qui vient d'être ajouté : on attend qu'il soit retiré
          } else {
            S.sceneVec = null;
            await propose(ctx, { f: e.f, q: e.q, thumb: thumbOf(ctx.video, aimRegion(ctx.video, ctx.el.querySelector('.pm-scan-aim'))) }, true);
          }
        } catch (err) { /* image non prête */ } finally { S.busy = false; }
      }
      S.timer = setTimeout(tick, 650);
    };
    S.timer = setTimeout(tick, 200);
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
    onClose: (ctx) => { stopLoop(); S.auto = false; S.ctx = null; S.last = null; S.sceneVec = null; S.panelPid = null; if (ctx.el) ctx.el.classList.remove('pm-vision-on'); },
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

  window.PMVision = { ipStream, cameraSources, loadEngine, embed, aimRegion, centerRegion, thumbOf, loadImage, loadIndex, match, addLocal, products, config, api, b64, IDX, engine: E };
})();
