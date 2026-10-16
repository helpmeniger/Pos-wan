# API REST MARWA

Base : `{APP_URL}/api`. Format JSON (UTF-8). Montants en **FCFA entiers**. Dates `AAAA-MM-JJ HH:MM:SS`
(heure de Niamey) en sortie ; en entrée, `JJ/MM/AAAA` et `JJ/MM/AAAA HH:MM` sont aussi acceptés.

## Authentification

**Navigateur (session)** : `POST /api/auth/login` crée une session (cookie `MARWASESSID`, HttpOnly,
SameSite=Lax, Secure en HTTPS). Toute requête modifiante doit porter l'en-tête `X-CSRF-Token`
(valeur fournie par la balise `<meta name="csrf-token">`, la réponse de connexion ou `GET /api/auth/csrf`).
Sans jeton valide : `419 csrf_mismatch`.

**Applications mobiles (jeton)** :

```http
POST /api/auth/token
Content-Type: application/json

{"identifier": "90 00 00 01", "password": "…", "device_name": "Android Awa"}
```
```json
{"data": {"token": "q2V…", "token_type": "Bearer", "expires_in_days": 30, "user": {"id": 4, "role": "client", …}}}
```

Ensuite : `Authorization: Bearer q2V…` (pas de cookie, pas de CSRF). Les jetons sont stockés
hachés (SHA-256) ; `POST /api/auth/logout` révoque le jeton courant.

## Réponses et erreurs

Succès : `{"data": …}` ou `{"message": "…"}`. Erreur :

```json
{"error": {"code": "validation_failed", "message": "Certaines informations sont invalides.",
           "fields": {"phone": "Numéro de téléphone invalide (ex. 90 12 34 56 ou +227 90 12 34 56)."}}}
```

| Statut | Code(s) | Signification |
|---|---|---|
| 400 | `bad_request`, `invalid_json`, `invalid_signature` | Requête mal formée |
| 401 | `unauthenticated`, `invalid_credentials` | Non connecté / identifiants incorrects (message identique que le compte existe ou non) |
| 403 | `forbidden` | Rôle, permission ou propriété de la ressource insuffisants |
| 404 | `not_found` | Ressource inexistante |
| 409 | `conflict`, `invalid_transition`, `already_assigned`, `stale_order`, `duplicate_payment`… | Conflit d'état (machine à états, attribution, paiement déjà effectué) |
| 419 | `csrf_mismatch` | Jeton CSRF absent/expiré (sessions uniquement) |
| 422 | `validation_failed`, `price_out_of_bounds`, `not_serviceable`, `merchant_closed`… | Données refusées par les règles métier |
| 423 | `account_locked` | Compte verrouillé 15 min après 5 échecs de connexion |
| 429 | `rate_limited`, `too_many_open_orders` | Limitation de débit / anti-abus |
| 500 | `server_error` | Erreur interne (référence à communiquer au support) |

## Exemples

### Estimer puis commander un taxi avec prix négocié

```http
POST /api/estimate
{"service_type":"taxi","pickup_lat":13.5137,"pickup_lng":2.1098,"dropoff_lat":13.4900,"dropoff_lng":2.1600}
```
Réponse : une entrée par catégorie de véhicule — `distance_m`, `duration_s`, `route_source`
(`osrm` | `estimate`), `approximate`, `fare`, `booking_fee`, `service_fee`, `total`, `min_offer`,
`max_offer`, `geometry` ([[lat,lng],…]), `disclaimer`.

```http
POST /api/orders
{"service_type":"taxi","vehicle_category":"taxi","pricing_mode":"negotiated","offered_price":1500,
 "pickup_text":"Plateau","pickup_lat":13.5137,"pickup_lng":2.1098,
 "dropoff_text":"Aéroport","dropoff_lat":13.49,"dropoff_lng":2.16,"payment_method":"cash"}
```
→ `201` avec la commande (`status: "negotiating"`, `final_price: null`). Les prix et frais sont
**toujours recalculés côté serveur** ; les montants envoyés par le client sont ignorés.

### Côté chauffeur

