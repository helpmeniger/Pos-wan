# Module « Reconnaissance des articles par photo » (vision) — 1.3.0

Reconnaît les articles **par la caméra, sans code-barres** : webcam du PC de caisse, tablette
ou smartphone connecté au réseau local. Une fois le module activé, la reconnaissance est
disponible **partout où POS Marwa attend un article** :

| Écran | Accès |
|---|---|
| Caisse (`/pos`) | bouton violet 👁 à côté du bouton caméra, ou « Reconnaître l'article » dans la fenêtre caméra |
| Prise de commande restaurant | bouton 👁 à côté de la recherche |
| Inventaire (comptage) | « Reconnaître l'article » dans la fenêtre caméra : +1 à l'article reconnu |
| Étiquettes | idem : l'article reconnu est ajouté à la liste d'impression |
| Délivrance pharmacie (Clinique) | idem : contrôle de la boîte délivrée |
| Liste des articles (recherche) | idem : ouvre la recherche de l'article reconnu |

Le module ne s'affiche pas là où l'on attend autre chose qu'un article (par exemple le retrait
d'un dépôt pressing par son ticket).

## Caméras (1.1) — Configuration → Caméras

| Type | Branchement | Comment POS Marwa la lit |
|---|---|---|
| Webcam / caméra USB | Sur le poste | Directement par le navigateur ; choix de l'appareil mémorisé (le nom est retrouvé sur chaque poste) |
| Carte d'acquisition / table de mixage | Boîtier HDMI-USB, carte PCIe, mélangeur vidéo, logiciel de caméra virtuelle | Windows la présente comme une caméra : même fonctionnement. Permet d'utiliser une caméra analogique, un enregistreur DVR/NVR (sortie HDMI) ou une régie |
| Caméra IP | Réseau local (Wi-Fi ou câble) | Le serveur lit l'**image fixe** (snapshot JPEG) ou le **flux MJPEG** et la transmet au navigateur. Caméras uniquement RTSP : passer par **go2rtc** (gratuit) installé sur le serveur |

- **Identifiants des caméras IP :**
  - chiffrés en base ;
  - jamais envoyés au navigateur ;
  - adresses limitées au réseau local, sauf autorisation explicite ;
  - pas de redirection suivie.
- **Bouton « Tester » :** il affiche l'image en direct, avec la taille et le temps de réponse.
- **Caméras en caisse :** une caméra cochée « Caisse et reconnaissance » apparaît dans le choix des caméras de la fenêtre caméra (scan des codes-barres et « Reconnaître »).
- **Caméra IP sans HTTPS :** une caméra IP fonctionne aussi en caisse sans HTTPS.

## Surveillance des articles (1.1) — Gestion des articles → Surveillance des articles

**Ce que fait l'écran :**
- Il analyse **en continu et en temps réel** chaque caméra cochée « Surveillance ».
- Le calcul se fait sur le poste qui affiche l'écran, qui doit rester ouvert ; l'écran est
  maintenu allumé.

**Comment un passage est compté :**
1. Le fond de l'image est appris ; ce qui bouge est isolé puis suivi d'une image à l'autre.
2. Un passage est compté selon le mode choisi :
   - **Ligne rouge** : quand un objet franchit la ligne. Le sens est indiqué par la flèche
     « SORTIE » ; le sens inverse compte comme « retour ». Un objet qui hésite sur la ligne
     n'est pas compté plusieurs fois.
   - **Zone** : chaque objet qui entre et reste dans la zone est compté une fois.
3. À chaque passage, une vignette de l'objet est prise et l'article est reconnu (si les
   articles ont été appris). Le passage est enregistré comme « identifié », « incertain » ou
   « non identifié ».

**Tracé :** la ligne se trace en cliquant deux points sur l'image, la zone en faisant glisser
un rectangle. Bouton « Sens » pour inverser, puis « Enregistrer le tracé ».

**Rapport « Passages vus / ventes »** (fin de journée ou toute période) :
- **Par article :**
  - passages vus (dont sûrs) et retours ;
  - unités vendues ;
  - sorties de stock (toutes, par type) ;
  - passages **justifiés** par une vente du même article à ± N minutes (20 par défaut) ;
  - passages **sans vente**, et écart vus − vendus ;
  - appréciation : conforme / à vérifier / à vérifier en priorité.
