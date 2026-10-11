/**
 * Test de bout en bout de l'interface (navigateur réel, Playwright) - FACULTATIF.
 * Outil de développement uniquement : l'application n'a pas besoin de Node.js.
 *
 *   npm install playwright   (une fois, dans tests/e2e)
 *   npx playwright install chromium
 *   set NP_TEST_URL=http://localhost/niger_parcelles
 *   set NP_TEST_ADMIN_USER=admin & set NP_TEST_ADMIN_PASS=...
 *   node tests/e2e/map_e2e.js
 *
 * Vérifie : chargement de la carte, dessin d'un polygone, calcul de superficie,
 * enregistrement, rechargement depuis MySQL, modification des limites,
 * mesure de distance, recherche, fiche détaillée, affichage mobile.
 */
'use strict';

const path = require('path');
let playwright;
try { playwright = require('playwright'); } catch (e) { playwright = require(process.env.PLAYWRIGHT_PATH || 'playwright'); }

const BASE = (process.env.NP_TEST_URL || 'http://localhost/niger_parcelles').replace(/\/$/, '') + '/';
const USER = process.env.NP_TEST_ADMIN_USER || 'admin';
const PASS = process.env.NP_TEST_ADMIN_PASS || '';
const OUT = process.env.NP_TEST_OUT || path.join(__dirname, 'test-results');

let passed = 0;
let failed = 0;
async function step(name, fn) {
    try {
        await fn();
        passed++;
        console.log('  ✔ ' + name);
    } catch (e) {
        failed++;
        console.log('  ✘ ' + name + '\n      ' + e.message);
    }
}
function assert(cond, msg) { if (!cond) throw new Error(msg || 'assertion'); }