```http
POST /api/provider/online      {"online": true, "lat": 13.515, "lng": 2.110}
POST /api/provider/location    {"lat": 13.514, "lng": 2.1099, "heading": 90, "speed": 5.2, "accuracy": 12}
GET  /api/provider/requests    → demandes proposées (position arrondie, sans identité du client)
POST /api/provider/requests/{id}/counter  {"amount": 1800, "ttl_seconds": 120}
POST /api/provider/requests/{id}/accept   → affectation atomique (409 already_assigned si un autre a gagné)
POST /api/provider/orders/{id}/action     {"action": "en_route_pickup" | "arrived_pickup" | "in_progress" | "completed" | "picked_up" | "delivered" | "delivery_failed" | "release" | …}
POST /api/provider/orders/{id}/action     {"action": "delivered", "delivery_code": "4821", "amount_collected": 4850}
```

### Suivi

`GET /api/orders/{id}/tracking` → `status`, `pickup`, `dropoff`, `route`, `vehicle`
(`icon`: `car` | `moto` | `bicycle` | `van`, plaque, prénom), `position` (`lat`, `lng`, `heading`,
`updated_at`, `age_seconds`, `stale`), `eta` (`target`, `remaining_m`, `remaining_s`, `arrival_at`,
`approximate`), `trail`. Accessible au client, au prestataire affecté, au commerce concerné et à
l'administration ; `403` pour toute autre personne.

### Temps réel

`GET /api/events?since=0` renvoie le curseur courant ; ensuite `GET /api/events?since={curseur}`
renvoie les nouveaux événements **de l'utilisateur connecté uniquement** (`notification`,
`order_updated`, `message`, `offer_updated`, `counter_rejected`). Fréquence conseillée : 4 s pendant
une commande active, 12–30 s sinon.

### Paiement électronique (passerelle de démonstration)

```http
POST /api/orders/{id}/pay
Idempotency-Key: pay-7f3c2a…        (8 à 80 caractères ; même clé = même paiement)
```
→ `redirect_url` vers la page du prestataire. La confirmation arrive **uniquement** par
`POST /api/webhooks/payments/{gateway}` avec signature vérifiée (`X-Marwa-Signature` = HMAC-SHA256
du corps pour la passerelle `demo`), montant contrôlé et identifiant d'événement unique (rejeu sans effet).

### Exports

Les rapports d'administration (`/api/admin/reports/{type}`), de commerce (`/api/merchant/report`) et
d'entreprise (`/api/business/statement`) acceptent `format=json|csv|xlsx|pdf`. Types de rapport :
`period` (avec `granularity=day|week|month|year`), `city`, `merchant`, `driver`, `courier`,
`payment_method`, `commissions`, `cash`, `unpaid`, `refunds`, `payouts`, `expenses` ; filtres
`from`, `to` (AAAA-MM-JJ), `city_id`, `service_type`, `provider_id`, `merchant_id`.

## Référence des routes

Liste générée à partir du routeur (`routes/api.php`). « rôle » = rôle principal de l'utilisateur ;
« permission » = permission d'administration ; « limite nom,max,secondes » = limitation de débit.
Les contrôles de propriété (ma commande, mon commerce, mon solde) sont effectués en plus dans les services.

### Supervision

| Méthode | Chemin | Accès |
|---|---|---|
| `GET` | `/api/health` | public |

### Authentification et compte

| Méthode | Chemin | Accès |
|---|---|---|
| `GET` | `/api/auth/csrf` | public |
| `POST` | `/api/auth/register` | limite register,10,3600 |
| `POST` | `/api/auth/login` | limite login,20,300 |
| `POST` | `/api/auth/token` | limite login,20,300 |
| `POST` | `/api/auth/logout` | public |
| `POST` | `/api/auth/password/forgot` | limite forgot,10,3600 |
| `POST` | `/api/auth/password/reset` | limite reset,10,3600 |

### Carte, estimation et catalogue (public)

| Méthode | Chemin | Accès |
|---|---|---|
| `GET` | `/api/geo/config` | public |
| `GET` | `/api/geo/search` | limite geo,60,60 |
| `GET` | `/api/geo/reverse` | limite geo,60,60 |
| `GET` | `/api/geo/zones` | public |
| `GET` | `/api/geo/landmarks` | public |
| `POST` | `/api/estimate` | limite estimate,40,60 |
| `GET` | `/api/merchants` | public |
| `GET` | `/api/merchants/{id}` | public |
| `GET` | `/api/promotions` | public |

### Paiements

