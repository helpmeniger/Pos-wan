/*
 * POS Marwa — surveillance des étagères : un article est-il pris (ou remis) à son emplacement ?
 * Calcul pur (aucune dépendance au navigateur) : testé tel quel par la suite de tests.
 *
 *   const s = PMShelf.create({ width: 160, height: 90, slots: [{ id, x, y, w, h }], sensitivity: 1..10, settleFrames });
 *   const r = s.process(image);     // Uint8Array(width × height) en niveaux de gris, ou RGBA (width × height × 4)
 *   r.state  : 'apprentissage' | 'mouvement' | 'masque' | 'calme'
 *   r.slots  : [{ id, change, touched, count, units, present }]   count = articles vus à leur place sur « units »
 *   r.events : [{ type: 'prise' | 'remis' | 'deplace' | 'depose', slot, qty, to?, change, box: { x, y, w, h } }]
 *
 * Comptage par places (emplacement avec units > 1, ex. 6 bouteilles de face) : l'emplacement est découpé en
 * « units » places le long de son grand côté ; une place est « occupée » quand elle ressemble à la même place sur
 * l'image « rayon plein ». À chaque geste :
 *   - place occupée devenue vide → article pris ; place vide redevenue comme « plein » → article remis ;
 *   - place vide qui change sans redevenir comme « plein » → objet déposé (pas à sa place) ;
 *   - un article parti d'un endroit et un objet posé ailleurs sur le rayon dans le même geste → « déplacé »
 *     (il ne quitte pas le rayon : pas de suivi jusqu'à la caisse) ;
 *   - touché sans changement → aucun événement.
 *   s.reference() / s.restore(ref) : état « rayon plein » (pour le garder entre deux sessions)
 *
 * Principe (détection de changement après mouvement, pas de suivi d'objet) :
 * 1. Une image « stable » de la scène est mémorisée quand plus rien ne bouge.
 * 2. Une main, un bras, une personne passent : on attend. On attend aussi tant que :
 *      - la scène hors emplacements reste différente de l'image stable (quelqu'un se tient devant le rayon) ;
 *      - un changement déborde autour d'un emplacement (une main ou un bras encore posé sur l'article).
 *    Si cela dure (caméra déplacée, décor changé), les références sont réapprises au bout de « relearnFrames » images.
 * 3. Quand tout est de nouveau immobile, chaque emplacement « touché » pendant le mouvement (le mouvement a atteint
 *    l'emplacement ou sa bordure) est comparé à son état stable. S'il a nettement changé, c'est un événement :
 *      - « prise » si l'emplacement s'éloigne de l'état « rayon plein » ;
 *      - « remis » s'il s'en rapproche (article remis, ou rayon réapprovisionné).
 * 4. Les écarts sont mesurés en couleur quand l'image est en couleur (une main de même luminosité qu'un article
 *    se distingue par sa teinte) ; les variations de lumière de toute l'image sont compensées.
 */
