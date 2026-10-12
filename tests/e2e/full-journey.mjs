/**
 * Parcours complet dans de vrais navigateurs (Playwright) : un client et un chauffeur, chacun
 * dans son propre navigateur, avec géolocalisation réelle du navigateur (positions injectées
 * via l'API de géolocalisation de Chromium, exactement comme un téléphone).
 *
 *  1. le chauffeur se connecte et passe « En ligne » (envoi GPS) ;
 *  2. le client choisit la destination sur la carte, voit les prix et commande ;
 *  3. le chauffeur reçoit la demande et l'accepte ;
 *  4. le chauffeur se déplace : le client voit l'icône du véhicule bouger sur sa carte ;
 *  5. arrivée, prise en charge, fin de course, déclaration des espèces ;
 *  6. le client voit « Paiement confirmé » et note le chauffeur ;
 *  7. l'administrateur retrouve la commande, la commission et le grand livre équilibré.
 *
 * Prérequis : base fraîche (php bin/migrate.php --fresh && php bin/seed.php) et serveur démarré.
 *   BASE_URL=http://127.0.0.1:8080 node tests/e2e/full-journey.mjs
 */
import { chromium } from 'playwright';

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8080';
const SHOTS = (process.argv.find((a) => a.startsWith('--shots=')) || '').split('=')[1];
const PASS = 'Demo2026!';
const log = (m) => console.log(`[${new Date().toISOString().slice(11, 19)}] ${m}`);
const shot = async (page, name) => { if (SHOTS) await page.screenshot({ path: `${SHOTS}/journey-${name}.png` }); };

const browser = await chromium.launch();
const newCtx = (lat, lng, mobile) => browser.newContext({ locale: 'fr-FR', timezoneId: 'Africa/Niamey', permissions: ['geolocation'], geolocation: { latitude: lat, longitude: lng, accuracy: 15 },
  viewport: mobile ? { width: 412, height: 860 } : { width: 1280, height: 860 }, isMobile: !!mobile, hasTouch: !!mobile });
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
  // 1. Chauffeur en ligne (téléphone Android simulé)
  const driverCtx = await newCtx(13.5150, 2.1100, true);
  const driver = await driverCtx.newPage(); watch(driver, 'chauffeur');
  await login(driver, 'chauffeur@marwa.demo');
  await driver.waitForSelector('[data-online]', { state: 'attached' });
  if (!(await driver.isChecked('[data-online]'))) await driver.click('label.switch');
  await driver.waitForSelector('[data-toggle-label]:has-text("En ligne")', { timeout: 15000 });
  log('Chauffeur en ligne');
  await shot(driver, '1-chauffeur-en-ligne');

  // 2. Le client commande un taxi en choisissant la destination sur la carte
  const clientCtx = await newCtx(13.5137, 2.1098);
  const client = await clientCtx.newPage(); watch(client, 'client');
  await login(client, 'client@marwa.demo');
  await client.goto(BASE + '/client#/taxi', { waitUntil: 'domcontentloaded' });
  await client.waitForFunction(() => document.querySelector('[data-pickup] input')?.value, null, { timeout: 15000 });
  await client.click('[data-dropoff] [data-mappick]');
  const box = await client.locator('#taxi-map').boundingBox();
  await client.mouse.click(box.x + box.width / 2 + 160, box.y + box.height / 2 + 140);
  await client.waitForSelector('.vehicle-option', { timeout: 15000 });
  const price = await client.locator('.vehicle-option.active .price').textContent();
  log('Estimation affichée : ' + price.trim());
  await shot(client, '2-client-estimation');
  await client.click('[data-form] button[type=submit]');
  await client.waitForURL(/#\/commande\/\d+/, { timeout: 15000 });
  const orderId = client.url().match(/commande\/(\d+)/)[1];
  log('Commande créée #' + orderId);

  // 3. Le chauffeur reçoit et accepte la demande
  await driver.waitForSelector('[data-acc]', { timeout: 30000 });
  await shot(driver, '3-chauffeur-demande');
  await driver.click('[data-acc]');
  await driver.waitForURL(/#\/course/, { timeout: 15000 });
  await driver.waitForSelector('[data-act="en_route_pickup"]', { timeout: 15000 });
  log('Demande acceptée par le chauffeur');
  await driver.click('[data-act="en_route_pickup"]');
  await driver.waitForSelector('[data-act="arrived_pickup"]');

  // 4. Déplacement réel signalé par le GPS du navigateur du chauffeur
  for (const [lat, lng] of [[13.5146, 2.1099], [13.5142, 2.1098], [13.5139, 2.1098]]) {
    await driverCtx.setGeolocation({ latitude: lat, longitude: lng, accuracy: 10 });
    await driver.waitForTimeout(5500);
  }
  await client.waitForSelector('.mw-vehicle', { timeout: 20000 });
  const badge = await client.locator('[data-mapbadge]').textContent();
  log('Le client voit le véhicule sur sa carte : « ' + badge.trim() + ' »');
  await shot(client, '4-client-suivi');

  // 5. Arrivée, prise en charge, fin de course, espèces
  await driver.click('[data-act="arrived_pickup"]');
  await driver.waitForSelector('[data-act="in_progress"]');
  await driver.click('[data-act="in_progress"]');
  await driver.waitForSelector('[data-act="completed"]');
  await shot(driver, '5-chauffeur-course');
  await driver.click('[data-act="completed"]');
  await driver.waitForSelector('.modal [name="amount_collected"]');
  await driver.click('.modal button[type=submit]');
  await driver.waitForURL((u) => !u.hash.includes('course'), { timeout: 15000 });
  log('Course terminée et espèces déclarées');

  // 6. Le client voit le paiement confirmé et note le chauffeur
  await client.waitForSelector('[data-rating]', { timeout: 20000 });
  await client.click('[data-stars="provider"] [data-v="5"]');
  await client.click('[data-stars="service"] [data-v="4"]');
  await client.click('[data-sendrating]');
  await client.waitForSelector('text=Merci pour votre avis', { timeout: 10000 });
  const status = await client.locator('.status-hero h2').textContent();
  log('Statut final côté client : ' + status.trim());
  await shot(client, '6-client-fin');
  if (!/Paiement confirmé/.test(status)) throw new Error('Statut final inattendu : ' + status);

  // 7. Contrôle administrateur
  const adminCtx = await newCtx(13.51, 2.11);
  const admin = await adminCtx.newPage(); watch(admin, 'admin');
  await login(admin, 'admin@marwa.demo');
  await admin.goto(BASE + '/admin#/commande/' + orderId, { waitUntil: 'domcontentloaded' });
  await admin.waitForSelector('text=Répartition financière');
  const commission = await admin.locator('.table-wrap').first().textContent();
  log('Répartition vue par l\'administration : ' + commission.replace(/\s+/g, ' ').slice(0, 140));
  await admin.goto(BASE + '/admin#/grand-livre', { waitUntil: 'domcontentloaded' });
  await admin.waitForSelector('.alert');
  const balance = await admin.locator('.alert').first().textContent();
  log(balance.trim().slice(0, 110));
  await shot(admin, '7-admin-grand-livre');
  if (!/équilibrée/.test(balance)) throw new Error('Grand livre non équilibré');

  if (errors.length) throw new Error('Erreurs JavaScript :\n' + errors.join('\n'));
  log('PARCOURS COMPLET RÉUSSI');
  await browser.close();
  process.exit(0);
} catch (e) {
  console.error('ÉCHEC : ' + e.message + (errors.length ? '\n' + errors.join('\n') : ''));
  await browser.close();
  process.exit(1);
}
