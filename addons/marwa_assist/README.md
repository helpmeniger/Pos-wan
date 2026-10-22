# MARWA REMOTE ASSIST — assistance à distance (module `marwa_assist` 1.0.0)

High Tech Niger (HTN) voit l'écran d'un PC de la boutique pour aider le caissier. Le contrôle
clavier et souris n'est donné que si un responsable l'autorise. **La caisse continue de
fonctionner pendant toute l'assistance.**

Le module contient tout : le **client** pour les PC (kits à télécharger), le **serveur
HTN** (présence et relais) et les écrans de suivi dans POS Marwa. Le moteur de prise en main est
**RustDesk**, logiciel libre (AGPL-3.0). Il est livré **sans modification** et lancé comme un
programme séparé (voir « Licences »).

## 1. Les quatre composants

```
 PC de la boutique                         Serveur HTN (IP publique)              PC du technicien
 ┌──────────────────────────┐              ┌──────────────────────────┐          ┌───────────────────┐
 │ (1) POS Marwa (WampServer)│              │ (4) hbbs : présence /    │          │ (2) RustDesk      │
 │   bouton « Assistance »,  │              │     identifiants         │◄────────►│  (kit technicien, │
 │   bandeau, historique     │              │     TCP 21115-21116      │          │   sans install.)  │
 │ (2) RustDesk installé par │──sortant────►│     UDP 21116            │          └───────────────────┘
 │   le kit (3) moteur de    │  chiffré     │ (4) hbbr : relais        │
 │   prise en main           │◄────────────►│     TCP 21117            │
 └──────────────────────────┘              └──────────────────────────┘
```

| Composant | Où | Fourni par le module |
|---|---|---|
| 1. Serveur web POS Marwa | PC de la boutique (WampServer) | écrans, bandeau, historique, droits, kits, diagnostic |
| 2. Client Windows | chaque PC à assister + PC du technicien | `client/rustdesk.exe` (1.5.0) dans les kits |
| 3. Moteur de prise en main | dans le client | RustDesk (écran chiffré, clavier/souris, fenêtre de consentement) |
| 4. Relais | serveur HTN | `hbbs` + `hbbr` (RustDesk Server OSS 1.1.16, Windows et Linux) |

Les boutiques n'ont **rien à ouvrir** sur leur box : le PC de la boutique se connecte vers le
serveur HTN. POS Marwa reste fermé à Internet ; WampServer n'est jamais exposé.

## 2. Installation

### 2.1 Serveur HTN (une seule fois)
- **Windows** : double-cliquez `windows\assist-serveur.bat`.
  - Le script demande les droits administrateur.
  - Il ouvre le pare-feu Windows (TCP 21115-21117, UDP 21116).
  - Il reconstitue les programmes et démarre le serveur.
  - **La fenêtre doit rester ouverte.**
- **VPS Linux** : `php bin/assist.php serveur`, à lancer de préférence comme service
  (systemd), dans le dossier POS Marwa.
- Sur la box ou le pare-feu, ouvrez vers ce PC **TCP 21115, 21116, 21117 et UDP 21116**, rien
  d'autre.
- Notez :
  - **l'adresse IP publique** ;
  - **la clé** affichée par le serveur (aussi dans `php bin/assist.php cle` et dans l'onglet
    « Serveur HTN et boutiques »).

Aucun nom de domaine n'est nécessaire. Il faut une adresse **fixe** : sur une box dont l'IP
change, les boutiques perdent le serveur à chaque changement. Un petit VPS à IP fixe est le plus
sûr. Une connexion derrière CGNAT (fréquente en 4G) ne peut pas héberger le serveur.

### 2.2 Dans chaque boutique (POS Marwa → Configuration → Assistance à distance → Réglages)
- Adresse du serveur HTN : l'IP publique. Pour un essai sur le même réseau, l'IP locale du
  serveur, ex. `192.168.1.10`.
  - `127.0.0.1` et `localhost` sont refusés : les autres PC ne joindraient pas le serveur, et le
    relais hbbr réserve les connexions locales à ses commandes d'administration.
