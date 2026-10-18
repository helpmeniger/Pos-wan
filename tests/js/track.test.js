/* Tests du moteur de surveillance (comptage par ligne / zone) — node tests/js/track.test.js */
'use strict';
const T = require('../../addons/vision/public/vision-track.js');
const W = 160, H = 120;
let pass = 0, fail = 0;
const ok = (c, m) => { if (c) pass++; else { fail++; console.log('  ✘ ' + m); } };
function rng(seed) { let s = seed >>> 0; return () => ((s = (s * 1664525 + 1013904223) >>> 0) / 4294967296); }
/** Image : fond texturé + bruit, objets (rectangles clairs) aux positions données. */
function frame(objs, r, light = 0) {
  const g = new Uint8Array(W * H);
  for (let y = 0; y < H; y++) for (let x = 0; x < W; x++) g[y * W + x] = Math.max(0, Math.min(255, 90 + ((x >> 3) + (y >> 3)) % 2 * 20 + (r() - 0.5) * 10 + light));
  for (const o of objs) for (let y = Math.max(0, o.y | 0); y < Math.min(H, (o.y + o.h) | 0); y++) for (let x = Math.max(0, o.x | 0); x < Math.min(W, (o.x + o.w) | 0); x++) g[y * W + x] = 220;
  return g;
}
function run(opts, seq) { const t = T.create(Object.assign({ width: W, height: H }, opts)); const r = rng(7); const ev = []; seq.forEach((objs, i) => ev.push(...t.process(frame(objs, r, typeof objs.light === 'number' ? objs.light : 0)).events)); return ev; }
const still = (n) => Array.from({ length: n }, () => []);
const moveX = (x0, x1, y, steps, w = 24, h = 24) => Array.from({ length: steps }, (_, i) => [{ x: x0 + (x1 - x0) * i / (steps - 1), y, w, h }]);
const line = { x1: 0.5, y1: 0.05, x2: 0.5, y2: 0.95 };

// 1. Un article traverse la ligne de gauche à droite : 1 passage, sens « sortie » (+1 → −1)
let e = run({ line }, [...still(8), ...moveX(5, 130, 50, 25), ...still(10)]);
ok(e.length === 1, '1 objet qui traverse = 1 passage (obtenu ' + e.length + ')');
const dirLR = e[0] && e[0].type;
ok(dirLR === 'sortie' || dirLR === 'entree', 'sens reconnu');
// 2. Sens inverse : l'autre sens ; option « inverser »
e = run({ line }, [...still(8), ...moveX(130, 5, 50, 25), ...still(10)]);
ok(e.length === 1 && e[0].type !== dirLR, 'sens inverse détecté (' + (e[0] && e[0].type) + ')');
e = run({ line, invert: true }, [...still(8), ...moveX(5, 130, 50, 25), ...still(10)]);
ok(e.length === 1 && e[0].type !== dirLR, 'option « inverser le sens »');
// 3. Objet qui hésite sur la ligne (va-et-vient dans la bande neutre) : aucun passage
const jitter = Array.from({ length: 30 }, (_, i) => [{ x: 68 + (i % 2 ? 2 : -2), y: 50, w: 24, h: 24 }]);
e = run({ line }, [...still(8), ...jitter, ...still(5)]);
ok(e.length === 0, 'hésitation sur la ligne : 0 passage (obtenu ' + e.length + ')');
// 4. Aller puis retour : 1 sortie + 1 entrée (retour)
e = run({ line }, [...still(8), ...moveX(5, 130, 50, 20), ...moveX(130, 5, 50, 20), ...still(5)]);
ok(e.length === 2 && e[0].type !== e[1].type, 'aller-retour : 2 passages de sens opposés');
// 5. Deux articles l'un au-dessus de l'autre en même temps : 2 passages
const two = Array.from({ length: 25 }, (_, i) => { const x = 5 + 125 * i / 24; return [{ x, y: 10, w: 22, h: 22 }, { x, y: 85, w: 22, h: 22 }]; });
e = run({ line }, [...still(8), ...two, ...still(5)]);
ok(e.length === 2, 'deux articles simultanés : 2 passages (obtenu ' + e.length + ')');
// 6. Bruit seul et petit objet sous la taille minimale : rien
e = run({ line }, [...still(40)]);
ok(e.length === 0, 'image immobile : 0 passage');
e = run({ line }, [...still(8), ...moveX(5, 140, 50, 25, 2, 2)]);
ok(e.length === 0, 'objet minuscule (bruit) ignoré');
// 7. Changement brutal de lumière : pas de faux passage
const lit = still(20).map((f, i) => { f.light = i >= 10 ? 60 : 0; return f; });
e = run({ line }, [...still(8), ...lit]);
ok(e.length === 0, 'changement de lumière : 0 passage');
// 8. Passage en dehors du segment (au-delà de son extrémité) : non compté
e = run({ line: { x1: 0.5, y1: 0.05, x2: 0.5, y2: 0.35 } }, [...still(8), ...moveX(5, 130, 85, 25), ...still(5)]);
ok(e.length === 0, 'passage au-delà de l\'extrémité de la ligne : 0');
// 9. Mode zone : chaque objet qui entre et reste dans la zone compte une fois
const zone = { x: 0.3, y: 0.2, w: 0.4, h: 0.6 };
e = run({ mode: 'zone', zone }, [...still(8), ...moveX(5, 70, 40, 15), ...Array.from({ length: 10 }, () => [{ x: 70, y: 40, w: 24, h: 24 }]), ...still(10)]);
ok(e.length === 1 && e[0].type === 'vu', 'zone : 1 article vu une seule fois');
// 10. Ligne horizontale, déplacement vertical
e = run({ line: { x1: 0.05, y1: 0.5, x2: 0.95, y2: 0.5 } }, [...still(8), ...Array.from({ length: 25 }, (_, i) => [{ x: 60, y: 2 + 90 * i / 24, w: 24, h: 24 }]), ...still(5)]);
ok(e.length === 1, 'ligne horizontale : 1 passage');
// 11. Boîte de l'événement fournie (pour la vignette et la reconnaissance)
e = run({ line }, [...still(8), ...moveX(5, 130, 50, 25)]);
ok(e[0] && e[0].box && e[0].box.w >= 20 && e[0].box.h >= 20, 'cadre de l\'objet fourni');
// 12. Sensibilité : réglage extrême accepté, paramètres cohérents
const p1 = T.create({ width: W, height: H, sensitivity: 1 }).params, p10 = T.create({ width: W, height: H, sensitivity: 10 }).params;
ok(p1.thr > p10.thr && p1.minArea > p10.minArea, 'sensibilité : seuils plus bas à 10 qu\'à 1');

console.log(`Moteur de surveillance : ${pass} réussi(s), ${fail} échec(s)`);
process.exit(fail ? 1 : 0);