- **Couverture des caméras :** part des articles vendus effectivement vue par une caméra. Si
  elle est faible, les écarts ne sont pas fiables.
- **Répartition par heure.**
- **Analyse rédigée automatiquement :**
  - articles à vérifier en priorité ;
  - heures sensibles ;
  - passages non identifiés ;
  - sorties de stock autres que des ventes.
- **Revue des passages :** confirmer, **corriger** (la vignette est alors apprise : la
  reconnaissance s'améliore), ou **ignorer** (main, personne, reflet). Exports CSV et PDF.
- **Articles comparés :** par défaut, ceux que la reconnaissance connaît ou qu'une caméra a
  vus. La case « tous les articles du magasin » les inclut tous.

**Conservation :** aucune vidéo n'est enregistrée, seulement une vignette de 112 px par
passage, effacée après 30 jours (réglable).

### Ce que la surveillance ne fait pas (limites honnêtes)
- **Pas une preuve de vol.** Un passage « sans vente » signale une situation à vérifier
  (vignette, comptage du rayon) ; il peut venir d'une erreur de détection ou de
  reconnaissance.
- **Détection par le mouvement :**
  - plusieurs articles collés passent pour un seul ;
  - une personne ou un chariot peut être compté : il faut le revoir et l'ignorer ;
  - un article caché (sac, poche) n'est pas vu.
  Placez la caméra au-dessus du passage, ligne perpendiculaire au mouvement, éclairage stable.
- **Reconnaissance :** elle a la même précision que celle mesurée plus bas. Lors de l'essai
  automatique, un jus a été pris pour un lait d'une autre marque ; le passage est resté
  « incertain ».
- **Caméras IP :** le comptage par ligne a besoin d'au moins 5 images/s pour un passage rapide.
  La lecture d'images fixes par le serveur est réglable de 1 à 10 images/s ; le débit réel dépend de la caméra (non mesuré sur une vraie caméra).
- **Vie privée :** filmer des personnes est encadré par la loi. Informez le personnel et la
  clientèle (affichage), limitez les caméras aux zones utiles et respectez la réglementation
  sur les données personnelles en vigueur au Niger.

## Isolement de l'article et ajout automatique (1.2)

Objectif : que la caméra s'intéresse **à l'article**, pas au mur, au comptoir ni aux objets
posés autour, et qu'en caisse chaque article reconnu avec certitude soit **ajouté tout seul**,
puis que l'article suivant soit reconnu à son tour.

### Fond appris (recommandé à la caisse)

- Dans la fenêtre caméra : **« 🧱 Apprendre le fond »**, le cadre étant **vide** (comptoir sans
  article). Une image du fond est gardée sur ce poste, pour cette caméra (pas sur le serveur).
- Ensuite, seule la partie de l'image qui **diffère du fond** est analysée :
  - compensation des changements de lumière ;
  - les **ombres** de l'article sont ignorées ;
  - l'article est **détouré** (fond rendu transparent), recadré, posé sur un gris neutre,
    puis comparé aux photos apprises de la même façon.
- Cadre vide : rien n'est analysé, rien n'est ajouté (« Posez un article dans le cadre »).
- Si la caméra bouge ou si la lumière change beaucoup, POS Marwa le signale : réapprenez le fond.

### Pendant l'apprentissage

- Aperçu en direct de l'article **détouré sur fond transparent** (damier) : vous voyez ce qui
  sera réellement appris.
- Chaque photo produit **deux empreintes** : l'article détouré et l'image entière.
- Case **« Isoler l'article »** (cochée par défaut) et bouton « Apprendre le fond » aussi
  dans la fenêtre d'apprentissage.
- Si une photo ressemble davantage à un autre article, un avertissement s'affiche.

### En caisse : « En continu » + « Ajout automatique »

1. Cochez **En continu** et **Ajout automatique** (mémorisés sur ce poste).
2. Posez un article : il doit être reconnu **sur deux images de suite** avec une note et un
   écart avec le 2ᵉ article suffisants ; il est alors **ajouté au panier**.
3. Retirez l'article : le suivant est reconnu à son tour. (Sans fond appris, POS Marwa attend
   que l'image change nettement.)
4. **↶ Annuler** retire l'unité ajoutée. L'annulation est enregistrée.
5. Pas sûr : POS Marwa **propose** les articles les plus proches et le caissier touche le bon.

Garde-fous :
- seuils propres aux articles détourés (Réglages : `0,45` et écart `0,25`, choisis par mesure
  pour **aucun ajout faux** sur les essais) ;
- écart exigé **doublé** pour les paires d'articles corrigées au moins 2 fois en 90 jours et
  pour les articles annulés au moins 2 fois ;
- les ajouts automatiques ne sont **jamais appris** (une erreur ne se renforce pas) ;
- le code-barres reste prioritaire.

### Mesures 1.2 (honnêtes)

**Caisse simulée** (12 articles dessinés dont des sosies : Cola 33/50 cl, Cola/Cola Zéro,
Orange/Mangue ; lumière variable ; objets parasites autour ; calcul dans Chromium avec le code
livré) :

| Essai | Bon article en 1ᵉʳ | Ajouts automatiques justes | Ajouts automatiques faux |
|---|---|---|---|
| 1.1 (image entière) | 75 % / 83 % | — | — |
| 1.2 avec fond appris | 86 % / 96 % | 32 / 38 | **0** |
| 1.2, appris par photos, fond appris en caisse | 92 % | 21 | **0** |
| Comptoir vide (40 images) | — | — | **0** |

- Avec des objets parasites dans le cadre : 79 % → 92 % au 1ᵉʳ rang.
- Les erreurs restantes sont des sosies (Cola 33/50 cl…) : ils sont **proposés**, pas ajoutés.

**Essai de bout en bout** (caisse réelle de POS Marwa, caméra simulée, 3 articles appris par
4 photos, fond appris) : Mangue et Cola 33 cl ajoutés automatiquement, **aucun ajout faux**,
rien sur le comptoir vide, « Annuler » retire bien la ligne. Le Cola 33 cl, très proche du
Cola 50 cl, n'a été ajouté qu'une fois sur trois présentations ; les autres fois il a été
proposé.

**Photos réelles en rayon** (banc public, article tenu à la main devant des étagères) : le
détourage d'après les bords de l'image, **sans fond appris**, n'améliore pas la reconnaissance
(34,7 % → 31,0 %). Il n'est donc **pas** utilisé en caisse sans fond appris ; il sert seulement
à l'apprentissage par photos sur fond uni.

**Limite importante mesurée :** un article **jamais appris** qui ressemble à un article appris
peut être ajouté **sous le nom de l'article appris** (simulation avec 3 à 6 articles appris
sur 12 présentés : 8 à 16 ajouts faux sur 36 à 54 présentations d'articles inconnus). Deux
contre-mesures ont été essayées (ressemblance brute, dispersion propre à chaque article) :
elles ne séparent pas ces cas de façon fiable et n'ont pas été retenues. Règle pratique :
**activez l'ajout automatique seulement quand tous les articles posés devant cette caméra
ont été appris**, sinon gardez le mode « propose, le caissier confirme ». Surveillez les
annulations dans les statistiques du module.

Taille apparente de l'article (33 cl / 50 cl) : implémentée, mesurée **sans gain** (la
distance à la caméra varie) ; désactivée.

