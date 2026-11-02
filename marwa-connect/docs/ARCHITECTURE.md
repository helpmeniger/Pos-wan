# MARWA CONNECT — Dossier d'architecture

*Smart Wi-Fi Management & Ticketing System* · version 1.0.0

Ce document répond à la « première mission » du cahier des charges :
architecture technique, modules, schéma de base de données, connexion RouterOS,
structure des dossiers, interfaces, dépendances et risques.

---

## 1. Architecture technique retenue

| Couche | Choix | Justification |
|---|---|---|
| Serveur | Apache 2.4 + PHP 8.2+ + MySQL/MariaDB (WampServer) | Imposé ; portable vers un hébergeur Linux sans modification. |
| Langage | PHP 8.2+, `declare(strict_types=1)` | Typage strict, `readonly`, `match`, enums SQL. |
| Framework | **Micro-framework MVC maison** (`app/Core`) | Aucune dépendance Composer obligatoire : installation par simple copie dans `www/`. Code entièrement lisible et maîtrisé. |
| Base de données | PDO MySQL, requêtes préparées uniquement, InnoDB, utf8mb4 | Transactions, clés étrangères, verrouillage de lignes pour les ventes. |
| Interface | Bootstrap 5.3 + Bootstrap Icons + Chart.js 4 **servis localement** | Fonctionne sans Internet (exigence 19.2). |
| JavaScript | Vanilla JS, Fetch API, aucun script inline | Compatible avec une CSP stricte (`script-src 'self'`). |
| Routeurs | Client **RouterOS API binaire** natif PHP (ports 8728 / 8729 TLS) | Compatible RouterOS v6 et v7 ; aucun module PHP exotique. |
| Tâches de fond | `cli/*.php` lancés par le **Planificateur de tâches Windows** (ou cron) + table `jobs` | Synchronisation, alertes, expiration des tickets, sauvegardes, sans démon permanent. |

### Flux d'une requête

```
Navigateur ─► Apache (.htaccess) ─► public/index.php ─► bootstrap/app.php
   ─► App::handle ─► Session::start ─► Router::dispatch
   ─► Middlewares (csrf → auth → can:permission → throttle)
   ─► Controller ─► Services / Database ─► View (layout + composants) ─► Response
```

### Principes

* **Séparation stricte** : contrôleurs fins, logique métier dans `app/Services`, accès SQL via `App\Core\Database`.
* **Sécurité par défaut** : CSRF sur toute écriture, échappement `e()` systématique, CSP, cookies `HttpOnly`/`SameSite`, permissions vérifiées côté serveur.
* **Aucune donnée fictive** : chaque indicateur provient de la base ou d'un routeur réel. Un module non encore livré est affiché « Bientôt », jamais simulé.
* **Mises à jour sans perte** : migrations versionnées, jamais rejouées ; seeders idempotents (`INSERT IGNORE`).

---

## 2. Modules et relations

```
                       ┌──────────────────┐
                       │  Auth / Rôles    │◄── toutes les requêtes
                       └────────┬─────────┘
          ┌─────────────────────┼──────────────────────┐
          ▼                     ▼                      ▼
   ┌─────────────┐      ┌──────────────┐       ┌───────────────┐
   │  Routeurs   │◄────►│   Offres     │──────►│   Tickets     │
   │ (RouterOS)  │      │ + profils HS │       │ (lots, stock) │
   └──────┬──────┘      └──────────────┘       └───┬───────┬───┘
          │ sync                                  │       │
          ▼                                       ▼       ▼
   ┌─────────────┐                        ┌──────────┐ ┌────────────┐
   │ Monitoring  │                        │  Ventes  │ │ Revendeurs │
   │ Sessions WiFi│◄──── consommation ────│  Caisse  │◄┤ stock, com.│
   └──────┬──────┘                        │ Paiements│ └────────────┘
          │                               └────┬─────┘
          ▼                                    ▼
   ┌─────────────────────────────────────────────────┐
   │ Notifications / Alertes  ◄──►  Optimus IA        │
   │ Tableau de bord & rapports (lecture seule)       │
   └─────────────────────────────────────────────────┘
          Journal d'audit · Paramètres · Sauvegardes (transverses)
```

