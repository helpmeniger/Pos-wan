# Vidéos d'explication des codes-barres

`montage.py` reprend une vidéo source HELP-ME (image + voix off) et produit une vidéo d'explication moderne :
générique, sous-titres exacts calés sur la voix (surlignage mot à mot), étapes, loupes animées sur les parties
expliquées, schémas animés (bouton d'alimentation, souris, pavé tactile…), barre de progression et écran « À retenir ».

```
python3 app_update/tools/montage/montage.py app_update/tools/montage/configs/vid1.json sortie.mp4 --sources DOSSIER_DES_VIDEOS
```

Une configuration (`configs/vidN.json`) donne : la vidéo source, le titre, **le texte exact lu par la voix**
(une phrase par ligne), les étapes, les effets (loupe : zone `cx, cy` de l'image 1920 × 1080 et position `px, py` ;
`card` : schéma ; `zoom` : poussée de caméra) rattachés à une phrase, et les points « À retenir ».
Les phrases sont calées automatiquement sur les pauses de la voix (détection des silences).

Version pour l'application (720p, environ 3 Mo) :
```
ffmpeg -i sortie.mp4 -vf scale=1280:720 -c:v libx264 -preset slow -crf 27 -c:a aac -b:a 96k -ac 1 \
       -movflags +faststart app_android/app/src/main/res/raw/video_CODE.mp4
```
Le lecteur de leçon affiche automatiquement la carte « Regarder la vidéo » quand `res/raw/video_CODE.mp4` existe
pour un code-barres de la leçon (miniature : `res/drawable-nodpi/vthumb_CODE.webp`).

| Vidéo | Code-barres | Sujet | Statut |
|---|---|---|---|
| vid1 | 2019 | Allumer l'ordinateur | fait |
| vid2 | 2018 | La souris et le pavé tactile | fait |
| vid3 | 2001 | Manipuler une fenêtre | source trop lourde pour le connecteur Drive (12 Mo) |
| vid4 | 2002 | Barre des tâches et zone de notification | source trop lourde pour le connecteur Drive (23 Mo) |
