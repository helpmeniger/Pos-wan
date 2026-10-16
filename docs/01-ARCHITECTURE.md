# JOB NIGER — 01. Architecture générale et choix techniques

> Phase 1 — Conception. Ce document fixe les décisions techniques. Les phases suivantes
> s'y conforment ; toute dérogation doit être écrite ici.

## 1. Vue d'ensemble

```
 Navigateur (mobile / PC)          Future appli Android
   HTML + Bootstrap 5 + JS (Fetch)        │ JSON (jeton Bearer)
            │ HTTPS, session + CSRF       │
            ▼                             ▼
 ┌──────────────── public/index.php (seul point d'entrée exposé) ───────────────┐
 │ Router ─► Middleware (session, CSRF, auth, rôle, rate-limit, maintenance)     │
 │        ─► Controllers (Web : vues PHP │ Api : JSON)                           │
 │        ─► Validators ─► Policies (qui a le droit ?)                           │
 │        ─► Services (règles métier, transactions SQL)                          │
 │        ─► Repositories (PDO, requêtes préparées) ─► MySQL / MariaDB (InnoDB)  │
 └──────────────────────────────────────────────────────────────────────────────┘
        │                     │                         │
 storage/private/      Passerelles de paiement     File d'envoi notifications
 (pièces, preuves)     (PaymentGatewayInterface)   (e-mail, SMS, push) — CLI cron
        ▲
 public/webhooks/{gateway} ─► WebhookController ─► vérif. signature ─► PaymentService
```

**Principe directeur :** une seule base de code, deux « bords » (Web et API REST `/api/v1`)
qui appellent **les mêmes services**. Aucune règle métier dans les contrôleurs ou les vues ;
c'est ce qui rend l'application Android possible sans réécriture.

## 2. Choix techniques

| Sujet | Décision | Justification |
|---|---|---|
| Langage | **PHP 8.3** (compatible 8.2) | Fourni par WampServer 3.3, typage strict, enums, readonly |
| Framework | **MVC maison léger** + composants Composer ciblés | Installation simple sous Wamp, code lisible et maîtrisé ; pas de magie |
| Dépendances Composer | `vlucas/phpdotenv`, `monolog/monolog`, `phpoffice/phpspreadsheet` (export Excel), `phpunit/phpunit` (dev) | Briques éprouvées, limitées au strict nécessaire |
| Base de données | **MySQL 8.0.16+ ou MariaDB 10.6+**, InnoDB, `utf8mb4_unicode_ci` | Transactions ACID, clés étrangères, CHECK, triggers |
| Accès données | **PDO** (`ERRMODE_EXCEPTION`, `EMULATE_PREPARES=false`) | Requêtes préparées natives contre l'injection SQL |
| Montants | `DECIMAL(15,2)`, arithmétique PHP en **entiers de francs** (classe `Money`) | Aucun flottant ; XOF n'a pas de sous-unité → arrondi à l'unité |
| Frontend | HTML5, **Bootstrap 5.3**, Bootstrap Icons, JS natif (Fetch API) | Léger, responsive, pas de build obligatoire |
| Graphiques | **Chart.js 4** (fichier local dans `public/assets/vendor`) | Fonctionne hors CDN, connexions lentes |
| Carte | **Leaflet + tuiles OpenStreetMap** | Gratuit ; respecter la politique d'usage des tuiles (fournisseur dédié en production) |
| Temps réel | **Interrogation périodique** (8 s, configurable, `since_id`) puis WebSocket (Ratchet/Swoole) en évolution | Compatible Wamp sans démon ; API déjà paginée par curseur |
| Hors-ligne | Service Worker + IndexedDB pour **brouillons** (annonces, messages, candidatures) avec `client_msg_id` anti-doublon | Les opérations financières restent **exclusivement serveur** |
| Tâches planifiées | `php bin/console schedule:run` (Planificateur Windows / cron) | Expirations, validation tacite, rappels, envois, sauvegardes |
| Journalisation | Monolog → `storage/logs/app-YYYY-MM-DD.log` + tables `security_events`, `admin_audit_logs` | Séparation technique / sécurité / audit |
| Tests | **PHPUnit** (Unit, Integration sur base `job_niger_test`, Security) + `tests/Database/*.sql` | Exécutables localement et en CI |
| i18n | Fichiers `lang/fr/*.php` ; clés prêtes pour `ha`, `dje`, `en` | Français au lancement |
| Dates | Stockage heure locale `Africa/Niamey` (UTC+1 fixe), affichage `JJ/MM/AAAA` | Le Niger n'a pas d'heure d'été |

## 3. Arborescence prévue

