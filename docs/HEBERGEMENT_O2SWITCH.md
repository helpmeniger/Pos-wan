# Héberger POS Marwa sur o2switch (de A à Z), sans exposer les secrets

Ce guide installe POS Marwa sur un hébergement mutualisé **o2switch** (cPanel, PHP, MariaDB), accessible
en HTTPS depuis Internet. Les secrets restent **hors du dossier public**, **hors de Git** et **hors des
archives** que vous distribuez.

> Les noms des menus cPanel ci-dessous sont ceux affichés habituellement chez o2switch. Ils peuvent
> changer légèrement : en cas de doute, utilisez la barre de recherche de cPanel ou l'assistance o2switch.
> Ce guide n'a pas été déroulé sur un compte o2switch réel ; il s'appuie sur le fonctionnement de
> POS Marwa (vérifié) et sur l'interface cPanel standard.

---

## 0. Ce qui fonctionne en ligne… et ce qui ne fonctionne pas

POS Marwa a été conçu d'abord pour le **réseau local** de la boutique. Sur un serveur distant,
certaines fonctions ne peuvent pas marcher : le serveur ne « voit » pas le réseau de la boutique.

| Fonction | En ligne (o2switch) |
|---|---|
| Caisse, stocks, achats, clients, rapports, modules (Clinique, Hôtel, Établissement, Pressing, Comptabilité…) | ✅ |
| Accès depuis plusieurs boutiques, téléphone, tablette (HTTPS) | ✅ |
| Scanner code-barres par la caméra du téléphone / PC (dans le navigateur) | ✅ (HTTPS obligatoire, fourni) |
| Impression des tickets et factures **par le navigateur** (bouton Imprimer, ticket 58/80 mm, A4) | ✅ |
| Impression **directe ESC/POS** vers une imprimante réseau ou USB partagée (port 9100) | ❌ le serveur ne peut pas joindre l'imprimante de la boutique |
| Caméras IP de la boutique (module vision, surveillance) | ❌ ne jamais exposer une caméra sur Internet pour cela |
| Réception DICOM des appareils d'imagerie (port réseau) | ❌ mutualisé : pas de port personnalisé |
| Serveur d'assistance RustDesk (MARWA REMOTE ASSIST, ports 21115-21117) | ❌ à garder sur un VPS ou un PC dédié |
| Voix Piper côté serveur | ⚠️ à tester (dépend de l'autorisation d'exécuter des programmes) ; la voix du navigateur reste disponible |
| **Caisse** pendant une coupure Internet (v2.11) | ✅ après une première ouverture en ligne : ventes gardées sur le poste, envoyées au retour de la connexion (voir § 15) |
| Autres pages (stocks, achats, rapports, clients, modules) sans Internet | ❌ elles lisent et écrivent directement sur le serveur |

**Conseil** : la caisse hors connexion couvre les coupures de quelques minutes à quelques heures.
Si Internet manque souvent pendant des journées entières, gardez l'installation locale
(WampServer, voir `INSTALLATION_WAMPSERVER.md`).

---

## 1. Les secrets : ce qu'il faut protéger et où ils vivent

| Secret | Rôle | Où il doit être | Où il ne doit **jamais** être |
|---|---|---|---|
| `DB_PASSWORD` | Mot de passe de la base MariaDB | `.env` à la racine du projet, **au-dessus** de `public/` | Dans le code, dans Git, dans un ZIP distribué, dans `public/` |
| `APP_KEY` | Clé secrète de l'application (64 caractères hexadécimaux), générée à l'installation | `.env` | Idem ; ne la réutilisez jamais d'une installation à l'autre |
| Mots de passe des comptes (administrateur, caissiers…) | Connexion | Base de données (hachés) | Dans un e-mail, un message WhatsApp, un fichier texte |
| **Clé privée de licence** (Ed25519) de l'auteur | Signer les clés d'activation | **Uniquement** sur le PC de l'auteur, hors ligne, sauvegardée chiffrée | Sur le serveur o2switch, dans Git, dans `outils-auteur/` |
| Sauvegardes (`storage/backups/*.sql.gz`) | Contiennent **toutes** les données (clients, ventes, patients, élèves) | `storage/` (bloqué par `.htaccess`), puis copie chiffrée chez vous | Dans `public/`, sur une clé USB non chiffrée, sur un Drive partagé |
| Accès cPanel / SSH / FTP o2switch | Administration du serveur | Gestionnaire de mots de passe + double authentification | Partagés avec les employés de la boutique |

