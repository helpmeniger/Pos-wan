# Journal des modifications

Format : [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/) — versions [SemVer](https://semver.org/lang/fr/).

## [2.7.0] — 2026-10-22

### Ajouté
- **MARWA REMOTE ASSIST** (`addons/marwa_assist`, module 1.0.0) : assistance à distance de
  n'importe quel PC de la boutique, sans arrêter la caisse.
  - **Client et serveur dans le module** : RustDesk 1.5.0 (client Windows) et RustDesk Server
    OSS 1.1.16 (`hbbs` + `hbbr`, Windows et Linux).
    - Logiciels libres AGPL-3.0, non modifiés.
    - Livrés en fragments de 1 Mo vérifiés par SHA-256.
  - **Boutons** :
    - Assistance à distance ;
    - Partager mon écran : observation seule ;
    - Autoriser le contrôle à distance : identifiants d'un responsable, durée limitée,
      retour automatique en observation ;
    - Terminer.
  - **Bandeau permanent** sur toutes les pages : « écran observé », ou « contrôle à distance »
    avec compte à rebours.
  - **Kits à télécharger** :
    - poste de boutique : installation, réglage sur le serveur HTN, consentement par clic,
      clavier coupé, fenêtre jamais masquée, identifiant transmis par jeton secret ;
    - technicien : sans installation.
  - **Onglets** : postes équipés, historique détaillé, diagnostic technique sans donnée
    commerciale, réglages.
  - **Serveur HTN** : `windows\assist-serveur.bat` (pare-feu, démarrage) ou
    `php bin/assist.php serveur` ; onglet « Serveur HTN et boutiques » (état, clé, annuaire).
  - Accès sans surveillance désactivé par défaut (double confirmation).
- Tests : bloc PHP complet (129/129 pour la suite) et essai réel avec deux clients RustDesk
  et le serveur du paquet. Détail dans `addons/marwa_assist/README.md`.

### Corrigé
- Installation d'un module au nom long : le libellé des permissions est tronqué à la taille de
  la colonne (l'installation échouait).

### Limites connues
- Scripts Windows (`.bat`) et coupure automatique du clavier sur le poste serveur non testés sur
  un vrai Windows.
- Pas encore d'essai entre deux réseaux Internet réels.

## [2.6.1] — 2026-10-21

### Corrigé
- **Mise à jour par le navigateur : « Failed to fetch ».**
  - **Le paquet ne contient plus aucun `.exe`, `.dll` ou modèle vocal en clair.** La voix est
    livrée uniquement en fragments neutres (`.partNNN`), reconstitués et vérifiés par SHA-256
    sur le serveur. Un antivirus ne peut plus bloquer ces fichiers pendant l'envoi.
  - **Envoi plus robuste** :
    - avant l'envoi, chaque fichier est relu et les fichiers illisibles (verrouillés ou mis en
      quarantaine) sont nommés ;
    - jusqu'à 4 nouveaux essais par lot, puis le lot est découpé jusqu'au fichier seul ;
    - le message d'erreur indique le fichier en cause et la marche à suivre.
  - **Connexion coupée pendant l'installation** : le serveur termine l'installation, sans
    copie à moitié faite ; la page attend qu'il réponde avec la nouvelle version.
- Essai réel avec les limites de WampServer (8 Mo par envoi, 2 Mo par fichier) et 5 coupures
  réseau simulées : mise à jour complète, intégrité vérifiée, version installée confirmée.

## [2.6.0] — 2026-10-21

### Ajouté
- **Voix Piper livrée dans le dossier POS Marwa** (`addons/marwa_voice/piper`, module vocal
  1.1).
  - Contenu : moteur Piper pour Windows et voix française « siwis », la meilleure qualité
    française disponible pour Piper.
  - Aucune installation et aucun Internet : mise en place automatique au premier usage.
  - Chaque fichier est vérifié par SHA-256 ; fragments de 1 Mo au plus.
  - Licences jointes.
- **Écran cuisine qui parle** :
  - chaque nouvelle commande est lue à voix haute (numéro, table ou à emporter / livraison,
    plats, quantités, remarques, serveur) ;
  - les ajouts sont signalés comme tels ;
  - les annulations sont lues avec leurs plats et leur motif ;
  - bouton 🔊 pour relire un bon ;
  - un bon n'est lu qu'une fois et n'est jamais écarté de la file avant 10 minutes.
- **Plan de salle** : les serveurs entendent « Table T3 : deux plats prêts à servir ».
- Quantités et unités dites naturellement : « un demi », « trente-trois centilitres »,
  « un kilo ».

### Modifié
- L'écran cuisine charge les scripts des modules, comme la caisse.
- Le flux des bons indique si un bon est un ajout à une commande déjà envoyée.
- Pause de 0,35 s entre les phrases pour mieux comprendre les listes de plats.
- `installer-voix.bat` et le service vocal utilisent la voix livrée. Le téléchargement n'est
  plus qu'un secours.

### Limites connues
- Le programme Piper Windows livré a été vérifié fichier par fichier, mais n'a pas encore été
  exécuté sur un PC Windows.
- Les commandes **vocales** du cuisinier (« Commande 24 prête ») demandent la reconnaissance
  vocale, qui n'est pas encore réalisée.

## [2.5.0] — 2026-10-21

### Ajouté
- **MARWA VOICE AI 1.0 — phase 1** (`addons/marwa_voice`) : POS Marwa parle, sans Internet.
  - Annonces des ventes : total et monnaie, chiffres du serveur.
  - Annonces des refus de vente (motif lu), des coupures réseau (« vente non confirmée »,
    jamais « échouée » à tort), des échecs d'impression (« la vente est enregistrée, mais… »),
    du stock insuffisant, des codes inconnus et des messages d'erreur des pages.
  - File d'annonces : priorités, interruption par les alertes critiques, doublons ignorés,
    regroupement, annonces périmées écartées.
  - Réglages du responsable : événements, niveaux, voix, volume, débit, plage de silence.
  - Réglages de chaque poste : voix coupée, modules à ne pas lire, nom du poste.
  - Historique contrôlé par le serveur.
  - Voix locale **Piper** (installateur `windows\installer-voix.bat`, fichiers vérifiés par
    SHA-256) avec service persistant. Sinon, voix française de Windows.
  - Aucun microphone dans cette phase.

### Modifié
- Caisse : émission d'événements pour les modules (`pm:event`), sans effet sans module.
- Caisse : le message de coupure réseau ne dit plus « la vente n'a pas été enregistrée » (son
  état n'était pas vérifié). Il indique « non confirmée » et invite à revalider, sans risque de
  doublon.
- Politique de sécurité : `media-src 'self' blob:` (sons générés localement).
- `demarrer-pos-marwa.bat` : lance le service vocal si Piper est installé ; la fenêtre POS
  Marwa autorise le son sans clic préalable.

### Limites connues
- Pas encore essayé sur un vrai PC Windows avec haut-parleurs ; les scripts `.bat` n'ont pas
  été exécutés.
- Aucune voix haoussa disponible (aucune voix Piper haoussa n'existe) : les annonces sont en
  français.

## [2.4.0] — 2026-10-19

### Ajouté
- **Reconnaissance des articles 1.2** (`addons/vision`) :
  - **fond appris** (« 🧱 Apprendre le fond », cadre vide) : seul l'article posé devant le fond
    est analysé ; murs, comptoir et objets autour ne sont plus pris pour un article ; ombres et
    variations de lumière compensées ;
  - **article détouré** (fond transparent) pendant l'apprentissage, avec aperçu en direct ;
    chaque photo donne deux empreintes (article détouré + image entière) ;
  - en caisse, **« En continu » + « Ajout automatique »** : chaque article reconnu avec
    certitude sur deux images de suite est ajouté au panier ; on retire l'article et le suivant
    est reconnu ; bouton **↶ Annuler** (retire l'unité, annulation enregistrée) ;
  - seuils propres aux articles détourés (Réglages, 0,45 / 0,25) ; écart doublé pour les
    paires souvent corrigées et les articles souvent annulés.

### Modifié
- Le scanner caméra transmet l'annulation à la caisse (`onUndo`).
- Migration du module : résultat « annulé » dans le journal, taille apparente des empreintes.

### Limites connues (mesurées)
- Un article **jamais appris** qui ressemble à un article appris peut être ajouté sous le nom
  de ce dernier : n'activez l'ajout automatique que si tous les articles présentés devant
  cette caméra ont été appris. Détails et chiffres : `addons/vision/README.md`.
- Sans fond appris, le détourage d'après les bords n'améliore pas la reconnaissance sur photos
  réelles ; il n'est pas utilisé en caisse.
- Testé en simulation et avec une caméra simulée dans Chromium ; **pas encore avec une vraie
  webcam en magasin**.

## [2.3.1] — 2026-10-19

### Corrigé
- Page de connexion : le titre affichait encore l'ancien nom « POS WAN ». Un test le vérifie
  désormais.
- Le paquet 2.3.0 contenait par erreur des photos produites par les tests automatiques
  (dossier `storage/ecole/photos`). Elles sont retirées et ce dossier n'est plus jamais livré.
  Si vous avez installé la 2.3.0 sans encore utiliser le module Établissement, vous pouvez
  vider ce dossier.
- Le ZIP 2.3.0 publié en premier ne contenait pas `release.json` (vérification d'intégrité) ;
  les paquets incluent de nouveau ce fichier.

## [2.3.0] — 2026-10-19

### Ajouté
- **Module « Établissement » 1.0** (`addons/ecole`) pour écoles, collèges / lycées et
  universités :
  - années scolaires, trimestres / semestres, classes, matières, coefficients, crédits ;
  - élèves / étudiants (photo, parent / tuteur, carte scolaire à code-barres) et personnel ;
  - inscriptions avec échéancier (frais d'inscription, scolarité en tranches, remises, autres
    frais), abandon, changement de classe ;
  - caisse de l'établissement : recherche par nom, matricule, téléphone, carte ou visage ;
    paiement idempotent ; reçu ticket 80 mm ou A4 ; inscription avec premier versement ;
  - comptabilité de la période, impayés échus, salaires du personnel, annulations tracées ;
  - notes, moyennes pondérées, rangs ex æquo, bulletins A4 par élève ou par classe, par
    période ou annuels ;
  - appel par classe et du personnel, clôture de la journée, relevé des absences ;
  - **pointage par reconnaissance faciale** (webcam, caméra USB / carte d'acquisition, caméra
    IP) : contrôle de qualité, accord sur plusieurs images, seuil et écart avec la 2ᵉ
    personne, inconnus gardés pour vérification, consentement obligatoire, effacement.
- **Mode de fonctionnement « Établissement scolaire / université »** : la caisse ouvre la caisse
  de l'établissement, l'accueil et le tableau de bord sont ceux de l'établissement, les menus
  et alertes commerciaux sont masqués (rien n'est supprimé).
- Alertes : inscriptions avec frais échus impayés, visages inconnus à vérifier.

### Changé
- Une date seule s'affiche sans « 00:00 ».
- Les mouvements de caisse des modules (clinique, pressing, établissement) ont un libellé dans
  l'état de caisse.

### Base de données
- Module ecole `0001_ecole.sql` : tables `ecole_*`, compteurs EL, PE, REC, SAL.

### Vérifié
- 125 tests automatisés réussis (dont 5 pour le module : calculs, caisse, droits, tous les
  formulaires et toutes les pages, impressions) et 15 tests JavaScript.
- Essai Chromium de bout en bout, détaillé dans `addons/ecole/README.md` :
  - inscription avec photos, caisse, reçu, notes, bulletins ;
  - pointage par caméra simulée : élève et enseignant reconnus, inconnu signalé, aucun double
    pointage.
- Corrigé pendant l'essai : un visage qui en remplaçait un autre au même endroit n'était pas
  analysé ; le suivi trop court sur un ordinateur lent.
- **Non testé** avec de vraies caméras ni avec de vrais élèves.

## [2.2.1] — 2026-10-18

### Corrigé
- **Erreur 500 sur « Surveillance des articles », « Passages vus / ventes » et « Caméras »
  après la mise à jour.**
  - **Cause :** les fichiers du module Reconnaissance 1.1 avaient été copiés, mais le module
    restait en 1.0.0 dans la base, sans ses nouvelles tables.
  - **Correction :** après une mise à jour du logiciel, les modules actifs dont la version a
    changé sont maintenant mis à jour automatiquement, avec une sauvegarde préalable, comme la
    base du logiciel.
  - **En cas d'échec :** les écrans du module sont masqués (plus d'erreur 500), la cause est
    journalisée, un nouvel essai a lieu 10 minutes plus tard, et le bouton « Mettre à jour »
    reste disponible dans Modules.

## [2.2.0] — 2026-10-18

### Ajouté
- **Module Reconnaissance par photo 1.1 : caméras.**
  - Webcam / USB et carte d'acquisition (table de mixage, HDMI) choisies sur le poste.
  - Caméras IP lues par le serveur (image fixe ou MJPEG), avec identifiants chiffrés, réseau
    local uniquement et bouton « Tester ».
- **Lecteur caméra :** choix de la caméra mémorisé sur le poste ; les caméras IP sont
  disponibles en caisse, même sans HTTPS.
- **Surveillance des articles en continu :**
  - détection du mouvement et suivi ;
  - comptage par ligne rouge (sortie / retour) ou par zone ;
  - vignette et reconnaissance de l'article à chaque passage ;
  - plusieurs caméras sur un même écran.
- **Rapport « Passages vus / ventes » :**
  - rapprochement horaire de chaque passage avec une vente du même article ;
  - passages sans vente, sorties de stock par type, couverture des caméras, répartition par
    heure ;
  - analyse rédigée automatiquement ;
  - revue des passages (confirmer, corriger et apprendre, ignorer) ;
  - exports CSV et PDF ;
  - conservation limitée des vignettes.
- **Cœur :** `PMScanner.addSource` (caméras fournies par un module).

### Vérifié
- Moteur de comptage : 15 vérifications JavaScript sur images synthétiques (sens, hésitation
  sur la ligne, aller-retour, deux objets, bruit, lumière, extrémité de la ligne, zone).
- Fausse caméra IP : mot de passe, MJPEG, redirection refusée, réponse non image.
- Chromium avec une webcam simulée et une caméra IP simulée, chacune filmant un article qui
  traverse l'image :
  - **Caméra IP :** 3 passages comptés sur 3.
  - **Webcam :** 2 passages, le premier ayant eu lieu pendant le démarrage de la caméra.
  - **Reconnaissance :** juste pour le lait (webcam), fausse pour le jus (caméra IP, resté
    « incertain »).

### Base de données
- Module vision `0002_cameras_surveillance.sql` : tables `vision_cameras` et `vision_events`.

## [2.1.0] — 2026-10-18

### Ajouté
- **Module « Reconnaissance des articles par photo »** (`addons/vision`, à activer dans
  Modules complémentaires). Il reconnaît les articles par la caméra (webcam ou smartphone du
  réseau), sans code-barres :
  - **Où :** caisse, restaurant, inventaire, étiquettes, délivrance pharmacie et recherche
    d'articles.
  - **Comment :** le réseau de neurones MobileNet v2 et TensorFlow.js sont livrés avec le
    module et fonctionnent dans le navigateur, sans Internet.
  - **Apprentissage :** quelques photos par article, ou les photos des fiches.
  - **Confirmation :** 4 propositions avec photo, prix et stock. La correction pendant une
    vente apprend la photo.
  - **Options :** mode continu, ajout automatique sous seuils réglables.
  - **Suivi :** statistiques (taux de bonnes propositions, confusions) et détection des
    articles qui se ressemblent.
- **Précision mesurée sur un banc public difficile** (31 articles très proches photographiés
  en rayon) : bon article en 1ᵉʳ dans 34 % des cas, parmi les 4 propositions dans 65 % des cas
  avec 5 photos par article, 75 % avec 15 photos. Le caissier confirme donc toujours, sauf
  activation de l'ajout automatique (désactivé par défaut). Détails : `addons/vision/README.md`.
- **Modules :** clé `assets` du manifeste, qui charge les scripts et styles d'un module actif
  sur toutes les pages (gestion, caisse, restaurant).
- **Lecteur caméra extensible** (`PMScanner.addTool`) : il sait si l'écran attend un article.
- **Prise de commande restaurant :** bouton caméra (code-barres, référence, reconnaissance).

### Base de données
- Module vision `0001_vision.sql` : tables `vision_refs` (empreintes) et `vision_log`
  (journal).

## [2.0.0] — 2026-10-16 — POS Marwa

### Changé
- **POS Wan devient POS Marwa** : nom affiché, documents et documentation. Restent inchangés
  pour la compatibilité :
  - le nom de la base (`poswan`) ;
  - les clés de licence déjà délivrées ;
  - le cookie de session ;
  - le préfixe des sauvegardes.
- Le lien « Ouvrir la caisse » et l'écran d'accueil ouvrent la caisse du mode choisi.

### Ajouté
- **Modes de fonctionnement** (Paramètres → Mode de fonctionnement de la caisse) :
  - modes : Commerce général, Restaurant, Pharmacie, Clinique, Clinique + Pharmacie, Pressing ;
  - la caisse, l'accueil et les menus s'adaptent au mode ;
  - changer de mode ne supprime aucune donnée.
- **Stock en temps réel dans la caisse** :
  - actualisation toutes les 15 s et après chaque vente ;
  - stock vendable sans lots périmés ni rappelés ;
  - test de 10 ventes simultanées pour 5 unités en stock : 5 acceptées, 5 refusées, stock final 0.
- **Accès mobile par QR code** (Configuration → Accès mobile) :
  - détection de l'adresse IP locale et alerte si elle change ;
  - consignes Apache et pare-feu ;
  - page mobile `/m` qui vérifie le serveur et la caméra ;
  - page `/health`.
- **Appareils connectés** :
  - enregistrement de chaque appareil ;
  - approbation facultative des nouveaux appareils du réseau ;
  - révocation, qui coupe la session immédiatement.
- **Scan des codes-barres par la caméra** :
  - lecteur intégré du navigateur, ou ZXing ;
  - une lecture par présentation du code, bouton « +1 », photo du code si pas de HTTPS,
    saisie manuelle ;
  - disponible dans la caisse, l'inventaire, les articles, les étiquettes, la délivrance
    pharmacie et la caisse pressing ;
  - les douchettes fonctionnent toujours.
- **Inventaire** :
  - comptage par scan (+1 par lecture) et saisie manuelle enregistrée immédiatement, combinables ;
  - observations, remise à zéro, historique des corrections, auteur du comptage ;
  - filtres (non comptés, écarts, surplus, manquants, conformes) ;
  - inventaire partiel par catégorie, un seul inventaire en cours par entrepôt ;
  - rapport imprimable et exportable (CSV, PDF).
- **Étiquettes** :
  - choix des informations : code-barres, nom, prix, référence, magasin ;
  - articles ajoutés par recherche, catégorie ou scan, avec quantité par article ;
  - format en mm : planche A4 ou rouleau ;
  - aperçu en direct ;
  - modèles enregistrés et modèle par défaut ;
  - étiquette de calibrage ;
  - EAN-13 réel pour les codes EAN.
- **Clinique 1.1.0** :
  - caisse clinique : patient trouvé ou créé, prestations, paiement total ou partiel, ticket ;
  - contrôle des médicaments par scan à la délivrance.
- **Pressing 1.1.0** :
  - caisse pressing ;
  - statuts : Déposé, En attente, En traitement, Prêt, Partiellement retiré, Retiré, Annulé ;
  - retrait partiel ;
  - jours de retard et alertes (dépôts en retard et dépôts dont la date approche) ;
  - écran « Suivi des vêtements » avec filtres et exports ;
  - historique par client.
- **Windows** :
  - `demarrer-pos-marwa.bat` démarre WampServer, attend Apache/MySQL et ouvre POS Marwa une
    seule fois ;
  - raccourci Bureau ;
  - tâche planifiée à l'ouverture de session ;
  - voir `docs/DEMARRAGE_WINDOWS.md`.
- **PWA** : icône, ajout à l'écran d'accueil, ouverture plein écran.

### Corrigé
- L'en-tête `Permissions-Policy` bloquait la caméra : elle est désormais autorisée pour POS
  Marwa uniquement (`camera=(self)`).
- Clinique et pressing : un utilisateur sans droit de vente commerciale est dirigé vers la
  caisse de son activité au lieu d'une erreur 403.

### Base de données
- `0006_marwa.sql` :
  - tables `user_devices`, `inventory_count_log` et `label_templates` (3 modèles) ;
  - colonnes de comptage dans `inventory_items` ;
  - colonne `category_id` dans `inventories`.
- Pressing `0002_suivi_retraits.sql` : statut `partial`, quantités retirées.
- Les migrations s'appliquent automatiquement ; les modules se mettent à jour par
  Configuration → Modules → Mettre à jour.

## [1.6.1] — 2026-10-12

### Corrigé
- **Activation de la licence en « Erreur 500 » sous WampServer** : la vérification de la
  signature utilisait l'extension PHP `sodium`, qui n'est pas activée par défaut sous Windows.
  POS Marwa utilise maintenant l'extension si elle est présente, sinon la bibliothèque PHP pure
  `sodium_compat` (paragonie, licence ISC) intégrée dans `app/Lib/`. Même correction pour
  `bin/licence.php`.
- Une erreur technique pendant l'activation affiche sa cause au lieu d'une page d'erreur 500.
- La page Licence indique le mode de vérification utilisé (extension ou bibliothèque intégrée).

## [1.6.0] — 2026-10-12

### Corrigé
- **« Action inconnue » en cliquant sur Installer un module** : après la fenêtre de
  confirmation, le formulaire était renvoyé sans le bouton cliqué. Corrigé pour toutes les
  confirmations (le bouton est transmis), et les boutons ne restent plus grisés après
  « Annuler ». Vérifié dans Chromium (clic → confirmation → module installé).

### Ajouté
- **Mise à jour du logiciel depuis le navigateur** (Configuration → Mise à jour du logiciel) :
  sélection du dossier complet de la nouvelle version, envoi par lots (limites PHP de Wamp
  respectées), vérification (version, fichiers essentiels, empreintes `release.json`),
  sauvegarde de la base et du code, copie, mise à jour automatique de la base, page de
  maintenance pendant la copie, retour au code précédent. Mot de passe exigé.
- **À propos** (lien en bas du menu) : auteur Mahamadou Hamidou (Mohamed), ingénieur
  informaticien nigérien, contacts, version, licence. Mention de l'auteur à la connexion.
- **Contrat de licence d'utilisation** (10 articles, `LICENCE.md`), accepté à l'installation.
- **Licence et activation** : clé signée Ed25519 liée à l'installation, essai 30 jours puis
  lecture seule, bandeaux d'information ; outils de l'auteur `bin/licence.php` (création des
  clés et des licences) et `bin/release.php` (préparation d'une version).
- Permissions `system.update` et `licence.manage` (administrateur général).

## [1.5.1] — 2026-10-12

### Corrigé
- **Installation des modules en erreur (« Erreur 500 ») après une mise à jour par copie des
  fichiers** : la migration 1.4 (table `addons`) n'était appliquée que par
  `php bin/migrate.php`. La base est désormais **mise à jour automatiquement** au premier
  affichage (sauvegarde de sécurité avant, verrou contre les exécutions simultanées), et
  l'installation d'un module applique d'abord les migrations en attente.
- Une erreur technique pendant l'installation d'un module affiche maintenant sa cause à
  l'administrateur (et la journalise) au lieu d'une page d'erreur 500.
- Modules décompressés par Windows dans un sous-dossier (`addons/module-x-1.0.0/x/`) ou
  renommés (`addons/x (1)/`) : rangés automatiquement en `addons/x/`.

## [1.5.0] — 2026-10-11

### Ajouté — module « Clinique » (`addons/clinique/`)
- Dossiers patients (n° PAT, allergies, antécédents, groupe sanguin, assurance et taux de
  prise en charge), rendez-vous par médecin (chevauchements refusés), file d'attente avec
  urgence et constantes de triage.
- Consultations : observation, examen, diagnostic obligatoire pour clôturer, constantes
  contrôlées (valeurs plausibles), historique.
- **Lien avec la pharmacie** : ordonnance rédigée par le médecin (médicaments du stock ou
  hors stock), imprimée, transmise à la file « Ordonnances → pharmacie » ; la délivrance
  (partielle possible) crée une vente normale (stock, lots FEFO, caisse) et remplit le
  registre des ordonnances.
- Hospitalisation et mise en observation : lits, plan de soins (fréquence, durée),
  transmissions, courbe des constantes, transfert de lit, sortie avec facture automatique.
- **Écran de supervision des malades** (plein écran, actualisé toutes les 20 s) : alertes
  sur les constantes hors seuils (signal sonore), soins en retard / dus, bouton « Fait »,
  constantes à reprendre, lits libres, filtre par service. Seuils réglables.
- Facturation des actes avec tiers payant, encaissement en caisse, rapport CSV / PDF.
- 7 permissions ; 3 rôles créés (médecin, infirmier, accueil). Accès au dossier médical
  réservé aux soignants et journalisé.

### Modifié
- Manifeste des modules : clé `new_roles` (création de rôles à l'installation).

## [1.4.1] — 2026-10-11

### Ajouté — module « Pressing » (`addons/pressing/`)
- Tarifs par article et par service (lavage + repassage, nettoyage à sec, repassage,
  teinture), 18 articles et prix indicatifs en FCFA, modifiables.
- Dépôt tactile : client (rappel des clients connus), articles, couleur / marque / défauts
  constatés, express (supplément et délai), remise contrôlée, acompte en caisse.
- Ticket client avec QR code, étiquettes par pièce (n° à reporter sur les vêtements).
- Suivi : reçu → en cours → prêt → retiré ; retards, linge non retiré, message WhatsApp.
- Retrait : paiement du solde avec monnaie ; remise sans paiement complet réservée au
  responsable (motif) ; annulation avec remboursement en caisse.
- Rapport par période (dépôts, pièces, facturé, encaissé, reste, services, articles,
  modes de paiement) et export CSV / PDF. 4 permissions dédiées.

## [1.4.0] — 2026-10-11

### Ajouté
- **Modules complémentaires (addons)** : déposer le dossier d'un module dans `addons/`,
  puis **Gestion → Modules complémentaires → Installer** (sauvegarde automatique,
  tables, permissions, menu, paramètres, traductions, fichiers publics). Activation /
  désactivation sans perte de données, mise à jour quand la version augmente, commande
  `php bin/addons.php`. Permission `addons.manage` (administrateur général).
- Module d'exemple **Livraisons** (`addons/livraisons/`) : suivi des livraisons à domicile,
  modèle à copier pour créer ses propres modules. Guide développeur : `docs/MODULES.md`.
- **Menu de gestion à droite** (Paramètres → Position du menu : droite par défaut, gauche
  en option ; sur téléphone le menu s'ouvre depuis la droite).
- **Assistance** affichée sur l'écran de connexion : +227 92 08 12 82 / 87 22 52 02
  (numéros cliquables, modifiables dans les paramètres).

### Sécurité
- Le dossier `addons/` n'est jamais servi par Apache (`.htaccess`) ; codes de module
  contrôlés (pas de chemin arbitraire) ; aucun envoi de module par le navigateur.

## [1.3.0] — 2026-10-10

### Ajouté
- **TVA** (Paramètres → TVA) : pas de TVA, **TVA générale** au taux choisi (19 %, 18 %… ou
  libre) avec case « Exonéré » par article, ou taux article par article. Prix saisis TTC ;
  TVA détaillée par taux sur les tickets, factures, devis et tickets ESC/POS.
- **Rapport TVA** (Rapports → TVA) : ventes TTC, retours, base HT et TVA collectée nettes,
  par taux et par mois, avec export CSV et PDF.
- **Export PDF** de tous les rapports et listes exportables (ventes, articles, bénéfices,
  stock, mouvements, péremptions, créances, dépenses, ordonnancier, TVA) : PDF généré par
  POS Marwa lui-même, sans Internet ni logiciel supplémentaire (en-tête, totaux, pagination).

### Corrigé — restaurant / écran cuisine
- Les articles d'une catégorie **sans poste de préparation** étaient marqués « servis » et
  n'apparaissaient jamais sur l'écran cuisine : ils vont désormais au **poste par défaut**
  (« cuisine », réglable). Saisir `aucun` comme poste d'une catégorie pour servir sans ticket
  (boissons en bouteille…).
- Une commande **encaissée sans avoir été envoyée** (vente à emporter…) n'arrivait pas en
  cuisine : les articles non envoyés partent maintenant en cuisine à l'encaissement
  (paramètre, activé par défaut) ; un avertissement s'affiche sur l'écran d'encaissement.
- Le bouton **Bon cuisine** réimprime les derniers bons de la commande même s'ils sont déjà
  prêts ou servis (auparavant : message d'erreur).
- Tests automatisés : le serveur PHP de test est arrêté proprement en fin de suite.

### Migration
- `0004_tva_cuisine` (colonne `products.vat_exempt`, paramètres TVA et restaurant). Les
  installations existantes gardent le mode « taux par article » (comportement inchangé).

## [1.2.0] — 2026-10-10

### Ajouté
- **Imprimantes multiples** (Paramètres → Imprimantes) : imprimantes réseau (IP, port 9100) ou
  USB partagées sous Windows, pilotées en ESC/POS par le serveur ; chaque imprimante a ses
  usages (tickets de caisse, bons cuisine / bar / grill, additions) et peut être limitée à une
  caisse (ex. alimentation → imprimante A, restaurant → imprimante B). Page de test, coupe
  papier, ouverture du tiroir-caisse, copies. Repli automatique sur l'impression navigateur.
  Une panne d'imprimante n'annule jamais une vente.
- **Langues** : français, anglais, arabe (affichage de droite à gauche), haoussa, espagnol,
  portugais, turc. Langue par défaut dans les paramètres, choix par utilisateur (Mon compte)
  et sur l'écran de connexion.
- **Devise configurable** (FCFA, naira, cedi, dirham, euro, dollar… ou personnalisée) :
  symbole, position, décimales, nom en toutes lettres sur les factures.
- **Modèles d'impression** : 3 modèles de ticket (Classique, Compact, Moderne) et 3 modèles
  de facture / devis A4 (Classique, Moderne à bandeau, Épuré), avec aperçu.
- **QR code** en bas des tickets, factures, devis et additions (référence et identifiant du
  document, signature HMAC) et page **Vérifier un QR code** qui détecte les documents falsifiés.
- **Devis** : création, suivi (envoyé, accepté, refusé, annulé), impression, duplication,
  **conversion en vente** (prix du devis conservés, stock et caisse contrôlés).
- **Bons de livraison** A4 pour toute vente, avec ou sans les prix.
- **Caisse** : le bouton **Valider** encaisse directement en espèces sans fenêtre (montant
  « Argent client » ou montant exact, monnaie affichée dans la barre) ; le bouton **Espèces**
  ouvre la fenêtre d'encaissement complète (paiement mixte, carte, mobile money). Option
  désactivable dans les paramètres.
- **Mobile money** : liste des opérateurs paramétrable (Airtel Money, Moov Money, Zamani Cash,
  Nita, Amanata…), choix de l'opérateur à l'encaissement, opérateur enregistré sur le paiement.
- **Codes-barres** : bouton « Générer » dans la fiche article, « Créer un code-barres » sur un
  article qui n'en a pas, génération groupée pour tous les articles sans code (+ étiquettes).
- **Restaurant** : articles et catégories réservés au restaurant (ou à la boutique) ;
  commandes affichées sur l'écran cuisine dès l'envoi ; bouton « Bon cuisine » et bouton
  d'impression sur chaque ticket de l'écran cuisine (impression à la demande).
- **Achats** : à la réception, choix du coût par ligne — **prix moyen pondéré** (défaut),
  **nouveau prix** (tout le stock revalorisé) ou **ancien prix** — et nouveau prix de vente
  facultatif (historisé).
- Migration `0003_imprimantes_devis_langues` ; permission `quotes.manage` (accordée aux
  rôles Responsable de magasin et Caissier).

### Modifié
- L'impression automatique des bons cuisine par le navigateur est désactivée par défaut
  (écran cuisine ou imprimante de poste recommandés).

## [1.1.0] — 2026-10-10

### Ajouté — module Restaurant
- Nouveau type d'activité « Restaurant / maquis / café / fast-food » et fonctionnalités
  `restaurant` (plan de salle, commandes, cuisine) et `recipes` (fiches techniques).
- Plan de salle par salle (tables libres / occupées / addition demandée / plats prêts),
  rafraîchi automatiquement ; commandes à emporter et en livraison.
- Prise de commande tactile (même ergonomie que la caisse) : couverts, services
  (boisson, entrée, plat, dessert), notes pour la cuisine, quantités rapides.
- Envoi en cuisine : un bon par poste de préparation (cuisine, grill, bar…) défini sur
  les catégories, impression automatique des bons, articles sans poste servis directement.
- Écran cuisine / bar (KDS) : tickets en temps réel, chronomètre avec alertes de retard,
  étapes « Commencer → Prêt → Servi », rappel d'un ticket, signal sonore.
- Annulation d'un plat envoyé : permission ou autorisation d'un responsable, motif,
  bon d'annulation en cuisine, déclaration de perte (déstockage des ingrédients).
- Transfert de table, fusion de tables, addition (pré-note), paiement total ou
  **addition séparée** (par article ou en parts égales), remise, crédit client.
- Fiches techniques : les ventes déstockent les ingrédients (articles « non vendables »),
  coût matière réel, réintégration en cas d'annulation ou de retour.
- Rapport restaurant : CA, couverts, ticket moyen par couvert, serveurs, plats,
  rotation des tables, temps de préparation, annulations.
- Rôles « Serveur » et « Cuisinier / barman » ; 5 nouvelles permissions.
- Migration `0002_restaurant` : les installations existantes sont mises à jour par
  `php bin/migrate.php` (les nouvelles permissions sont accordées aux rôles système concernés).

### Corrigé
- Retour automatique à l'écran d'origine après l'impression d'un ticket (`next` contrôlé).

## [1.0.0] — 2026-10-10

### Ajouté
- Noyau applicatif PHP sans dépendance : routeur avec permissions, PDO préparé, sessions
  durcies, CSRF, CSP, validation, téléversement sécurisé, journalisation, audit.
- Assistant d'installation web et CLI (`bin/install.php`), migrations (`bin/migrate.php`).
- Caisse plein écran (tactile, clavier, douchette) inspirée de l'ancien logiciel : tuiles de
  catégories/articles, bande de quantité 2–9, panneau ticket sombre « Total / Valider »,
  barre code-barres / argent client / à rendre, paiements mixtes, crédit, remises avec
  autorisation, attente/reprise, impression ticket/facture.
- Écran de connexion avec pavé numérique PIN, identifiant/mot de passe et badge RFID (optionnel).
- « Menu de gestion » à icônes rondes regroupant tous les modules.
- Modules : articles, catégories/marques/unités, stocks, lots/FEFO, transferts, inventaires,
  fournisseurs, commandes, réceptions, retours fournisseurs, clients/crédits, sessions de
  caisse, dépenses/recettes, rapports, tableau de bord, utilisateurs/rôles, paramètres,
  boutiques/entrepôts/caisses, journal d'audit, sauvegardes/restauration.
- Types de commerce : alimentation, boutique, pharmacie, quincaillerie, dépôt de boissons,
  cosmétique, informatique, papeterie, pièces détachées.
- Suite de tests automatisés (57 tests) et documentation complète.
