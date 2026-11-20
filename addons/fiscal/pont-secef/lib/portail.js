'use strict';
/**
 * Pilotage de l'e-SECeF / e-SFE (DGI Niger) comme le ferait un utilisateur, d'après les écrans relevés le 20/11/2026
 * (documentation/marwa-secef/CARTOGRAPHIE_PORTAIL.md). Aucun appel direct aux services internes du portail.
 *
 * GARDE-FOU : toute requête d'écriture (autre que GET) vers /api/ du portail est BLOQUÉE par le navigateur, sauf
 * pendant les deux seules actions qui écrivent : « programmer un article » et « CERTIFIER », ouvertes explicitement.
 * Préparer une facture (remplir, vérifier, annuler) ne peut donc rien enregistrer sur le portail.
 */
const fs = require('fs');
const path = require('path');

let pw;
try { pw = require('playwright'); } catch (e) { pw = require('playwright-core'); }

class EtatPortail extends Error {
  constructor(etat, message, reessayable = false) {
    super(message);
    this.etat = etat;
    this.reessayable = reessayable;
  }
}

const norm = (s) => String(s || '').replace(/\s+/g, ' ').trim().toLowerCase();
const nombre = (s) => {
  const t = String(s || '').replace(/[\s  ]/g, '').replace(',', '.').replace(/[^0-9.\-]/g, '');
  return t === '' || isNaN(Number(t)) ? null : Number(t);
};

class Portail {
  constructor(cfg, journal) {
    this.cfg = cfg;
    this.log = journal;
    this.ctx = null;
    this.page = null;
    this.ecritures = false; // ouvert seulement pendant « programmer un article » et « CERTIFIER »
    this.bloquees = 0;
    this.visible = null;
  }

  etat() {
    return { navigateur: this.ctx ? 'demarre' : 'arrete', session: this.page && /\/esfe\/pos/.test(this.page.url()) ? 'ouverte' : (this.ctx ? 'inconnue' : 'fermee') };
  }

  async demarrer(visible) {
    if (this.ctx && this.visible === !!visible) return;
    await this.arreter();
    const opts = { headless: !visible, locale: 'fr-FR', viewport: { width: 1440, height: 900 }, acceptDownloads: true, args: ['--disable-features=Translate'] };
    if (this.cfg.proxy) opts.proxy = { server: this.cfg.proxy };
    const n = this.cfg.navigateur;
    if (n === 'msedge' || n === 'chrome') opts.channel = n;
    else if (n !== 'chromium' && fs.existsSync(n)) opts.executablePath = n;
    this.ctx = await pw.chromium.launchPersistentContext(this.cfg.profil, opts);
    this.visible = !!visible;
    await this.ctx.route('**/api/**', (route) => {
      const m = route.request().method();
      if (m === 'GET' || m === 'HEAD' || m === 'OPTIONS' || this.ecritures) return route.continue();
      this.bloquees++;
      this.log('garde-fou : écriture bloquée', m, new URL(route.request().url()).pathname);
      return route.abort();
    });
    this.page = this.ctx.pages()[0] || await this.ctx.newPage();
    this.page.on('dialog', (d) => d.dismiss().catch(() => {}));
    this.log('navigateur démarré', n, visible ? '(fenêtre visible)' : '(sans fenêtre)');
  }

  async arreter() {
    if (this.ctx) { try { await this.ctx.close(); } catch (e) { /* déjà fermé */ } }
    this.ctx = null; this.page = null;
  }

  texte() { return this.page.evaluate(() => document.body ? document.body.innerText : ''); }

  /** Clic sur l'élément visible dont le texte vaut exactement « libelle » (dans « portee » si donnée). */
  async cliquer(libelle, portee, obligatoire = true) {
    const ok = await this.page.evaluate(([l, p]) => {
      const vis = (e) => !!(e.offsetWidth || e.offsetHeight);
      const root = p ? [...document.querySelectorAll(p)].filter(vis).pop() || null : document;
      if (!root) return false;
      const c = [...root.querySelectorAll('ion-button,button,a,ion-item,ion-chip,ion-label,ion-radio,div,span')]
        .filter((e) => vis(e) && (e.innerText || '').trim().toUpperCase() === l.toUpperCase());
      const el = c.find((e) => /^(ION-BUTTON|BUTTON|A|ION-RADIO)$/.test(e.tagName)) || c[0];
      if (!el) return false;
      el.click();
      return true;
    }, [libelle, portee || null]);
    if (!ok && obligatoire) throw new EtatPortail('formulaire', `Élément « ${libelle} » introuvable : le portail a peut-être changé.`);
    return ok;
  }

