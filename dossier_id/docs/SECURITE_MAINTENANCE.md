# Guide de sécurité et de maintenance

## 1. Mesures de sécurité implémentées

| Domaine | Mesure | Où |
|---|---|---|
| Mots de passe | Hachage Argon2id (bcrypt à défaut), rehachage automatique, politique de complexité, changement imposé à la 1re connexion | `app/Core/Auth.php` |
| Connexion | Message d'erreur générique, temps de réponse homogène, verrouillage du compte après N échecs, blocage du poste après 4×N échecs, journalisation de chaque tentative | `Auth::attempt()` |
| Comptes | Désactivation manuelle motivée, désactivation automatique après inactivité, comptes de démonstration refusés en production | `Auth`, `UserController` |
| Sessions | Cookie `HttpOnly`, `SameSite=Strict`, `Secure` en HTTPS, mode strict, identifiant régénéré à la connexion, expiration d'inactivité (15 min) et durée maximale (8 h), révocation si compte désactivé | `Session`, `Auth::check()` |
| Injections SQL | Requêtes préparées sans émulation, identifiants SQL validés, motifs LIKE échappés | `Database` |
| XSS | Échappement systématique (`e()`), Content-Security-Policy stricte sans script ni style en ligne | `helpers.php`, `App::securityHeaders()` |
| CSRF | Jeton par session, vérifié par le routeur sur **toutes** les requêtes POST, déconnexion en POST | `Csrf`, `App::run()` |
| Contrôle d'accès | Vérification côté serveur à chaque action (permissions) et au niveau de la fiche (restreinte / archivée) héritée par photos, passages, procédures, documents ; refus journalisés | `Auth::require()`, `Access` |
| Moindre privilège | 4 rôles, matrice modifiable mais tracée ; l'administrateur n'accède pas aux dossiers ; garde-fous non modifiables | `SettingsController` |
| Fichiers importés | Type réel (finfo + getimagesize), taille, dimensions, ré-encodage complet des images (EXIF et charges cachées supprimés), PDF actifs refusés, nom d'origine nettoyé | `UploadService` |
| Stockage | Hors de `public/`, noms aléatoires sans donnée personnelle, chiffrement authentifié (libsodium), empreinte vérifiée au téléchargement, `.htaccess` « Require all denied » | `FileStorage`, `Crypto` |
| Clés | Deux clés distinctes (stockage / sauvegarde), stockées hors de `www` | `config.storage.key_dir` |
| Sauvegardes | Chiffrées, intégrité vérifiée, sauvegarde de sécurité avant restauration | `BackupService` |
| Journal d'audit | Ajout seul, chaîné par SHA-256 (altération et suppression détectées), filtrage des clés sensibles, consultation par le responsable de contrôle | `Audit` |
| Journaux techniques | Message et emplacement de l'erreur uniquement, jamais les données de requête | `App::logError()` |
| En-têtes HTTP | CSP, `X-Frame-Options: DENY`, `nosniff`, `Referrer-Policy: no-referrer`, `Permissions-Policy`, `Cache-Control: no-store` | `App::securityHeaders()` |
| Exports | Permission dédiée, neutralisation des formules (injection CSV), journalisés | `Response::csv()` |

## 2. Protection des données personnelles

- Collecter uniquement les champs nécessaires : sexe, nationalité, profession sont facultatifs.
- Observations factuelles et professionnelles uniquement.
- Statuts procéduraux encadrés : « mise en cause » et « poursuivie » sont présentés comme des faits
  allégués ; « condamnée » exige une décision documentée ; le caractère définitif est distinct.
- Rectification motivée, archivage (retrait des consultations courantes), accès restreint.
- Définir une **durée de conservation** par catégorie de dossier, conforme aux règles applicables,
  et archiver les fiches arrivées à échéance.
- Avant la mise en production : faire valider le traitement par l'autorité compétente et le délégué
  à la protection des données (registre, analyse d'impact, information des personnes, durées).

## 3. Mise en production — liste de contrôle

- [ ] `app.env = 'production'` dans `config.php` (désactive les comptes de démo et masque les erreurs).
- [ ] Aucune donnée fictive : base installée **sans** `seed_demo.php`, ou réinstallée.
- [ ] Mot de passe MySQL fort ; compte `root` protégé ; utilisateur dédié aux droits limités.
- [ ] VirtualHost pointant sur `public/`, `Require local` / `Require ip` limité au réseau du service.
- [ ] HTTPS et `app.force_https = true` si accès réseau.
- [ ] Clés hors de `www`, copie hors ligne en lieu sûr.
- [ ] `php scripts\check_env.php` sans erreur.
- [ ] Sauvegarde quotidienne planifiée et externalisée ; restauration testée.
- [ ] `display_errors = Off`, `expose_php = Off`.
- [ ] Pare-feu Windows : ports 80/443 ouverts uniquement au réseau du service ; port MySQL (3306/3307) **fermé** à l'extérieur.
- [ ] Au moins deux administrateurs ; un responsable de contrôle distinct.
- [ ] Postes clients : sessions Windows verrouillées automatiquement, antivirus à jour.

## 4. Exploitation courante

| Fréquence | Tâche | Qui |
|---|---|---|
| Quotidienne | Vérifier la sauvegarde planifiée | Administrateur |
| Hebdomadaire | Consulter les alertes du tableau de bord, le journal (refus d'accès, échecs de connexion, exports) | Responsable de contrôle |
| Hebdomadaire | Externaliser les sauvegardes | Administrateur |
| Mensuelle | *Vérifier l'intégrité* du journal d'audit ; revue des comptes (départs, rôles) | Contrôle / Admin |
| Trimestrielle | Test de restauration sur un poste de test | Administrateur |
| Annuelle | Revue de la matrice des permissions, des durées de conservation, des fiches à archiver | Responsable du service |

## 5. Mises à jour

1. Sauvegarde complète et vérification.
2. Remplacer les fichiers de l'application **sans** écraser `config/config.php`, `storage/` ni les clés.
3. `php scripts\install.php` (applique les nouvelles migrations de `database/migrations/`).
4. `php scripts\check_env.php` puis `php tests\run.php` sur l'environnement de test.
5. Maintenir WampServer, PHP et MySQL/MariaDB à jour (correctifs de sécurité).

## 6. Incident de sécurité

1. Désactiver les comptes concernés ; changer les mots de passe des administrateurs.
2. Exporter et conserver le journal d'audit ; *Vérifier l'intégrité*.
3. Si une clé a pu être copiée : générer de nouvelles clés implique de rechiffrer les fichiers
   (opération non automatisée dans cette version — voir les limites) ; isoler le poste.
4. Informer la hiérarchie et le délégué à la protection des données selon la procédure du service.

## 7. Journaux

- Journal d'audit : dans la base (`audit_log`), consultable et exportable par le responsable de contrôle.
- Erreurs techniques : `storage\logs\app-AAAA-MM.log` (à purger périodiquement).
- Apache / PHP : `C:\wamp64\logs\`.
