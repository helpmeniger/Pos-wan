# Architecture technique de POS Marwa

## 1. Choix techniques

| Choix | Raison |
|---|---|
| PHP 8.1+ sans framework ni Composer | Installation directe sur WampServer, aucune dépendance à télécharger, fonctionnement hors ligne, code lisible et maintenable par un développeur PHP local. |
| MySQL/MariaDB InnoDB, utf8mb4 | Fourni par WampServer ; transactions, clés étrangères, verrous de ligne. |
| Bootstrap 5.3 + Bootstrap Icons + Chart.js, servis localement | Interface moderne et responsive sans CDN (la boutique peut être hors ligne). |
| JavaScript natif (pas de build) | Aucun outil de compilation à installer ; CSP stricte (aucun script inline). |
| Rendu serveur + API JSON pour la caisse | Pages d'administration simples et robustes ; caisse réactive (recherche, scan, encaissement sans rechargement). |

## 2. Arborescence

```
pos-marwa/
├── app/
│   ├── bootstrap.php        Autoload PSR-4, .env, configuration, fuseau horaire, erreurs
│   ├── helpers.php          e(), url(), money(), setting(), feature(), paginate(), amount_words()…
│   ├── Core/                Noyau : DB (PDO), Router, Request, Session, Csrf, Auth, View,
│   │                        Validator, Upload, Csv, Audit, Logger, ErrorHandler, exceptions
│   ├── Controllers/         Un contrôleur par module (HTTP uniquement : lecture des entrées, vues)
│   ├── Services/            Règles métier transactionnelles (SaleService, StockService, CashService,
│   │                        PurchaseService, InventoryService, CustomerService, SupplierService,
│   │                        ExpenseService, ProductService, ReportService, BackupService,
│   │                        InstallService, Sequence, Barcode, Catalog, Menu, Notifications, DemoSeeder)
│   └── Views/               Gabarits PHP (layouts admin / caisse / impression, un dossier par module)
├── bin/                     install.php, migrate.php, backup.php (ligne de commande)
├── config/                  app.php, database.php (lisent .env)
├── database/
│   ├── schema.sql           Schéma complet (version 1)
│   └── migrations/          Évolutions NNNN_nom.sql appliquées par bin/migrate.php
├── docs/                    Documentation, modèle d'hôte virtuel, captures
├── public/                  SEUL dossier exposé : index.php, assets/, uploads/
├── routes/web.php           Table des routes + permission requise par route
├── storage/                 logs/, backups/, sessions/ (jamais exposé)
├── tests/                   run.php (suite de tests), server-router.php
└── .env.example             Modèle de configuration (le .env réel n'est jamais versionné)
```

## 3. Cycle d'une requête

`public/index.php` → en-têtes de sécurité → session → (redirection vers l'installateur si besoin)
→ `Router::dispatch()` : correspondance de route → **CSRF** (POST) → **authentification** →
**permission** de la route → contrôleur → service (transaction) → vue (`e()` partout) ou JSON.

Les erreurs métier (`BusinessException`) sont affichées à l'utilisateur ; toute autre erreur
est journalisée et remplacée par un message générique.

## 4. Modèle de données (principales tables)

- **Organisation** : `stores` (boutiques), `warehouses` (entrepôts/dépôts), `cash_registers` (caisses, liées à un entrepôt de vente).
- **Sécurité** : `users`, `roles`, `permissions`, `role_permissions`, `login_attempts`, `audit_logs`.
- **Catalogue** : `categories` (2 niveaux), `brands`, `units`, `products` (variantes via `parent_id`, champs pharmacie), `product_packagings` (paquet/carton = N unités), `price_history`.
- **Stock** : `stock_levels` (cache par article × entrepôt), `stock_lots` (lot, péremption, rappel), `stock_movements` (journal immuable, solde après mouvement), `stock_transfers(+items)`, `inventories(+items)`.
- **Ventes** : `sales` (`client_uuid` unique = idempotence), `sale_items`, `sale_item_lots` (répartition FEFO), `sale_payments`, `held_sales` (mises en attente), `sale_returns(+items)`, `prescriptions`.
- **Caisse** : `cash_sessions` (une seule ouverte par caisse : index unique sur colonne générée), `cash_movements` (tous les flux, signés, par mode de paiement).
- **Tiers** : `customers` + `customer_transactions` (grand livre client), `suppliers` + `supplier_transactions` (grand livre fournisseur).
- **Achats** : `purchase_orders(+items)`, `receptions(+items)`, `supplier_returns(+items)`.
- **Finances** : `expense_categories`, `expenses` (dépenses et recettes diverses, contrepassables).
- **Système** : `settings` (clé/valeur), `sequences` (numérotation annuelle), `schema_migrations`.

## 5. Règles métier

### Prix et taxes
- Les prix de vente sont **TTC**. La taxe incluse est calculée par ligne : `taxe = total × taux / (100 + taux)`.
- Prix appliqué : conditionnement > prix de gros (si vente en gros autorisée et quantité minimale atteinte) > promotion active > prix normal.
- Toute dérogation de prix exige `pos.price_override` (ou un superviseur) ; le prix catalogue est conservé dans `sale_items.list_price`.
- Remise : `pos.discount` ; au-delà du seuil `max_discount_percent` (10 % par défaut), `pos.discount_high` ou superviseur. La remise globale est répartie au prorata sur les lignes (utile pour les retours).

### Stock
- La quantité ne change **que** par `StockService` (mouvement + niveau dans la même transaction, verrou de ligne).
- Stock négatif interdit par défaut (paramètre), toujours interdit pour les articles suivis par lot.
- **Valorisation : coût moyen pondéré (CMP) global par article**, recalculé à chaque entrée valorisée :
  `CMP = (stock × CMP + qté reçue × coût de revient) / (stock + qté reçue)`.
  Le coût de revient d'une réception inclut la quote-part des **frais d'approche** (transport…) répartis au prorata de la valeur.
- Sorties d'un article suivi par lot : **FEFO** (péremption la plus proche d'abord) ; lots périmés et rappelés exclus de la vente.
- Inventaire : les quantités théoriques sont figées au lancement ; à la validation, seul l'écart compté est appliqué au stock actuel (les ventes faites pendant le comptage restent justes). Les articles non comptés ne sont pas modifiés.
- Une commande fournisseur n'affecte jamais le stock ; seule la réception le fait.