| Module | Phase | Dépend de | Fournit à |
|---|---|---|---|
| Installation, Auth, Rôles, Audit, Profil | 1 ✅ | — | tous |
| Tableau de bord, navigation, Paramètres | 2 ✅ | Auth | — |
| Routeurs MikroTik (connecteur, sync) | 3 ✅ | Auth, Crypto | Offres, Tickets, Monitoring |
| Offres & profils Hotspot | 4 ✅ | Routeurs | Tickets, Ventes, Paiement en ligne |
| Tickets (génération, impression) | 5 ✅ | Offres, Routeurs | Ventes, Revendeurs |
| Caisse, ventes, paiements | 6 ✅ | Tickets | Revendeurs, Rapports |
| Revendeurs | 7 ✅ | Tickets, Ventes | Rapports |
| Clients connectés, Monitoring | 8 ✅ | Routeurs | Alertes, IA |
| Notifications, Optimus IA | 9 ✅ | tous (lecture) | — |
| Sécurité, tests, sauvegardes, doc | 10 ✅ | tous | — |

---

## 3. Schéma de la base de données

Les 41 tables (+ `migrations`) sont **créées dès la phase 1** dans
`database/migrations/` afin que les phases suivantes n'aient jamais à
restructurer des tables contenant déjà des données.

### 3.1 Noyau (`000001_create_core_tables`)

| Table | Rôle | Clés importantes |
|---|---|---|
| `settings` | Paramètres clé/valeur typés (`string,int,bool,json,encrypted,text`) | `key` unique |
| `roles` | 8 rôles système + rôles personnalisés | `slug` unique, `is_system` |
| `permissions` | 36 permissions regroupées par module | `slug` unique |
| `role_permissions` | Matrice N-N | PK (role_id, permission_id) |
| `users` | Comptes (hash Argon2id), statut, thème, changement forcé du mot de passe, suppression logique | `username`, `email` uniques |
| `login_attempts` | Journal des connexions + limitation des tentatives | index (identifier, date), (ip, date) |
| `rate_limits` | Limiteur de débit des API | `key` |
| `audit_logs` | Qui / quoi / quand / IP / avant-après (JSON) | index utilisateur, entité, action |
| `backups` | Historique des sauvegardes (taille, SHA-256) | |
| `jobs` | File de tâches (poussée de tickets, sync…) | index (queue, status, available_at) |

### 3.2 Réseau (`000002_create_network_tables`)

| Table | Rôle |
|---|---|
| `sites` | Établissements / emplacements |
| `routers` | Hôte, ports API/API-SSL, identifiant API, **mot de passe chiffré** (`api_password_enc`), modèle, version RouterOS, état (`online/offline/unknown`), échecs consécutifs, intervalle de sync |
| `router_interfaces` | Interfaces récupérées (`.id` RouterOS, MAC, running, compteurs) |
| `router_status_logs` | Relevés périodiques : CPU, RAM, uptime, débit, utilisateurs actifs, latence |
| `hotspot_profiles` | Profils `/ip/hotspot/user/profile` synchronisés |
| `router_sync_logs` | Historique de chaque synchronisation (succès/échec, durée) |

### 3.3 Commercial (`000003_create_commercial_tables`)

| Table | Rôle |
|---|---|
| `resellers` | Compte revendeur, modèle `wholesale`/`commission`, taux, solde, plafond de crédit |
| `reseller_routers` | Routeurs autorisés par revendeur |
| `offers` | Prix, validité, temps cumulé (`limit-uptime`), quota (`limit-bytes-total`), débits, utilisateurs simultanés, liaison MAC, **mode d'activation** et **mode de validité**, dates, visibilité en ligne |
| `offer_router` | Offre ↔ routeur ↔ profil Hotspot |
| `ticket_batches` | Lot de génération : préfixe, longueurs, jeu de caractères, progression de la poussée vers le routeur |
| `tickets` | **`username` unique sur tous les routeurs**, mot de passe chiffré, `.id` RouterOS, statut (`available, sold, active, used, expired, suspended, cancelled`), dates de vente/1re utilisation/expiration, consommation, `on_router`, `version` (verrou optimiste) |
| `ticket_print_templates` | Modèles : individuel, planches 10/20/40, thermique 58/80 mm, A4 ; champs sélectionnables |

### 3.4 Ventes (`000004_create_sales_tables`)

