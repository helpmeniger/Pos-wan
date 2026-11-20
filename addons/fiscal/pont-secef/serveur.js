#!/usr/bin/env node
'use strict';
/**
 * Pont MARWA SECEF CONNECT — service local entre POS Marwa et l'e-SECeF (application e-SFE de la DGI Niger).
 *
 *  - n'écoute que sur 127.0.0.1 ; chaque requête porte le jeton partagé (en-tête X-Pont-Jeton) ;
 *  - un seul navigateur, une seule opération à la fois (file d'attente interne) ;
 *  - chaque facture est identifiée par sa clé d'idempotence ; une facture dont CERTIFIER a pu être actionné
 *    n'est jamais renvoyée (état « incertaine » : vérification humaine dans le Journal de l'e-SFE) ;
 *  - les identifiants e-SECeF reçus de POS Marwa ne sont ni écrits sur disque ni journalisés.
 *
 * API (JSON) :
 *   GET  /etat
 *   POST /session/tester        {acces, url_portail, fenetre_visible}
 *   POST /session/fermer        {url_portail}
 *   POST /articles/lister       {acces, url_portail, fenetre_visible}
 *   POST /articles/programmer   {acces, article: {designation, code, groupe, mode, prix}, …}
 *   POST /factures/soumettre    {acces, facture, certifier, creer_articles, attente_s, …}
 *   GET  /factures/{cle}
 */
const http = require('http');
const crypto = require('crypto');
const { config } = require('./lib/config');
const { creerJournal } = require('./lib/journal');
const { Travaux, CLE } = require('./lib/travaux');
const { Portail, EtatPortail } = require('./lib/portail');

const cfg = config();
const log = creerJournal(cfg.journal);
const travaux = new Travaux(cfg.travaux);
const portail = new Portail(cfg, log);

if (!cfg.jeton || cfg.jeton.length < 32) {
  console.error('Jeton du pont absent ou trop court : définissez PONT_SECEF_JETON (ou le fichier jeton.txt) avec le jeton créé dans POS Marwa (Certification fiscale → MARWA SECEF CONNECT).');
  process.exit(2);
}
const repris = travaux.reprendre();
if (repris) log('reprise après arrêt :', repris, 'facture(s) remise(s) en état sûr');

// ------------------------------------------------------------------ file d'attente (une opération à la fois)
let chaine = Promise.resolve();
let enCours = null;
function enFile(libelle, fn) {
  const p = chaine.then(async () => { enCours = libelle; try { return await fn(); } finally { enCours = null; } });
  chaine = p.catch(() => {});
  return p;
}

// ------------------------------------------------------------------ HTTP
function repondre(res, code, data) {
  const corps = JSON.stringify(data);
  res.writeHead(code, { 'Content-Type': 'application/json; charset=utf-8', 'Cache-Control': 'no-store', 'X-Content-Type-Options': 'nosniff' });
  res.end(corps);
}

function jetonValide(req) {
  const a = Buffer.from(String(req.headers['x-pont-jeton'] || ''));
  const b = Buffer.from(cfg.jeton);
  return a.length === b.length && crypto.timingSafeEqual(a, b);
}

function lireCorps(req) {
  return new Promise((ok, ko) => {
    let n = 0; const morceaux = [];
    req.on('data', (c) => { n += c.length; if (n > 1024 * 1024) { ko(new Error('corps trop volumineux')); req.destroy(); } else morceaux.push(c); });
    req.on('end', () => { try { ok(morceaux.length ? JSON.parse(Buffer.concat(morceaux).toString('utf8')) : {}); } catch (e) { ko(new Error('JSON invalide')); } });
    req.on('error', ko);
  });
}

const erreur = (e) => (e instanceof EtatPortail
  ? { ok: false, etat: e.etat, erreur: e.message, message: e.message, reessayable: e.reessayable }
  : { ok: false, etat: 'erreur', erreur: 'Erreur du pont : ' + String(e.message || e).split('\n')[0], message: String(e.message || e).split('\n')[0], reessayable: true });