| Méthode | Chemin | Accès |
|---|---|---|
| `POST` | `/api/webhooks/payments/{gateway}` | limite webhook,120,60 |

### Authentification et compte

| Méthode | Chemin | Accès |
|---|---|---|
| `GET` | `/api/auth/me` | connecté |
| `POST` | `/api/auth/otp/send` | connecté |
| `POST` | `/api/auth/otp/verify` | connecté, limite otp_verify,10,600 |
| `PUT` | `/api/account/profile` | connecté |
| `POST` | `/api/account/password` | connecté, limite pwd,10,3600 |
| `POST` | `/api/account/photo` | connecté, limite upload,20,3600 |
| `POST` | `/api/account/delete` | connecté, limite pwd,10,3600 |

### Notifications, temps réel, assistance

| Méthode | Chemin | Accès |
|---|---|---|
| `GET` | `/api/notifications` | connecté |
| `POST` | `/api/notifications/read` | connecté |
| `GET` | `/api/events` | connecté |
| `GET` | `/api/complaints` | connecté |
| `POST` | `/api/complaints` | connecté |
| `GET` | `/api/complaints/{id}` | connecté |
| `POST` | `/api/complaints/{id}/messages` | connecté |
| `POST` | `/api/emergency` | connecté |

### Commandes

| Méthode | Chemin | Accès |
|---|---|---|
| `GET` | `/api/orders` | connecté |
| `GET` | `/api/orders/{id}` | connecté |
| `GET` | `/api/orders/{id}/tracking` | connecté |
| `GET` | `/api/orders/{id}/messages` | connecté |
| `POST` | `/api/orders/{id}/messages` | connecté |
| `GET` | `/api/orders/{id}/receipt` | connecté |

### Paiements

| Méthode | Chemin | Accès |
|---|---|---|
| `POST` | `/api/payments/demo/{uuid}/simulate` | connecté, limite pay,20,60 |

### Adresses (client, entreprise)

| Méthode | Chemin | Accès |
|---|---|---|
| `GET` | `/api/addresses` | connecté, rôle client,business |
| `POST` | `/api/addresses` | connecté, rôle client,business |
| `PUT` | `/api/addresses/{id}` | connecté, rôle client,business |
| `DELETE` | `/api/addresses/{id}` | connecté, rôle client,business |

### Commandes

| Méthode | Chemin | Accès |
|---|---|---|
| `POST` | `/api/orders` | connecté, rôle client,business |
| `POST` | `/api/orders/{id}/cancel` | connecté, rôle client,business |
| `POST` | `/api/orders/{id}/offer` | connecté, rôle client,business |
| `POST` | `/api/orders/{id}/negotiations/{nid}/accept` | connecté, rôle client,business |
| `POST` | `/api/orders/{id}/negotiations/{nid}/reject` | connecté, rôle client,business |
| `POST` | `/api/orders/{id}/pay` | connecté, rôle client,business, limite pay,20,60 |
| `POST` | `/api/orders/{id}/rate` | connecté, rôle client,business |

### Entreprise

| Méthode | Chemin | Accès |
|---|---|---|
| `GET` | `/api/business` | connecté, rôle client,business |
| `PUT` | `/api/business` | connecté, rôle client,business |
| `POST` | `/api/business/members` | connecté, rôle client,business |
| `PUT` | `/api/business/members/{id}` | connecté, rôle client,business |
| `GET` | `/api/business/orders` | connecté, rôle client,business |
| `GET` | `/api/business/statement` | connecté, rôle client,business |
| `POST` | `/api/business/addresses` | connecté, rôle client,business |
| `DELETE` | `/api/business/addresses/{id}` | connecté, rôle client,business |

### Chauffeur / livreur

