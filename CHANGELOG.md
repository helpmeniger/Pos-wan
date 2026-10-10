# Journal des modifications

Format : [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/) — versions [SemVer](https://semver.org/lang/fr/).

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
