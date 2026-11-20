'use strict';
// Essais du pont sans portail : registre des factures (anti-doublon, reprise) et sécurité HTTP.
const test = require('node:test');
const assert = require('node:assert');
const fs = require('fs');
const os = require('os');
const path = require('path');
const http = require('http');
const { spawn } = require('child_process');
const { Travaux } = require('../lib/travaux');
const { nettoyer } = require('../lib/journal');
const { nombre } = require('../lib/portail');

const cle = (c) => c.repeat(64);

test('registre : une facture dont CERTIFIER a pu partir n\'est jamais renvoyée', () => {
  const d = fs.mkdtempSync(path.join(os.tmpdir(), 'pont-'));
  const t = new Travaux(d);
  assert.strictEqual(t.decision(cle('a')), null, 'inconnue : à traiter');
  t.ecrire({ cle: cle('a'), etat: 'preparee', certification_envoyee: false });
  assert.strictEqual(t.decision(cle('a')), null, 'préparée sans certification : peut être refaite');
  t.ecrire({ cle: cle('a'), etat: 'erreur', certification_envoyee: true });
  assert.ok(t.decision(cle('a')), 'certification partie : jamais renvoyée');
  t.ecrire({ cle: cle('b'), etat: 'certifiee', certification_envoyee: true });
  assert.strictEqual(t.decision(cle('b')).etat, 'certifiee');
  t.ecrire({ cle: cle('c'), etat: 'en_cours' });
  assert.ok(t.decision(cle('c')), 'en cours : pas de second traitement');
  assert.throws(() => t.lire('../../etc/passwd'), /invalide/);
});

test('registre : reprise après arrêt brutal', () => {
  const d = fs.mkdtempSync(path.join(os.tmpdir(), 'pont-'));
  const t = new Travaux(d);
  t.ecrire({ cle: cle('d'), etat: 'en_cours', certification_envoyee: true });
  t.ecrire({ cle: cle('e'), etat: 'en_cours', certification_envoyee: false });
  assert.strictEqual(t.reprendre(), 2);
  assert.strictEqual(t.lire(cle('d')).etat, 'incertaine', 'certification peut-être faite : incertaine');
  assert.strictEqual(t.lire(cle('e')).etat, 'erreur');
  assert.strictEqual(t.lire(cle('e')).reessayable, true);
  assert.strictEqual(Travaux.vue(t.lire(cle('d'))).certification_envoyee, true);
});

test('journal : aucun secret', () => {
  const l = nettoyer('{"acces":{"identifiant":"123","mot_de_passe":"Secret2026"}} jeton=abcdef eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.sig');
  assert.ok(!l.includes('Secret2026') && !l.includes('abcdef') && !l.includes('eyJhbGci'), l);
});

test('nombres affichés par le portail', () => {
  assert.strictEqual(nombre('3 900 000'), 3900000);
  assert.strictEqual(nombre('1 540 000'), 1540000);
  assert.strictEqual(nombre('12,5'), 12.5);
  assert.strictEqual(nombre('-'), null);
});

function requete(port, methode, chemin, corps, entetes) {
  return new Promise((ok, ko) => {
    const r = http.request({ host: '127.0.0.1', port, method: methode, path: chemin, headers: Object.assign({ 'Content-Type': 'application/json' }, entetes) }, (res) => {
      let b = ''; res.on('data', (c) => { b += c; }); res.on('end', () => ok({ status: res.statusCode, data: b ? JSON.parse(b) : {} }));
    });
    r.on('error', ko);
    if (corps) r.write(JSON.stringify(corps));
    r.end();
  });
}

test('serveur : jeton, hôte, origine, validation, facture inconnue', async () => {
  const jeton = 'j'.repeat(48);
  const port = 18760 + Math.floor(Math.random() * 200);
  const donnees = fs.mkdtempSync(path.join(os.tmpdir(), 'pont-'));
  const p = spawn(process.execPath, [path.join(__dirname, '..', 'serveur.js')], { env: Object.assign({}, process.env, { PONT_SECEF_JETON: jeton, PONT_SECEF_PORT: String(port), PONT_SECEF_DONNEES: donnees }) });
  try {
    for (let i = 0; i < 50; i++) { try { await requete(port, 'GET', '/etat', null, {}); break; } catch (e) { await new Promise((r) => setTimeout(r, 100)); } }
    const J = { 'X-Pont-Jeton': jeton };
    assert.strictEqual((await requete(port, 'GET', '/etat', null, {})).status, 401, 'sans jeton');
    assert.strictEqual((await requete(port, 'GET', '/etat', null, { 'X-Pont-Jeton': 'x'.repeat(48) })).status, 401, 'mauvais jeton');
    assert.strictEqual((await requete(port, 'GET', '/etat', null, Object.assign({ Origin: 'https://site.exemple' }, J))).status, 403, 'appel depuis une page web');
    assert.strictEqual((await requete(port, 'GET', '/etat', null, Object.assign({ Host: 'pirate.exemple' }, J))).status, 403, 'autre nom d\'hôte (DNS rebinding)');
    const e = await requete(port, 'GET', '/etat', null, J);
    assert.strictEqual(e.status, 200);
    assert.strictEqual(e.data.navigateur, 'arrete');
    assert.strictEqual((await requete(port, 'GET', '/factures/' + cle('f'), null, J)).status, 404);
    const inv = await requete(port, 'POST', '/factures/soumettre', { facture: { cle: 'x', type: 'FV', mode: 'TTC', lignes: [] } }, J);
    assert.strictEqual(inv.status, 400);
    const sansLigne = await requete(port, 'POST', '/factures/soumettre', { facture: { cle: cle('f'), type: 'FV', mode: 'TTC', lignes: [] } }, J);
    assert.ok(/sans ligne/.test(sansLigne.data.erreur));
    assert.ok(!fs.existsSync(path.join(donnees, 'travaux', cle('f') + '.json')), 'facture invalide : rien enregistré');
  } finally {
    p.kill();
  }
});

test('serveur : refuse de démarrer sans jeton', () => {
  const r = require('child_process').spawnSync(process.execPath, [path.join(__dirname, '..', 'serveur.js')], { env: Object.assign({}, process.env, { PONT_SECEF_JETON: '', PONT_SECEF_DONNEES: fs.mkdtempSync(path.join(os.tmpdir(), 'pont-')) }), timeout: 10000 });
  assert.strictEqual(r.status, 2);
});