## Contrôle rayon → caisse (1.3) — Gestion des articles → Contrôle rayon → caisse

Objectif : quand une caméra voit un article **quitter son emplacement** sur l'étagère, vérifier qu'il
est ensuite **encaissé** (ou remis en place). Sinon, **demander une vérification**, sans accuser personne.

### 1. Donner un rôle à chaque caméra (Configuration → Caméras)

| Rôle | Où la placer | Ce qu'elle signale |
|---|---|---|
| **Étagère** | Face au rayon, d'assez près, sans passage de clients entre la caméra et le rayon | Un article **pris** ou **remis** à un emplacement tracé |
| **Caisse** | Au-dessus du comptoir (zone tracée sur le comptoir), rattachée à un **poste de caisse** | L'article reconnu sur le comptoir : « vu en caisse » |
| **Sortie** | Au-dessus de la porte, ligne rouge en travers du passage | Un article reconnu qui **sort** |
| Passage | (fonctionnement 1.1 / 1.2 inchangé) | Passages comptés, comparés aux ventes en fin de journée |

Chaque caméra est rattachée à une **boutique** (directement, ou par sa caisse) : une vente d'une autre
boutique ne justifie jamais une prise.

### 2. Tracer les emplacements de l'étagère (écran Surveillance)

- **Tracer un emplacement** : faites glisser un rectangle autour d'un article, ou d'une rangée d'articles
  identiques. Nommez-le et, si possible, indiquez **l'article attendu** : la prise est alors nommée par
  l'emplacement (plan de rayon), sans dépendre de la reconnaissance par photo.
