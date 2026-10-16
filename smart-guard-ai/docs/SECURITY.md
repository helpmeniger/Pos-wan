# Sécurité et vie privée

## Mesures en place

| Domaine | Mesure | Où |
|---|---|---|
| Mots de passe | Argon2id (repli bcrypt), politique 10 caractères lettres+chiffres, ré-hachage automatique | `Core/Auth.php` |
| Connexion | 5 échecs / identifiant ou 15 / IP en 15 min → blocage ; vérification à temps constant ; journalisation | `Auth::attempt`, `login_attempts`, `audit_logs` |
| Sessions | cookie HttpOnly + SameSite=Strict (+ Secure en HTTPS), mode strict, régénération à la connexion, expiration par inactivité | `Core/Session.php` |
| Autorisations | RBAC (rôles × permissions configurables), contrôle **côté serveur** sur chaque route, données filtrées par magasin, droits relus à chaque requête (compte désactivé = effet immédiat) | `Router`, `config/routes.php` |
| Injections SQL | requêtes préparées partout, noms de colonnes validés | `Core/Database.php` |
| XSS | échappement systématique `e()`, en-têtes CSP, `X-Content-Type-Options`, `X-Frame-Options` | vues, `public/index.php` |
| CSRF | jeton par session sur tout POST (formulaires et AJAX) | `Core/Csrf.php` |
| Téléversements | taille, type réel (finfo + getimagesize), dimensions, **ré-encodage JPEG** (supprime EXIF/GPS et contenu caché), nom aléatoire, stockage hors web, dédoublonnage SHA-256 | `ImageService` |
| CSV | injection de formules neutralisée à l'export | `Export::safeCell` |
| Secrets | `.env` hors du web ; clés générées aléatoirement ; mots de passe des caméras chiffrés (libsodium secretbox, `APP_KEY`) et jamais réaffichés | `Crypto`, `generate_keys.php` |
| Fichiers | `.htaccess` refuse `app/`, `config/`, `storage/`, `ai-service/`, `scripts/`, `.env`, `*.sql`… ; médias servis par PHP après contrôle des droits et du magasin, avec vérification `realpath` | `.htaccess`, `MediaController` |
| Service IA | écoute sur 127.0.0.1, clé `X-API-Key` (comparaison à temps constant), pas de documentation OpenAPI exposée, limite de taille des requêtes, délais, validation Pydantic, chemins confinés à `storage/`, nombre de caméras limité | `ai-service/app/main.py`, `security.py` |
| Flux vidéo (anti-SSRF) | URL construite côté serveur ; schémas rtsp/http(s) seulement ; **adresse IP littérale** obligatoire dans `CAMERA_ALLOWED_NETWORKS` ; boucle locale, multicast, link-local et ports de services internes refusés — contrôle dans PHP **et** dans le service IA | `NetworkGuard`, `validate_stream_url` |
| Événements IA → PHP | jeton `X-AI-Token` + liste d'IP autorisées ; validation stricte (uuid, horodatage, chemins de médias) ; idempotence | `Router::checkAiCaller`, `IngestService` |
| API ventes | jeton Bearer (≥ 24 caractères), désactivée si absent ; anti-doublon par référence externe | `ApiController` |
| Commandes système | **aucune** commande système exécutable depuis l'interface ; sauvegarde en PHP pur ; restauration en ligne de commande seulement | `BackupService` |
| Base de données | utilisateur MySQL dédié aux droits limités à la base | `install_db.php --create-user` |
| Traçabilité | journal des actions sensibles (connexion, droits, caméras, IA, alertes, stocks, exports, sauvegardes) | `audit_logs` |

Tests correspondants : `tests/e2e_test.py` (accès anonymes, CSRF, rôles, fichiers non image,
chemins malveillants, idempotence, mot de passe de caméra non affiché) et
`ai-service/tests/test_api.py` (clé d'API, SSRF, traversée de répertoires).

## Recommandations d'exploitation

* Changer le mot de passe `root` de MySQL de WampServer ; ne pas ouvrir le port 3306.
* Ne jamais exposer WampServer, MySQL ou le service IA sur Internet ; accès distant par VPN ou
  reverse-proxy HTTPS.
* Activer HTTPS sur le réseau local si possible, puis `SESSION_SECURE=true`.
* Comptes nominatifs (pas de compte partagé), désactiver les comptes des employés partis.
* Mettre à jour Windows, WampServer, Python et les dépendances (`pip list --outdated`,
  `pip-audit` facultatif).
* Copier les sauvegardes et `.env` hors de l'ordinateur ; protéger l'accès physique au PC.
* Utiliser un compte « lecteur » sans droits d'administration sur les caméras IP et changer
  leurs mots de passe par défaut.

## Vie privée et cadre légal

* Pas de reconnaissance faciale, pas d'identification ni de suivi de personnes : le détecteur
  YOLO ignore la classe « personne » ; le détecteur de mouvement ne qualifie pas les objets.
* Informer les personnes filmées (panneau à l'entrée ; texte dans Paramètres).
* Conserver les images le temps nécessaire (paramètres de rétention ; images des alertes
  ouvertes protégées puis purgées après clôture) ; limiter l'accès aux enregistrements
  (permission `video.view`).
* Vérifier les obligations applicables au Niger (protection des données personnelles,
  vidéosurveillance, droit du travail pour les caméras visant des postes de travail) et, le cas
  échéant, les déclarations auprès de l'autorité compétente.
