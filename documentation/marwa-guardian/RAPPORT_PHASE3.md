# MARWA GUARDIAN — Rapport de la phase 3 (protection des données)

26/11/2026 — version 0.3.0. Code : `guardian/` (Go). Construction : `guardian/construire.sh <sortie> 0.3.0`.

## 1. Ce qui est développé

| Fonction | Fichiers | État |
|---|---|---|
| **Contrôle de chaque sauvegarde** de POS Marwa (`app\storage\backups\poswan_*.sql.gz`) : empreinte SHA-256 comparée au fichier `.sha256`, lecture complète du fichier compressé, en-tête « POS Marwa sauvegarde », dernière ligne `-- FIN`, nombre de tables, version. Résultat gardé en mémoire : un fichier n'est relu que s'il change | `internal/core/backups.go` | ✅ testé (sauvegarde réelle, sauvegarde tronquée, empreinte fausse) |
| **Ancienneté** : « Votre dernière sauvegarde vérifiée date de X » — avertissement après 26 h, critique après 72 h (réglables). Une sauvegarde abîmée ne compte pas | `CheckBackups` | ✅ testé |
| **Échecs de la sauvegarde automatique** lus dans le journal du lanceur de POS Marwa Bureau (« Sauvegarde automatique : … ») | `lastAutoBackupFailure` | ✅ testé (exemple de journal) |
| **Test de restauration** : la dernière sauvegarde vérifiée est rejouée dans une base MariaDB **temporaire et séparée** (dossier propre, port libre pris au hasard sur 127.0.0.1, mot de passe aléatoire sous Windows), comptage des tables, ventes, lignes de vente, articles, clients, utilisateurs, puis arrêt et effacement de la base temporaire. La base de POS Marwa n'est jamais touchée. Priorité basse. Tous les 7 jours (réglable, 0 = à la demande) et bouton « Tester la restauration maintenant » | `internal/core/restore.go` | ✅ testé sous Linux avec une **vraie sauvegarde de POS Marwa** (voir § 2) ; Windows **non exécuté** |
| **Copies hors du poste** : clé USB / disque externe reconnu **par son nom** (la lettre peut changer), autre disque ou partage réseau. Seules les sauvegardes vérifiées sont copiées, via un fichier temporaire, empreinte contrôlée, puis renommage. Dossier `MARWA GUARDIAN\<ordinateur>` ; rotation (N gardées) **uniquement** sur les copies de Guardian dans ce dossier. Configuration de POS Marwa Bureau (`pos-marwa.ini`, `app\.env`, `donnees\secret\bd.dat`) copiée une fois par jour, 10 jours gardés | `internal/core/copies.go` | ✅ testé (support simulé, rotation, configuration) |
| Alertes : sauvegarde absente, ancienne, abîmée, sauvegarde automatique en échec, test de restauration en échec (critique), copie non faite depuis 7 jours, copie en échec. Résolution automatique quand le problème disparaît | `backups.go`, `restore.go`, `copies.go` | ✅ testé |
| API : `GET /api/sauvegardes`, `POST /api/sauvegardes/tester`, `/copier`, `/reglages`, `POST /api/destinations`, `/destinations/{id}/retirer`. Contrôles : support branché, dossier absolu existant, refus d'un dossier placé dans les sauvegardes de POS Marwa ou dans les données de Guardian, pas de doublon, 5 destinations au plus, 1 à 365 copies gardées | `internal/web/server.go` | ✅ testé |
| **Console au style de POS Marwa** : barre latérale bleu nuit, « MARWA **GUARDIAN** » en texte seul (plus d'icône), tuiles en dégradé comme le tableau de bord de POS Marwa, écran de connexion sombre comme celui de POS Marwa, Bootstrap 5.3 et Bootstrap Icons **inclus dans le programme** (aucun appel à Internet). Nouvelle page « Sauvegardes » et réglages dans « Configuration » | `internal/web/console` | ✅ testé dans Chromium (aucune erreur JavaScript) |

## 2. Tests réellement lancés

- `go test ./...` : **23 tests**, tous réussis, dont `TestRestoreTestRealMariaDB` qui démarre réellement MariaDB (Linux).
- Essai de bout en bout sous Linux : faux poste POS Marwa Bureau contenant une **vraie sauvegarde automatique** produite par POS Marwa 3.17.0
  (`poswan_20261126_233229_auto.sql.gz`, 45 Ko), agent lancé :
  - sauvegarde « Vérifiée », 74 tables ;
  - test de restauration réussi en 2 s : **74 tables, 152 ventes, 371 lignes de vente, 19 articles, 2 clients, 1 utilisateur** ;
  - clé USB simulée « CLE-CAISSE » ajoutée depuis la console, copie faite et contrôlée (sauvegarde, archive des photos, empreintes, configuration du jour) ;
  - console pilotée par Chromium : connexion, tableau de bord, sauvegardes, configuration, journal — aucune erreur JavaScript.
- Défaut trouvé et corrigé pendant l'essai : sous Linux, le chemin du fichier de connexion de la base temporaire était trop long
  (limite de 107 caractères) ; il est maintenant placé dans le dossier temporaire du système.
- Défaut trouvé et corrigé : la console affichait « Jamais fait » juste après un test réussi (état rafraîchi seulement toutes les 30 min).

## 3. Non testé (à faire en phase 7 sur le PC de test Windows)

1. Test de restauration sous Windows (`mariadb-install-db.exe`, `mariadbd.exe` de POS Marwa Bureau ou de WampServer) avec le compte du service
   `NT SERVICE\MarwaGuardian`.
2. Lecture des volumes Windows (nom des clés USB, disques réseau) et espace libre.
3. Copie vers un **partage réseau** : le service accède au réseau sous le nom de l'ordinateur (`<DOMAINE>\<PC>$`) ; sur un partage simple
   (groupe de travail) il faudra peut-être donner les droits d'écriture à ce compte ou choisir une clé USB. À vérifier en phase 7.
4. Installations WampServer : le dossier de l'application et celui de MariaDB doivent être indiqués dans « Configuration ».

## 4. Limites connues

- Une sauvegarde « Vérifiée » signifie : fichier complet, empreinte juste, contenu lisible jusqu'à la fin. Seul le **test de restauration** prouve
  qu'elle se recharge ; il est fait sur la dernière sauvegarde, pas sur toutes.
- Les copies ne sont faites que lorsque la destination est branchée / accessible ; une clé USB rangée ailleurs n'est copiée qu'à son retour.
- Le fichier `bd.dat` copié est chiffré pour **cet** ordinateur : il ne sert qu'à restaurer sur le même PC.
- Les copies ne sont pas chiffrées par Guardian : une clé USB perdue expose les données de vente qu'elle contient. Données personnelles
  (clients) : à protéger conformément à la loi n° 2017-28 — rangez le support en lieu sûr.
- Aucune donnée n'est envoyée hors de l'ordinateur ; Guardian ne supprime que ses propres anciennes copies dans son dossier.

## 5. Version 0.3.1 — facilités ajoutées

| Fonction | État |
|---|---|
| **Menu à droite** par défaut, comme POS Marwa ; « à gauche » au choix dans Configuration → Affichage (réglage enregistré par l'agent) | ✅ testé (Chromium) |
| Petits écrans : menu escamotable ouvert par le bouton ☰, fermé en touchant à côté | ✅ testé (écran de téléphone simulé) |
| **« Votre protection »** sur le tableau de bord : 7 points contrôlés (sauvegarde récente, sauvegarde qui se recharge, copie hors du PC, disque, arrêts anormaux, services de sécurité, historique), note « x / y » ; chaque point en défaut a son bouton d'action (« Tester maintenant », « Copier maintenant », « Ajouter »). Un point non vérifiable est affiché « non vérifié » et n'est pas compté comme bon | ✅ testé |
| **Clé USB branchée → proposée en un clic** : « Oui, copier dessus » l'ajoute comme destination et lance la copie contrôlée ; « Pas cette clé » ne la propose plus sur ce navigateur | ✅ testé (support simulé « DISQUE-EXT » : copie faite et contrôlée) |
| **Imprimer le bilan** : tableau de bord mis en page pour l'imprimante (date, nom du poste), pour le technicien ou le dossier | ✅ testé (PDF produit par Chromium) |

Bootstrap 5.3.3 et Bootstrap Icons 1.11.3 : mêmes versions que POS Marwa, gardées pour l'uniformité.
