/**
 * Test navigateur des outils de précision et de l'assistant (FACULTATIF, Playwright).
 * Les tuiles OpenStreetMap / satellite sont simulées : image synthétique de
 * concessions murées de 20 × 20 m, pour un test reproductible hors ligne.
 *
 *   node tests/e2e/assist_e2e.js   (variables : voir map_e2e.js)
 */
'use strict';

const path = require('path');
const zlib = require('zlib');
let playwright;
try { playwright = require('playwright'); } catch (e) { playwright = require(process.env.PLAYWRIGHT_PATH || 'playwright'); }
const AS = require('../../assets/js/parcel-assist.js');

const BASE = (process.env.NP_TEST_URL || 'http://localhost/niger_parcelles').replace(/\/$/, '') + '/';
const USER = process.env.NP_TEST_ADMIN_USER || 'admin';
const PASS = process.env.NP_TEST_ADMIN_PASS || '';
const OUT = process.env.NP_TEST_OUT || path.join(__dirname, 'test-results');

// Zone de test (fictive) et grille de concessions
const LAT0 = 13.4205, LNG0 = 2.0185, ANG = 15 * Math.PI / 180, LOT = 20;

// ---------- Encodeur PNG minimal
const crcTable = new Int32Array(256).map((_, n) => { let c = n; for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1; return c; });
const crc32 = (buf) => { let c = -1; for (const b of buf) c = crcTable[(c ^ b) & 0xff] ^ (c >>> 8); return (c ^ -1) >>> 0; };
function chunk(type, data) {
    const len = Buffer.alloc(4); len.writeUInt32BE(data.length);
    const td = Buffer.concat([Buffer.from(type), data]);
    const crc = Buffer.alloc(4); crc.writeUInt32BE(crc32(td));
    return Buffer.concat([len, td, crc]);
}
function png(w, h, pixel) {
    const raw = Buffer.alloc((w * 3 + 1) * h);
    for (let y = 0; y < h; y++) {
        raw[y * (w * 3 + 1)] = 0;
        for (let x = 0; x < w; x++) { const [r, g, b] = pixel(x, y); const o = y * (w * 3 + 1) + 1 + x * 3; raw[o] = r; raw[o + 1] = g; raw[o + 2] = b; }
    }
    const ihdr = Buffer.alloc(13); ihdr.writeUInt32BE(w, 0); ihdr.writeUInt32BE(h, 4); ihdr[8] = 8; ihdr[9] = 2;
    return Buffer.concat([Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]), chunk('IHDR', ihdr), chunk('IDAT', zlib.deflateSync(raw)), chunk('IEND', Buffer.alloc(0))]);
}
let seed = 7;
const rnd = () => { seed = (seed * 1103515245 + 12345) & 0x7fffffff; return seed / 0x7fffffff; };
/** Tuile satellite synthétique : concessions de 20 m tournées de 15°, murs, toits, rues. */
function satTile(z, tx, ty) {
    const c0 = AS.latLngToPixel(LAT0, LNG0, z);
    const mpp = AS.metersPerPixel(LAT0, z);
    const co = Math.cos(ANG), si = Math.sin(ANG);
    const sands = [[205, 178, 140], [196, 170, 128], [214, 188, 150], [200, 172, 138]];
    return png(256, 256, (x, y) => {
        const mx = (tx * 256 + x - c0.x) * mpp, my = (ty * 256 + y - c0.y) * mpp;
        const u = mx * co + my * si, v = -mx * si + my * co;
        const iu = Math.floor(u / LOT + 0.5), iv = Math.floor(v / LOT + 0.5);
        const fu = u - iu * LOT, fv = v - iv * LOT;
        let col;
        if (((iv % 3) + 3) % 3 === 2) col = [150, 135, 115];
        else if (Math.abs(Math.abs(fu) - LOT / 2) < 0.4 || Math.abs(Math.abs(fv) - LOT / 2) < 0.4) col = [95, 80, 70];
        else if (fu > -7 && fu < 0 && fv > -6 && fv < 1) col = [160, 165, 170];
        else col = sands[(((iu % 4) + 4) % 4 + (((iv % 2) + 2) % 2) * 2) % 4];
        const n = (rnd() - 0.5) * 14;
        return [col[0] + n, col[1] + n, col[2] + n].map((c) => Math.max(0, Math.min(255, Math.round(c))));
    });
}
const roadTile = png(256, 256, (x, y) => (x % 64 < 3 || y % 64 < 3 ? [255, 255, 255] : [242, 239, 233]));