  async attendreTexte(re, ms) {
    await this.page.waitForFunction((src) => new RegExp(src, 'i').test(document.body ? document.body.innerText : ''), re.source, { timeout: ms });
  }

  // ------------------------------------------------------------------ session

  /** Session e-SECeF ouverte et application e-SFE chargée (connexion avec le NIF si nécessaire). */
  async session(acces, urlPortail, visible) {
    await this.demarrer(visible);
    const p = this.page;
    const base = (urlPortail || 'https://esecef.impots.gouv.ne').replace(/\/+$/, '');
    if (/\/esfe\/pos/.test(p.url())) {
      const t = await this.texte().catch(() => '');
      if (!/session a expiré/i.test(t) && /ACCUEIL/i.test(t)) return { deja: true };
    }
    await p.goto(base + '/Manage', { waitUntil: 'domcontentloaded', timeout: 90000 }).catch((e) => { throw new EtatPortail('reseau', 'Portail e-SECeF injoignable : ' + e.message.split('\n')[0], true); });
    // Sans session, /Manage mène à « La session a expiré » : la connexion passe par l'accueil
    // (bouton « Accès à la plateforme e-SECeF » → page de connexion SyGMEF), comme observé le 20/11/2026.
    if (!/\/Manage/i.test(p.url()) && !(await p.locator('#UserName').count())) {
      await p.goto(base + '/', { waitUntil: 'domcontentloaded', timeout: 90000 }).catch((e) => { throw new EtatPortail('reseau', 'Portail e-SECeF injoignable : ' + e.message.split('\n')[0], true); });
      await this.cliquer('Accès à la plateforme e-SECeF');
      await p.waitForSelector('#UserName, form[action="/Manage/OpenEsfe"]', { timeout: 90000 }).catch(() => { throw new EtatPortail('reseau', 'Page de connexion SyGMEF non chargée.', true); });
    }
    if (await p.locator('#UserName').count()) {
      if (!acces || !acces.identifiant || !acces.mot_de_passe) throw new EtatPortail('identifiants', 'Identifiants e-SECeF absents.');
      await p.fill('#UserName', acces.identifiant);
      await p.fill('#Password', acces.mot_de_passe);
      await Promise.all([p.waitForLoadState('domcontentloaded', { timeout: 90000 }), p.click('button:has-text("Se connecter"), input[type=submit]')]);
      await p.waitForTimeout(2500);
      const t = await this.texte();
      if (/mot de passe invalide|identifiant de l'utilisateur ou mot de passe/i.test(t)) {
        const m = t.match(/Tentatives restantes[^\n]*/i);
        throw new EtatPortail('identifiants', 'Identifiant ou mot de passe e-SECeF refusé par le portail.' + (m ? ' ' + m[0] : '') + ' Aucune nouvelle tentative automatique.');
      }
      if (/bloqu/i.test(t) && /UserName/i.test(await p.content())) throw new EtatPortail('identifiants', 'Compte e-SECeF temporairement bloqué par le portail.');
      if (/captcha|code de v[ée]rification|code envoy|OTP/i.test(t)) throw new EtatPortail('intervention', 'Le portail demande une vérification (code, CAPTCHA) : terminez-la dans la fenêtre du pont.');
      if (!/\/Manage/i.test(p.url())) await p.goto(base + '/Manage', { waitUntil: 'domcontentloaded', timeout: 90000 });
      this.log('connexion e-SECeF réussie');
    }
    const ouvrir = p.locator('form[action="/Manage/OpenEsfe"] button, form[action="/Manage/OpenEsfe"] input[type=submit]');
    if (!(await ouvrir.count())) throw new EtatPortail('formulaire', 'Bouton « e-SFE » introuvable dans « Gérer les e-SECeF » (aucun e-SFE actif, ou portail modifié).');
    await ouvrir.first().click();
    try {
      await this.attendreTexte(/Bienvenue sur la plateforme de facturation/, this.cfg.delaiChargement);
    } catch (e) {
      throw new EtatPortail('reseau', 'L\'application e-SFE ne s\'est pas chargée à temps.', true);
    }
    this.log('application e-SFE ouverte');
    return { deja: false };
  }