// ------------------------------------------------------------------ factures
function validerFacture(f) {
  if (!f || !CLE.test(String(f.cle || ''))) throw new EtatPortail('formulaire', 'Clé de facture invalide.');
  if (!/^(FV|FT|FA|EV|ET|EA)$/.test(f.type) || !/^(TTC|HT)$/.test(f.mode)) throw new EtatPortail('formulaire', 'Type ou mode de facture invalide.');
  if (!Array.isArray(f.lignes) || !f.lignes.length) throw new EtatPortail('formulaire', 'Facture sans ligne.');
  for (const l of f.lignes) {
    if (!l.designation || !/^[ABCDFGHIJKL]$/.test(l.groupe) || !(l.quantite > 0) || typeof l.montant !== 'number') throw new EtatPortail('formulaire', 'Ligne de facture invalide.');
  }
  if ((f.type === 'FA' || f.type === 'EA') && !f.reference_origine) throw new EtatPortail('formulaire', 'Avoir sans référence à la facture de vente d\'origine.');
}

async function traiter(t, corps) {
  const f = corps.facture;
  t.etat = 'en_cours'; t.tentatives = (t.tentatives || 0) + 1; t.certification_envoyee = false; t.message = '';
  travaux.ecrire(t);
  try {
    await portail.session(corps.acces, corps.url_portail, corps.fenetre_visible);
    if (f.type === 'FA' || f.type === 'EA') throw new EtatPortail('formulaire', 'Avoir certifié : saisie de la référence d\'origine pas encore relevée sur le portail. Établissez l\'avoir sur l\'e-SFE.');
    // Articles absents du portail : programmés d'abord, seulement si POS Marwa l'a autorisé
    if (corps.creer_articles) {
      for (const l of f.lignes) {
        if (!(await portail.articleExiste(l.designation))) {
          log('article absent du portail, programmation :', l.designation);
          await portail.programmerArticle({ designation: l.designation, code: l.code || '', groupe: l.groupe, mode: f.mode, prix: l.prix_unitaire });
          t.articles_programmes = [...(t.articles_programmes || []), l.designation];
          travaux.ecrire(t);
        }
      }
    }
    const prep = await portail.preparer(f, { creer_articles: !!corps.creer_articles });
    t.resultat = { total: prep.total, repartition: prep.repartition, commentaire: prep.commentaire };
    if (!corps.certifier) {
      await portail.annuler(); // projet abandonné : rien n'est enregistré sur le portail
      t.etat = 'preparee';
      t.message = 'Facture préparée et vérifiée (total ' + prep.total + '), puis annulée sans certification.';
      return travaux.ecrire(t);
    }
    const r = await portail.certifier(t.cle, async () => { t.certification_envoyee = true; t.certification_le = new Date().toISOString(); travaux.ecrire(t); });
    t.resultat = Object.assign(t.resultat, r.resultat, { total: prep.total });
    if (r.certifiee) {
      t.etat = 'certifiee';
      t.message = 'Facture certifiée.';
      log('facture certifiée', f.reference_pos, r.resultat.code_secef ? '(code relevé)' : '');
    } else {
      t.etat = 'incertaine';
      t.message = r.message + ' Vérifiez la facture dans le Journal de l\'e-SFE avant toute nouvelle tentative.';
    }
    return travaux.ecrire(t);
  } catch (e) {
    const err = erreur(e);
    if (portail.page) await portail.capture('erreur-' + String(f.reference_pos || '').replace(/[^A-Za-z0-9-]/g, ''));
    if (t.certification_envoyee) {
      t.etat = 'incertaine';
      t.message = err.message + ' — la certification a pu partir : vérifiez le Journal de l\'e-SFE avant toute nouvelle tentative.';
    } else {
      // Avant CERTIFIER, rien n'existe sur le portail : un problème de données (article non programmé, écart de
      // total, champ refusé) est une « erreur » corrigeable, jamais un refus fiscal.
      t.etat = ['identifiants', 'intervention'].includes(err.etat) ? err.etat : 'erreur';
      t.message = err.message;
      t.reessayable = err.reessayable;
      try { await portail.annuler(); } catch (x) { /* projet local seulement */ }
    }
    log('facture', f.reference_pos, '→', t.etat, ':', t.message);
    return travaux.ecrire(t);
  }
}

