# Architecture de MARWA

## 1. Choix techniques

| Besoin | Choix | Raison |
|---|---|---|
| Serveur | PHP 8.2, architecture MVC maison (≈ 1 500 lignes de noyau) | Fonctionne sur WampServer sans Composer ; aucune dépendance à maintenir |
| Données | MySQL 8 / MariaDB 10.6+, InnoDB, utf8mb4, **PDO + requêtes préparées exclusivement** | Transactions, verrous `FOR UPDATE`, contraintes, triggers |
| Interface | HTML rendu côté serveur + JavaScript natif (sans framework, sans étape de compilation) | Léger sur Android et réseaux lents ; aucune chaîne de build sur Windows |
| Carte | Leaflet 1.9.4 **embarqué localement** + tuiles OpenStreetMap | Pas de CDN tiers (CSP stricte), cartes adaptées au Niger |
| Itinéraires | OSRM (configurable) avec repli automatique sur une estimation | Le service public OSRM n'offre aucune garantie |
| Temps réel | Flux d'événements en base + interrogation incrémentale (`GET /api/events?since=`) | Compatible Apache/WampServer sans démon WebSocket ; même contrat réutilisable par un futur serveur WebSocket |
| API mobile | Jetons Bearer (hachés en base) en plus des sessions | Prépare les applications Android |

## 2. Cycle d'une requête

```
public/index.php → bootstrap/app.php (autoload, .env, config, fuseau Africa/Niamey)
  → Kernel::handle()
      ├─ Router::match()                          (routes/api.php, routes/web.php)
      ├─ Session::start()  (cookie HttpOnly, SameSite=Lax, Secure en HTTPS, rotation 15 min)
      ├─ Auth::resolve()   (jeton Bearer OU session ; utilisateur suspendu = déconnecté)
      ├─ CSRF obligatoire pour toute requête modifiante authentifiée par cookie
      ├─ Middlewares : auth, role:…, perm:…, throttle:…, verified_provider
      ├─ Contrôleur → Services métier (transactions SQL)
      └─ En-têtes de sécurité (CSP, X-Frame-Options, nosniff, HSTS en HTTPS)
  Erreurs → JSON {error:{code,message,fields}} (API) ou page HTML ; les exceptions internes
  sont journalisées avec une référence affichée à l'utilisateur, sans détail technique.
```

## 3. Organisation du code

| Dossier | Rôle |
|---|---|
| `app/Core` | Noyau : `Kernel`, `Router`, `Request/Response`, `Database`, `Auth`, `Session`, `Csrf`, `Validator`, `RateLimiter`, `Audit`, `Settings`, `Geo`, `Str` |
| `app/Middleware` | Authentification, rôles, permissions, limitation de débit, prestataire validé |
| `app/Controllers/Api` | API publique et des espaces client / prestataire / commerce / entreprise |
| `app/Controllers/Admin` | API d'administration (direction, configuration, finance, support) |
| `app/Services` | Logique métier : `PricingService`, `DispatchService`, `NegotiationService`, `OrderService`, `OrderStateMachine`, `OrderPolicy`, `TrackingService`, `CommissionService`, `LedgerService`, `PaymentService`, `FinanceService`, `ReportService`, `NotificationService`, `RealtimeService`, `UploadService`, `ZoneService`… |
| `app/Services/Routing` | Fournisseurs d'itinéraires interchangeables (`OsrmRouter`, `EstimateRouter`) |
| `app/Services/Payment` | `PaymentGateway` (interface) + `DemoGateway` |
| `app/Services/Notification` | E-mail (log / mail() / SMTP), SMS (log + interface `SmsGateway`) |
| `app/Services/Report` | Exports CSV, XLSX (OOXML), PDF (générateur intégré) |
| `public/assets/js` | `core.js` (API, UI, routeur, temps réel), `map.js` (Leaflet, icônes, sélecteur de lieu), un fichier par espace |

## 4. Données (64 tables + 1 vue)