- Sans article indiqué, la reconnaissance nomme l'article **seulement si elle est sûre** ; sinon l'article
  reste « non identifié » (aucun rapprochement n'est fait sur une supposition).
- **Rayon plein : mémoriser** : à faire quand le rayon est rempli. Un article qui revient à sa place est alors
  compté « remis », même après un redémarrage de l'écran.
- Pendant la surveillance, chaque emplacement est coloré : vert (calme), orange (touché), rouge (changé).
  L'état du rayon s'affiche : « Apprentissage », « Mouvement devant le rayon », « Rayon caché : en attente »,
  « Rayon surveillé ».

**Comment une prise est détectée.** Ce n'est pas un suivi d'objet : l'écran mémorise l'image du rayon quand
plus rien ne bouge. Une main passe : il attend. Il attend aussi tant qu'une personne se tient devant le rayon,
ou qu'un bras reste posé sur l'emplacement. Quand tout est de nouveau immobile (environ 1,5 s), chaque
emplacement que le mouvement a atteint est comparé à son état d'avant :
- il a nettement changé en s'éloignant de l'état « rayon plein » → **pris** ;
- il s'en rapproche → **remis** (article remis, ou rayon réapprovisionné).

Les écarts sont mesurés **en couleur** : une main de même luminosité qu'un article se distingue par sa
teinte. Les variations de lumière de toute l'image sont compensées. Si la scène change durablement (caméra
déplacée), le rayon est réappris au bout d'environ 20 s, sans événement.

### 3. Le suivi de chaque article pris

| Ce qui se passe | Résultat |
|---|---|
| Vente du même article, même boutique, après la prise | **Encaissé** : suivi clos, vente liée. Une unité vendue ne clôt qu'**un** suivi |
| L'article revient à son emplacement | **Remis en rayon** : suivi clos |
| La caméra de caisse le reconnaît sur le comptoir | **Vu en caisse** : la vente est attendue |
| Délai dépassé (15 min par défaut) sans vente ni remise | **Vérification demandée** (« À vérifier ») |
| Vu en caisse mais pas encaissé dans le délai | Vérification demandée, **priorité** |
| La caméra de sortie le voit sortir sans encaissement | Vérification demandée **immédiatement**, priorité |
| Vente enregistrée après l'alerte (file d'attente) | Clos « encaissé après l'alerte » (l'alerte reste tracée) |
| Article non identifié (reconnaissance incertaine) | Vérification de **faible priorité** (désactivable) |
| Article vu sur le comptoir alors qu'aucun rayon filmé ne l'a vu partir | Suivi ouvert depuis la caisse |
| Article vu à la sortie, sans prise observée | Justifié par une vente récente non encore rapprochée ; sinon à vérifier |

Une **tolérance de 60 s** admet une vente enregistrée juste avant la prise (horloges, article posé sur le
comptoir après le scan).

### 4. Les alertes

- **Écran Surveillance** : panneau « Vérifications demandées », actualisé toutes les 5 s, avec un **signal
  sonore** pour chaque nouvelle alerte (désactivable) et des boutons de vérification.
- **Cloche** de la barre supérieure (responsables ayant le droit `vision.watch`).
- **Page Contrôle rayon → caisse** : à vérifier, en cours, clos automatiquement, vérifiés. Pour chaque
  article : vignettes (au rayon, en caisse, à la sortie), parcours observé, vente liée.
