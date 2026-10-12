# Guide de dépannage

Commencez toujours par `scripts\windows\check.bat` puis l'écran **Journal** de l'interface
(filtre « error »), et les fichiers `storage\logs\worker.log` / `monitor.log`.

## Démarrage

| Symptôme | Cause probable | Solution |
|---|---|---|
| `php.exe introuvable` | WampServer installé ailleurs | `set WAMP_DIR=D:\wamp64` ou `set VERIFIA_PHP=…\php.exe` avant le script |
| `[ERREUR] PHP >= 8.2` | ancienne version sélectionnée | WampServer › PHP › Version ; vérifier aussi `VERIFIA_PHP` |
| `[ERREUR] Extension GD` / `PDO MySQL` en ligne de commande | extension active pour Apache seulement | décommenter `extension=gd` / `extension=pdo_mysql` dans `wamp64\bin\php\php8.x\php.ini` |
| `Connexion MySQL/MariaDB` en erreur | WampServer arrêté, mauvais mot de passe, port différent | icône verte ; corriger `db` dans `config\config.php` (port 3306 ou 3307 pour MariaDB selon l'installation) |
| `L'interface … ne répond pas` | Apache arrêté, mauvais `base_url`, port 80 occupé (Skype, IIS) | démarrer Apache ; corriger `app.base_url` ; changer le port d'Apache |
| Page « Accès interdit » sur `/verifia/` | `rewrite_module` inactif ou `AllowOverride None` | activer le module ; WampServer › Apache › httpd-vhosts.conf : `AllowOverride All` |
| Page blanche / erreur 500 | dépendances absentes | `composer install` ; consulter `wamp64\logs\php_error.log` |
| `Dépendances Composer installées` en erreur | `vendor\` absent | `composer install --no-dev` dans le dossier verifia |

## Moteur de surveillance

| Symptôme | Cause | Solution |
|---|---|---|
| Badge « moteur arrêté » | `run_monitor.bat` non lancé ou planté | relancer `start.bat` ; lire `storage\logs\monitor.log` |
| `Playwright n'est pas installé` | `npm install` non exécuté | `cd monitor && npm install` |
| `Executable doesn't exist` (Chromium) | navigateur Playwright non téléchargé (proxy, pare-feu) | `npx playwright install chromium`, ou `'browser_channel' => 'msedge'` dans la configuration |
| `Impossible de lire la configuration via PHP` | `php` absent du PATH du moteur | lancer via `start.bat` (il ajoute PHP au PATH) ou définir `VERIFIA_PHP` |
| `Serveur VERIFIA injoignable` | Apache arrêté / `base_url` faux | après 60 s sans contact, les navigateurs sont fermés **par sécurité** ; redémarrer Apache |
| API du moteur : `Non autorisé` (403) | jeton différent ou appel non local | ne pas modifier `monitor.token` à la main ; `allowed_ips` doit contenir `127.0.0.1` et `::1` |
| « Capacité atteinte » au démarrage d'une session | limite configurée ou mémoire libre insuffisante | arrêter une session, augmenter `max_concurrent_sessions` (Administration) si la machine le permet |
| Session « Fenêtre du navigateur fermée manuellement » | la fenêtre a été fermée par l'utilisateur | bouton *Redémarrer* (VERIFIA ne rouvre pas une fenêtre fermée volontairement) |
| Session en « Erreur » après 3 redémarrages | plantages répétés (mémoire, site) | réduire le nombre de sessions, puis *Redémarrer* ; voir *Historique et erreurs* de la session |
| La fenêtre s'ouvre sur le mauvais écran | coordonnées absentes ou écran déconnecté | *Paramètres* de la session › position X/Y (voir MULTI-ECRANS.md) |

## Détection des publications

| Symptôme | Explication | Que faire |
|---|---|---|
| « CAPTCHA … intervention manuelle » | le site a présenté un contrôle anti-robot | le résoudre vous-même dans la fenêtre ; l'analyse reprend seule. VERIFIA ne contourne jamais ces contrôles |
| « Connexion requise » | session expirée ou première utilisation | se connecter dans la fenêtre de la session ; la connexion est conservée dans le profil local de cette session |
| « Le site limite ou bloque la navigation automatisée » | limitation de débit ou blocage | laisser la session suspendue, augmenter l'attente entre défilements, ou passer à l'analyse manuelle |
| 0 publication détectée sur un site | structure de page non reconnue par l'adaptateur générique (`article`, `[role=article]`, classes *post/entry/story*) | utiliser *Analyser maintenant* pour vérifier ; sinon *Analyse manuelle* ; un adaptateur spécifique peut être ajouté dans `monitor\src\extractor.js` |
| Texte tronqué (« Voir plus ») | VERIFIA ne clique pas sur les liens d'expansion | ouvrir la publication et utiliser l'analyse manuelle si le texte complet est nécessaire |
| Doublons visibles dans le fil | normal : la même publication vue dans plusieurs sessions est **une seule** publication avec plusieurs occurrences | voir « Occurrences » dans la fiche |

## Analyse et alertes

| Symptôme | Cause | Solution |
|---|---|---|
| Badge « analyse arrêtée », file « En attente » qui grossit | `run_worker.bat` arrêté | relancer `start.bat` ; on peut lancer plusieurs workers : `php bin\worker.php` dans d'autres fenêtres |
| Tâches « Échouées » | erreur répétée 3 fois (image corrompue, base indisponible) | détail dans *Journal* ; la publication reste consultable |
| Trop / pas assez d'alertes | seuil `alert_min_priority` | Administration : `high` par défaut ; `normal` transmet aussi les affirmations vérifiables simples |
| Correspondances d'images abusives / manquées | seuil `image_dhash_threshold` (10 par défaut, sur dHash **et** aHash) | baisser à 6–8 pour moins de faux positifs ; monter à 12 pour plus de rappel |
| Pas de mise à jour en direct | proxy ou antivirus qui bufférise les flux SSE | le centre bascule automatiquement en « mise à jour périodique » (toutes les 4 s) |
| PDF : image WebP absente | Dompdf ne gère pas WebP | l'image reste visible dans la fiche web ; le PDF le signale |

## Réinitialisation

- Données collectées uniquement : Administration › *Tout supprimer* (conserve comptes et paramètres, déclenche l'arrêt immédiat).
- Mot de passe administrateur perdu :
  `php -r "require 'src/bootstrap.php'; Verifia\Database::run('UPDATE users SET password_hash=? WHERE username=?', [password_hash('NouveauMotDePasse', PASSWORD_DEFAULT), 'admin']);"`
- Compte verrouillé après 5 échecs : attendre 15 minutes ou réinitialiser le mot de passe comme ci-dessus.
