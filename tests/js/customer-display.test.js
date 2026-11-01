/* Afficheur client : le pilote du navigateur produit les mêmes octets que le serveur
   (App\Services\CustomerDisplay::vfdBytes), et les lignes affichées suivent la vente.
   Usage : node tests/js/customer-display.test.js */
'use strict';
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const SRC = fs.readFileSync(path.join(__dirname, '../../public/assets/js/customer-display-link.js'), 'utf8');
let pass = 0; let fail = 0;
const check = (c, m) => { if (c) { pass++; console.log('  ✔ ' + m); } else { fail++; console.log('  ✘ ' + m); } };

/** Charge le pilote avec un réglage donné, dans un document minimal. */
function load(vfd) {
  const cfg = { thanks: 'Merci !', thanksSeconds: 5, vfd: Object.assign({ enabled: true, mode: 'browser', protocol: 'escpos', cols: 20, baud: 9600, line1: 'BIENVENUE', line2: 'Supérette' }, vfd) };
  const listeners = {};
  const document = {
    getElementById: (id) => (id === 'pm-display-config' ? { textContent: JSON.stringify(cfg) } : null),
    querySelector: () => null, querySelectorAll: () => [],
    addEventListener: (t, f) => { (listeners[t] = listeners[t] || []).push(f); },
    dispatchEvent: () => true,
  };
  const window = { PWMoney: { c: { symbol: 'F' }, plain: (n) => Math.round(n).toLocaleString('fr-FR') } };
  const fn = new Function('window', 'document', 'navigator', 'localStorage', 'CustomEvent', 'TextEncoder', 'alert', SRC.replace("'BroadcastChannel' in window", 'false'));
  fn(window, document, {}, { setItem() {}, getItem() { return null; } }, function CustomEvent() {}, TextEncoder, () => {});
  return window.PMDisplay;
}
const php = (l1, l2, proto, cols) => execFileSync('php', ['-r', `define('BASE_PATH', '${path.join(__dirname, '../..')}'); require BASE_PATH . '/app/Core/Audit.php'; spl_autoload_register(function ($c) { $f = BASE_PATH . '/app/' . str_replace(['App\\\\', '\\\\'], ['', '/'], $c) . '.php'; if (is_file($f)) require $f; }); echo App\\Services\\CustomerDisplay::vfdBytes($argv[1], $argv[2], $argv[3], (int) $argv[4]);`, l1, l2, proto, String(cols)]).toString('latin1');

for (const proto of ['escpos', 'cd5220', 'text']) {
  const D = load({ protocol: proto });
  for (const [a, b] of [['TOTAL', '1 500 F'], ['Café crème à 500 F', 'Œuf frais — 3 x 75 F'], ['x'.repeat(60), '']]) {
    check(D.vfdBytes(a, b) === php(a, b, proto, 20), `${proto} : « ${a.slice(0, 18)} » identique au serveur`);
  }
}
const D = load({});
const cart = { mode: 'cart', lines: [{ name: 'Riz parfumé 25 kg', qty: 2, price: 9000, total: 18000 }], total: 18000 };
const [l1, l2] = D.vfdLines(cart);
check(l1.length === 20 && l1.startsWith('2 x Riz') && l1.endsWith('18 000 F'.replace(/\s/g, ' ')), 'article ajouté : quantité, nom, montant sur 20 caractères (' + JSON.stringify(l1) + ')');
check(/^TOTAL +18.000 F$/.test(l2), 'ligne 2 : total (' + JSON.stringify(l2) + ')');
const [t1, t2] = D.vfdLines({ mode: 'thanks', total: 18000, change: 2000 });
check(/^TOTAL/.test(t1) && /^RENDU +2.000 F$/.test(t2), 'après la vente : total et monnaie rendue');
check(D.vfdLines({ mode: 'thanks', total: 500, change: 0 })[1] === 'Merci !', 'sans monnaie : message de remerciement');
check(D.vfdLines({ mode: 'idle' }).join('|') === 'BIENVENUE|Supérette', 'au repos : lignes d\'accueil des paramètres');
console.log(`\n${pass} réussi(s), ${fail} échec(s)`);
process.exit(fail ? 1 : 0);
