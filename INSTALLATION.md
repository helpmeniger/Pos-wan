# Installation de JOB NIGER sous WampServer (Windows)

## ⚡ Installation simple et automatique (recommandée, 3 minutes)

1. **Démarrez WampServer** et attendez que son icône soit **verte**.
2. **Décompressez** `job-niger.zip` dans `C:\wamp64\www\`
   → vous obtenez le dossier `C:\wamp64\www\job-niger` (avec `app`, `public`, `vendor`… directement dedans).
3. Ouvrez votre navigateur sur **<http://localhost/job-niger/>**
   → l'**assistant d'installation** s'ouvre tout seul.
4. L'assistant vérifie votre ordinateur (version de PHP, extensions, droits d'écriture, module Apache *rewrite*).
   Si un point est en rouge, l'assistant indique quoi cocher dans le menu WampServer.
5. Remplissez le formulaire :
   - Base de données : serveur `127.0.0.1`, port `3306` (MySQL) ou `3307` (MariaDB), utilisateur `root`,
     mot de passe **vide** (valeurs par défaut de WampServer). La base `job_niger` est créée automatiquement.
   - Votre compte administrateur : nom, téléphone (qui sert d'identifiant), mot de passe.
6. Cliquez sur **« Installer JOB NIGER »**. Quelques secondes plus tard, la page de connexion s'affiche :
   connectez-vous avec le téléphone et le mot de passe choisis.

C'est tout : tables, 221 métiers, rôles, paramètres et fichier `.env` (avec une clé secrète unique) sont créés automatiquement.
L'assistant se désactive dès que l'installation est terminée. Il n'est accessible que depuis l'ordinateur lui-même
ou le réseau local.

> **Pour réinstaller à zéro** : supprimez le fichier `C:\wamp64\www\job-niger\.env` (et, si vous le souhaitez, la base `job_niger`
> dans phpMyAdmin), puis rouvrez <http://localhost/job-niger/>.

### Problèmes fréquents

| Ce que vous voyez | Solution |
|---|---|
| **Erreur 403 « Forbidden »** | Vous utilisez une version antérieure au 25/10/2026 : remplacez le dossier par celui du nouveau zip. Vérifiez aussi que le dossier s'appelle bien `job-niger` et contient directement `public`, `app`… (pas `job-niger\job-niger\…`). |
| Page « activez le module rewrite » | Icône WampServer → **Apache** → **Modules Apache** → cocher `rewrite_module`, puis recharger la page. |
| « Accès refusé pour l'utilisateur root » | Vous avez défini un mot de passe MySQL : saisissez-le dans l'assistant. |
| « Aucun serveur MySQL ne répond » | Icône WampServer pas verte, ou mauvais port : essayez `3306` puis `3307`. |
| « La base contient déjà … table(s) » | Choisissez un autre nom de base (ex : `job_niger2`) ou videz-la dans phpMyAdmin. |
| Page d'erreur après une ancienne installation manuelle | Supprimez le fichier `.env` puis rechargez : l'assistant reprend la main. |

---

# Installation manuelle et configuration avancée

> Les sections suivantes s'adressent aux personnes qui préfèrent la ligne de commande, veulent un nom de domaine local
> (`job-niger.local`) ou préparent la mise en ligne.

## 1. Vérifier WampServer et PHP

1. Installez **WampServer 3.3 ou plus récent** (64 bits) et ses prérequis Visual C++.
2. Clic gauche sur l'icône Wamp → **PHP → Version** : choisissez **PHP 8.2 ou 8.3**.
3. Clic gauche → **PHP → Extensions PHP**, et vérifiez que sont cochées :
   `pdo_mysql`, `mbstring`, `fileinfo`, `openssl`, `intl`, `gd`, `sodium`, `curl`.
4. Dans un terminal (PowerShell) :
   ```powershell
   php -v
   php -m
   ```
   Si `php` n'est pas reconnu, ajoutez `C:\wamp64\bin\php\php8.3.x` à la variable d'environnement `PATH`.
   ⚠️ Le PHP en ligne de commande doit être le **même** que celui d'Apache.

## 2. Installer Composer

Téléchargez **Composer-Setup.exe** sur <https://getcomposer.org>, sélectionnez le `php.exe` de Wamp, puis :
```powershell
composer -V
```

## 3. Récupérer le projet

Placez le code dans `C:/wamp64/www/job-niger` (clone Git ou archive), puis :
```powershell
cd C:\wamp64\www\job-niger
composer install
```
(En production : `composer install --no-dev --optimize-autoloader`.)

## 4. Créer la base et l'utilisateur MySQL

Ouvrez **phpMyAdmin** (`http://localhost/phpmyadmin`) ou la console MySQL de Wamp, et exécutez
(remplacez le mot de passe par un mot de passe fort) :
```sql
CREATE DATABASE job_niger CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE job_niger_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'job_niger_app'@'localhost' IDENTIFIED BY 'UnMotDePasseFort!2026';
GRANT ALL PRIVILEGES ON job_niger.* TO 'job_niger_app'@'localhost';
GRANT ALL PRIVILEGES ON job_niger_test.* TO 'job_niger_app'@'localhost';
FLUSH PRIVILEGES;
```
N'utilisez pas le compte `root` pour l'application.

