# Installation sous Windows avec WampServer

Durée : environ 30 minutes. Une connexion Internet est nécessaire **uniquement pendant
l'installation** (WampServer, Python, dépendances). Ensuite, tout fonctionne hors ligne.

## 0. Matériel

| | Prototype | Recommandé |
|---|---|---|
| Système | Windows 10/11 64 bits | Windows 10/11 64 bits |
| Processeur | 4 cœurs | 6–8 cœurs récents |
| Mémoire | 8–12 Go | 16–32 Go |
| Disque | SSD, 20 Go libres | SSD + disque secondaire pour les médias |
| Caméras | 1 webcam USB | Webcam(s) USB et/ou caméras IP RTSP |

Le nombre de caméras analysées simultanément dépend de la machine : commencez avec une caméra à
3–5 images/s analysées et surveillez le temps de traitement (tableau de bord).

## 1. Installer et configurer WampServer

1. Installer les **redistribuables Visual C++** (lien sur wampserver.com), puis **WampServer 3.3+ 64 bits**.
2. Démarrer WampServer (icône **verte**).
3. Clic gauche sur l'icône > **PHP > Version** : choisir **8.3** (8.1 minimum).

## 2. Vérifier PHP et activer les extensions

Icône WampServer > **PHP > Extensions PHP** : cocher `pdo_mysql`, `gd`, `sodium`, `curl`,
`fileinfo`, `mbstring`, `zip`, `exif` (WampServer redémarre). Apache doit avoir `rewrite_module`
actif (**Apache > Modules**, activé par défaut).

## 3. Copier le projet

Copier le dossier `smart-guard-ai` dans `C:\wamp64\www\`. Le fichier `.htaccess` à la racine
redirige tout vers `public\` et bloque `app\`, `config\`, `storage\`, `ai-service\`, `.env`…

> Option plus sûre : créer un **VirtualHost** dont la racine est `C:\wamp64\www\smart-guard-ai\public`
> (WampServer > « Add a Virtual Host ») et mettre `APP_BASE_PATH=/` dans `.env`.

## 4 à 7. Base de données, Python et dépendances : `scripts\install.bat`

Installer d'abord **Python 3.11 ou 3.12 64 bits** depuis python.org en cochant **« Add python.exe
to PATH »** et **« py launcher »**. Puis double-cliquer sur `scripts\install.bat`, qui :

0. prépare le service IA (environnement `ai-service\.venv` + dépendances) **dès le début**,
   indépendamment de la base de données — voir aussi `ai-service\setup_ai.bat` ci-dessous ;

1. vérifie PHP et les extensions ;
2. crée `.env` et `ai-service\.env` et génère des **secrets aléatoires** (clé de chiffrement,
   clé PHP→IA, jeton IA→PHP, jeton API ventes) ;
3. crée la base `smart_guard_ai` et un **utilisateur MySQL dédié** `smartguard` (droits limités à
   cette base) avec le compte administrateur MySQL de WampServer (`root`, mot de passe vide par
   défaut — **changez-le**) ;
4. importe `database\schema.sql` et les données de référence, applique les migrations ;
5. crée le **compte administrateur** de l'application ;
6. crée l'environnement virtuel `ai-service\.venv` et installe `requirements.txt`
   (et, si vous l'acceptez, `onnxruntime`) ;
7. lance le diagnostic.

Installation manuelle équivalente :

```bat
cd C:\wamp64\www\smart-guard-ai
set PHP=C:\wamp64\bin\php\php8.3.14\php.exe
%PHP% scripts\generate_keys.php --db-pass=MotDePasseMySQL-Solide --base-path=/smart-guard-ai
%PHP% scripts\install_db.php --root-user=root --root-pass= --create-user
%PHP% scripts\create_admin.php
py -3.12 -m venv ai-service\.venv
ai-service\.venv\Scripts\python -m pip install -r ai-service\requirements.txt
```

### Préparer uniquement le service IA : `ai-service\setup_ai.bat`

Double-cliquer sur `ai-service\setup_ai.bat` (connexion Internet nécessaire la première fois) :
il trouve un Python 3.10+ 64 bits réellement utilisable (en ignorant le faux `python.exe` du
Microsoft Store), crée `ai-service\.venv`, installe les dépendances, crée `ai-service\.env` et
synchronise ses secrets avec le `.env` de l'application. Il peut être relancé sans risque.
`ai-service\run_ai.bat` (et donc `scripts\start.bat`) le lance **automatiquement** si
l'environnement est absent ou incomplet.

## 8. Installer les modèles d'IA (facultatif mais recommandé)

* **Par défaut**, aucun modèle n'est requis : détecteur de mouvement OpenCV + empreintes classiques.
* **Extracteur ONNX (recommandé)** : sur un PC connecté à Internet,
  `pip install torch torchvision onnx` puis `python ai-service\scripts\export_embedder.py ai-service\models\embedder.onnx`.
  Copier `embedder.onnx` dans `ai-service\models\`, installer `onnxruntime`, redémarrer le
  service IA, puis **relancer la préparation** (Apprentissage IA) : les galeries dépendent de
  l'extracteur.
* **YOLO (facultatif, AGPL-3.0)** : `pip install ultralytics`, copier `yolov8n.pt` dans
  `ai-service\models\`, mettre `DETECTOR=yolo` dans `ai-service\.env`. Vérifiez la licence.

## 9. Configurer les caméras

Voir [CAMERAS.md](CAMERAS.md). La webcam doit être branchée sur **l'ordinateur qui exécute le
service IA**. Les caméras IP doivent être dans `CAMERA_ALLOWED_NETWORKS` (fichiers `.env` et
`ai-service\.env`).

## 10–12. Démarrer

1. Démarrer **WampServer** (Apache + MySQL) — ce n'est **pas** le service IA.
2. Lancer `scripts\start.bat` : démarre le service IA dans une fenêtre réduite « SMART GUARD AI -
   Service IA » (redémarrage automatique en cas d'arrêt) et ouvre le navigateur.
3. `scripts\stop.bat` arrête le service IA (pas WampServer).

## 13. Se connecter

`http://localhost/smart-guard-ai/` avec le compte créé à l'étape 5.
(Réinitialisation : `php scripts\create_admin.php --username=admin`.)

