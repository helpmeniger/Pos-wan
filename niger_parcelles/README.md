# Niger Parcelles

Application web de **cartographie et de gestion foncière** du Niger, conçue pour fonctionner
localement sous **WampServer** (Apache + PHP 8 + MySQL/MariaDB), sans Docker, sans Node.js
et sans Composer.

> ⚠️ Les fiches produites par l'application sont des **documents de gestion**. Elles ne
> constituent pas, à elles seules, un titre foncier officiel, et l'enregistrement d'un nom ne
> prouve pas la propriété. L'application n'invente aucune donnée cadastrale ni aucune limite
> administrative : celles-ci doivent être importées depuis des sources officielles.

---

## Sommaire

1. [Fonctionnalités](#1-fonctionnalités)
2. [Architecture retenue](#2-architecture-retenue)
3. [Base de données](#3-base-de-données)
4. [Prérequis (extensions PHP et bibliothèques)](#4-prérequis)
5. [Installation pas à pas avec WampServer](#5-installation-pas-à-pas-avec-wampserver)
6. [Utilisation](#6-utilisation)
7. [Rôles et permissions](#7-rôles-et-permissions)
8. [Sécurité](#8-sécurité)
9. [Sauvegarde et restauration](#9-sauvegarde-et-restauration)
10. [Tests](#10-tests)
11. [Limites connues et dépendances externes](#11-limites-connues-et-dépendances-externes)
12. [Liste des fichiers](#12-liste-des-fichiers)

---

## 1. Fonctionnalités

| Domaine | Fonctions |
|---|---|
| **Carte** | Carte Leaflet centrée sur le Niger ; fonds OpenStreetMap, satellite (Esri World Imagery) et hybride ; échelle, légende, coordonnées du curseur ; recherche de parcelles, de zones et de localités OSM (Nominatim) ; saisie de coordonnées GPS « 13.51, 2.11 » ; filtres par statut, catégorie, zone, chevauchement ; mesure de distances et de surfaces ; géolocalisation de l'appareil ; indicateur de disponibilité du fond de carte |
| **Délimitation** | Dessin de polygones (Leaflet-Geoman), fermeture, déplacement / ajout / suppression de sommets (clic droit), annulation pas à pas, accrochage aux parcelles voisines, superficie (m² et ha) et périmètre en direct, vérification serveur, enregistrement en MySQL |
| **Contrôles** | Polygone fermé et non auto-intersecté, coordonnées plausibles (zone du Niger paramétrable), superficie positive et bornée, doublons (géométrie quasi identique, réf. cadastrale), **chevauchements signalés et non rejetés** (copropriété, servitude…) |
| **Référence** | Générée côté serveur : `NIG-NIA-2026-000001` (préfixe = code de la région configuré, sinon `NIG-2026-000001`), compteur atomique, jamais réattribuée, distincte de la référence cadastrale officielle |
| **Fiche parcelle** | Identification, catégorie, informations foncières, propriétaires (selon droits), documents, historique complet (avant/après), versions successives des limites, chevauchements, circuit de vérification |
| **Propriétaires** | Personnes physiques/morales, collectivités, groupements ; liens N-N avec rôle, quote-part, dates de validité et statut (déclaré / documenté / vérifié / contesté) ; mutations (vente, succession…) avec conservation des anciens liens ; détection de doublons ; archivage |
| **Documents** | Plans, actes, titres, PV, photos… stockés hors accès web sous un nom aléatoire, contrôle extension + type MIME + contenu, téléchargement via contrôleur PHP avec vérification des droits et journalisation |
| **Recherche** | Référence, réf. cadastrale, propriétaire (profils autorisés), zone (avec sous-zones), point GPS, catégorie, statut, superficie min/max, dates ; tri, pagination, autocomplétion, affichage sur la carte |
| **Tableau de bord** | Indicateurs et graphiques Chart.js calculés en temps réel ; filtres période / zone ; bandeau d'alerte si des données de démonstration sont présentes |
| **Import** | GeoJSON, KML/KMZ, CSV (WKT ou un sommet par ligne), Shapefile ZIP (lecteur PHP intégré) ; choix/détection du système de coordonnées (WGS84, UTM 31N/32N/33N, Web Mercator) ; aperçu cartographique ; erreurs, doublons et chevauchements ; confirmation ; transaction unique ; rapport |
| **Exports** | CSV (compatible Excel FR), Excel .xlsx, GeoJSON, fiche parcellaire PDF (avec plan, sommets, propriétaires autorisés), fiche propriétaire PDF, carte de situation imprimable, rapports PDF/CSV/Excel |
| **Rapports** | Parcelles par zone, non vérifiées, dossiers incomplets / documents manquants, chevauchements, mutations, statistiques par période et localité, historique des imports |
| **Administration** | Utilisateurs et rôles, paramètres, découpage administratif + import de limites officielles, sauvegarde/restauration, journal d'audit, purge des données de démonstration |

---

## 2. Architecture retenue

```
Navigateur (HTML5, Bootstrap 5, Leaflet, Geoman, Turf, Chart.js — fichiers locaux)
     │  pages PHP (rendu serveur)        │  Fetch API (JSON + jeton CSRF)
     ▼                                   ▼
Apache (WampServer) ──► pages *.php ──► includes/ (auth, permissions, CSRF, fonctions)
                         api/*.php  ──► includes/services/ (logique métier)
                                    ──► includes/lib/ (Geo, Crs, PDF, Excel, Shapefile, SQL)
                                    ──► PDO (requêtes préparées) ──► MySQL / MariaDB (InnoDB, utf8mb4)
                                    ──► storage/ (documents, exports, journaux, sauvegardes — privé)
```

Décisions :

* **PHP sans framework ni Composer** : installation par simple copie dans `www`.
* **Bibliothèques front-end embarquées** dans `assets/vendor/` (versions figées : Leaflet 1.9.4,
  Leaflet-Geoman 2.17.0, Bootstrap 5.3.3, Bootstrap Icons 1.11.3, Chart.js 4.4.4, Turf 7.1.0) et
  le moteur de précision maison `parcel-assist.js` :
  l'interface fonctionne sans Internet ; seuls les fonds de carte et le géocodage OSM en ont besoin.
* **Géométries en GeoJSON** (RFC 7946, WGS84, `[lng, lat]`) validées et stockées en `LONGTEXT`,
  avec **boîtes englobantes indexées** pour les requêtes spatiales (pas de PostGIS requis).
* **Calculs géodésiques sur l'ellipsoïde WGS84** (`includes/lib/Geo.php` côté serveur,
  `assets/js/parcel-assist.js` côté navigateur, mêmes formules) : superficie par intégration
  exacte de l'aire authalique, longueurs par l'arc de méridien exact. Écart inférieur à 1 ppm
  avec l'algorithme de référence de Karney (GeographicLib), soit moins d'un millimètre carré
  sur 400 m² ; coordonnées stockées avec 8 décimales (≈ 1 mm). **Le serveur recalcule toujours**
  les valeurs enregistrées.
* **Chevauchements** : pré-filtre SQL par emprise puis test géométrique exact (croisement de
  limites, sommets/milieux intérieurs, tolérance de contiguïté de 10 cm paramétrable).
* **Performance** : la carte ne charge que les parcelles de l'emprise visible ; en dessous du
  zoom 13 seuls les centroïdes sont envoyés ; simplification Douglas-Peucker aux zooms moyens ;
  plafond paramétrable du nombre d'entités par requête ; pagination côté serveur partout.
* **PDF et Excel sans bibliothèque externe** : générateur PDF minimal (`SimplePdf`) et écriture
  `.xlsx` via `ZipArchive` (repli automatique sur le format XML Excel 2003).
* **CSP stricte** (`script-src 'self'`) : aucun script en ligne, configuration transmise en JSON.

---

## 3. Base de données

Fichier : `database/schema.sql` (InnoDB, `utf8mb4_unicode_ci`, clés étrangères, index,
contraintes d'unicité). Données de référence incluses : 5 rôles, le Niger, ses 8 régions avec
leur code (AGA, DIF, DOS, MAR, NIA, TAH, TIL, ZIN) et les 5 arrondissements communaux de Niamey
(**sans limites géographiques** : à importer).

| Table | Rôle | Points clés |
|---|---|---|
| `roles` | Rôles applicatifs | `code` unique ; permissions dans `includes/permissions.php` |
| `users` | Comptes | `password_hash` (bcrypt), `must_change_password`, `username`/`email` uniques |
| `login_attempts` | Anti force brute | index (utilisateur, date) et (IP, date) |
| `administrative_areas` | Découpage hiérarchique | `parent_id`, `area_type`, `code` (préfixe), `geojson` + emprise, `source` obligatoire à l'import |
| `owners` | Propriétaires / ayants droit | `owner_type`, `info_status`, `is_archived`, `is_demo` |
| `parcels` | Parcelles | `parcel_reference` **unique**, `cadastral_reference`, mesures, centroïde, **emprise indexée**, `survey_level`, `declared_status`, `verification_status`, `has_overlap_warning`, `is_archived`, `is_demo` |
| `parcel_geometries` | **Versions** des limites | (`parcel_id`, `geometry_version`) unique ; source, CRS d'origine, motif |
| `parcel_reference_sequences` | Compteur annuel des références | incrément atomique `LAST_INSERT_ID()` |
| `parcel_owners` | Liens N-N parcelle ↔ propriétaire | rôle, `share_percentage` (0–100), `valid_from`/`valid_to`, statut, mutations entrante/sortante |
| `mutations` | Changements de propriétaire | type, date, acte ; jamais supprimées |
| `documents` | Pièces justificatives | `storage_filename` aléatoire unique, MIME, taille, SHA-256, statut, remplacement |
| `parcel_history` | Historique métier | `previous_data` / `new_data` en JSON (dont anciennes géométries) |
| `parcel_overlaps` | Chevauchements détectés | paire ordonnée unique, statut (à vérifier / légitime / corrigé) |
| `import_batches` | Rapports d'import | compteurs, rapport JSON |
| `audit_logs` | Journal d'audit | connexions, refus d'accès, modifications, exports, téléchargements |
| `settings` | Paramètres | clé unique |

Distinctions métier toujours visibles :

* `survey_level` : dessinée à titre indicatif · relevée sur le terrain · associée à des documents · vérifiée par une autorité habilitée ;
* `parcel_owners.verification_status` : propriétaire déclaré · documenté · vérifié · contesté/incomplet.

---

## 4. Prérequis

* **WampServer 3.x** (Windows) avec **PHP 8.0 à 8.3** et **MySQL 5.7+/8.x ou MariaDB 10.3+**.
* Extensions PHP (menu WampServer › PHP › Extensions PHP) :

| Extension | Usage | Statut |
|---|---|---|
| `pdo_mysql` | Accès à la base | **obligatoire** (actif par défaut) |
| `mbstring` | Textes UTF-8 | **obligatoire** (actif par défaut) |
| `fileinfo` | Contrôle du type des documents | **obligatoire** (à cocher si absent) |
| `json`, `session`, `openssl` | Cœur | **obligatoire** (natif) |
| `zip` | Excel .xlsx, Shapefile, KMZ, sauvegarde des documents | recommandé (actif par défaut) |
| `zlib` | Compression des PDF | recommandé |
| `curl` | Tests HTTP automatisés uniquement | facultatif |

* Module Apache **`mod_rewrite`** (actif par défaut dans WampServer) et `AllowOverride All`.
* Navigateur récent : Chrome, Edge ou Firefox.
* Aucune autre installation : les bibliothèques JavaScript sont fournies dans `assets/vendor/`.

---

## 5. Installation pas à pas avec WampServer

### 5.1 Installer et démarrer WampServer

1. Téléchargez WampServer depuis le site officiel (wampserver.com) et installez-le
   (installez aussi les « Visual C++ Redistributable » demandés).
2. Lancez **Wampserver64**. L'icône « W » apparaît près de l'horloge :
   **verte** = Apache et MySQL/MariaDB fonctionnent ; **orange** = un service est arrêté
   (souvent le port 80 occupé par Skype/IIS) ; **rouge** = aucun service.
3. Ouvrez `http://localhost/` : la page d'accueil de WampServer doit s'afficher.

### 5.2 Copier l'application

Copiez le dossier `niger_parcelles` dans :

```
C:\wamp64\www\niger_parcelles\
```

> Si WampServer est installé ailleurs (ex. `D:\wamp64`), copiez le dossier dans le sous-dossier
> `www` de cette installation (`D:\wamp64\www\niger_parcelles\`). L'adresse reste
> `http://localhost/niger_parcelles/` : l'application détecte son chemin automatiquement.
> Si vous utilisez un hôte virtuel ou un autre chemin web, renseignez `base_url` dans
> `config/app.local.php` (voir 5.5).

### 5.3 Créer la base et son utilisateur MySQL (phpMyAdmin)

Pour une configuration sûre, l'application utilise **sa propre base** et **un utilisateur MySQL
dédié protégé par mot de passe** (pas le compte `root`). L'installateur ne crée ni ne supprime
jamais de base de données : c'est vous qui la créez.

1. Clic gauche sur l'icône WampServer › **phpMyAdmin** (`http://localhost/phpmyadmin/`),
   connectez-vous avec `root` (mot de passe vide par défaut ; choisissez **MySQL** ou **MariaDB**
   selon le serveur que vous utilisez).
2. Onglet **Comptes d'utilisateurs** › **Ajouter un compte d'utilisateur**.
3. Renseignez :
   * **Nom d'utilisateur** : par exemple `niger_app` ;
   * **Nom d'hôte** : `Local` (`localhost`) ;
   * **Mot de passe** : un mot de passe robuste (bouton « Générer » possible) — notez-le.
4. Dans « Base de données pour ce compte d'utilisateur », cochez
   **« Créer une base portant son nom et donner à cet utilisateur tous les privilèges »**.
   La base s'appellera alors `niger_app`.
   *Variante* : créez d'abord la base `niger_parcelles` (onglet Bases de données, interclassement
   `utf8mb4_unicode_ci`), puis donnez à l'utilisateur **tous les privilèges sur cette seule base**
   (Comptes d'utilisateurs › Modifier les privilèges › Base de données).
5. Ne cochez **aucun privilège global**, puis cliquez sur **Exécuter**.
6. Conseil : donnez ensuite un mot de passe au compte `root` (Comptes d'utilisateurs › root ›
   Modifier les privilèges › Changer le mot de passe).

### 5.4 Lancer l'installateur web

1. Ouvrez **`http://localhost/niger_parcelles/`** : vous êtes redirigé vers `install.php`.
2. Vérifiez que tous les prérequis sont « OK ».
3. Saisissez **le nom de la base**, **l'utilisateur MySQL** et **son mot de passe** créés à
   l'étape 5.3 (hôte `localhost`, port `3306` ; MariaDB sous WampServer utilise souvent **`3307`**).
4. Cliquez sur **« Tester la connexion »** : l'installateur indique si la connexion fonctionne et si
   la base est vide. Les messages d'erreur expliquent la cause (base inexistante, droits
   insuffisants, mot de passe incorrect, serveur arrêté, mauvais port).
5. Re-saisissez le mot de passe MySQL, puis renseignez le **compte administrateur** de
   l'application (identifiant, nom, mot de passe d'au moins 10 caractères avec lettres et
   chiffres, différent du mot de passe MySQL). Cochez éventuellement les données de
   démonstration **fictives**.
6. Cliquez sur **« Installer »** et confirmez. L'installateur :
   * **crée automatiquement toutes les tables** si la base est vide (si vous avez déjà importé
     `database/schema.sql` avec phpMyAdmin, il les conserve et crée seulement l'administrateur) ;
   * refuse de toucher une base qui contient déjà des utilisateurs Niger Parcelles ou une
     installation incomplète ;
   * annule tout (suppression des seules tables qu'il vient de créer) en cas d'erreur ;
   * enregistre la connexion dans `config/database.local.php` (fichier privé, exclu de Git,
     inaccessible depuis le navigateur ; le mot de passe n'est jamais affiché) ;
   * crée le compte administrateur (mot de passe haché bcrypt) puis **se verrouille**
     (`storage/installed.lock`) : une seconde exécution est refusée.

Un mot de passe MySQL vide est refusé. L'utilisation du compte `root` est possible mais
signalée par un avertissement.

### 5.5 Configuration (facultatif)

* `config/database.php` : valeurs par défaut (aucun mot de passe réel) ; les vôtres vont dans
  `config/database.local.php`.
* `config/app.php` : fuseau horaire, taille des imports, fonds de carte, durée de session.
  Surcharges locales dans `config/app.local.php`, par exemple :
  ```php
  <?php return ['base_url' => 'http://parcelles.local/', 'session_lifetime' => 3600];
  ```
* **Paramètres › Général** (dans l'application) : nom de l'organisation, centre de la carte,
  zone plausible, superficies min/max, taille des documents, couche satellite.
* Pour des documents volumineux, augmentez `upload_max_filesize` / `post_max_size`
  (menu WampServer › PHP › Paramètres PHP) ; le `.htaccess` fixe 20 Mo / 25 Mo.

### 5.6 Premier démarrage

1. Connectez-vous sur `http://localhost/niger_parcelles/`.
2. **Paramètres › Découpage administratif** : vérifiez les codes des régions, ajoutez vos
   départements, communes, quartiers ; importez leurs limites (GeoJSON officiel) en indiquant la source.
3. **Utilisateurs** : créez les comptes des agents (mot de passe provisoire à changer à la
   première connexion).
4. Si vous avez chargé la démonstration, **supprimez-la** avant l'exploitation réelle
   (Paramètres › Données de démonstration).

---

## 6. Utilisation

**Dessiner une parcelle** : Carte foncière › zoomez (vue satellite conseillée) › « Nouvelle
parcelle » › cliquez chaque sommet › cliquez sur le premier sommet (ou « Fermer le polygone »).
Ajustez les sommets (glisser ; point intermédiaire = ajout ; clic droit = suppression ;
« Annuler » revient en arrière). Renseignez zone, catégorie, nature de la délimitation ›
« Vérifier » (contrôle serveur, chevauchements) › « Enregistrer ». La référence s'affiche.

**Position exacte** : cliquez n'importe où sur la carte : latitude/longitude (7 décimales),
degrés-minutes-secondes, coordonnées UTM et précision du pointage au zoom courant s'affichent
(bouton « Copier »). En cliquant sur une parcelle, le point cliqué est indiqué en tête de sa fiche.
Le bouton <i>position</i> active le suivi GPS continu ; « Précision › Ma position moyennée »
fait la moyenne pondérée des mesures GPS pendant 15 s.

**Zoom très rapproché** : molette ou « Précision › Zoom maximal » jusqu'au niveau 25 (la barre
d'état indique la résolution : 1 px ≈ 4,5 mm et la largeur de vue ≈ 5 m) ; le viseur central
(« Précision › Viseur ») permet de pointer chaque angle exactement, y compris sur tablette.

**Outils de précision pendant la saisie** : longueur de chaque côté affichée sur la carte,
tableau des sommets modifiable (coordonnées au 1/10 000 000 de degré), « Équerrer » (rectangle le
plus proche, angles droits), « Ajuster à 400 m² » (ou toute superficie cible), ajout d'un sommet
au viseur ou à la position GPS moyennée, accrochage aux limites des parcelles voisines.
« Précision › Saisir des coordonnées » trace une parcelle à partir d'une liste de points WGS84
ou UTM 31N/32N/33N (plan de géomètre) ; « Relevé GPS sur le terrain » enregistre les sommets un
par un en marchant autour de la parcelle.

**Assistant d'identification (menu « Assistant »)** :
* *Détecter* : cliquez dans la cour d'une concession sur l'image satellite ; l'assistant repère
  ses murs et propose son contour avec un indice de confiance (format courant : 20 × 20 m = 400 m²).
* *Gabarit* : un clic = une parcelle de 20 × 20 m (ou 15 × 25, 20 × 30, 10 × 40… ou sur mesure),
  orientée comme les parcelles voisines ou le bâti.
* *Lotir* : dessinez un îlot, il est découpé en lots de la taille choisie.
Les propositions s'accumulent : ajustez-les (« Ajuster », rotation de 5°, suppression), puis
« Enregistrer les N parcelles » avec une zone et une catégorie communes. Les chevauchements sont
signalés comme pour une saisie manuelle.

**Noms des propriétaires sur la carte** : bouton « Propriétaires » (profils autorisés aux données
personnelles) ; les noms et quotes-parts s'affichent sur chaque parcelle suffisamment grande à
l'écran, au survol pour les autres. L'affichage est journalisé.

**Fonds de carte** : si OpenStreetMap ne répond pas, l'application bascule automatiquement sur
un serveur de secours (OpenStreetMap France, puis CARTO, puis Esri) ; l'état du fond est affiché
en bas à gauche.

**Modifier des limites** : fiche ou panneau de la carte › « Modifier les limites » ; un motif
est demandé ; une nouvelle version est créée, l'ancienne reste consultable.

**Propriétaires** : fiche parcelle › onglet Propriétaires › associer un propriétaire existant
(autocomplétion) ou en créer un ; quotes-parts contrôlées (≤ 100 %) ; « Enregistrer une mutation »
clôt les liens choisis et crée les nouveaux, le tout tracé.

**Circuit de vérification** : Brouillon → Soumise (agent) → En vérification / Anomalie
(vérificateur) → Vérifiée / Rejetée (profil habilité). Chaque transition est contrôlée côté
serveur et historisée.

**Import** : Importation › fichier + système de coordonnées › Analyser › contrôlez l'aperçu, les
erreurs et avertissements › options › confirmez › rapport.

---

## 7. Rôles et permissions

Contrôlés **côté serveur** à chaque requête (`can()`, `require_permission()`), y compris dans
les API et les exports.

| Permission | Admin général | Admin foncier | Agent de terrain | Vérificateur | Consultation |
|---|:-:|:-:|:-:|:-:|:-:|
| Consulter parcelles / carte | ✔ | ✔ | ✔ | ✔ | ✔ |
| Créer / dessiner des parcelles | ✔ | ✔ | ✔ | | |
| Modifier toute parcelle | ✔ | ✔ | ses brouillons | | |
| Soumettre à vérification | ✔ | ✔ | ✔ | | |
| Contrôler, signaler une anomalie | ✔ | ✔ | | ✔ | |
| Valider / rejeter | ✔ | ✔ | | ✔ | |
| Données personnelles des propriétaires | ✔ | ✔ | ✔ | ✔ | |
| Gérer propriétaires / mutations | ✔ | ✔ | propriétaires | | |
| Documents : ajouter / vérifier | ✔ | ✔ / ✔ | ✔ / | / ✔ | |
| Import / exports / rapports | ✔ | ✔ | | export, rapports | rapports |
| Utilisateurs, paramètres, journal, sauvegardes | ✔ | | | | |

---

## 8. Sécurité

Mis en œuvre : PDO et requêtes préparées partout (aucune concaténation de valeurs saisies) ;
`password_hash()` / `password_verify()` (bcrypt, re-hachage automatique) ; régénération de
l'identifiant de session à la connexion ; cookies `HttpOnly`, `SameSite=Lax`, `Secure` en HTTPS ;
expiration après inactivité ; jetons **CSRF** sur tous les formulaires et appels d'API en écriture ;
échappement systématique (`e()` côté PHP, `NP.esc()` côté JS) ; **CSP** stricte, `X-Frame-Options`,
`nosniff` ; limitation des tentatives de connexion (5 par compte / 50 par IP en 15 min) ; messages
d'erreur génériques (détails techniques uniquement dans `storage/logs`) ; validation des
coordonnées, énumérations, dates ; uploads contrôlés (extension, type MIME réel, signature,
recherche de code PHP/script, double extension, taille), stockés sous nom aléatoire `.bin` dans
un dossier où PHP est désactivé ; téléchargement via contrôleur avec vérification des droits ;
refus des entités XML externes (XXE) ; neutralisation des formules dans les CSV ; journal
d'audit ; aucun secret dans le dépôt (`*.local.php` exclus) ; dossiers `config`, `includes`,
`database`, `storage`, `tests`, `tools` interdits par `.htaccess`.

**Avant toute exposition sur un réseau ou Internet** : activer **HTTPS** (certificat), changer
le mot de passe `root` MySQL et créer un utilisateur MySQL dédié aux droits limités à la base,
passer `environment` à `production`, déplacer `storage/` hors de `www` (paramètre
`storage_path`), désactiver phpMyAdmin ou le restreindre, protéger et chiffrer les sauvegardes,
restreindre l'accès Apache (pare-feu, `Require ip`), tenir PHP/Apache/MySQL à jour.

---

## 9. Sauvegarde et restauration

* **Dans l'application** (Paramètres › Sauvegardes) : sauvegarde SQL complète générée en PHP
  (sans mysqldump) et archive ZIP des documents, téléchargeables ; restauration d'un fichier
  `.sql` Niger Parcelles (confirmation « RESTAURER », sauvegarde de sécurité automatique avant).
* **Manuellement** : phpMyAdmin › Exporter (SQL) + copie du dossier `storage/documents/`.
* Conseil : sauvegarde quotidienne copiée sur un support externe chiffré, test de restauration
  mensuel sur une base distincte.

---

## 10. Tests

Les tests utilisent une **base dédiée** `niger_parcelles_test` (recréée à chaque exécution ;
le nom doit finir par `_test`) — la base de production n'est jamais touchée.

Depuis une invite de commandes Windows (`cmd`) :

```bat
cd C:\wamp64\www\niger_parcelles
C:\wamp64\bin\php\php8.3.x\php.exe tests\run_tests.php
```

(adaptez `php8.3.x` à votre version ; si MySQL a un mot de passe :
`set NP_DB_USER=root` et `set NP_DB_PASS=...` avant la commande).

| Fichier | Contenu | Exécution |
|---|---|---|
| `tests/run_tests.php` | 21 tests unitaires + 27 tests d'intégration MySQL | `php tests\run_tests.php` |
| `tests/http_test.php` | 19 tests HTTP « boîte noire » (auth, CSRF, rôles, XSS, injection SQL, documents privés, accès direct, blocage des connexions) | `set NP_TEST_ADMIN_PASS=...` puis `php tests\http_test.php` (extension curl) |
| `tests/e2e/map_e2e.js` | 11 tests navigateur (carte, dessin, superficie, enregistrement, rechargement, modification, mesure, recherche, mobile) | facultatif, nécessite Node.js + Playwright (outil de développement uniquement) |
| `tests/e2e/assist_e2e.js` | 13 tests navigateur : Referer OSM, fond de secours, zoom 25, position exacte au clic, GPS moyenné, détection, gabarit, enregistrement groupé, saisie UTM, longueurs des côtés, noms des propriétaires | facultatif (Node.js + Playwright) ; tuiles simulées, fonctionne hors ligne |
| `tests/js/assist_test.js` | 11 tests du moteur de précision (Mercator, UTM, rectangles, gabarits, lots, détection sur image synthétique, référence Karney) | `node tests/js/assist_test.js` (facultatif) |

> Les tests HTTP vérifient le blocage anti force brute : chaque exécution enregistre environ
> 8 échecs de connexion pour votre adresse IP. Au-delà de 6 exécutions en 15 minutes, l'IP est
> bloquée (comportement voulu) : patientez 15 minutes ou videz la table `login_attempts`
> dans phpMyAdmin.

Résultats obtenus lors du développement (Linux, Apache 2.4 + PHP 8.3), sur **MySQL 8.0**
(mode strict, `ONLY_FULL_GROUP_BY`, configuration par défaut de WampServer) et sur
**MariaDB 10.11** : 50/50 (unitaires + intégration), 19/19 (HTTP), 11/11 + 13/13 (navigateur),
11/11 (moteur JavaScript), ainsi que
l'installateur web sur une base et un utilisateur créés manuellement. L'exécution sur votre
installation WampServer reste à faire : lancez les commandes ci-dessus après installation.

Couverture : connexion MySQL, création des tables, relations propriétaires/parcelles,
unicité et non-réattribution des références, historique, création/rechargement/modification de
polygones, calcul de superficie (comparé à Turf.js), chevauchements et contiguïté, import/export
GeoJSON, CSV UTM, KML, Shapefile, mutations, documents, connexion/déconnexion, pages protégées,
rôles, CSRF, validation des fichiers, injections SQL, accès direct aux documents, affichage mobile.

---

## 11. Limites connues et dépendances externes

* **Fonds de carte et imagerie** : OpenStreetMap et Esri World Imagery nécessitent Internet et
  sont soumis à leurs conditions d'utilisation (pas d'usage intensif ni de téléchargement massif
  de tuiles). L'imagerie satellite ne prouve ni une délimitation juridique ni l'identité d'un
  propriétaire. Aucun fond n'est disponible hors connexion tant que des tuiles n'ont pas été
  légalement préparées (un serveur de tuiles local peut être ajouté dans `config/app.php`).
* **Recherche de localités** : service Nominatim d'OpenStreetMap (Internet, 1 requête/s max).
* **Si la carte reste grise** : vérifiez la connexion Internet du poste ; un pare-feu ou un proxy
  d'entreprise doit autoriser `tile.openstreetmap.org`, `*.tile.openstreetmap.fr`,
  `*.basemaps.cartocdn.com` et `server.arcgisonline.com`. L'application transmet l'en-tête
  « Referer » exigé par OpenStreetMap (les versions antérieures au 16/10/2026 le supprimaient,
  ce qui faisait refuser les tuiles).
* **Limites administratives** : non fournies ; à importer depuis une source fiable.
* **Systèmes de coordonnées** : WGS84, UTM 31N/32N/33N (WGS84) et Web Mercator. Les données sur
  un autre datum (ex. Clarke 1880) doivent être converties au préalable (QGIS).
* **Précision** : les calculs (superficie, longueurs) sont exacts au millimètre près ; la
  précision réelle d'une parcelle dépend de la façon dont ses sommets ont été obtenus : pointage
  sur l'imagerie (résolution de l'ordre de 30 à 50 cm par pixel dans les villes, moins ailleurs,
  avec un décalage possible de l'image de 1 à 3 m), GPS de smartphone (± 3 à 5 m, amélioré par la
  moyenne), récepteur GNSS différentiel (centimétrique). Les chevauchements sont testés sur les
  contours extérieurs.
* **Zoom au-delà de 19** : jusqu'au niveau 25 (vue de 5 à 10 m de large, soit « quelques
  mètres du sol »), l'image n'est plus détaillée mais agrandie ; ce zoom sert à pointer les
  sommets avec précision, pas à voir plus de détails que la photo n'en contient.
* **Assistant de détection** : il analyse l'image satellite autour du clic (couleurs,
  contours des murs) ; il fonctionne bien sur les concessions murées bien visibles et propose un
  gabarit orienté quand les limites sont peu nettes. Ses propositions sont **indicatives** et
  doivent être vérifiées ; elles sont enregistrées avec la nature « dessinée à titre indicatif ».
* **Parcelles multi-parties** : une parcelle = un polygone (les MultiPolygon à plusieurs parties
  doivent être scindés).
* **PDF** : polices standard (Helvetica, jeu WinAnsi) ; la fiche contient un plan vectoriel de la
  parcelle mais pas l'image satellite (utilisez la « carte de situation » imprimable pour cela).
* **Mode hors connexion** : la gestion fonctionne sans Internet sur le poste WampServer ; un mode
  terrain déconnecté (synchronisation) n'est pas inclus mais l'architecture API JSON le permet.
* **Échelle** : conçu pour des dizaines de milliers de parcelles (index d'emprise, chargement
  par emprise, plafonds). Au-delà de plusieurs centaines de milliers, envisager une base
  spatiale (MySQL 8 SPATIAL ou PostGIS).

---

## 12. Liste des fichiers

```
niger_parcelles/
├── index.php, login.php, logout.php, profil.php, install.php
├── dashboard.php                 tableau de bord
├── carte.php                     carte foncière (dessin, recherche, mesures)
├── parcelles.php                 liste + recherche avancée + exports
├── parcelle_details.php          fiche complète (propriétaires, documents, historique…)
├── parcelle_edit.php             informations descriptives et foncières
├── parcelle_print.php            carte de situation imprimable
├── proprietaires.php, proprietaire_details.php
├── documents.php, importation.php, rapports.php, utilisateurs.php, parametres.php
├── api/        parcelles, geometries, proprietaires, recherche, statistiques,
│               import, export, documents, admin_areas (JSON)
├── config/     app.php, database.php, database.local.example.php
├── includes/   bootstrap, functions, auth, permissions, csrf, header, sidebar, footer
│   ├── lib/        Geo, Crs, SimplePdf, XlsxWriter, ShapefileReader, SqlRunner
│   └── services/   ParcelService, ParcelQuery, OwnerService, DocumentService,
│                   ImportService, ReportService, PdfService, StatsService, BackupService
├── assets/     css/app.css, js/(app, map, parcel-assist, dashboard, parcel-details, import).js,
│               img/logo.svg (drapeau du Niger), vendor/ (Leaflet, Geoman, Bootstrap, Icons, Chart.js, Turf)
├── database/   schema.sql, demo_data.sql (fictif)
├── storage/    documents/, exports/, imports/, logs/, backups/ (privés)
├── tests/      run_tests.php, unit_test.php, integration_test.php, http_test.php, e2e/
├── tools/      generate_demo_data.php
├── .htaccess, .gitignore, README.md
```

Licences des bibliothèques embarquées : fichiers `LICENSE` dans chaque dossier de
`assets/vendor/` (Leaflet BSD-2, Leaflet-Geoman MIT, Bootstrap MIT, Bootstrap Icons MIT,
Chart.js MIT, Turf MIT).
