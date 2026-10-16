# Caméras

## Webcam / caméra USB (exemple de configuration)

| Champ | Valeur conseillée |
|---|---|
| Type | Webcam / caméra USB |
| Index du périphérique | `0` (bouton **Détecter** pour lister les caméras vues par le service IA) |
| Résolution | 1280 × 720 (640 × 480 sur un PC modeste) |
| Images/s capture | 15 |
| Images/s analysées | 5 (2–3 sur un PC modeste) |
| Fonction | Sortie |

* Le périphérique doit être branché sur **l'ordinateur qui exécute le service IA**. Une caméra
  branchée sur un smartphone ou un autre PC n'est pas accessible.
* Fermez les logiciels qui utilisent déjà la webcam (visioconférence).
* Sous Windows, OpenCV utilise DirectShow ; si l'image est noire, essayez un autre index ou un
  autre port USB, et autorisez l'accès à la caméra (Paramètres > Confidentialité > Caméra >
  « Autoriser les applications de bureau »).
* Déconnexion : le service IA réessaie automatiquement (1, 2, 5, 10 puis 30 s) et notifie
  « Caméra déconnectée / reconnectée ». Image uniforme pendant ~50 images → « Flux inutilisable ».

## Caméra IP compatible RTSP (exemple)

| Champ | Exemple |
|---|---|
| Type | Caméra IP (RTSP) |
| Adresse IP | `192.168.1.64` (adresse fixe ou réservée dans le routeur) |
| Port | `554` |
| Chemin du flux | voir tableau ci-dessous (**privilégier le sous-flux**) |
| Identifiant | compte « lecteur » dédié, sans droits d'administration |
| Mot de passe | chiffré en base (libsodium), jamais réaffiché |

Chemins courants (à vérifier dans la documentation de votre modèle) :

| Marque | Flux principal | Sous-flux |
|---|---|---|
| Hikvision | `/Streaming/Channels/101` | `/Streaming/Channels/102` |
| Dahua | `/cam/realmonitor?channel=1&subtype=0` | `...&subtype=1` |
| Reolink | `/h264Preview_01_main` | `/h264Preview_01_sub` |
| TP-Link Tapo | `/stream1` | `/stream2` |
| ONVIF générique | obtenir l'URI RTSP avec un outil ONVIF (ONVIF Device Manager) | |

Testez d'abord l'URL dans VLC (`Média > Ouvrir un flux réseau`), puis utilisez **Tester la
connexion** dans l'application.

**Sécurité** : seules les adresses IP littérales appartenant à `CAMERA_ALLOWED_NETWORKS` sont
acceptées (pas de nom d'hôte, pas de 127.x, ports de services internes refusés). Le navigateur
ne reçoit jamais l'URL ni le mot de passe : les images passent par PHP, qui vérifie les droits.

## Enregistreur existant (NVR/DVR)

Choisir le type « Enregistreur existant », renseigner l'IP du NVR et le chemin RTSP de la voie
souhaitée (ex. Hikvision : `/Streaming/Channels/201` pour la voie 2). La compatibilité dépend de
la marque, du modèle, de l'activation de RTSP et des droits du compte.

## Placement de la caméra de sortie

* Vue **dégagée et plongeante** sur le passage de sortie, pas de contre-jour (porte vitrée).
* Éclairage suffisant et stable ; éviter les reflets.
* Le passage doit traverser l'image ; les articles doivent être visibles plusieurs images de suite.
* Une seule caisse/porte par ligne si possible.

## Ligne virtuelle

Fiche caméra > cliquer **deux points** (A puis B) sur l'aperçu. La flèche rouge indique le sens
compté comme **SORTIE** ; inverser avec « Sens de sortie ». Pour une ligne tracée de haut en bas,
« côté gauche → côté droit » correspond à un déplacement de la gauche vers la droite de l'image.

* **Images min. du suivi** : en dessous, le passage est « ambigu » (à vérifier).
* **Marge anti-doublon** (hystérésis) : un objet qui oscille sur la ligne n'est compté qu'une fois.
* Un objet qui passe **au-delà des extrémités** du segment n'est pas compté.
* Un objet perdu près de la ligne en se dirigeant vers la sortie produit une **perte de suivi**.

## Analyse, affichage et enregistrement : trois réglages séparés

* **Analyse** (fiche caméra ou interrupteur « Analyse » en direct) : détection et comptage.
* **Affichage** (bouton Pause de la page en direct) : n'arrête ni l'analyse ni l'enregistrement.
* **Enregistrement des extraits** (fiche caméra) : quelques secondes avant/après chaque événement.