- **Vérifier** = choisir le résultat : **Encaissé**, **Remis**, **Fausse alerte** (avec la raison), **Perte
  confirmée** (avec une note, par exemple le comptage du rayon). Chaque vérification est enregistrée et auditée.
- **Fiabilité par caméra** : part des articles nommés, encaissés, remis, alertes, **part des fausses alertes**,
  pertes, délai moyen jusqu'à la vente. Une caméra qui produit beaucoup de fausses alertes est mal placée ;
  un délai moyen proche du délai réglé indique qu'il faut l'allonger.
- **Rappel discret au caissier** (désactivé par défaut) : un article vu sur le comptoir de **sa** caisse
  depuis plus de 60 s (réglable) et pas encore encaissé s'affiche dans un petit encadré « À vérifier avant de
  clôturer la vente ». Rien n'est ajouté au panier automatiquement.

### 5. Réglages (Configuration → Caméras → Contrôle rayon → caisse)

| Réglage | Par défaut |
|---|---|
| Délai pour passer en caisse | 15 min (1 à 120) |
| Alerte immédiate à la sortie | oui |
| Signaler les articles non identifiés | oui (faible priorité) |
| Rappel discret au caissier, après | non, 60 s |

### 6. Ce que le contrôle ne fait pas (limites honnêtes)

- **Une alerte n'est pas une preuve de vol.** Un article peut avoir été posé ailleurs, encaissé sous un autre
  article (ou avec un autre code-barres), mal reconnu, ou caché par une personne. Vérifiez avec discrétion,
  sans accuser un client ni un employé.
- **Quantité** : une prise correspond à **un** changement d'emplacement. Deux articles pris d'un coup sur le
  même emplacement comptent pour une prise.
- **Réapprovisionnement** : un rayon rempli par un employé est compté « remis » et peut clore un suivi en
  cours sur cet emplacement. Mémorisez à nouveau « rayon plein » après une mise en rayon.
- **Emplacements sans article indiqué** : l'article dépend de la reconnaissance par photo, dont la précision
  est mesurée plus bas ; un article non reconnu avec certitude n'est pas nommé.
- **Caméra mal placée** : des clients qui passent constamment entre la caméra et le rayon gardent l'écran en
  attente (« rayon caché ») et peuvent faire manquer des prises.
- **Calcul sur le poste** : l'écran de surveillance doit rester ouvert ; aucune détection quand il est fermé.
- **Vie privée** : aucune vidéo n'est enregistrée, seulement des vignettes de l'article, effacées après la
  durée de conservation. Filmer des personnes est encadré par la loi n° 2017-28 relative à la protection des
  données à caractère personnel : informez le personnel et la clientèle (affichage), limitez les caméras aux
  zones utiles.

### 7. Essais réalisés (1.3)

- **Moteur d'étagère** (`tests/js/shelf.test.js`, 20 vérifications sur images de synthèse) : prise,
  main qui touche sans prendre, pris puis remis, deux prises successives, voisin non touché, personne
  immobile devant le rayon (attente puis prise quand elle part), lumière qui change, caméra déplacée
  (réappris sans événement, puis nouvelle prise détectée), prise d'une partie de l'emplacement, article
  plus grand que l'emplacement tracé, référence « rayon plein » restaurée, main de même luminosité mais d'une
  autre teinte, main qui s'arrête sur l'article.
- **600 essais aléatoires** (bruit, position et couleur de la main, lumière) : 200 prises, 200 « touché sans
  prendre », 200 remises → aucune erreur. Ce sont des images de synthèse.
- **Suivi rayon → caisse** (`tests/vision_parcours.php`, sur de vraies ventes) : encaissé, une vente pour une
  seule prise, alerte au délai puis vente tardive, remis, vu en caisse sans vente (priorité), reconnaissance
  incertaine, sortie immédiate, sortie sans prise observée, autre boutique, rappel au caissier, vérification,
  droits (le caissier ne voit ni ne clôt les alertes), cloche.
