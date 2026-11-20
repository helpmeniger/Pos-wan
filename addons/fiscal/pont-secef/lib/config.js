'use strict';
// Réglages du pont : variables d'environnement, puis fichiers à côté du programme. Aucun identifiant e-SECeF ici :
// POS Marwa les transmet requête par requête et le pont ne les écrit jamais.
const fs = require('fs');
const path = require('path');

const RACINE = path.resolve(__dirname, '..');

function lireJeton() {
  if (process.env.PONT_SECEF_JETON) return process.env.PONT_SECEF_JETON.trim();
  const f = path.join(RACINE, 'jeton.txt');
  return fs.existsSync(f) ? fs.readFileSync(f, 'utf8').trim() : '';
}

function config() {
  const donnees = path.resolve(process.env.PONT_SECEF_DONNEES || path.join(RACINE, 'donnees'));
  const c = {
    version: require('../package.json').version,
    hote: '127.0.0.1',
    port: parseInt(process.env.PONT_SECEF_PORT || '8760', 10),
    jeton: lireJeton(),
    donnees,
    profil: path.join(donnees, 'profil-navigateur'),
    travaux: path.join(donnees, 'travaux'),
    pdf: path.join(donnees, 'pdf'),
    journal: path.join(donnees, 'pont.log'),
    // Navigateur : Edge (présent sur Windows 10/11) par défaut sous Windows ; Chromium de Playwright ailleurs.
    navigateur: process.env.PONT_SECEF_NAVIGATEUR || (process.platform === 'win32' ? 'msedge' : 'chromium'),
    proxy: process.env.PONT_SECEF_PROXY || '',
    // Le chargement de l'e-SFE peut être long (connexion lente) : 5 minutes au plus.
    delaiChargement: parseInt(process.env.PONT_SECEF_DELAI_CHARGEMENT || '300', 10) * 1000,
  };
  for (const d of [c.donnees, c.travaux, c.pdf]) fs.mkdirSync(d, { recursive: true, mode: 0o700 });
  return c;
}

module.exports = { config, RACINE };
