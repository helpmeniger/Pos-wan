/**
 * Langues : chaque espace est affiché en haoussa, zarma et anglais sans erreur JavaScript,
 * et la part de textes restés en français est mesurée.
 *   BASE_URL=http://127.0.0.1:8080 node tests/e2e/i18n.mjs
 */
import { chromium } from 'playwright';
import { readFileSync } from 'fs';

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8080';
const SHOTS = (process.argv.find((a) => a.startsWith('--shots=')) || '').split('=')[1];
const source = new Set(JSON.parse(readFileSync(new URL('../../public/assets/i18n/_source.json', import.meta.url))));
// Textes identiques dans les deux langues (noms propres, « Taxi », « Pro »…) : non comptés comme oubliés
const same = (lang) => { const s = new Set(); for (const k of [1, 2]) { try { const d = JSON.parse(readFileSync(new URL(`../../tools/i18n/parts/${lang}-${k}.json`, import.meta.url))); Object.entries(d).forEach(([a, b]) => { if (a === b) s.add(a); }); } catch (e) { /* fichiers de travail absents */ } } return s; };
const plan = [
  { user: null, pages: ['/', '/connexion', '/inscription'] },
  { user: 'client@marwa.demo', pages: ['/client#/', '/client#/taxi', '/client#/repas', '/client#/historique', '/client#/profil'] },
  { user: 'chauffeur@marwa.demo', pages: ['/prestataire#/', '/prestataire#/revenus', '/prestataire#/documents'] },
  { user: 'restaurant@marwa.demo', pages: ['/commerce#/', '/commerce#/catalogue', '/commerce#/parametres'] },
];
const browser = await chromium.launch();
let failed = false;
for (const lang of ['ha', 'dje', 'en']) {
  let shown = 0, french = 0; const left = new Set(); const errors = []; const identical = same(lang);
  for (const step of plan) {
    const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, geolocation: { latitude: 13.5137, longitude: 2.1098 }, permissions: ['geolocation'] });
    await ctx.addCookies([{ name: 'mw_lang', value: lang, url: BASE }]);
    const page = await ctx.newPage();
    page.on('pageerror', (e) => errors.push(e.message));
    if (step.user) {
      await page.goto(BASE + '/connexion');
      if (await page.locator('[data-tab="password"]').count()) await page.click('[data-tab="password"]');
      await page.fill('#identifier', step.user); await page.fill('#password', 'Demo2026!');
      await Promise.all([page.waitForNavigation(), page.click('#login-form button[type=submit]')]);
    }
    for (const p of step.pages) {
      await page.goto(BASE + p, { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(1300);
      const htmlLang = await page.getAttribute('html', 'lang');
      if (htmlLang !== lang) errors.push(`langue de la page ${p} : ${htmlLang}`);
      const texts = await page.evaluate(() => { const out = []; const tw = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT); let n;
        while ((n = tw.nextNode())) { const p = n.parentElement; if (!p || p.closest('script,style,[translate="no"],.leaflet-control-attribution') || !p.offsetParent && p.nodeName !== 'OPTION') continue; const t = n.nodeValue.replace(/\s+/g, ' ').trim(); if (t.length > 1) out.push(t); } return out; });
      texts.forEach((t) => { shown++; if (source.has(t) && !identical.has(t)) { french++; left.add(t); } });
      if (SHOTS) await page.screenshot({ path: `${SHOTS}/i18n-${lang}-${(step.user || 'public').split('@')[0]}-${p.replace(/[^a-z0-9]+/gi, '_')}.png` });
    }
    await ctx.close();
  }
  const pct = Math.round(100 * (1 - french / shown));
  console.log(`${lang} : ${shown} textes affichés, ${pct} % traduits${left.size ? ' — encore en français : ' + [...left].slice(0, 12).join(' | ') : ''}${errors.length ? '\n  ERREURS : ' + errors.join(' ; ') : ''}`);
  if (errors.length || pct < 90) failed = true;
}
await browser.close();
console.log(failed ? 'ÉCHEC' : 'LANGUES OK');
process.exit(failed ? 1 : 0);
