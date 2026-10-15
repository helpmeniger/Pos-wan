/**
 * Outil (pas un test) : relève les textes réellement affichés dans les espaces
 * client, chauffeur, livreur, commerce et entreprise, pour la liste des traductions.
 *   BASE_URL=http://127.0.0.1:8080 node tests/e2e/i18n-collect.mjs > tools/i18n/runtime.json
 */
import { chromium } from 'playwright';

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8080';
const plan = [
  { user: null, pages: ['/', '/connexion', '/inscription', '/inscription?profil=driver', '/inscription?profil=courier', '/inscription?profil=merchant', '/inscription?profil=business', '/mot-de-passe-oublie', '/hors-ligne'] },
  { user: 'client@marwa.demo', pages: ['/client#/', '/client#/taxi', '/client#/taxi?service=pro', '/client#/colis', '/client#/colis?type=document', '/client#/colis?type=errand', '/client#/repas', '/client#/repas/1', '/client#/historique', '/client#/adresses', '/client#/profil', '/client#/assistance', '/client#/assistance?nouveau=1', '/client#/commande/1', '/client#/commande/2'] },
  { user: 'chauffeur@marwa.demo', pages: ['/prestataire#/', '/prestataire#/course', '/prestataire#/revenus', '/prestataire#/historique', '/prestataire#/documents', '/prestataire#/profil', '/prestataire#/assistance'] },
  { user: 'livreur@marwa.demo', pages: ['/prestataire#/', '/prestataire#/course', '/prestataire#/revenus', '/prestataire#/documents'] },
  { user: 'restaurant@marwa.demo', pages: ['/commerce#/', '/commerce#/commandes', '/commerce#/catalogue', '/commerce#/finances', '/commerce#/rapports', '/commerce#/livreurs', '/commerce#/parametres', '/commerce#/profil'] },
  { user: 'entreprise@marwa.demo', pages: ['/entreprise#/', '/entreprise#/collaborateurs', '/entreprise#/commandes', '/entreprise#/releves', '/entreprise#/adresses'] },
];
const collect = () => {
  const out = new Set();
  const tw = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT | NodeFilter.SHOW_ELEMENT);
  let n;
  while ((n = tw.nextNode())) {
    if (n.nodeType === 3) {
      const p = n.parentElement;
      if (!p || ['SCRIPT', 'STYLE', 'TEXTAREA'].includes(p.nodeName) || p.closest('[translate="no"], .leaflet-control-attribution')) continue;
      const t = n.nodeValue.replace(/\s+/g, ' ').trim();
      if (t) out.add(t);
    } else ['placeholder', 'title', 'aria-label'].forEach((a) => { const v = n.getAttribute(a); if (v) out.add(v.trim()); });
  }
  return [...out];
};
const browser = await chromium.launch();
const all = new Set();
for (const step of plan) {
  const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, locale: 'fr-FR', geolocation: { latitude: 13.5137, longitude: 2.1098 }, permissions: ['geolocation'] });
  const page = await ctx.newPage();
  if (step.user) {
    await page.goto(BASE + '/connexion');
    await page.fill('#identifier', step.user); await page.fill('#password', 'Demo2026!');
    await Promise.all([page.waitForNavigation(), page.click('#login-form button[type=submit]')]);
  }
  for (const p of step.pages) {
    await page.goto(BASE + p, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1500);
    (await page.evaluate(collect)).forEach((t) => all.add(t));
    // menus et fenêtres courants
    for (const sel of ['[data-more]', '[data-user-btn]', '[data-lang-btn]']) {
      if (await page.locator(sel).count()) {
        try { await page.click(sel, { timeout: 1500 }); await page.waitForTimeout(400); (await page.evaluate(collect)).forEach((t) => all.add(t)); await page.keyboard.press('Escape'); } catch (e) { /* invisible */ }
      }
    }
  }
  await ctx.close();
}
await browser.close();
console.log(JSON.stringify([...all], null, 0));
