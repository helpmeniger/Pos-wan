# POS Marwa Bureau — édition Windows autonome (`setup.exe`)

POS Marwa installé comme un logiciel Windows ordinaire : un installateur, une icône, une fenêtre. Aucun
WampServer, XAMPP ni serveur à installer : PHP et MariaDB sont livrés dans le dossier du logiciel et démarrés,
sans fenêtre, par `POS Marwa.exe`. Tout (programme, base de données, sauvegardes, journaux) reste dans ce dossier.

Édition **gestion commerciale** : le logiciel complet (caisse, articles, stock, achats, fournisseurs, clients,
crédits, devis, retours, rapports, configuration, mises à jour) et les modules commerciaux
`banques comptabilite doublons fiscal livraisons marwa_ia pay` (à activer dans Configuration → Modules
complémentaires). Les modules sans rapport avec un commerce (ONG, RH, école, hôtel, clinique, pressing, archives,
boutique en ligne, caméras, voix, assistance à distance) ne sont pas livrés.

Menu de gestion **en tuiles** (écran « Menu de gestion ») au lieu de la liste latérale ; la caisse est inchangée.

## Fonctionnement

```
POS Marwa.exe ──► MariaDB 10.11   (127.0.0.1:3407, donnees\mysql)
             ├──► 8 serveurs PHP  (php -S 127.0.0.1:188xx, chacun traite une requête à la fois)
             ├──► répartiteur     (127.0.0.1:8740 → serveur PHP le moins occupé ; réponses en continu)
             └──► fenêtre         (Edge ou Chrome en mode application, profil donnees\fenetre)
```

