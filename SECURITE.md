# Sécurité de POS Wan

Ce document décrit les protections réellement implémentées, la façon dont elles ont été
vérifiées, et les **risques résiduels**. Aucun logiciel n'est inviolable : la sécurité
dépend aussi de la configuration du poste, du réseau et des pratiques de l'équipe.

---

## 1. Mesures implémentées

### Authentification et sessions
| Mesure | Où |
|---|---|
| Mots de passe hachés avec `password_hash()` (bcrypt par défaut de PHP), vérifiés par `password_verify()` | `app/Core/Auth.php` |
| Politique : 10 caractères min., lettres + chiffres, sans l'identifiant, liste de mots de passe courants refusés | `Auth::passwordPolicyError()` |
| Aucun compte par défaut ni porte dérobée : l'administrateur est créé à l'installation avec son propre mot de passe | `InstallService` |
| Mot de passe défini par un administrateur ⇒ changement obligatoire à la première connexion | `must_change_password` |
| Verrouillage du compte 15 min après 5 échecs (mot de passe, PIN ou badge) | `Auth::finishAttempt()` |
| Limitation par adresse IP : 20 échecs / 15 min ⇒ blocage temporaire | `Auth::ipBlocked()` |
| Délai aléatoire après un échec ; message d'erreur générique (« Identifiants incorrects ») | `AuthController` |
| Vérification à temps constant même si l'identifiant n'existe pas | `Auth::attemptPassword()` |
| Régénération de l'identifiant de session à la connexion | `Auth::login()` |
| Expiration après 30 min d'inactivité et 12 h maximum (réglables dans `.env`) | `Auth::user()` |
| Session invalidée si le compte est désactivé ou si le mot de passe change | signature `pwd_sig` |
| Cookie de session `HttpOnly`, `SameSite=Strict`, `Secure` si HTTPS (`SESSION_SECURE=true`), mode strict, identifiants de 48 caractères | `app/Core/Session.php` |
| Code PIN haché (4–8 chiffres, suites simples refusées) ; badge RFID stocké en empreinte HMAC-SHA256 | `AuthController`, `Auth::badgeHash()` |
| Ré-authentification d'un superviseur (mot de passe ou PIN + permission requise) pour les opérations sensibles en caisse | `Auth::verifySupervisor()` |
| Re-saisie du mot de passe pour une restauration de sauvegarde | `BackupController::restore()` |

### Autorisations
- Rôles et **permissions granulaires** (51 permissions) vérifiés **côté serveur** sur chaque route
  (`routes/web.php` → `Router::dispatch()` → `Auth::authorize()`), et à nouveau dans les services
  pour les décisions métier (remise, prix, crédit, ordonnance).
