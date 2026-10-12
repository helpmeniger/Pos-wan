/**
 * Test de fumée de l'interface (navigateur réel, Playwright + Chromium).
 * Parcourt chaque espace avec un compte de démonstration et échoue si une erreur JavaScript survient.
 *   BASE_URL=http://127.0.0.1:8080 node tests/e2e/ui-smoke.mjs [--shots=dossier]
 * Prérequis : base migrée + php bin/seed.php, serveur démarré (php -S … bin/dev-router.php).
 */
import { chromium } from 'playwright';

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8080';
const shotsArg = process.argv.find((a) => a.startsWith('--shots='));
const SHOTS = shotsArg ? shotsArg.split('=')[1] : null;
const PASS = 'Demo2026!';
const plan = [
  { user: null, pages: ['/', '/connexion', '/inscription', '/inscription?profil=merchant', '/mot-de-passe-oublie', '/pages/cgu'] },
  { user: 'client@marwa.demo', pages: ['/client#/', '/client#/taxi', '/client#/colis', '/client#/repas', '/client#/repas/1', '/client#/historique', '/client#/adresses', '/client#/profil', '/client#/assistance'] },
  { user: 'chauffeur@marwa.demo', pages: ['/prestataire#/', '/prestataire#/course', '/prestataire#/revenus', '/prestataire#/historique', '/prestataire#/documents'] },
  { user: 'restaurant@marwa.demo', pages: ['/commerce#/', '/commerce#/commandes', '/commerce#/catalogue', '/commerce#/finances', '/commerce#/rapports', '/commerce#/livreurs', '/commerce#/parametres'] },
  { user: 'entreprise@marwa.demo', pages: ['/entreprise#/', '/entreprise#/collaborateurs', '/entreprise#/commandes', '/entreprise#/releves', '/entreprise#/adresses'] },
  { user: 'admin@marwa.demo', pages: ['/admin#/', '/admin#/carte', '/admin#/commandes', '/admin#/utilisateurs', '/admin#/chauffeurs', '/admin#/livreurs', '/admin#/commerces', '/admin#/entreprises',
    '/admin#/paiements', '/admin#/versements', '/admin#/remboursements', '/admin#/grand-livre', '/admin#/depenses', '/admin#/rapports', '/admin#/rapports?type=commissions', '/admin#/tarifs', '/admin#/commissions',
    '/admin#/zones', '/admin#/promotions', '/admin#/reclamations', '/admin#/notifications', '/admin#/parametres', '/admin#/audit'] },
];

const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined });
let failures = 0;
for (const step of plan) {
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 860 }, locale: 'fr-FR', timezoneId: 'Africa/Niamey', geolocation: { latitude: 13.5137, longitude: 2.1098 }, permissions: ['geolocation'] });
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
  page.on('console', (m) => { if (m.type() === 'error' && !/Failed to load resource|tile\.openstreetmap|ERR_|net::/.test(m.text())) errors.push('console: ' + m.text()); });
  if (step.user) {
    await page.goto(BASE + '/connexion', { waitUntil: 'domcontentloaded' });
    await page.fill('#identifier', step.user);
    await page.fill('#password', PASS);
    await Promise.all([page.waitForNavigation(), page.click('#login-form button[type=submit]')]);
  }
  for (const p of step.pages) {
    errors.length = 0;
    const resp = await page.goto(BASE + p, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1300);
    if (!resp || resp.status() >= 400) errors.push('HTTP ' + (resp ? resp.status() : 'aucune réponse'));
    if (!(await page.locator('header.site-header').count())) errors.push('Mise en page absente (erreur serveur ?) : ' + (await page.textContent('body')).slice(0, 200));
    const alert = await page.locator('#view .alert-error').first().textContent().catch(() => null);
    if (alert) errors.push('UI error: ' + alert.trim());
    const ok = errors.length === 0;
    if (!ok) failures++;
    console.log(`${ok ? 'OK  ' : 'FAIL'} ${(step.user || 'public').padEnd(24)} ${p}${ok ? '' : '\n      ' + errors.join('\n      ')}`);
    if (SHOTS) await page.screenshot({ path: `${SHOTS}/${(step.user || 'public').split('@')[0]}-${p.replace(/[^a-z0-9]+/gi, '_')}.png`, fullPage: false });
  }
  await ctx.close();
}
await browser.close();
console.log(failures ? `\n${failures} page(s) en erreur.` : '\nToutes les pages se chargent sans erreur JavaScript.');
process.exit(failures ? 1 : 0);
