# Démarrage de POS Marwa sous Windows (WampServer)

Ce document décrit le lanceur `demarrer-pos-marwa.bat`, le raccourci du Bureau, le démarrage
automatique à l'ouverture de session et l'accès depuis les smartphones (QR code, caméra).

> **Vérification honnête** : les fichiers `.bat` ont été écrits et relus pour Windows 10/11
> (ASCII pur, fins de ligne CRLF), mais ils **n'ont pas pu être exécutés** dans
> l'environnement de développement (Linux). Faites l'essai décrit au §5 sur le poste de
> l'établissement avant de compter dessus.

## 1. Fichiers fournis

| Fichier | Rôle |
|---|---|
| `demarrer-pos-marwa.bat` (racine) | Démarre WampServer si besoin, attend Apache et MySQL/MariaDB, ouvre POS Marwa une seule fois |
| `windows\installer-raccourci.bat` | Crée le raccourci « POS Marwa » (icône) sur le Bureau et dans le menu Démarrer |
| `windows\installer-demarrage-auto.bat` | Crée la tâche planifiée « POS Marwa » (ouverture de session Windows) |
| `windows\desinstaller-demarrage-auto.bat` | Supprime cette tâche planifiée |
| `windows\pos-marwa.ini.exemple` | Réglages facultatifs (adresse, dossier Wamp, type de fenêtre, attente) |
| `windows\pos-marwa.ico` | Icône du raccourci |
| `windows\installer-voix.bat` | MARWA VOICE AI : met en place et vérifie la voix Piper livrée dans le dossier (sans Internet) |
| `windows\service-vocal.bat` | Service vocal Piper (lancé par `demarrer-pos-marwa.bat` quand Piper est installé) |
| `windows\trouver-php.bat` | Utilitaire : trouve `php.exe` de WampServer pour les deux scripts précédents |

Le dossier `windows\` est inaccessible depuis le navigateur (règle du `.htaccess` racine).

## 2. Ce que fait `demarrer-pos-marwa.bat`

1. Lit `windows\pos-marwa.ini` s'il existe. Par défaut, l'adresse est
   `http://localhost/<nom du dossier>/`, par exemple `http://localhost/pos-marwa/`.
2. Vérifie si POS Marwa répond déjà. Il interroge `/health`, une page qui contrôle PHP **et**
   la base de données. Si POS Marwa répond, le lanceur ouvre l'application directement.
3. Cherche WampServer :
   - d'abord le dossier parent (cas `C:\wamp64\www\pos-marwa`) ;
   - puis le Registre Windows (programme de désinstallation de WampServer) ;
   - enfin `C:\wamp64`, `C:\wamp`, `D:\wamp64`, `D:\wamp` et `E:\wamp64`.

   Vous pouvez imposer le dossier avec `WAMP=` dans le fichier `.ini`.