| Méthode | Chemin | Accès |
|---|---|---|
| `GET` | `/api/provider/profile` | connecté, rôle driver,courier |
| `PUT` | `/api/provider/profile` | connecté, rôle driver,courier |
| `POST` | `/api/provider/vehicle` | connecté, rôle driver,courier |
| `POST` | `/api/provider/vehicle/photo` | connecté, rôle driver,courier, limite upload,20,3600 |
| `POST` | `/api/provider/documents` | connecté, rôle driver,courier |
| `GET` | `/api/provider/earnings` | connecté, rôle driver,courier |
| `GET` | `/api/provider/history` | connecté, rôle driver,courier |
| `POST` | `/api/provider/payouts` | connecté, rôle driver,courier |
| `POST` | `/api/provider/online` | connecté, rôle driver,courier, prestataire validé |
| `POST` | `/api/provider/location` | connecté, rôle driver,courier, prestataire validé, limite gps,40,60 |
| `GET` | `/api/provider/requests` | connecté, rôle driver,courier, prestataire validé |
| `POST` | `/api/provider/requests/{id}/accept` | connecté, rôle driver,courier, prestataire validé, limite accept,30,60 |
| `POST` | `/api/provider/requests/{id}/refuse` | connecté, rôle driver,courier, prestataire validé |
| `POST` | `/api/provider/requests/{id}/counter` | connecté, rôle driver,courier, prestataire validé |
| `GET` | `/api/provider/current` | connecté, rôle driver,courier |
| `POST` | `/api/provider/orders/{id}/action` | connecté, rôle driver,courier |
| `POST` | `/api/provider/orders/{id}/cash` | connecté, rôle driver,courier |
| `POST` | `/api/provider/proof` | connecté, rôle driver,courier, limite upload,30,3600 |

### Restaurant / commerce

| Méthode | Chemin | Accès |
|---|---|---|
| `GET` | `/api/merchant/store` | connecté, rôle merchant |
| `PUT` | `/api/merchant/store` | connecté, rôle merchant |
| `POST` | `/api/merchant/store/open` | connecté, rôle merchant |
| `POST` | `/api/merchant/store/logo` | connecté, rôle merchant, limite upload,20,3600 |
| `GET` | `/api/merchant/catalog` | connecté, rôle merchant |
| `POST` | `/api/merchant/categories` | connecté, rôle merchant |
| `PUT` | `/api/merchant/categories/{id}` | connecté, rôle merchant |
| `DELETE` | `/api/merchant/categories/{id}` | connecté, rôle merchant |
| `POST` | `/api/merchant/products` | connecté, rôle merchant |
| `PUT` | `/api/merchant/products/{id}` | connecté, rôle merchant |
| `DELETE` | `/api/merchant/products/{id}` | connecté, rôle merchant |
| `POST` | `/api/merchant/products/{id}/availability` | connecté, rôle merchant |
| `POST` | `/api/merchant/products/{id}/photo` | connecté, rôle merchant, limite upload,60,3600 |
| `POST` | `/api/merchant/products/{id}/options` | connecté, rôle merchant |
| `DELETE` | `/api/merchant/products/{id}/options/{oid}` | connecté, rôle merchant |
| `POST` | `/api/merchant/products/import` | connecté, rôle merchant, limite upload,10,3600 |
| `GET` | `/api/merchant/orders` | connecté, rôle merchant |
| `POST` | `/api/merchant/orders/{id}/action` | connecté, rôle merchant |
| `GET` | `/api/merchant/finance` | connecté, rôle merchant |
| `GET` | `/api/merchant/report` | connecté, rôle merchant |
| `POST` | `/api/merchant/payouts` | connecté, rôle merchant |
| `GET` | `/api/merchant/couriers` | connecté, rôle merchant |
| `POST` | `/api/merchant/couriers` | connecté, rôle merchant |
| `DELETE` | `/api/merchant/couriers/{id}` | connecté, rôle merchant |

### Administration