| Table | Rôle |
|---|---|
| `payment_methods` | Espèces, Nita, Airtel Money, Moov Money, Amana, carte, solde revendeur ; configuration chiffrée ; **désactivés tant que non configurés** |
| `cash_registers`, `cash_sessions` | Ouverture / clôture, montant attendu vs déclaré, écart |
| `sales` | Référence unique, canal (`physical/online/reseller`), caissier, remise, commission, statut de paiement, **`idempotency_key` unique** (anti double-vente) |
| `sale_items` | **`ticket_id` unique** : un ticket ne peut être vendu qu'une fois |
| `payments` | Référence prestataire **unique par prestataire** (anti double-crédit), mode de confirmation (`webhook`, `status_check`…) |
| `refunds` | Remboursements avec approbation |
| `online_orders` | Commandes publiques : jeton d'accès haché, expiration |
| `webhook_events` | Webhooks reçus, signature vérifiée, unicité (provider, event_id) |
| `reseller_transactions` | Grand livre du solde revendeur (`balance_after`) |

### 3.5 Activité (`000005_create_activity_tables`)

| Table | Rôle |
|---|---|
| `wifi_sessions` | Historique des sessions Hotspot (IP, MAC, durée, octets, cause de fin) |
| `notifications`, `notification_reads` | Notifications ciblées (utilisateur ou permission), `dedupe_key` anti-répétition |
| `notification_channels` | E-mail / WhatsApp / SMS (configuration chiffrée) |
| `alert_rules` | Seuils : CPU, RAM, débit, stock, paiement, écart de caisse… |
| `ai_conversations`, `ai_messages` | Historique Optimus IA, avec les **données réelles** ayant servi à chaque réponse |
| `ai_pending_actions` | Opérations proposées par l'IA en attente de **confirmation humaine** |

### 3.6 Intégrité des opérations commerciales (phases 5-7)

* Vente = **transaction SQL** : `SELECT … FOR UPDATE` sur les tickets `available`, création de `sales` + `sale_items`, passage à `sold`.
* Double clic / rejeu : `sales.idempotency_key` unique + verrouillage du bouton côté client.
* Paiement en ligne : statut `confirmed` **uniquement** via webhook signé ou interrogation serveur-à-serveur du prestataire ; jamais via la redirection navigateur.

---

## 4. Connexion à MikroTik RouterOS

### 4.1 Protocole retenu : API RouterOS (binaire)

* **Port 8728** (TCP) ou **8729** (TLS, *API-SSL*, recommandé).
* Échange de *phrases* composées de *mots* préfixés par leur longueur
  (encodage variable 1 à 5 octets) ; une phrase se termine par un mot vide.
* Réponses : `!re` (enregistrement), `!done`, `!trap` (erreur), `!fatal`.
* **Authentification** :
  * RouterOS ≥ 6.43 et v7 : `/login =name=… =password=…` (mot de passe transmis tel quel → **utiliser API-SSL ou un tunnel VPN**).
  * RouterOS < 6.43 : défi MD5 (`=ret=` puis `=response=00…`). Le connecteur gère les deux automatiquement.
* Étiquettes `.tag` pour envoyer des commandes en rafale (création de 1 000 tickets sans attendre chaque réponse).
* L'API REST (RouterOS v7, HTTPS) pourra être ajoutée comme second pilote derrière la même interface `RouterClient`.

### 4.2 Commandes documentées utilisées

| Besoin | Commande RouterOS |
|---|---|
| Identité, ressources, carte | `/system/identity/print`, `/system/resource/print`, `/system/routerboard/print` |
| Interfaces, trafic | `/interface/print`, `/interface/monitor-traffic =interface=… =once=` |
| Profils Hotspot | `/ip/hotspot/user/profile/print`, `…/add`, `…/set` (`rate-limit`, `shared-users`, `session-timeout`) |
| Utilisateurs (tickets) | `/ip/hotspot/user/add =name= =password= =profile= =limit-uptime= =limit-bytes-total= =comment=`, `…/print`, `…/set =disabled=yes`, `…/remove` |
| Clients connectés | `/ip/hotspot/active/print`, `/ip/hotspot/active/remove` (déconnexion) |
| Liaison MAC | `/ip/hotspot/user/set =mac-address=` |
| Journaux | `/log/print` (filtres `?topics=`) |
| Redémarrage | `/system/reboot` (après confirmation + audit) |

### 4.3 Sécurité de la liaison serveur ↔ routeur