| Domaine | Tables |
|---|---|
| Identité et droits | `users`, `roles`, `permissions`, `role_permissions`, `api_tokens`, `password_resets`, `otp_codes`, `rate_limits` |
| Profils | `customer_profiles`, `provider_profiles` (chauffeurs **et** livreurs, champ `provider_type`), `vehicles`, `provider_documents`, `provider_locations`, `businesses`, `business_members` |
| Commerces | `merchants` (= restaurants/commerces), `merchant_hours`, `merchant_closures`, `product_categories`, `products`, `product_variants` (variantes + suppléments) |
| Géographie | `cities`, `neighborhoods`, `service_zones` (polygones GeoJSON + bbox indexée), `landmarks`, `addresses`, `geocode_cache` |
| Tarifs | `pricing_rules`, `commission_rules`, `promotions`, `promotion_redemptions` |
| Commandes | `orders`, `order_items`, `order_status_history`, `rides`, `deliveries`, `dispatch_offers` (= *driver_offers*), `fare_negotiations`, `assignments`, `driver_locations` |
| Finance | `payments`, `payment_webhooks`, `refunds`, `commissions`, `ledger_accounts`, `ledger_transactions`, `ledger_entries`, vue `partner_balances`, `payouts`, `expenses`, `subscriptions`, `invoices` |
| Communication | `notifications`, `outbound_messages`, `realtime_events`, `messages`, `ratings`, `complaints`, `complaint_messages`, `emergency_alerts` |
| Administration | `audit_logs`, `settings`, `content_pages`, `files`, `schema_migrations` |

Adaptations par rapport au cahier des charges : `driver_profiles` et `delivery_profiles` sont
fusionnés dans `provider_profiles` (mêmes règles d'attribution, de documents et de revenus) ;
`restaurants` s'appelle `merchants` (restaurants **et** commerces) ; `partner_balances` est une
**vue** calculée depuis le grand livre (aucun solde stocké qui pourrait diverger).

Règles d'intégrité : clés étrangères partout, montants entiers en FCFA, `CHECK` sur les montants
et les notes, `UNIQUE` sur les clés d'idempotence (`payments.idempotency_key`,
`ledger_transactions.dedupe_key`, `payment_webhooks(gateway,event_id)`), triggers interdisant
`UPDATE`/`DELETE` sur `ledger_entries`, `ledger_transactions` et `audit_logs`. Les comptes
supprimés sont **anonymisés** (les écritures financières sont conservées).

## 5. Machines à états (`OrderStateMachine`)

Trois flux. Chaque transition est autorisée **par rôle** (`customer`, `provider`, `merchant`,
`admin`, `system`), appliquée par `UPDATE … WHERE status = :from AND version = :v` (verrou
optimiste), horodatée et historisée dans `order_status_history`, puis notifiée.

**Course (taxi, transport pro)** : `created → searching | negotiating → assigned → en_route_pickup → arrived_pickup → in_progress → completed → paid`
(+ `cancelled`, `expired`, `disputed`, `refund_pending`, `refunded`). Un prestataire qui se désiste
fait revenir la commande à `searching`/`negotiating`.

**Livraison (colis, documents, courses, urgent)** : `… → arrived_pickup → picked_up → in_delivery → delivered → paid`,
avec `delivery_failed → in_delivery | returning → returned`.

**Commerce (repas, boutique)** : `created → pending → accepted → preparing → ready → picked_up → in_delivery → delivered → paid`
(+ `refused`, `expired` si le commerce ne répond pas). L'étape du livreur pendant la préparation
(`assigned`, `en_route_pickup`, `arrived_pickup`) est suivie dans `provider_stage`.

États terminaux : `cancelled`, `expired`, `refused`, `refunded`. Une commande terminée ne peut
plus revenir en arrière ; seule l'administration peut la placer en litige ou en remboursement.

## 6. Tarification et négociation

`prix = max(minimum, base + prix/km × distance + prix/min × durée) × majoration de nuit`, arrondi
au pas configuré (50 FCFA par défaut), + frais de réservation et de service. La règle la plus
spécifique (zone > ville > catégorie de véhicule > priorité) s'applique. Si l'itinéraire vient de
l'estimation de secours, le tarif est marqué **approximatif** dans l'interface.

Mode négocié : le client propose un prix dans des bornes paramétrables (`min_offer_pct`,
`max_offer_pct` de l'estimation) ; les prestataires sollicités acceptent (affectation immédiate
au prix du client), refusent, ou contre-proposent avec une durée de validité (30 s à 5 min). Le
client accepte une contre-proposition → affectation au prix convenu. Anti-abus : nombre limité de
contre-propositions par prestataire et de modifications de prix par le client, limitation de débit,
nombre maximal de demandes simultanées par client.

## 7. Attribution (`DispatchService`)

1. Sélection SQL des prestataires **éligibles** : compte actif et validé, en ligne, libre
   (`current_order_id IS NULL`), permis et documents non expirés, bonne catégorie de véhicule,
   capacité suffisante, position GPS récente, dans la boîte englobante du rayon, pas déjà sollicité.
2. Tri par distance réelle (Haversine), éventuellement pondérée par la note (`dispatch.rating_weight`, 0 par défaut).
3. Envoi par **vagues** à un groupe limité (`dispatch.batch_size`), avec délai de réponse ; vague
   suivante quand toutes les propositions ont expiré ou été refusées ; échec après `dispatch.max_rounds`.
