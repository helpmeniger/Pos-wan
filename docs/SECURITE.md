# Sécurité

## Mesures en place (vérifiées par les tests lorsque indiqué)

| Domaine | Mesure | Où |
|---|---|---|
| Mots de passe | **Argon2id** (repli bcrypt coût 12), jamais stockés ni journalisés en clair, re-hachage automatique | `Auth::hashPassword`, `AuthService` — testé |
| Connexion | Message identique compte existant/inexistant, temps constant, verrouillage 15 min après 5 échecs, limite 30 tentatives / 15 min / IP | `AuthService::attempt` — testé |
| Sessions | Cookie HttpOnly + SameSite=Lax + Secure (HTTPS), `use_strict_mode`, régénération à la connexion et toutes les 15 min, expiration d'inactivité (2 h) et absolue (12 h), liaison au navigateur (empreinte User-Agent), stockage dans `storage/sessions` | `Session`, `Auth` |
| CSRF | Jeton par session obligatoire pour toute requête modifiante par cookie (`X-CSRF-Token`) | `Kernel` — testé |
| Injections SQL | 100 % requêtes préparées PDO (`ATTR_EMULATE_PREPARES=false`) ; noms de colonnes uniquement issus du code ; `sql_mode` strict | `Database` |
| XSS | Échappement systématique (`e()` côté PHP, `MW.esc()` / `textContent` côté JS) ; **CSP stricte** `script-src 'self'` (aucun script en ligne, Leaflet hébergé localement) ; `X-Content-Type-Options`, `X-Frame-Options: DENY`, `Referrer-Policy`, `Permissions-Policy` | `Kernel::secureHeaders` |
| Validation | Liste blanche des champs, typage, bornes, formats (téléphone Niger, dates JJ/MM/AAAA, coordonnées) ; prix et totaux **recalculés côté serveur** | `Validator`, services — testé |
| Contrôle d'accès | Middlewares `auth`/`role`/`perm` sur chaque route **et** contrôle de propriété dans les services (`OrderPolicy`, solde partenaire, commerce propriétaire) ; minimisation des données selon le rôle (position arrondie et sans identité pour un prestataire non affecté, numéros masqués) | `OrderPolicy` — testé |
| Rôles du personnel | `admin` (tout), `support` (utilisateurs, commandes, réclamations, validations — **sans** finance), `finance` (paiements, remboursements, versements, rapports — **sans** paramétrage) | migration 004 — testé |
| Limitation de débit | Connexion, inscription, OTP, mot de passe oublié, estimation, géocodage, création de commande (12/h), messages, contre-propositions, GPS, webhooks | `RateLimiter`, `throttle:` — testé |
| Anti-abus | Commandes simultanées limitées, bornes de prix négocié, nombre de contre-propositions/modifications limité, filtrage des numéros et liens dans la messagerie | services — testé |
| Double authentification | TOTP (RFC 6238, vérifié avec les vecteurs officiels) + 8 codes de secours à usage unique, anti-rejeu, secret chiffré AES-256-GCM ; obligatoire pour le personnel via *Paramètres* | `SecurityService` — testé |
| Appareils | Déconnexion de tous les appareils, sessions invalidées au changement de mot de passe, alerte « connexion depuis un nouvel appareil » | testé |
| Mots de passe faibles | Refus des mots de passe courants (azerty, niamey2026…) et de ceux contenant le nom, l'e-mail ou le téléphone | testé |
| Administration | Accès limitable à des adresses IP (`ADMIN_ALLOWED_IPS`, CIDR accepté) | testé |
| Notifications push | Services de push officiels uniquement (anti-SSRF), messages chiffrés de bout en bout (RFC 8291), désabonnement à la déconnexion | testé |
| Serveur | `deploy/securiser-serveur.sh` : pare-feu, fail2ban (SSH, échecs de connexion MARWA, Nginx), mises à jour automatiques, SSH, noyau, MariaDB local, PHP ; `bin/security-check.php` | |
| Téléversements | Taille max. (4 Mo), type vérifié **par le contenu** (`finfo`), liste blanche JPEG/PNG/WebP/PDF, nom aléatoire, stockage hors `public/`, images **ré-encodées** (suppression EXIF/GPS), servis par PHP après contrôle d'accès | `UploadService` — testé |
| Paiements | Confirmation **uniquement** par notification serveur signée (HMAC-SHA256, comparaison à temps constant), contrôle du montant, journal des webhooks, idempotence (`Idempotency-Key`, `event_id` unique, une seule intention active par commande), aucune capture d'écran acceptée comme preuve | `PaymentService` — testé |
| Grand livre | Écritures équilibrées obligatoires, clés de déduplication, **triggers SQL interdisant modification et suppression** | migration 002 — testé |
| Audit | Journal immuable : connexions du personnel, tarifs, commissions, validations, remboursements, versements, reversements, dépenses, suspensions, paramètres, exports | `Audit`, `audit_logs` — testé |
| Secrets | `.env` hors du dossier public, refusé par `.htaccess` et Nginx ; aucun secret dans le code ni dans les journaux (masquage) | |
| Exposition | Seul `public/` est servi ; `.htaccess` racine bloque `app/ config/ storage/ database/ bin/ tests/ .git .env` | vérifié sur Apache 2.4 + mod_php (VirtualHost et sous-dossier) : réponses 403 |
| Comptes supprimés | Anonymisation des données personnelles, conservation des écritures financières | `AuthService::deleteAccount` |
| Géolocalisation | Partage uniquement en ligne / pendant une commande, arrêt et effacement de la position hors ligne, historique purgé | `TrackingService` |
| Exports tableur | Neutralisation des formules (`=`, `+`, `-`, `@`) | `CsvExporter` |