1. Créer sur chaque routeur un **groupe dédié** `marwa-api` avec les seules politiques `read, write, api, reboot` et un utilisateur restreint à l'IP du serveur (`/user add … address=IP_SERVEUR`).
2. Restreindre le service : `/ip service set api-ssl address=IP_SERVEUR` et désactiver `api` en clair hors LAN.
3. **Ne jamais exposer 8728/8729 sur Internet** : routeurs distants via VPN (WireGuard, L2TP/IPsec, SSTP) vers le serveur.
4. Mots de passe API chiffrés en base (`Crypto` : libsodium XSalsa20-Poly1305 ou AES-256-GCM) avec la clé `APP_KEY` du `.env` ; jamais renvoyés au navigateur.

### 4.4 Synchronisation multi-routeurs

* `cli/scheduler.php`, lancé chaque minute par le Planificateur Windows, sélectionne les routeurs dont l'intervalle est échu, les interroge (timeout court, plusieurs routeurs traités successivement sans bloquer l'interface), puis écrit `router_status_logs`, `router_sync_logs`, `wifi_sessions`.
* En cas d'échec : `consecutive_failures++`, passage `offline` au-delà du seuil, notification `router_offline` (dédupliquée), **nouvel essai** au cycle suivant avec recul progressif.
* Réconciliation des tickets : comparaison `/ip/hotspot/user/print` (uptime, bytes) ↔ table `tickets` pour détecter première utilisation, consommation et expiration ; un ticket observé utilisé sur le routeur n'est jamais remis « disponible ».

### 4.5 Modes de validité des tickets

| Mode | Mise en œuvre |
|---|---|
| Temps de connexion cumulé | `limit-uptime` natif RouterOS |
| Quota de données | `limit-bytes-total` natif |
| Temps réel écoulé depuis la 1re connexion | Détection de la 1re connexion (sync + script `on-login` du profil écrivant la date dans `comment`) → `expires_at` calculé → désactivation par le planificateur ; script `scheduler` RouterOS de secours si le serveur est injoignable |
| Date fixe / dès la génération | `expires_at` fixé à la création, désactivation planifiée |

---

## 5. Structure des dossiers

```
marwa-connect/
├── .htaccess            Redirige vers public/, protège le reste
├── index.php            Secours si mod_rewrite est désactivé
├── server.php           Routeur pour « php -S » (tests sans Apache)
├── .env.example         Modèle de configuration (le .env est généré à l'installation)
├── app/
│   ├── Core/            App, Router, Request, Response, View, Database, Session,
│   │                    Csrf, Auth, Crypto, Validator, Migrator, Logger, ErrorHandler, Url, Env, Config
│   ├── Controllers/     Contrôleurs web (+ Api/ pour l'API interne JSON)
│   ├── Middleware/      auth, guest, csrf, can:permission, throttle, installed
│   ├── Services/        Audit, Settings, Installer, Backup, LoginThrottle, RateLimiter
│   │                    (+ MikroTik, Tickets, Sales… dans les phases suivantes)
│   ├── Models/          Modèles métier (à partir de la phase 3)
│   ├── Services/MikroTik/  RouterOSClient (protocole binaire), RouterService
│   └── Helpers/         functions.php : e(), url(), asset(), money(), can()…
├── bootstrap/app.php    Autoloader PSR-4, .env, config, gestion d'erreurs
├── config/              app, database, security, mikrotik, permissions, menu
├── database/
│   ├── migrations/      Schéma versionné (jamais rejoué)
│   └── seeds/           Données de référence idempotentes
├── public/              Seul dossier exposé au web
│   ├── index.php        Contrôleur frontal
│   └── assets/          css/, js/, images/, vendor/ (Bootstrap, icônes, Chart.js locaux)
├── resources/views/     layouts/, components/, et une vue par module
├── routes/              web.php, api.php, install.php
├── storage/             logs/, backups/, exports/, cache/, sessions/ (non versionnés)
├── cli/                 migrate.php, backup.php, scheduler.php
├── tests/               simulateur RouterOS + parcours de bout en bout (test uniquement)
└── docs/                Ce dossier
```

---

## 6. Principales interfaces

