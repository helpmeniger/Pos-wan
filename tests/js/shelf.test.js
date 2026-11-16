/* Tests du moteur « étagère » (article pris / remis à son emplacement) — node tests/js/shelf.test.js */
'use strict';
const S = require('../../addons/vision/public/vision-shelf.js');
const W = 160, H = 120;
let pass = 0, fail = 0;
const ok = (c, m) => { if (c) pass++; else { fail++; console.log('  ✘ ' + m); } };
function rng(seed) { let s = seed >>> 0; return () => ((s = (s * 1664525 + 1013904223) >>> 0) / 4294967296); }

// Étagère : fond texturé, trois emplacements (A, B, C) côte à côte sur une planche
const SLOTS = [
  { id: 'A', x: 0.05, y: 0.30, w: 0.25, h: 0.30 },
  { id: 'B', x: 0.375, y: 0.30, w: 0.25, h: 0.30 },
  { id: 'C', x: 0.70, y: 0.30, w: 0.25, h: 0.30 },
];
/** Article dans un emplacement : boîte claire à motif (pas un aplat). */
function item(slot, frac = 1) {
  const s = SLOTS.find((x) => x.id === slot);
  return { x: (s.x + 0.02) * W, y: (s.y + 0.03) * H, w: (s.w - 0.04) * W * frac, h: (s.h - 0.06) * H, v: 215, pattern: true };
}
/** Image : fond, articles présents, objets mobiles (main, personne), lumière. */
function frame(objs, r, light = 0) {
  const g = new Uint8Array(W * H);
  for (let y = 0; y < H; y++) for (let x = 0; x < W; x++) g[y * W + x] = 70 + ((x >> 3) + (y >> 3)) % 2 * 18 + (r() - 0.5) * 8;
  for (const o of objs) for (let y = Math.max(0, o.y | 0); y < Math.min(H, (o.y + o.h) | 0); y++) for (let x = Math.max(0, o.x | 0); x < Math.min(W, (o.x + o.w) | 0); x++) {
    g[y * W + x] = o.pattern ? (((x >> 2) + (y >> 2)) % 2 ? o.v : o.v - 70) : o.v;
  }
  if (light) for (let i = 0; i < g.length; i++) g[i] = Math.max(0, Math.min(255, Math.round(g[i] * (1 + light))));
  return g;
}
function run(seq, opts = {}) {
  const t = S.create(Object.assign({ width: W, height: H, slots: SLOTS }, opts)); const r = rng(11); const ev = [];
  let last;
  for (const f of seq) { last = t.process(frame(f.objs, r, f.light || 0)); ev.push(...last.events); }
  return { ev, last, t };
}
const shelf = (ids) => ids.map((id) => item(id));
const still = (ids, n, light = 0) => Array.from({ length: n }, () => ({ objs: shelf(ids), light }));
/** Une main (objet sombre) arrive par le bas jusqu'à l'emplacement, reste, puis repart. */
function hand(ids, slot, during = ids, after = during) {
  const s = SLOTS.find((x) => x.id === slot); const cx = (s.x + s.w / 2) * W - 8;
  const seq = [];
  for (let i = 0; i < 6; i++) seq.push({ objs: [...shelf(ids), { x: cx, y: H - 10 - i * 12, w: 16, h: 40, v: 25 }] });
  for (let i = 0; i < 3; i++) seq.push({ objs: [...shelf(during), { x: cx, y: (s.y + 0.05) * H, w: 16, h: 70, v: 25 }] });
  for (let i = 5; i >= 0; i--) seq.push({ objs: [...shelf(after), { x: cx, y: H - 10 - i * 12, w: 16, h: 40, v: 25 }] });
  return seq;
}