4. **Attribution unique garantie** : transaction + `SELECT … FOR UPDATE` sur la commande et le
   prestataire, mises à jour conditionnelles (`provider_id IS NULL`, `current_order_id IS NULL`),
   index uniques `assignments.active_order_id` et `provider_profiles.current_order_id`.
   Le test `ConcurrencyTest` lance 5 processus qui acceptent au même instant : un seul gagne.
5. Repas : la recherche du livreur démarre à `maintenant + temps de préparation − 10 min`.

Le moteur avance via `bin/worker.php` (cron) **et** de façon opportuniste lors des consultations.

## 8. Suivi GPS

Le navigateur du prestataire envoie sa position (`watchPosition`) toutes les 5 s au plus, ou dès
15 m de déplacement, uniquement lorsqu'il est **en ligne** ; le serveur ignore les envois trop
rapprochés. La position courante est dans `provider_locations` ; l'historique (`driver_locations`)
n'est enregistré que **pendant une commande active** et purgé après `tracking.history_retention_days`.
Le client, le commerce (pendant la préparation) et l'administration ne voient que le prestataire
affecté ; un prestataire non affecté ne voit qu'une position arrondie (~110 m) et aucune identité.
Passé `tracking.stale_after_seconds`, l'interface affiche « position non actualisée » avec l'heure.

**Limite des navigateurs** : la géolocalisation est suspendue lorsque l'application passe en
arrière-plan ou que l'écran se verrouille (le *Wake Lock* est demandé quand le navigateur le
permet). Un suivi continu garanti nécessitera l'application Android native prévue (même API).

## 9. Modèle économique et comptable

Grand livre interne **en partie double** (`LedgerService`). Comptes système : `1000` trésorerie,
`1100` fonds chez les prestataires de paiement, `2300` paiements clients reçus d'avance, `2400` TVA
collectée, `4000` commissions, `4100` frais de service, `4300` abonnements, `5000` promotions,
`5100` frais de paiement, `5200` dépenses, `5300` remboursements à charge, `5400` écarts de caisse.
Chaque prestataire, commerce et entreprise a son compte de tiers (solde créditeur = MARWA lui doit).

Au **règlement** d'une commande, le « collecteur » (qui détient l'argent) est débité du montant
encaissé ; chaque partenaire est crédité de son net, MARWA de ses commissions et frais.

*Exemple du cahier des charges* — course de 3 000 FCFA payée en espèces, commission 10 % :

| Compte | Débit | Crédit |
|---|---:|---:|
| Chauffeur (il détient l'argent) | 3 000 | |
| Chauffeur (son net) | | 2 700 |
| 4000 Commissions MARWA | | 300 |

→ Solde du chauffeur : −300 = commission à reverser à MARWA. Payée par Mobile Money, la même
course débite `2300` au lieu du chauffeur : le solde du chauffeur devient +2 700 (MARWA lui doit).

Le **CA brut** (somme payée par les clients) n'est jamais confondu avec le revenu MARWA. Les
promotions financées par MARWA sont des charges (`5000`), celles financées par un commerce
réduisent son net. Les écritures sont immuables : toute correction passe par une contre-passation
ou une écriture d'ajustement, journalisée dans l'audit. Le tableau de bord contrôle en permanence
l'égalité débit = crédit.

## 10. Hors ligne et réseau lent

- Service worker : ressources statiques en cache, page hors ligne ; **jamais** les appels API.
- Les GET réessaient automatiquement (attente progressive) et retombent sur la dernière réponse
  connue (cache local, par utilisateur, vidé à la déconnexion) avec un bandeau « connexion perdue ».
- Les POST ne sont jamais rejoués automatiquement ; aucune commande ni aucun paiement n'est
  confirmé sans réponse du serveur. Les paiements utilisent des clés d'idempotence.
- Interrogation adaptative : 4 s pendant une commande active, 12 s sinon, 30 s en arrière-plan.
- Aucune police externe, Leaflet local, images ré-encodées (≤ 1 600 px) à l'envoi.

## 11. Évolutivité

- **Applications Android** : toute l'API fonctionne avec un jeton Bearer (`POST /api/auth/token`),
  sans cookie ni CSRF ; mêmes règles d'accès côté serveur.
- **Autres villes / pays** : `cities` (pays, fuseau, devise), zones et règles tarifaires/commissions
  par ville ou zone ; repères importables depuis OpenStreetMap.
- **Fournisseurs** : interfaces `RoutingProvider`, `PaymentGateway`, `SmsGateway` ; pilotes e-mail.
- **Temps réel** : un serveur WebSocket pourra consommer `realtime_events` sans changer les clients
  métier ; `GET /api/events` restera le mode dégradé.
