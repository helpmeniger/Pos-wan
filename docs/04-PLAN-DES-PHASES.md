# JOB NIGER — 04. Plan de développement et fichiers prévus

| Phase | Contenu | Statut |
|---|---|---|
| 1 — Conception | Architecture, règles métier, finance, schéma SQL, données de référence, test de fumée | ✅ livrée |
| 2 — Socle | Config/.env, PDO, routeur, middleware, vues, auth (inscription, connexion, reset, OTP), RBAC, `admin:create`, migrations | ✅ livrée |
| 3 — Fonctionnel | Profils, catalogue, annonces, candidatures, invitations, recherche, messagerie, négociation, contrats, workflow missions, avis | ⏭ suivante |
| 4 — Finance | Money, Commission, Ledger, Transaction, Payment + passerelles, Webhook, Refund, Payout, reçus, rapports, exports | |
| 5 — Administration | Tableaux de bord, utilisateurs, entreprises, modération, vérifications, litiges, signalements, paramètres, rôles, audit | |
| 6 — Sécurité & qualité | PHPUnit (unit/intégration/sécurité), en-têtes, CSP, upload, rate-limit, sauvegardes, journalisation | |
| 7 — Déploiement | Données de démo, INSTALLATION.md (Wamp), accès smartphone LAN, guide production, sauvegarde/restauration | |

## Fichiers livrés en Phase 1
```
README.md
docs/01-ARCHITECTURE.md        décisions techniques, arborescence, sécurité, évolutivité
docs/02-REGLES-METIER.md       rôles, vérifications, âge/risques, annonces, missions, messagerie, litiges, avis
docs/03-SYSTEME-FINANCIER.md   commissions, modes de paiement, plan de comptes, écritures, webhooks, rapports
docs/04-PLAN-DES-PHASES.md     ce fichier
database/schema.sql            schéma complet (92 tables, 3 vues, 22 triggers)
database/seeders/001_reference_data.sql   langues, villes, quartiers, rôles, permissions, types de mission,
                                          comptes du grand livre, passerelles, commission 10 %, plans, paramètres
database/seeders/002_job_catalogue.sql    21 domaines, 221 métiers (risque, âge, niveau, qualification)
tests/Database/schema_smoke.sql           scénario financier + 30 contrôles d'intégrité
tests/Database/run_schema_smoke.sh
```

## Fichiers livrés en Phase 2 (socle)
```
composer.json  phpunit.xml  .env.example  .htaccess (refus racine)  INSTALLATION.md
bootstrap/app.php              amorçage commun (web, console, tests) et déclaration des services
bin/console                    migrate [--fresh] · db:check · admin:create · key:generate · schedule:run · audit:verify
config/{app,database,security,services,permissions}.php
app/Core/                      Application, Container, Config, Env, Router, Request, Response, View, Session, Csrf,
                               Database, Validator, HttpException, ValidationException, helpers.php
app/Support/                   Money (entiers de francs, points de base), Phone (+227), Uuid, Logger, SqlScriptRunner
app/Middleware/                SecurityHeaders, StartSession, VerifyCsrf, Authenticate, RedirectIfAuthenticated,
                               RequirePermission, RequireRole, ForcePasswordChange, Throttle
app/Services/Auth/             AuthService, RegistrationService, PasswordHasher, PasswordResetService, OtpService,
                               PermissionService, RateLimiter
app/Services/Audit/            AuditLogger (chaînage SHA-256), SecurityLogger
app/Services/Notifications/    NotificationService, MailerInterface (+ LogMailer, PhpMailer), SmsSenderInterface (+ LogSmsSender)
app/Services/Dashboard/        DashboardService (indicateurs réels prestataire / demandeur / administration)
app/Repositories/              UserRepository, SettingRepository, CityRepository, CatalogRepository
app/Controllers/Web/           Home, Page (pages + contact → ticket), Auth, Password, PhoneVerification, Dashboard, Notification
app/Controllers/Admin/         Dashboard
app/Controllers/Api/V1/        Meta (health, villes, quartiers, catégories)
app/Console/Kernel.php
app/Views/                     layouts, partials, public (accueil, catégories, à propos, aide, contact, CGU, confidentialité),
                               auth, dashboard, admin, notifications, errors
routes/{web,api}.php
public/index.php  public/.htaccess  public/uploads/.htaccess  public/robots.txt
public/assets/{css/app.css, js/app.js, images/logo.svg, images/favicon.svg}
public/assets/vendor/          Bootstrap 5.3.3, Bootstrap Icons 1.11.3, Chart.js 4.4.6, Leaflet 1.9.4 (servis localement)
database/migrations/2026_10_17_000001_password_changed_at_precision.sql
tests/Unit (38) · tests/Integration (22) · tests/Feature (11) · tests/Security (11) — 82 tests
```

