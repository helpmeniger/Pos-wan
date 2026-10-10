# État du projet — version 1.1.0

## Terminé et vérifié

| Module | État | Vérification |
|---|---|---|
| Installation (web + CLI), migrations | ✅ | Tests automatisés + installation réelle sous Apache sur copie vierge |
| Authentification (mot de passe, PIN, badge), verrouillage, sessions | ✅ | Tests HTTP |
| Rôles et permissions (46), contrôle serveur, rôles personnalisés | ✅ | Tests (403, IDOR) |
| Caisse : recherche, scan, catégories, quantités rapides, conditionnements, gros, promo | ✅ | Tests + navigation Chromium |
| Paiements multiples / mixtes, monnaie, crédit avec plafond, autorisation superviseur | ✅ | Tests |
| Mise en attente / reprise, tickets 58/80 mm, facture A4, réimpression DUPLICATA | ✅ | Tests + rendu |
| Retours (partiels, avoir, remise en stock), annulations contre-passées | ✅ | Tests |
| Articles : CRUD, archivage, photos sécurisées, import/export CSV, étiquettes, EAN internes, prix groupés, historique, doublons | ✅ | Tests |
| Stocks : mouvements, ajustements, transferts, inventaires, lots/FEFO, rappels, traçabilité | ✅ | Tests (dont cohérence stock = Σ mouvements = Σ lots) |
| Achats : commandes, réceptions partielles, frais d'approche, CMP, retours, dettes, paiements | ✅ | Tests |
| Clients : comptes, crédits, règlements, acomptes, reçus, relevés | ✅ | Tests |
| Caisse : ouverture, mouvements, clôture par coupures, écart, état imprimable | ✅ | Tests |
| Dépenses / recettes, contrepassation | ✅ | Tests |
| Tableau de bord et rapports (ventes, articles, bénéfices, stock, créances/dettes, ordonnancier), export CSV | ✅ | Tests + rendu |
| Pharmacie : champs DCI, ordonnance obligatoire, pharmacien, registre | ✅ | Tests |
| Multi-boutiques / multi-entrepôts / multi-caisses | ✅ | Pages testées ; transfert testé |
| Journal d'audit | ✅ | Pages testées |
| **Restaurant** : plan de salle, commandes (sur place / emporter / livraison), bons par poste, écran cuisine, annulations avec perte, transfert/fusion, addition séparée, fiches techniques, rapport | ✅ | 10 tests + scénario complet Chromium (salle → cuisine → paiement) |
| Migration d'une installation existante (0001 → 0002) | ✅ | Exécutée sur une base avec données |
| Sauvegarde, vérification SHA-256, restauration, rotation, sauvegarde CLI planifiable | ✅ | Tests (aller-retour + altération) |
| Protections Apache (.htaccess) en sous-dossier | ✅ | Testé sous Apache 2.4 + mod_php |

**Résultat de la suite de tests : 67 réussis, 0 échec** (`php tests/run.php`).

## Limites connues / non implémenté (à ne pas présenter comme disponible)

| Élément | Situation actuelle | Piste |
|---|---|---|
| Impression ESC/POS directe et ouverture du tiroir | Impression via le pilote Windows et le navigateur (`--kiosk-printing` pour l'impression silencieuse) | Petit service local d'impression ESC/POS |
| Intégration API mobile money (Airtel Money, Moov/Flooz, Zamani Cash…) et TPE | Paiements enregistrés avec la référence de transaction, sans échange avec l'opérateur | Intégration selon les API et contrats des opérateurs |
| Lecture automatique d'une balance électronique | Saisie manuelle du poids (quantités décimales) ; pas de lecture des étiquettes code-barres « prix/poids » | Prise en charge des codes EAN à poids variable (préfixe 2x) |
| Afficheur client (second écran) | Non disponible | Page « afficheur » synchronisée |
| Utilisation des points de fidélité (conversion en remise) | Les points sont cumulés ; leur utilisation se fait par une remise manuelle | Bon de réduction automatique |
| Export Excel natif (.xlsx) et PDF généré par le serveur | CSV compatible Excel ; PDF via « Imprimer → Enregistrer en PDF » | Bibliothèque XLSX / PDF |
| Conversion d'unités générique (kg ↔ g, etc.) | Conditionnements (N unités de base) uniquement | Table de conversions |
| Génération automatique de la grille de variantes | Chaque variante est un article lié à son parent (création par « Dupliquer / variante ») | Assistant taille × couleur |
| Mode hors ligne (coupure du serveur local) | Le panier en cours est conservé dans le navigateur, mais la vente nécessite le serveur | File d'attente locale synchronisée |
| Restaurant : routage des bons vers plusieurs imprimantes réseau (une par poste) | Bon imprimé sur l'imprimante par défaut de l'appareil qui envoie ; écran cuisine recommandé | Service local d'impression par poste |
| Restaurant : modificateurs payants (suppléments), menus/formules à prix fixe, réservations | Notes libres ; suppléments à saisir comme articles | Options d'articles et réservations |
| HTTPS | Documenté (certificat local), non configuré automatiquement | — |
| Conformité réglementaire pharmacie / fiscale (Niger) | Fonctions de traçabilité fournies, **non certifiées** | Validation par un pharmacien et un expert-comptable |
| Factures normalisées / dispositifs fiscaux éventuels exigés par l'administration | Non implémenté | À étudier selon la réglementation en vigueur |

## Prochaines tâches proposées
1. Valider le module pharmacie et les mentions des factures avec des professionnels locaux.
2. Service local ESC/POS (impression rapide + tiroir-caisse).
3. Codes-barres balance (poids/prix variable) et afficheur client.
4. Utilisation des points de fidélité.
5. Export XLSX natif des rapports.