- Clé publique : collez-la exactement (44 caractères).
- **Accès sans surveillance** : désactivé par défaut. Pour l'activer, il faut cocher la case
  **et** taper « SANS SURVEILLANCE ». Le kit demande alors un mot de passe permanent.

### 2.3 Équiper un PC (onglet « Postes équipés »)
1. Nommez le poste (ex. « Caisse 1 »), puis cliquez **Kit du poste** et téléchargez le ZIP.
   Le ZIP contient : `rustdesk.exe` officiel, `installer-assistance.bat`, la notice et la licence.
2. Sur le PC, décompressez le ZIP et double-cliquez `installer-assistance.bat` (droits
   administrateur). Le script :
   - installe RustDesk ;
   - le règle sur le serveur HTN avec la clé imposée ;
   - règle la sécurité : acceptation par clic, clavier/souris, presse-papiers, fichiers,
     son, tunnel et redémarrage coupés, fenêtre de connexion jamais masquée, pas de découverte
     réseau ;
   - **transmet l'identifiant du poste** à POS Marwa grâce à un jeton secret, valable par défaut
     7 jours (le serveur n'en garde que l'empreinte) ;
   - si l'envoi échoue, l'identifiant affiché se saisit à la main dans « Postes équipés ».
3. **Kit technicien** : RustDesk sans installation, réglé sur le serveur HTN. L'ancienne
   configuration du technicien est sauvegardée (`RustDesk2.toml.avant-marwa`).

« Retirer » un poste efface son jeton. Désinstallez aussi RustDesk du PC s'il ne doit plus être
assisté.

## 3. Utilisation (bouton **Assistance à distance**)

1. **Assistance à distance** : le caissier décrit le problème. Il donne au technicien le **code
   boutique** et l'**identifiant du poste** affichés (appel ou lien WhatsApp).
2. **Partager mon écran** (observation) :
   - le technicien se connecte ;
   - une **fenêtre RustDesk demande d'accepter** sur le PC : rien ne s'affiche chez le
     technicien sans ce clic ;
   - le technicien **voit** l'écran mais ne peut ni taper ni cliquer ;
   - un **bandeau** reste affiché sur toutes les pages de POS Marwa : « Assistance : écran
     observé ».
3. **Autoriser le contrôle à distance** :
   - un responsable (gérant ou propriétaire, droit `assist.control`) saisit son identifiant
     et son mot de passe ou PIN ;
   - la durée est limitée : 15 min par défaut, 60 au plus ;
   - le bandeau devient rouge avec un compte à rebours ;
   - le caissier coche **Clavier** dans la fenêtre RustDesk ;
   - sur le PC qui héberge POS Marwa, le module coupe et rétablit ce réglage lui-même (voir
     limites) ;
   - à l'heure dite, l'intervention revient automatiquement en observation.
4. **Terminer** : depuis la page ou le bandeau. Le caissier peut aussi cliquer **Disconnect**
   dans la fenêtre RustDesk : la coupure est immédiate.
5. **Historique** : qui a demandé, qui a autorisé, durée, technicien, résumé, et chaque
   événement :
   - demande, observation, contrôle autorisé, **tentative refusée**, retour en observation,
     fin ;
   - kits, postes, réglages.
   - Tout est aussi dans le journal d'audit général.

Une demande oubliée **expire après 8 h**.

**Diagnostic** (onglet réservé aux responsables) : un état technique à envoyer au technicien
(copier ou WhatsApp). Il ne contient aucune vente, aucun client et aucun mot de passe.
- Version, PHP, base de données (temps de réponse), caisses ouvertes, dernière vente.
- Imprimantes réseau, disque, serveur HTN joignable, postes équipés, licence.
- Dernières erreurs du journal, secrets masqués.

## 4. Pourquoi la caisse ne s'arrête pas
- RustDesk est un programme séparé : POS Marwa n'attend jamais lui pour vendre.
- Le bandeau fait une requête légère toutes les 20 s. Il s'arrête sans session (401) ou sans
  module (404).
- Serveur HTN arrêté, Internet coupé ou session coupée : seule l'assistance s'interrompt.
- Vérifié en réel (voir § 7) : une vente pendant le partage est enregistrée en 223 ms. Une autre
  vente faite pendant l'arrêt du serveur HTN l'est en 75 ms.

