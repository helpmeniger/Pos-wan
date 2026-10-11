# Rapport des tests effectués

Date : 11 octobre 2026 — version : migration `001_initial_schema`.

## 1. Environnement d'exécution réel des tests

| Élément | Utilisé pour les tests | Cible de production |
|---|---|---|
| Système | Linux (conteneur Ubuntu 24.04) | Windows 10/11 |
| PHP | 8.3.6 CLI + serveur intégré `php -S` | PHP 8.x de WampServer |
| Base | MariaDB 10.11.14 | MySQL 8 ou MariaDB de WampServer |
| Serveur web | serveur intégré de PHP | Apache 2.4 (WampServer) |
| Navigateur | Chromium (Playwright) pour le contrôle visuel | navigateurs des postes |

**Non testé directement** : exécution sous Windows avec WampServer, Apache et ses fichiers
`.htaccess`, MySQL 8. Le code n'utilise aucune fonction propre à Linux (chemins portables,
extensions standard des distributions PHP de WampServer), mais une recette sur le poste cible
reste indispensable (section 5).

## 2. Tests automatisés — `php tests/run.php`

Résultat de la dernière exécution : **44 tests réussis, 0 échec, 265 assertions (≈ 22 s)**.
Base dédiée `dossier_id_test` recréée à chaque exécution ; stockage et clés temporaires dans `tests/.tmp`.

### Tests unitaires et d'intégration (33)

| Domaine | Tests |
|---|---|
| Authentification | connexion et journalisation ; hachage Argon2id ; message générique ; verrouillage après N échecs ; compte désactivé ; désactivation pour inactivité ; comptes de démo refusés en production |
| Sessions | expiration d'inactivité et durée maximale ; révocation immédiate d'un compte désactivé |
| Mots de passe | politique de complexité |
| Permissions | matrice des 4 rôles (moindre privilège), refus journalisé, aucun rôle n'a toutes les permissions |
| Fiches | validation (obligatoires, formats, dates futures, caractères) ; numéro unique ; détection des champs modifiés ; accès fiche restreinte / archivée / inexistante |
| Recherche | nom sans accents, orthographe approchante (Moussa/Musa), n° de dossier, identifiant normalisé, période, statut procédural, n° de procédure, tentative d'injection SQL ; pagination |
| Passages | historique, recherche par période et service, validation |
| Procédures | « condamnée » exige juridiction, décision, date, justificatif ; « poursuivie » exige référence parquet ; caractère définitif réservé aux décisions ; changement de statut justifié et historisé ; plusieurs procédures par fiche |
| Doublons | normalisation, clé phonétique ; détection (même identifiant, noms proches, noms inversés, date différente exclue) ; analyse globale ; exclusion après décision |
| Photographies | ré-encodage et suppression EXIF ; redimensionnement ; PNG ; image trop petite (mauvaise qualité) ; faux fichier image (PHP) ; GIF refusé ; image tronquée ; fichier vide ; polyglotte JPEG+PHP neutralisé ; taille maximale |
| Documents | PDF valide ; PDF avec JavaScript/OpenAction refusé ; exécutable refusé ; nom de fichier nettoyé |
| Stockage | chiffrement au repos ; altération détectée ; traversée de répertoire refusée ; clés distinctes |
| CSRF / XSS / CSV | jetons ; échappement des sorties dans la page de fiche ; neutralisation des formules |
| Journal d'audit | filtrage des données sensibles ; chaîne valide ; modification et suppression détectées |
| Sauvegarde | sauvegarde complète chiffrée, modification, restauration (base + fichiers), sauvegarde de sécurité, journal intègre ; fichiers altérés / tronqués / étrangers refusés ; nom invalide refusé |
| Résilience | base indisponible : exception contrôlée, reprise après rétablissement |

### Tests HTTP de bout en bout (11) — serveur réel, cookies, CSRF, envois multipart