- **Essai de bout en bout** dans Chromium avec une **vidéo de rayon simulée** donnée comme webcam (trois
  articles, une main de même luminosité que l'article prend celui du milieu et s'arrête un instant dessus) :
  une seule prise, sur le bon emplacement, article nommé par l'emplacement, vignette montrant l'article avant
  la prise, suivi ouvert ; délai réglé à 1 min pour l'essai → alerte affichée 62 s plus tard dans le panneau
  en direct et sur la page Contrôle ; aucune erreur dans le navigateur. Le premier essai avait échoué
  (main invisible en niveaux de gris) : c'est ce qui a conduit à comparer en couleur.
- **Non mesuré : une vraie caméra dans une vraie boutique.** La fiabilité réelle dépend de l'emplacement de la
  caméra, de l'éclairage et des clients. Commencez par une seule étagère, suivez la part des fausses alertes
  sur la page Contrôle, puis étendez.

## Installation

1. Le dossier `addons/vision` est fourni avec POS Marwa 2.1.0 (ou copiez-le dans `addons/`).
2. **Configuration → Modules complémentaires → Reconnaissance des articles par photo → Installer.**
3. Droits accordés automatiquement (modifiables dans Rôles & permissions) :
   - `vision.use` (reconnaître) : propriétaire, gérant, magasinier, caissier, pharmacien,
     serveur, accueil clinique ;
   - `vision.teach` (apprendre, régler) : propriétaire, gérant, magasinier.

Désactiver le module masque la reconnaissance partout ; les photos apprises sont conservées.

## Apprendre les articles (Gestion des articles → Reconnaissance par photo)

- **Apprendre** sur la ligne d'un article :
  - prenez **5 à 10 photos** dans le cadre jaune (barre Espace) : face, dos, côtés, de près,
    de loin ;
  - sur un téléphone sans HTTPS, utilisez **Photos…** (appareil photo ou galerie).
- **Apprendre les photos des fiches** : utilise les photos déjà présentes sur les fiches
  articles. C'est un bon point de départ, mais il vaut moins que des photos prises à la caisse.
- **Articles qui se ressemblent** : liste les paires faciles à confondre, pour les
  photographier sous l'angle qui les distingue.
- **Tester la reconnaissance** : présentez un article ; rien n'est ajouté ni appris.
- Chaque ligne indique le nombre de photos (vert : 5 et plus). Les vignettes permettent
  d'oublier une mauvaise photo.

## En vente