| Écran | Rôle | Phase |
|---|---|---|
| Assistant d'installation | Prérequis, base de données, super administrateur | 1 ✅ |
| Connexion | Limitation des tentatives, messages neutres | 1 ✅ |
| Gabarit général | Barre supérieure (nom, sélecteur de routeur, indicateur de connexion temps réel, notifications, profil, thème clair/sombre) + **navigation à droite** rétractable (rail d'icônes sur ordinateur, tiroir sur mobile) | 1 ✅ |
| Utilisateurs | Liste filtrable, création, modification, suspension, réinitialisation, suppression logique | 1 ✅ |
| Rôles & permissions | Matrice par module, rôles personnalisés | 1 ✅ |
| Journal d'audit | Actions et connexions, filtres, détail avant/après | 1 ✅ |
| Mon profil | Coordonnées, thème, mot de passe, dernières connexions | 1 ✅ |
| Tableau de bord complet | KPI commerciaux/réseau, graphiques, filtres de période | 2 ✅ |
| Paramètres | Entreprise, logo, couleurs, préfixes, sécurité, sauvegardes | 2 ✅ |
| Routeurs | Liste, fiche technique, test de connexion, interfaces, sessions, journaux, redémarrage | 3 ✅ |
| Offres | CRUD, association routeurs/profils | 4 ✅ |
| Tickets | Générateur, lots, stock, impression (7 formats), PDF | 5 ✅ |
| Caisse | Vente rapide, paiement, reçu, ouverture/clôture | 6 ✅ |
| Revendeurs / espace revendeur | Stocks, commissions, soldes | 7 ✅ |
| Clients Wi-Fi / Monitoring | Sessions actives, déconnexion, graphiques temps réel | 8 ✅ |
| Notifications / Optimus IA | Centre d'alertes, assistant conversationnel | 9 ✅ |
| Page publique d'achat | Choix du forfait, paiement, remise des identifiants | 6 ✅ |

---

## 7. Dépendances et risques techniques

### Dépendances

| Élément | Obligatoire | Note |
|---|---|---|
| PHP ≥ 8.2 avec `pdo_mysql`, `mbstring`, `openssl`, `json`, `ctype` | oui | Inclus dans WampServer 3.3+ |
| `sodium` | recommandé | Sinon repli AES-256-GCM (OpenSSL) |
| `curl` | phases 6/9 | Prestataires de paiement, WhatsApp, IA externe |
| `gd` | recommandé | Logos, QR codes sur les tickets |
| Apache `mod_rewrite` | recommandé | Sans lui : URL en `public/index.php/…` (géré automatiquement) |
| MySQL 5.7+ / MariaDB 10.4+ | oui | Type `JSON` requis |
| Bootstrap 5.3.3, Bootstrap Icons 1.11.3, Chart.js 4.4.4 | embarqués | Aucun CDN |

### Risques et parades

| Risque | Impact | Parade |
|---|---|---|
| Perte de `APP_KEY` | Mots de passe routeurs/tickets illisibles | Sauvegarder `.env` hors du serveur ; l'installation ne régénère jamais une clé existante |
| API RouterOS exposée ou en clair | Prise de contrôle du routeur | API-SSL, VPN, restriction d'adresse, utilisateur à droits limités |
| Routeur hors ligne pendant la génération | Tickets en base mais absents du routeur | `tickets.on_router`, lots `partial`, re-poussée via `jobs` ; un ticket non poussé n'est jamais vendable |
| Divergence base ↔ routeur (ticket utilisé mais « disponible ») | Revente d'un ticket | Réconciliation périodique, verrous `FOR UPDATE`, statuts à sens unique |
| Validité « depuis la 1re connexion » non native | Ticket utilisable trop longtemps | Script `on-login` + planificateur serveur + scheduler RouterOS de secours |
| Paiement confirmé par simple redirection | Fraude | Webhook signé + vérification serveur-à-serveur + unicité des références |
| APIs de paiement nigériennes peu documentées | Délais d'intégration | Interface `PaymentProvider` commune ; chaque connecteur ajouté indépendamment, désactivé par défaut |
| Absence de démon sous Windows | Pas de sync temps réel | Planificateur Windows chaque minute + rafraîchissement AJAX à la demande |
| Gros lots (1 000+ tickets) et `max_execution_time` | Génération interrompue | Insertion par paquets en transaction, poussée asynchrone par `jobs`, envoi en rafale `.tag` |
| Différences RouterOS v6 / v7 | Commandes ou champs différents | Détection de version à la connexion ; adaptateurs par version dans le connecteur |
| Session volée | Accès non autorisé | Cookies HttpOnly/SameSite, régénération d'ID, empreinte navigateur, expiration d'inactivité |
