'use strict';
/**
 * Factures confiées au pont, une par clé d'idempotence (empreinte SHA-256 fournie par POS Marwa).
 * Chaque changement d'état est écrit sur disque AVANT l'action correspondante (écriture atomique) :
 * après une coupure, une facture dont le bouton CERTIFIER a pu être actionné est marquée « incertaine »
 * et n'est JAMAIS renvoyée automatiquement.
 *
 * États : recue → en_cours → preparee | certifiee | incertaine | refusee | erreur | identifiants | intervention
 */
const fs = require('fs');
const path = require('path');

const CLE = /^[a-f0-9]{64}$/;
const TERMINES_SANS_RENVOI = ['certifiee', 'incertaine', 'refusee'];

class Travaux {
  constructor(dossier) {
    this.dossier = dossier;
    fs.mkdirSync(dossier, { recursive: true, mode: 0o700 });
  }

  fichier(cle) {
    if (!CLE.test(cle)) throw new Error('clé de facture invalide');
    return path.join(this.dossier, cle + '.json');
  }

  lire(cle) {
    const f = this.fichier(cle);
    if (!fs.existsSync(f)) return null;
    return JSON.parse(fs.readFileSync(f, 'utf8'));
  }

  ecrire(travail) {
    travail.maj = new Date().toISOString();
    const f = this.fichier(travail.cle);
    const tmp = f + '.' + process.pid + '.tmp';
    const fd = fs.openSync(tmp, 'w', 0o600);
    fs.writeSync(fd, JSON.stringify(travail, null, 1));
    fs.fsyncSync(fd);
    fs.closeSync(fd);
    fs.renameSync(tmp, f);
    return travail;
  }

  /** Après un redémarrage : une facture interrompue pendant la certification devient « incertaine ». */
  reprendre() {
    let n = 0;
    for (const f of fs.readdirSync(this.dossier)) {
      if (!f.endsWith('.json')) continue;
      const t = JSON.parse(fs.readFileSync(path.join(this.dossier, f), 'utf8'));
      if (t.etat === 'en_cours' || t.etat === 'recue') {
        if (t.certification_envoyee) {
          t.etat = 'incertaine';
          t.message = 'Le pont s\'est arrêté pendant la certification : vérifiez la facture dans le Journal de l\'e-SFE avant toute nouvelle tentative.';
        } else {
          t.etat = 'erreur';
          t.reessayable = true;
          t.message = 'Le pont s\'est arrêté avant la certification : la facture peut être renvoyée sans risque.';
        }
        this.ecrire(t);
        n++;
      }
    }
    return n;
  }

  /**
   * Décision pour une nouvelle demande : null = la traiter ; sinon l'état existant est renvoyé tel quel.
   * Une facture n'est retraitée que si CERTIFIER n'a jamais été actionné et qu'elle n'est pas en cours.
   */
  decision(cle) {
    const t = this.lire(cle);
    if (!t) return null;
    if (t.certification_envoyee || TERMINES_SANS_RENVOI.includes(t.etat) || t.etat === 'en_cours' || t.etat === 'recue') return t;
    return null;
  }

  /** Vue publique (renvoyée à POS Marwa) : ni identifiant ni contenu sensible. */
  static vue(t) {
    if (!t) return null;
    return { ok: true, cle: t.cle, etat: t.etat, message: t.message || '', reessayable: !!t.reessayable,
      certification_envoyee: !!t.certification_envoyee, resultat: t.resultat || null, tentatives: t.tentatives || 0, maj: t.maj };
  }
}

module.exports = { Travaux, CLE };