| Méthode | Chemin | Accès |
|---|---|---|
| `GET` | `/api/admin/dashboard` | connecté, rôle admin,support,finance, permission admin.dashboard |
| `GET` | `/api/admin/map` | connecté, rôle admin,support,finance, permission orders.view |
| `GET` | `/api/admin/users` | connecté, rôle admin,support,finance, permission users.view |
| `GET` | `/api/admin/users/{id}` | connecté, rôle admin,support,finance, permission users.view |
| `POST` | `/api/admin/users/{id}/status` | connecté, rôle admin,support,finance, permission users.manage |
| `POST` | `/api/admin/staff` | connecté, rôle admin,support,finance, permission settings.manage |
| `GET` | `/api/admin/providers` | connecté, rôle admin,support,finance, permission partners.validate |
| `GET` | `/api/admin/providers/{id}` | connecté, rôle admin,support,finance, permission partners.validate |
| `POST` | `/api/admin/providers/{id}/verify` | connecté, rôle admin,support,finance, permission partners.validate |
| `POST` | `/api/admin/documents/{id}/review` | connecté, rôle admin,support,finance, permission partners.validate |
| `GET` | `/api/admin/merchants` | connecté, rôle admin,support,finance, permission partners.validate |
| `POST` | `/api/admin/merchants/{id}/verify` | connecté, rôle admin,support,finance, permission partners.validate |
| `GET` | `/api/admin/businesses` | connecté, rôle admin,support,finance, permission partners.validate |
| `POST` | `/api/admin/businesses/{id}/verify` | connecté, rôle admin,support,finance, permission partners.validate |
| `POST` | `/api/admin/businesses/{id}/payment` | connecté, rôle admin,support,finance, permission payments.manage |
| `GET` | `/api/admin/orders` | connecté, rôle admin,support,finance, permission orders.view |
| `GET` | `/api/admin/orders/{id}` | connecté, rôle admin,support,finance, permission orders.view |
| `POST` | `/api/admin/orders/{id}/action` | connecté, rôle admin,support,finance, permission orders.manage |
| `GET` | `/api/admin/pricing` | connecté, rôle admin,support,finance, permission pricing.manage |
| `POST` | `/api/admin/pricing` | connecté, rôle admin,support,finance, permission pricing.manage |
| `PUT` | `/api/admin/pricing/{id}` | connecté, rôle admin,support,finance, permission pricing.manage |
| `GET` | `/api/admin/commissions` | connecté, rôle admin,support,finance, permission commissions.manage |
| `POST` | `/api/admin/commissions` | connecté, rôle admin,support,finance, permission commissions.manage |
| `PUT` | `/api/admin/commissions/{id}` | connecté, rôle admin,support,finance, permission commissions.manage |
| `POST` | `/api/admin/commissions/preview` | connecté, rôle admin,support,finance, permission commissions.manage |
| `GET` | `/api/admin/cities` | connecté, rôle admin,support,finance, permission zones.manage |
| `POST` | `/api/admin/cities` | connecté, rôle admin,support,finance, permission zones.manage |
| `PUT` | `/api/admin/cities/{id}` | connecté, rôle admin,support,finance, permission zones.manage |
| `GET` | `/api/admin/neighborhoods` | connecté, rôle admin,support,finance, permission zones.manage |
| `POST` | `/api/admin/neighborhoods` | connecté, rôle admin,support,finance, permission zones.manage |
| `PUT` | `/api/admin/neighborhoods/{id}` | connecté, rôle admin,support,finance, permission zones.manage |
| `GET` | `/api/admin/zones` | connecté, rôle admin,support,finance, permission zones.manage |
| `POST` | `/api/admin/zones` | connecté, rôle admin,support,finance, permission zones.manage |
| `PUT` | `/api/admin/zones/{id}` | connecté, rôle admin,support,finance, permission zones.manage |
| `DELETE` | `/api/admin/zones/{id}` | connecté, rôle admin,support,finance, permission zones.manage |
| `GET` | `/api/admin/landmarks` | connecté, rôle admin,support,finance, permission zones.manage |
| `POST` | `/api/admin/landmarks` | connecté, rôle admin,support,finance, permission zones.manage |
| `PUT` | `/api/admin/landmarks/{id}` | connecté, rôle admin,support,finance, permission zones.manage |
| `GET` | `/api/admin/promotions` | connecté, rôle admin,support,finance, permission promotions.manage |
| `POST` | `/api/admin/promotions` | connecté, rôle admin,support,finance, permission promotions.manage |
| `PUT` | `/api/admin/promotions/{id}` | connecté, rôle admin,support,finance, permission promotions.manage |
| `GET` | `/api/admin/settings` | connecté, rôle admin,support,finance, permission settings.manage |
| `POST` | `/api/admin/settings` | connecté, rôle admin,support,finance, permission settings.manage |
| `POST` | `/api/admin/content` | connecté, rôle admin,support,finance, permission settings.manage |
| `GET` | `/api/admin/system` | connecté, rôle admin,support,finance, permission settings.manage |
| `GET` | `/api/admin/audit` | connecté, rôle admin,support,finance, permission audit.view |
| `GET` | `/api/admin/payments` | connecté, rôle admin,support,finance, permission finance.view |
| `POST` | `/api/admin/payments/{id}/verify-cash` | connecté, rôle admin,support,finance, permission payments.manage |
| `GET` | `/api/admin/reconciliation` | connecté, rôle admin,support,finance, permission finance.view |
| `GET` | `/api/admin/refunds` | connecté, rôle admin,support,finance, permission finance.view |
| `POST` | `/api/admin/refunds` | connecté, rôle admin,support,finance, permission orders.manage |
| `POST` | `/api/admin/refunds/{id}/review` | connecté, rôle admin,support,finance, permission refunds.manage |
| `GET` | `/api/admin/payouts` | connecté, rôle admin,support,finance, permission finance.view |
| `POST` | `/api/admin/payouts/{id}/review` | connecté, rôle admin,support,finance, permission payouts.manage |
| `POST` | `/api/admin/remittances` | connecté, rôle admin,support,finance, permission payouts.manage |
| `GET` | `/api/admin/balances` | connecté, rôle admin,support,finance, permission finance.view |
| `GET` | `/api/admin/ledger` | connecté, rôle admin,support,finance, permission finance.view |
| `GET` | `/api/admin/ledger/transactions/{id}` | connecté, rôle admin,support,finance, permission finance.view |
| `GET` | `/api/admin/ledger/accounts/{id}` | connecté, rôle admin,support,finance, permission finance.view |
| `POST` | `/api/admin/ledger/adjustments` | connecté, rôle admin,support,finance, permission payments.manage |
| `POST` | `/api/admin/ledger/transactions/{id}/reverse` | connecté, rôle admin,support,finance, permission payments.manage |
| `GET` | `/api/admin/expenses` | connecté, rôle admin,support,finance, permission finance.view |
| `POST` | `/api/admin/expenses` | connecté, rôle admin,support,finance, permission expenses.manage |
| `POST` | `/api/admin/subscriptions` | connecté, rôle admin,support,finance, permission commissions.manage |
| `GET` | `/api/admin/reports/{type}` | connecté, rôle admin,support,finance, permission finance.view |
| `GET` | `/api/admin/complaints` | connecté, rôle admin,support,finance, permission complaints.manage |
| `GET` | `/api/admin/complaints/{id}` | connecté, rôle admin,support,finance, permission complaints.manage |
| `POST` | `/api/admin/complaints/{id}` | connecté, rôle admin,support,finance, permission complaints.manage |
| `GET` | `/api/admin/alerts` | connecté, rôle admin,support,finance, permission complaints.manage |
| `POST` | `/api/admin/alerts/{id}` | connecté, rôle admin,support,finance, permission complaints.manage |
| `GET` | `/api/admin/ratings` | connecté, rôle admin,support,finance, permission complaints.manage |
| `POST` | `/api/admin/ratings/{id}` | connecté, rôle admin,support,finance, permission complaints.manage |
| `POST` | `/api/admin/broadcast` | connecté, rôle admin,support,finance, permission notifications.send |