// 1. Apprentissage puis rayon immobile : aucun événement
let x = run(still(['A', 'B', 'C'], 30));
ok(x.ev.length === 0 && x.last.state === 'calme', 'rayon immobile : 0 événement, état calme (' + x.last.state + ')');
// 2. Une main prend l'article B : 1 « prise » sur B, et seulement après le départ de la main
x = run([...still(['A', 'B', 'C'], 12), ...hand(['A', 'B', 'C'], 'B', ['A', 'C'], ['A', 'C']), ...still(['A', 'C'], 12)]);
ok(x.ev.length === 1 && x.ev[0].type === 'prise' && x.ev[0].slot === 'B', 'article B pris : 1 prise sur B (obtenu ' + JSON.stringify(x.ev.map((e) => e.type + e.slot)) + ')');
ok(x.ev[0] && x.ev[0].box && x.ev[0].box.w > 5, 'zone du changement fournie (vignette et reconnaissance)');
// 3. Main qui touche B sans rien prendre : 0 événement
x = run([...still(['A', 'B', 'C'], 12), ...hand(['A', 'B', 'C'], 'B'), ...still(['A', 'B', 'C'], 12)]);
ok(x.ev.length === 0, 'main qui touche sans prendre : 0 événement (obtenu ' + x.ev.length + ')');
// 4. Pris puis remis : prise puis « remis »
x = run([...still(['A', 'B', 'C'], 12), ...hand(['A', 'B', 'C'], 'B', ['A', 'C'], ['A', 'C']), ...still(['A', 'C'], 10), ...hand(['A', 'C'], 'B', ['A', 'B', 'C'], ['A', 'B', 'C']), ...still(['A', 'B', 'C'], 10)]);
ok(x.ev.length === 2 && x.ev[0].type === 'prise' && x.ev[1].type === 'remis' && x.ev[1].slot === 'B', 'pris puis remis : prise + remis (obtenu ' + JSON.stringify(x.ev.map((e) => e.type + e.slot)) + ')');
// 5. Deux prises successives sur deux emplacements différents
x = run([...still(['A', 'B', 'C'], 12), ...hand(['A', 'B', 'C'], 'A', ['B', 'C'], ['B', 'C']), ...still(['B', 'C'], 10), ...hand(['B', 'C'], 'C', ['B'], ['B']), ...still(['B'], 10)]);
ok(x.ev.length === 2 && x.ev[0].slot === 'A' && x.ev[1].slot === 'C' && x.ev.every((e) => e.type === 'prise'), 'deux prises successives (A puis C)');
// 6. Le voisin non touché ne déclenche rien, même si la main passe devant
x = run([...still(['A', 'B', 'C'], 12), ...hand(['A', 'B', 'C'], 'A', ['B', 'C'], ['B', 'C']), ...still(['B', 'C'], 10)]);
ok(x.ev.length === 1 && x.ev[0].slot === 'A', 'seul l\'emplacement concerné est signalé');
// 7. Personne immobile devant le rayon : on attend (« masque »), l'événement vient quand elle part
const person = { x: 20, y: 5, w: 120, h: 110, v: 40 };
const seq7 = [...still(['A', 'B', 'C'], 12), ...hand(['A', 'B', 'C'], 'B', ['A', 'C'], ['A', 'C'])];
for (let i = 0; i < 4; i++) seq7.push({ objs: [...shelf(['A', 'C']), { ...person, x: person.x - 20 + i * 5 }] });
for (let i = 0; i < 15; i++) seq7.push({ objs: [...shelf(['A', 'C']), person] });
const t7 = S.create({ width: W, height: H, slots: SLOTS }); const r7 = rng(3); const ev7 = []; let masked = false;
for (const f of seq7) { const o = t7.process(frame(f.objs, r7)); ev7.push(...o.events); if (o.state === 'masque') masked = true; }
ok(ev7.length === 0 && masked, 'personne immobile devant le rayon : attente, aucun événement (état masque)');
for (let i = 0; i < 4; i++) ev7.push(...t7.process(frame([...shelf(['A', 'C']), { ...person, x: person.x + i * 30 }], r7)).events);
for (let i = 0; i < 12; i++) ev7.push(...t7.process(frame(shelf(['A', 'C']), r7)).events);
ok(ev7.length === 1 && ev7[0].slot === 'B' && ev7[0].type === 'prise', 'quand la personne part : la prise sur B est signalée');
// 8. Variation lente puis brusque de la lumière : aucun faux événement
const lightSeq = [...still(['A', 'B', 'C'], 12)];
for (let i = 0; i < 30; i++) lightSeq.push({ objs: shelf(['A', 'B', 'C']), light: i * 0.006 });
for (let i = 0; i < 12; i++) lightSeq.push({ objs: shelf(['A', 'B', 'C']), light: 0.25 });
x = run(lightSeq);
ok(x.ev.length === 0, 'lumière qui change : 0 événement (obtenu ' + x.ev.length + ')');
// 9. Caméra déplacée (image décalée) : attente, puis tout est réappris, sans événement
const moved = (n) => Array.from({ length: n }, () => ({ objs: shelf(['A', 'B', 'C']).map((o) => ({ ...o, x: o.x + 9, y: o.y + 6 })).concat([{ x: 0, y: 0, w: 9, h: H, v: 140 }]) }));
const t9 = S.create({ width: W, height: H, slots: SLOTS, relearnFrames: 40 }); const r9 = rng(9); const ev9 = []; let reset9 = false;
for (const f of [...still(['A', 'B', 'C'], 12), ...moved(70)]) { const o = t9.process(frame(f.objs, r9)); ev9.push(...o.events); if (o.reset) reset9 = true; }
ok(ev9.length === 0 && reset9, 'caméra déplacée : références réapprises, 0 événement (obtenu ' + ev9.length + ')');
const strip = { x: 0, y: 0, w: 9, h: H, v: 140 };
for (const f of [...hand(['A', 'B', 'C'], 'C', ['A', 'B'], ['A', 'B']).map((f) => ({ objs: f.objs.map((o) => ({ ...o, x: o.x + 9, y: o.y + 6 })).concat([strip]) })), ...Array.from({ length: 12 }, () => ({ objs: shelf(['A', 'B']).map((o) => ({ ...o, x: o.x + 9, y: o.y + 6 })).concat([strip]) }))]) ev9.push(...t9.process(frame(f.objs, r9)).events);
ok(ev9.length === 1 && ev9[0].slot === 'C' && ev9[0].type === 'prise', 'après réapprentissage, une prise est de nouveau détectée');
// 10. Prise partielle (un article sur deux d'un même emplacement) : signalée si le changement est net
const half = SLOTS[1];
const two = (frac) => [item('A'), { ...item('B'), w: (half.w - 0.04) * W * frac }, item('C')];
const seq10 = [...Array.from({ length: 12 }, () => ({ objs: two(1) }))];
const s10 = SLOTS.find((s) => s.id === 'B'); const cx10 = (s10.x + s10.w / 2) * W - 8;
for (let i = 0; i < 6; i++) seq10.push({ objs: [...two(1), { x: cx10, y: H - 10 - i * 12, w: 16, h: 40, v: 25 }] });
for (let i = 5; i >= 0; i--) seq10.push({ objs: [...two(0.5), { x: cx10, y: H - 10 - i * 12, w: 16, h: 40, v: 25 }] });
for (let i = 0; i < 12; i++) seq10.push({ objs: two(0.5) });
x = run(seq10);
ok(x.ev.length === 1 && x.ev[0].slot === 'B' && x.ev[0].type === 'prise', 'moitié de l\'emplacement vidée : 1 prise');
// 11. Référence « rayon plein » conservée et restaurée : un rayon redémarré vide garde le bon sens
const t11 = S.create({ width: W, height: H, slots: SLOTS }); const r11 = rng(5);
for (const f of still(['A', 'B', 'C'], 12)) t11.process(frame(f.objs, r11));
const ref = t11.reference();
ok(ref && ref.length === W * H, 'référence « rayon plein » exportable');
const t12 = S.create({ width: W, height: H, slots: SLOTS }); t12.restore(ref); const r12 = rng(6); const ev12 = [];
for (const f of [...still(['A', 'C'], 12), ...hand(['A', 'C'], 'B', ['A', 'B', 'C'], ['A', 'B', 'C']), ...still(['A', 'B', 'C'], 12)]) ev12.push(...t12.process(frame(f.objs, r12)).events);
ok(ev12.length === 1 && ev12[0].type === 'remis' && ev12[0].slot === 'B', 'redémarrage avec B vide, B remis : « remis » grâce à la référence restaurée');
// 11 bis. Article qui déborde de son emplacement tracé : la prise est quand même signalée
const big = (ids) => ids.map((id) => { const o = item(id); return { ...o, x: o.x - 5, y: o.y - 4, w: o.w + 10, h: o.h + 8 }; });
const tb = S.create({ width: W, height: H, slots: SLOTS }); const rb = rng(13); const evb = [];
const sb = SLOTS[0]; const cxb = (sb.x + sb.w / 2) * W - 8;
const seqb = [...Array.from({ length: 12 }, () => ({ objs: big(['A', 'B', 'C']) }))];
for (let i = 0; i < 6; i++) seqb.push({ objs: [...big(['A', 'B', 'C']), { x: cxb, y: H - 10 - i * 12, w: 16, h: 40, v: 25 }] });
for (let i = 5; i >= 0; i--) seqb.push({ objs: [...big(['B', 'C']), { x: cxb, y: H - 10 - i * 12, w: 16, h: 40, v: 25 }] });
for (let i = 0; i < 12; i++) seqb.push({ objs: big(['B', 'C']) });
for (const f of seqb) evb.push(...tb.process(frame(f.objs, rb)).events);
ok(evb.length === 1 && evb[0].slot === 'A', 'article plus grand que l\'emplacement tracé : prise signalée');
// 11 ter. Images en couleur : main de même luminosité que l'article mais d'une autre teinte, qui s'arrête un instant
//         sur l'article en le saisissant → exactement une prise (ni ratée, ni suivie d'une fausse « remise »)
function rgba(objs, r) {
  const g = new Uint8ClampedArray(W * H * 4);
  for (let y = 0; y < H; y++) for (let x = 0; x < W; x++) { const v = 70 + ((x >> 3) + (y >> 3)) % 2 * 18 + (r() - 0.5) * 8, j = (y * W + x) * 4; g[j] = g[j + 1] = g[j + 2] = v; g[j + 3] = 255; }
  for (const o of objs) for (let y = Math.max(0, o.y | 0); y < Math.min(H, (o.y + o.h) | 0); y++) for (let x = Math.max(0, o.x | 0); x < Math.min(W, (o.x + o.w) | 0); x++) {
    const j = (y * W + x) * 4, f = o.pattern ? (((x >> 2) + (y >> 2)) % 2 ? 1 : 0.8) : 1;
    g[j] = o.rgb[0] * f; g[j + 1] = o.rgb[1] * f; g[j + 2] = o.rgb[2] * f;
  }
  return g;
}
const COL = { A: [200, 40, 40], B: [40, 160, 60], C: [40, 70, 200] };
const citem = (id) => ({ ...item(id), rgb: COL[id] });
const SKIN = [150, 110, 85];   // luminosité proche de l'article vert : invisible en niveaux de gris
function colorRun(pause, settleFrames = 6) {
  const t = S.create({ width: W, height: H, slots: SLOTS, settleFrames }); const r = rng(21); const ev = [];
  const s = SLOTS[1], cx = (s.x + s.w / 2) * W - 8;
  const seq = [];
  for (let i = 0; i < 12; i++) seq.push(['A', 'B', 'C'].map(citem));
  for (let i = 0; i < 6; i++) seq.push([...['A', 'B', 'C'].map(citem), { x: cx, y: H - 10 - i * 12, w: 16, h: 60, rgb: SKIN }]);
  for (let i = 0; i < pause; i++) seq.push([...['A', 'C'].map(citem), { x: cx, y: (s.y + 0.05) * H, w: 16, h: 80, rgb: SKIN }]);
  for (let i = 5; i >= 0; i--) seq.push([...['A', 'C'].map(citem), { x: cx, y: H - 10 - i * 12, w: 16, h: 60, rgb: SKIN }]);
  for (let i = 0; i < 15; i++) seq.push(['A', 'C'].map(citem));
  for (const f of seq) ev.push(...t.process(rgba(f, r)).events);
  return ev;
}
let cev = colorRun(3);
ok(cev.length === 1 && cev[0].type === 'prise' && cev[0].slot === 'B', 'couleur : main de même luminosité que l\'article → prise détectée (obtenu ' + JSON.stringify(cev.map((e) => e.type + e.slot)) + ')');
cev = colorRun(10);
ok(cev.length === 1 && cev[0].type === 'prise' && cev[0].slot === 'B', 'main qui s\'arrête plus longtemps que le délai d\'immobilité : une seule prise, pas de fausse remise (obtenu ' + JSON.stringify(cev.map((e) => e.type + e.slot)) + ')');
// 12. Sensibilité : réglage borné, paramètres cohérents
const lo = S.create({ width: W, height: H, slots: SLOTS, sensitivity: 1 }).params, hi = S.create({ width: W, height: H, slots: SLOTS, sensitivity: 10 }).params;
ok(lo.takeMin > hi.takeMin && lo.thrPix > hi.thrPix, 'sensibilité : plus haute = seuils plus bas');
// 13. Sans emplacement : ne plante pas, aucun événement
x = run([...still(['A', 'B', 'C'], 12), ...hand(['A', 'B', 'C'], 'B', ['A', 'C'], ['A', 'C']), ...still(['A', 'C'], 12)], { slots: [] });
ok(x.ev.length === 0, 'aucun emplacement tracé : aucun événement');

console.log(`Étagère : ${pass} réussi(s), ${fail} échec(s)`);
process.exit(fail ? 1 : 0);
