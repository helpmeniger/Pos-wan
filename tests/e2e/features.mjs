/**
 * Tests navigateur des fonctionnalités mobiles (Playwright, téléphones simulés) :
 *  - demande d'autorisation de localisation expliquée, puis accordée ;
 *  - taxis libres à proximité affichés avec des icônes de voiture ;
 *  - navigation intégrée du chauffeur (consignes, recalcul en cas d'écart, action « Je suis arrivé ») ;
 *  - itinéraire restant en temps réel côté client.
 *
 * Prérequis : base fraîche + données de démonstration ; pour des consignes routières,
 * OSRM_URL pointant vers un serveur OSRM (ou tests/e2e/fake-osrm.php hors ligne).
 *   BASE_URL=http://127.0.0.1:8080 node tests/e2e/features.mjs
 */
import { chromium } from 'playwright';

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8080';
const SHOTS = (process.argv.find((a) => a.startsWith('--shots=')) || '').split('=')[1];
const PASS = 'Demo2026!';
const log = (m) => console.log(`[${new Date().toISOString().slice(11, 19)}] ${m}`);
const shot = async (page, name) => { if (SHOTS) await page.screenshot({ path: `${SHOTS}/feat-${name}.png` }); };
const check = (cond, msg) => { if (!cond) throw new Error(msg); log('OK  ' + msg); };

const browser = await chromium.launch();
const phone = (opts = {}) => browser.newContext(Object.assign({ locale: 'fr-FR', timezoneId: 'Africa/Niamey', viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, deviceScaleFactor: 2 }, opts));
const errors = [];
const watch = (page, who) => {
  page.on('pageerror', (e) => errors.push(`${who}: ${e.message}`));
  page.on('console', (m) => { if (m.type() === 'error' && !/Failed to load resource|tile|net::/.test(m.text())) errors.push(`${who}: ${m.text()}`); });
};
const login = async (page, email) => {
  await page.goto(BASE + '/connexion', { waitUntil: 'domcontentloaded' });
  await page.fill('#identifier', email);
  await page.fill('#password', PASS);
  await Promise.all([page.waitForNavigation(), page.click('#login-form button[type=submit]')]);
};