## 14–17. Premiers tests

14. **Caméras > Ajouter** une webcam USB (bouton « Détecter »), puis **Tester la connexion**.
15. **Produits > Nouveau produit**, ajouter au moins 8 photos variées, puis
    **« Enregistrer l'article et préparer sa reconnaissance IA »** ; consulter les résultats de
    validation et **activer** la version. Tester une photo dans **Apprentissage IA**.
16. Sur la fiche caméra : **Démarrer l'analyse**, cliquer deux points pour tracer la **ligne
    virtuelle**, vérifier la flèche « SORTIE », enregistrer.
17. **Rapports > Rapports journaliers > Générer** le premier rapport.

Test sans caméra ni vrais produits (jeu synthétique, données fictives) :

```bat
ai-service\.venv\Scripts\python ai-service\scripts\make_synthetic_dataset.py C:\temp\synth
%PHP% scripts\seed_demo.php --synthetic=C:\temp\synth
```
puis Apprentissage IA > Lancer la préparation > Activer, et « Tester le comptage sur une courte
vidéo » avec `C:\temp\synth\scene.mp4` (attendu : 3 sorties, 1 entrée).

## Tâches planifiées

`scripts\register_tasks.bat` (en administrateur) crée : maintenance toutes les heures
(rétention, quota disque, santé du service IA, rapport de la veille), sauvegarde quotidienne à
23 h 30, démarrage du service IA à l'ouverture de session.

## Accès depuis le réseau local (smartphones, tablettes)

1. WampServer > clic gauche > **Apache > httpd-vhosts.conf** : dans le VirtualHost `localhost`
   (ou le vôtre), remplacer `Require local` par `Require ip 192.168.1` (votre sous-réseau).
2. Pare-feu Windows : autoriser Apache (port 80) sur le **réseau privé** uniquement.
3. Ouvrir `http://<IP-du-PC>/smart-guard-ai/` depuis le téléphone.
4. La caméra du **navigateur** (photos par webcam) exige HTTPS hors `localhost` ; sur smartphone,
   utilisez le bouton « Prendre une photo » (appareil photo du téléphone), qui fonctionne en HTTP.
5. Pour HTTPS sur le réseau local : certificat auto-signé ou d'une autorité interne
   (Apache `mod_ssl`), puis `SESSION_SECURE=true` dans `.env`.