  async nim() {
    const t = await this.texte();
    const m = t.match(/e-SECeF ID\s*:?\s*\n?\s*([A-Z]{2}[0-9A-Z-]{6,})/);
    return m ? m[1] : null;
  }

  async deconnecter(urlPortail) {
    if (!this.page) return;
    const base = (urlPortail || 'https://esecef.impots.gouv.ne').replace(/\/+$/, '');
    await this.page.goto(base + '/Manage', { waitUntil: 'domcontentloaded', timeout: 60000 }).catch(() => {});
    await this.cliquer('Se déconnecter', null, false);
    await this.page.waitForTimeout(2000);
    await this.arreter();
    this.log('session e-SECeF fermée');
  }

  async menu(libelle) {
    await this.cliquer(libelle); // bouton du menu supérieur (ion-button) : prioritaire sur les autres textes identiques
    await this.page.waitForTimeout(2500);
  }

  // ------------------------------------------------------------------ articles

  /** Lignes visibles de la liste des articles (écran Articles ou section « 2. Articles » d'une facture). */
  async lignesArticles() {
    return this.page.evaluate(() => {
      const vis = (e) => !!(e.offsetWidth || e.offsetHeight);
      const out = [];
      for (const ic of document.querySelectorAll('ion-icon[name="remove-circle-outline"], ion-icon[name="bag-add-outline"]')) {
        if (!vis(ic)) continue;
        let row = ic.closest('ion-row, ion-item, tr, mat-row');
        if (!row) continue;
        const cells = (row.innerText || '').split('\n').map((s) => s.trim()).filter(Boolean);
        out.push(cells);
      }
      return out;
    });
  }

  async listerArticles() {
    await this.menu('ARTICLES');
    await this.attendreTexte(/Gérer les articles programmés/, 60000);
    await this.page.waitForTimeout(2000);
    const vus = new Map();
    for (let page = 0; page < 300; page++) {
      for (const c of await this.lignesArticles()) {
        // Colonnes observées : Désignation · Groupe · Prix [TTC] · Prix [HT] · Mode · Taxe spécifique
        const a = { designation: c[0], groupe: (c[1] || '').replace(/[^A-Z]/g, '').slice(0, 1), prix_ttc: nombre(c[2]), prix_ht: nombre(c[3]), mode: c[4] || '', ts: c[5] && c[5] !== '-' ? c[5] : '' };
        if (a.designation) vus.set(norm(a.designation), a);
      }
      const suivant = this.page.locator('button[aria-label="Next page"]').first();
      if (!(await suivant.count()) || await suivant.isDisabled()) break;
      await suivant.click();
      await this.page.waitForTimeout(1200);
    }
    this.log('articles relevés :', vus.size);
    return [...vus.values()];
  }

  async choisirOption(selectLocator, debut) {
    await selectLocator.click();
    await this.page.waitForTimeout(1200);
    const ok = await this.page.evaluate((d) => {
      const b = [...document.querySelectorAll('ion-alert button, ion-popover ion-item, ion-action-sheet button')].find((x) => (x.innerText || '').trim().toUpperCase().startsWith(d.toUpperCase()));
      if (!b) return false;
      b.click();
      return true;
    }, debut);
    if (!ok) { await this.page.keyboard.press('Escape'); throw new EtatPortail('formulaire', `Option « ${debut} » absente de la liste du portail.`); }
    await this.page.waitForTimeout(600);
    await this.cliquer('OK', 'ion-alert', false);
    await this.page.waitForTimeout(800);
  }

  async remplir(portee, formcontrolname, valeur) {
    const inp = this.page.locator(`${portee} ion-input[formcontrolname="${formcontrolname}"] input:visible`).last();
    if (!(await inp.count())) throw new EtatPortail('formulaire', `Champ « ${formcontrolname} » introuvable : le portail a peut-être changé.`);
    await inp.fill(String(valeur));
  }

