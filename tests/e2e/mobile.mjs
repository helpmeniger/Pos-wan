/**
 * Affichage sur smartphone : chaque écran est ouvert sur un petit Android (360 × 740) et un iPhone
 * (390 × 844). Échec si la page déborde horizontalement, si un bouton est trop petit pour le doigt
 * (< 40 px), si un champ de saisie a une police < 16 px (zoom automatique sur iPhone) ou en cas
 * d'erreur JavaScript.
 *   BASE_URL=http://127.0.0.1:8080 node tests/e2e/mobile.mjs [--shots=dossier]
 */
import { chromium } from 'playwright';

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8080';
const SHOTS = (process.argv.find((a) => a.startsWith('--shots=')) || '').split('=')[1];
const plan = [
  { user: null, pages: ['/', '/connexion', '/inscription', '/inscription?profil=merchant', '/mot-de-passe-oublie'] },
  { user: 'client@marwa.demo', pages: ['/client#/', '/client#/taxi', '/client#/colis', '/client#/repas', '/client#/repas/1', '/client#/historique', '/client#/adresses', '/client#/profil', '/client#/assistance', '/client#/commande/1'] },
  { user: 'chauffeur@marwa.demo', pages: ['/prestataire#/', '/prestataire#/course', '/prestataire#/revenus', '/prestataire#/historique', '/prestataire#/documents', '/prestataire#/profil'] },
  { user: 'restaurant@marwa.demo', pages: ['/commerce#/', '/commerce#/commandes', '/commerce#/catalogue', '/commerce#/finances', '/commerce#/rapports', '/commerce#/parametres'] },
  { user: 'entreprise@marwa.demo', pages: ['/entreprise#/', '/entreprise#/collaborateurs', '/entreprise#/commandes', '/entreprise#/releves'] },
  { user: 'admin@marwa.demo', pages: ['/admin#/', '/admin#/direct', '/admin#/commandes', '/admin#/utilisateurs', '/admin#/paiements', '/admin#/parametres', '/admin#/traductions'] },
];
const devices = [{ name: 'android-360', viewport: { width: 360, height: 740 }, deviceScaleFactor: 3 }, { name: 'iphone-390', viewport: { width: 390, height: 844 }, deviceScaleFactor: 3 }];
const inspect = () => {
  const vw = document.documentElement.clientWidth;
  const issues = [];
  if (document.documentElement.scrollWidth > vw + 1) {
    const wide = [...document.querySelectorAll('body *')].filter((e) => { const r = e.getBoundingClientRect(); return r.right > vw + 1 && r.width > 0 && !e.closest('.table-wrap, .leaflet-container, .gallery, .segmented, .chips, .tabs, .modal, pre'); })
      .slice(0, 3).map((e) => `${e.tagName.toLowerCase()}.${[...e.classList].join('.')} (${Math.round(e.getBoundingClientRect().right)}px)`);
    issues.push(`débordement horizontal (${document.documentElement.scrollWidth}px > ${vw}px) ${wide.join(', ')}`);
  }
  const small = [...document.querySelectorAll('button, a.btn, .icon-btn, [role=button], input[type=checkbox], input[type=radio]')].filter((e) => {
    const r = e.getBoundingClientRect(); if (!r.width || !e.offsetParent || e.closest('.leaflet-container, .table-wrap, .rating-stars')) return false;
    const target = e.matches('input') ? (e.closest('label') || e).getBoundingClientRect() : r; return target.height < 40 && target.width < 40 || target.height < 32;
  }).slice(0, 3).map((e) => `${e.tagName.toLowerCase()}.${[...e.classList].join('.')} « ${(e.textContent || e.getAttribute('aria-label') || '').trim().slice(0, 20)} » ${Math.round(e.getBoundingClientRect().width)}×${Math.round(e.getBoundingClientRect().height)}`);
  if (small.length) issues.push('cibles tactiles trop petites : ' + small.join(' ; '));
  const zoomy = [...document.querySelectorAll('input:not([type=checkbox]):not([type=radio]):not([type=file]), select, textarea')].filter((e) => e.offsetParent && parseFloat(getComputedStyle(e).fontSize) < 16).slice(0, 2)
    .map((e) => `${e.tagName.toLowerCase()}[name=${e.name}] ${getComputedStyle(e).fontSize}`);
  if (zoomy.length) issues.push('police < 16 px (zoom iPhone) : ' + zoomy.join(', '));
  return issues;
};
const browser = await chromium.launch();
let failures = 0;
for (const step of plan) {
  // Une connexion par compte ; les deux tailles d'écran sont testées dans la même session
  const ctx = await browser.newContext({ viewport: devices[0].viewport, deviceScaleFactor: 3, isMobile: true, hasTouch: true, locale: 'fr-FR', geolocation: { latitude: 13.5137, longitude: 2.1098 }, permissions: ['geolocation'] });
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(e.message));
  if (step.user) {
    await page.goto(BASE + '/connexion');
    if (await page.locator('[data-tab="password"]').count()) await page.click('[data-tab="password"]');
    await page.fill('#identifier', step.user); await page.fill('#password', 'Demo2026!');
    await Promise.all([page.waitForNavigation(), page.click('#login-form button[type=submit]')]);
  }
  for (const dev of devices) {
    await page.setViewportSize(dev.viewport);
    for (const p of step.pages) {
      errors.length = 0;
      await page.goto(BASE + p, { waitUntil: 'domcontentloaded' });
      await page.reload({ waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(1200);
      await page.keyboard.press('Escape').catch(() => {});
      const issues = [...(await page.evaluate(inspect)), ...errors.map((e) => 'JS : ' + e)];
      if (issues.length) failures++;
      console.log(`${issues.length ? 'FAIL' : 'OK  '} ${dev.name} ${(step.user || 'public').split('@')[0].padEnd(11)} ${p}${issues.length ? '\n      ' + issues.join('\n      ') : ''}`);
      if (SHOTS) await page.screenshot({ path: `${SHOTS}/${dev.name}-${(step.user || 'public').split('@')[0]}-${p.replace(/[^a-z0-9]+/gi, '_')}.png` });
    }
  }
  await ctx.close();
}
await browser.close();
console.log(failures ? `\n${failures} écran(s) à corriger.` : '\nTous les écrans s\'affichent correctement sur smartphone.');
process.exit(failures ? 1 : 0);