**Ne jamais exposer WampServer, MySQL ou le service IA directement sur Internet.** Pour un accès
distant : VPN (WireGuard, OpenVPN) ou reverse-proxy HTTPS avec authentification, filtrage et mises
à jour régulières. Le service IA reste sur `127.0.0.1`.

## Mise à jour

1. Sauvegarde (`scripts\backup.bat`).
2. Remplacer les fichiers du projet **en conservant** `.env`, `ai-service\.env`, `storage\`,
   `ai-service\models\` et `ai-service\.venv\`.
3. `scripts\update.bat` : sauvegarde, migrations SQL, dépendances Python, redémarrage du service IA.

## Sauvegarde et restauration

* Manuelle : **Maintenance > Sauvegarder maintenant** ou `scripts\backup.bat`
  → `storage\backups\sgai-db-AAAAMMJJ-HHMMSS-xxxx.sql.gz` (30 dernières conservées).
* À copier régulièrement **hors de l'ordinateur** avec le fichier `.env` (sans `APP_KEY`, les
  mots de passe de caméras chiffrés sont irrécupérables) et `ai-service\models\galleries\`.
* Restauration (ligne de commande uniquement) : `scripts\restore.bat sgai-db-....sql.gz` —
  une sauvegarde de sécurité de l'état actuel est faite automatiquement avant.
* Photos des produits : copier `storage\product_images\`.

## Dépannage

| Symptôme | Cause probable / solution |
|---|---|
| Tableau de bord vide (« … »), page Utilisateurs en erreur, avec **MySQL 8/9** | corrigé dans cette version (requêtes compatibles avec le mode SQL strict `ONLY_FULL_GROUP_BY`) ; remplacez les fichiers de l'application |
| Page sans mise en forme (texte brut, sans couleurs) | ouvrir `http://localhost/smart-guard-ai/` (avec la barre finale) ; vider le cache du navigateur (Ctrl+F5) ; vérifier que `rewrite_module` est actif dans Apache ; le préfixe d'URL est maintenant détecté automatiquement même si `APP_BASE_PATH` est incorrect |
| « Extension sodium » signalée | facultative : OpenSSL (AES-256-GCM) est utilisé à la place. Pour l'activer quand même : `C:\wamp64\bin\php\php8.3.x\php.ini` (ligne de commande) **et** WampServer > PHP > Extensions > `sodium` (Apache) |
| Page blanche ou erreur 500 | `storage\logs\php-error.log`, `scripts\check_system.bat` |
| « Service IA injoignable » | lancer `scripts\start.bat` ; voir `ai-service\runtime\ai-service.log` |
| « Environnement Python absent » / « Aucun Python 3.10+ 64 bits utilisable » | lancer `ai-service\setup_ai.bat` ; installer Python 3.12 64 bits (python.org) avec « Add to PATH » et « py launcher » ; si `python` ouvre le Microsoft Store : Paramètres > Applications > Paramètres avancés des applications > **Alias d'exécution d'application** → désactiver `python.exe` et `python3.exe` |
| « Les modules ne se chargent pas » (cv2, numpy) | installer le **Microsoft Visual C++ Redistributable x64**, puis relancer `ai-service\setup_ai.bat` |
| Échec d'installation des dépendances avec Python 3.14 | installer Python 3.12, supprimer `ai-service\.venv`, relancer `ai-service\setup_ai.bat` |
| Aucun événement reçu | `PHP_CALLBACK_URL` de `ai-service\.env` doit pointer vers `http://127.0.0.1/smart-guard-ai/api/ai/events` ; jetons identiques dans les deux `.env` |
| Webcam introuvable | branchée sur un autre PC ? utilisée par une autre application (Teams, Zoom) ? essayer un autre index |
| Caméra IP refusée | IP hors `CAMERA_ALLOWED_NETWORKS` ; utiliser une adresse IP (pas de nom d'hôte) |
| Envoi de vidéo refusé | taille > `upload_max_filesize` : la racine `.htaccess` règle 256 Mo ; sinon modifier `php.ini` |
| « Trop de tentatives » | attendre 15 minutes (`LOGIN_LOCK_MINUTES`) |
| Analyse lente | réduire « images/s analysées », la résolution, ou le nombre de caméras |
