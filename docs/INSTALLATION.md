# Installation de VERIFIA sous Windows (WampServer)

## 1. Prérequis

| Composant | Version | Remarque |
|---|---|---|
| Windows | 10 ou 11, 64 bits | 8 Go de RAM minimum ; 16 Go conseillés au-delà de 3 sessions |
| WampServer | 3.3 ou plus récent | Apache + MySQL 8 ou MariaDB 10.6+ |
| PHP | **8.2 ou plus récent** | extensions `pdo_mysql`, `gd`, `mbstring`, `fileinfo` (actives par défaut dans WampServer) ; `exif` et `intl` conseillées |
| Node.js | **20 LTS** ou plus récent (18.18 minimum) | https://nodejs.org/ — cocher « Add to PATH » |
| Composer | 2.x | https://getcomposer.org/download/ (ou `composer.phar` posé dans le dossier) |
| Navigateur piloté | Chromium (téléchargé par Playwright) **ou** Microsoft Edge | Edge est déjà présent sur Windows |
| Tesseract (facultatif) | 5.x | OCR du texte incrusté dans les images |

Mémoire : chaque session ouvre un navigateur complet (300 à 600 Mo, davantage sur Facebook).
VERIFIA refuse d'ouvrir une session supplémentaire si la limite configurée ou la mémoire libre ne le permet pas.

## 2. Copier le projet

```
C:\wamp64\www\verifia\
```

(clonage Git ou copie du dossier). L'interface sera servie à `http://localhost/verifia/public/`.
Seul `public/` doit être accessible : le fichier `.htaccess` racine bloque `src/`, `config/`, `storage/`, `vendor/`…
Vérifiez que le module Apache `rewrite_module` est actif (WampServer : clic gauche › Apache › Modules).

## 3. Version de PHP

WampServer : clic gauche › PHP › Version › choisir **8.2+**.
Les scripts Windows prennent automatiquement le `php.exe` 8.x le plus récent de `C:\wamp64\bin\php\`.
Pour en imposer un : `set VERIFIA_PHP=C:\wamp64\bin\php\php8.3.6\php.exe` avant de lancer les scripts.

> Le PHP en ligne de commande lit `php.ini` et non `phpForApache.ini` : les extensions doivent y être actives aussi.

## 4. Installation automatique

Démarrer WampServer, attendre l'icône verte, puis double-cliquer :

```
scripts\windows\install.bat
```

Le script :
1. installe les dépendances PHP (Dompdf) avec Composer ;
2. vérifie Node.js, installe Playwright dans `monitor\` et télécharge Chromium ;
3. demande l'utilisateur et le mot de passe MySQL (par défaut `root` sans mot de passe sous WampServer) ;
4. crée `config\config.php` avec un **jeton aléatoire** pour le moteur de surveillance ;
5. crée la base `verifia` et ses 21 tables (le script est rejouable sans perte de données) ;
6. crée le compte administrateur (ou le laisse à créer à la première ouverture de l'interface, depuis le poste local).

Le mot de passe administrateur ne doit contenir ni `&`, ni `^`, ni `%` lorsqu'il est saisi dans le script
(limitation de l'invite de commandes) ; sinon laissez le champ vide et créez le compte dans le navigateur.

### Installation manuelle (équivalente)

```bat
cd C:\wamp64\www\verifia
composer install --no-dev
cd monitor && npm install --omit=dev && npx playwright install chromium && cd ..
C:\wamp64\bin\php\php8.3.6\php.exe bin\install.php --db-user=root --db-pass= --admin-user=admin --admin-pass=UnMotDePasseLong
```

## 5. Configuration (`config\config.php`)

| Clé | Rôle |
|---|---|
| `app.base_url` | URL de l'interface (`http://localhost/verifia/public`) |
| `app.timezone` | fuseau d'affichage (stockage toujours en UTC) |
| `db.*` | connexion MySQL |
| `monitor.token` | secret partagé avec le moteur de surveillance (généré à l'installation) |
| `monitor.allowed_ips` | adresses autorisées sur l'API du moteur (boucle locale uniquement) |
| `monitor.browser_path` / `browser_channel` | navigateur piloté : vide = Chromium de Playwright ; `'msedge'` = Edge installé |
| `monitor.headless` | `false` (recommandé) : les fenêtres sont visibles pour se connecter manuellement |
| `security.allowed_origins` | origines acceptées pour les requêtes de l'interface |

Les paramètres fonctionnels (nombre maximal de sessions, mémoire par navigateur, priorité minimale des alertes,
seuils d'images et de texte, chemin de Tesseract, durée de conservation…) se règlent dans
**Administration** et sont stockés dans la table `application_settings`.

## 6. Démarrage et arrêt

```
scripts\windows\start.bat   vérifie les prérequis, lance les moteurs (fenêtres réduites), ouvre l'interface
scripts\windows\stop.bat    arrêt propre (fermeture des navigateurs), puis arrêt forcé après 12 s si besoin
scripts\windows\check.bat   affiche seulement l'état des prérequis
```

`start.bat` contrôle : version de PHP, extensions, dépendances, dossier `storage\` inscriptible, configuration,
connexion MySQL, schéma installé, Node.js, Playwright, réponse d'Apache. Toute erreur est affichée avec la correction à appliquer.

Chaque moteur tourne dans une boucle de **redémarrage contrôlé** (`run_worker.bat`, `run_monitor.bat`) :
en cas de panne, redémarrage après 5 s (analyse) ou 10 s (surveillance), au plus 20 fois, puis arrêt avec message.
Dans le moteur de surveillance, une session dont le navigateur plante est rouverte jusqu'à 3 fois (délai croissant),
puis passe en « Erreur » jusqu'à ce que l'utilisateur clique sur *Redémarrer*.

Journaux : `storage\logs\worker.log`, `storage\logs\monitor.log`, et l'écran *Journal* de l'interface.

## 7. OCR (facultatif)

1. Installer Tesseract pour Windows (build UB Mannheim) avec les langues française et anglaise.
2. *Administration* › `tesseract_path` = `C:\Program Files\Tesseract-OCR\tesseract.exe`.

Sans Tesseract, les fiches indiquent explicitement « OCR indisponible ».

## 8. Tâches planifiées conseillées

- Purge des publications non signalées expirées : `php bin\purge.php` (Planificateur de tâches, une fois par jour).
  Les publications liées à une alerte ne sont jamais purgées.

## 9. Mise à jour

Arrêter VERIFIA (`stop.bat`), remplacer les fichiers (sans écraser `config\config.php` ni `storage\`),
puis relancer `install.bat` : les tables manquantes sont créées sans toucher aux données existantes
(les éventuelles modifications de colonnes d'une version future seront fournies sous forme de script de migration).
