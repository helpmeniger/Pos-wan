/**
 * Tests du moteur d'assistance (node tests/js/assist_test.js) - aucune dépendance.
 * Image satellite synthétique : concessions murées de 20 × 20 m, tournées,
 * avec toits, bruit et voisins contigus.
 */
'use strict';
const A = require('../../assets/js/parcel-assist.js');

let passed = 0, failed = 0;
function test(name, fn) {
    try { fn(); passed++; console.log('  ✔ ' + name); } catch (e) { failed++; console.log('  ✘ ' + name + '\n      ' + e.message); }
}
function near(exp, act, tol, msg) { if (Math.abs(exp - act) > tol) throw new Error((msg || '') + ` attendu ${exp} ± ${tol}, obtenu ${act}`); }
function ok(c, msg) { if (!c) throw new Error(msg || 'assertion'); }

// Générateur pseudo-aléatoire déterministe
let seed = 42;
const rnd = () => { seed = (seed * 1103515245 + 12345) & 0x7fffffff; return seed / 0x7fffffff; };

/** Image w×h : grille de concessions de lotM × lotM mètres tournées de angleDeg, murs sombres, toits. */
function synthImage(w, h, mpp, lotM, angleDeg) {
    const img = new Uint8ClampedArray(w * h * 4);
    const ang = angleDeg * Math.PI / 180, c = Math.cos(ang), s = Math.sin(ang);
    const lot = lotM / mpp, wall = Math.max(1.2, 0.4 / mpp);
    const sands = [[205, 178, 140], [196, 170, 128], [214, 188, 150], [200, 172, 138]];
    for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) {
        // coordonnées dans le repère tourné, origine au centre
        const u = (x - w / 2) * c + (y - h / 2) * s, v = -(x - w / 2) * s + (y - h / 2) * c;
        const iu = Math.floor(u / lot + 0.5), iv = Math.floor(v / lot + 0.5);
        const fu = u - iu * lot, fv = v - iv * lot; // position dans le lot, [-lot/2, lot/2]
        let col;
        const street = (Math.abs(iv) % 3 === 2); // une rangée sur trois = rue
        if (street) col = [150, 135, 115];
        else if (Math.abs(Math.abs(fu) - lot / 2) < wall || Math.abs(Math.abs(fv) - lot / 2) < wall) col = [95, 80, 70];
        else if (fu > -lot * 0.35 && fu < 0 && fv > -lot * 0.3 && fv < lot * 0.05) col = [160, 165, 170]; // toit
        else col = sands[(((iu % 4) + 4) % 4 + (((iv % 2) + 2) % 2) * 2) % 4];
        const n = (rnd() - 0.5) * 16;
        const o = (y * w + x) * 4;
        img[o] = col[0] + n; img[o + 1] = col[1] + n; img[o + 2] = col[2] + n; img[o + 3] = 255;
    }
    return img;
}

console.log('Moteur d\'assistance (parcel-assist.js)');

test('Mercator : aller-retour pixel <-> lat/lng et résolution au sol', () => {
    const p = A.latLngToPixel(13.5116, 2.1254, 19);
    const q = A.pixelToLatLng(p.x, p.y, 19);
    near(13.5116, q.lat, 1e-9); near(2.1254, q.lng, 1e-9);
    near(0.2903, A.metersPerPixel(13.5116, 19), 0.001, 'm/px au zoom 19');
    near(0.0045, A.metersPerPixel(13.5116, 25), 0.0005, 'm/px au zoom 25');
});

test('UTM : identique à l\'implémentation PHP (Niamey, zone 31N)', () => {
    const u = A.toUtm(13.5116, 2.1254);
    ok(u.zone === 31);
    near(405351.917, u.x, 0.01); near(1493881.554, u.y, 0.01);
    const b = A.fromUtm(u.x, u.y, 31);
    near(13.5116, b.lat, 1e-8); near(2.1254, b.lng, 1e-8);
    ok(A.toDms(13.5116, true) === "13° 30' 41,76\" N", A.toDms(13.5116, true));
});