- Cloisonnement des données : un vendeur sans `sales.view_all` ne voit que ses propres ventes
  (protection contre l'accès par identifiant / IDOR).
- Seul un administrateur peut créer, modifier ou désactiver un compte administrateur ;
  le dernier administrateur actif ne peut pas être désactivé ou rétrogradé.

### Protection des données et des requêtes
| Mesure | Où |
|---|---|
| Requêtes **préparées PDO** partout (`ATTR_EMULATE_PREPARES=false`), noms de colonnes contrôlés par liste blanche | `app/Core/DB.php` |
| Mode SQL strict | `DB::connection()` |
| Jeton **CSRF** sur toute requête POST (formulaires et API JSON via en-tête `X-CSRF-Token`) | `Router`, `Csrf` |
| Échappement systématique de l'affichage (`e()` = `htmlspecialchars` UTF-8) | toutes les vues |
| **Content-Security-Policy** stricte : aucun script en ligne ni externe (`script-src 'self'`) | `public/index.php` |
| En-têtes `X-Content-Type-Options`, `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy`, `Permissions-Policy`, suppression de `X-Powered-By` | `public/index.php` |
| Validation serveur de toutes les saisies (types, bornes, formats, listes de valeurs) | `app/Core/Validator.php`, services |
| Les prix, remises et taxes sont **recalculés par le serveur** : le navigateur ne fait qu'afficher | `SaleService::create()` |
| Neutralisation de l'injection de formules dans les exports CSV (`=`, `+`, `-`, `@`) | `Csv::safeCell()` |
| Redirections limitées au même hôte (pas de redirection ouverte) | `back()` |

### Fichiers envoyés
- Contrôle de la taille, de l'extension **et du type réel** (`finfo` + `getimagesize`).
- Ré-encodage des images avec GD (supprime tout contenu caché / fichier polyglotte),
  redimensionnement à 800 px, **nom aléatoire** (128 bits).
- Dossier `public/uploads` : exécution PHP désactivée (`php_flag engine off`, `RemoveHandler`),
  scripts et SVG interdits, listing désactivé.
- Imports CSV : 5 Mo / 5 000 lignes max., transaction complète (tout ou rien), simulation possible.

### Configuration et serveur
- Seul `public/` est destiné à être servi. Les dossiers `app`, `config`, `database`, `storage`,
  `routes`, `tests`, `bin`, `docs` contiennent un `.htaccess` « Require all denied » et sont
  bloqués par le `.htaccess` racine.
- Aucun secret dans le code : identifiants de base et clé applicative dans `.env` (hors `public/`,
  exclu de Git, droits 0640 à la création).
- Affichage des erreurs désactivé en production ; erreurs journalisées dans `storage/logs`
  (sans mot de passe, PIN ni jeton).
- Installateur automatiquement désactivé après installation (404).
- Sauvegardes hors du dossier public, téléchargeables uniquement par un administrateur
  (requête POST + CSRF), noms de fichiers validés (pas de traversée de répertoire),
  empreinte SHA-256 vérifiée avant toute restauration.

### Impression, QR codes et traductions (v1.2)
- Imprimantes serveur : adresses limitées au **réseau local** (IP privées, nom local) et aux
  partages d'imprimante Windows `\\poste\partage` (aucun chemin de fichier arbitraire) ;
  gestion réservée à `settings.manage`, chaque modification journalisée.
- QR codes des documents : référence + **signature HMAC-SHA256** (clé `APP_KEY`, tronquée à
  48 bits) ; la page *Vérifier* signale un document modifié ou émis par une autre installation.
  Ce n'est pas une signature fiscale ; changer `APP_KEY` invalide les QR déjà imprimés.
- Traductions : appliquées uniquement aux textes fixes de l'interface (correspondance
  exacte), jamais à l'intérieur des scripts ; les valeurs restent échappées.
- Devis convertis, mobile money, coût d'achat : mêmes contrôles serveur que les ventes
  (prix et remises protégés par permission, opérateur limité à la liste paramétrée,
  nouveau prix de vente soumis à `products.price_edit`).

### Modules complémentaires (v1.4)
- Installation réservée à `addons.manage` (administrateur général) ; aucun envoi de module
  par le navigateur : il faut un accès au dossier `addons/` du serveur.
- Dossier `addons/` interdit par Apache ; codes de module validés (`[a-z][a-z0-9_]`) : pas
  de traversée de répertoire ; seuls les fichiers statiques (CSS, JS, images) sont publiés.
- Sauvegarde automatique avant installation et mise à jour ; désactiver ne supprime rien.
- **Limite** : un module installé exécute du PHP avec tous les droits de l'application ;
  n'installer que des modules de confiance (aucun « bac à sable »).

### Module Clinique (v1.5) — données de santé
- Dossier médical (consultations, constantes, hospitalisations, ordonnances) réservé au droit
  `clinique.medical` ; l'accueil et la caisse ne voient que la partie administrative
  (vérifié par test HTTP : 403 et contenu masqué).
- Chaque consultation de dossier médical, de séjour et de l'écran de supervision est journalisée.
- Délivrance des ordonnances réservée à `clinique.pharmacy`, contrôlée aussi dans le service ;
  elle passe par la vente standard (droits pharmacien, ordonnancier, idempotence).
- Écran de supervision : lecture JSON authentifiée, action « Fait » protégée par jeton CSRF.
- **Limites** : les données ne sont pas chiffrées dans la base (protéger le serveur, les
  sauvegardes et les postes) ; pas de certification « logiciel médical » ; les seuils d'alerte
  sont indicatifs et ne remplacent pas la surveillance clinique.

