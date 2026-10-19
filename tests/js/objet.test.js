/* Tests de l'isolement de l'objet (addons/vision/public/vision-objet.js) — node tests/js/objet.test.js */
'use strict';
const O = require('../../addons/vision/public/vision-objet.js');
let pass = 0, fail = 0;
const ok = (c, m) => { if (c) pass++; else { fail++; console.log('  ✘ ' + m); } };
const G = O.G;
let seed = 7; const rnd = () => ((seed = (seed * 1103515245 + 12345) & 0x7fffffff) / 0x7fffffff);
/** Scène : fond (dégradé beige + bruit), objet rectangle rouge avec étiquette blanche, ombre facultative. */
function scene({ obj = true, gain = 1, ombre = false, x0 = 40, y0 = 30, x1 = 75, y1 = 90, fondUni = false } = {}) {
  const d = new Uint8ClampedArray(G * G * 4);
  for (let y = 0; y < G; y++) for (let x = 0; x < G; x++) {
    const k = (y * G + x) * 4;
    let r = fondUni ? 200 : 180 + x * 0.4, g = fondUni ? 190 : 165 + y * 0.3, b = fondUni ? 170 : 140;
    if (ombre && x > x1 && x < x1 + 18 && y > y0 + 10 && y < y1 + 8) { r *= 0.6; g *= 0.6; b *= 0.6; }
    if (obj && x >= x0 && x <= x1 && y >= y0 && y <= y1) { r = 190; g = 30; b = 35; if (x > x0 + 8 && x < x1 - 8 && y > y0 + 20 && y < y0 + 34) { r = g = b = 245; } }
    d[k] = Math.min(255, r * gain + (rnd() - 0.5) * 12); d[k + 1] = Math.min(255, g * gain + (rnd() - 0.5) * 12); d[k + 2] = Math.min(255, b * gain + (rnd() - 0.5) * 12); d[k + 3] = 255;
  }
  return d;
}
const fond = scene({ obj: false });
// 1. Avec fond appris
let s = O.segment(scene(), fond, G, G);
const vrai = (36 * 61) / (G * G);
ok(s.objets === 1 && Math.abs(s.frac - vrai) < 0.05, `fond : un objet, surface ${s.frac.toFixed(3)} ≈ ${vrai.toFixed(3)}`);
ok(s.bbox && Math.abs(s.bbox.x0 - 40) <= 3 && Math.abs(s.bbox.x1 - 75) <= 3 && Math.abs(s.bbox.y0 - 30) <= 3 && Math.abs(s.bbox.y1 - 90) <= 3, 'fond : cadre de l\'objet ' + JSON.stringify(s.bbox));
ok(s.mask[(31 + 26) * G + 57] === 1, 'fond : l\'étiquette blanche fait partie de l\'objet (trou rebouché)');
// 2. Comptoir vide : aucun objet, même si l'exposition de la caméra change
s = O.segment(scene({ obj: false, gain: 1.25 }), fond, G, G);
ok(s.objets === 0 && s.frac === 0, 'fond : comptoir vide, exposition +25 % → aucun objet (' + s.frac + ')');
s = O.segment(scene({ obj: false, gain: 0.8 }), fond, G, G);
ok(s.objets === 0, 'fond : comptoir vide, exposition −20 % → aucun objet');
// 3. Ombre portée ignorée
s = O.segment(scene({ ombre: true }), fond, G, G);
ok(s.bbox && s.bbox.x1 <= 79, 'fond : l\'ombre à droite de l\'objet n\'est pas prise pour l\'objet (x1=' + (s.bbox && s.bbox.x1) + ')');
// 4. Sans fond appris : modèle des bords
s = O.segment(scene({ fondUni: true }), null, G, G);
ok(s.objets >= 1 && s.bbox && Math.abs(s.bbox.x0 - 40) <= 4 && Math.abs(s.bbox.y1 - 90) <= 4, 'bords : objet trouvé sur fond uni ' + JSON.stringify(s.bbox));
s = O.segment(scene({ fondUni: true, ombre: true }), null, G, G);
ok(s.bbox && s.bbox.x1 <= 79, 'bords : l\'ombre portée n\'est pas prise pour l\'objet (x1=' + (s.bbox && s.bbox.x1) + ')');
s = O.segment(scene({ obj: false, fondUni: true }), null, G, G);
ok(s.objets === 0, 'bords : fond uni seul → aucun objet');
// 5. Cadre carré
const q = O.squareBox({ x0: 40, y0: 30, x1: 75, y1: 90 }, G, G);
ok(q.s > 0.55 && q.s <= 1 && q.x >= 0 && q.y >= 0 && q.x + q.s <= 1.0001 && q.y + q.s <= 1.0001, 'cadre carré dans l\'image ' + JSON.stringify(q));
ok(O.change(fond, fond, G * G) === 0 && O.change(fond, scene(), G * G) > 5, 'changement de scène mesuré');
console.log(`Isolement de l'objet : ${pass} réussi(s), ${fail} échec(s)`);
process.exit(fail ? 1 : 0);
