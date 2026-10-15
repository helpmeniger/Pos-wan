# Installation sur WampServer (Windows 10/11)

## Installation express (automatique, recommandée)

1. Démarrez **WampServer** et attendez que l'icône soit **verte**.
2. Décompressez `marwa.zip` **directement dans `C:\wamp64\www\`**. Vous devez obtenir
   `C:\wamp64\www\marwa\index.php` (et **pas** `C:\wamp64\www\marwa\marwa\…` : dans la fenêtre
   « Extraire tout » de Windows, effacez le `\marwa` final proposé dans le chemin de destination).
3. Double-cliquez sur **`DEMARRER-MARWA.bat`** — ou ouvrez simplement **http://localhost/marwa/**.

C'est tout. Au premier lancement, MARWA :

- trouve tout seul MySQL ou MariaDB de WampServer (utilisateur `root` sans mot de passe,
  ports 3306, 3307 et 3308 essayés) ;
- crée la base `marwa`, toutes les tables, les comptes de démonstration et le fichier `.env` ;
- affiche « Installation terminée » avec la liste des comptes (mot de passe : `Demo2026!`).

Si la base n'est pas trouvée (mot de passe MySQL personnalisé, autre port…), un formulaire
s'affiche pour saisir les paramètres ; l'installation se fait ensuite en un clic.

L'adresse est détectée automatiquement : le dossier peut porter n'importe quel nom
(`http://localhost/<nom-du-dossier>/`), aucune modification de `APP_URL` n'est nécessaire.
Si le module Apache `rewrite_module` est désactivé, MARWA fonctionne quand même
(adresses de la forme `http://localhost/marwa/index.php/connexion`) ; pour des adresses propres,
activez-le : clic gauche Wamp → Apache → Modules Apache → `rewrite_module`.

**Démarrage automatique avec Windows** : double-cliquez une fois sur `DEMARRAGE-AUTO-WINDOWS.bat`.
À chaque ouverture de session, WampServer et MARWA se lancent et le navigateur s'ouvre
(désactivation : `DEMARRAGE-AUTO-WINDOWS.bat /retirer`).