1. Touchez **👁** (ou « Reconnaître l'article » dans la fenêtre caméra) avec l'article dans le
   cadre.
2. POS Marwa propose les **4 articles les plus ressemblants**, avec leur photo, leur prix et
   leur stock. Il indique « Article reconnu » quand il est sûr.
3. Touchez le bon article : il est ajouté à la vente, exactement comme un scan de code-barres
   (stock, prix, lots et droits inchangés).
4. Si aucun ne convient, tapez son nom dans « Autre article ». La photo est **apprise** avec le
   bon article : la reconnaissance s'améliore à chaque vente.

Options :
- **« En continu »** (PC ou téléphone avec accélération graphique) : la caméra analyse sans
  cesse ce qui est présenté et propose dès qu'elle est sûre. Pour proposer à nouveau, l'article
  doit d'abord sortir du cadre.
- **Ajout automatique** (Réglages, désactivé par défaut) : quand la ressemblance et l'écart
  avec le 2ᵉ article dépassent les seuils, l'article est ajouté sans toucher l'écran.
  Les ajouts automatiques ne sont pas appris, pour éviter qu'une erreur ne se renforce.
- Le **code-barres reste prioritaire** : s'il est visible, il est lu comme d'habitude.

## Fonctionnement technique

- **Calcul local, sans Internet**, dans le navigateur de chaque appareil :
  - le réseau de neurones MobileNet v2 (Google, licence Apache 2.0) est livré dans
    `public/modele/` (8,6 Mo, fragments de 1 Mo) ;
  - le moteur TensorFlow.js 4.22 est dans `public/tf.min.js`.
- Il est téléchargé depuis le serveur POS Marwa à la première utilisation, puis gardé en
  cache par le navigateur.
- **Chaque photo devient une empreinte de 512 octets.** Le serveur conserve les empreintes et
  une vignette de 112 px, jamais les photos complètes.
- **Comparaison :**
  - similarité cosinus entre empreintes ;
  - centrage par la moyenne des photos apprises, qui retire le fond et l'éclairage communs ;
  - note d'un article = meilleure photo, renforcée par la deuxième.
- **Accélération graphique (WebGL)** utilisée quand elle existe ; sinon calcul sur le
  processeur, plus lent (quelques secondes), et pas de mode continu.
- **Compatible avec la politique de sécurité de POS Marwa** : aucune évaluation de code n'est
  autorisée.

## Tables

| Table | Contenu |
|---|---|
| `vision_refs` | Empreintes apprises : article, 512 octets, nature (image entière ou article détouré), taille apparente, origine (photo, fiche, vente), vignette, auteur, date |
| `vision_log` | Reconnaissances : proposé, choisi, note, écart, résultat (confirmé, corrigé, automatique, annulé, aucun) |
| `vision_cameras` | Caméras : type, rôle (passage, étagère, caisse, sortie), boutique, lieu, caisse filmée, tracé |
| `vision_events` | Passages, articles vus, pris ou remis en rayon (vignette de l'article, jamais de vidéo) |
| `vision_slots` | Emplacements tracés sur l'image d'une caméra d'étagère, article attendu |
| `vision_journeys` | Suivi « pris en rayon → encaissé / remis / à vérifier », vérification et note |

## Précision mesurée (sans complaisance)

**Banc d'essai :** « Grocery Store Dataset » (photos publiques prises au téléphone en
magasin, avec la main, le rayon et des reflets).
- 31 articles emballés très proches : laits, jus, yaourts, souvent de la même marque.
- 248 photos de test jamais apprises.
- Calcul fait dans Chromium avec le code livré.

| Photos apprises par article | Bon article proposé en 1ᵉʳ | Bon article parmi les 4 propositions | Bonne marque en 1ᵉʳ |
|---|---|---|---|
| 1 | 20 % | 42 % | 49 % |
| 3 | 28 % | 61 % | 65 % |
| 5 | 34 % | 65 % | 71 % |
| 10 | 35 % | 73 % | 73 % |
| 15 | 37 % | 75 % | 75 % |

**Lecture :**
- Les erreurs sont surtout des confusions entre articles de la même marque, comme deux laits
  Arla presque identiques.
- Plus l'article a de photos (apprentissage pendant les ventes), plus il figure souvent parmi
  les 4 propositions. La 1ʳᵉ place progresse peu au-delà de 5 photos.
- C'est pourquoi le module **propose et le caissier confirme**, et l'ajout automatique est
  désactivé par défaut. Les seuils par défaut (0,80 / 0,06) ne sont presque jamais atteints :
  sur ce banc, aucune proposition n'a dépassé 0,6.
- **Non mesuré :** les conditions réelles d'une caisse (même caméra, même éclairage, fond uni,
  article bien cadré) sont plus favorables que ces photos en rayon. Faites l'essai avec vos
  articles grâce à « Tester la reconnaissance » et suivez le taux affiché sur la page du module.

**Autres méthodes essayées sans gain notable :**
- regroupements max, GeM, R-MAC ;
- résolution 320 px ;
- normalisation en racine signée ;
- blanchiment diagonal.

Toutes donnent 31 à 35 % au 1ᵉʳ rang. Le modèle actuel est le meilleur compromis
vitesse / précision disponible hors ligne.

## Limites (à connaître)

- **Pas de reconnaissance « universelle ».** Le module reconnaît les articles **que vous lui
  avez appris** ; un article jamais photographié ne peut pas être reconnu. Pour qu'il le soit,
  il suffit de le corriger une fois en vente, ou de l'apprendre.
- **Les articles presque identiques** (même emballage, parfum ou taille différents) peuvent
  être confondus. Le caissier confirme ; le code-barres reste plus sûr pour ces articles.
- **Les produits en vrac sans emballage** (fruits, légumes) sont reconnus par leur aspect
  général, avec une précision moindre.
- **Les performances dépendent de l'appareil** : rapide avec accélération graphique, plus lent
  sur les PC très anciens.
- **Caméra en direct sur smartphone :** HTTPS est exigé par les navigateurs (voir
  `docs/DEMARRAGE_WINDOWS.md` §7). Sinon, la photo de l'article est utilisée.
