# Application Android HELP-ME

Projet Android Studio de l'application HELP-ME (`com.hightechniger.help_me`), relié au manuel « Apprendre l'informatique de bureau ».

## Compiler

Prérequis : JDK 17 ou 21, SDK Android (plateforme 35). Puis, à la racine de ce dossier :

1. Copier `google-services.json` (console Firebase) dans `app/`.
2. Créer `secrets.properties` (jamais dans le dépôt) :
   ```
   HM_SERVER_URL=…        # serveur du lobby et du classement
   HM_LOGIN_URL=…         # serveur de connexion
   HM_API_KEY=…
   HM_HMAC_SECRET=…       # À CHANGER côté serveur : l'ancien a été publié dans l'APK
   HM_MANUAL_PASSWORD=…
   HM_FORUM_RELAY_KEY=…
   ```
3. Pour la version publiée, créer `keystore.properties` (`storeFile`, `storePassword`, `keyAlias`, `keyPassword`).
4. `./gradlew assembleDebug` ou `./gradlew bundleRelease` (bundle pour Google Play).

## Changements de cette mise à jour

- Outillage : Gradle 8.9, plugin Android 8.7.3, `compileSdk`/`targetSdk` 35, Java 17, Firebase BoM ; doublons de plugins, Realm et jcenter supprimés.
- Dépendances inutilisées retirées : WebRTC, ML Kit, ExoPlayer, Picasso, Firebase Storage. Version publiée réduite par R8 : 118 Mo → 43 Mo (APK universel ; ~20 Mo par appareil via Google Play).
- Signature de publication par `keystore.properties` (plus de clé de débogage).
- Secrets retirés du code source (`BuildConfig`, lus depuis `secrets.properties`).
- `MainActivity11` et `MainActivity12` (déclarées sans code) retirées du manifeste.
- **Vidéos remplacées provisoirement par des images** du manuel (`res/drawable-nodpi/lecon_*.webp`, 1,2 Mo au lieu de ~714 Mo) via `MediaImages` et `ImageLessonActivity`.
- Scan des codes du manuel : `MainActivity8` → `LessonActivity` (65 fiches, `assets/lessons.json`).
- **Assistant IA hors ligne refait** (`brain/` + `AIChatActivity`) : une seule base `assets/brain.json`
  (2 347 fiches après fusion de 3 410 fiches et suppression de 1 063 doublons, dont les 209 sections du manuel et
  40 fiches nouvelles), compréhension des fautes et abréviations, suites de conversation, avis « Utile / Pas utile »
  mémorisés, dictée vocale et lecture à voix haute. Évaluation : 98 % de bonnes réponses sur le jeu de réglage
  (ancien moteur : 43 %), 89 % sur le jeu de contrôle (ancien : 31 %). Voir `app_update/README.md`.
- Démonstration animée en code « Créer et organiser » (`demo/`).

Régénérer les données du manuel et de l'assistant (à la racine du dépôt) :
`python3 app_update/tools/export_manuel.py && python3 app_update/tools/build_brain.py`.

## Refonte des écrans (novembre 2026)

- **Cours** : `assets/course.json` (9 modules, 128 leçons) produit par `app_update/tools/export_cours.py` à partir du manuel :
  chaque leçon reprend le texte, les étapes, les tableaux, les encadrés, les exercices et les **63 figures du manuel**
  (`res/drawable-nodpi/fig_*.webp`, flèches et annotations conservées).
- **Nouveaux écrans** : accueil (progression, reprendre, recherche, accès rapides, modules), `ModuleActivity`,
  `LessonReaderActivity` (lecture à voix haute, favoris, « J’ai compris », leçon suivante), `TipsActivity`
  (astuce du jour à faire glisser, fiches par thème), `ShortcutChallengeActivity` (défi chronométré de 78 raccourcis).
- **Scan** : chaque code-barres ouvre directement la leçon complète du manuel.
- **Système de design** : `ui/Ui.java` (couleurs, formes, animations), `res/values/styles_hm.xml`, icônes `ic_m_*`.
- Écrans harmonisés : inscription, carnet de notes, nom, score, historique, certificat, mises à jour, menu.
- Captures d’écran : dossier `captures/` (générées par `ScreensTest` avec Robolectric :
  `./gradlew testDebugUnitTest --tests '*ScreensTest*'`).
