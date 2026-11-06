/**
 * Test navigateur (FACULTATIF, Playwright) : travail sans réseau, détection en
 * série, centre de contrôle, fiche PDF à QR code et vérification publique.
 * Tuiles simulées : fonctionne hors ligne.
 *
 *   node tests/e2e/terrain_e2e.js   (variables : voir map_e2e.js)
 */
'use strict';

const path = require('path');
let playwright;
try { playwright = require('playwright'); } catch (e) { playwright = require(process.env.PLAYWRIGHT_PATH || 'playwright'); }
const { satTile, roadTile, LAT0, LNG0 } = require('./synthetic_tiles.js');

const BASE = (process.env.NP_TEST_URL || 'http://localhost/niger_parcelles').replace(/\/$/, '') + '/';
const USER = process.env.NP_TEST_ADMIN_USER || 'admin';
const PASS = process.env.NP_TEST_ADMIN_PASS || '';
const OUT = process.env.NP_TEST_OUT || path.join(__dirname, 'test-results');
const DEMO = [13.4802, 2.1557]; // lots de démonstration (fictifs)

let passed = 0, failed = 0;
async function step(name, fn) {
    try { await fn(); passed++; console.log('  ✔ ' + name); } catch (e) { failed++; console.log('  ✘ ' + name + '\n      ' + e.message.split('\n')[0]); }
}
const assert = (c, m) => { if (!c) throw new Error(m || 'assertion'); };

