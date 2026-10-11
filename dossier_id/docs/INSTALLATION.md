# Guide d'installation pas à pas sous WampServer

## Prérequis

| Élément | Version | Remarque |
|---|---|---|
| Windows | 10 / 11 (64 bits) | |
| WampServer | 3.3 ou plus récent | inclut Apache 2.4, PHP 8.x, MySQL 8 et/ou MariaDB 10.6+ |
| PHP | 8.1 minimum (8.2 / 8.3 recommandés) | testé avec PHP 8.3 |
| MySQL / MariaDB | MySQL 8.0+ ou MariaDB 10.6+ | testé avec MariaDB 10.11 |
| Visual C++ Redistributable | celui exigé par WampServer | installé avec WampServer |

Aucune connexion Internet n'est nécessaire après installation : Bootstrap 5.3.3 et Bootstrap Icons
sont fournis localement dans `public/assets/vendor/`.

## Étape 1 — Copier l'application

Copier le dossier `dossier_id` dans `C:\wamp64\www\dossier_id\`.

## Étape 2 — Extensions et réglages PHP

Clic gauche sur l'icône WampServer → **PHP** → **Extensions PHP**, vérifier que sont cochées :
`pdo_mysql`, `sodium`, `gd`, `fileinfo`, `mbstring`, `zip` (recommandées : `intl`, `exif`, `openssl`).

Clic gauche → **PHP** → **php.ini**, ajuster :

```ini
upload_max_filesize = 12M
post_max_size = 14M
max_execution_time = 300
display_errors = Off        ; en production
expose_php = Off
session.cookie_httponly = 1
```

Faites de même pour le php.ini **CLI** (`C:\wamp64\bin\php\php8.3.x\php.ini`), utilisé par les scripts.
Redémarrez les services (clic gauche → **Redémarrer les services**).

Vérifiez aussi qu'Apache a les modules `rewrite_module` et `headers_module` (clic gauche → Apache → Modules).

## Étape 3 — Base de données et utilisateur dédié

Ouvrir phpMyAdmin (<http://localhost/phpmyadmin>) ou la console MySQL, puis exécuter (en
remplaçant le mot de passe) :

```sql
CREATE DATABASE dossier_id CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'dossier_id'@'localhost' IDENTIFIED BY 'UnMotDePasseLongEtUnique!';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES ON dossier_id.* TO 'dossier_id'@'localhost';
-- Facultatif, pour exécuter les tests automatisés :
GRANT ALL PRIVILEGES ON dossier_id_test.* TO 'dossier_id'@'localhost';
FLUSH PRIVILEGES;
```

> N'utilisez pas le compte `root` (sans mot de passe par défaut sous WampServer) pour l'application.
> Définissez un mot de passe pour `root` dans phpMyAdmin.

## Étape 4 — Configuration

```bat
cd C:\wamp64\www\dossier_id
copy config\config.example.php config\config.php
notepad config\config.php
```

Renseigner :
- `db.password` : le mot de passe défini à l'étape 3 ;
- `db.port` : 3306 (MySQL) ou 3307 (MariaDB si WampServer l'utilise sur ce port) ;
- `app.env` : `demo` pour l'environnement de test, `production` pour l'exploitation ;
- `app.base_url` : `/dossier_id/public` (ou `''` avec le VirtualHost de l'étape 7) ;
- `storage.key_dir` : par défaut `C:\wamp64\dossier_id_keys` (hors de `www`) ;
- `app.force_https` : `true` si l'application est servie en HTTPS.

## Étape 5 — Vérification de l'environnement

```bat
C:\wamp64\bin\php\php8.3.x\php.exe scripts\check_env.php
```

Le script vérifie PHP, les extensions, les limites d'envoi, la connexion à la base, les droits
d'écriture, l'emplacement du stockage et des clés. Corrigez tout point marqué `[ERREUR]`.

## Étape 6 — Installation

```bat
C:\wamp64\bin\php\php8.3.x\php.exe scripts\install.php
```

Le script : génère les clés de chiffrement (`storage.key`, `backup.key`), crée les répertoires de
stockage, applique les migrations et crée le premier administrateur (voir
[PREMIER_ADMINISTRATEUR.md](PREMIER_ADMINISTRATEUR.md)). **Notez le mot de passe temporaire affiché.**

Alternative : importer `database/dossier_id.sql` dans phpMyAdmin, puis exécuter quand même
`scripts\install.php` (génération des clés et du premier compte).

Pour un environnement de démonstration seulement :

```bat
C:\wamp64\bin\php\php8.3.x\php.exe scripts\seed_demo.php
```

## Étape 7 — (Recommandé) VirtualHost pointant sur `public/`

Clic gauche WampServer → **Vos VirtualHosts** → **Gestion VirtualHost**, ou ajouter dans
`C:\wamp64\bin\apache\apache2.4.x\conf\extra\httpd-vhosts.conf` :

```apache
<VirtualHost *:80>
    ServerName dossier-id.local
    DocumentRoot "C:/wamp64/www/dossier_id/public"
    <Directory "C:/wamp64/www/dossier_id/public/">
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require local
        # Réseau local du service, par exemple :
        # Require ip 192.168.10.0/24
    </Directory>
</VirtualHost>
```

Ajouter `127.0.0.1 dossier-id.local` dans `C:\Windows\System32\drivers\etc\hosts`, mettre
`'base_url' => ''` dans `config.php`, redémarrer Apache. Accès : <http://dossier-id.local/>.

Sans VirtualHost, les fichiers `.htaccess` (« Require all denied ») protègent `app/`, `config/`,
`storage/`, etc. Vérifiez qu'en ouvrant <http://localhost/dossier_id/storage/> ou
<http://localhost/dossier_id/config/config.php> vous obtenez bien **403 Forbidden**.

## Étape 8 — HTTPS (fortement recommandé si plusieurs postes accèdent au serveur)

Sur un poste unique (accès `localhost`), HTTP est acceptable. Dès que l'application est accessible
depuis le réseau, configurez un certificat (autorité interne du service, ou auto-signé déployé sur
les postes), activez `mod_ssl` et mettez `app.force_https = true`.

## Étape 9 — Premier test

1. Ouvrir l'application, se connecter avec le compte administrateur et le mot de passe temporaire.
2. Définir un mot de passe personnel (imposé).
3. Paramètres → renseigner le nom du service ; Utilisateurs → créer les comptes des agents.
4. Sauvegardes → créer une première sauvegarde et la **vérifier**.

## Dépannage

| Symptôme | Cause probable / solution |
|---|---|
| « Fichier de configuration introuvable » | `config\config.php` absent (étape 4) |
| `SQLSTATE[HY000] [2002]` | MySQL/MariaDB arrêté (icône WampServer non verte) ou mauvais port |
| `SQLSTATE[HY000] [1045]` | identifiant / mot de passe MySQL incorrect |
| Erreur 500 | consulter `storage\logs\app-AAAA-MM.log` et le journal Apache |
| Envoi de fichier refusé « taille autorisée par le serveur » | augmenter `upload_max_filesize` / `post_max_size` (étape 2) |
| Page sans mise en forme | `base_url` incorrect dans `config.php` |
| « Clé de chiffrement introuvable » | exécuter `scripts\install.php` ou restaurer les fichiers de clés |