### Mise à jour du logiciel depuis le navigateur (v1.6)
- Réservée à la permission `system.update` (administrateur général) **et** à la saisie du mot
  de passe ; jeton de préparation lié à la session ; CSRF sur chaque envoi.
- Chemins contrôlés côté serveur : pas de `..`, pas de chemin absolu, seuls les dossiers du
  logiciel sont acceptés ; `.env`, `storage/`, `public/uploads/` ne sont jamais remplacés.
- Version plus ancienne refusée ; fichiers essentiels exigés ; empreintes SHA-256 de
  `release.json` vérifiées (fichier manquant ou modifié → refus).
- Sauvegarde de la base **et** du code actuel avant la copie ; retour au code précédent possible.
- **Limite** : `release.json` prouve l'intégrité (copie complète, non endommagée), pas
  l'origine : un administrateur qui installe un dossier piégé exécute ce code. N'installer que
  les versions reçues de l'auteur.

### Licence et protection contre l'utilisation non autorisée (v1.6)
- Clé d'activation **signée (Ed25519)** par l'auteur et liée à l'identifiant de l'installation :
  une clé copiée sur une autre installation, modifiée ou expirée est refusée ; la clé privée
  n'est jamais livrée avec le logiciel.
- Essai de 30 jours (date inscrite au journal d'audit), puis **lecture seule** : consultation,
  sauvegardes, activation restent possibles ; aucune donnée n'est bloquée ni effacée.
- Mentions de l'auteur dans le menu, à la connexion et à l'installation ; contrat de licence
  accepté à l'installation.
- **Limite honnête** : le code PHP est lisible ; ces mesures empêchent la copie simple et
  l'usage sans clé, pas un informaticien qui modifie le code. Pour aller plus loin : encodage
  du code PHP (ionCube, SourceGuardian — outils payants) et recours au contrat (article 4).

### Intégrité métier
- **Transactions SQL** pour ventes, paiements, stocks, retours, réceptions, inventaires, caisse.
- Verrous de ligne (`SELECT … FOR UPDATE`) sur stocks, lots, sessions de caisse, comptes
  clients/fournisseurs et séquences de numérotation.
- **Idempotence** des ventes (identifiant unique généré par la caisse) : une double validation
  ou une nouvelle tentative après coupure réseau ne crée jamais de doublon.
- Une seule session ouverte par caisse (index unique en base).
- Stock négatif bloqué par défaut ; quantités modifiables uniquement par des mouvements tracés.
- **Aucune suppression** de ventes, paiements, mouvements, dépenses : annulation, retour ou
  contrepassation, avec motif obligatoire et journalisation.
- Journal d'audit : connexions, échecs, verrouillages, ventes, annulations, remises autorisées,
  réimpressions (mention DUPLICATA), ajustements, rappels de lots, modifications d'utilisateurs,
  de rôles et de paramètres, sauvegardes et restaurations.

---

## 2. Vérifications effectuées

Exécutées réellement dans l'environnement de développement (PHP 8.3, MariaDB 10.11) :

1. **`php tests/run.php` : 95 tests, 0 échec**, dont :
   CSRF (formulaire, API JSON), connexion/échec/verrouillage/PIN, régénération de session,
   en-têtes HTTP, redirection des pages protégées, contrôle d'accès par rôle (403),
   accès aux ventes d'autrui (IDOR), injections SQL dans la recherche, les filtres et le tri,
   XSS stocké, fichiers sensibles, téléversement d'un script déguisé en image,
   intégrité et altération des sauvegardes, traversée de répertoire, cohérence stock/mouvements/lots,
   et pour la v1.2 : impression ESC/POS vers un serveur TCP local de test (octets, QR, tiroir,
   coupe), imprimante injoignable sans perte de vente, adresses d'imprimante publiques refusées,
   QR authentique / falsifié, devis (prix protégés, conversion idempotente), modes de coût,
   canaux de vente, traductions et affichage arabe (RTL) ; v1.3 : calcul de TVA (générale,
   exonération, sans TVA), rapport TVA net des retours, validité des PDF générés, accès
   au rapport TVA réservé (403), envoi en cuisine à l'encaissement.
2. **Apache 2.4 + mod_php (configuration type WampServer, projet dans un sous-dossier)** :
   `/.env`, `/.env.example`, `/app/…`, `/config/…`, `/storage/…`, `/database/schema.sql`,
   `/routes/…`, `/tests/…`, `/bin/…`, `/README.md`, `/public/.htaccess`, `/public/uploads/` → **403** ;
   un fichier `.php` ou `.phtml` déposé dans `uploads` → **403, non exécuté**.
3. Assistant d'installation sur une copie vierge : `.env` créé hors web (0640, clé aléatoire),
   puis `/install` → 404.
4. Navigation Chromium sur les écrans principaux : aucune erreur JavaScript, aucune violation CSP.

Ces tests ont été faits sur l'application locale uniquement (aucun test sur un système tiers).

---

## 3. Risques résiduels et recommandations

| Risque | Recommandation |
|---|---|
| **HTTP en clair** sur le réseau local : mots de passe interceptables sur un Wi-Fi partagé | Activer HTTPS (voir INSTALLATION § 8) et `SESSION_SECURE=true` ; Wi-Fi WPA2/WPA3 dédié à la boutique. |
| Accès physique au poste (vol, accès au disque) : base, sauvegardes et `.env` non chiffrés | Chiffrement BitLocker du disque, session Windows protégée, verrouillage automatique, sauvegardes hors site chiffrées. |
| Compte MySQL `root` sans mot de passe (défaut Wamp) | Définir un mot de passe ; utiliser un compte dédié à POS Wan avec droits limités à sa base. |
| phpMyAdmin accessible | Le laisser limité à `localhost` (défaut Wamp) ; ne jamais exposer Wamp sur Internet. |
| Liste des comptes ayant un PIN visible sur l'écran de connexion (choix ergonomique de la caisse) | Désactiver la connexion PIN dans Paramètres si l'écran est visible du public ; PIN de 6 chiffres conseillé. |
| Un administrateur malveillant peut tout faire (y compris restaurer une ancienne sauvegarde) | Limiter le rôle Administrateur au propriétaire ; contrôler régulièrement le journal d'audit ; conserver des sauvegardes hors site. |
| Journal d'audit modifiable par quiconque a un accès direct à la base | Restreindre l'accès MySQL ; exporter périodiquement le journal. |
| Fraude interne par entente (caissier + superviseur) | Contrôles croisés : rapports de remises par vendeur, annulations, écarts de caisse, inventaires tournants. |
| `'unsafe-inline'` autorisé pour les styles (CSP) | Risque faible (aucun script inline autorisé) ; peut être retiré lors d'une refonte des styles. |
| Dépendances front-end figées (Bootstrap 5.3.3, Chart.js 4.4.4) | Vérifier les avis de sécurité lors des mises à jour ; elles sont servies localement. |
| Module pharmacie | Fonctions de traçabilité fournies, mais **la conformité réglementaire (Niger) n'est pas certifiée** : à valider par un pharmacien responsable et l'autorité compétente avant usage en production. |
| Paiements carte / mobile money | Enregistrés avec l'opérateur et la référence, sans intégration aux opérateurs : rapprochement manuel avec les relevés. |
| Imprimantes réseau (port 9100) | Le protocole RAW n'est ni chiffré ni authentifié : placer les imprimantes sur le réseau de la boutique, pas sur un Wi-Fi public. |
| Traductions générées | Relues automatiquement (paramètres, cohérence) mais pas par des locuteurs natifs, en particulier le haoussa : faire relire avant usage auprès du public. |

### Bonnes pratiques d'exploitation
- Un compte nominatif par personne, jamais de compte partagé.
- Désactiver immédiatement le compte d'un employé qui quitte l'entreprise.
- Clôturer la caisse chaque jour et justifier tout écart.
- Vérifier chaque semaine : écarts de caisse, annulations, remises, ajustements de stock, échecs de connexion (Journal d'audit).
- Tester une restauration chaque trimestre.
- Appliquer les mises à jour de Windows, WampServer et PHP.