`DEMARRER-MARWA.bat` lance aussi, dans une fenêtre réduite, les tâches de fond
(`bin\worker.php` : vagues d'attribution, expirations, relance des messages). Sans elles,
l'application fonctionne, ces tâches étant aussi déclenchées pendant l'utilisation.

**Mises à jour** : remplacez les fichiers du projet en conservant `.env` et `storage\`.
Les nouvelles migrations sont appliquées automatiquement à l'ouverture suivante.

### Dépannage rapide

| Symptôme | Cause / solution |
|---|---|
| Liste de fichiers ou « Index of /marwa » | Le projet est dans un sous-dossier en trop (`www\marwa\marwa`) : remontez son contenu d'un niveau, ou ouvrez `http://localhost/marwa/marwa/` |
| Page « Installation de MARWA » avec « serveur injoignable » | MySQL/MariaDB non démarré : icône Wamp verte ? |
| « accès refusé » | Mot de passe MySQL personnalisé : saisissez-le dans le formulaire |
| 403 sur toutes les pages | `AllowOverride` désactivé dans la configuration Apache de `www` (Wamp l'active par défaut) |
| 404 sur `http://localhost/marwa/connexion` mais l'accueil fonctionne | `rewrite_module` désactivé : utilisez les liens de l'application (ils passent par `index.php`) ou activez le module |

---

## Installation manuelle (détaillée)

## 1. Prérequis

| Élément | Version | Vérification |
|---|---|---|
| WampServer 64 bits | 3.3+ | icône verte dans la barre des tâches |
| PHP | **8.2** recommandé (8.1 minimum) | clic gauche Wamp → PHP → Version |
| MySQL ou MariaDB | MySQL 8.0.16+ ou MariaDB 10.6+ | clic gauche Wamp → MySQL/MariaDB |
| Apache | 2.4 avec `mod_rewrite` (et `mod_headers` conseillé) | Apache → Modules |
| Extensions PHP | `pdo_mysql`, `mbstring`, `intl`, `curl`, `fileinfo`, `gd`, `zip`, `openssl` | PHP → Extensions PHP |

Composer, Node.js et Docker **ne sont pas nécessaires** pour faire fonctionner MARWA.
(Node.js n'est utile que pour les tests navigateur facultatifs.)

Vérifier PHP en ligne de commande (utiliser le PHP de Wamp) :

```bat
C:\wamp64\bin\php\php8.2.x\php.exe -v
C:\wamp64\bin\php\php8.2.x\php.exe -m | findstr /I "pdo_mysql mbstring intl curl fileinfo gd zip"
```

> Astuce : ajoutez `C:\wamp64\bin\php\php8.2.x` au `PATH` Windows pour taper simplement `php`.
> Attention, le `php.ini` de la ligne de commande est différent de celui d'Apache : activez
> les extensions dans les deux (`C:\wamp64\bin\php\php8.2.x\php.ini` et via le menu Wamp).

## 2. Copier le projet

```bat
cd C:\wamp64\www
git clone <URL-du-dépôt> marwa
```

ou décompresser l'archive dans `C:\wamp64\www\marwa\`.

## 3. Configuration (`.env`)

```bat
cd C:\wamp64\www\marwa
copy .env.example .env
notepad .env
```

Valeurs minimales :

```ini
APP_ENV=local
APP_DEBUG=true
APP_URL=http://marwa.local          # adresse utilisée dans les e-mails ; les pages détectent l'adresse automatiquement
DB_HOST=127.0.0.1
DB_PORT=3306                         # 3307 si vous utilisez MariaDB sous Wamp
DB_DATABASE=marwa
DB_USERNAME=root
DB_PASSWORD=
PAYMENT_DEMO_SECRET=<chaîne aléatoire de 32+ caractères>
```

Générer un secret : `php -r "echo bin2hex(random_bytes(32));"`

## 4. Base de données

**Option A — ligne de commande (recommandée)** :

```bat
php bin\migrate.php
php bin\seed.php                      :: facultatif : données de DÉMONSTRATION
php bin\create-admin.php --email=admin@votre-domaine.ne --name="Prénom Nom"
```

`migrate.php` crée la base `marwa` (utf8mb4) et applique les fichiers de `database/migrations/`
dans l'ordre ; il peut être relancé sans risque (seules les migrations manquantes sont appliquées).

**Option B — phpMyAdmin** : créer la base `marwa` (interclassement `utf8mb4_unicode_ci`), puis
*Importer* `database/marwa_install.sql`. Créer ensuite l'administrateur avec `bin\create-admin.php`.

> Les déclencheurs (triggers) qui rendent le grand livre et le journal d'audit immuables
> nécessitent le privilège `TRIGGER` (l'utilisateur `root` de Wamp l'a). Avec MySQL 8 et la
> journalisation binaire active, si l'erreur 1419 apparaît : `SET GLOBAL log_bin_trust_function_creators = 1;`.

## 5. Apache : VirtualHost `marwa.local` (recommandé)

1. Wamp → *Vos VirtualHosts* → *Gestion VirtualHost* : nom `marwa.local`, chemin
   `C:/wamp64/www/marwa/public` (**le dossier `public`**, pas la racine). Ou copier
   `deploy/apache-vhost-wamp.conf` dans `C:\wamp64\bin\apache\apache2.4.x\conf\extra\httpd-vhosts.conf`.
2. Ajouter dans `C:\Windows\System32\drivers\etc\hosts` (bloc-notes en administrateur) :
   `127.0.0.1 marwa.local`
3. Wamp → *Redémarrer les services*. Ouvrir http://marwa.local

**Sans VirtualHost** (`http://localhost/marwa`) : le fichier `.htaccess` à la racine redirige
tout vers `public/` et bloque `app/`, `config/`, `storage/`, `.env`… Mettre `APP_URL=http://localhost/marwa`.
Vérifier que `mod_rewrite` est activé et que `AllowOverride All` est autorisé pour `C:/wamp64/www`.

Contrôle : http://marwa.local/api/health doit répondre `{"status":"ok",…}` et
http://marwa.local/.env, http://localhost/marwa/config/app.php, …/storage/logs doivent répondre **403/404**.

## 6. Tâches planifiées (attribution, relances, purge GPS)

Le moteur d'attribution avance aussi pendant que les utilisateurs consultent l'application, mais
il faut planifier le worker pour un fonctionnement fiable :

*Planificateur de tâches Windows* → Créer une tâche → Déclencheur : toutes les **1 minute** →
Action : `C:\wamp64\bin\php\php8.2.x\php.exe` avec l'argument `C:\wamp64\www\marwa\bin\worker.php --once`.

Ou laisser une fenêtre ouverte : `php bin\worker.php` (boucle toutes les 10 s).

## 7. Utilisation sur le réseau local (tests sur smartphone Android)

1. Trouver l'IP du PC : `ipconfig` (ex. `192.168.1.20`).
2. Autoriser Apache dans le pare-feu Windows (réseau privé uniquement).
3. VirtualHost : `Require all granted` (au lieu de `Require local`) pour le réseau local.
4. `APP_URL=http://192.168.1.20` et ouvrir cette adresse sur le téléphone.

**Géolocalisation** : les navigateurs n'autorisent l'API de géolocalisation que sur **HTTPS** ou
`localhost`. Sur le réseau local en HTTP, le GPS du téléphone sera refusé : pour tester le suivi
réel sur smartphone, utilisez un certificat (ex. `mkcert` + VirtualHost 443) ou un tunnel HTTPS,
ou testez sur le PC (localhost). La saisie manuelle et le choix sur la carte restent disponibles.

**Exposition sur Internet** : WampServer n'est **pas** une plateforme de production sécurisée.
Pour un accès public, utilisez un VPS avec HTTPS (voir [DEPLOIEMENT_VPS.md](DEPLOIEMENT_VPS.md)).

## 8. Configuration de l'e-mail (facultatif)

Par défaut `MAIL_DRIVER=log` : les e-mails et SMS sont écrits dans `storage/logs/outbox.log`
(aucun envoi réel). Pour envoyer de vrais e-mails : `MAIL_DRIVER=smtp` + `SMTP_HOST`,
`SMTP_PORT=587`, `SMTP_USER`, `SMTP_PASSWORD`, `SMTP_ENCRYPTION=tls` (compte de messagerie
professionnel ou fournisseur transactionnel).

## 9. Lancer les tests

```bat
php tests\run.php
```

La base `marwa_test` (paramètre `DB_TEST_DATABASE`) est **supprimée puis recréée** à chaque
exécution ; elle doit être différente de la base principale. Le test de concurrence multi-processus
est ignoré sous Windows (extension `pcntl` absente) : il s'exécute sous Linux.

## 10. Dépannage

| Symptôme | Cause probable / solution |
|---|---|
| Page blanche ou erreur 500 | Voir `storage/logs/app-AAAA-MM-JJ.log` et `storage/logs/php-errors.log`. Mettre `APP_DEBUG=true` en local. |
| 404 sur toutes les pages sauf l'accueil | `mod_rewrite` désactivé ou `AllowOverride None`. |
| « Votre session a expiré » à chaque envoi | `APP_URL` ne correspond pas à l'adresse utilisée dans le navigateur (cookie de session sur un autre chemin). |
| `could not find driver` | Extension `pdo_mysql` non activée pour la ligne de commande ou pour Apache. |
| `Access denied for user` | Identifiants `DB_*` du `.env` ; MariaDB écoute souvent sur le port 3307 sous Wamp. |
| Export Excel : « extension zip requise » | Activer `zip` dans PHP. |
| Carte grise sans fond | Pas d'accès à `tile.openstreetmap.org` (pare-feu / hors ligne). La saisie d'adresse reste possible. |
| Prix « estimation approximative » | Le serveur OSRM est injoignable : estimation à vol d'oiseau × facteur routier (voir SERVICES_EXTERNES.md). |
| Erreur 1419 à la migration | Voir la note sur les triggers au §4. |
| Le GPS ne fonctionne pas sur le téléphone | HTTPS obligatoire hors `localhost` (voir §7). |
| Téléversement refusé | `upload_max_filesize`/`post_max_size` du php.ini (≥ 5M) et droits d'écriture sur `storage/`. |
