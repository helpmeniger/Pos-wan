# Application HELP-ME — mise à jour liée au manuel (étape 1)

Fichiers à intégrer au projet Android Studio de l'application (`com.hightechniger.help_me`).

## Contenu

| Fichier | Où le mettre | Rôle |
|---|---|---|
| `assets/lessons.json` | `app/src/main/assets/` | 65 fiches de leçons (une par code-barres du manuel) : titre, partie, page, résumé, points clés, « À retenir », vidéo |
| `assets/knowledge_manuel.json` | `app/src/main/assets/` | 209 fiches de connaissances tirées du manuel pour l'assistant IA (format `intent / question / tags / answer / image`) |
| `java/.../MainActivity8.java` | remplace l'existant | Le scan ouvre la fiche de leçon pour **tous** les codes (28 d'origine + 35 nouveaux) |
| `java/.../LessonActivity.java` | nouveau | Écran de leçon moderne (animations d'apparition, vidéo, points clés, bouton vers l'assistant) |
| `java/.../AIChatActivity.java` | remplace l'existant | Charge `knowledge_manuel.json` en priorité ; reçoit la question venant d'une leçon |

| `java/.../demo/*.java` | nouveau paquet `com.hightechniger.help_me.demo` | Démonstrations animées dessinées en code (moteur + scène « Créer et organiser ») |
| `java/.../MainActivity4.java` | remplace l'existant | Le menu « Créer et organiser » ouvre la démonstration animée au lieu de `vid5.mp4` |

À ajouter dans `AndroidManifest.xml`, dans `<application>` :

```xml
<activity android:name=".LessonActivity" android:exported="false" />
<activity android:name=".demo.DemoActivity" android:exported="false"
    android:theme="@android:style/Theme.DeviceDefault.NoActionBar" />
```

## Vidéo remplacée par une animation en code : `vid5.mp4`

`vid5.mp4` (« Créer et organiser », code 2017, **17,4 Mo**) est remplacée par la démonstration
`CreerOrganiserScene`, dessinée en direct (code compilé : environ 40 Ko).
Contenu identique au manuel : clic droit › Nouveau › Dossier, nommer, ouvrir, Ctrl + Maj + N, F2,
glisser-déposer, Suppr, Corbeille › Restaurer, puis un écran « À retenir ». Sous-titres synchronisés,
boutons Précédent / Lecture-Pause / Suivant / Rejouer, barre de progression.

Après intégration, **supprimer `app/src/main/res/raw/vid5.mp4`** (vérifier d'abord qu'aucun autre fichier
ne référence `R.raw.vid5`, sinon la compilation échoue).

Aperçu de l'animation : `preview/demo_creer_organiser_apercu.mp4` (rendu ici avec le même code de scène).
Pour créer une nouvelle démonstration : écrire une classe qui étend `DemoScene`, l'ajouter dans
`DemoActivity.create()`, et indiquer son identifiant dans le champ `demo` de `lessons.json`
(table `DEMOS` de `tools/export_manuel.py`).

Les 28 codes d'origine gardent leur vidéo (même correspondance que l'ancien `MainActivity8`). Les 35 nouveaux codes affichent la fiche de leçon en attendant leurs vidéos : il suffira d'ajouter la vidéo dans `res/raw` et son nom dans le champ `video` de `lessons.json`.

## Régénérer les données après une modification du manuel

```bash
python3 manuel/tools/build.py           # reconstruit le manuel (et codes-helpme.json)
python3 app_update/tools/export_manuel.py
```

## Points relevés dans l'application actuelle

1. **Sécurité (urgent)** : `res/raw/helpmebot.json` est une clé privée de compte de service Google Cloud embarquée dans l'APK. À révoquer dans la console Google Cloud et à remplacer par un appel via un serveur. Ce fichier n'est pas copié ici.
2. **Taille** : environ 714 Mo de vidéos dans `res/raw` — au-delà des limites de Google Play pour le module de base : utiliser des packs de ressources (Play Asset Delivery) ou le streaming.
3. `assets/bookone.pdf` est l'ancien manuel : à remplacer par le nouveau PDF.