## 5. Configurer le fichier `.env`

```powershell
copy .env.example .env
php bin/console key:generate
```
Éditez `.env` :
```ini
APP_ENV=local
APP_DEBUG=true
APP_URL=http://job-niger.local
DB_HOST=127.0.0.1
DB_DATABASE=job_niger
DB_USERNAME=job_niger_app
DB_PASSWORD=UnMotDePasseFort!2026
DB_TEST_DATABASE=job_niger_test
```
Le fichier `.env` ne doit **jamais** être commité ni envoyé à quelqu'un.

## 6. Créer les tables et les données de référence

```powershell
php bin/console migrate
php bin/console db:check
```
`migrate` crée le schéma (`database/schema.sql`), charge les rôles, permissions, villes, catalogue des 221 métiers,
paramètres et règle de commission (10 % à la charge du prestataire), puis applique les migrations de `database/migrations/`.
La commande peut être relancée sans risque : elle n'applique que ce qui manque.

> Si MySQL refuse la création des triggers (« you do not have the SUPER privilege… binary logging »), exécutez une fois
> en tant que root : `SET GLOBAL log_bin_trust_function_creators = 1;`

**Alternative sans terminal** : dans phpMyAdmin → base `job_niger` → *Importer*, importez dans l'ordre
`database/schema.sql`, `database/seeders/001_reference_data.sql`, `database/seeders/002_job_catalogue.sql`,
puis chaque fichier de `database/migrations/` (voir `database/migrations/README.md`).

## 7. Configurer Apache (VirtualHost)

1. Activez `mod_rewrite` et `mod_headers` : icône Wamp → **Apache → Modules Apache**.
2. Icône Wamp → **Apache → httpd-vhosts.conf**, ajoutez :
   ```apache
   <VirtualHost *:80>
       ServerName job-niger.local
       DocumentRoot "C:/wamp64/www/job-niger/public"
       <Directory "C:/wamp64/www/job-niger/public/">
           Options -Indexes +FollowSymLinks
           AllowOverride All
           Require local
       </Directory>
       ErrorLog "${INSTALL_DIR}/logs/job-niger-error.log"
       CustomLog "${INSTALL_DIR}/logs/job-niger-access.log" common
   </VirtualHost>
   ```
   Le DocumentRoot pointe sur **`public/`** : le reste du projet (`.env`, `config/`, `storage/`, `vendor/`) n'est jamais exposé.
3. Ouvrez `C:\Windows\System32\drivers\etc\hosts` **en administrateur** et ajoutez :
   ```
   127.0.0.1   job-niger.local
   ```
4. Icône Wamp → **Redémarrer les services**, puis ouvrez <http://job-niger.local>.

Astuce : Wamp propose aussi **« Ajouter un Virtual Host »** depuis `http://localhost` (rubrique *Outils*) ; indiquez alors bien le dossier `public`.

**Sans VirtualHost** : `http://localhost/job-niger/` fonctionne directement (le fichier `.htaccess` de la racine
renvoie vers `public/` et bloque les dossiers internes). Le sous-dossier est détecté automatiquement.

## 8. Permissions des dossiers

Le compte qui exécute Apache doit pouvoir **écrire** dans :
`storage/logs`, `storage/sessions`, `storage/cache`, `storage/private`, `storage/backups`, `public/uploads`.
Sous Windows/Wamp c'est le cas par défaut. Sur un serveur Linux :
```bash
chown -R www-data:www-data storage public/uploads
chmod -R 750 storage && chmod 640 .env
```

## 9. Créer le compte administrateur

```powershell
php bin/console admin:create --phone="90 00 00 01" --first=Prénom --last=Nom --email=admin@exemple.ne
```
La commande affiche **une seule fois** un mot de passe temporaire aléatoire. À la première connexion
(`/connexion`), le changement de mot de passe est **imposé** avant tout accès. Aucun identifiant n'est écrit dans le code.

Autres rôles du personnel : `--role=ADMIN`, `--role=FINANCE_ADMIN`, `--role=MODERATOR`, `--role=SUPPORT`.