```
job-niger/
├── app/
│   ├── Core/                 Application, Router, Request, Response, View, Session, Csrf,
│   │                         Database (PDO + transaction()), Container, Config, Validator, Money
│   ├── Controllers/
│   │   ├── Web/              Home, Auth, Jobs, Workers, Profile, Applications, Missions,
│   │   │                     Messages, Negotiations, Payments, Earnings, Disputes, Reviews…
│   │   ├── Admin/            Dashboard, Users, Businesses, Catalog, JobModeration, Missions,
│   │   │                     Transactions, Commissions, Payouts, Refunds, Disputes, Reports,
│   │   │                     Verifications, Support, Settings, Roles, Audit
│   │   ├── Api/V1/           mêmes ressources en JSON
│   │   └── Webhooks/         PaymentWebhookController
│   ├── Middleware/           StartSession, VerifyCsrf, Authenticate, RequireRole,
│   │                         RequirePermission, RateLimit, ForcePasswordChange, SecurityHeaders
│   ├── Models/               objets métier simples (User, JobPost, Mission, Payment…) + Enums
│   ├── Repositories/         un dépôt par agrégat, SQL préparé, pagination
│   ├── Services/
│   │   ├── Auth/             AuthService, PasswordResetService, OtpService
│   │   ├── Jobs/             JobPostService, ApplicationService, InvitationService, SearchService,
│   │   │                     ModerationService, RecommendationService
│   │   ├── Missions/         NegotiationService, ContractService, MissionWorkflowService
│   │   ├── Messaging/        ConversationService, MessageService
│   │   ├── Payment/          PaymentGatewayInterface, Gateways/*, PaymentService,
│   │   │                     WebhookProcessor, ReconciliationService
│   │   ├── Finance/          CommissionService, TransactionService, FinancialLedgerService,
│   │   │                     PayoutService, RefundService, ReceiptService, FinancialReportService
│   │   ├── Disputes/         DisputeService
│   │   ├── Reviews/          ReviewService, ReputationService
│   │   ├── Notifications/    NotificationService, Channels/{InApp,Email,Sms,Push}
│   │   ├── Files/            FileStorageService (finfo, ré-encodage image, noms aléatoires)
│   │   └── Audit/            AuditLogger, SecurityLogger
│   ├── Policies/             JobPostPolicy, MissionPolicy, ConversationPolicy, PaymentPolicy…
│   ├── Validators/           règles de formulaires (serveur)
│   └── Views/                layouts/, partials/, public/, worker/, client/, admin/, emails/
├── bin/console               CLI : migrate, seed, admin:create, schedule:run, backup:run…
├── config/                   app.php, database.php, permissions.php, payment.php, security.php
├── database/
│   ├── schema.sql            ✅ Phase 1
│   ├── migrations/           fichiers versionnés (à partir de la Phase 2)
│   └── seeders/              ✅ 001_reference_data.sql, 002_job_catalogue.sql ; 900_demo_data (Phase 7)
├── docs/                     ✅ conception (ce dossier)
├── lang/fr/
├── public/                   ← DocumentRoot Apache (seul dossier exposé)
│   ├── index.php  .htaccess
│   ├── assets/{css,js,images,vendor}
│   └── uploads/              fichiers PUBLICS uniquement (avatars, portfolio ré-encodés)
├── routes/web.php api.php
├── storage/{logs,cache,private,backups,sessions}   ← hors web, refus .htaccess en plus
├── tests/{Unit,Integration,Security,Database}
├── .env.example  composer.json  README.md  INSTALLATION.md
```

## 4. Sécurité dès la conception (résumé — détail en Phase 6)

| Menace | Contre-mesure retenue |
|---|---|
| Injection SQL | PDO préparé partout ; noms de colonnes de tri en liste blanche |
| XSS | Échappement systématique `e()` dans les vues ; CSP stricte (`script-src 'self'`) ; aucun HTML utilisateur |
| CSRF | Jeton par session sur tout POST/PUT/DELETE web ; API : jeton Bearer + pas de cookie |
| IDOR (ex. `/messages/42`) | Identifiants publics = **UUID** ; chaque accès passe par une **Policy** qui vérifie l'appartenance ; refus journalisé `idor.denied` |
| Mots de passe | `password_hash(PASSWORD_ARGON2ID)` (repli bcrypt), `password_needs_rehash` |
| Force brute | Table `rate_limits` (IP + téléphone), verrouillage temporaire, délai progressif |
| Session | `session_regenerate_id(true)` après connexion, cookies `HttpOnly`, `Secure` (HTTPS), `SameSite=Lax`, expiration d'inactivité |
| Fichiers | Taille max, **MIME réel via finfo**, extension en liste blanche, ré-encodage des images (GD), nom aléatoire, stockage privé hors web, téléchargement via contrôleur |
| Falsification de prix | Les montants ne sont **jamais** lus du navigateur au paiement : ils viennent du contrat figé (hash SHA-256) |
| Faux paiement | Confirmation **uniquement** par webhook signé ou vérification manuelle autorisée ; jamais par la page de retour |
| Rejeu webhook / double traitement | Unicité `(gateway, event_id)`, `(gateway, gateway_reference)`, `idempotency_key` + `SELECT … FOR UPDATE` |
| Altération financière | Triggers : écritures, journaux, transactions, paiements, commissions, contrats, audit en **ajout seul** |
| Abus de privilèges | RBAC fin + principe des 4 yeux sur versements et remboursements |
| Secrets | `.env` hors dépôt ; `.env.example` sans valeur réelle ; aucune clé en base |

L'application **ne sera pas présentée comme inviolable** : tests de sécurité, mises à jour
des dépendances (`composer audit`) et surveillance des journaux sont prévus.

## 5. Évolutivité

- Services sans état → plusieurs serveurs web possibles derrière un répartiteur (sessions en base ou Redis).
- API REST versionnée `/api/v1` → appli Android.
- `PaymentGatewayInterface` → nouvel opérateur = une classe + une ligne `payment_gateways`.
- Notifications par canaux enfichables (`ChannelInterface`).
- Index composites alignés sur les recherches (ville + métier + statut + date), FULLTEXT sur les annonces,
  pagination systématique (offset pour les listes admin, **curseur** pour messages et notifications).
- Données de démonstration marquées `is_demo = 1` et purgeables.

## 6. Hébergement

- **Développement :** WampServer, VirtualHost `job-niger.local` → `C:/wamp64/www/job-niger/public`.
- **Production :** VPS Linux (Apache ou Nginx + PHP-FPM), HTTPS (Let's Encrypt), base non exposée,
  sauvegardes externalisées. Un WampServer directement exposé à Internet **n'est pas recommandé**.