## Ajouts (connexion par téléphone, suivi en direct)

| Méthode | Chemin | Accès | Rôle |
|---|---|---|---|
| POST | `/api/auth/phone/send` | public (limité) | Envoie un code de connexion au numéro `phone` (clients). Réponse `password_required` pour un compte professionnel |
| POST | `/api/auth/phone/verify` | public (limité) | `phone`, `code`, `remember` → connexion, ou `needs_profile` + `signup_token` pour un nouveau numéro |
| POST | `/api/auth/phone/register` | public (limité) | `phone`, `signup_token`, `full_name`, `accept_terms`, `remember` → crée le compte client et connecte |
| POST | `/api/auth/login` | public | accepte `remember` (« se souvenir de moi », cookie `mw_remember` 30 jours) |
| DELETE | `/api/notifications/{id}` | connecté | Efface une de ses notifications |
| GET | `/api/admin/live` | `orders.view` | Commandes ouvertes, alertes, prestataires en ligne (présence dans l'application) |
| GET | `/api/admin/live/orders/{id}/candidates` | `orders.view` | Prestataires validés les plus proches du départ |
| POST | `/api/admin/live/contact` | `orders.manage` | `user_id`, `message`, `order_id?`, `sms?`, `offer?` : notification (app + push), SMS, proposition de la course |
| POST | `/api/admin/promotions/{id}/image` | `promotions.manage` | Photo de la promotion |