test('Rectangle d\'aire minimale d\'un carré tourné', () => {
    const corners = A.rectCorners(100, 100, 50, 30, 0.3);
    const r = A.minAreaRect(corners.concat([[100, 100]]));
    near(1500, r.area, 0.5); near(0.3, ((r.angle % (Math.PI / 2)) + Math.PI / 2) % (Math.PI / 2) % (Math.PI / 2), 0.01);
});

test('Gabarit 20 × 20 m = 400 m² (géodésique) à n\'importe quelle orientation', () => {
    for (const ang of [0, 0.4, 1.1]) {
        const ll = A.rectangleLatLngs(13.5, 2.1, 20, 20, ang);
        const a = A.analyzePolygon(ll);
        near(400, a.areaM2, 0.5, 'aire');
        near(20, a.wM, 0.02); near(20, a.hM, 0.02);
    }
});

test('Découpage d\'un îlot de 60 × 40 m en lots de 20 × 20 m', () => {
    const block = A.rectangleLatLngs(13.5, 2.1, 60, 40, 0.25);
    const lots = A.subdivide(block, 20, 20);
    ok(lots.length === 6, 'lots : ' + lots.length);
    lots.forEach((l) => near(400, A.analyzePolygon(l).areaM2, 0.5));
});

test('Équerrage et ajustement à 400 m²', () => {
    const rough = [{ lat: 13.5, lng: 2.1 }, { lat: 13.50018, lng: 2.10001 }, { lat: 13.50019, lng: 2.10019 }, { lat: 13.49999, lng: 2.10017 }];
    const sq = A.squarePolygon(rough);
    ok(sq.length === 4);
    const a = A.analyzePolygon(sq);
    const scaled = A.scaleToArea(sq, a.areaM2, 400);
    near(400, A.analyzePolygon(scaled).areaM2, 0.5);
});

for (const angle of [0, 17, 38]) {
    test(`Détection d'une concession de 20 × 20 m sur image satellite synthétique (rotation ${angle}°)`, () => {
        const w = 512, h = 512, mpp = 0.2903;
        const img = synthImage(w, h, mpp, 20, angle);
        // clic dans la cour (zone sable), hors toit : coin du lot central décalé
        const ang = angle * Math.PI / 180;
        const u = 20 / mpp * 0.25, v = 20 / mpp * 0.3;
        const sx = Math.round(w / 2 + u * Math.cos(ang) - v * Math.sin(ang));
        const sy = Math.round(h / 2 + u * Math.sin(ang) + v * Math.cos(ang));
        const r = A.detectParcel(img, w, h, sx, sy, { mpp, targetArea: 400 });
        ok(r, 'aucune détection');
        near(400, r.area, 25, 'superficie détectée (axe des murs)');
        ok(r.score > 0.4, 'score faible : ' + r.score.toFixed(2));
        const det = ((r.angle * 180 / Math.PI) % 90 + 90) % 90;
        const diff = Math.min(Math.abs(det - angle), 90 - Math.abs(det - angle));
        ok(diff < 4, `orientation : ${det.toFixed(1)}° au lieu de ${angle}°`);
    });
}

test('Orientation dominante retrouvée par le tenseur de structure', () => {
    const w = 256, h = 256, mpp = 0.29;
    const img = synthImage(w, h, mpp, 20, 25);
    const pre = A.preprocess(img, w, h);
    const o = A.dominantOrientation(pre, 128, 128, 100);
    const deg = o.angle * 180 / Math.PI;
    ok(Math.min(Math.abs(deg - 25), 90 - Math.abs(deg - 25)) < 4, 'orientation ' + deg.toFixed(1));
});

test('Superficie et périmètre ellipsoïdaux = référence Karney (GeographicLib) à 0,5 ppm près', () => {
    const ref = require('../fixtures/geodesic_reference.json').cases;
    for (const [k, v] of Object.entries(ref)) {
        const a = A.geodesicArea(v.coords), p = A.geodesicLength(v.coords, true);
        ok(Math.abs(a / v.area - 1) < 5e-7, `${k} : aire ${a} au lieu de ${v.area}`);
        ok(Math.abs(p / v.perimeter - 1) < 5e-7, `${k} : périmètre ${p} au lieu de ${v.perimeter}`);
    }
});

console.log(`\n${passed} test(s) réussi(s), ${failed} échec(s)`);
process.exit(failed ? 1 : 0);