## Mise en production — liste de contrôle

- [ ] `APP_ENV=production`, `APP_DEBUG=false`, `APP_DEMO=false`, `PAYMENT_DEMO_ENABLED=false`
- [ ] HTTPS partout (certificat valide) et `SESSION_SECURE_COOKIE=true` (active aussi HSTS)
- [ ] Le serveur web ne sert que `public/` ; vérifier que `/.env`, `/config/app.php`, `/storage/logs/` renvoient 403/404
- [ ] Utilisateur MySQL dédié (pas `root`), mot de passe fort, MySQL non exposé sur Internet
- [ ] `PAYMENT_DEMO_SECRET` et futurs secrets de fournisseurs longs et aléatoires ; `.env` en `chmod 640`
- [ ] Aucune donnée de démonstration (`users.is_demo = 0` partout) ; comptes du personnel nominatifs
- [ ] Sauvegardes quotidiennes **hors serveur** + test de restauration
- [ ] `TRUSTED_PROXIES` renseigné si un proxy/CDN est utilisé
- [ ] Mises à jour de sécurité du système, de PHP et de MariaDB planifiées
- [ ] Revue des textes CGU / confidentialité / numéros d'urgence par un juriste (voir CONFORMITE.md)
- [ ] Test d'intrusion recommandé avant l'ouverture d'un paiement électronique réel
- [ ] Activer la double authentification pour chaque compte du personnel, puis *Paramètres* → « Double authentification obligatoire »
- [ ] Lancer `sudo bash deploy/securiser-serveur.sh` puis `php bin/security-check.php --url=https://votre-domaine` (aucun point « CRITIQUE »)
- [ ] Sauvegarder hors serveur `storage/app.key` et `storage/vapid.json` (inclus dans `bin/backup.php`) : sans eux, les doubles authentifications et les notifications push cessent de fonctionner

## Points connus / améliorations recommandées

- Le limiteur de débit est stocké en base (fenêtre fixe) ; en forte charge, préférer Redis.
- L'empreinte de session repose sur le User-Agent (protection partielle contre le vol de cookie ;
  la vraie protection reste HTTPS + HttpOnly).
- Antivirus des fichiers téléversés : non intégré (les images sont ré-encodées, les PDF non).