## 10. Lancer les tests

```powershell
vendor\bin\phpunit                       # tous les tests (108)
vendor\bin\phpunit --testsuite Unit      # tests unitaires seuls (sans base)
```
Les tests d'intégration **recréent entièrement** la base `job_niger_test` (jamais `job_niger`) : le nom de la base
de test doit contenir « test », sinon les tests refusent de s'exécuter.

## 11. Tâches planifiées

Planificateur de tâches Windows → *Créer une tâche* → toutes les 5 minutes :
```
Programme : C:\wamp64\bin\php\php8.3.x\php.exe
Arguments : C:\wamp64\www\job-niger\bin\console schedule:run
```
(Linux : `*/5 * * * * php /var/www/job-niger/bin/console schedule:run`.)

## 12. Vérifier les journaux

| Journal | Emplacement |
|---|---|
| Erreurs de l'application | `storage/logs/app-AAAA-MM-JJ.log` |
| E-mails (mode développement) | `storage/logs/mail.log` |
| SMS (mode développement) | `storage/logs/sms.log` |
| Événements de sécurité | table `security_events` |
| Actions administratives | table `admin_audit_logs` (`php bin/console audit:verify`) |
| Apache | `C:/wamp64/logs/job-niger-error.log` |

## 13. Tester les fonctions essentielles

1. Accueil, catégories de métiers, pages d'aide et légales.
2. Inscription d'un prestataire, d'un particulier et d'une organisation.
3. Déconnexion / connexion par téléphone (formats `90 12 34 56`, `+22790123456`).
4. Mot de passe oublié → le lien apparaît dans `storage/logs/sms.log` (mode développement).
5. 5 mots de passe erronés → compte verrouillé 15 minutes.
6. Connexion administrateur → changement de mot de passe imposé → tableau de bord d'administration.
7. Un prestataire qui ouvre `/admin` reçoit une erreur 403.

## 14. Accès depuis un smartphone du même réseau Wi-Fi

1. Trouvez l'adresse IP du PC : `ipconfig` (ex : `192.168.1.20`).
2. Dans le VirtualHost, ajoutez `ServerAlias 192.168.1.20` et remplacez `Require local` par `Require ip 192.168.1`
   (votre sous-réseau uniquement). Redémarrez Wamp.
3. Autorisez Apache dans le pare-feu Windows (réseau **privé** seulement).
4. Sur le smartphone : `http://192.168.1.20`. Mettez `APP_URL=http://192.168.1.20` pendant ce test pour que les liens
   envoyés (réinitialisation de mot de passe) soient corrects.

## 15. Mise en ligne sur Internet

**Un WampServer exposé directement à Internet n'est pas recommandé pour la production** (configuration de
développement, mises à jour Windows/Apache non gérées, pas de HTTPS par défaut).

Recommandé : un VPS Linux (Ubuntu LTS) ou un hébergement PHP 8.2+/MySQL 8 avec accès SSH :
1. Apache ou Nginx + PHP-FPM, DocumentRoot sur `public/`.
2. HTTPS obligatoire (Let's Encrypt / certbot), puis dans `.env` : `APP_ENV=production`, `APP_DEBUG=false`,
   `APP_URL=https://votre-domaine`, `SESSION_SECURE_COOKIE=true`, `SECURITY_HSTS=true`.
3. `composer install --no-dev --optimize-autoloader`, `php bin/console migrate`.
4. MySQL accessible uniquement en local (pas de port 3306 ouvert sur Internet), compte applicatif dédié.
5. Dans `php.ini` : `expose_php = Off`, `display_errors = Off`, `session.cookie_secure = 1`.
6. Pare-feu (ports 22, 80, 443 uniquement), mises à jour automatiques de sécurité, sauvegardes externalisées
   (le guide de sauvegarde et de restauration est livré en Phase 6).
7. Derrière un proxy inverse ou un CDN : renseignez `TRUSTED_PROXIES` avec ses adresses.

## Dépannage

| Symptôme | Cause probable |
|---|---|
| « Dépendances absentes » | `composer install` n'a pas été exécuté |
| Erreur 500 sans détail | Mettre `APP_DEBUG=true` **en local uniquement**, consulter `storage/logs` |
| Toutes les pages sauf l'accueil en 404 | `mod_rewrite` inactif ou `AllowOverride` différent de `All` |
| « Votre session a expiré » (419) | Formulaire resté ouvert plus de 2 h, ou cookies bloqués : recharger la page |
| `SQLSTATE[HY000] [1045]` | Identifiants MySQL de `.env` incorrects |
| `SQLSTATE[HY000] [2002]` | MySQL arrêté : démarrer les services Wamp |
