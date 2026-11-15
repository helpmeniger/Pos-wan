# MARWA AI 3.0 — Audit de l'assistant 2.0 et plan d'amélioration

Document de la phase 1 de la mise à niveau « Advanced Business Intelligence Assistant ». Il décrit l'assistant tel qu'il était
dans le code (version 2.0, POS Marwa 3.9.0), ses limites constatées, et ce qui a été fait pour chacune. L'assistant existant a
été **mis à jour** : pas de second assistant, pas de réécriture, les fonctions 1.x et 2.0 restent en service.

## 1. Ce que l'assistant 2.0 savait faire (constaté)

| Domaine | État en 2.0 |
|---|---|
| Compréhension | Classifieur par exemples (similarité de mots + trigrammes), ~56 intentions de données, 114 questions au banc |
| Fautes | Distance d'édition mot à mot sur le vocabulaire connu ; pas de mots collés, pas de phonétique, peu d'abréviations |
| Données | 56 outils SQL en lecture seule, filtrés par les droits et la boutique de rattachement |
| Contexte | Question de suite sur la période et le lieu ; une demande précédente mémorisée |
| Conversation | Salutations simples ; tout le reste renvoyé vers « je suis un assistant de gestion » |
| Conseils | Module de conseils 1.x (textes généraux), sans lien avec les chiffres de l'entreprise |
| Opérations | 4 opérations préparées puis confirmées (inventaire, brouillon de commande, sortie de caisse, annulation de vente) |
| Apprentissage | Questions non comprises listées ; un administrateur rattache une question à une intention |
| Évaluation | Banc de 114 questions ; pas de mesure séparée des formulations jamais vues |
| Sécurité | Droits contrôlés côté serveur ; aucun SQL généré ; pas de réponse dédiée aux tentatives de manipulation |

## 2. Limites constatées et réponse apportée en 3.0

| Limite constatée | Ce qui a été fait | Fichier |
|---|---|---|
| Fautes multiples, lettres inversées, mots collés, écriture phonétique ou SMS mal compris | Moteur de récupération : abréviations, tournures SMS, clé phonétique, distance de Damerau, découpage des mots collés, élisions ; les noms d'articles et de clients de la base ne sont jamais « corrigés » | `Brain/Recover.php` |
| Tournures naturelles (« fais-moi le point », « ça donne quoi », « où on en est ») ignorées | Table d'expressions idiomatiques + exemples par intention | `Brain/Brain.php`, `Brain/Nlu.php` |
| Une seule demande par question | Découpage en plusieurs demandes (virgules, « et », « puis », « aussi »), sans couper une comparaison | `Brain/Nlu.php` |
| L'assistant devinait parfois au lieu de demander | Issue « clarification » : article ambigu, quantité sans nature d'opération, client homonyme, période absente d'une comparaison. Aucune opération de stock ou d'argent n'est devinée | `Brain/Actions.php`, `Brain/Brain.php` |
| Conseils sans lien avec les données | Moteur expert : constats calculés sur les chiffres réels (ruptures, dormants, marge, créances, écarts de caisse), distingués des conseils généraux | `Brain/Expert.php` |
| Conversation pauvre | Moteur général : salutations, remerciements, humeur, plaisanteries, ~30 notions de gestion expliquées, présentation du logiciel | `Brain/General.php` |
| Suites limitées (« Et hier ? ») | Suites sur la période, le lieu, le nombre (« et les 5 meilleurs ? »), l'article (pronoms), l'exclusion (« retire les produits de la boutique »), singulier / pluriel | `Brain/Brain.php` |
| Pas de « dernière opération » par catégorie | Dernière entrée de stock, mouvements, dernier retrait de caisse, dernières ventes, fiche article / client / fournisseur, règlements : 15 nouveaux outils | `Brain/Expert.php`, `Brain/Catalog.php` |
| Ajout de stock impossible par la conversation | Outil `stock_add` (droit `stock.adjust`), proposé puis exécuté par la fonction métier `StockEntry::quickAdd` après confirmation | `Brain/Actions.php` |
| Aucune réponse aux tentatives de manipulation | Garde-fou : règles ignorées, « modes », secrets, SQL, suppression en masse, opération cachée, effacement de dette, élévation de droits. Il s'ajoute aux contrôles de droits, il ne les remplace pas | `Brain/Guard.php` |
| Évaluation trop petite, sans mesure de généralisation | Bibliothèque de 1 262 scénarios rejouables, formulations « vues » et « jamais vues » comptées à part, jeu « à l'aveugle » | `Brain/Dataset.php`, `Brain/Evaluation.php`, `data/scenarios/` |
| Pas d'outil d'amélioration sans toucher au code | MARWA AI Training Center (11 menus) | `TrainingController.php`, `views/admin/` |

## 3. Ce qui n'a volontairement pas été fait, et pourquoi

- **Pas de « réentraînement » d'un grand modèle de langage.** Ajouter des phrases dans MySQL n'entraîne pas un modèle. Ce qui
  évolue, ce sont les exemples du classifieur, les synonymes et la base de connaissances ; l'interface le dit en toutes lettres.
- **Pas d'apprentissage automatique sur les conversations.** Une formulation ne devient un exemple que si un administrateur la
  valide, et elle est anonymisée avant (téléphones, courriels, montants). Les conversations relèvent de la loi n° 2017-28 sur la
  protection des données à caractère personnel : durée de conservation réglable, suppression par l'utilisateur.
- **Pas d'embeddings neuronaux ni de recherche vectorielle.** La similarité est lexicale (mots et trigrammes pondérés). C'est
  suffisant hors ligne et rapide (quelques millisecondes), mais une tournure sans aucun mot commun avec les exemples n'est pas
  reconnue. Un modèle de langage peut être branché en complément (réglage existant) ; son apport n'a pas été mesuré.
- **Pas de module de tickets Wi-Fi ni d'abonnements.** Il n'existe pas dans POS Marwa. Le domaine « tickets / abonnements » du
  cahier des charges a été adapté aux tickets de caisse, documents et paiements électroniques ; une question sur les tickets
  Wi-Fi reçoit une réponse qui le dit, sans chiffre inventé.
- **Pas de SQL généré par l'assistant.** Toutes les lectures passent par des outils écrits à la main, soumis aux droits.

## 4. Les dix phases et leur validation

| Phase | Contenu | Validation |
|---|---|---|
| 1 | Audit (ce document) | relecture du code 2.0 |
| 2 | Récupération des fautes | scénarios « fautes » (153) |
| 3 | Intentions, multi-demandes, clarification | scénarios par domaine |
| 4 | Conversation générale et notions | scénarios « conversation », « curiosités » |
| 5 | Conseils calculés | scénarios « analyse et conseils », chiffres recalculés en SQL |
| 6 | Contexte | scénarios à contexte (`ctx=`) |
| 7 | Opérations et garde-fou | tests d'ajout de stock confirmé, refus par rôle |
| 8 | Bibliothèque et banc d'évaluation | 1 262 scénarios, 3 664 formulations rejouées |
| 9 | Training Center | tests HTTP des 11 menus |
| 10 | Documentation, rapport de performance, livraison | suite complète |

Les résultats chiffrés sont dans `06_RAPPORT_DE_PERFORMANCE.md`.