| Étape | Détail |
|---|---|
| Premier démarrage | `mariadb-install-db` crée la base ; mot de passe root et mot de passe de l'application tirés au hasard, avec la clé `APP_KEY`, chiffrés par Windows pour cet ordinateur (`donnees\secret\bd.dat`, DPAPI) ; base `poswan` et compte `poswan` (droits sur cette base seulement) ; `app\.env` écrit **sans aucun secret** (`MENU_STYLE=tuiles`, `POS_EDITION=bureau`) : clé et accès à la base sont transmis aux serveurs PHP par leur environnement. Date du premier démarrage gardée hors du dossier (registre, `%ProgramData%\HighTechNiger\pm.dat`). Un premier démarrage interrompu est refait : le dossier inachevé est mis de côté (`donnees\mysql-inacheve-…`), jamais supprimé. |
| Démarrage suivant | Base démarrée avec `donnees\mariadb.ini` (réécrit à chaque fois) ; une base restée démarrée (lanceur arrêté de force) est réutilisée. |
| Fenêtre | Ouverte tout de suite sur une page « Démarrage… » qui se recharge seule. Chaque page envoie un signe de vie toutes les 15 s (`/__bureau/battement.js`, ajouté par le répartiteur). Sans signe de vie depuis 3 minutes (dernière fenêtre fermée, sur ce PC ou un autre poste), arrêt des serveurs PHP puis de la base (`mariadb-admin shutdown`). La mise en veille du PC n'est pas comptée. |
| Double-clic alors que POS Marwa tourne | Une nouvelle fenêtre s'ouvre ; rien n'est redémarré. |
| `POS Marwa.exe --arreter` | Arrête tout (raccourci « Arrêter POS Marwa ») ; jeton local `donnees\secret\arret.jeton`. |
| Contrôle de démarrage | Base (compte de l'application), serveurs PHP, application par le chemin de la fenêtre, fichiers des pages ; message à l'écran en cas de problème ; rapport `donnees\journaux\diagnostic.txt` et page `/__bureau/diagnostic` (ce PC seulement, sans mot de passe). Plantage du lanceur : `plantage.log`. |
| `POS Marwa.exe --sans-fenetre` | Serveurs seuls (poste serveur pour d'autres caisses, essais). |

## Sécurité

- Base de données : écoute **127.0.0.1 seulement** ; aucun compte anonyme ; aucun mot de passe par défaut ; les
  mots de passe ne passent jamais sur la ligne de commande (fichier d'identification temporaire).
- Répartiteur : 127.0.0.1 par défaut ; réseau local seulement avec `RESEAU=1` (règle de pare-feu limitée au
  programme et aux réseaux privés, posée par l'installateur si l'option est cochée).
- Serveurs PHP : 127.0.0.1 ; le routeur (`app\routeur.php`) ne sert que `public\` et n'exécute que
  `public\index.php` (ni `.env`, ni `app\`, ni fichier caché).
- Adresse du poste client : transmise par le répartiteur avec un jeton secret tiré à chaque démarrage ; sans lui,
  l'en-tête est ignoré (le blocage après échecs de connexion par adresse ne peut pas être contourné).
- Si `POS Marwa.exe` est arrêté de force, Windows arrête aussi les serveurs PHP (groupe de processus).
- Limites : le code PHP reste lisible dans `app\` (protection par licence signée, pas par chiffrement) ; tout
  administrateur de l'ordinateur peut déchiffrer `donnees\secret\bd.dat` (protection DPAPI « machine ») ; copié sur un autre ordinateur, ce fichier est illisible et POS Marwa ne démarre pas. Windows réinstallé compte comme un nouvel ordinateur : restaurer une sauvegarde (support externe). L'installateur n'est pas signé
  (avertissement SmartScreen) : un certificat de signature de code est nécessaire pour le supprimer.

## Construire l'installateur (sous Linux)

Outils : `makensis` (NSIS 3), Go ≥ 1.22, `x86_64-w64-mingw32-windres` (paquet `binutils-mingw-w64-x86-64`),
`cpp`, `unzip`, `php`.

```bash
# composants officiels (empreintes vérifiées par le script)
curl -LO https://windows.php.net/downloads/releases/php-8.3.37-nts-Win32-vs16-x64.zip
curl -LO https://archive.mariadb.org/mariadb-10.11.20/winx64-packages/mariadb-10.11.20-winx64.zip
curl -L -o mariadb-10.11.20.sha https://archive.mariadb.org/mariadb-10.11.20/winx64-packages/sha256sums.txt
curl -L -o vc_redist.x64.exe https://aka.ms/vs/17/release/vc_redist.x64.exe

bureau/construire.sh <dossier des composants> <dossier de sortie>
# → POS-Marwa-Bureau-<version>-Setup.exe
```

## Essais

- `bureau/essai-linux.sh` : le lanceur sous Linux avec le PHP et le MariaDB du système (premier démarrage,
  `.env`, base locale seulement, assistant d'installation, routeur, requêtes simultanées, arrêt propre, second
  démarrage, contrôle de démarrage, `.env` réaligné, fenêtre, secrets chiffrés, dossier copié refusé, conversion 3.13) — 29 vérifications.
- `tests/run.php` : test « Menu de gestion en tuiles ».
- **Non exécuté sous Windows** (aucun PC Windows ni Wine fonctionnel dans l'environnement de construction) :
  installateur, `POS Marwa.exe`, PHP et MariaDB pour Windows, fenêtre Edge, raccourcis, désinstallation. Vérifié
  sans les exécuter : bibliothèques nécessaires toutes présentes (ou fournies par Windows 10/11), programme
  « Windows GUI » sans console, contenu de l'installateur.

## Fichiers

| Fichier | Rôle |
|---|---|
| `lanceur/` | `POS Marwa.exe` (Go, sans dépendance) ; `diagnostic.go` : contrôle de démarrage et rapport |
| `routeur.php` | routeur des serveurs PHP (copié en `app\routeur.php`) |
| `php.ini` | configuration PHP de l'édition |
| `pos-marwa.ini` | réglages modifiables (ports, réseau, impression directe, navigateur) |
| `installateur.nsi` | installateur et désinstallateur |
| `construire.sh` | assemblage complet |
| `essai-linux.sh` | essai automatique du lanceur |
| `LISEZ-MOI.txt`, `LICENCES-TIERS.txt` | livrés avec le logiciel |
