/**
 * Tests navigateur (téléphones simulés) de :
 *  - la connexion d'un nouveau client par code SMS, sans mot de passe, et « se souvenir de moi » ;
 *  - l'affichage des adresses favorites à la sélection d'une adresse ;
 *  - un chauffeur qui se connecte et voit la course restée sans prise en charge ;
 *  - l'écran d'administration « Suivi en direct » et la notification d'un prestataire ;
 *  - la photo d'une promotion, visible par le client ;
 *  - l'effacement d'une notification en la glissant vers la droite.
 * Prérequis : base fraîche + données de démonstration.
 *   BASE_URL=http://127.0.0.1:8080 node tests/e2e/live-ops.mjs
 */
import { chromium } from 'playwright';

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8080';
const SHOTS = (process.argv.find((a) => a.startsWith('--shots=')) || '').split('=')[1];
const IMG = process.env.TEST_IMAGES || '/tmp/testimg';
const log = (m) => console.log(`[${new Date().toISOString().slice(11, 19)}] ${m}`);
const shot = async (page, name) => { if (SHOTS) await page.screenshot({ path: `${SHOTS}/live-${name}.png` }); };
const check = (cond, msg) => { if (!cond) throw new Error(msg); log('OK  ' + msg); };
const browser = await chromium.launch();
const phone = (opts = {}) => browser.newContext(Object.assign({ locale: 'fr-FR', timezoneId: 'Africa/Niamey', viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true }, opts));
const errors = [];
const watch = (page, who) => {
  page.on('pageerror', (e) => errors.push(`${who}: ${e.message}`));
  page.on('console', (m) => { if (m.type() === 'error' && !/Failed to load resource|tile|net::/.test(m.text())) errors.push(`${who}: ${m.text()}`); });
};
const loginPwd = async (page, email) => {
  await page.goto(BASE + '/connexion', { waitUntil: 'domcontentloaded' });
  if (await page.locator('[data-tab="password"]').count()) await page.click('[data-tab="password"]');
  await page.fill('#identifier', email); await page.fill('#password', 'Demo2026!');
  await Promise.all([page.waitForNavigation(), page.click('#login-form button[type=submit]')]);
};

