# Installation de POS Wan sur WampServer (Windows)

Ce guide installe POS Wan sur un poste Windows équipé de WampServer, pour une utilisation
sur le poste lui-même et, si besoin, depuis d'autres appareils du réseau local de la boutique
(tablette, deuxième caisse).

> Temps estimé : 20 à 30 minutes.

---

## 1. Prérequis

| Élément | Version minimale | Recommandée |
|---|---|---|
| WampServer (64 bits) | 3.3 | dernière version |
| PHP | 8.1 | **8.2 ou 8.3** |
| MySQL / MariaDB | MySQL 8.0 / MariaDB 10.4 | MariaDB 10.11 ou MySQL 8.0 |
| Navigateur | Chrome / Edge récents | Chrome |

Extensions PHP nécessaires (clic gauche sur l'icône Wamp → PHP → Extensions PHP) :

- `pdo_mysql` (obligatoire)
- `mbstring` (obligatoire)
- `fileinfo` (obligatoire : contrôle du type réel des fichiers envoyés)
- `gd` (recommandée : redimensionnement et nettoyage des photos)
- `zip` (recommandée : sauvegarde des photos)
- `zlib` (incluse : compression des sauvegardes)

Modules Apache (clic gauche → Apache → Modules Apache) : **`rewrite_module`** et **`headers_module`** cochés.

POS Wan n'utilise ni Composer ni Node.js : toutes les bibliothèques (Bootstrap 5.3.3,
Bootstrap Icons 1.11.3, Chart.js 4.4.4) sont fournies dans `public/assets/vendor`.
L'application fonctionne donc **sans connexion Internet**.

---

## 2. Copier les fichiers

1. Décompressez l'archive dans `C:\wamp64\www\pos-wan`
   (vous devez obtenir `C:\wamp64\www\pos-wan\public\index.php`).