(async () => {
    require('fs').mkdirSync(OUT, { recursive: true });
    const launchOpts = process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {};
    const browser = await playwright.chromium.launch(launchOpts);
    const page = await browser.newPage({ viewport: { width: 1400, height: 900 } });
    const jsErrors = [];
    page.on('pageerror', (e) => jsErrors.push(e.message));
    page.on('dialog', (d) => d.accept());
    console.log('Niger Parcelles - tests navigateur sur ' + BASE);

    await step('Connexion', async () => {
        await page.goto(BASE + 'login.php');
        await page.fill('#username', USER);
        await page.fill('#password', PASS);
        await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
        assert(page.url().includes('dashboard.php'), 'redirection vers le tableau de bord : ' + page.url());
        await page.waitForSelector('#chartEvolution, .kpi');
    });

    await step('Chargement de la carte centrée sur le Niger', async () => {
        await page.goto(BASE + 'carte.php');
        await page.waitForFunction(() => window.NPMap && window.NPMap.map);
        const c = await page.evaluate(() => window.NPMap.map.getCenter());
        assert(c.lat > 11 && c.lat < 24 && c.lng > 0 && c.lng < 16, 'centre hors Niger : ' + JSON.stringify(c));
        assert(await page.isVisible('.leaflet-control-scale'), 'échelle');
        assert(await page.isVisible('.map-legend'), 'légende');
        await page.mouse.move(700, 450);
        assert((await page.textContent('#cursorCoords')).includes('Lat'), 'coordonnées du curseur');
    });

    let createdRef = null;
    let createdId = null;
    const lat0 = 13.43 + Math.random() * 0.01;
    const lng0 = 2.05 + Math.random() * 0.01;
    await step('Dessin d\'un polygone, superficie en direct et enregistrement', async () => {
        await page.evaluate(([la, ln]) => window.NPMap.map.setView([la, ln], 18, { animate: false }), [lat0, lng0]);
        await page.waitForTimeout(500);
        await page.click('#btnDraw');
        await page.waitForTimeout(400); // ouverture du panneau latéral
        const box = await page.locator('#map').boundingBox();
        const cx = box.x + box.width / 2 - 100;
        const cy = box.y + box.height / 2;
        const pts = [[cx - 60, cy - 50], [cx + 70, cy - 50], [cx + 70, cy + 60], [cx - 60, cy + 60]];
        for (const [x, y] of pts) { await page.mouse.click(x, y); await page.waitForTimeout(120); }
        await page.mouse.click(pts[0][0], pts[0][1]);
        await page.waitForSelector('#liveMeasure');
        const txt = await page.textContent('#liveMeasure');
        assert(/Superficie/.test(txt) && /m²/.test(txt), 'superficie affichée : ' + txt);
        await page.selectOption('#pfCat', 'commercial');
        await page.fill('#pfLoc', 'Test E2E');
        await page.click('#edSave');
        await page.waitForFunction(() => document.querySelector('#panelTitle').textContent.startsWith('Parcelle '), null, { timeout: 10000 });
        createdRef = (await page.textContent('#panelTitle')).replace('Parcelle ', '').trim();
        assert(/^NIG-/.test(createdRef), 'référence générée : ' + createdRef);
        await page.screenshot({ path: path.join(OUT, 'carte-creation.png') });
    });

    await step('Rechargement de la parcelle depuis MySQL (nouvelle page)', async () => {
        await page.goto(BASE + 'parcelles.php?q=' + encodeURIComponent(createdRef));
        const link = page.locator('a.ref', { hasText: createdRef });
        assert(await link.count() === 1, 'parcelle trouvée par sa référence');
        const href = await link.getAttribute('href');
        createdId = parseInt(new URL(href, BASE).searchParams.get('id'), 10);
        await page.goto(BASE + 'carte.php?parcel=' + createdId);
        await page.waitForFunction((ref) => document.querySelector('#panelTitle').textContent.includes(ref), createdRef);
        assert((await page.textContent('#panelBody')).includes('Commercial'), 'catégorie enregistrée');
    });

    await step('Modification des limites (nouvelle version)', async () => {
        await page.goto(BASE + 'carte.php?edit=' + createdId);
        await page.waitForSelector('#pfReason');
        await page.waitForTimeout(600);
        const marker = page.locator('.marker-icon').first();
        const mb = await marker.boundingBox();
        await page.mouse.move(mb.x + mb.width / 2, mb.y + mb.height / 2);
        await page.mouse.down();
        await page.mouse.move(mb.x - 30, mb.y - 30, { steps: 5 });
        await page.mouse.up();
        await page.fill('#pfReason', 'Correction test E2E');
        await page.click('#edSave');
        await page.waitForFunction(() => document.querySelector('#panelTitle').textContent.startsWith('Parcelle '), null, { timeout: 10000 });
        await page.goto(BASE + 'parcelle_details.php?id=' + createdId + '&tab=historique');
        const body = await page.textContent('body');
        assert(body.includes('Version 2') && body.includes('Correction test E2E'), 'version 2 dans l\'historique');
    });

    await step('Outil de mesure de distance', async () => {
        await page.goto(BASE + 'carte.php');
        await page.waitForFunction(() => window.NPMap);
        await page.evaluate(([la, ln]) => window.NPMap.map.setView([la, ln], 17, { animate: false }), [lat0, lng0]);
        await page.click('#btnMeasureLine');
        const box = await page.locator('#map').boundingBox();
        await page.mouse.click(box.x + 200, box.y + 300);
        await page.mouse.dblclick(box.x + 400, box.y + 300);
        await page.waitForSelector('.measure-tooltip');
        assert(/m/.test(await page.textContent('.measure-tooltip')), 'distance affichée');
    });

    await step('Recherche avec autocomplétion', async () => {
        await page.fill('#npGlobalSearch', createdRef.slice(0, 12));
        await page.waitForSelector('#npGlobalSearchResults a', { timeout: 5000 });
        assert((await page.textContent('#npGlobalSearchResults')).includes('NIG-'), 'suggestions');
    });

    await step('Fiche détaillée et validation de formulaire', async () => {
        await page.goto(BASE + 'parcelle_details.php?id=' + createdId);
        assert((await page.textContent('h1')).includes(createdRef));
        assert(await page.isVisible('#miniMap'), 'mini-carte');
        // Validation serveur : personne physique sans nom de famille refusée
        await page.goto(BASE + 'proprietaires.php?new=1');
        await page.fill('#first_name', 'Prénom seul');
        await Promise.all([page.waitForNavigation(), page.click('form[data-autosave] button.btn-primary')]);
        assert((await page.textContent('body')).includes('Le nom de famille est obligatoire'), 'message de validation explicite');
    });

    await step('Affichage téléphone (375 px) : menu repliable et carte utilisable', async () => {
        const m = await browser.newPage({ viewport: { width: 375, height: 740 }, isMobile: true, hasTouch: true });
        await m.context().addCookies(await page.context().cookies());
        await m.goto(BASE + 'carte.php?parcel=' + createdId);
        await m.waitForFunction(() => window.NPMap);
        assert(!(await m.isVisible('#npSidebar .np-nav')), 'menu latéral replié');
        const scrollW = await m.evaluate(() => document.documentElement.scrollWidth);
        assert(scrollW <= 380, 'pas de défilement horizontal : ' + scrollW);
        await m.screenshot({ path: path.join(OUT, 'mobile-carte.png') });
        await m.goto(BASE + 'dashboard.php');
        await m.screenshot({ path: path.join(OUT, 'mobile-tableau-de-bord.png'), fullPage: true });
        await m.close();
    });

    await step('Nettoyage (archivage de la parcelle de test)', async () => {
        await page.goto(BASE + 'parcelle_details.php?id=' + createdId);
        await page.fill('input[name=reason]', 'Test E2E');
        await page.click('button:has-text("Archiver")');
        await page.waitForLoadState();
    });

    await step('Aucune erreur JavaScript', async () => {
        assert(jsErrors.length === 0, jsErrors.join(' | '));
    });

    await browser.close();
    console.log(`\n${passed} test(s) réussi(s), ${failed} échec(s). Captures : ${OUT}`);
    process.exit(failed ? 1 : 0);
})();