try {
  // --- 1. Nouveau client : numéro de téléphone + code SMS, sans mot de passe, « se souvenir de moi »
  const geo = { permissions: ['geolocation'], geolocation: { latitude: 13.5137, longitude: 2.1098, accuracy: 12 } };
  const c1 = await phone(geo);
  const p1 = await c1.newPage(); watch(p1, 'nouveau client');
  await p1.goto(BASE + '/connexion', { waitUntil: 'domcontentloaded' });
  check(await p1.isVisible('#phone-form'), 'Onglet « Client : code par SMS » affiché par défaut');
  const num = '9' + String(Math.floor(1000000 + Math.random() * 8999999));
  await p1.fill('#phone', num);
  await p1.click('#phone-form button[type=submit]');
  await p1.waitForSelector('#code-form');
  const code = (await p1.locator('#code-form .alert').textContent()).match(/(\d{6})/)[1];
  await shot(p1, '1-code-sms');
  await p1.fill('#otp', code);
  await p1.click('#code-form button[type=submit]');
  await p1.waitForSelector('#fn');
  await p1.fill('#fn', 'Mariama Test');
  await p1.check('[name=accept_terms]');
  await Promise.all([p1.waitForNavigation(), p1.click('form:has(#fn) button[type=submit]')]);
  await p1.waitForSelector('text=Bonjour Mariama');
  check(true, 'Compte client créé et connecté avec le seul numéro vérifié (aucun mot de passe)');
  const cookies = await c1.cookies();
  const remember = cookies.find((c) => c.name === 'mw_remember');
  check(remember && remember.expires > Date.now() / 1000 + 20 * 86400 && remember.httpOnly, '« Se souvenir de moi » : cookie sécurisé valable 30 jours');
  // Nouveau navigateur avec le seul cookie « se souvenir de moi » (session fermée)
  const c1b = await phone(geo);
  await c1b.addCookies([remember]);
  const p1b = await c1b.newPage(); watch(p1b, 'client revenu');
  await p1b.goto(BASE + '/client#/', { waitUntil: 'domcontentloaded' });
  await p1b.waitForSelector('text=Bonjour Mariama', { timeout: 10000 });
  check(true, 'Retour sur l\'application sans se reconnecter (session ouverte par « se souvenir de moi »)');
  check(!(await p1b.locator('#view .card:has(h2:text("Adresses favorites"))').count()), 'Aucun bloc « Adresses favorites » quand la liste est vide');

  // --- 2. Adresses favorites proposées à la sélection d'une adresse (client de démonstration)
  const c2 = await phone(geo);
  const p2 = await c2.newPage(); watch(p2, 'client démo');
  await loginPwd(p2, 'client@marwa.demo');
  await p2.goto(BASE + '/client#/taxi', { waitUntil: 'domcontentloaded' });
  await p2.waitForFunction(() => document.querySelector('[data-pickup] input')?.value, null, { timeout: 15000 });
  check(!(await p2.locator('[data-dropoff] .chips').count()), 'Favoris non affichés en permanence sous les champs');
  await p2.click('[data-dropoff] input');
  await p2.waitForSelector('[data-dropoff] .suggestions:not([hidden]) [data-favi]');
  await shot(p2, '2-favoris');
  await p2.click('[data-dropoff] [data-favi] >> nth=0');
  await p2.waitForSelector('.vehicle-option', { timeout: 15000 });
  check(true, 'Adresse favorite proposée à la sélection de la destination, puis utilisée');

  // --- 3. Course sans chauffeur en ligne, puis un chauffeur se connecte et la voit
  await p2.click('[data-form] button[type=submit]');
  await p2.waitForURL(/#\/commande\/\d+/, { timeout: 15000 });
  const orderId = p2.url().match(/commande\/(\d+)/)[1];
  log('Course créée #' + orderId + ' (aucun chauffeur en ligne)');
  const cd = await phone({ permissions: ['geolocation'], geolocation: { latitude: 13.5150, longitude: 2.1100, accuracy: 8 } });
  const pd = await cd.newPage(); watch(pd, 'chauffeur');
  await loginPwd(pd, 'chauffeur@marwa.demo');
  await pd.waitForSelector('[data-online]', { state: 'attached' });
  if (!(await pd.isChecked('[data-online]'))) await pd.click('label.switch');
  await pd.waitForSelector('[data-acc]', { timeout: 30000 });
  await shot(pd, '3-chauffeur-demande-en-attente');
  check(true, 'Le chauffeur qui vient de se connecter voit la course restée sans prise en charge');

  // --- 4. Administration : suivi en direct et notification d'un prestataire
  const ca = await browser.newContext({ locale: 'fr-FR', viewport: { width: 1280, height: 900 } });
  const pa = await ca.newPage(); watch(pa, 'admin');
  await loginPwd(pa, 'admin@marwa.demo');
  await pa.goto(BASE + '/admin#/direct', { waitUntil: 'domcontentloaded' });
  await pa.waitForSelector(`.live-order[data-o="${orderId}"]`, { timeout: 15000 });
  const stats = (await pa.locator('[data-stats]').textContent()).replace(/\s+/g, ' ');
  check(/En attente/.test(stats), 'Suivi en direct : ' + stats.trim().slice(0, 90));
  await pa.click(`.live-order[data-o="${orderId}"] [data-near]`);
  await pa.waitForSelector('.modal [data-c]');
  await shot(pa, '4-prestataires-proches');
  await pa.click('.modal .list-item:has-text("Moussa") [data-c]');
  await pa.waitForSelector('.modal [name=message]');
  check(await pa.locator('.modal a[href^="https://wa.me/"]').count() === 1 && await pa.locator('.modal a[href^="tel:"]').count() >= 1, 'Liens Appeler / WhatsApp / SMS proposés');
  await pa.click('.modal button[type=submit]');
  await pa.waitForSelector('text=Message envoyé', { timeout: 10000 });
  await pd.click('[data-notif-btn]');
  await pd.waitForSelector('.dropdown .swipe-row:has-text("Message de l\'équipe MARWA")', { timeout: 15000 });
  await shot(pd, '4b-message-recu');
  await pd.keyboard.press('Escape'); await pd.mouse.click(5, 300);
  check(true, 'Le prestataire reçoit le message de l\'équipe MARWA dans ses notifications');

  // --- 5. Photo de promotion visible par le client
  await pa.goto(BASE + '/admin#/promotions', { waitUntil: 'domcontentloaded' });
  await pa.waitForSelector('.table tbody tr');
  await pa.click('.table tbody tr >> nth=0');
  await pa.waitForSelector('.modal [data-img] [data-gallery]', { state: 'attached' });
  await pa.setInputFiles('.modal [data-img] [data-gallery]', IMG + '/couverture.jpg');
  await pa.waitForSelector('.modal [data-img] .single-image img', { timeout: 15000 });
  await pa.click('.modal [data-close]');
  await p2.goto(BASE + '/client#/', { waitUntil: 'domcontentloaded' });
  await p2.waitForSelector('.promo-card.has-img img', { timeout: 15000 });
  const ok = await p2.evaluate(() => { const i = document.querySelector('.promo-card img'); return i.complete && i.naturalWidth > 0; });
  check(ok, 'Promotion avec photo affichée sur l\'accueil du client');
  await shot(p2, '5-promotion-photo');

  // --- 6. Glisser une notification vers la droite pour l'effacer
  await pd.goto(BASE + '/prestataire#/', { waitUntil: 'domcontentloaded' });
  await pd.click('[data-notif-btn]');
  await pd.waitForSelector('.dropdown .swipe-row');
  const before = await pd.locator('.dropdown .swipe-row').count();
  const item = pd.locator('.dropdown .swipe-item').first();
  const b = await item.boundingBox();
  await pd.mouse.move(b.x + 30, b.y + b.height / 2);
  await pd.mouse.down();
  for (let x = 30; x <= b.width * 0.8; x += 25) await pd.mouse.move(b.x + x, b.y + b.height / 2);
  await pd.mouse.up();
  await pd.waitForFunction((n) => document.querySelectorAll('.dropdown .swipe-row').length === n - 1, before, { timeout: 5000 });
  const after = await (await pd.request.get(BASE + '/api/notifications')).json();
  check(after.data.items.length === before - 1, `Notification effacée en la glissant vers la droite (${before} → ${before - 1})`);

  if (errors.length) throw new Error('Erreurs JavaScript :\n' + errors.join('\n'));
  log('TESTS DES NOUVEAUTÉS RÉUSSIS');
  await browser.close();
  process.exit(0);
} catch (e) {
  if (SHOTS) for (const ctx of browser.contexts()) for (const [i, pg] of ctx.pages().entries()) await pg.screenshot({ path: `${SHOTS}/live-echec-${browser.contexts().indexOf(ctx)}-${i}.png` }).catch(() => {});
  console.error('ÉCHEC : ' + e.message + (errors.length ? '\n' + errors.join('\n') : ''));
  await browser.close();
  process.exit(1);
}