2. Vérifiez que les dossiers suivants sont accessibles en écriture (c'est le cas par défaut sous Windows) :
   `storage\` (et ses sous-dossiers) et `public\uploads\`.

---

## 3. Créer la base de données

Ouvrez phpMyAdmin (`http://localhost/phpmyadmin`), onglet **SQL**, et exécutez
(remplacez le mot de passe par un mot de passe robuste, différent de celui de l'application) :

```sql
CREATE DATABASE poswan CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'poswan'@'localhost' IDENTIFIED BY 'Remplacez-Ce-Mot-De-Passe-2026!';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES, LOCK TABLES
  ON poswan.* TO 'poswan'@'localhost';
FLUSH PRIVILEGES;
```

> N'utilisez pas le compte `root` (sans mot de passe par défaut sous Wamp) pour l'application.
> Pensez aussi à définir un mot de passe pour `root` (voir § 8).

---

## 4. Configurer Apache (hôte virtuel recommandé)

La configuration la plus sûre fait pointer Apache **uniquement** sur le dossier `public/`
(le code, la configuration, les journaux et les sauvegardes ne sont alors jamais exposés).

1. Clic gauche sur Wamp → Apache → `httpd-vhosts.conf`, ajoutez :

```apache
<VirtualHost *:80>
    ServerName pos-wan.local
    DocumentRoot "C:/wamp64/www/pos-wan/public"
    <Directory "C:/wamp64/www/pos-wan/public">
        Options -Indexes +FollowSymLinks
        AllowOverride All
        # Poste local uniquement :
        Require local
        # Pour autoriser les autres appareils de la boutique, remplacez la ligne
        # ci-dessus par le réseau local, par exemple :
        # Require ip 192.168.1
    </Directory>
    ErrorLog "${INSTALL_DIR}/logs/pos-wan-error.log"
</VirtualHost>
```

2. Ajoutez dans `C:\Windows\System32\drivers\etc\hosts` (ouvrir le Bloc-notes **en administrateur**) :

```
127.0.0.1   pos-wan.local
```

3. Redémarrez les services Wamp (clic gauche → Redémarrer les services).
4. Ouvrez `http://pos-wan.local`.

Un modèle complet est fourni dans `docs/apache-vhost-pos-wan.conf`.

**Sans hôte virtuel** : `http://localhost/pos-wan/` fonctionne aussi. Le fichier `.htaccess`
de la racine redirige vers `public/` et **interdit** l'accès à `app/`, `config/`, `storage/`,
`database/`, `.env`, etc. (vérifié : réponse 403). L'hôte virtuel reste préférable.

---

## 5. Installer POS Wan

### Option A — assistant web (recommandé)

1. Ouvrez `http://pos-wan.local` : vous êtes redirigé vers `/install`.
2. Vérifiez que tous les contrôles d'environnement sont verts.
3. Saisissez les paramètres de la base (étape 3), le nom du commerce, le **type d'activité**
   (alimentation, boutique, pharmacie, quincaillerie…), puis l'identifiant et le mot de passe
   administrateur (10 caractères minimum, lettres et chiffres, sans l'identifiant).
4. Cochez « données de démonstration » uniquement pour une formation ou un essai.
5. Validez. L'assistant :
   - crée le fichier `.env` (hors du dossier public) avec une clé secrète aléatoire ;
   - crée les tables, les rôles, les permissions et les paramètres ;
   - crée le compte administrateur ;
   - **se désactive définitivement** (fichier `storage/installed.lock`, toute visite de `/install` renvoie 404).

### Option B — ligne de commande

```bat
cd C:\wamp64\www\pos-wan
copy .env.example .env
notepad .env          (renseigner DB_USERNAME, DB_PASSWORD et APP_KEY)
C:\wamp64\bin\php\php8.2.x\php.exe bin\install.php --company="Mon commerce" --type=alimentation --user=gerant --name="Nom Prénom"
```

Le mot de passe administrateur est demandé au clavier. Pour générer `APP_KEY` :
`php -r "echo bin2hex(random_bytes(32));"`

Types disponibles : `alimentation`, `boutique`, `pharmacie`, `quincaillerie`, `boissons`,
`cosmetique`, `informatique`, `papeterie`, `pieces`, `restaurant`.

---

## 6. Premiers réglages (après connexion)

1. **Paramètres** : nom, adresse, téléphone, NIF, RCCM, logo, pied de ticket, largeur du ticket
   (58 ou 80 mm), modes de paiement acceptés, seuil de remise sans autorisation.
2. **Boutiques, entrepôts, caisses** : une boutique, un entrepôt et une caisse sont créés
   à l'installation. Ajoutez-en si nécessaire (multi-boutiques / dépôt central).
3. **Utilisateurs** : créez un compte par personne, choisissez le **rôle** et la **caisse affectée**.
   Chaque utilisateur change son mot de passe à la première connexion et peut définir un
   **code PIN** (connexion rapide à la caisse via le pavé numérique).
4. **Catégories** puis **Articles** (saisie, ou import CSV depuis Excel : Articles → Importer).
5. **Ma caisse** → ouvrir la caisse avec le fond de caisse compté, puis vendre.
6. Si des données de démonstration ont été chargées : Paramètres → « Archiver les données de démonstration ».

---

## 7. Matériel

### Douchette code-barres
Toute douchette USB en mode « clavier » (HID) fonctionne, configurée pour envoyer **Entrée**
après chaque code (réglage d'usine le plus courant). En caisse, le mode **Auto** ajoute
l'article dès la lecture, même si le curseur n'est pas dans le champ code-barres.

### Plusieurs imprimantes (alimentation + restaurant…) — impression par le serveur
Un navigateur imprime toujours sur l'imprimante **par défaut** de l'appareil. Pour envoyer
chaque document sur sa propre imprimante (tickets de l'alimentation, bons de la cuisine, bons
du bar…), déclarez les imprimantes dans **Paramètres → Imprimantes** : c'est alors le serveur
POS Wan (le PC où tourne WampServer) qui leur envoie les données en **ESC/POS** (langage
de la plupart des imprimantes thermiques : Epson, Xprinter, Rongta, Sunmi…).

| Branchement | À saisir dans « Adresse » | Préparation |
|---|---|---|
| Réseau (câble Ethernet ou Wi-Fi) | `192.168.1.50` (port 9100 par défaut) ou `192.168.1.50:9100` | Donnez une **adresse IP fixe** à l'imprimante (page de configuration imprimée en maintenant le bouton FEED à l'allumage). |
| USB sur le PC serveur | `\\localhost\XP-80` | Windows : *Paramètres → Imprimantes → l'imprimante → Propriétés de l'imprimante → Partage* : cochez « Partager », nom de partage sans espace (ex. `XP-80`). |

Pour chaque imprimante, cochez ses **usages** (Tickets de caisse, Bons cuisine, Bons bar,
Additions restaurant) et, si besoin, la **caisse concernée** (ex. caisse « Alimentation » →
imprimante A, caisse « Restaurant » → imprimante B). Le bouton **Tester** imprime une page
de test (accents, montant, QR code). Options : coupe automatique, ouverture du tiroir-caisse
à chaque ticket, nombre de copies.

Remarques :
- L'imprimante réseau doit être sur le **réseau local** (adresses 192.168.x.x, 10.x.x.x,
  172.16–31.x.x ou nom local) ; une adresse Internet est refusée.
- Si l'imprimante est éteinte ou injoignable, la vente est **quand même enregistrée** ; un
  message s'affiche et le ticket peut être réimprimé (navigateur ou bouton « Imprimer sur
  l'imprimante de caisse » de la fiche de vente).
- L'écriture arabe ne s'imprime pas en mode ESC/POS texte : utilisez l'impression navigateur
  (imprimante « Navigateur ») pour des tickets en arabe.
- Sans imprimante serveur pour un usage, l'impression se fait par le navigateur comme ci-dessous.

### Imprimante thermique par le navigateur (tickets 58/80 mm)
Sans imprimante déclarée côté serveur, POS Wan imprime via le **pilote Windows** de
l'imprimante par défaut de l'appareil :

1. Installez le pilote du fabricant, réglez le papier sur 80 mm (ou 58 mm) et la hauteur « continue ».
2. Définissez-la comme imprimante par défaut du poste de caisse.
3. Dans Chrome, à la première impression : Marges = « Aucune », En-têtes et pieds de page = décoché.
4. Impression **sans boîte de dialogue** (facultatif) : créez un raccourci Chrome avec l'option
   `--kiosk-printing`, par exemple :
   `"C:\Program Files\Google\Chrome\Application\chrome.exe" --kiosk-printing --app=http://pos-wan.local/pos`

Les factures A4, bons de commande, bons de réception, états de caisse et étiquettes s'impriment
sur une imprimante classique ; « Enregistrer au format PDF » de la fenêtre d'impression produit un PDF.

### Tiroir-caisse
Avec une imprimante déclarée côté serveur, cochez « Ouvrir le tiroir-caisse à chaque ticket »
(impulsion ESC/POS). Avec l'impression navigateur, réglez le pilote pour ouvrir le tiroir
« avant impression » (option du pilote).

### Badge RFID (optionnel)
Lecteurs USB en émulation clavier uniquement. Activez « Connexion par badge RFID » dans
Paramètres, puis enregistrez le badge de chaque utilisateur (Utilisateurs → Modifier).
Le numéro du badge n'est jamais stocké en clair (empreinte HMAC).

### Restaurant : écrans et bons de cuisine
- **Écran cuisine / bar** : ouvrez `http://pos-wan.local/kitchen` sur une tablette ou un
  téléviseur avec navigateur placé en cuisine (compte de rôle « Cuisinier / barman »).
  Un onglet par poste : cuisine, grill, bar… (le poste se règle sur chaque catégorie).
- **Bons imprimés** : déclarez une imprimante par poste (usage « Bons cuisine », « Bons
  bar »…) dans Paramètres → Imprimantes : chaque bon part automatiquement à l'envoi sur
  l'imprimante de son poste. Sans imprimante de poste, les commandes s'affichent sur
  l'écran cuisine et le bon peut être imprimé **à la demande** (bouton « Bon cuisine » de
  la commande ou icône imprimante de chaque ticket de l'écran cuisine).
- **Serveurs sur tablette/téléphone** : la prise de commande fonctionne dans le navigateur
  sur le Wi-Fi de l'établissement (voir § 4 `Require ip` et § 8 HTTPS).

### Balance
Les articles « vendus au poids » acceptent des quantités décimales (saisie du poids au pavé
numérique). La lecture automatique d'une balance connectée **n'est pas implémentée**.

---

## 8. Sécurisation du poste (production)

- Dans `.env` : `APP_ENV=production` et `APP_DEBUG=false` (valeurs écrites par l'assistant).
- Mot de passe pour le compte MySQL `root` (phpMyAdmin → Comptes utilisateurs).
- phpMyAdmin et la page d'accueil de Wamp accessibles en local uniquement
  (`Require local` dans leurs alias — réglage par défaut de Wamp à conserver).
- Ne mettez **jamais** WampServer « en ligne » (option *Put Online*) pour un accès Internet direct.
- MySQL ne doit pas être joignable depuis le réseau : laissez `bind-address = 127.0.0.1`
  (ou ne l'ouvrez pas dans le pare-feu, port 3306).
- Pare-feu Windows : autorisez uniquement le port 80/443 depuis le réseau local si d'autres
  appareils utilisent POS Wan, et rien depuis Internet.
- Accès à distance (propriétaire en déplacement) : uniquement via un VPN correctement configuré.
- Comptes Windows : session protégée par mot de passe, verrouillage automatique de l'écran.
- Voir `SECURITE.md` pour la liste des mesures et des risques résiduels.

### HTTPS sur le réseau local (recommandé si plusieurs appareils)
Les identifiants circulent en clair en HTTP sur le Wi-Fi. Pour chiffrer :

1. Générez un certificat local (par exemple avec `mkcert pos-wan.local 192.168.1.10`)
   et installez l'autorité de certification sur chaque appareil.
2. Activez `ssl_module` dans Wamp et ajoutez un `VirtualHost *:443` avec
   `SSLEngine on`, `SSLCertificateFile` et `SSLCertificateKeyFile`.
3. Dans `.env` : `SESSION_SECURE=true`.

---

## 9. Sauvegardes

### Manuelle
Menu **Sauvegardes** → « Sauvegarder maintenant ». Chaque sauvegarde comprend :
- `poswan_AAAAMMJJ_HHMMSS_manuel.sql.gz` : toute la base, compressée, avec empreinte SHA-256 ;
- `…_fichiers.zip` : photos et logo (`public/uploads`).

Les archives sont dans `storage\backups` (jamais accessibles par le navigateur ; le
téléchargement passe par un formulaire réservé aux administrateurs). Les 15 dernières
sont conservées (paramètre réglable).

### Automatique (Planificateur de tâches Windows)
Ouvrez une invite de commandes **en administrateur** :

```bat
schtasks /Create /SC DAILY /ST 21:30 /TN "POS Wan - Sauvegarde" /RL HIGHEST ^
 /TR "\"C:\wamp64\bin\php\php8.2.x\php.exe\" \"C:\wamp64\www\pos-wan\bin\backup.php\" --copy-to=\"E:\Sauvegardes POS\""
```

(adaptez la version de PHP et le lecteur de copie). `--copy-to` recopie l'archive sur un
second support (disque USB, NAS, dossier synchronisé).

### Copie hors site (indispensable)
Une sauvegarde restée sur le même ordinateur ne protège ni du vol, ni de l'incendie, ni
d'une panne de disque. Recopiez au moins **une fois par semaine** une sauvegarde sur un
support conservé **hors de la boutique** (clé USB chiffrée BitLocker To Go, disque externe
chiffré, ou stockage en ligne chiffré). Testez une restauration une fois par trimestre.

Conservez aussi une copie du fichier `.env` (accès à la base) dans un endroit sûr :
il n'est pas inclus dans les sauvegardes pour ne pas diffuser le mot de passe de la base.

---

## 10. Restauration

### Depuis l'application
Menu **Sauvegardes** → Restauration : choisissez l'archive, saisissez votre mot de passe et
tapez `RESTAURER`. L'archive est vérifiée (empreinte + lecture complète), une **sauvegarde de
sécurité** de l'état actuel est faite automatiquement, puis la base est remplacée.
Les photos se restaurent en décompressant le `.zip` dans `public\uploads`.

### Après une panne (nouveau poste)
1. Installez WampServer et copiez POS Wan (§ 2).
2. Recréez la base et l'utilisateur MySQL (§ 3) ; replacez votre `.env` sauvegardé
   (ou recréez-le depuis `.env.example`).
3. Restaurez la base en ligne de commande :
   ```bat
   cd C:\wamp64\www\pos-wan\storage\backups
   "C:\Program Files\7-Zip\7z.exe" e poswan_XXXXXXXX_XXXXXX_auto.sql.gz
   C:\wamp64\bin\mysql\mysqlX.X.X\bin\mysql.exe -u poswan -p poswan < poswan_XXXXXXXX_XXXXXX_auto.sql
   ```
4. Créez le fichier `storage\installed.lock` (contenu libre) pour désactiver l'installateur.
5. Décompressez l'archive `_fichiers.zip` dans `public\` (dossier `uploads`).

---

## 11. Mise à jour sans perte de données

1. Faites une sauvegarde (menu Sauvegardes) et une copie du dossier complet.
2. Remplacez les fichiers de l'application **sauf** : `.env`, `storage\`, `public\uploads\`.
3. Appliquez les évolutions du schéma :
   ```bat
   cd C:\wamp64\www\pos-wan
   C:\wamp64\bin\php\php8.2.x\php.exe bin\migrate.php
   ```
   (`migrate.php` crée d'abord une sauvegarde de sécurité, puis applique uniquement les
   fichiers de `database\migrations` non encore exécutés.)
4. Videz le cache du navigateur (Ctrl+F5).

---

## 12. Dépannage

| Symptôme | Cause probable / solution |
|---|---|
| Page blanche ou « erreur interne » | Consultez `storage\logs\app-AAAA-MM.log` et le journal Apache. Mettez temporairement `APP_DEBUG=true` **sur le poste uniquement**. |
| 404 sur toutes les pages sauf l'accueil | `rewrite_module` désactivé ou `AllowOverride None` : activez le module et `AllowOverride All`. |
| « Connexion à la base impossible » | Vérifiez `.env` (hôte, base, utilisateur, mot de passe) et que MySQL est démarré (icône Wamp verte). |
| « Session expirée ou jeton de sécurité invalide » | Page restée ouverte trop longtemps : rechargez (F5). Vérifiez que l'heure du poste est correcte. |
| Connexion impossible après plusieurs essais | Verrouillage de sécurité (15 min). Un administrateur peut déverrouiller le compte (Utilisateurs). |
| « La caisse est fermée » | Ouvrez la caisse (Ma caisse) ; l'utilisateur doit avoir une caisse affectée. |
| « Stock insuffisant » | Le stock négatif est bloqué : enregistrez la réception ou un ajustement. |
| Le ticket s'imprime en A4 / avec marges | Réglez le format papier du pilote et « Marges : aucune » dans Chrome (§ 7). |
| La douchette ne fait rien | Testez-la dans le Bloc-notes : elle doit écrire le code puis aller à la ligne. |
| Photos refusées | Formats JPG/PNG/WEBP/GIF, 2 Mo maximum ; extension `fileinfo` requise. |
| Import CSV refusé | Enregistrez depuis Excel en « CSV (séparateur : point-virgule) » ; colonnes `nom` et `prix_vente` obligatoires. Utilisez la simulation pour voir les erreurs ligne par ligne. |
| Heures décalées | `APP_TIMEZONE=Africa/Niamey` dans `.env` et heure Windows correcte. |

---

## 13. Tests automatisés (développement)

Sur un poste de développement uniquement (la base de test est **recréée** à chaque exécution) :

```bat
set TEST_DB_USER=poswan
set TEST_DB_PASS=...
php tests\run.php
```

L'utilisateur MySQL doit pouvoir créer la base `poswan_test`. Résultat attendu :
`67 réussi(s), 0 échec(s)`.