async function soumettre(corps) {
  validerFacture(corps.facture);
  const cle = corps.facture.cle;
  const existant = travaux.decision(cle);
  if (existant) return Travaux.vue(existant); // déjà traitée, en cours ou incertaine : jamais renvoyée
  const t = travaux.ecrire(Object.assign(travaux.lire(cle) || { cle, cree: new Date().toISOString() }, {
    etat: 'recue', reference_pos: corps.facture.reference_pos, total: corps.facture.total, certifier: !!corps.certifier, message: '',
  }));
  const fin = enFile('facture ' + corps.facture.reference_pos, () => traiter(t, corps));
  const attente = Math.max(5, Math.min(110, Number(corps.attente_s) || 60)) * 1000;
  const r = await Promise.race([fin, new Promise((ok) => setTimeout(() => ok(null), attente))]);
  return Travaux.vue(r || travaux.lire(cle));
}

// ------------------------------------------------------------------ routes
const serveur = http.createServer(async (req, res) => {
  try {
    const hote = String(req.headers.host || '');
    if (!/^(127\.0\.0\.1|localhost)(:\d+)?$/.test(hote)) return repondre(res, 403, { ok: false, erreur: 'hôte refusé' });
    if (req.headers.origin) return repondre(res, 403, { ok: false, erreur: 'origine refusée' }); // jamais appelé par une page web
    if (!jetonValide(req)) return repondre(res, 401, { ok: false, erreur: 'jeton du pont invalide' });
    const url = new URL(req.url, 'http://127.0.0.1');
    const corps = req.method === 'POST' ? await lireCorps(req) : {};
    const route = req.method + ' ' + url.pathname;
    log('requête', route);

    if (route === 'GET /etat') return repondre(res, 200, Object.assign({ ok: true, version: cfg.version, travail: enCours, ecritures_bloquees: portail.bloquees }, portail.etat()));
    if (route === 'POST /session/tester') {
      const r = await enFile('test de connexion', async () => { await portail.session(corps.acces, corps.url_portail, corps.fenetre_visible); return portail.nim(); });
      return repondre(res, 200, { ok: true, nim: r, message: 'Session e-SECeF ouverte, application e-SFE chargée' + (r ? ' (e-SECeF ID ' + r + ')' : '') + '.' });
    }
    if (route === 'POST /session/fermer') {
      await enFile('déconnexion', () => portail.deconnecter(corps.url_portail));
      return repondre(res, 200, { ok: true, message: 'Session fermée.' });
    }
    if (route === 'POST /articles/lister') {
      const a = await enFile('relevé des articles', async () => { await portail.session(corps.acces, corps.url_portail, corps.fenetre_visible); return portail.listerArticles(); });
      return repondre(res, 200, { ok: true, articles: a });
    }
    if (route === 'POST /articles/programmer') {
      const a = corps.article || {};
      await enFile('programmation de ' + a.designation, async () => {
        await portail.session(corps.acces, corps.url_portail, corps.fenetre_visible);
        if (await portail.articleExiste(a.designation)) return;
        await portail.programmerArticle(a);
      });
      return repondre(res, 200, { ok: true, etat: 'programme', message: 'Article programmé.' });
    }
    if (route === 'POST /factures/soumettre') return repondre(res, 200, await soumettre(corps));
    const m = url.pathname.match(/^\/factures\/([a-f0-9]{64})$/);
    if (req.method === 'GET' && m) {
      const t = travaux.lire(m[1]);
      return t ? repondre(res, 200, Travaux.vue(t)) : repondre(res, 404, { ok: false, etat: 'inconnue', erreur: 'facture inconnue du pont' });
    }
    return repondre(res, 404, { ok: false, erreur: 'route inconnue' });
  } catch (e) {
    const r = erreur(e);
    log('erreur :', r.message);
    return repondre(res, r.etat === 'formulaire' && /invalide/.test(r.message) ? 400 : 502, r);
  }
});

serveur.requestTimeout = 0; // les opérations sur le portail peuvent être longues (attente bornée par attente_s)
serveur.listen(cfg.port, cfg.hote, () => {
  log('pont MARWA SECEF', cfg.version, 'à l\'écoute sur', cfg.hote + ':' + cfg.port, '— navigateur :', cfg.navigateur);
  console.log(`Pont MARWA SECEF ${cfg.version} : http://${cfg.hote}:${cfg.port} (navigateur : ${cfg.navigateur}). Laissez cette fenêtre ouverte.`);
});

for (const s of ['SIGINT', 'SIGTERM']) process.on(s, async () => { await portail.arreter(); process.exit(0); });