  /** Programme un article sur l'e-SFE (ÉCRITURE sur le portail : appelée seulement à la demande de POS Marwa). */
  async programmerArticle(a) {
    if (!/^[ABCDFGHIJKL]$/.test(a.groupe || '')) throw new EtatPortail('formulaire', 'Groupe de taxation invalide.');
    await this.menu('ARTICLES');
    await this.cliquer('PROGRAMMER UN ARTICLE');
    await this.page.waitForSelector('ion-modal ion-input[formcontrolname="name"]', { timeout: 30000 });
    await this.remplir('ion-modal', 'name', a.designation);
    if (a.code) await this.remplir('ion-modal', 'code', a.code);
    await this.choisirOption(this.page.locator('ion-modal ion-select[formcontrolname="taxGroup"]').last(), `[${a.groupe}]`);
    await this.cliquer(a.mode === 'HT' ? 'MODE HT' : 'MODE TTC', 'ion-modal', false);
    await this.remplir('ion-modal', a.mode === 'HT' ? 'priceExc' : 'priceInc', a.prix);
    this.ecritures = true;
    try {
      await this.cliquer('AJOUTER', 'ion-modal');
      await this.page.waitForFunction(() => ![...document.querySelectorAll('ion-modal')].some((m) => /Programmer l'article/.test(m.innerText || '')), null, { timeout: 60000 })
        .catch(async () => { throw new EtatPortail('refusee', 'Article refusé par l\'e-SFE : ' + (await this.texte()).match(/[^\n]*(obligatoire|invalide|existe)[^\n]*/i)?.[0] || 'fenêtre restée ouverte'); });
    } finally {
      this.ecritures = false;
    }
    this.log('article programmé :', a.designation, 'groupe', a.groupe);
    return true;
  }

  // ------------------------------------------------------------------ facture

  /** Recherche d'une désignation dans la liste visible ; clique sur « sac + » si demandé. */
  async trouverArticle(designation, ajouter) {
    const p = this.page;
    // L'application garde les pages déjà visitées, cachées : seul le champ visible compte
    const inp = p.locator('ion-input input[placeholder="Chercher"]:visible').first();
    if (await inp.count()) { await inp.fill(designation); await this.cliquer('CHERCHER', null, false); await p.waitForTimeout(2000); }
    return p.evaluate(([d, add]) => {
      const vis = (e) => !!(e.offsetWidth || e.offsetHeight);
      const n = (s) => String(s || '').replace(/\s+/g, ' ').trim().toLowerCase();
      for (const ic of document.querySelectorAll('ion-icon[name="bag-add-outline"], ion-icon[name="remove-circle-outline"]')) {
        if (!vis(ic)) continue;
        const row = ic.closest('ion-row, ion-item, tr');
        if (row && n((row.innerText || '').split('\n')[0]) === n(d)) {
          if (add && ic.getAttribute('name') === 'bag-add-outline') (ic.closest('ion-button, button') || ic).click();
          return true;
        }
      }
      return false;
    }, [designation, !!ajouter]);
  }

  /** L'article est-il programmé sur l'e-SFE ? (écran Articles, recherche ; lecture seule) */
  async articleExiste(designation) {
    await this.menu('ARTICLES');
    await this.attendreTexte(/Gérer les articles programmés/, 60000);
    return this.trouverArticle(designation, false);
  }

  /** Section « 2. Articles » de la facture : recherche puis ajout au panier (icône sac +). */
  async ajouterLigne(l) {
    if (!(await this.trouverArticle(l.designation, true))) {
      throw new EtatPortail('refusee', `Article « ${l.designation} » non programmé sur l'e-SFE (programmez-le, ou activez la programmation automatique).`);
    }
    await this.page.waitForTimeout(1500);
  }

  /** Lignes du panier : [désignation, prix, quantité, montant]. */
  async panier() {
    return this.page.evaluate(() => {
      const vis = (e) => !!(e.offsetWidth || e.offsetHeight);
      const out = [];
      for (const ic of document.querySelectorAll('ion-icon[name="bag-remove-outline"]')) {
        if (!vis(ic)) continue;
        const row = ic.closest('ion-row, ion-item, tr');
        if (row) out.push((row.innerText || '').split('\n').map((s) => s.trim()).filter(Boolean));
      }
      return out;
    });
  }

  /** Quantité (et prix) d'une ligne du panier : menu « trois barres » de la ligne → fenêtre de modification. */
  async ajusterLigne(index, l) {
    const menus = this.page.locator('ion-icon[name="bag-remove-outline"]');
    const row = menus.nth(index).locator('xpath=ancestor::*[self::ion-row or self::ion-item or self::tr][1]');
    const m = row.locator('ion-icon[name="menu-outline"]');
    if (!(await m.count())) throw new EtatPortail('formulaire', 'Modification de ligne du panier introuvable : le portail a peut-être changé.');
    await m.first().click();
    await this.page.waitForTimeout(1500);
    const champs = await this.page.evaluate(() => [...document.querySelectorAll('ion-modal ion-input')].map((e) => e.getAttribute('formcontrolname') || ''));
    const qte = champs.find((c) => /quant/i.test(c));
    const prix = champs.find((c) => /price|prix/i.test(c) && !/exc/i.test(c));
    if (!qte) { await this.page.keyboard.press('Escape'); throw new EtatPortail('formulaire', 'Champ « Quantité » introuvable dans la modification de ligne (champs : ' + champs.join(', ') + ').'); }
    await this.remplir('ion-modal', qte, l.quantite);
    if (prix && l.prix_unitaire != null) await this.remplir('ion-modal', prix, l.prix_unitaire);
    if (!(await this.cliquer('MODIFIER', 'ion-modal', false))) await this.cliquer('AJOUTER', 'ion-modal');
    await this.page.waitForTimeout(1500);
  }

  /**
   * Remplit une facture sur l'e-SFE et vérifie qu'elle correspond à la vente. Ne certifie jamais.
   * @returns {{total: number, repartition: string[], lignes: array}}
   */
  async preparer(f, options) {
    const p = this.page;
    await this.menu('FACTURES');
    if (/Projet de la facture|Continuer à modifier la facture/i.test(await this.texte())) {
      await this.cliquer('NON', null, false); // projet local d'une tentative précédente : jamais repris (la facture est refaite)
      await p.waitForTimeout(2000);
    }
    const t0 = await this.texte();
    if (/enregistrer au moins un nom d'opérateur/i.test(t0) || !/Opérateur actuel/i.test(t0)) {
      throw new EtatPortail('intervention', 'Aucun opérateur enregistré sur l\'e-SFE : ajoutez-en un (Factures → opérateur) puis réessayez.');
    }
    const choisi = await p.evaluate(([type, mode]) => {
      const vis = (e) => !!(e.offsetWidth || e.offsetHeight);
      const rows = [...document.querySelectorAll('ion-row, ion-item, div')].filter((e) => vis(e) && new RegExp('^\\s*' + type + '\\s').test(e.innerText || '') && /PRIX TTC/.test(e.innerText || ''));
      rows.sort((a, b) => a.innerText.length - b.innerText.length);
      const b = rows[0] && [...rows[0].querySelectorAll('ion-button, button')].find((x) => (x.innerText || '').trim().toUpperCase() === 'PRIX ' + mode);
      if (!b) return false;
      b.click();
      return true;
    }, [f.type, f.mode]);
    if (!choisi) throw new EtatPortail('formulaire', `Type de facture ${f.type} (${f.mode}) introuvable : type désactivé dans la configuration de l'e-SFE ?`);
    await this.attendreTexte(new RegExp('Type:\\s*' + f.type + ',\\s*Mode:\\s*' + f.mode), 60000);
    // Le bandeau du type s'affiche avant le formulaire : on attend la liste des articles programmés (icône « sac + »)
    await p.waitForSelector('ion-icon[name="bag-add-outline"]:visible', { timeout: 120000 })
      .catch(() => { throw new EtatPortail('reseau', 'Formulaire de facture non chargé à temps.', true); });
    await p.waitForTimeout(1000);

    if (f.client && f.client.nom) {
      await p.evaluate(() => {
        const h = [...document.querySelectorAll('ion-card, ion-item, div')].filter((e) => /^Informations sur le client/.test((e.innerText || '').trim())).sort((a, b) => a.innerText.length - b.innerText.length)[0];
        const b = h && [...h.querySelectorAll('ion-button')].find((x) => /MODIFIER/i.test(x.innerText));
        if (b) b.click();
      });
      await p.waitForSelector('ion-modal ion-radio-group', { timeout: 20000 });
      await this.cliquer(f.client.type === 'morale' ? 'Personne morale' : 'Personne physique', 'ion-modal');
      await p.waitForTimeout(800);
      if (f.client.nif) await this.remplir('ion-modal', 'tin', f.client.nif);
      await this.remplir('ion-modal', 'name', f.client.nom);
      if (f.client.adresse) await this.remplir('ion-modal', 'address', f.client.adresse);
      if (f.client.contact) await this.remplir('ion-modal', 'contact', f.client.contact);
      await this.cliquer('MODIFIER', 'ion-modal');
      await p.waitForTimeout(1500);
      const err = (await this.texte()).match(/L'NIF[^\n]*/);
      if (err) throw new EtatPortail('refusee', 'Client refusé par l\'e-SFE : ' + err[0]);
    }

    let commentaire = null;
    if (f.commentaire) {
      try {
        await p.evaluate(() => {
          const h = [...document.querySelectorAll('ion-card, ion-item, div')].filter((e) => /^Commentaires/.test((e.innerText || '').trim())).sort((a, b) => a.innerText.length - b.innerText.length)[0];
          const b = h && [...h.querySelectorAll('ion-button')].find((x) => /MODIFIER/i.test(x.innerText));
          if (b) b.click();
        });
        await p.waitForSelector('ion-modal ion-input input:visible, ion-modal ion-textarea textarea:visible', { timeout: 10000 });
        await p.locator('ion-modal ion-input input:visible, ion-modal ion-textarea textarea:visible').first().fill(f.commentaire);
        await this.cliquer('MODIFIER', 'ion-modal');
        await p.waitForTimeout(1000);
        commentaire = f.commentaire;
      } catch (e) {
        await p.keyboard.press('Escape').catch(() => {});
        this.log('commentaire non saisi :', e.message);
      }
    }

    for (let i = 0; i < f.lignes.length; i++) {
      const l = f.lignes[i];
      await this.ajouterLigne(l);
      const lignes = await this.panier();
      const idx = lignes.findIndex((c) => norm(c[0]) === norm(l.designation));
      if (idx < 0) throw new EtatPortail('formulaire', `« ${l.designation} » n'apparaît pas dans le panier après l'ajout.`);
      const [, prix, qte] = lignes[idx].map((x, k) => (k === 0 ? x : nombre(x)));
      if (Math.abs((qte || 0) - l.quantite) > 0.0005 || (prix != null && Math.abs(prix - l.prix_unitaire) > 0.009)) {
        await this.ajusterLigne(idx, l);
      }
    }
    // Contrôle ligne par ligne du panier
    const panier = await this.panier();
    for (const l of f.lignes) {
      const c = panier.find((x) => norm(x[0]) === norm(l.designation));
      const montant = c ? nombre(c[3]) : null;
      if (!c || montant == null || Math.abs(montant - l.montant) > 1) {
        throw new EtatPortail('ecart', `Ligne « ${l.designation} » : montant e-SFE ${montant} ≠ POS Marwa ${l.montant}. Facture non certifiée.`);
      }
    }
    if (f.paiement && f.paiement !== 'ESPECES') {
      const sel = p.locator('ion-select:visible').filter({ hasText: /ESPECES|MOBILE|VIREMENT|CARTE|CHEQUES|AUTRE|Paiement/i });
      const cible = (await sel.count()) ? sel.last() : p.locator('ion-select:visible').last();
      await this.choisirOption(cible, f.paiement);
    }
    const t = await this.texte();
    if (/Erreurs de facturation/i.test(t)) {
      const bloc = t.split(/Erreurs de facturation/i)[1] || '';
      throw new EtatPortail('refusee', 'Erreurs signalées par l\'e-SFE : ' + bloc.split('\n').slice(1, 4).join(' ').trim());
    }
    const tot = t.match(/Total:\s*\n?\s*([0-9\s  .,]+)/);
    const total = tot ? nombre(tot[1]) : null;
    if (total == null || Math.abs(total - f.total) > 1) {
      throw new EtatPortail('ecart', `Total e-SFE ${total} ≠ total POS Marwa ${f.total} : facture non certifiée.`);
    }
    const repartition = (t.match(/^[A-L]\s*-\s*\d+%.*$/gm) || []).map((s) => s.replace(/\s+/g, ' '));
    return { total, repartition, commentaire };
  }

  /** Capture d'écran locale pour le diagnostic (dossier données du pont, jamais transmise). */
  async capture(nom) {
    try {
      const d = path.join(this.cfg.donnees, 'captures');
      fs.mkdirSync(d, { recursive: true, mode: 0o700 });
      const f = path.join(d, new Date().toISOString().replace(/[:.]/g, '-') + '-' + nom + '.png');
      await this.page.screenshot({ path: f, fullPage: true });
      return f;
    } catch (e) { return null; }
  }

  async annuler() {
    if (await this.cliquer('ANNULER', null, false)) {
      await this.page.waitForTimeout(1500);
      await this.cliquer('OUI', null, false);
      await this.page.waitForTimeout(2000);
    }
  }

  /**
   * CERTIFIER (ÉCRITURE fiscale irréversible). « avant » est appelé juste avant le clic pour enregistrer sur disque
   * que la certification part. Ne renvoie « certifiée » que si le Code SECeF/DGI et le NIM sont lus à l'écran.
   */
  async certifier(cle, avant) {
    const p = this.page;
    await avant();
    this.ecritures = true;
    try {
      await this.cliquer('CERTIFIER');
      await p.waitForTimeout(1500);
      await this.cliquer('OUI', 'ion-alert', false) || await this.cliquer('OK', 'ion-alert', false);
      await p.waitForFunction(() => /Facture certifiée|ÉLÉMENTS DE SÉCURITÉ|Code SECeF\/DGI|Une erreur s'est produite|Erreur/i.test(document.body.innerText), null, { timeout: 180000 });
    } finally {
      this.ecritures = false;
    }
    await p.waitForTimeout(2000);
    const t = await this.texte();
    const lire = (re) => { const m = t.match(re); return m ? m[1].trim() : null; };
    const r = {
      code_secef: lire(/Code SECeF\/DGI\s*:?\s*\n?\s*([A-Z0-9-]{8,40})/i),
      nim: lire(/SECeF NIM\s*:?\s*\n?\s*([A-Z0-9-]{6,20})/i),
      compteurs: lire(/SECeF Compteurs\s*:?\s*\n?\s*([^\n]{1,80})/i),
      heure: lire(/SECeF Heure\s*:?\s*\n?\s*([^\n]{4,40})/i),
      isf: lire(/\bISF\s*:?\s*\n?\s*([A-Z0-9-]{4,30})/),
      total: null, qr: null, pdf: null,
    };
    if (!r.code_secef || !r.nim) {
      const err = t.match(/(Une erreur s'est produite[^\n]*|Erreur[^\n]*)/i);
      return { certifiee: false, message: err ? err[0] : 'Éléments de sécurité non trouvés à l\'écran.', resultat: r };
    }
    // PDF officiel (si le portail le propose) : archivé tel quel
    try {
      const [dl] = await Promise.all([p.waitForEvent('download', { timeout: 60000 }), this.cliquer('TÉLÉCHARGER LE PDF')]);
      const f = path.join(this.cfg.pdf, cle + '.pdf');
      await dl.saveAs(f);
      r.pdf = f;
    } catch (e) {
      this.log('PDF non téléchargé :', e.message.split('\n')[0]);
    }
    if (await this.cliquer('FERMER LA FACTURE', null, false)) {
      await p.waitForTimeout(1200);
      await this.cliquer('OUI', null, false);
    }
    return { certifiee: true, resultat: r };
  }
}

module.exports = { Portail, EtatPortail, nombre, norm };