4. Lance `wampmanager.exe` **une seule fois** (pas de second lancement s'il tourne déjà). Il
   démarre aussi les services Windows `wampapache64` / `wampmysqld64` / `wampmariadb64`
   s'ils existent et sont arrêtés.
5. Attend jusqu'à `ATTENTE` secondes (120 par défaut) que `/health` réponde « ok ».
6. Ouvre POS Marwa :
   - avec `NAVIGATEUR=app` (défaut), dans une **fenêtre dédiée** Edge ou Chrome (`--app`),
     sans barre d'adresse ;
   - sinon, dans le navigateur par défaut.

   Un verrou de 60 secondes empêche l'ouverture de plusieurs fenêtres en cas de double-clic.

   La fenêtre dédiée autorise le son sans clic préalable
   (`--autoplay-policy=no-user-gesture-required`), pour les annonces de l'assistant vocal. Si
   la voix Piper est livrée ou installée, le **service vocal** est
   lancé dans une fenêtre réduite. S'il tourne déjà, le second s'arrête seul.
7. En cas de problème, affiche un message clair : Wamp introuvable, délai dépassé, base
   arrêtée, etc. Le message apparaît dans une fenêtre en mode `--auto`. Tout est consigné
   dans `storage\logs\demarrage.log`.

Le lanceur ne modifie aucun réglage de sécurité de Windows, ne désactive ni l'UAC ni le
pare-feu, et n'enregistre aucun mot de passe.

## 3. Raccourci sur le Bureau

Double-cliquez sur `windows\installer-raccourci.bat`. Il crée le raccourci « POS Marwa » sur le
Bureau et dans le menu Démarrer. Ce raccourci lance `demarrer-pos-marwa.bat` en fenêtre réduite.

Si vous déplacez le dossier de POS Marwa, relancez `installer-raccourci.bat`.

## 4. Démarrage automatique avec Windows

### Option recommandée : tâche planifiée

1. Clic droit sur `windows\installer-demarrage-auto.bat` → **Exécuter**. Windows demande
   l'autorisation (UAC) : acceptez. L'élévation est nécessaire pour que WampServer puisse
   démarrer ses services.
2. Cela crée la tâche planifiée « POS Marwa » :
   - déclenchement : à l'ouverture de session ;
   - délai : 30 secondes, pour laisser le réseau démarrer ;
   - privilèges : les plus élevés (`/RL HIGHEST`) ;
   - action : `demarrer-pos-marwa.bat --auto`.
3. Pour la vérifier : Planificateur de tâches → Bibliothèque → « POS Marwa ».
4. Pour l'enlever : `windows\desinstaller-demarrage-auto.bat`.

La tâche s'exécute dans la session de l'utilisateur qui l'a créée. La session Windows reste
protégée par son mot de passe : rien ne démarre avant la connexion.

### Option complémentaire : services WampServer en démarrage automatique

Si Apache et MySQL doivent tourner même sans session ouverte (par exemple pour un poste
serveur sans écran), faites ceci :
- ouvrez `services.msc` ;
- passez `wampapache64` et `wampmysqld64` (ou `wampmariadb64`) en « Automatique ».

Le lanceur détecte alors que POS Marwa répond et ouvre seulement la fenêtre.

## 5. Essai à faire sur le poste (5 minutes)

1. Arrêtez WampServer (icône → Quitter), puis double-cliquez sur le raccourci : Wamp doit
   démarrer et POS Marwa doit s'ouvrir en moins de 2 minutes.
2. Double-cliquez à nouveau : la fenêtre ne doit pas se dédoubler en boucle, et Wamp ne
   doit pas être relancé.
3. Fermez la session Windows, rouvrez-la : POS Marwa doit s'ouvrir seul (tâche planifiée).
4. En cas d'échec, lisez `storage\logs\demarrage.log`, qui indique l'étape en cause.

## 6. Accès depuis les smartphones et tablettes (réseau local)

Allez dans **Configuration → Accès mobile (QR code)**. Cette page :
- détecte l'adresse IP locale du serveur, par exemple `192.168.1.10` ;
- affiche le QR code de la page d'accueil mobile `/m` ;
- signale si l'adresse a changé depuis la dernière visite (pensez à réserver l'adresse
  dans la box ou le routeur).

Deux réglages Windows/Apache sont nécessaires une fois :

1. **Apache** : dans l'hôte virtuel de POS Marwa (`httpd-vhosts.conf`), gardez `Require local`
   et ajoutez `Require ip 192.168 10 172.16.0.0/12` (réseaux privés uniquement). Redémarrez
   Apache.
2. **Pare-feu Windows** (invite de commandes administrateur) :
   ```
   netsh advfirewall firewall add rule name="POS Marwa (Apache)" dir=in action=allow protocol=TCP localport=80 profile=private
   ```
   Le profil réseau du Wi-Fi doit être « Privé ».

MySQL/MariaDB (port 3306) **ne doit pas** être ouvert au réseau. Les smartphones ne parlent
qu'à Apache.

Le QR code ne donne aucun droit : l'identifiant et le mot de passe restent exigés.
L'administrateur peut exiger l'approbation des nouveaux appareils et révoquer un appareil
(**Configuration → Appareils connectés**).

## 7. Caméra des smartphones : HTTPS

Les navigateurs n'autorisent la caméra en direct que sur `https://` ou sur `localhost`. En
`http://192.168.x.x`, POS Marwa propose automatiquement **« Photo du code »** : la caméra du
téléphone prend une photo, que POS Marwa décode. La douchette USB ou Bluetooth fonctionne
toujours.

Pour le scan en direct, installez un certificat local :
1. Téléchargez `mkcert` sur le serveur, puis :
   ```
   mkcert -install
   mkcert pos-marwa.local 192.168.1.10
   ```
2. Dans Wamp :
   - activez `ssl_module` et `socache_shmcb_module` ;
   - ajoutez un `<VirtualHost *:443>` avec `SSLEngine on`, `SSLCertificateFile` et
     `SSLCertificateKeyFile` (fichiers créés par mkcert), et les mêmes règles `Require`
     qu'au §6.
3. Installez l'autorité de certification de mkcert (`rootCA.pem`, dossier indiqué par
   `mkcert -CAROOT`) sur chaque téléphone : Paramètres → Sécurité → Installer un certificat.
4. Dans `.env` : `SESSION_SECURE=true`.
5. Ouvrez le pare-feu sur le port 443 (même commande qu'au §6 avec `localport=443`).
6. Dans `windows\pos-marwa.ini` : `URL=https://pos-marwa.local/`.

## 8. Application sur l'écran d'accueil (PWA)

Sur le téléphone, ouvrez la page `/m` puis choisissez « Ajouter à l'écran d'accueil ». POS
Marwa s'ouvre alors en plein écran avec son icône.

Aucune donnée n'est stockée hors ligne : le serveur reste nécessaire pour vendre.