En-têtes de sécurité et cookie `HttpOnly`/`SameSite=Strict` ; redirection sans authentification
(y compris photographies) ; 404 / 405 ; CSRF absent → 419 ; changement de mot de passe imposé
puis déconnexion ; création de fiche avec alerte de doublon et justification ; import de photo
(EXIF supprimé, `no-store`), fichier non image refusé, droits par rôle, journalisation des
affichages ; agent sans autorisation et fiche restreinte (403 journalisés) ; rectification motivée,
archivage, interdiction de modifier une fiche archivée ; sauvegarde par l'administrateur,
confirmation exigée, administrateur sans accès aux dossiers ; `public/` ne contenant que
`index.php` ; parcours complet passages → procédures (condamnation non documentée refusée, clôture)
→ documents (téléchargement, rattachement invalide refusé) → impression → revue de doublon →
création de compte (mot de passe temporaire, changement imposé) → paramètres (bornes) →
garde-fous de la matrice → audit (filtre, intégrité, export CSV).

## 3. Contrôles manuels et semi-automatisés effectués

- `php -l` sur les 83 fichiers PHP : aucune erreur de syntaxe.
- `php scripts/check_env.php` : aucun point bloquant (avertissement attendu : `upload_max_filesize = 2M`
  dans l'environnement de test).
- `php scripts/install.php --create-db --demo` sur base vide : réussi ; ré-exécution sans effet de bord.
- Import de `database/dossier_id.sql` dans une base vide : 15 tables créées.
- Parcours de 20 pages × 4 comptes de démonstration (80 requêtes) : 35 réponses 200 et 45 refus 403
  conformes à la matrice des permissions ; aucun avertissement PHP dans le journal du serveur.
- Captures Chromium (connexion, tableau de bord, liste, fiche, photographies, procédures, comparaison
  visuelle, doublons, création, audit, paramètres, utilisateurs) : aucune erreur JavaScript ni violation
  CSP. Défaut corrigé pendant la recette : les onglets masqués de la fiche occupaient de l'espace.
- Scénario « restauration d'une sauvegarde » en ligne de commande sur la base de démonstration :
  sauvegarde → vérification → modification → restauration → donnée d'origine retrouvée, sauvegarde
  de sécurité créée.
- Scénario « compte désactivé » (`inactif.demo`) : connexion refusée avec message explicite.

Défauts trouvés par les tests et corrigés : recherche approchante limitée au nom (ajout d'une clé
phonétique du prénom) ; avertissement d'interpolation dans l'outil de migration.

## 4. Correspondance avec les scénarios de démonstration

| Scénario | Données fictives | Vérifié |
|---|---|---|
| Personne avec plusieurs passages et procédures | DIALLO Aminata (5 passages, entendue + condamnée documentée non définitive) | automatisé + visuel |
| Doublon potentiel (orthographe) | GARBA Moussa / GARBA Musa | automatisé + visuel |
| Doublon (même identifiant) | ADAMOU Zeinabou / Zeinab (`DEMO-100777` / `DEMO 100 777`) | automatisé |
| Orthographe approchante | OUMAROU / UMARU Abdoulaye | automatisé |
| Personne acquittée | SANI Ibrahim | automatisé |
| Dossier clôturé | BELLO Fatima | automatisé |
| Fiche à accès restreint | OUSMANE Mariama | automatisé (403 / 200 selon le rôle) |
| Fiche archivée | MAMANE Yacouba | automatisé |
| Utilisateur sans autorisation | `controle.demo` sur les fiches, `consult.demo` en création | automatisé |
| Photographie de mauvaise qualité / fausse image / PDF actif | `database/seeds/fichiers_test/` | automatisé |
| Restauration | sauvegarde de la base de démonstration | manuel CLI + automatisé |

## 5. Recette à réaliser sur le poste WampServer cible

1. `check_env.php` sans erreur ; `tests\run.php` : 44 tests réussis.
2. <http://localhost/dossier_id/config/config.php>, `/storage/`, `/app/`, `/database/dossier_id.sql`
   → **403** (vérifie les `.htaccess` sous Apache).
3. Connexion, import d'une photo de `fichiers_test`, comparaison visuelle, impression d'une fiche.
4. Sauvegarde planifiée exécutée par le Planificateur de tâches ; restauration sur un poste de test.
5. Accès depuis un second poste du réseau (si prévu) en HTTPS.
