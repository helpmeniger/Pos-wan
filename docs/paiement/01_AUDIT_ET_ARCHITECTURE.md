# MARWA PAY CONNECT — Phase 1 (audit) et Phase 2 (architecture)

## Avertissements
- Aucune documentation officielle de My NiTA, AMANA, d'un opérateur Mobile Money, d'une banque nigérienne, d'un acquéreur
  Visa/Mastercard ni d'un fabricant de TPE n'a été fournie. **Aucun connecteur ne sera déclaré compatible** avec ces services
  tant que leur documentation et leurs accès de test n'auront pas été obtenus et le connecteur testé dessus.
- Visa et Mastercard sont des **réseaux de cartes**, pas des API marchandes : un commerçant les accepte par l'intermédiaire d'un
  acquéreur (banque) ou d'un prestataire de paiement certifié PCI DSS (page de paiement hébergée, tokenisation, TPE certifié).
- POS Marwa ne verra, ne stockera et ne transmettra **jamais** de numéro de carte complet, de CVV ni de code PIN.

## PHASE 1 — Audit (constaté dans le code)

| Élément | Constat | Conséquence |
|---|---|---|
| PHP / framework | PHP 8.1+ (testé 8.3), sans framework ni Composer ; PDO MySQL/MariaDB | Même style ; cURL pour HTTP |
| Extensions | Système de modules (addons) + événements du cœur (`App\Core\Events`, ajouté en 3.7.0) | Module `addons/pay`, indépendant |
| Moyens de paiement | `payment_methods()` : cash, card, mobile_money, transfer, cheque (activés par réglage) ; `sale_payments` (method, provider, amount, reference) | Les paiements électroniques sont enregistrés avec ces méthodes existantes (carte, mobile money, virement) : **aucun moyen existant supprimé**, rapports et clôtures inchangés |
| Vente | `SaleService::create` : prix et totaux recalculés côté serveur ; plusieurs règlements par vente ; crédit client avec plafond et autorisation ; idempotence `client_uuid` ; les paiements non-espèces ne peuvent dépasser le total | Paiements multiples et partiels déjà gérés : le module y ajoute des lignes « électroniques » vérifiées |
| Caisse | `cash_sessions` (une session ouverte par caisse), `cash_movements` signés par méthode ; clôture = espèces attendues/comptées | Un remboursement électronique réussi crée un mouvement négatif de sa méthode (jamais en espèces) |
| Dettes / acomptes | Compte client (`CustomerService::ledger`), règlements de dettes, échéances | Réutilisés : un solde peut être réglé plus tard par le module (paiement différé) |
| Retours | `createReturn` avec remboursement cash / carte / mobile / virement / avoir | Les remboursements électroniques passent par le prestataire d'origine (écran Remboursements) |
| Caisse (écran) | `pos.js` : fenêtre de règlement, lignes de paiement, reste et monnaie calculés ; mode hors connexion | Bouton « PAIEMENT ÉLECTRONIQUE » dans cette fenêtre ; **indisponible hors connexion** (une confirmation serveur est indispensable) |
| Utilisateurs | RBAC, autorisation superviseur (`Auth::verifySupervisor`) | Remboursement : permission dédiée ou validation d'un responsable |
| Sécurité | CSRF, requêtes préparées, `Audit`, chiffrement libsodium dérivé d'`APP_KEY`, route webhook signée | Réutilisés |

### Limites identifiées
1. PHP s'exécute sur le serveur : il ne peut pas ouvrir l'USB, le port COM ou le Bluetooth du poste de caisse.
   → **Pont local Marwa Pay Bridge** (script PHP CLI pour Windows, lancé au démarrage du poste) qui reçoit des demandes signées (HMAC + horodatage) et dialogue avec le terminal.
2. Un TPE « autonome » (non intégré) ne renvoie rien à POS Marwa : la transaction est saisie par le caissier avec le n° d'autorisation et reste **« À vérifier »** jusqu'au rapprochement avec le relevé de la banque.
3. Les SDK Android de TPE s'exécutent sur le terminal : POS Marwa ne peut les piloter que si le terminal expose une API locale (HTTP).

## PHASE 2 — Architecture

### Parcours d'un paiement électronique à la caisse
1. Le caissier ouvre « PAIEMENT ÉLECTRONIQUE », choisit un moyen activé pour sa caisse ; le montant proposé = reste à payer.
2. Le serveur crée une transaction `pending` (numéro unique `PAY…`, clé d'idempotence liée au panier), vérifie montant et devise, puis appelle le connecteur.
3. Selon le connecteur : réponse immédiate, demande poussée sur le téléphone du client, lien / QR de page hébergée, ou terminal.
4. La caisse interroge le serveur ; le serveur interroge le prestataire (**serveur à serveur**) ou reçoit un webhook **signé**.
   Une redirection du client n'est jamais une preuve.
5. Statut `succeeded` vérifié → la ligne de paiement est ajoutée (méthode carte / mobile money / virement, référence du prestataire).
6. À la validation de la vente, le serveur vérifie que chaque ligne électronique correspond à une transaction réussie, de même montant,
   non utilisée, puis la **réserve** pour ce panier (empêche le double usage) et la relie à la vente.

### Composants (`addons/pay/src`)
- `PayEngine` : création, idempotence, appel des connecteurs, vérification, expiration, liaison aux ventes, remboursements.
- `GatewayDriver` (interface) : `info, capabilities, testConnection, initiate, getStatus, cancel, refund, getRefundStatus, parseWebhook`.
- `TerminalDriver` (même interface, pour les TPE).
- `Secrets` (chiffrement), `PayAdmin` (configuration), `Reconciliation` (import de relevés CSV), `Reports`.
- Pilotes : `Simulation` (complet, marqué TEST) ; `Generic/RestApiGateway`, `SoapGateway`, `WebhookGateway` (configurables) ;
  `Terminals/TcpTerminalDriver`, `UsbTerminalDriver` (via pont), `AndroidTerminalDriver` (API HTTP locale), `PosTerminalDriver` (TPE autonome, « À vérifier ») ;
  squelettes **non compatibles** : `Cards/VisaGateway`, `MastercardGateway`, `GenericCardGateway`, `Wallets/PaypalGateway`, `MobileMoneyGateway`, `Niger/MyNitaGateway`, `AmanaGateway`, `Banking/BankApiGateway`.

### Modifications du cœur (minimes, sans effet si le module est inactif)
- `SaleService::create` : transmet la référence de transaction des lignes de paiement et l'événement `sale.payments` (contrôle et réservation avant enregistrement).
- Écran de caisse : bouton « PAIEMENT ÉLECTRONIQUE » affiché seulement si le module est actif.

### Tables (migration réversible, aucune table existante modifiée)
`payment_providers`, `payment_connectors`, `payment_api_credentials`, `payment_terminals`, `payment_methods`, `payment_transactions`,
`payment_transaction_logs`, `payment_refunds`, `payment_webhooks`, `payment_currencies`, `payment_exchange_rates`, `payment_reconciliation`, `payment_settings`.

### Statuts
En attente · En cours · Réussi · Refusé · Annulé · Expiré · Remboursé · Partiellement remboursé · À vérifier · Échec technique.

### Multidevise
Devise de la boutique par défaut. Un moyen dans une autre devise exige un taux saisi (avec source et date) ; le montant réellement
débité, la devise et les frais sont ceux **renvoyés par le prestataire** quand il les fournit. Aucune conversion silencieuse.

### Données personnelles
Numéros de téléphone et noms des payeurs : loi n° 2017-28 (Niger). Seul le strict nécessaire est conservé ; jamais de donnée de carte.
