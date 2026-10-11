# Catalogue agronomique — statut de validation

Ce document recense **toutes les données agronomiques intégrées** dans AgriSaha
et leur statut. Rien ici n'a encore été validé ligne à ligne par un organisme
agricole : chaque valeur est marquée « à valider » dans le code
(`ValidationStatus.toValidate`) et doit être confirmée par l'INRAN (Institut
National de la Recherche Agronomique du Niger), la Direction de la
vulgarisation agricole ou un technicien compétent avant une diffusion à grande
échelle.

Fichiers concernés :

- `lib/data/catalog/crop_catalog.dart` — durées de cycle (semis → récolte)
- `lib/data/catalog/calendar_templates.dart` — modèles de tâches du calendrier

## 1. Durées de cycle génériques (variété inconnue)

Utilisées uniquement si la variété est inconnue. L'application affiche alors
« Estimation générique — confiance faible » et propose de corriger la date.

| Culture  | Min (j) | Typique (j) | Max (j) | Remarque |
|----------|--------:|------------:|--------:|----------|
| Mil      | 75  | 90  | 120 | mils hâtifs 75–95 j, tardifs > 110 j |
| Sorgho   | 90  | 110 | 130 | dépend de la photopériode |
| Maïs     | 90  | 105 | 120 | variétés précoces à intermédiaires |
| Riz      | 100 | 120 | 150 | irrigué / bas-fond |
| Niébé    | 60  | 75  | 90  | hâtifs 60–70 j |
| Arachide | 90  | 105 | 120 | hâtive ≈ 90 j, semi-tardive ≈ 120 j |
| Oignon   | 90  | 110 | 130 | **à partir du repiquage** |
| Tomate   | 70  | 85  | 100 | jusqu'à la 1re récolte, à partir du repiquage |
| Chou     | 60  | 80  | 100 | à partir du repiquage |
| Autre    | —   | —   | —   | aucune valeur : l'utilisateur saisit la durée ou la date |

## 2. Variétés nommées

Liste volontairement courte, limitée à des variétés dont la durée de cycle est
largement publiée :

| Culture  | Variété | Cycle (j) | Source indiquée | Statut |
|----------|---------|-----------|-----------------|--------|
| Mil      | HKP     | 85–95 (90)   | Fiche variétale INRAN | à valider |
| Arachide | 55-437  | 85–95 (90)   | Catalogue régional CEDEAO/UEMOA/CILSS | à valider |
| Arachide | 28-206  | 115–125 (120)| Catalogue régional CEDEAO/UEMOA/CILSS | à valider |

**À compléter avec l'INRAN** : variétés de mil (ex. SOSAT-C88, ICMV IS 89305,
Zatib…), sorgho, niébé (ex. IT90K-372-1-2, TN 5-78…), maïs, riz et oignon
(Violet de Galmi). Ces variétés ne sont **pas** intégrées tant que leurs
durées n'ont pas été confirmées ; l'agriculteur ou le conseiller peut les
ajouter lui-même dans le formulaire (« Mémoriser cette variété »), avec la
mention « Saisie par l'utilisateur ».

## 3. Calendrier des opérations

Modèles génériques, décalés par rapport au semis (S) ou à la récolte estimée
(R). Aucun produit, dose ou mélange n'est recommandé.

| Tâche | Période | Cultures |
|-------|---------|----------|
| Préparation du sol | S−14 à S−1 | toutes |
| Semis / repiquage | S | toutes |
| Contrôle de la levée | S+5 à S+10 | céréales, légumineuses, riz, autres |
| Contrôle de la reprise | S+5 à S+10 | maraîchage |
| Démariage | S+15 à S+20 | mil, sorgho |
| 1er sarclage | S+15 à S+25 | toutes |
| Fertilisation (à valider avec un technicien) | S+15 à S+30 | céréales, riz, maraîchage |
| 2e sarclage | S+35 à S+45 | céréales, légumineuses, maraîchage |
| Surveillance ravageurs/maladies | tous les 14 j jusqu'à R−14 | toutes |
| Humidité du sol / irrigation | tous les 7 j jusqu'à R−10 | irrigué ou mixte uniquement |
| Surveillance de la maturation | R−14 à R−8 | toutes |
| Préparation de la récolte | R−7 à R−1 | toutes |
| Récolte | R à R+7 | toutes |
| Séchage et stockage | R+1 à R+14 | céréales, légumineuses, riz |
| Tri et conservation | R+1 à R+7 | maraîchage |

Les tâches dont la période était déjà passée au moment de l'enregistrement
sont marquées « Antérieure à l'enregistrement » et ne génèrent pas d'alerte.

## 4. Amélioration des estimations

Chaque récolte réelle conserve l'estimation initiale (date et durée). Le
formulaire de culture affiche le cycle moyen réellement observé par
l'agriculteur pour la même culture/variété, ce qui lui permet d'ajuster ses
prochaines estimations.