Écarts assumés par rapport au plan initial :
- Pas de dépendance d'exécution Composer : `Env` et `Logger` maison remplacent phpdotenv et Monolog
  (installation plus simple sous Wamp, surface d'attaque réduite). PHPUnit reste en dépendance de développement.
- Migrations en SQL pur (`database/migrations/*.sql`) exécutées par `bin/console migrate`, importables aussi dans phpMyAdmin.
- `Money` est livré dès la Phase 2 (utilisé pour l'exemple de commission de la page « Comment ça marche »).
- `schema_migrations.version` élargi à 190 caractères dans `schema.sql` (aucune base n'était encore déployée).

## Livré en Phase 3a-3c (recrutement, emploi, paie, administration)
```
database/migrations/2026_10_30_000001_recruitment_employment_payroll.sql   interviews, employments, payslips, payslip_events + 5 déclencheurs
app/Support/References.php                         numéros de documents JN-<PRÉFIXE>-<ANNÉE>-<SÉQUENCE>
app/Services/Files/FileStorageService.php          téléversements (finfo, liste blanche, ré-encodage GD, stockage privé)
app/Services/Finance/LedgerService.php             écritures équilibrées, idempotentes
app/Services/Jobs/{JobPostService,ApplicationService,InterviewService}.php
app/Services/Employment/{EmploymentService,PayrollService,PayslipService}.php
app/Services/Verification/VerificationService.php  documents d'identité / professionnels, décision admin
app/Services/{Profile/ProfileService,Admin/AdminService}.php
app/Controllers/Web/{Job,Candidate,Recruiter,Employment,Payslip,Archive,Profile,Verification,File}Controller.php
app/Controllers/Admin/{User,Verification,Recruitment,Finance,System}Controller.php
tests/Integration/{RecruitmentPayrollTest,VerificationUploadTest}.php, tests/Feature/RecruitmentJourneyTest.php
```
Tâche planifiée : `php bin/console schedule:run` (une fois par jour) génère les fiches de paie dues et termine les
prestations arrivées à échéance ; l'opération est idempotente (une fiche par emploi et par période).

## Reste prévu (fonctionnel)
```
app/Services/Files/FileStorageService.php        téléversements (finfo, ré-encodage GD, stockage privé)
app/Services/Profile/{WorkerProfileService,BusinessService}.php
app/Services/Jobs/{JobPostService,ModerationService,ApplicationService,InvitationService,SearchService,RecommendationService}.php
app/Services/Missions/{NegotiationService,ContractService,MissionWorkflowService}.php
app/Services/Messaging/{ConversationService,MessageService}.php
app/Services/Reviews/{ReviewService,ReputationService}.php
app/Policies/{JobPostPolicy,MissionPolicy,ConversationPolicy,ApplicationPolicy}.php
app/Controllers/Web/{Profile,Jobs,Workers,Applications,Invitations,Missions,Negotiations,Messages,Reviews,Files}Controller.php
app/Controllers/Api/V1/{Auth,Jobs,Messages}Controller.php        jetons Bearer (api_tokens)
app/Views/{profile,jobs,workers,missions,messages,reviews}/*
tests/Integration/{JobPostTest,ApplicationTest,NegotiationContractTest,MissionWorkflowTest,MessagingTest}.php
tests/Security/{IdorTest,UploadTest,PriceTamperingTest}.php
```

## Intégrations externes à configurer (aucune n'est active)
| Intégration | Nécessite | État |
|---|---|---|
| Mobile Money intégré (agrégateur ou opérateurs) | Contrat marchand, identifiants API, clé de signature webhook, validation réglementaire BCEAO | A_CONFIGURER |
| Carte bancaire (PSP agréé UEMOA) | Contrat, page de paiement hébergée, clés | A_CONFIGURER |
| Virement bancaire | Compte bancaire de la société | A_CONFIGURER |
| SMS (OTP, notifications) | Fournisseur SMS local, identifiant expéditeur | Pilote `log` seulement ; OTP désactivé (`auth.phone_otp_enabled = 0`) |
| E-mail transactionnel | Serveur SMTP / service d'envoi, domaine (SPF/DKIM) | Pilote `log` (dév.) ou `mail()` de PHP ; SMTP authentifié à brancher |
| Tuiles cartographiques | Fournisseur de tuiles OSM pour la production | Usage de dév. uniquement |
| Notifications push | Firebase Cloud Messaging (appli Android) | Futur |
| Validation juridique | CGU, confidentialité, statut fiscal (TVA sur commissions), droit du travail | **Obligatoire avant lancement** |

## Préalables avant commercialisation
La plateforme ne sera **pas** présentée comme prête à être commercialisée tant que : les tests des phases 2 à 6
ne sont pas exécutés et réussis, un audit de sécurité n'a pas été réalisé, les paiements intégrés ne sont pas
contractualisés et testés en conditions réelles, et les obligations légales n'ont pas été validées.
