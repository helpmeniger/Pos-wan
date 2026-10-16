# JOB NIGER — 04. Plan de développement et fichiers prévus

| Phase | Contenu | Statut |
|---|---|---|
| 1 — Conception | Architecture, règles métier, finance, schéma SQL, données de référence, test de fumée | ✅ livrée |
| 2 — Socle | Config/.env, PDO, routeur, middleware, vues, auth (inscription, connexion, reset, OTP), RBAC, `admin:create`, migrations | ⏭ suivante |
| 3 — Fonctionnel | Profils, catalogue, annonces, candidatures, invitations, recherche, messagerie, négociation, contrats, workflow missions, avis | |
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

## Fichiers prévus en Phase 2 (socle)
```
composer.json  .env.example  .gitignore  public/index.php  public/.htaccess  .htaccess (refus racine)
bin/console
config/{app,database,security,permissions,payment}.php
app/Core/{Application,Container,Config,Env,Router,Request,Response,View,Session,Csrf,Database,Validator,Money,Uuid,Paginator}.php
app/Middleware/{StartSession,SecurityHeaders,VerifyCsrf,Authenticate,RequirePermission,RequireRole,RateLimit,ForcePasswordChange}.php
app/Services/Auth/{AuthService,PasswordResetService,OtpService,PermissionService}.php
app/Services/Audit/{AuditLogger,SecurityLogger}.php
app/Repositories/{UserRepository,RoleRepository,SettingRepository}.php
app/Controllers/Web/{HomeController,AuthController,PasswordController}.php
app/Views/layouts/{app,admin,auth}.php  app/Views/partials/*  app/Views/auth/*  app/Views/public/home.php
public/assets/css/app.css  public/assets/js/app.js  public/assets/images/logo.svg
routes/{web,api}.php
database/migrations/2026_10_16_000001_initial_schema.php (enveloppe schema.sql)
app/Console/Commands/{Migrate,Seed,AdminCreate,ScheduleRun}.php
tests/Unit/{MoneyTest,ValidatorTest,CsrfTest}.php  tests/Security/{LoginRateLimitTest,SessionFixationTest}.php
phpunit.xml
```

## Intégrations externes à configurer (aucune n'est active)
| Intégration | Nécessite | État |
|---|---|---|
| Mobile Money intégré (agrégateur ou opérateurs) | Contrat marchand, identifiants API, clé de signature webhook, validation réglementaire BCEAO | A_CONFIGURER |
| Carte bancaire (PSP agréé UEMOA) | Contrat, page de paiement hébergée, clés | A_CONFIGURER |
| Virement bancaire | Compte bancaire de la société | A_CONFIGURER |
| SMS (OTP, notifications) | Fournisseur SMS local, identifiant expéditeur | Désactivé (`auth.phone_otp_enabled = 0`) |
| E-mail transactionnel | Serveur SMTP / service d'envoi, domaine (SPF/DKIM) | À configurer en Phase 2 |
| Tuiles cartographiques | Fournisseur de tuiles OSM pour la production | Usage de dév. uniquement |
| Notifications push | Firebase Cloud Messaging (appli Android) | Futur |
| Validation juridique | CGU, confidentialité, statut fiscal (TVA sur commissions), droit du travail | **Obligatoire avant lancement** |

## Préalables avant commercialisation
La plateforme ne sera **pas** présentée comme prête à être commercialisée tant que : les tests des phases 2 à 6
ne sont pas exécutés et réussis, un audit de sécurité n'a pas été réalisé, les paiements intégrés ne sont pas
contractualisés et testés en conditions réelles, et les obligations légales n'ont pas été validées.
