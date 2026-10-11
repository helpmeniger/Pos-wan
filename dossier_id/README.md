# DOSSIER ID — Gestion numérique des dossiers et identification administrative

Application web locale (WampServer / Windows) permettant à un service administratif habilité de
gérer des fiches individuelles, les passages dans les bureaux, les photographies de référence,
les documents associés et l'historique des procédures, avec contrôle des doublons, traçabilité
complète et protection des données.

> **Outil d'aide à la gestion administrative.** Toute décision reste humaine. Une mention dans
> un dossier ne constitue jamais une preuve de culpabilité. Cette version **ne comporte aucune
> reconnaissance faciale automatique** : la comparaison des photographies est faite visuellement
> par l'agent.

## Démarrage rapide (WampServer)

```bat
:: 1. Copier le dossier dans C:\wamp64\www\dossier_id\
:: 2. Créer la base et l'utilisateur MySQL (voir docs\INSTALLATION.md, étape 3)
copy config\config.example.php config\config.php
notepad config\config.php
:: 3. Vérifier puis installer (remplacer 8.3.x par votre version de PHP)
C:\wamp64\bin\php\php8.3.x\php.exe scripts\check_env.php
C:\wamp64\bin\php\php8.3.x\php.exe scripts\install.php
:: 4. Facultatif, environnement de test uniquement : données fictives
C:\wamp64\bin\php\php8.3.x\php.exe scripts\seed_demo.php
```

Accès : <http://localhost/dossier_id/public/>

### Comptes de démonstration (données fictives, mode `demo` uniquement)

Mot de passe commun : `Demo!Dossier2026` — refusés automatiquement si `app.env = 'production'`.

| Identifiant | Rôle | Accès |
|---|---|---|
| `admin.demo` | Administrateur | Comptes, paramètres, sauvegardes — **aucun accès au contenu des dossiers** |
| `agent.demo` | Agent d'enregistrement | Création et mise à jour des fiches, photos, passages, procédures, documents |
| `consult.demo` | Agent habilité à consulter | Lecture seule, y compris fiches restreintes et archivées, export CSV |
| `controle.demo` | Responsable de contrôle | Journal d'audit, vérification d'intégrité, statistiques |
| `inactif.demo` | (désactivé) | Scénario de refus de connexion |

## Fonctionnalités

- **Fiches individuelles** : numéro unique (`DID-AAAA-NNNNNN`), identification, identifiant administratif
  (vérifié ou non), rectification motivée, archivage, niveau d'accès restreint.
- **Recherche** : nom/prénom (accents ignorés, orthographes approchantes), n° de dossier, identifiant,
  période de naissance ou d'enregistrement, statut procédural, n° de procédure/PV ; résultats paginés,
  filtrés selon les droits, export CSV (permission dédiée).
- **Doublons** : alerte à la création (identifiant, nom/prénom, inversion, phonétique, date et lieu
  de naissance), justification exigée, analyse globale, décision motivée de l'agent.
- **Comparaison visuelle** : photographies côte à côte, zoom et déplacement synchronisés, niveaux de
  gris, grille de repères, tableau comparatif des informations — sans aucune analyse automatique.
- **Photographies** : JPEG/PNG, vérification du type réel, des dimensions et de la taille, ré-encodage
  (métadonnées EXIF supprimées), stockage chiffré hors du web, référence d'autorisation obligatoire,
  retrait motivé (conservé pour traçabilité), chaque affichage est journalisé.
- **Passages** : date, lieu, service, motif, référence, agent responsable, observations, statut.
- **Procédures** : n° de procédure et PV, faits *allégués*, service enquêteur, statut (entendue,
  mise en cause, poursuivie, condamnée, acquittée, clôturée), décision documentée et caractère définitif.
  « Condamnée » exige juridiction, date, dispositif et document justificatif ; tout changement de
  statut est justifié et historisé.
- **Documents** : PDF/JPEG/PNG, PDF actifs refusés, chiffrés, empreinte vérifiée au téléchargement.
- **Tableau de bord** : indicateurs, statistiques administratives agrégées, dossiers récents,
  alertes de sécurité, état de la base, dernières opérations.
- **Sécurité** : voir [docs/SECURITE_MAINTENANCE.md](docs/SECURITE_MAINTENANCE.md).

## Structure

```
dossier_id/
├── public/                 seul répertoire servi : index.php + assets (Bootstrap 5.3.3 local)
├── app/
│   ├── Core/               configuration, base, sessions, CSRF, authentification, audit, chiffrement, routeur
│   ├── Controllers/        un contrôleur par module
│   ├── Models/             Person, Visit, Procedure (validation + requêtes préparées)
│   ├── Services/           accès, doublons, imports, stockage chiffré, sauvegardes, migrations
│   ├── Support/            libellés, validation, pagination, normalisation des noms
│   ├── Views/              gabarits PHP (échappement systématique)
│   └── routes.php
├── config/                 config.example.php (sans secret) → config.php
├── database/               migrations/, dossier_id.sql (import phpMyAdmin), seeds/fichiers_test/
├── storage/                photos/, documents/, backups/, logs/, tmp/ (accès web interdit)
├── scripts/                install, check_env, seed_demo, backup
├── tests/                  tests unitaires, d'intégration et HTTP (php tests/run.php)
└── docs/                   guides
```

## Documentation

| Guide | Contenu |
|---|---|
| [docs/INSTALLATION.md](docs/INSTALLATION.md) | Installation pas à pas sous WampServer, VirtualHost, php.ini |
| [docs/PREMIER_ADMINISTRATEUR.md](docs/PREMIER_ADMINISTRATEUR.md) | Premier compte administrateur et création des agents |
| [docs/SAUVEGARDE_RESTAURATION.md](docs/SAUVEGARDE_RESTAURATION.md) | Sauvegardes chiffrées, planification, restauration testée |
| [docs/SECURITE_MAINTENANCE.md](docs/SECURITE_MAINTENANCE.md) | Mesures de sécurité, exploitation, mises à jour |
| [docs/RAPPORT_TESTS.md](docs/RAPPORT_TESTS.md) | Tests réellement exécutés et résultats |
| [docs/FONCTIONNALITES_ET_LIMITES.md](docs/FONCTIONNALITES_ET_LIMITES.md) | Fonctionnalités opérationnelles et limites restantes |

## Tests

```bat
C:\wamp64\bin\php\php8.3.x\php.exe tests\run.php
```

Les tests utilisent une base séparée `<nom>_test` (créée et supprimée automatiquement — le compte
MySQL doit avoir les droits sur cette base) et un stockage temporaire `tests/.tmp`.
