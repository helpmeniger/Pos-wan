'use strict';
// Journal technique du pont. Jamais de corps de requête, d'identifiant, de mot de passe, de cookie ni de jeton.
const fs = require('fs');

function nettoyer(texte) {
  return String(texte)
    .replace(/eyJ[\w-]+\.[\w-]+\.[\w-]+/g, '‹jeton›')
    .replace(/("?(mot_de_passe|password|Password|jeton|token|acces|cookie)"?\s*[:=]\s*)("[^"]*"|\S+)/gi, '$1‹masqué›')
    .slice(0, 2000);
}

function creerJournal(fichier) {
  return (...parts) => {
    const ligne = new Date().toISOString() + ' ' + nettoyer(parts.join(' ')) + '\n';
    try {
      if (fs.existsSync(fichier) && fs.statSync(fichier).size > 5 * 1024 * 1024) fs.truncateSync(fichier, 0);
      fs.appendFileSync(fichier, ligne, { mode: 0o600 });
    } catch (e) { /* journal indisponible : sans conséquence */ }
    if (process.env.PONT_SECEF_VERBEUX) process.stdout.write(ligne);
  };
}

module.exports = { creerJournal, nettoyer };