let passed = 0, failed = 0;
async function step(name, fn) {
    try { await fn(); passed++; console.log('  ✔ ' + name); } catch (e) { failed++; console.log('  ✘ ' + name + '\n      ' + e.message.split('\n')[0]); }
}
function assert(c, m) { if (!c) throw new Error(m || 'assertion'); }

(async () => {
    require('fs').mkdirSync(OUT, { recursive: true });
    const browser = await playwright.chromium.launch(process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {});
    const ctx = await browser.newContext({ viewport: { width: 1400, height: 900 }, geolocation: { latitude: LAT0, longitude: LNG0, accuracy: 4 }, permissions: ['geolocation'] });
    const page = await ctx.newPage();
    const jsErrors = [];
    page.on('pageerror', (e) => jsErrors.push(e.message));
    page.on('dialog', (d) => d.accept());
    const referers = [];
    let blockOsm = false;
    const cors = { 'Access-Control-Allow-Origin': '*' };
    await ctx.route(/tile\.openstreetmap\.org\//, (route) => {
        referers.push(route.request().headers().referer || '');
        if (blockOsm) return route.fulfill({ status: 403, body: 'blocked' });
        return route.fulfill({ status: 200, contentType: 'image/png', headers: cors, body: roadTile });
    });
    await ctx.route(/tile\.openstreetmap\.fr|basemaps\.cartocdn\.com|World_Street_Map|World_Boundaries/, (route) =>
        route.fulfill({ status: 200, contentType: 'image/png', headers: cors, body: roadTile }));
    await ctx.route(/World_Imagery\/MapServer\/tile\/(\d+)\/(\d+)\/(\d+)/, (route) => {
        const m = route.request().url().match(/tile\/(\d+)\/(\d+)\/(\d+)/);
        return route.fulfill({ status: 200, contentType: 'image/png', headers: cors, body: satTile(+m[1], +m[3], +m[2]) });
    });

    console.log('Niger Parcelles - précision et assistant sur ' + BASE);
    await page.goto(BASE + 'login.php');
    await page.fill('#username', USER);
    await page.fill('#password', PASS);
    await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);

    await step('Fond OpenStreetMap affiché, avec en-tête Referer (exigé par OSM)', async () => {
        await page.goto(BASE + 'carte.php');
        await page.waitForFunction(() => window.NPMap);
        await page.waitForTimeout(2500);
        assert(referers.length > 0, 'aucune tuile OSM demandée');
        assert(referers.every((r) => r.startsWith('http')), 'Referer absent : ' + JSON.stringify(referers.slice(0, 3)));
        assert((await page.textContent('#tileText')).includes('OpenStreetMap'), await page.textContent('#tileText'));
    });

    await step('Bascule automatique vers un fond OSM de secours si OSM est bloqué', async () => {
        blockOsm = true;
        await page.reload();
        await page.waitForFunction(() => window.NPMap);
        await page.evaluate(() => window.NPMap.map.setView([13.43, 2.02], 15));
        await page.waitForFunction(() => document.querySelector('#tileText').textContent.includes('France'), null, { timeout: 15000 });
        blockOsm = false;
    });

    await step('Zoom jusqu\'à 25 (vue de quelques mètres, résolution millimétrique affichée)', async () => {
        await page.evaluate(([la, ln]) => window.NPMap.map.setView([la, ln], 25, { animate: false }), [LAT0, LNG0]);
        await page.waitForTimeout(800);
        assert(await page.evaluate(() => window.NPMap.map.getZoom()) === 25, 'zoom');
        const info = await page.textContent('#zoomInfo');
        assert(/Zoom 25/.test(info) && /mm/.test(info) && /vue ≈ \d/.test(info), info);
        await page.screenshot({ path: path.join(OUT, 'zoom25.png') });
    });

    const EMPTY_LAT = LAT0 + 0.01, EMPTY_LNG = LNG0 + 0.01; // zone sans parcelle
    await step('Clic : position exacte (degrés, DMS, UTM, précision du pointage)', async () => {
        await page.evaluate(([la, ln]) => window.NPMap.map.setView([la, ln], 22, { animate: false }), [EMPTY_LAT, EMPTY_LNG]);
        await page.waitForTimeout(300);
        const box = await page.locator('#map').boundingBox();
        await page.mouse.click(box.x + box.width / 2, box.y + box.height / 2);
        await page.waitForSelector('.pos-popup');
        const t = await page.textContent('.pos-popup');
        const lat = parseFloat(t.match(/Latitude\s*([\d.]+)/)[1]);
        const lng = parseFloat(t.match(/Longitude\s*([\d.]+)/)[1]);
        assert(Math.abs(lat - EMPTY_LAT) < 2e-6 && Math.abs(lng - EMPTY_LNG) < 2e-6, `position ${lat}, ${lng}`);
        assert(/UTM 31N/.test(t) && /° /.test(t) && /cm à ce zoom/.test(t), t);
        await page.screenshot({ path: path.join(OUT, 'position.png') });
        await page.keyboard.press('Escape');
    });

    await step('Position GPS moyennée', async () => {
        const fix = await page.evaluate(() => window.NPMap.gpsAverage(2, null));
        assert(fix && Math.abs(fix.lat - LAT0) < 1e-6, JSON.stringify(fix));
    });

    let detected = null;
    await step('Assistant : détection d\'une concession de 400 m² sur l\'image satellite', async () => {
        // clic dans la cour de la concession centrale (hors toit)
        const m = 5, u = 4, v = 4;
        const dx = u * Math.cos(ANG) - v * Math.sin(ANG), dy = u * Math.sin(ANG) + v * Math.cos(ANG);
        const lat = LAT0 - dy / 110600, lng = LNG0 + dx / (111320 * Math.cos(LAT0 * Math.PI / 180));
        await page.evaluate(([la, ln]) => window.NPMap.map.setView([la, ln], 20, { animate: false }), [lat, lng]);
        await page.evaluate(() => window.NPMap.startAssist('detect'));
        await page.evaluate(([la, ln]) => window.NPMap.detectAt(L.latLng(la, ln)), [lat, lng]);
        await page.waitForFunction(() => window.NPMap.candidates().length > 0, null, { timeout: 15000 });
        detected = await page.evaluate(() => { const c = window.NPMap.candidates()[0]; return { area: c.area, source: c.source, score: c.score }; });
        assert(/détection image/.test(detected.source), detected.source);
        assert(Math.abs(detected.area - 400) < 25, 'superficie ' + detected.area);
        assert(m);
        await page.screenshot({ path: path.join(OUT, 'detection.png') });
    });

    await step('Assistant : gabarit 20 × 20 m orienté comme le bâti', async () => {
        await page.evaluate(() => window.NPMap.startAssist('template'));
        const box = await page.locator('#map').boundingBox();
        await page.mouse.click(box.x + 150, box.y + 200);
        await page.waitForFunction(() => window.NPMap.candidates().length === 2);
        const a = await page.evaluate(() => window.NPMap.candidates()[1].area);
        assert(Math.abs(a - 400) < 1, 'gabarit ' + a);
    });

    await step('Assistant : enregistrement groupé des propositions', async () => {
        await page.fill('#cfLoc', 'TEST-E2E-ASSISTANT');
        await page.click('#candSave');
        await page.waitForFunction(() => document.querySelector('#panelBody').textContent.match(/enregistrée|chevauchent/), null, { timeout: 15000 });
        const t = await page.textContent('#panelBody');
        if (/chevauchent/.test(t)) { await page.click('#candForce'); await page.waitForTimeout(1500); }
        assert(/enregistrée/.test(await page.textContent('#panelBody')), 'aucune parcelle enregistrée');
    });

    await step('Saisie de coordonnées UTM et longueurs des côtés affichées', async () => {
        await page.click('#panelClose');
        const u = AS.toUtm(LAT0 + 0.002, LNG0 + 0.002);
        const lines = [[0, 0], [20, 0], [20, 20], [0, 20]].map(([dx, dy]) => (u.x + dx).toFixed(2) + ' ' + (u.y + dy).toFixed(2)).join('\n');
        await page.click('button:has-text("Précision")');
        await page.click('#prCoords');
        await page.selectOption('#ceCrs', '31');
        await page.fill('#ceText', lines);
        await page.click('#ceGo');
        await page.waitForSelector('#liveMeasure');
        const t = await page.textContent('#liveMeasure');
        assert(/400,2\d m²/.test(t), 'surface UTM 20 × 20 m (attendu 400,23 m² au sol) : ' + t);
        await page.waitForSelector('.edge-label');
        const labels = await page.$$eval('.edge-label', (els) => els.map((e) => e.textContent));
        assert(labels.length === 4 && labels.every((l) => /^20,0[01] m$/.test(l)), JSON.stringify(labels));
        await page.screenshot({ path: path.join(OUT, 'coordonnees.png') });
        await page.click('#edCancel');
    });

    await step('Noms des propriétaires affichés sur les parcelles', async () => {
        await page.goto(BASE + 'carte.php?parcel=9002');
        await page.waitForFunction(() => window.NPMap);
        await page.waitForTimeout(1500);
        await page.evaluate(() => window.NPMap.map.setZoom(20, { animate: false }));
        await page.click('#btnOwners');
        await page.waitForSelector('.owner-label .ol-own', { timeout: 10000 });
        const names = await page.$$eval('.owner-label', (els) => els.map((e) => e.textContent).join(' | '));
        assert(/DÉMO-ALPHA/.test(names), names.slice(0, 200));
        await page.screenshot({ path: path.join(OUT, 'proprietaires.png') });
    });

    await step('Clic sur une parcelle : fiche + point cliqué exact', async () => {
        await page.goto(BASE + 'carte.php');
        await page.waitForFunction(() => window.NPMap);
        await page.evaluate(([la, ln]) => window.NPMap.map.setView([la, ln], 21, { animate: false }), [LAT0, LNG0]);
        await page.waitForTimeout(1500);
        const box = await page.locator('#map').boundingBox();
        await page.mouse.click(box.x + box.width / 2, box.y + box.height / 2);
        await page.waitForFunction(() => document.querySelector('#panelBody') && document.querySelector('#panelBody').textContent.includes('Point cliqué'), null, { timeout: 10000 });
        assert(/UTM 31N/.test(await page.textContent('#panelBody')));
    });

    await step('Nettoyage : parcelles de test archivées', async () => {
        const html = await (await page.request.get(BASE + 'parcelles.php?q=TEST-E2E-ASSISTANT')).text();
        const ids = [...new Set([...html.matchAll(/parcelle_details\.php\?id=(\d+)/g)].map((m) => m[1]))];
        const csrf = await page.evaluate(() => JSON.parse(document.getElementById('np-config').textContent).csrf);
        for (const id of ids) {
            const r = await page.request.post(BASE + 'api/parcelles.php?action=archive', { headers: { 'X-CSRF-Token': csrf, 'Content-Type': 'application/json' }, data: { id: +id, reason: 'Test automatisé' } });
            assert(r.ok(), 'archivage ' + id);
        }
        assert(ids.length >= 2, 'parcelles de test trouvées : ' + ids.length);
    });

    await step('Aucune erreur JavaScript', async () => { assert(jsErrors.length === 0, jsErrors.join(' | ')); });

    await browser.close();
    console.log(`\n${passed} test(s) réussi(s), ${failed} échec(s). Captures : ${OUT}` + (detected ? ` · détection : ${detected.area.toFixed(1)} m², confiance ${Math.round(detected.score * 100)} %` : ''));
    process.exit(failed ? 1 : 0);
})();
