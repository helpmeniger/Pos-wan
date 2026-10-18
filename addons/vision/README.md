# Module « Reconnaissance des articles par photo » (vision) — 1.0.0

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
| `vision_refs` | Empreintes apprises : article, 512 octets, origine (photo, fiche, vente), vignette, auteur, date |
| `vision_log` | Reconnaissances : proposé, choisi, note, écart, résultat (confirmé, corrigé, automatique, aucun) |

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
