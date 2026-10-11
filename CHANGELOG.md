# Journal des modifications

Format : [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/) — versions [SemVer](https://semver.org/lang/fr/).

## [1.5.0] — 2026-10-11

### Ajouté — module « Clinique » (`addons/clinique/`)
- Dossiers patients (n° PAT, allergies, antécédents, groupe sanguin, assurance et taux de
  prise en charge), rendez-vous par médecin (chevauchements refusés), file d'attente avec
  urgence et constantes de triage.
- Consultations : observation, examen, diagnostic obligatoire pour clôturer, constantes
  contrôlées (valeurs plausibles), historique.
- **Lien avec la pharmacie** : ordonnance rédigée par le médecin (médicaments du stock ou
  hors stock), imprimée, transmise à la file « Ordonnances → pharmacie » ; la délivrance
  (partielle possible) crée une vente normale (stock, lots FEFO, caisse) et remplit le
  registre des ordonnances.
- Hospitalisation et mise en observation : lits, plan de soins (fréquence, durée),
  transmissions, courbe des constantes, transfert de lit, sortie avec facture automatique.
- **Écran de supervision des malades** (plein écran, actualisé toutes les 20 s) : alertes
  sur les constantes hors seuils (signal sonore), soins en retard / dus, bouton « Fait »,
  constantes à reprendre, lits libres, filtre par service. Seuils réglables.
- Facturation des actes avec tiers payant, encaissement en caisse, rapport CSV / PDF.
- 7 permissions ; 3 rôles créés (médecin, infirmier, accueil). Accès au dossier médical
  réservé aux soignants et journalisé.

### Modifié
- Manifeste des modules : clé `new_roles` (création de rôles à l'installation).

## [1.4.1] — 2026-10-11

### Ajouté — module « Pressing » (`addons/pressing/`)
- Tarifs par article et par service (lavage + repassage, nettoyage à sec, repassage,
  teinture), 18 articles et prix indicatifs en FCFA, modifiables.
- Dépôt tactile : client (rappel des clients connus), articles, couleur / marque / défauts
  constatés, express (supplément et délai), remise contrôlée, acompte en caisse.
- Ticket client avec QR code, étiquettes par pièce (n° à reporter sur les vêtements).
- Suivi : reçu → en cours → prêt → retiré ; retards, linge non retiré, message WhatsApp.
- Retrait : paiement du solde avec monnaie ; remise sans paiement complet réservée au
  responsable (motif) ; annulation avec remboursement en caisse.
- Rapport par période (dépôts, pièces, facturé, encaissé, reste, services, articles,
  modes de paiement) et export CSV / PDF. 4 permissions dédiées.

## [1.4.0] — 2026-10-11

### Ajouté
- **Modules complémentaires (addons)** : déposer le dossier d'un module dans `addons/`,
  puis **Gestion → Modules complémentaires → Installer** (sauvegarde automatique,
  tables, permissions, menu, paramètres, traductions, fichiers publics). Activation /
  désactivation sans perte de données, mise à jour quand la version augmente, commande
  `php bin/addons.php`. Permission `addons.manage` (administrateur général).
- Module d'exemple **Livraisons** (`addons/livraisons/`) : suivi des livraisons à domicile,
  modèle à copier pour créer ses propres modules. Guide développeur : `docs/MODULES.md`.
- **Menu de gestion à droite** (Paramètres → Position du menu : droite par défaut, gauche
  en option ; sur téléphone le menu s'ouvre depuis la droite).
- **Assistance** affichée sur l'écran de connexion : +227 92 08 12 82 / 87 22 52 02
  (numéros cliquables, modifiables dans les paramètres).

### Sécurité
- Le dossier `addons/` n'est jamais servi par Apache (`.htaccess`) ; codes de module
  contrôlés (pas de chemin arbitraire) ; aucun envoi de module par le navigateur.

## [1.3.0] — 2026-10-10

### Ajouté
- **TVA** (Paramètres → TVA) : pas de TVA, **TVA générale** au taux choisi (19 %, 18 %… ou
  libre) avec case « Exonéré » par article, ou taux article par article. Prix saisis TTC ;
  TVA détaillée par taux sur les tickets, factures, devis et tickets ESC/POS.
- **Rapport TVA** (Rapports → TVA) : ventes TTC, retours, base HT et TVA collectée nettes,
  par taux et par mois, avec export CSV et PDF.
- **Export PDF** de tous les rapports et listes exportables (ventes, articles, bénéfices,
  stock, mouvements, péremptions, créances, dépenses, ordonnancier, TVA) : PDF généré par
  POS Wan lui-même, sans Internet ni logiciel supplémentaire (en-tête, totaux, pagination).

### Corrigé — restaurant / écran cuisine
- Les articles d'une catégorie **sans poste de préparation** étaient marqués « servis » et
  n'apparaissaient jamais sur l'écran cuisine : ils vont désormais au **poste par défaut**
  (« cuisine », réglable). Saisir `aucun` comme poste d'une catégorie pour servir sans ticket
  (boissons en bouteille…).
- Une commande **encaissée sans avoir été envoyée** (vente à emporter…) n'arrivait pas en
  cuisine : les articles non envoyés partent maintenant en cuisine à l'encaissement
  (paramètre, activé par défaut) ; un avertissement s'affiche sur l'écran d'encaissement.
- Le bouton **Bon cuisine** réimprime les derniers bons de la commande même s'ils sont déjà
  prêts ou servis (auparavant : message d'erreur).
- Tests automatisés : le serveur PHP de test est arrêté proprement en fin de suite.

### Migration
- `0004_tva_cuisine` (colonne `products.vat_exempt`, paramètres TVA et restaurant). Les
  installations existantes gardent le mode « taux par article » (comportement inchangé).

## [1.2.0] — 2026-10-10

### Ajouté
- **Imprimantes multiples** (Paramètres → Imprimantes) : imprimantes réseau (IP, port 9100) ou
  USB partagées sous Windows, pilotées en ESC/POS par le serveur ; chaque imprimante a ses
  usages (tickets de caisse, bons cuisine / bar / grill, additions) et peut être limitée à une
  caisse (ex. alimentation → imprimante A, restaurant → imprimante B). Page de test, coupe
  papier, ouverture du tiroir-caisse, copies. Repli automatique sur l'impression navigateur.
  Une panne d'imprimante n'annule jamais une vente.
- **Langues** : français, anglais, arabe (affichage de droite à gauche), haoussa, espagnol,
  portugais, turc. Langue par défaut dans les paramètres, choix par utilisateur (Mon compte)
  et sur l'écran de connexion.
- **Devise configurable** (FCFA, naira, cedi, dirham, euro, dollar… ou personnalisée) :
  symbole, position, décimales, nom en toutes lettres sur les factures.
- **Modèles d'impression** : 3 modèles de ticket (Classique, Compact, Moderne) et 3 modèles
  de facture / devis A4 (Classique, Moderne à bandeau, Épuré), avec aperçu.
- **QR code** en bas des tickets, factures, devis et additions (référence et identifiant du
  document, signature HMAC) et page **Vérifier un QR code** qui détecte les documents falsifiés.
- **Devis** : création, suivi (envoyé, accepté, refusé, annulé), impression, duplication,
  **conversion en vente** (prix du devis conservés, stock et caisse contrôlés).
- **Bons de livraison** A4 pour toute vente, avec ou sans les prix.
- **Caisse** : le bouton **Valider** encaisse directement en espèces sans fenêtre (montant
  « Argent client » ou montant exact, monnaie affichée dans la barre) ; le bouton **Espèces**
  ouvre la fenêtre d'encaissement complète (paiement mixte, carte, mobile money). Option
  désactivable dans les paramètres.
- **Mobile money** : liste des opérateurs paramétrable (Airtel Money, Moov Money, Zamani Cash,
  Nita, Amanata…), choix de l'opérateur à l'encaissement, opérateur enregistré sur le paiement.
- **Codes-barres** : bouton « Générer » dans la fiche article, « Créer un code-barres » sur un
  article qui n'en a pas, génération groupée pour tous les articles sans code (+ étiquettes).
- **Restaurant** : articles et catégories réservés au restaurant (ou à la boutique) ;
  commandes affichées sur l'écran cuisine dès l'envoi ; bouton « Bon cuisine » et bouton
  d'impression sur chaque ticket de l'écran cuisine (impression à la demande).
- **Achats** : à la réception, choix du coût par ligne — **prix moyen pondéré** (défaut),
  **nouveau prix** (tout le stock revalorisé) ou **ancien prix** — et nouveau prix de vente
  facultatif (historisé).
- Migration `0003_imprimantes_devis_langues` ; permission `quotes.manage` (accordée aux
  rôles Responsable de magasin et Caissier).

### Modifié
- L'impression automatique des bons cuisine par le navigateur est désactivée par défaut
  (écran cuisine ou imprimante de poste recommandés).

## [1.1.0] — 2026-10-10

### Ajouté — module Restaurant
- Nouveau type d'activité « Restaurant / maquis / café / fast-food » et fonctionnalités
  `restaurant` (plan de salle, commandes, cuisine) et `recipes` (fiches techniques).
- Plan de salle par salle (tables libres / occupées / addition demandée / plats prêts),
  rafraîchi automatiquement ; commandes à emporter et en livraison.
- Prise de commande tactile (même ergonomie que la caisse) : couverts, services
  (boisson, entrée, plat, dessert), notes pour la cuisine, quantités rapides.
- Envoi en cuisine : un bon par poste de préparation (cuisine, grill, bar…) défini sur
  les catégories, impression automatique des bons, articles sans poste servis directement.
- Écran cuisine / bar (KDS) : tickets en temps réel, chronomètre avec alertes de retard,
  étapes « Commencer → Prêt → Servi », rappel d'un ticket, signal sonore.
- Annulation d'un plat envoyé : permission ou autorisation d'un responsable, motif,
  bon d'annulation en cuisine, déclaration de perte (déstockage des ingrédients).
- Transfert de table, fusion de tables, addition (pré-note), paiement total ou
  **addition séparée** (par article ou en parts égales), remise, crédit client.
- Fiches techniques : les ventes déstockent les ingrédients (articles « non vendables »),
  coût matière réel, réintégration en cas d'annulation ou de retour.
- Rapport restaurant : CA, couverts, ticket moyen par couvert, serveurs, plats,
  rotation des tables, temps de préparation, annulations.
- Rôles « Serveur » et « Cuisinier / barman » ; 5 nouvelles permissions.
- Migration `0002_restaurant` : les installations existantes sont mises à jour par
  `php bin/migrate.php` (les nouvelles permissions sont accordées aux rôles système concernés).

### Corrigé
- Retour automatique à l'écran d'origine après l'impression d'un ticket (`next` contrôlé).

## [1.0.0] — 2026-10-10

### Ajouté
- Noyau applicatif PHP sans dépendance : routeur avec permissions, PDO préparé, sessions
  durcies, CSRF, CSP, validation, téléversement sécurisé, journalisation, audit.
- Assistant d'installation web et CLI (`bin/install.php`), migrations (`bin/migrate.php`).
- Caisse plein écran (tactile, clavier, douchette) inspirée de l'ancien logiciel : tuiles de
  catégories/articles, bande de quantité 2–9, panneau ticket sombre « Total / Valider »,
  barre code-barres / argent client / à rendre, paiements mixtes, crédit, remises avec
  autorisation, attente/reprise, impression ticket/facture.
- Écran de connexion avec pavé numérique PIN, identifiant/mot de passe et badge RFID (optionnel).
- « Menu de gestion » à icônes rondes regroupant tous les modules.
- Modules : articles, catégories/marques/unités, stocks, lots/FEFO, transferts, inventaires,
  fournisseurs, commandes, réceptions, retours fournisseurs, clients/crédits, sessions de
  caisse, dépenses/recettes, rapports, tableau de bord, utilisateurs/rôles, paramètres,
  boutiques/entrepôts/caisses, journal d'audit, sauvegardes/restauration.
- Types de commerce : alimentation, boutique, pharmacie, quincaillerie, dépôt de boissons,
  cosmétique, informatique, papeterie, pièces détachées.
- Suite de tests automatisés (57 tests) et documentation complète.