(function (root) {
  'use strict';
  const clamp = (v, a, b) => Math.max(a, Math.min(b, v));

  function create(o) {
    const W = o.width | 0, H = o.height | 0, N = W * H;
    const sens = clamp(Number(o.sensitivity) || 5, 1, 10);
    const thrPix = 44 - sens * 3;               // écart significatif pour un pixel (niveau 0 à 255, par couleur)
    const thrMove = 30 - sens * 1.5;            // écart image à image = mouvement
    const takeMin = 0.30 - sens * 0.015;        // part de l'emplacement qui doit changer (0,15 à 0,285)
    const settle = Math.max(3, Math.round(o.settleFrames || 6));   // images immobiles avant de juger
    const learn = Math.max(3, Math.round(o.learnFrames || 8));
    const relearn = Math.max(settle + 5, Math.round(o.relearnFrames || 150));   // ≈ 20 s à 8 images/s
    const confirm = Math.max(1, Math.round(o.confirmFrames || settle * 3));     // calme exigé (≈ 5 s après le dernier mouvement) avant de confirmer une prise
    let C = 0;                                  // 1 = niveaux de gris, 3 = couleur (fixé par la première image)

    const slots = (o.slots || []).map((s) => {
      const x0 = clamp(Math.floor(s.x * W), 0, W - 1), y0 = clamp(Math.floor(s.y * H), 0, H - 1);
      const x1 = clamp(Math.ceil((s.x + s.w) * W), x0 + 1, W), y1 = clamp(Math.ceil((s.y + s.h) * H), y0 + 1, H);
      const m = Math.max(2, Math.round(0.15 * Math.min(x1 - x0, y1 - y0)));
      // Places : découpage le long du grand côté (une place par article de face)
      const horiz = (x1 - x0) >= (y1 - y0);
      const len = horiz ? x1 - x0 : y1 - y0;
      const units = clamp(Math.round(Number(s.units) || 1), 1, Math.max(1, Math.min(30, Math.floor(len / 3))));
      const cells = [];
      for (let u = 0; u < units; u++) {
        const a = Math.round(u * len / units), b = Math.round((u + 1) * len / units);
        const c = horiz ? { x0: x0 + a, x1: x0 + b, y0, y1 } : { x0, x1, y0: y0 + a, y1: y0 + b };
        c.n = (c.x1 - c.x0) * (c.y1 - c.y0); cells.push(c);
      }
      return { id: s.id, x0, y0, x1, y1, m, n: (x1 - x0) * (y1 - y0), touched: false, change: 0, ring: null, near: null, units, cells, present: cells.map(() => true) };
    });
    // Emplacements + marge (un article déborde souvent un peu du rectangle tracé) : exclus de la mesure « hors rayon »
    const inSlot = new Uint8Array(N);
    for (const s of slots) {
      for (let y = Math.max(0, s.y0 - s.m); y < Math.min(H, s.y1 + s.m); y++) for (let x = Math.max(0, s.x0 - s.m); x < Math.min(W, s.x1 + s.m); x++) inSlot[y * W + x] = 1;
    }
    // Pour chaque emplacement : sa zone élargie (mouvement qui « touche » l'emplacement) et un anneau au-delà de la marge
    // (un changement qui y persiste = bras ou main encore là)
    for (const s of slots) {
      const near = [], ring = [];
      for (let y = Math.max(0, s.y0 - 2 * s.m); y < Math.min(H, s.y1 + 2 * s.m); y++) for (let x = Math.max(0, s.x0 - 2 * s.m); x < Math.min(W, s.x1 + 2 * s.m); x++) {
        const i = y * W + x;
        const inMargin = x >= s.x0 - s.m && x < s.x1 + s.m && y >= s.y0 - s.m && y < s.y1 + s.m;
        if (inMargin) near.push(i); else if (!inSlot[i]) ring.push(i);
      }
      s.near = Int32Array.from(near); s.ring = Int32Array.from(ring);
    }
    let outsideN = 0; for (let i = 0; i < N; i++) outsideN += inSlot[i] ? 0 : 1;

    let prev = null, stable = null, full = null, calm = 0, moved = false, masked = 0, state = 'apprentissage';
    // État d'avant le dernier jugement : si la scène y revient, ce jugement avait été faussé par un obstacle immobile
    let prevStable = null, prevPresent = null, judgeId = 0;

    // Pavés de l'image (hors emplacements) pour mesurer la lumière : un changement de lumière est à peu près
    // le même partout, une personne ou une ombre portée ne change qu'une partie des pavés.
    const TX = 8, TY = 6, tiles = [];
    for (let ty = 0; ty < TY; ty++) for (let tx = 0; tx < TX; tx++) {
      const idx = [];
      for (let y = Math.floor(ty * H / TY); y < Math.floor((ty + 1) * H / TY); y++) for (let x = Math.floor(tx * W / TX); x < Math.floor((tx + 1) * W / TX); x++) if (!inSlot[y * W + x]) idx.push(y * W + x);
      if (idx.length >= 0.3 * (W / TX) * (H / TY)) tiles.push(Int32Array.from(idx));
    }
    const lum = (a, i) => { let v = 0; for (let c = 0; c < C; c++) v += a[i * C + c]; return v; };
    /** Lumière entre deux images : gain (médiane des pavés) et part des pavés qui ne suivent pas ce gain. */
    function light(a, b) {
      if (tiles.length < 4) {
        let sa = 0, sb = 0; for (let i = 0; i < N; i++) { sa += lum(a, i); sb += lum(b, i); }
        return { k: sb > 0 ? clamp(sa / sb, 0.5, 2) : 1, spread: 0 };
      }
      const r = tiles.map((t) => { let sa = 0, sb = 0; for (const i of t) { sa += lum(a, i); sb += lum(b, i); } return sb > 0 ? sa / sb : 1; });
      const sorted = [...r].sort((x, y) => x - y);
      const k = clamp(sorted[sorted.length >> 1], 0.5, 2);
      return { k, spread: r.filter((v) => Math.abs(v / k - 1) > 0.12).length / r.length };
    }
    const gain = (a, b) => light(a, b).k;
    /** Écart d'un pixel entre deux images : le plus grand écart des couleurs (référence corrigée de la lumière). */
    const dpx = (a, b, i, k) => { let d = 0; for (let c = 0, j = i * C; c < C; c++, j++) { const v = Math.abs(a[j] - b[j] * k); if (v > d) d = v; } return d; };
    /** Part des pixels de l'emplacement qui diffèrent entre l'image courante et une référence. */
    function diff(s, cur, ref, k) {
      let n = 0;
      for (let y = s.y0; y < s.y1; y++) for (let x = s.x0, i = y * W + x; x < s.x1; x++, i++) if (dpx(cur, ref, i, k) > thrPix) n++;
      return n / s.n;
    }
    /** Part des pixels d'une place qui diffèrent entre deux images. */
    function cellDiff(c, cur, ref, k) {
      let n = 0;
      for (let y = c.y0; y < c.y1; y++) for (let x = c.x0, i = y * W + x; x < c.x1; x++, i++) if (dpx(cur, ref, i, k) > thrPix) n++;
      return n / c.n;
    }
    const presentMax = 0.35;          // une place qui diffère de moins de 35 % de l'image « plein » est occupée
    const presence = (s, img, k) => s.cells.map((c) => cellDiff(c, img, full, k) < presentMax);
    function partDiff(idx, cur, ref, k) {
      if (!idx.length) return 0;
      let n = 0; for (const i of idx) if (dpx(cur, ref, i, k) > thrPix) n++;
      return n / idx.length;
    }
    /** Part des pixels hors emplacements qui diffèrent de l'image stable (quelqu'un devant le rayon ?). */
    function outsideDiff(cur, ref, k) {
      if (outsideN < N * 0.1) return 0;
      let n = 0;
      for (let i = 0; i < N; i++) if (!inSlot[i] && dpx(cur, ref, i, k) > thrPix) n++;
      return n / outsideN;
    }
    function changedBox(s, cur, ref, k) {
      let mx = W, my = H, Mx = -1, My = -1;
      for (let y = s.y0; y < s.y1; y++) for (let x = s.x0; x < s.x1; x++) {
        if (dpx(cur, ref, y * W + x, k) > thrPix) { if (x < mx) mx = x; if (y < my) my = y; if (x > Mx) Mx = x; if (y > My) My = y; }
      }
      return Mx < 0 ? { x: s.x0, y: s.y0, w: s.x1 - s.x0, h: s.y1 - s.y0 } : { x: mx, y: my, w: Mx - mx + 1, h: My - my + 1 };
    }
    /** Image reçue → tableau de travail (gris : 1 valeur par pixel ; RGBA : 3 valeurs par pixel). */
    function toWork(img) {
      if (!C) C = img.length >= 4 * N ? 3 : 1;
      if (C === 1) {
        if (img.length >= 4 * N) { const g = new Float32Array(N); for (let i = 0, j = 0; i < N; i++, j += 4) g[i] = (img[j] * 77 + img[j + 1] * 150 + img[j + 2] * 29) / 256; return g; }
        return Float32Array.from(img.length > N ? img.subarray(0, N) : img);
      }
      const out = new Float32Array(N * 3);
      if (img.length >= 4 * N) { for (let i = 0, j = 0, k = 0; i < N; i++, j += 4, k += 3) { out[k] = img[j]; out[k + 1] = img[j + 1]; out[k + 2] = img[j + 2]; } } else { for (let i = 0; i < N; i++) out[i * 3] = out[i * 3 + 1] = out[i * 3 + 2] = img[i]; }
      return out;
    }

    function judge(img) {
      const cur = toWork(img);
      const events = [];
      if (!prev) { prev = cur; return { state, slots: view(), events }; }
      // Mouvement image à image
      let movingPix = 0;
      const moveMask = new Uint8Array(N);
      for (let i = 0; i < N; i++) if (dpx(cur, prev, i, 1) > thrMove) { moveMask[i] = 1; movingPix++; }
      prev = cur;
      const moving = movingPix > N * 0.004;
      if (moving) {
        calm = 0; moved = true;
        // Emplacement « touché » : le mouvement atteint l'emplacement ou sa bordure (une main arrive par le bord)
        for (const s of slots) {
          if (s.touched) continue;
          let m = 0; const need = Math.max(2, s.near.length * 0.015);
          for (const i of s.near) if (moveMask[i] && ++m > need) { s.touched = true; break; }
        }
      } else calm++;

      // Apprentissage : première image stable = état de référence (rayon plein si rien n'a été fourni)
      if (!stable) {
        state = 'apprentissage';
        if (calm >= learn) {
          stable = cur; if (!full) full = cur; moved = false; state = 'calme';
          const kf = gain(cur, full);
          slots.forEach((s) => { s.touched = false; s.present = presence(s, cur, kf); });
        }
        return { state, slots: view(), events };
      }
      const L = light(cur, stable), k = L.k;
      for (const s of slots) s.change = diff(s, cur, stable, k);
      if (moving || calm < settle) { state = 'mouvement'; return { state, slots: view(), events }; }
      // Immobile, mais quelque chose cache encore le rayon : une personne devant (hors emplacements), ou une main /
      // un bras posé sur un emplacement touché (le changement déborde dans l'anneau autour de l'emplacement)
      // (un bras fin ne couvre qu'une petite part de l'anneau : on compte les points changés, pas une proportion)
      const arm = moved && slots.some((s) => s.touched && partDiff(s.ring, cur, stable, k) * s.ring.length > Math.max(10, 0.012 * s.ring.length));
      if (arm || L.spread > 0.15 || outsideDiff(cur, stable, k) > 0.06) {
        // La scène redevient comme avant le dernier jugement (une main ou un bras immobile avait été pris pour l'état
        // du rayon) : ce jugement est annulé et le geste sera jugé de nouveau, depuis le bon état
        if (prevStable) {
          const P = light(cur, prevStable);
          if (P.spread <= 0.15 && outsideDiff(cur, prevStable, P.k) <= 0.06) {
            stable = prevStable; prevStable = null; masked = 0; moved = true;
            slots.forEach((s, i) => { s.present = prevPresent[i]; s.touched = true; });
            return { state: 'mouvement', slots: view(), events, rollback: judgeId };
          }
        }
        state = 'masque';
        if (++masked < relearn) return { state, slots: view(), events };
        // La scène a durablement changé (caméra déplacée, décor modifié) : tout est réappris, sans événement
        const F = light(cur, full);
        if (F.spread > 0.15 || outsideDiff(cur, full, F.k) > 0.06) full = cur;
        stable = cur; moved = false; masked = 0; prevStable = null;
        const kr = gain(cur, full);
        slots.forEach((s) => { s.touched = false; s.change = 0; s.present = presence(s, cur, kr); });
        return { state: 'calme', slots: view(), events, reset: true };
      }
      masked = 0;
      state = 'calme';
      if (moved) {
        const kf = gain(cur, full);
        const kfs = gain(stable, full);
        const losses = [], gains = [];
        prevStable = stable; prevPresent = slots.map((s) => s.present.slice()); judgeId++;
        for (const s of slots) {
          if (!s.touched) continue;
          const box = () => changedBox(s, cur, stable, k), ch = Math.round(s.change * 1000) / 1000;
          if (s.units > 1) {
            // Places : avant (image stable) / après, comparées à l'image « rayon plein »
            const before = s.present, after = presence(s, cur, kf);
            let lost = 0, back = 0, other = 0;
            s.cells.forEach((c, i) => {
              if (cellDiff(c, cur, stable, k) < takeMin) { after[i] = before[i]; return; }   // place inchangée
              if (before[i] && !after[i]) lost++;
              else if (!before[i] && after[i]) back++;
              else if (!before[i] && !after[i]) other++;
            });
            s.present = after;
            const same = Math.min(lost, back); lost -= same; back -= same;   // articles réarrangés dans l'emplacement
            if (lost) losses.push({ s, n: lost, box, ch });
            if (back) gains.push({ s, n: back, kind: 'remis', box, ch });
            if (other) gains.push({ s, n: other, kind: 'depose', box, ch });
          } else {
            if (s.change < takeMin) continue;
            const before = diff(s, stable, full, kfs), after = diff(s, cur, full, kf);
            s.present = [after < presentMax];
            if (after >= before) losses.push({ s, n: 1, box, ch }); else gains.push({ s, n: 1, kind: 'remis', box, ch });
          }
        }
        // Parti d'un emplacement et posé sur un autre dans le même geste : déplacé (reste dans le rayon)
        for (const l of losses) for (const g of gains) {
          if (g.s === l.s || !l.n || !g.n) continue;
          const q = Math.min(l.n, g.n); l.n -= q; g.n -= q;
          events.push({ type: 'deplace', slot: l.s.id, to: g.s.id, qty: q, change: l.ch, box: l.box() });
        }
        for (const l of losses) if (l.n) events.push({ type: 'prise', slot: l.s.id, qty: l.n, change: l.ch, box: l.box(), judge: judgeId });
        for (const g of gains) if (g.n) events.push({ type: g.kind, slot: g.s.id, qty: g.n, change: g.ch, box: g.box() });
        // Nouvel état stable (l'emplacement vidé devient la référence pour la prochaine prise)
        stable = cur; moved = false;
        slots.forEach((s) => { s.touched = false; s.change = 0; });
      } else {
        // Rien ne se passe : l'état stable suit lentement la lumière ambiante
        for (let i = 0; i < stable.length; i++) stable[i] += (cur[i] - stable[i]) * 0.02;
      }
      return { state, slots: view(), events };
    }
    /**
     * Confirmation des prises : une prise n'est annoncée qu'après « confirm » images calmes qui suivent le dernier mouvement. Si, entre-temps,
     * l'emplacement redevient comme avant (un bras resté immobile en travers d'un rayon qui repart), la prise et la
     * remise s'annulent ; si un article apparaît sur un autre emplacement, c'est un déplacement.
     */
    let pending = [];
    function process(img) {
      const r = judge(img);
      // Jugement annulé : ses prises en attente aussi ; scène réapprise : plus rien n'est fiable, tout est abandonné
      if (r.rollback) pending = pending.filter((p) => p.ev.judge !== r.rollback);
      if (r.reset) pending = [];
      const out = [], fresh = [];
      for (const e of r.events) {
        if (e.type === 'remis' || e.type === 'depose') {
          // D'abord la prise du même emplacement (bras qui repart, article reposé), ensuite un autre emplacement (déplacé)
          for (const p of [...pending].sort((x, y) => (x.ev.slot === e.slot ? 0 : 1) - (y.ev.slot === e.slot ? 0 : 1))) {
            if (!e.qty) break;
            if (!p.ev.qty) continue;
            const q = Math.min(p.ev.qty, e.qty);
            if (p.ev.slot !== e.slot) out.push({ type: 'deplace', slot: p.ev.slot, to: e.slot, qty: q, change: p.ev.change, box: p.ev.box });
            p.ev.qty -= q; e.qty -= q;
          }
          pending = pending.filter((p) => p.ev.qty > 0);
          if (e.qty) out.push(e);
        } else if (e.type === 'prise') fresh.push({ ev: e, left: confirm, age: 0 });
        else out.push(e);
      }
      for (const p of pending) {
        p.age++;
        if (r.state === 'mouvement') p.left = confirm;   // quelque chose bouge encore : le calme se compte à nouveau
        else if (r.state === 'calme') p.left--;
        if (p.left <= 0 || p.age > relearn) out.push(p.ev);
      }
      pending = pending.filter((p) => p.left > 0 && p.age <= relearn).concat(fresh);
      r.events = out; r.pending = pending.length;
      return r;
    }
    const view = () => slots.map((s) => ({ id: s.id, change: Math.round(s.change * 1000) / 1000, touched: s.touched, units: s.units,
      count: s.present.filter(Boolean).length, present: s.present.slice() }));

    return {
      process,
      /** Fixe l'état « rayon plein » sur l'image stable actuelle. */
      setFull() { if (stable) { full = Float32Array.from(stable); slots.forEach((s) => { s.present = s.cells.map(() => true); }); return true; } return false; },
      /** Places de chaque emplacement (coordonnées dans l'image analysée), pour l'affichage. */
      cells: () => slots.map((s) => ({ id: s.id, cells: s.cells.map((c) => ({ x: c.x0 / W, y: c.y0 / H, w: (c.x1 - c.x0) / W, h: (c.y1 - c.y0) / H })) })),
      reference() { return full ? Uint8Array.from(full, (v) => clamp(Math.round(v), 0, 255)) : null; },
      /** Référence d'une session précédente (même taille d'image et même nature : gris ou couleur). */
      restore(ref) {
        if (!ref) return;
        if (!C && (ref.length === N || ref.length === 3 * N)) C = ref.length === N ? 1 : 3;
        if (ref.length === N * C) full = Float32Array.from(ref);
      },
      params: { thrPix, thrMove, takeMin, settle },
    };
  }

  const api = { create };
  if (typeof module === 'object' && module.exports) module.exports = api;
  else root.PMShelf = api;
})(typeof window !== 'undefined' ? window : this);