### Caisse
- Vente possible seulement si la caisse de l'utilisateur a une session ouverte (paramètre).
- Espèces théoriques = fond de caisse + somme des mouvements en espèces de la session (ventes nettes de la monnaie rendue, remboursements, apports/retraits, dépenses, règlements).
- Clôture : comptage (par coupures FCFA ou total), écart calculé ; commentaire obligatoire si écart ≥ 1 F.

### Crédit client
- Vente à crédit = paiement inférieur au total ; client obligatoire ; `pos.credit_sale` ; plafond du client (dépassement = autorisation superviseur explicite).
- Chaque variation de solde est écrite dans `customer_transactions` (solde après opération), avec échéance.

### Corrections
- Vente annulée (délai paramétrable, 24 h par défaut) : contre-passation stock (dans les lots d'origine), caisse et compte client. La vente reste visible avec le motif.
- Retour : partiel ou total, remboursement espèces / avoir client / autre mode, remise en stock optionnelle (produit revendable ou non).
- Dépense : contrepassation (écriture inverse), jamais de suppression.

### Indicateurs (voir aussi l'aide des rapports)
- CA net = ventes non annulées − retours de la période.
- Marge brute = CA net − coût des marchandises vendues (au coût réel de sortie).
- Bénéfice estimé = marge brute − dépenses + recettes diverses (hors amortissements et impôts).
- Trésorerie de caisse (espèces théoriques) et valeur du stock sont présentées séparément.

## 6. Types de commerce et modules

Le type d'activité active des **fonctionnalités** (paramètres `feature_*`) sans dupliquer le code :

| Fonctionnalité | Effet |
|---|---|
| `lots` | Lots + péremption, FEFO, alertes, rapport de péremption, traçabilité |
| `variants` | Variantes (taille, couleur) rattachées à un article parent |
| `packaging` | Conditionnements avec leur prix et leur code-barres |
| `weight` | Quantités décimales / vente au poids |
| `wholesale` | Prix de gros et option « Vente en gros » en caisse |
| `promotions` | Prix promotionnel daté |
| `pharmacy` | DCI, dosage, forme, ordonnance obligatoire, registre des délivrances, rappels de lots |
| `loyalty` | Cumul de points fidélité |
| `restaurant` | Plan de salle, commandes, écran cuisine, addition séparée |
| `recipes` | Fiches techniques : déstockage des ingrédients à la vente |

Ajouter un type de commerce : une entrée dans `Catalog::BUSINESS_TYPES`. Ajouter une
fonctionnalité : une entrée dans `Catalog::FEATURES`, puis des tests `feature('code')` dans
les vues/services concernés.

### Restaurant (fonctionnalités `restaurant` et `recipes`)
- Tables : `restaurant_areas`, `restaurant_tables` ; commandes : `restaurant_orders` (une seule
  commande en cours par table, garanti par index unique), `restaurant_order_items`
  (cycle new → sent → preparing → ready → served, ou cancelled), `kitchen_tickets`
  (un bon par poste et par envoi, ou bon d'annulation), `kitchen_ticket_cancellations`.
- Le poste de préparation vient de la catégorie (`categories.kitchen_station`), hérité de la
  catégorie parente ; sans poste, l'article ne génère pas de bon (servi directement).
- Les prix sont figés à la saisie de la commande. Le paiement crée une vente via
  `SaleService::create(..., ['trusted_prices' => true, 'restaurant_order_id' => …])` :
  caisse, stock, crédit et tickets restent gérés par le moteur commun. Une commande peut
  être réglée en plusieurs ventes (`restaurant_order_items.paid_qty`).
- Fiche technique (`product_recipes`) : à la vente, ce sont les ingrédients qui sortent
  du stock (FEFO si lots) ; le détail est mémorisé dans `sale_item_components` pour les
  annulations et retours. Le déstockage a lieu au **paiement** (et en perte lors d'une
  annulation « déjà préparé »), pas à l'envoi en cuisine.
- Articles `sellable = 0` : ingrédients, invisibles en caisse, refusés à la vente.

## 6 bis. Version 1.2 : impression, langues, documents

- **Impression** : `PrintService` choisit les imprimantes actives (`printers`) d'un usage
  (`receipt`, `kitchen:<poste>`, `bill`) et d'une caisse, construit le document avec
  `EscPos` (page de codes PC850, QR natif `GS ( k`, coupe, tiroir) et l'envoie par
  `fsockopen` (réseau, port 9100) ou `fopen` sur un partage Windows. Les erreurs sont
  renvoyées et journalisées, jamais propagées à la vente.
- **Documents** : `Documents` prépare des tableaux neutres (ticket, A4) affichés par les
  gabarits `app/Views/documents/ticket_{1,2,3}` et `a4_{1,2,3}` ; `DocumentRef` produit et
  vérifie le contenu signé des QR codes (`POSWAN|type|numéro|id|date|montant|signature`),
  dessinés dans le navigateur par `vendor/qrcode` (qrcode-generator, MIT).
- **Langues** : `App\Core\Lang` ; dictionnaires `lang/<code>.php` (clé = texte français).
  `t()` dans le code, `Lang::html()` traduit à l'affichage les nœuds texte et attributs
  identiques à une entrée, `__()` côté JavaScript. Arabe : `dir="rtl"`, Bootstrap RTL et
  montants isolés (U+2066/U+2069).
- **Devise** : paramètres `currency_*`, `money()` / `PWMoney` (aucune conversion).
- **Devis** : `QuoteService` (lignes contrôlées, statuts, conversion par `SaleService::create`
  avec `trusted_prices`, idempotente). Table `quotes`, `quote_items`, séquence `DV`.
- **Canal de vente** : `products.sales_channel` / `categories.sales_channel`
  (`all`, `shop`, `restaurant`), hérité de la catégorie puis de la catégorie parente,
  appliqué à l'affichage et contrôlé par `SaleService` et `RestaurantService`.
- **Coût d'achat** : `StockService::receive(..., $costMode)` — `average` (CMP), `new`, `old` ;
  mémorisé dans `reception_items.cost_mode`.

### Version 1.3
- **TVA** : `Tax::rate($produit)` selon `vat_mode` (`none`, `general` + `products.vat_exempt`,
  `product` = `products.tax_rate`) ; `SaleService` mémorise `tax_rate` / `tax_amount` par
  ligne ; `ReportService::vat()` agrège par taux et par mois (retours au prorata du taux de
  la ligne d'origine).
- **Export** : `App\Core\Export::download()` sert CSV ou PDF (`?export=csv|pdf`) à partir
  des mêmes lignes ; `Pdf` écrit un PDF 1.4 (Helvetica, WinAnsi, tableau paginé).
- **Cuisine** : `RestaurantService::stationFor()` (poste de la catégorie, sinon poste par
  défaut, `aucun` = sans ticket) ; `pay()` envoie d'abord les lignes `new` si
  `restaurant_send_on_pay`.

### Version 1.4 — modules complémentaires
`App\Core\Addons` : découverte des dossiers `addons/<code>/manifest.json`, table `addons`
(version installée, actif), chargement automatique `Addons\<Code>\` → `addons/<code>/src/`,
routes (`routes.php`, modules actifs seulement), vues `code::nom`, menu, traductions,
permissions (accordées à l'installation), migrations `addon:<code>:<fichier>`, fichiers
publics copiés dans `public/assets/addons/<code>/`. Voir `docs/MODULES.md`.

## 7. Rôles par défaut

| Rôle | Périmètre |
|---|---|
| Administrateur général | Tout |
| Propriétaire / gérant | Tout sauf gestion des rôles et sauvegardes |
| Responsable de magasin | Ventes, annulations, remises élevées, crédit, stock, achats, clients, caisse, rapports |
| Caissier | Vente, remise sous le seuil, réimpression, devis, clients, ouverture/clôture de sa caisse |
| Gestionnaire de stock | Articles, stock, ajustements, transferts, inventaires, lots |
| Acheteur | Fournisseurs, commandes, réceptions |
| Comptable | Rapports financiers, dépenses, paiements fournisseurs, règlements clients |
| Pharmacien responsable | Vente et délivrance sur ordonnance, lots, rappels, inventaires |
| Serveur | Plan de salle, prise de commande, envoi en cuisine, transfert de table (pas d'encaissement) |
| Cuisinier / barman | Écran cuisine / bar |
| Auditeur | Lecture seule (rapports, ventes, stock, journal d'audit) |

Les permissions de chaque rôle sont modifiables (Rôles & permissions) ; des rôles
personnalisés peuvent être créés.

## 8. Évolution du schéma

Ajouter un fichier `database/migrations/0002_description.sql` (instructions séparées par `;`
en fin de ligne), puis exécuter `php bin/migrate.php` : sauvegarde de sécurité automatique,
puis application des seules migrations non encore enregistrées dans `schema_migrations`.