## 5. Sécurité — ce qui est garanti, et ce qui ne l'est pas

Le système n'est pas inviolable. Voici précisément ce que fait le module :
- **Consentement à chaque connexion** : acceptation par clic sur le PC (`approve-mode click`)
  et mot de passe à usage unique.
- **Jamais de session cachée** : la fenêtre de connexion ne peut pas être masquée
  (`allow-hide-cm N`) et le bandeau POS Marwa reste visible.
- **Observation par défaut** : clavier, presse-papiers, transfert de fichiers, son, tunnel et
  redémarrage sont coupés par le kit.
- **Contrôle autorisé** :
  - identifiants d'un responsable exigés (même vérification que les remises : tentatives
    limitées par IP) ;
  - durée limitée ;
  - refus et autorisations tracés.
- **Serveur HTN privé** :
  - clé imposée (`-k`) : un client qui n'a pas la bonne clé est refusé (« Key mismatch »,
    vérifié) ;
  - le trafic est chiffré de bout en bout par RustDesk ; le relais ne voit pas l'écran.
- **Révocation** : « Retirer » le poste (jeton effacé), « Disconnect » dans RustDesk, ou arrêt
  du serveur HTN.
- **Sans surveillance** : désactivé par défaut. Il faut une double confirmation et un mot de
  passe permanent choisi sur place.

Limites :
- Le blocage du clavier repose sur RustDesk.
  - POS Marwa ne peut pas empêcher le caissier de cocher « Clavier » sans autorisation : le
    réglage reste dans la fenêtre RustDesk.
  - Le module le coupe lui-même uniquement sur le PC qui héberge POS Marwa sous Windows
    (`rustdesk.exe --option enable-keyboard`), ce qui n'est **pas testé** (voir § 7).
- Le bandeau POS Marwa suit l'IP du poste. Le technicien peut se connecter à un poste sans
  demande dans POS Marwa : la fenêtre RustDesk et le clic d'acceptation restent obligatoires.
- Un technicien qui a le contrôle peut voir et faire ce que le caissier peut faire. Ne donnez le
  contrôle qu'à un technicien de confiance, sur une durée courte.

**Données personnelles (loi n° 2017-28 du Niger)** :
- l'écran partagé peut montrer des noms de clients, des montants ou des données de santé
  (pharmacie, clinique) ;
- informez le personnel et, si besoin, les clients ;
- n'ouvrez à l'écran que ce qui est utile ;
- l'historique ne garde que des informations techniques (motif, durées, noms des personnes) ;
- RustDesk n'enregistre pas l'écran.

## 6. Commandes
```
php bin/assist.php serveur            serveur HTN (hbbs + hbbr), relance automatique, Ctrl+C pour arrêter
php bin/assist.php etat               état du serveur local et du paquet
php bin/assist.php cle                clé publique à donner aux boutiques
php bin/assist.php deployer [--forcer] reconstitue les programmes (SHA-256 vérifié) dans storage/assist
```
Les programmes sont livrés en **fragments de 1 Mo** (`paquet/*.partNNN` + `paquet.json`) :
- aucun `.exe` en clair, que l'antivirus pourrait bloquer pendant la mise à jour par le
  navigateur ;
- ils sont reconstitués à l'identique au premier usage.

## 7. Tests réalisés et non réalisés

**Réalisés réellement** (conteneur Linux, RustDesk 1.5.0 Linux et serveur 1.1.16 Linux du
paquet, deux écrans virtuels, POS Marwa dans Chromium) :
- **Serveur** : démarré par `bin/assist.php serveur`, clé générée et imposée.
- **Enregistrement du poste** : le poste boutique s'enregistre (`update_pk`) ; son identifiant
  arrive dans POS Marwa par `/assist/enregistrer` avec le jeton du kit ; un faux jeton est
  refusé.
- **Session dans POS Marwa** :
  - demande, puis partage ; la fenêtre de consentement s'affiche ;
  - les permissions sont celles du kit : clavier, presse-papiers, son, fichiers et
    redémarrage coupés, écran seul ;
  - après acceptation, le technicien voit la caisse en direct.