Les variables `ASSIST_HOST` et `ASSIST_KEY` (assistance à distance) contiennent une adresse et une clé
**publique** RustDesk : elles ne sont pas secrètes, mais se règlent aussi dans `.env`.

> POS Marwa n'utilise **aucune clé d'API externe** (ni paiement en ligne, ni SMS, ni IA dans le
> nuage). Si vous en ajoutez un jour (opérateur mobile money, passerelle SMS…), la règle est la même :
> une variable dans `.env`, lue par `Env::get()`, jamais écrite dans le code.

---

## 2. Préparer l'archive à envoyer (sur votre PC)

1. **Utilisez l'archive d'hébergement** (`pos-marwa-2.11.1-o2switch.zip`), produite avec `git archive`.
   - Elle **exclut** `outils-auteur/` : le générateur de licences ne part jamais chez un client ni sur un
     serveur.
   - Elle ne contient **aucun** fichier `.env`, aucune base, aucune sauvegarde, aucun journal, aucune
     clé : vous créez le `.env` sur le serveur (§ 8).
   - Elle exclut aussi ce qui ne sert pas sur un hébergement Linux : la suite de tests (`tests/`) et
     les scripts Windows (`windows/`).
2. **Ne clonez pas** le dépôt Git sur le serveur (outil « Git Version Control » de cPanel). Un clonage
   apporterait `outils-auteur/` et tout l'historique, et vous obligerait à donner au serveur un accès à
   votre dépôt.
3. Vérifiez l'empreinte de l'archive :
   ```
   sha256sum pos-marwa-2.11.1-o2switch.zip      (Linux / macOS)
   certutil -hashfile pos-marwa-2.11.1-o2switch.zip SHA256      (Windows)
   ```
   Le résultat doit être identique à celui du fichier `.sha256`.

---

## 3. Sécuriser le compte o2switch (avant toute chose)

1. Changez le mot de passe du compte o2switch : long, unique, gardé dans un gestionnaire de mots de
   passe.
2. Activez la **double authentification** de l'espace client et de cPanel, si elle est proposée.
3. **N'utilisez pas le FTP en clair.** Envoyez les fichiers par le **Gestionnaire de fichiers** de
   cPanel, ou en SFTP/SSH.
4. Pour SSH, autorisez seulement votre adresse IP : outil « **Autorisation SSH** » (liste blanche
   d'IP) dans cPanel.

---

## 4. Créer le sous-domaine, avec `public/` comme racine web

C'est **l'étape la plus importante pour la sécurité** : seul le dossier `public/` doit être
accessible depuis Internet. `.env`, `app/`, `storage/` (et donc les sauvegardes) restent alors
inaccessibles.

1. Dans cPanel, ouvrez **Domaines** (ou « Sous-domaines ») et créez par exemple `caisse.votredomaine.com`.
2. Pour la **racine du document** (*Document Root*), indiquez :
   ```
   posmarwa/public
   ```
   Le chemin complet sera `/home/VOTRE_COMPTE/posmarwa/public`.
   - Surtout **pas** `public_html/posmarwa`.
3. Le dossier `/home/VOTRE_COMPTE/posmarwa/` (le projet) se trouve ainsi **à côté** de `public_html`,
   **hors** de l'espace web.

Si vous ne pouvez vraiment pas changer la racine, le `.htaccess` à la racine du projet bloque `app/`,
`config/`, `storage/`, `.env`… C'est une protection de secours, pas la configuration recommandée.

---

## 5. Envoyer et décompresser les fichiers

1. Dans **Gestionnaire de fichiers**, allez dans `/home/VOTRE_COMPTE/`.
2. Envoyez `pos-marwa-2.11.1-o2switch.zip`, puis **Extraire**.
3. L'archive contient un dossier `pos-marwa/`. Renommez-le `posmarwa` (ou adaptez l'étape 4).
4. Vérifiez la structure : `/home/VOTRE_COMPTE/posmarwa/public/index.php` doit exister.
5. Supprimez le fichier ZIP du serveur.

