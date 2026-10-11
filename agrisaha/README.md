# AgriSaha

> Votre partenaire pour une agriculture plus intelligente

Application mobile Flutter d'assistance agricole pour les agriculteurs du
Niger et du Sahel : parcelles, suivi des semis, date estimée de récolte,
calendrier des travaux, journal de culture, récoltes réelles, rendement,
finances et tableau de bord — **entièrement hors connexion**.

## État du projet — version 0.1 (étape 1)

| Module | État |
|---|---|
| Identité visuelle, accueil, navigation (5 onglets) | ✅ fonctionnel |
| Base SQLite locale + migrations + file de synchronisation | ✅ fonctionnel (aucun serveur configuré) |
| Parcelles (création, modification, suppression protégée) | ✅ |
| Cultures : formulaire complet, variétés, photo facultative | ✅ |
| Calcul de la date estimée de récolte (catalogue / générique / saisie / correction) | ✅ |
| Âge, stade estimé, jours restants, niveau de confiance | ✅ |
| Calendrier agricole adapté à la culture et à l'irrigation | ✅ (à faire / effectuée / reportée + notes) |
| Journal de suivi chronologique (photos, quantités) | ✅ |
| Récolte réelle, rendement kg/ha, comparaison avec l'estimation | ✅ |
| Dépenses / recettes, marge, filtres période et culture | ✅ |
| Tableau de bord connecté aux données réelles + graphiques | ✅ |
| Alertes calculées localement (retards, travaux du jour, récoltes proches) | ✅ dans l'application |
| Profil local (sans compte), unité de surface locale, effacement des données | ✅ |
| Données de démonstration séparées et supprimables | ✅ |
| Notifications Android (rappels) | ⏳ étape 5 |
| Export PDF / CSV | ⏳ étape 6 |
| Météo (API), assistant, école agricole, traductions haoussa/zarma | ⏳ étape 7 — la carte météo affiche explicitement « non configurée » |
| Marché agricole (backend) | ⏳ étape 8 — écran informatif, aucun bouton factice |

Les durées de cycle et les modèles de calendrier sont documentés et marqués
« à valider » : voir [`docs/CATALOGUE_AGRONOMIQUE.md`](docs/CATALOGUE_AGRONOMIQUE.md).

## Prérequis

- Flutter **3.47.x stable** (Dart 3.13) — vérifié avec Flutter 3.47.7
- Android Studio (SDK Android, plateforme 35+, build-tools) et un JDK 17+
- Un téléphone Android (mode développeur) ou un émulateur

```bash
flutter --version
flutter doctor
```

## Installation et lancement

Toutes les commandes s'exécutent dans le dossier `agrisaha/` :

```bash
cd agrisaha
flutter pub get
flutter analyze          # analyse statique
flutter test             # tests unitaires, données et widgets
flutter run              # lance l'application sur l'appareil connecté
```

Dans Android Studio : *File › Open* → dossier `agrisaha`, puis lancer la
configuration `main.dart`.

## Générer un APK

```bash
cd agrisaha
flutter build apk --debug          # APK de test : build/app/outputs/flutter-apk/app-debug.apk
flutter build apk --release        # APK optimisé (signé avec la clé de debug si aucune clé n'est configurée)
flutter build appbundle --release  # pour le Play Store (clé de publication obligatoire)
```

### Signature de publication

1. Créer un keystore (à conserver précieusement, hors du dépôt) :
   ```bash
   keytool -genkey -v -keystore ~/agrisaha-release.jks -keyalg RSA \
     -keysize 2048 -validity 10000 -alias agrisaha
   ```
2. Copier `android/key.properties.example` en `android/key.properties` et le
   compléter. Ce fichier et le keystore sont ignorés par git.

## Architecture

```
lib/
  main.dart                 démarrage, ouverture de la base, ProviderScope
  app/                      application, routes (go_router), thème Material 3
  core/
    database/               AppDatabase (sqflite) + migrations versionnées
    errors/                 AppException, gestion centralisée des erreurs
    providers/              providers Riverpod (dépôts, lectures, horloge)
    utils/                  dates calendaires (UTC), formatage fr_FR, UUID
    validation/             validateurs de formulaires
  data/
    models/                 Plot, Crop, CropTask, JournalEntry, Harvest,
                            FinanceEntry, FarmProfile, CropVariety, enums
    catalog/                catalogue des cycles + modèles de calendrier
    repositories/           accès SQLite + règles métier + file de synchro
  domain/                   logique pure et testée : estimation de récolte,
                            stades, calendrier, rendement, finances,
                            alertes, tableau de bord
  features/                 écrans par module (home, dashboard, crop_tracking,
                            calendar, harvests, plots, alerts, finances,
                            marketplace, profile)
  shared/                   widgets communs, graphiques, service photo
test/
  unit/                     dates, estimations, calendrier, calculs, validations
  data/                     dépôts SQLite (base en mémoire et fichier)
  widget/                   démarrage, navigation, formulaire, tableau de bord
```

Principes :

- **Hors connexion d'abord** : toutes les données sont dans SQLite sur le
  téléphone ; elles survivent à la fermeture de l'application.
- **Dates calendaires en UTC** : `DateOnly` évite les erreurs de fuseau ; les
  fins de mois et années bissextiles sont testées.
- **Écritures transactionnelles** : une culture et son calendrier sont créés
  ensemble ; une récolte, sa recette et le changement de statut aussi.
- **Validation côté dépôt** en plus des formulaires.
- **Synchronisation préparée** : identifiants UUID, dates de création et de
  modification, table `sync_outbox` (une seule opération en attente par
  enregistrement → pas de doublon). Les données de démo ne sont jamais
  synchronisées. Aucun serveur n'est contacté dans cette version.
- **Migrations** : ajouter une entrée à `lib/core/database/migrations.dart`,
  ne jamais modifier une migration publiée.

## Dépendances

| Paquet | Rôle |
|---|---|
| flutter_riverpod 3 | gestion d'état |
| go_router 18 | navigation (barre inférieure à 5 onglets) |
| sqflite / path | base locale SQLite |
| intl, flutter_localizations | dates, nombres et sélecteurs en français |
| uuid | identifiants uniques |
| image_picker, path_provider | photos facultatives stockées localement |
| sqflite_common_ffi (dev) | base SQLite dans les tests |

Services externes : **aucun** dans cette version. Prévus : fournisseur météo
(ex. Open-Meteo), backend sécurisé pour le marché, éventuel assistant IA.
Exemple de configuration sans secret : `config/app_config.example.json`.

## Sécurité et confidentialité

- Aucune clé ni secret dans le code ; aucune permission Android déclarée.
- Appareil photo / galerie ouverts uniquement à la demande, via le sélecteur
  du système, avec explication préalable.
- Journalisation limitée au type d'erreur (aucune donnée saisie).
- Requêtes SQL paramétrées uniquement.
- Profil : suppression du profil seul, ou effacement complet des données,
  depuis l'onglet Profil.

## Tests

`flutter test` exécute 75 tests couvrant notamment : création de parcelle et
de culture, calcul de la date de récolte, âge de la culture, années
bissextiles et changements de mois, validation des formulaires,
enregistrement et rechargement après réouverture de la base, tâches
(effectuée / reportée / rouverte), récolte réelle, rendement, finances,
tableau de bord, file de synchronisation sans doublon, données de démo.

Restent à couvrir avec les étapes suivantes : erreurs réseau (météo/marché),
permissions et notifications, tests d'intégration sur appareil
(`integration_test`).