try {
  // --- Chauffeur en ligne
  const driverCtx = await phone({ permissions: ['geolocation'], geolocation: { latitude: 13.5150, longitude: 2.1100, accuracy: 10 } });
  const driver = await driverCtx.newPage(); watch(driver, 'chauffeur');
  await login(driver, 'chauffeur@marwa.demo');
  await driver.waitForSelector('[data-online]', { state: 'attached' });
  if (!(await driver.isChecked('[data-online]'))) await driver.click('label.switch');
  await driver.waitForSelector('[data-toggle-label]:has-text("En ligne")', { timeout: 15000 });
  await driver.waitForTimeout(1500);
  log('Chauffeur en ligne');

  // --- Client SANS autorisation de localisation : l'application l'explique puis la demande
  const clientCtx = await phone();
  const client = await clientCtx.newPage(); watch(client, 'client');
  await login(client, 'client@marwa.demo');
  await client.goto(BASE + '/client#/taxi', { waitUntil: 'domcontentloaded' });
  await client.waitForSelector('.modal:has-text("Autoriser la localisation")', { timeout: 15000 });
  await shot(client, '1-autorisation');
  check(true, 'Fenêtre explicative « Autoriser la localisation » affichée avant la demande du téléphone');
  await clientCtx.grantPermissions(['geolocation']);
  await clientCtx.setGeolocation({ latitude: 13.5137, longitude: 2.1098, accuracy: 12 });
  await client.click('.modal [data-yes]');
  await client.waitForFunction(() => document.querySelector('[data-pickup] input')?.value, null, { timeout: 15000 });
  check(true, 'Position du client utilisée comme départ après autorisation');

  // --- Taxis à proximité
  await client.waitForSelector('.mw-vehicle.nearby', { timeout: 20000 });
  const nb = await client.locator('[data-nearby]').textContent();
  check(/taxi disponible/.test(nb), 'Taxis proches affichés sur la carte avec icône de voiture : « ' + nb.trim() + ' »');
  await shot(client, '2-taxis-proches');

  // --- Commande
  await client.click('[data-dropoff] [data-mappick]');
  await client.waitForTimeout(1200); // défilement jusqu'à la carte
  const box = await client.locator('#taxi-map').boundingBox();
  await client.mouse.click(box.x + box.width / 2 + 90, box.y + box.height / 2 + 110);
  await client.waitForSelector('.vehicle-option', { timeout: 15000 });
  await client.click('[data-form] button[type=submit]');
  await client.waitForURL(/#\/commande\/\d+/, { timeout: 15000 });
  log('Commande créée');

  await driver.waitForSelector('[data-acc]', { timeout: 30000 });
  await driver.click('[data-acc]');
  await driver.waitForURL(/#\/course/, { timeout: 15000 });
  await driver.waitForSelector('[data-act="en_route_pickup"]', { timeout: 15000 });
  await driver.click('[data-act="en_route_pickup"]');
  await driver.waitForSelector('[data-act="arrived_pickup"]');

  // --- Navigation intégrée
  await driver.click('[data-navigate]');
  await driver.waitForSelector('.nav-screen', { timeout: 10000 });
  await driver.waitForFunction(() => /Démarrez|Tournez|Continuez|Dirigez-vous/.test(document.querySelector('[data-instr]')?.textContent || ''), null, { timeout: 15000 });
  const instr = await driver.locator('[data-instr]').textContent();
  const approx = await driver.locator('[data-approx]').isVisible();
  check(true, 'Navigation intégrée ouverte : « ' + instr.trim() + ' » (' + (approx ? 'mode approximatif' : 'itinéraire routier') + ')');
  await shot(driver, '3-navigation');
  // Écart volontaire de la route : recalcul automatique
  let navCalls = 0;
  driver.on('request', (r) => { if (r.url().includes('/navigation?')) navCalls++; });
  if (!approx) {
    await driverCtx.setGeolocation({ latitude: 13.5150, longitude: 2.1130, accuracy: 8 });
    await driver.waitForTimeout(5500);
    await driverCtx.setGeolocation({ latitude: 13.5151, longitude: 2.1131, accuracy: 8 });
    await driver.waitForTimeout(6000);
    await driverCtx.setGeolocation({ latitude: 13.5149, longitude: 2.1132, accuracy: 8 });
    for (let i = 0; i < 20 && navCalls === 0; i++) await driver.waitForTimeout(500);
    await driver.waitForFunction(() => /Tournez|Démarrez|Continuez/.test(document.querySelector('[data-instr]')?.textContent || ''), null, { timeout: 20000 });
    check(navCalls >= 1, 'Itinéraire recalculé après un écart de route (' + navCalls + ' recalcul(s)) : « ' + (await driver.locator('[data-instr]').textContent()).trim() + ' »');
  }
  // Approche du client
  for (const [lat, lng] of [[13.5145, 2.1099], [13.5140, 2.1098], [13.5137, 2.1098]]) {
    await driverCtx.setGeolocation({ latitude: lat, longitude: lng, accuracy: 8 });
    await driver.waitForTimeout(5500);
  }
  await driver.waitForFunction(() => /arrivé/i.test(document.querySelector('[data-instr]')?.textContent || ''), null, { timeout: 15000 });
  check(true, 'Arrivée détectée par la navigation');

  // --- Côté client : véhicule et itinéraire restant en direct
  await client.waitForSelector('.mw-vehicle:not(.nearby)', { timeout: 20000 });
  await shot(client, '4-suivi-client');
  const live = await client.locator('#track-map').getAttribute('data-live-route');
  check(!!live, 'Le client voit le véhicule et l\'itinéraire restant en temps réel (' + (live === 'road' ? 'itinéraire routier' : 'ligne directe') + ')');
  await shot(client, '4-suivi-client');

  await driver.click('.nav-screen [data-main]');
  await driver.waitForSelector('[data-act="in_progress"]', { timeout: 15000 });
  check(true, 'Bouton « Je suis arrivé » utilisable depuis l\'écran de navigation');

  // --- Catalogue : le restaurant ajoute un produit avec plusieurs photos (téléphone)
  const IMG = process.env.TEST_IMAGES || '/tmp/testimg';
  const merchCtx = await phone();
  const merch = await merchCtx.newPage(); watch(merch, 'restaurant');
  await login(merch, 'restaurant@marwa.demo');
  await merch.goto(BASE + '/commerce#/catalogue', { waitUntil: 'domcontentloaded' });
  await merch.click('[data-prod]');
  await merch.fill('.modal [name="name"]', 'Brochettes test photo');
  await merch.fill('.modal [name="price"]', '2500');
  await merch.setInputFiles('.modal [data-gallery]', [IMG + '/plat1.jpg', IMG + '/plat2.jpg']);
  await merch.waitForSelector('.modal .photo-tile.pending >> nth=1');
  await shot(merch, '5-produit-photos');
  await merch.click('.modal button[type=submit]');
  await merch.waitForSelector('.product-card:has-text("Brochettes test photo") .thumb-count', { timeout: 20000 });
  check(true, 'Produit créé avec 2 photos (compressées sur le téléphone puis envoyées)');
  // Changer la photo principale puis ajouter une photo de couverture
  await merch.click('.product-card:has-text("Brochettes test photo") [data-edit] >> nth=0');
  await merch.waitForSelector('.modal .photo-tile [data-main]');
  await merch.click('.modal .photo-tile [data-main]');
  await merch.waitForResponse((r) => r.url().includes('/main') && r.status() === 200);
  check(true, 'Photo principale modifiée');
  await merch.click('.modal [data-close]');
  await merch.goto(BASE + '/commerce#/parametres', { waitUntil: 'domcontentloaded' });
  await merch.waitForSelector('[data-cover] [data-gallery]', { state: 'attached' });
  await merch.setInputFiles('[data-cover] [data-gallery]', IMG + '/couverture.jpg');
  await merch.waitForSelector('[data-cover] .single-image img', { timeout: 15000 });
  check(true, 'Photo de couverture du restaurant enregistrée');

  // --- Le client voit les photos
  await client.goto(BASE + '/client#/repas', { waitUntil: 'domcontentloaded' });
  await client.click('.merchant-card >> nth=0');
  await client.waitForSelector('.store-cover', { timeout: 15000 });
  const card = client.locator('.product-card:has-text("Brochettes test photo")');
  await card.locator('.thumb').click();
  await client.waitForSelector('.modal .gallery img >> nth=1');
  const n = await client.locator('.modal .gallery img').count();
  const ok = await client.evaluate(() => [...document.querySelectorAll('.modal .gallery img')].every((i) => i.complete && i.naturalWidth > 0));
  check(n === 2 && ok, `Le client voit la vitrine et la galerie du produit (${n} photos chargées)`);
  await shot(client, '6-galerie-client');
  const w = await client.evaluate(() => document.querySelector('.modal .gallery img').naturalWidth);
  check(w <= 1600, `Photo redimensionnée (${w} px de large au lieu de 3000)`);
  await client.click('.modal [data-sheetadd]');
  await client.waitForSelector('[data-cartbar]:not([hidden])');
  check(true, 'Ajout au panier depuis la fiche produit');

  if (errors.length) throw new Error('Erreurs JavaScript :\n' + errors.join('\n'));
  log('TESTS DES FONCTIONNALITÉS RÉUSSIS');
  await browser.close();
  process.exit(0);
} catch (e) {
  if (SHOTS) for (const ctx of browser.contexts()) for (const [i, pg] of ctx.pages().entries()) await pg.screenshot({ path: `${SHOTS}/echec-${browser.contexts().indexOf(ctx)}-${i}.png` }).catch(() => {});
  console.error('ÉCHEC : ' + e.message + (errors.length ? '\n' + errors.join('\n') : ''));
  await browser.close();
  process.exit(1);
}