- **Vente pendant le partage** : enregistrée (HTTP 200, 223 ms) ; le technicien voit le ticket.
- **Clavier** :
  - en observation, le texte tapé par le technicien **n'arrive pas** dans la caisse ;
  - contrôle refusé avec un mauvais mot de passe, accordé avec le bon (bandeau rouge et
    compte à rebours) ;
  - après « Clavier » coché par le caissier, le texte arrive ; décoché, il est de nouveau
    bloqué.
- **Fin** : terminer dans POS Marwa retire le bandeau et remplit l'historique ;
  « Disconnect » côté caisse coupe le technicien (« Closed manually by the peer »).
- **Client avec une autre clé** : refusé par le serveur (« Key mismatch », journal
  « invalid key »).
- **Relais forcé** (`force-always-relay`, adresse réseau) : session relayée par hbbr
  (« got paired »).
- **Arrêt du serveur HTN pendant une session** : technicien déconnecté ; vente faite au même
  moment enregistrée (75 ms).
- **Tests automatiques PHP** (`tests/run.php`, bloc MARWA REMOTE ASSIST, suite complète à
  129/129) : droits, paquet (fragments, aucun exe en clair, fragment altéré refusé), réglages
  refusés et acceptés, kit (exe identique à l'officiel, options du script, ASCII/CRLF, jeton
  haché), enregistrement (faux jeton, identifiant invalide, kit expiré, poste retiré), cycle
  complet, expiration du contrôle et des demandes, vente pendant l'assistance, accès refusés au
  caissier.

Particularités du conteneur de test, **qui ne concernent pas Windows** :
- RustDesk Linux attend une session graphique ouverte (`loginctl`), absente du conteneur. Une
  session a été simulée pour le seul processus de test.
- Le relais a été testé avec l'adresse réseau du conteneur, car hbbr traite 127.0.0.1 comme
  canal d'administration. C'est pour cela que 127.0.0.1 est refusé dans les réglages.

**Non réalisés (aucun PC Windows disponible)** :
- les scripts `installer-assistance.bat`, `demarrer-technicien.bat` et
  `assist-serveur.bat` sur un vrai Windows (`--silent-install`, `--option`, `--get-id`,
  pare-feu) ;
- la coupure automatique du clavier sur le poste serveur (`localKeyboard`) ;
- le fonctionnement à travers Internet entre deux réseaux réels (box, NAT, 4G) ;
- le comportement des antivirus face à `rustdesk.exe`.

À faire sur place avant la mise en service : équiper un PC, faire une session d'essai depuis un
autre réseau, puis vérifier l'historique.

## 8. Dépannage
| Symptôme | Cause probable |
|---|---|
| « Key mismatch » chez le technicien | la clé du kit technicien ou de la boutique ne correspond pas à celle du serveur |
| Le technicien attend sans fin | le caissier n'a pas cliqué **Accepter** dans la fenêtre RustDesk |
| « Envoi impossible » à la fin du kit | PC sans accès au POS Marwa : saisir l'identifiant à la main (Postes équipés) |
| Diagnostic : serveur HTN injoignable | Internet coupé, serveur HTN arrêté, ports non redirigés |
| Le technicien ne peut pas cliquer | normal en observation : faire autoriser le contrôle, puis cocher **Clavier** |

## 9. Licences
- **RustDesk 1.5.0** (client) et **RustDesk Server OSS 1.1.16** (`hbbs`, `hbbr`) :
  - licence **AGPL-3.0** (texte : `paquet/licences/rustdesk-AGPL-3.0.txt`, joint à chaque kit) ;
  - programmes officiels **non modifiés** ;
  - sources : https://github.com/rustdesk/rustdesk/tree/1.5.0 et
    https://github.com/rustdesk/rustdesk-server/tree/1.1.16 ;
  - empreintes d'origine : `paquet/LICENCES.md`.
  - Toute modification de ces programmes devrait être publiée sous AGPL-3.0.
- Le code du module (PHP, JS, vues) fait partie de POS Marwa ; il **lance** RustDesk comme un
  programme séparé.
