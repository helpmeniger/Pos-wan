# MARWA AI 2.0 — Rapport de tests

Date : 12/11/2026 · POS Marwa 3.9.0 · module `marwa_ia` 2.0.0 · PHP 8 + MariaDB, base de test recréée à chaque passage.

## 1. Résultat global

| Série | Résultat |
|---|---|
| Suite automatisée complète de POS Marwa (`php tests/run.php`, tous modules) | **187 / 187 réussis** (dont 6 séries MARWA AI et les 4 séries MARWA IA 1.x conservées) |
| Banc de questions réelles MARWA AI | **114 / 114** (intention attendue + chiffres attendus présents + chiffres interdits absents) |
| Vérification dans Chromium (Playwright), PC 1366 px et téléphone 390 px | **36 / 36** |

Les chiffres attendus du banc sont **recalculés dans le test, directement en SQL** (valeur du stock, ventes du jour,
d'hier, d'un caissier, du mois, créances clients, dettes fournisseurs, ruptures, stock par magasin, dernière vente),
sans passer par le code de MARWA AI. Questions posées par : admin (104), caissier (7), magasin (1), gerant (2).

## 2. Catégories couvertes par le banc

| Catégorie | Exemples | Vérifié |
|---|---|---|
| Simples (51) | « Quelle est la valeur de mon stock ? », « Qui me doit de l'argent ? », « Dernière vente » | intention + montant exact |
| Fautes d'orthographe (7) | « valeur du stok », « combien jai vendu ojourdui », « qui me doi de largent » | intention + montant exact |
| Synonymes / formulations libres (8) | « Mon stock représente combien d'argent ? », « Combien de tickets ont été émis aujourd'hui ? » | intention + montant |
| Périodes (7) | hier, semaine dernière, mois dernier, année, « du 1er au 5 janvier 2001 », « année 1999 » | période respectée ; « aucune vente » quand il n'y en a pas, jamais les chiffres d'une autre période |
| Questions successives (7) | « Ventes d'aujourd'hui » → « et hier ? » → « fais la comparaison » → « et les dépenses ? » ; « stock du dépôt » → « et dans le magasin principal ? » ; question hors sujet au milieu | contexte repris ou abandonné à bon escient |
| Plusieurs demandes (3) | « Combien de ventes aujourd'hui ? Et qui me doit de l'argent ? » | les deux réponses, chacune chiffrée |
| Ambiguës / données absentes (3) | « Combien ? », article « Zorglub », client inconnu | demande de précision, aucun chiffre deviné |
| Hors sujet (2, + 1 au milieu d'une suite) | blague, météo ; « président du Niger » dans une conversation en cours | refus poli, aucun chiffre |
| Définitions, aide pas à pas, capacités, module absent (8) | brut / net, TVA, types de transactions, « comment faire un inventaire ? », tickets Wi-Fi | réponse documentée ; « aucun module de tickets Wi-Fi » |
| Actions (3) | inventaire, suppression d'article (refus expliqué), navigation | proposition seulement, rien n'est exécuté |
| Rapports 1.x (5) | « Ventes d'hier en PDF », plan marketing, formation, contrôle de configuration, « ventes de 1999 en PDF » | moteur 1.x conservé ; refus si la période ne peut pas être appliquée |
| Droits limités / non autorisés (10) | caissier : bénéfice, valeur du stock, ventes de l'admin, sortie de caisse, annulation ; gestionnaire de stock : chiffre d'affaires ; responsable : utilisateurs | refus poli **et** absence du chiffre protégé dans la réponse |

## 3. Autres séries automatisées MARWA AI

- **Migration et connaissance automatique** : 13 tables `ai_*`, tables 1.x conservées, script de retour arrière ;
  inventaire découvert (modules, menus, routes dont `POST /ia/v2/stream`, droits, tables, noms de paramètres,
  documentation) ; une nouvelle table créée pendant le test est connue automatiquement ; valeurs des paramètres
  jamais indexées ; réglage personnalisé conservé après réinstallation.
- **Mémoire** : un autre utilisateur ne peut ni lire, ni poursuivre, ni noter, ni supprimer une conversation ;
  contexte mémorisé ; purge selon la durée de conservation ; suppression ; journal anonymisé (téléphone, courriel) ;
  correction validée par l'administrateur → la formulation est comprise ensuite ; banc intégré de l'écran Pilotage ≥ 90 %.
- **Actions** : rien avant confirmation ; jeton faux, autre utilisateur, double exécution, délai dépassé refusés ;
  inventaire et brouillon de commande créés par les fonctions métier ; sortie de caisse : mot de passe exigé,
  mauvais mot de passe refusé, aucun mouvement sans authentification, 1 500 F enregistrés après authentification ;
  annulation ; journal et audit.
- **Modèles de langage** (contre une **fausse API locale** qui reproduit les formats de flux) : OpenAI, Claude,
  Gemini, Ollama lus morceau par morceau ; en-têtes et paramètres (version d'API Anthropic, effort, repli serveur,
  aucune température) ; jetons et coût consignés ; clé chiffrée en base ; HTTP refusé pour un fournisseur externe ;
  fournisseur externe non activable sans accord ; Ollama sur Internet refusé ; bascule vers le modèle de secours ;
  limite par utilisateur ; aide rédigée en continu sans envoi de la base (< 20 Ko, aucun mot de passe ni téléphone
  client) ; reformulation acceptée avec les mêmes chiffres ; garde-fou : chiffre inventé ou modifié rejeté.
- **HTTP** : page, flux `text/event-stream` (meta → text/block → done), suite dans la conversation, CSRF exigé,
  isolation entre utilisateurs, action confirmée par HTTP, bulle sur le même moteur, écrans d'administration réservés
  (403 pour le caissier), clé d'API jamais réaffichée, fiche de connaissance avec `<script>` échappée, exemples,
  durée de conservation validée, « effacer toutes mes conversations » sans toucher celles des autres.

## 4. Vérification dans le navigateur (Chromium)

PC et téléphone : indicateur de travail pendant la réponse, cartes, graphique, sources, question de suite,
deux tableaux pour deux demandes, proposition d'action avec Confirmer / Annuler, pas de défilement horizontal
sur téléphone, aucune erreur JavaScript. PC : bouton Copier (presse-papiers vérifié), liste des conversations,
conversation rechargée depuis la mémoire, nouvelle conversation, aide pas à pas, 6 écrans d'administration,
banc lancé depuis l'écran, bulle sur une autre page.

Défauts trouvés pendant cette vérification et corrigés : liste des conversations impossible à refermer sur
téléphone ; alerte « nouvelle commande en ligne » qui masquait la bulle puis le champ de saisie (déplacée en haut) ;
champ de saisie masqué par le bouton flottant du module voix sur téléphone.

## 5. Défauts trouvés par le banc pendant le développement (corrigés)

- question hors sujet traitée comme une suite de la question précédente ;
- « Ventes de l'année 1999 » répondue avec l'année en cours, et repli vers le moteur 1.x qui répondait sur
  « aujourd'hui » : une réponse sur une autre période que celle demandée est désormais impossible ;
- « Qui a effectué la dernière sortie de caisse ? » pris pour une demande de sortie de caisse ;
- « Remises et TVA », « Recettes et dépenses », « Ventes du mois dernier » rattachées à la mauvaise intention ;
- « Ventes de l'admin » demandée par un caissier : ses propres ventes étaient données sans le dire (refus explicite) ;
- mots numériques du vocabulaire provoquant une erreur ; paramètres de route typés à tort ; mot réservé SQL
  `sensitive` dans la migration.

## 6. Ce qui n'a PAS été testé

- **Aucun vrai compte** OpenAI, Anthropic, Google ni serveur Ollama réel : seulement la fausse API locale. Les formats
  suivent la documentation publique ; un essai avec une vraie clé est nécessaire avant la mise en service.
- Le streaming derrière Apache / Nginx de production (un tampon de proxy peut faire arriver le texte en un bloc).
- Charge (nombreux utilisateurs simultanés) et très grosses bases.
- Lecture par des utilisateurs réels : la compréhension est mesurée sur 114 formulations écrites pour le test,
  pas sur des questions de clients.

## Annexe — détail des 114 questions (sortie du test)

| # | Utilisateur | Question | Attendu | Obtenu | Résultat | Début de la réponse |
|---|---|---|---|---|---|---|
| 1 | admin | Quelle est la valeur de mon stock ? | stock_value | stock_value | OK | Après analyse des quantités disponibles et des coûts d'achat moyens, le stock (tous magasins confondus) est évalué à 2 288 571,22 F. |
| 2 | admin | Valeur du stock au prix de vente | stock_value_sale | stock_value_sale | OK | Au prix de vente, le stock (tous magasins confondus) vaut 2 991 800 F. |
| 3 | admin | Combien d'articles en stock ? | stock_count | stock_count | OK | 24 articles sont disponibles (tous magasins confondus), pour 1 435,5 unités au total. |
| 4 | admin | Quels produits sont en rupture ? | stock_out | stock_out | OK | 3 articles sont en rupture (tous magasins confondus) : Article concurrence, Article sans code, Café moulu 500 g. |
| 5 | admin | Quels produits ont un stock faible ? | stock_low | stock_low | OK | Stock faible (tous magasins confondus) : 1 article à surveiller, dont Lait en poudre 400 g. |
| 6 | admin | Qui me doit de l'argent ? | customer_debts | customer_debts | OK | 2 clients vous doivent au total 32 000 F. Le plus gros encours : Moussa Garba (26 000 F). |
| 7 | admin | Quels fournisseurs doivent être payés ? | supplier_payables | supplier_payables | OK | Vous devez 71 600 F à 2 fournisseurs, dont Fournisseur test (63 400 F). |
| 8 | admin | Combien de ventes aujourd'hui ? | sales_summary | sales_summary | OK | Vous avez vendu pour 8 400 F aujourd'hui, en 10 ventes. |
| 9 | admin | Chiffre d'affaires du mois | revenue | revenue | OK | Ce mois-ci : 197 400 F de ventes TTC (52 ventes), 1 250 F de retours, net 196 150 F. |
| 10 | admin | Bénéfice du mois | profit | profit | OK | Ce mois-ci : ventes nettes 196 150 F, coût des marchandises vendues 139 369,55 F, marge 56 780,45 F, dépenses 0 F → bénéfice estimé 56 780,4 |
| 11 | admin | Dépenses du mois | expenses_total | expenses_total | OK | Aucune dépense enregistrée ce mois-ci. |
| 12 | admin | Dernière vente | last_sale | last_sale | OK | Dernière vente : V2026-000055 — 12/11/2026 13:24, Caissier, 1 800 F. |
| 13 | admin | Les 5 dernières ventes | last_sales | last_sales | OK | Voici les 5 dernières ventes ; la plus récente : V2026-000055 (12/11/2026 13:24, 1 800 F). |
| 14 | admin | Meilleures ventes du mois | top_products | top_products | OK | Classement des ventes ce mois-ci : 1. Savon de ménage (19), 2. Oignons (au kg) (14), 3. Eau minérale 1,5 L (Pack de 6) (12), 4. Sac de riz 2 |
| 15 | admin | Ventes par mode de paiement ce mois | sales_by_method | sales_by_method | OK | Encaissements par mode de paiement ce mois-ci : Espèces : 143 800 F (47) ; Crédit client : 36 000 F (2) ; Mobile money : 16 100 F (4) ; Cart |
| 16 | admin | Ventes par vendeur ce mois | sales_by_user | sales_by_user | OK | Ventes par utilisateur ce mois-ci : Caissier : 134 550 F (35) ; Gerant : 54 150 F (10) ; Pharma : 8 300 F (4) ; Admin Test : 400 F (3). |
| 17 | admin | Ventes par catégorie | sales_by_category | sales_by_category | OK | Ventes par catégorie ce mois-ci : Alimentation : 113 250 F (8) ; Sans catégorie : 50 600 F (17) ; Vrac : 10 650 F (11) ; Hygiène : 9 050 F ( |
| 18 | admin | Heure de pointe | sales_by_hour | sales_by_hour | OK | L'heure de plus forte affluence sur les 30 derniers jours est 13 h – 14 h (49 ventes). |
| 19 | admin | Ventes annulées ce mois | cancelled_sales | cancelled_sales | OK | 3 ventes ont été annulées ce mois-ci, pour 11 650 F. |
| 20 | admin | Retours du mois | returns | returns | OK | 3 retours client ce mois-ci, pour 1 250 F remboursés ou portés en avoir. |
| 21 | admin | Remises et TVA du mois | discounts_tax | discounts_tax | OK | Ce mois-ci : montant brut 200 700 F, remises 3 300 F, total TTC 197 400 F dont 319,33 F de TVA. |
| 22 | admin | Solde de la caisse | cash_balance | cash_balance | OK | Espèces attendues en caisse : 200 425 F (fond de caisse + entrées − sorties en espèces). |
| 23 | admin | Entrées de caisse aujourd'hui | cash_in | cash_in | OK | Entrées aujourd'hui : 127 000 F au total, dont 125 500 F en espèces. |
| 24 | admin | Sorties de caisse aujourd'hui | cash_out | cash_out | OK | Sorties aujourd'hui : 12 200 F au total, dont 6 200 F en espèces. |
| 25 | admin | Écarts de caisse du mois | cash_difference | cash_difference | OK | À la dernière clôture (Caisse nuit, 12/11/2026 13:24), le solde théorique était de 1 000 F et le compté de 1 000 F, soit un écart de 0 F. Su |
| 26 | admin | Qui a effectué la dernière sortie de caisse ? | last_cash_out | last_cash_out | OK | La dernière sortie d'espèces (annulation de vente) a été faite par Admin Test le 12/11/2026 13:24 sur la caisse Caisse principale : 600 F —  |
| 27 | admin | Combien de clients ? | customers_count | customers_count | OK | 5 clients actifs sont enregistrés, dont 5 créés ce mois-ci et 2 avec une dette en cours. |
| 28 | admin | Meilleur client | top_customer | top_customer | OK | Moussa Garba est votre meilleur client sur les 12 derniers mois : 26 000 F d'achats en 1 passages. |
| 29 | admin | Achats du mois | purchases_total | purchases_total | OK | Achats ce mois-ci : 78 600 F en 6 réceptions de marchandise. |
| 30 | admin | Commandes fournisseur en cours | purchase_orders | purchase_orders | OK | Aucune commande fournisseur en cours. |
| 31 | admin | Articles qui ne se vendent pas | dormant | dormant | OK | 13 articles en stock (tous magasins confondus) n'ont pas été vendus depuis 30 jours, dont Huile végétale 5 L, Ciment test 50 kg, Sucre en po |
| 32 | admin | Produits qui périment bientôt | expiring | expiring | OK | 13 lots périment dans les 60 jours, dont 1 déjà périmé. Le premier : Yaourt test le 10/11/2026. |
| 33 | admin | Transferts de stock du mois | transfers | transfers | OK | 1 transfert de stock ce mois-ci ; le dernier (TR2026-000001) de Magasin principal vers Dépôt, le 12/11/2026 13:23. |
| 34 | admin | Ajustements de stock | adjustments | adjustments | OK | 7 ajustements de stock (corrections, pertes, inventaires) les 30 derniers jours. |
| 35 | admin | Inventaires en cours | inventories | inventories | OK | Aucun inventaire en cours. Le dernier inventaire validé (INV2026-000002, Magasin principal) date du 12/11/2026 13:23. |
| 36 | admin | Meilleur jour de vente du mois | best_day | best_day | OK | Record sur ce mois-ci : le 11/11/2026, 188 600 F encaissés en 39 ventes. |
| 37 | admin | Produits les plus rentables | most_profitable | most_profitable | OK | Sur les 90 derniers jours, les articles qui rapportent le plus de marge sont : Sac de riz 25 kg (23 250 F), Sac en pagne (14 000 F), Poulet  |
| 38 | admin | Combien d'utilisateurs ? | users_count | users_count | OK | 16 utilisateurs actifs : Cuisinier / barman 2, Caissier 2, Responsable de magasin 1, Accueil / caisse (clinique) 1, Auditeur (lecture seule) |
| 39 | admin | Qui suis-je ? | whoami | whoami | OK | Vous êtes connecté en tant que Admin Test (Administrateur général), boutique Boutique Centre, caisse Caisse principale. Je consulte pour vou |
| 40 | admin | Paiements électroniques du mois | epay_summary | epay_summary | OK | Paiements électroniques réels ce mois-ci : 4 transactions, 2 réussies pour 18 500 F, 0 refusées, 1 en attente ou à vérifier ; 6 000 F rembou |
| 41 | admin | Factures certifiées du mois | fiscal_summary | fiscal_summary | OK | Certifications fiscales ce mois-ci : 0 certifiées en production, 15 en test ou simulation (sans valeur fiscale), 1 en attente, 5 refusées ou |
| 42 | admin | Recettes et dépenses du mois | income_vs_expenses | income_vs_expenses | OK | Ce mois-ci : recettes 196 150 F (ventes nettes 196 150 F) contre 0 F de dépenses, soit 196 150 F d'écart. Les achats de marchandises (78 600 |
| 43 | admin | Résumé de l'activité de la semaine | activity_summary | activity_summary | OK | Bilan cette semaine : 52 ventes pour 197 400 F, 1 250 F de retours, 3 annulations ; marge 56 780,45 F, dépenses 0 F, bénéfice estimé 56 780, |
| 44 | admin | Fais-moi un bilan de la journée | daily_report | daily_report | OK | Bilan de la journée : 10 ventes pour 8 400 F (−95,5 % par rapport à hier), 600 F de retours, 1 annulation ; marge 2 600 F, dépenses 0 F, bén |
| 45 | admin | Compare les boutiques | compare_stores | compare_stores | OK | Une seule boutique a des ventes ce mois-ci dans votre périmètre : Boutique Centre (197 400 F). |
| 46 | admin | Dernières transactions | last_transactions | last_transactions | OK | Voici les 10 dernières opérations de votre périmètre ; la plus récente : Vente V2026-000055 le 12/11/2026 13:24. |
| 47 | admin | Quel caissier a réalisé le plus de ventes ? | top_cashier | top_cashier | OK | Caissier est le meilleur vendeur ce mois-ci avec 134 550 F en 35 ventes. |
| 48 | admin | Ventes du mois par boutique | sales_by_store | sales_by_store | OK | Ventes par boutique ce mois-ci : Boutique Centre : 197 400 F (52). |
| 49 | admin | Quels produits dois-je recommander ? | reorder | reorder | OK | Je vous conseille de recommander 2 articles (tous magasins confondus). En priorité : Article concurrence (5), Lait en poudre 400 g (14). |
| 50 | admin | Identifie les anomalies dans les transactions | anomalies | anomalies | OK | J'ai relevé 12 points à vérifier les 30 derniers jours. Ce sont des écarts par rapport aux habitudes ou aux règles de gestion, pas des preuv |
| 51 | admin | stock du savon | product_stock | product_stock | OK | Il reste 104 unité(s) de « Savon de ménage » (Magasin principal : 104). |
| 52 | admin | valeur du stok | stock_value | stock_value | OK | Après analyse des quantités disponibles et des coûts d'achat moyens, le stock (tous magasins confondus) est évalué à 2 288 571,22 F. |
| 53 | admin | combien jai vendu ojourdui | sales_summary | sales_summary | OK | Aujourd'hui, 10 ventes ont été réalisées pour un total de 8 400 F. |
| 54 | admin | produit en ruptur | stock_out | stock_out | OK | Attention, 3 articles n'ont plus de stock (tous magasins confondus). Les premiers : Article concurrence, Article sans code, Café moulu 500 g |
| 55 | admin | qui me doi de largent | customer_debts | customer_debts | OK | Créances clients : 32 000 F réparties sur 2 clients. |
| 56 | admin | benefise du mois | profit | profit | OK | Ce mois-ci : ventes nettes 196 150 F, coût des marchandises vendues 139 369,55 F, marge 56 780,45 F, dépenses 0 F → bénéfice estimé 56 780,4 |
| 57 | admin | chifre daffaire du mois | revenue | revenue | OK | Le chiffre d'affaires ce mois-ci est de 197 400 F TTC, soit 196 150 F net après 1 250 F de retours. |
| 58 | admin | depence du mois | expenses_total | expenses_total | OK | Aucune dépense enregistrée ce mois-ci. |
| 59 | admin | Combien valent les articles actuellement en magasin ? | stock_value | stock_value | OK | Le stock (tous magasins confondus) représente 2,29 millions F, pour 24 articles différents en stock. |
| 60 | admin | Mon stock représente combien d'argent ? | stock_value | stock_value | OK | Après analyse des quantités disponibles et des coûts d'achat moyens, le stock (tous magasins confondus) est évalué à 2 288 571,22 F. |
| 61 | admin | Combien de tickets ont été émis aujourd'hui ? | sales_summary | sales_summary | OK | Aujourd'hui, 10 ventes ont été réalisées pour un total de 8 400 F. |
| 62 | admin | produits épuisés | stock_out | stock_out | OK | Attention, 3 articles n'ont plus de stock (tous magasins confondus). Les premiers : Article concurrence, Article sans code, Café moulu 500 g |
| 63 | admin | est-ce que je gagne de l'argent | profit | profit | OK | Le bénéfice estimé ce mois-ci est de 56 780,45 F : marge sur ventes 56 780,45 F (28,9 %), moins 0 F de dépenses. |
| 64 | admin | Quelles sont mes créances clients ? | customer_debts | customer_debts | OK | Créances clients : 32 000 F réparties sur 2 clients. |
| 65 | admin | mon CA du mois | revenue | revenue | OK | Ce mois-ci : 197 400 F de ventes TTC (52 ventes), 1 250 F de retours, net 196 150 F. |
| 66 | admin | Total encaissé aujourd'hui | sales_summary | sales_summary | OK | Aujourd'hui, 10 ventes ont été réalisées pour un total de 8 400 F. |
| 67 | admin | Ventes d'hier | sales_summary | sales_summary | OK | Ventes hier : 39 tickets, 188 600 F encaissés, soit un panier moyen de 4 835,90 F. |
| 68 | admin | Ventes de la semaine dernière | sales_summary | sales_summary | OK | Aucune vente enregistrée la semaine dernière. |
| 69 | admin | Ventes du mois dernier | sales_summary | sales_summary | OK | Aucune vente enregistrée le mois dernier. |
| 70 | admin | Ventes de l'année | sales_summary | sales_summary | OK | Vous avez vendu pour 197 400 F cette année, en 52 ventes. |
| 71 | admin | Ventes du 1er au 5 janvier 2001 | sales_summary | sales_summary | OK | Aucune vente enregistrée du 01/01/2001 au 05/01/2001. |
| 72 | admin | Ventes de l'année 1999 | sales_summary | sales_summary | OK | Aucune vente enregistrée en 1999. |
| 73 | admin | Ventes de lannée 1999 | sales_summary | sales_summary | OK | Aucune vente enregistrée en 1999. |
| 74 | admin | Ventes d'aujourd'hui | sales_summary | sales_summary | OK | Ventes aujourd'hui : 10 tickets, 8 400 F encaissés, soit un panier moyen de 840 F. |
| 75 | admin | et hier ? | sales_summary | sales_summary | OK | Vous avez vendu pour 188 600 F hier, en 39 ventes. |
| 76 | admin | fais la comparaison | sales_compare | sales_compare | OK | Les ventes sont en baisse : 8 400 F (10 ventes) aujourd'hui contre 188 600 F (39 ventes) hier, soit −180 200 F (-95,5 %). |
| 77 | admin | et les dépenses ? | expenses_total | expenses_total | OK | Aucune dépense enregistrée aujourd'hui. |
| 78 | admin | Valeur du stock du dépôt | stock_value | stock_value | OK | Après analyse des quantités disponibles et des coûts d'achat moyens, le stock (le magasin « Dépôt ») est évalué à 66 000 F. |
| 79 | admin | et dans le magasin principal ? | stock_value | stock_value | OK | Après analyse des quantités disponibles et des coûts d'achat moyens, le stock (le magasin « Magasin principal ») est évalué à 2 222 571,22 F |
| 80 | admin | Qui est le président du Niger ? | — | — | OK | Je suis spécialisé dans la gestion de votre commerce et l'utilisation de POS Marwa : cette question semble hors de ce domaine, ou je ne l'ai |
| 81 | admin | Donne-moi la valeur du stock et les produits en rupture | stock_out | stock_out | OK | Le stock (tous magasins confondus) représente 2,29 millions F, pour 24 articles différents en stock. Attention, 3 articles n'ont plus de sto |
| 82 | admin | Combien de ventes aujourd'hui ? Et qui me doit de l'argent ? | customer_debts | customer_debts | OK | Aujourd'hui, 10 ventes ont été réalisées pour un total de 8 400 F. 2 clients vous doivent au total 32 000 F. Le plus gros encours : Moussa G |
| 83 | admin | ventes d'hier puis dépenses du mois | expenses_total | expenses_total | OK | Ventes hier : 39 tickets, 188 600 F encaissés, soit un panier moyen de 4 835,90 F. Aucune dépense enregistrée ce mois-ci. |
| 84 | admin | Combien ? | — | — | OK | Combien de quoi ? Précisez par exemple : « combien de ventes aujourd'hui ? », « combien vaut mon stock ? », « combien me doivent les clients |
| 85 | admin | Quel est le stock de l'article Zorglub ? | product_stock | product_stock | OK | De quel article voulez-vous connaître le stock ? Donnez son nom. |
| 86 | admin | Rapport du client Inconnu Xyzzy | legacy | legacy | OK | Je n'ai trouvé aucun client correspondant à « inconnu xyzzy ». Vérifiez l'orthographe ou le numéro de téléphone. |
| 87 | admin | Raconte-moi une blague | legacy | legacy | OK | Ce sujet sort de mon domaine. Je suis MARWA IA , l'assistant de gestion de votre alimentation / supermarché : je reste concentré sur votre a |
| 88 | admin | Quelle est la météo demain ? | legacy | legacy | OK | Ce sujet sort de mon domaine. Je suis MARWA IA , l'assistant de gestion de votre alimentation / supermarché : je reste concentré sur votre a |
| 89 | admin | C'est quoi la différence entre le brut et le net ? | definition | definition | OK | Montant brut : Somme des lignes au prix de vente avant remise (sales.subtotal).Montant net (chiffre d'affaires net) : Ventes TTC non annulée |
| 90 | admin | Que veut dire TVA ? | definition | definition | OK | TVA : Taxe comprise dans le prix TTC (sales.tax_total, détail par taux dans sale_items). |
| 91 | admin | Quels sont les types de transactions ? | transaction_types | transaction_types | OK | Voici les types de mouvements que POS Marwa enregistre, avec leurs effets sur le stock, la caisse et les comptes. |
| 92 | admin | Comment faire un inventaire ? | help | help | OK | Comment faire un inventaire ? — Gestion des articles → Inventaires → Démarrer (entrepôt, catégorie facultative). Comptez par scan (+1 par le |
| 93 | admin | Comment clôturer la caisse ? | help | help | OK | Comment clôturer la caisse ? — Saisissez d'abord les dépenses de la journée.Ma caisse : le résumé calcule les espèces théoriques.Comptez par |
| 94 | admin | Comment créer un article ? | help | help | OK | Comment créer un article ? — Gestion des articles → Articles → Nouveau : nom, code-barres (ou Générer), catégorie, prix de vente TTC, prix d |
| 95 | admin | Que sais-tu faire ? | capabilities | capabilities | OK | Je suis MARWA AI, l'assistant de POS Marwa. Je réponds à partir de vos données, avec les droits de votre compte (Administrateur général, tou |
| 96 | admin | Combien de tickets wifi sont expirés ? | wifi_tickets | wifi_tickets | OK | POS Marwa ne comporte pas de module de tickets Wi-Fi (Marwa Connect) : il n'y a donc aucun ticket Wi-Fi généré, utilisé ou expiré à compter. |
| 97 | admin | Prépare un inventaire du dépôt | action_inventory_start | action_inventory_start | OK | Je peux préparer cette opération : ouvrir un inventaire du magasin « Dépôt » (tous les articles). Le stock ne changera qu'à sa validation. R |
| 98 | admin | Supprime l'article Savon | action_unsupported | action_unsupported | OK | Je ne supprime pas d'article : dans POS Marwa un article utilisé s'archive depuis sa fiche (Gestion des articles → Articles), pour garder l' |
| 99 | admin | Ouvre les inventaires | action_navigate | action_navigate | OK | Voici la page « Inventaires ». |
| 100 | admin | Ventes d'hier en PDF | legacy | legacy | OK | Ventes Journée du 11/11/2026 39 vente(s) hier pour un total de 188 600 F , soit un panier moyen de 4 835,90 F. 2 vente(s) annulée(s) non com |
| 101 | admin | Ventes de 1999 en PDF | legacy:ventes | legacy:ventes | OK | Je n'ai pas pu appliquer la période « en 1999 » à ce rapport : pour ne pas vous donner les chiffres d'une autre période, je préfère m'arrête |
| 102 | admin | Mon plan marketing en PDF | legacy | legacy | OK | Plan marketing — Test SARL Votre plan marketing personnalisé Je l'ai préparé pour votre alimentation / supermarché avec vos propres chiffres |
| 103 | admin | Ma formation | legacy | legacy | OK | Votre formation POS Marwa Choisissez une leçon : je vous explique pas à pas, puis un petit quiz de 3 questions vérifie que tout est clair. P |
| 104 | admin | Vérifie ma configuration | legacy | legacy | OK | Contrôle de votre configuration 70 % 16 point(s) sur 23 en ordre À faire tout de suite 1 lot(s) périmé(s) encore en stock : retirez-les de l |
| 105 | caissier | Combien j'ai vendu aujourd'hui ? | sales_summary | sales_summary | OK | Vous avez vendu pour 8 400 F aujourd'hui (vos ventes), en 10 ventes. |
| 106 | caissier | Ventes de l'admin aujourd'hui | sales_summary | sales_summary | OK | Je ne peux vous montrer que vos propres ventes : votre rôle ne donne pas accès aux ventes de Admin Test. |
| 107 | caissier | Quel est le bénéfice du mois ? | profit | profit | OK | Je ne peux pas vous donner « bénéfice estimé » : votre rôle (Caissier) n'a pas accès à ces données. Un responsable peut vous les communiquer |
| 108 | caissier | Valeur du stock | stock_value | stock_value | OK | Je ne peux pas vous donner « valeur du stock (au coût d'achat) » : votre rôle (Caissier) n'a pas accès à ces données. Un responsable peut vo |
| 109 | caissier | Combien d'utilisateurs ? | users_count | users_count | OK | Je ne peux pas vous donner « utilisateurs du logiciel » : votre rôle (Caissier) n'a pas accès à ces données. Un responsable peut vous les co |
| 110 | caissier | Fais une sortie de caisse de 500 pour le pain | action_cash_out | action_cash_out | OK | Je ne peux pas préparer « Sortie de caisse » : votre rôle n'a pas ce droit. Demandez à un responsable. |
| 111 | caissier | Annule la vente V2026-000001 pour erreur | action_sale_cancel | action_sale_cancel | OK | Je ne peux pas préparer « Annuler une vente » : votre rôle n'a pas ce droit. Demandez à un responsable. |
| 112 | magasin | Chiffre d'affaires du mois | revenue | revenue | OK | Je ne peux pas vous donner « chiffre d'affaires » : votre rôle (Gestionnaire de stock) n'a pas accès à ces données. Un responsable peut vous |
| 113 | gerant | Bénéfice du mois | profit | profit | OK | Ce mois-ci : ventes nettes 196 150 F, coût des marchandises vendues 139 369,55 F, marge 56 780,45 F, dépenses 0 F → bénéfice estimé 56 780,4 |
| 114 | gerant | Combien d'utilisateurs ? | users_count | users_count | OK | Je ne peux pas vous donner « utilisateurs du logiciel » : votre rôle (Responsable de magasin) n'a pas accès à ces données. Un responsable pe |