(async () => {
    require('fs').mkdirSync(OUT, { recursive: true });
    const browser = await playwright.chromium.launch(process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {});
    const ctx = await browser.newContext({ viewport: { width: 1400, height: 900 }, serviceWorkers: 'allow' });
    const page = await ctx.newPage();
    const jsErrors = [];
    page.on('pageerror', (e) => jsErrors.push(e.message));
    page.on('dialog', (d) => d.accept());
    const cors = { 'Access-Control-Allow-Origin': '*' };
    await ctx.route(/tile\.openstreetmap|basemaps\.cartocdn\.com|World_Street_Map|World_Boundaries/, (r) => r.fulfill({ status: 200, contentType: 'image/png', headers: cors, body: roadTile }));
    await ctx.route(/World_Imagery\/MapServer\/tile\/(\d+)\/(\d+)\/(\d+)/, (r) => {
        const m = r.request().url().match(/tile\/(\d+)\/(\d+)\/(\d+)/);
        return r.fulfill({ status: 200, contentType: 'image/png', headers: cors, body: satTile(+m[1], +m[3], +m[2]) });
    });

    console.log('Niger Parcelles - terrain, contrôle et authentification sur ' + BASE);
    await page.goto(BASE + 'login.php');
    await page.fill('#username', USER);
    await page.fill('#password', PASS);
    await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);

    await step('Application installable : manifeste et service worker actifs', async () => {
        await page.goto(BASE + 'carte.php');
        await page.waitForFunction(() => window.NPMap);
        const ok = await page.evaluate(async () => { const r = await navigator.serviceWorker.ready; return !!r.active; });
        assert(ok, 'service worker');
        const man = await (await page.request.get(BASE + 'manifest.webmanifest')).json();
        assert(man.short_name === 'Niger Parcelles' && man.icons.length >= 2);
        await page.reload(); // la page est désormais servie et mise en cache par le service worker
        await page.waitForFunction(() => window.NPMap);
    });

    await step('Préparer la zone, couper le réseau : la carte et les parcelles restent disponibles', async () => {
        await page.evaluate(([la, ln]) => window.NPMap.map.setView([la, ln], 18, { animate: false }), DEMO);
        await page.waitForTimeout(1500);
        await page.click('#btnOffline');
        await page.click('#offPrepare');
        await page.waitForFunction(() => [...document.querySelectorAll('.toast-body')].some((t) => /copiée/.test(t.textContent)), null, { timeout: 10000 });
        await ctx.setOffline(true);
        await page.reload();
        await page.waitForFunction(() => window.NPMap, null, { timeout: 15000 });
        await page.evaluate(([la, ln]) => window.NPMap.map.setView([la, ln], 18, { animate: false }), DEMO);
        await page.waitForTimeout(2000);
        const n = await page.evaluate(() => { let c = 0; window.NPMap.map.eachLayer((l) => { if (l.feature && l.feature.properties && l.feature.properties.ref) c++; }); return c; });
        assert(n >= 5, 'parcelles affichées hors connexion : ' + n);
        assert(await page.evaluate(() => document.getElementById('btnOffline').classList.contains('btn-warning')), 'indicateur hors connexion');
    });

    await step('Saisie d\'une parcelle sans réseau : conservée sur l\'appareil', async () => {
        await page.evaluate(() => window.NPMap.map.setView([13.4835, 2.1600], 19, { animate: false }));
        await page.waitForTimeout(500);
        await page.click('#btnDraw');
        await page.waitForTimeout(400);
        const box = await page.locator('#map').boundingBox();
        const cx = box.x + box.width / 2 - 150, cy = box.y + box.height / 2;
        const pts = [[cx - 50, cy - 50], [cx + 50, cy - 50], [cx + 50, cy + 50], [cx - 50, cy + 50]];
        for (const [x, y] of pts) { await page.mouse.click(x, y); await page.waitForTimeout(120); }
        await page.mouse.click(pts[0][0], pts[0][1]);
        await page.waitForSelector('#pfLoc');
        await page.fill('#pfLoc', 'TEST-E2E-HORS-LIGNE');
        await page.click('#edSave');
        await page.waitForFunction(() => document.getElementById('panelTitle').textContent.includes('appareil'), null, { timeout: 10000 });
        assert((await page.textContent('#offBadge')) === '1', 'badge');
        await page.screenshot({ path: path.join(OUT, 'hors-ligne.png') });
    });

    await step('Retour du réseau : synchronisation automatique, référence attribuée', async () => {
        await ctx.setOffline(false);
        await page.waitForFunction(() => document.getElementById('offBadge').hidden, null, { timeout: 20000 });
        const html = await (await page.request.get(BASE + 'parcelles.php?q=TEST-E2E-HORS-LIGNE')).text();
        const ids = [...new Set([...html.matchAll(/parcelle_details\.php\?id=(\d+)/g)].map((m) => m[1]))];
        assert(ids.length === 1, 'parcelles synchronisées : ' + ids.length);
        page._offlineId = ids[0];
    });

    await step('Détection en série de toutes les concessions visibles (image satellite)', async () => {
        // Contexte sans service worker : les tuiles simulées sont injectées par Playwright,
        // qui n'intercepte pas les requêtes émises par un service worker.
        const ctx2 = await browser.newContext({ viewport: { width: 1400, height: 900 }, serviceWorkers: 'block' });
        await ctx2.route(/tile\.openstreetmap|basemaps\.cartocdn\.com|World_Street_Map|World_Boundaries/, (r) => r.fulfill({ status: 200, contentType: 'image/png', headers: cors, body: roadTile }));
        await ctx2.route(/World_Imagery\/MapServer\/tile\/(\d+)\/(\d+)\/(\d+)/, (r) => {
            const m = r.request().url().match(/tile\/(\d+)\/(\d+)\/(\d+)/);
            return r.fulfill({ status: 200, contentType: 'image/png', headers: cors, body: satTile(+m[1], +m[3], +m[2]) });
        });
        const page = await ctx2.newPage();
        page.on('pageerror', (e) => jsErrors.push(e.message));
        await page.goto(BASE + 'login.php');
        await page.fill('#username', USER);
        await page.fill('#password', PASS);
        await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
        await page.goto(BASE + 'carte.php');
        await page.waitForFunction(() => window.NPMap);
        await page.evaluate(([la, ln]) => window.NPMap.map.setView([la, ln], 19, { animate: false }), [LAT0 + 0.0003, LNG0]);
        await page.waitForTimeout(800);
        await page.evaluate(() => { window.NPMap.startAssist('detect'); return window.NPMap.detectInView(); });
        await page.waitForFunction(() => window.NPMap.candidates().length >= 10, null, { timeout: 90000 });
        const areas = await page.evaluate(() => window.NPMap.candidates().map((c) => c.area));
        const good = areas.filter((a) => Math.abs(a - 400) < 40).length;
        assert(good / areas.length > 0.8, `${good}/${areas.length} propositions proches de 400 m²`);
        await page.screenshot({ path: path.join(OUT, 'detection-serie.png') });
        await ctx2.close();
    });

    await step('Centre de contrôle : dossiers à risque et qualité des limites', async () => {
        await page.goto(BASE + 'controle.php?level=tous');
        assert(/Indicateurs/.test(await page.textContent('table')), 'tableau des risques');
        await page.screenshot({ path: path.join(OUT, 'controle-risques.png'), fullPage: false });
        await page.goto(BASE + 'controle.php?tab=limites');
        assert(/Qualité des limites/.test(await page.textContent('body')));
    });

    await step('Fiche PDF à QR code puis vérification publique (sans connexion)', async () => {
        const r = await page.request.get(BASE + 'api/export.php?type=fiche_pdf&id=' + page._offlineId);
        assert((await r.body()).slice(0, 5).toString() === '%PDF-');
        await page.goto(BASE + 'parcelle_details.php?id=' + page._offlineId + '&tab=historique');
        const code = await page.locator('#tab-historique .ref').first().textContent();
        const anon = await browser.newContext({ viewport: { width: 900, height: 900 } });
        const p2 = await anon.newPage();
        await p2.goto(BASE + 'verifier.php?t=' + encodeURIComponent(code.trim()));
        assert(/Fiche authentique et à jour/.test(await p2.textContent('body')), 'vérification');
        await p2.screenshot({ path: path.join(OUT, 'verification.png') });
        await anon.close();
    });

    await step('Nettoyage (archivage des parcelles de test)', async () => {
        const csrf = await page.evaluate(() => JSON.parse(document.getElementById('np-config').textContent).csrf);
        const r = await page.request.post(BASE + 'api/parcelles.php?action=archive', { headers: { 'X-CSRF-Token': csrf, 'Content-Type': 'application/json' },
            data: { id: +page._offlineId, reason: 'Test automatisé' } });
        assert(r.ok());
    });

    await step('Aucune erreur JavaScript', async () => { assert(jsErrors.length === 0, jsErrors.join(' | ')); });
    await browser.close();
    console.log(`\n${passed} test(s) réussi(s), ${failed} échec(s). Captures : ${OUT}`);
    process.exit(failed ? 1 : 0);
})();