**Droits des fichiers** (chez o2switch, PHP s'exécute avec votre propre utilisateur) :

| Élément | Droits |
|---|---|
| Dossiers | 755 |
| Fichiers | 644 |
| `.env` (créé plus tard) | **600** (lecture et écriture par vous seul) |
| `storage/` et `public/uploads/` | Doivent rester inscriptibles par votre utilisateur (755 suffit) |

---

## 6. Choisir PHP et ses extensions

1. Dans cPanel, ouvrez **Sélecteur de version PHP** (ou « MultiPHP »).
2. Choisissez **PHP 8.2 ou 8.3**. POS Marwa est testé en 8.3 ; le minimum est 8.1.
3. Cochez les extensions :
   - `pdo_mysql`, `mbstring`, `fileinfo`, `zlib` (obligatoires) ;
   - `gd`, `zip` (recommandées : photos, sauvegarde des fichiers, mise à jour depuis le navigateur).
4. Options PHP conseillées :

   | Option | Valeur |
   |---|---|
   | `display_errors` | Off |
   | `upload_max_filesize` | 64M (mises à jour, photos, imagerie) |
   | `post_max_size` | 64M |
   | `memory_limit` | 256M |
   | `max_execution_time` | 120 |
   | `date.timezone` | Africa/Niamey |

---

## 7. Créer la base de données (mot de passe fort, droits limités)

1. cPanel → **Bases de données MySQL** (ou « Assistant de base de données MySQL »).
2. Créez la base, par exemple `VOTRECOMPTE_posmarwa`.
3. Créez un utilisateur dédié, par exemple `VOTRECOMPTE_pmuser`.
   - Mot de passe généré par l'outil : au moins 24 caractères.
   - **Évitez les guillemets `"`** : l'installateur ne les accepte pas dans `.env`.
4. Ajoutez l'utilisateur à la base avec **tous les privilèges sur cette base uniquement**. Les
   migrations créent et modifient des tables.
5. Notez le mot de passe **dans votre gestionnaire de mots de passe**. Ne l'envoyez par aucune
   messagerie.

L'hôte de la base est `localhost` chez o2switch.

---

## 8. Installer POS Marwa sans laisser l'installateur ouvert au public

Tant que POS Marwa n'est pas installé, la page `/install` est accessible à **n'importe qui**. Une
personne qui y arriverait avant vous pourrait créer le compte administrateur. Deux méthodes sûres :

### Méthode A — en ligne de commande (SSH, recommandée)

1. Créez le fichier `.env` dans `/home/VOTRE_COMPTE/posmarwa/` :
   ```bash
   cd ~/posmarwa
   cp .env.example .env
   chmod 600 .env
   php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'    # copiez le résultat dans APP_KEY
   nano .env
   ```
2. Renseignez `.env` :
   ```
   APP_ENV=production
   APP_DEBUG=false
   APP_KEY=<les 64 caractères générés ci-dessus>
   APP_BASE_URL=https://caisse.votredomaine.com
   APP_TIMEZONE=Africa/Niamey
   DB_HOST=localhost
   DB_PORT=3306
   DB_DATABASE=VOTRECOMPTE_posmarwa
   DB_USERNAME=VOTRECOMPTE_pmuser
   DB_PASSWORD="le-mot-de-passe-de-l-etape-7"
   SESSION_IDLE_MINUTES=30
   SESSION_MAX_HOURS=12
   SESSION_SECURE=true
   ```
3. Lancez l'installation. Le mot de passe administrateur vous est demandé et ne s'affiche pas dans
   l'historique :
   ```bash
   php -v                       # doit afficher la version choisie à l'étape 6
   php bin/install.php --company="Ma Boutique" --type=boutique --user=admin --name="Prénom Nom"
   ```
   Types possibles : `alimentation`, `boutique`, `pharmacie`, `quincaillerie`… (voir l'assistant
   web).
4. Après l'installation, `/install` répond **404**.

### Méthode B — assistant web, protégé pendant l'installation

1. Dans cPanel, ouvrez **Confidentialité des répertoires** (*Directory Privacy*) et protégez
   `posmarwa/public` par un identifiant et un mot de passe **temporaires**.
2. Ouvrez `https://caisse.votredomaine.com/install` et remplissez le formulaire : hôte `localhost`,
   base, utilisateur et mot de passe de l'étape 7. L'assistant crée `.env` avec une `APP_KEY`
   aléatoire, en droits 0640.
3. Retirez la protection de répertoire une fois l'installation terminée.
4. Ouvrez `.env` et passez `SESSION_SECURE=true` (le site est en HTTPS). Ramenez ensuite les droits du
   fichier à **600**.

Dans les deux cas, **ne cochez pas les données de démonstration** pour une boutique réelle.

### Si l'assistant n'arrive pas à créer les tables

L'assistant vérifie la base **avant** d'écrire quoi que ce soit et affiche la cause exacte. Corrigez,
puis cliquez de nouveau sur « Installer » dans la même fenêtre (les champs de la base restent
modifiables, même si un `.env` existe déjà) :

| Message affiché | Cause | Correction |
|---|---|---|
| « La base … n'existe pas » | Nom saisi sans le préfixe o2switch | Saisir le nom complet : `moncompte_posmarwa` |
| « Utilisateur ou mot de passe de la base refusé » | Utilisateur sans préfixe, ou mot de passe différent | Nom complet de l'utilisateur ; au besoin, changer son mot de passe dans cPanel et le ressaisir |
| « L'utilisateur n'a pas les droits nécessaires » | Utilisateur non relié à la base | cPanel → Bases de données MySQL → « Ajouter un utilisateur à une base de données » → **TOUS LES PRIVILÈGES** |
| « Serveur MySQL injoignable » | Mauvais hôte | Hôte `localhost` |
| « contient déjà … table(s) d'une autre application » | Base déjà utilisée (WordPress…) | Créer une nouvelle base vide (rien n'est modifié dans l'autre) |
| « Mot de passe … guillemet » | `"` dans le mot de passe MySQL | Choisir un autre mot de passe dans cPanel |

Une installation **interrompue** (fenêtre fermée, coupure) reprend où elle s'est arrêtée : les tables
déjà créées sont conservées. Si la base contient déjà une installation complète de POS Marwa (fichiers
réinstallés), ses données sont gardées, une sauvegarde est faite, et l'assistant se ferme.

**Version 2.11.0 seulement** : après une erreur de connexion, l'assistant gardait un `.env` erroné et
masquait les champs de la base. Remplacez les fichiers par l'archive 2.11.1, ou supprimez le fichier
`posmarwa/.env` dans le Gestionnaire de fichiers, puis recommencez.

---

## 9. Activer HTTPS

1. cPanel → **SSL/TLS Status** (AutoSSL, Let's Encrypt) : vérifiez que `caisse.votredomaine.com` a un
   certificat valide. Lancez « Run AutoSSL » si besoin.
2. Forcez HTTPS.
   - **De préférence** : cPanel → **Domaines** → activez « Forcer la redirection HTTPS » pour
     `caisse.votredomaine.com`.
   - À défaut, ajoutez en tête de `posmarwa/public/.htaccess` :
     ```apache
     RewriteEngine On
     RewriteCond %{HTTPS} !=on
     RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L]
     ```
     Attention : une mise à jour du logiciel peut remplacer ce fichier. Vérifiez la redirection
     après chaque mise à jour.
3. `SESSION_SECURE=true` dans `.env` : le cookie de session n'est jamais envoyé en clair.

Le HTTPS est aussi **obligatoire** pour le scanner par caméra et pour l'installation de l'application
sur téléphone (PWA).

---

## 10. Premiers réglages de sécurité dans POS Marwa

1. Connectez-vous en administrateur. Vérifiez **Configuration → Paramètres** : nom, devise, fuseau
   horaire.
2. Créez **un compte par personne**, avec le rôle le plus limité possible (caissier, gérant…). Ne
   partagez jamais le compte administrateur.
3. Désactivez la connexion par **PIN** si l'écran de connexion est visible du public : sur Internet,
   préférez les mots de passe.
4. Activez la licence : envoyez à l'auteur **l'identifiant d'installation** (affiché dans « À propos »).
   L'auteur renvoie la clé signée. La clé privée ne quitte jamais son PC.
5. Vérifiez qu'aucun fichier sensible n'est accessible. Chacune de ces adresses doit répondre **403**
   ou **404** :
   ```
   https://caisse.votredomaine.com/.env
   https://caisse.votredomaine.com/storage/backups/
   https://caisse.votredomaine.com/install
   ```

---

## 11. Sauvegardes automatiques (tâche Cron) et copie hors du serveur

1. cPanel → **Tâches Cron** → ajoutez une sauvegarde tous les jours à 2 h :
   ```
   0 2 * * *  cd /home/VOTRE_COMPTE/posmarwa && php bin/backup.php > /dev/null 2>&1
   ```
   La sauvegarde est faite en PHP (sans `mysqldump`), dans `storage/backups/`, avec rotation et
   empreinte SHA-256.
2. **Copie hors du serveur.** Une fois par semaine, téléchargez la dernière sauvegarde :
   - soit depuis **Système & maintenance → Sauvegardes** (compte administrateur) ;
   - soit en SFTP.

   Gardez-la **chiffrée** (archive 7-Zip AES-256 avec un mot de passe fort, ou disque chiffré).
3. o2switch fait aussi ses propres sauvegardes du compte (outil de sauvegarde de cPanel). Elles ne
   remplacent pas votre copie personnelle.
4. **Testez une restauration chaque trimestre** sur une installation de test, jamais sur la base réelle.

---

## 12. Mettre à jour POS Marwa en ligne

1. Faites une sauvegarde (Système & maintenance → Sauvegardes) et téléchargez-la.
2. **Système & maintenance → Mise à jour du logiciel** → envoyez le nouveau ZIP de version.
   - Les empreintes `release.json` sont vérifiées.
   - `.env`, `storage/` et vos données sont conservés.
   - Les migrations de la base s'appliquent toutes seules.
3. Autre méthode, par SSH : décompressez la nouvelle version dans un dossier temporaire, recopiez-la
   par-dessus **sans** `.env` ni `storage/`, puis lancez :
   ```bash
   php bin/migrate.php
   ```

---

## 13. Ce qu'il ne faut jamais faire

- Mettre `.env`, une sauvegarde `.sql.gz` ou un ZIP de données dans `public/` ou `public_html/`.
- Passer `APP_DEBUG=true` sur le serveur en ligne (affichage de détails techniques aux visiteurs).
- Envoyer `.env`, un mot de passe ou une sauvegarde par WhatsApp, e-mail ou Drive partagé.
- Pousser `.env` dans Git (il est exclu par `.gitignore` : ne forcez jamais son ajout).
- Rendre public le dépôt GitHub s'il contient `outils-auteur/` ou une configuration de production.
- Copier la clé privée de licence sur le serveur, ou dans le même dossier que le logiciel.
- Réutiliser la même `APP_KEY` ou le même mot de passe de base pour deux boutiques.
- Exposer phpMyAdmin, les imprimantes ou les caméras de la boutique sur Internet.

---

## 14. Données personnelles (loi n° 2017-28 du Niger)

Héberger chez o2switch place les données **en France**. Cela comprend :

- les clients et les ventes ;
- les patients, avec la clinique ;
- les élèves, avec l'établissement ;
- les voyageurs, avec l'hôtel.

La loi nigérienne n° 2017-28 sur la protection des données personnelles encadre les transferts de
données hors du Niger, et plus encore les données de santé.

**Renseignez-vous auprès de la HAPDP** (Haute Autorité de Protection des Données à caractère
Personnel) **avant** d'y mettre des données de patients ou d'élèves. Prévoyez aussi une information
écrite des personnes concernées.

Pour ces activités, une installation **locale** (serveur dans l'établissement) reste souvent la
solution la plus simple sur le plan réglementaire.

---

## 15. Caisse hors connexion (coupure Internet)

**Ce qui se passe** : à l'ouverture de la caisse (en HTTPS), le navigateur garde la page de caisse,
ses fichiers et le catalogue des articles vendables. Si Internet tombe, la caisse continue :
chaque vente validée est gardée **dans le navigateur du poste** avec son identifiant unique, puis
envoyée au serveur dès le retour de la connexion. Le serveur recalcule tout (prix, remises, stock,
droits) : il peut refuser une vente, qui est alors présentée au caissier (jamais effacée en silence).

**Conditions** :
- **HTTPS obligatoire** (fourni par o2switch, § 9) : sans HTTPS, le navigateur refuse de garder la
  page ; une caisse déjà ouverte continue, mais ne peut pas être rouverte sans connexion.
- **Ouvrir la caisse au moins une fois avec la connexion** sur chaque poste, et après chaque mise à jour.
- **Ne pas effacer les données de navigation** du poste et ne pas utiliser de navigation privée :
  c'est là que sont gardées les ventes non envoyées.
- **Rétablir la connexion avant de clôturer la caisse** : une vente arrivée après la clôture est
  refusée (« caisse fermée ») et reste à traiter sur le poste.
- La session de travail expire après `SESSION_IDLE_MINUTES` (30 min par défaut) sans échange avec le
  serveur : après une longue coupure, le bandeau demande de **se reconnecter** ; les ventes partent
  ensuite automatiquement.

**Limites (à connaître)** :
- Le stock affiché hors connexion est celui du dernier chargement, diminué des ventes du poste ; il
  ignore les ventes des autres caisses. Un article épuisé entre-temps est refusé à l'envoi.
- Pas d'autorisation de responsable hors connexion (mot de passe non vérifiable) ; le plafond de
  crédit d'un client n'est vérifié qu'à l'envoi.
- La date de la vente dans POS Marwa est celle de l'envoi ; l'heure réelle et le numéro provisoire
  sont recopiés dans la note de la vente.
- Les ventes gardées sur un poste (articles, client choisi, ordonnance : nom du patient) restent dans
  le navigateur jusqu'à leur envoi : verrouillez la session Windows du poste (loi n° 2017-28).
- Le ticket provisoire n'est pas le ticket définitif ; réimprimez le ticket définitif depuis
  *Tickets* si le client le demande.

## 16. Liste de contrôle finale

- [ ] Racine web = `posmarwa/public`.
- [ ] `.env` hors de `public/`, en droits 600.
- [ ] `APP_DEBUG=false`, `APP_KEY` unique, `SESSION_SECURE=true`.
- [ ] Utilisateur de base dédié, droits sur sa seule base, mot de passe fort.
- [ ] HTTPS valide, redirection HTTP → HTTPS.
- [ ] `/install` → 404 ; `/.env` et `storage/` → 403 ou 404.
- [ ] Comptes nominatifs, administrateur réservé au propriétaire.
- [ ] Sauvegarde Cron quotidienne, copie chiffrée hebdomadaire hors serveur, restauration testée.
- [ ] Double authentification o2switch, SSH limité à votre IP, pas de FTP en clair.
- [ ] `outils-auteur/` et la clé privée de licence absents du serveur.
- [ ] Caisse ouverte une fois en HTTPS sur chaque poste ; coupure simulée (Wi-Fi coupé) : vente gardée puis envoyée.
- [ ] Question des données personnelles vérifiée auprès de la HAPDP (clinique, établissement, hôtel).
