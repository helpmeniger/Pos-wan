# Limites réelles, restrictions des sites et cadre d'utilisation

Ce document décrit honnêtement ce que VERIFIA ne fait pas, ou ne garantit pas.

## 1. Automatisation et conditions d'utilisation des sites

- VERIFIA pilote un vrai navigateur (Playwright) et lit le contenu affiché, **sans API sociale**.
  Cela ne le dispense pas des conditions d'utilisation des sites : **plusieurs plateformes, dont Facebook (Meta),
  interdisent dans leurs conditions la collecte automatisée sans autorisation écrite**. Il appartient à
  l'organisation utilisatrice de vérifier sa base légale et contractuelle (mission de vérification, accord,
  cadre réglementaire local, protection des données personnelles).
- Pour un site dont l'automatisation n'est pas permise, décocher **« automatisation autorisée »** dans
  *Administration › Sites surveillés*. La session reste ouverte mais ne défile plus : l'analyste fait défiler
  lui-même et clique sur **Analyser maintenant** pour transmettre les publications visibles,
  ou utilise l'écran **Analyse manuelle** (copier-coller du texte, import d'image).
- VERIFIA **ne masque pas** l'automatisation (pas de falsification d'empreinte de navigateur, pas de
  rotation d'adresses, pas de résolution de CAPTCHA). Un site peut donc la détecter et la refuser : c'est son droit.

## 2. Facebook en particulier

- Le balisage de Facebook est volontairement instable (classes générées, structure qui change).
  L'adaptateur repose sur des repères d'accessibilité (`role="article"`, `role="feed"`) ; il peut cesser
  de fonctionner après une mise à jour du site, sans préavis. Symptôme : 0 publication détectée.
- Défilement automatique prolongé : Facebook peut afficher un point de contrôle (« checkpoint »),
  une demande de reconnexion ou limiter le compte. VERIFIA détecte ces écrans, **suspend la session** et
  demande une intervention manuelle ; il ne tente jamais de les contourner. Des délais de défilement longs
  (8 à 15 s) et des durées de surveillance limitées réduisent ce risque sans l'éliminer.
- Les liens permanents ne sont pas toujours présents dans le fil (publications partagées, reels, publicités) ;
  le dédoublonnage se fait alors sur le texte normalisé.
- Le texte peut être tronqué (« Voir plus ») : VERIFIA ne clique pas sur les liens d'expansion.
- Les commentaires ne sont pas analysés (seuls les articles de premier niveau le sont).
- Les publications sponsorisées sont lues comme les autres ; le libellé « Sponsorisé » est repéré lorsqu'il est lisible.
- Les vidéos ne sont pas analysées (seule la vignette visible peut être capturée).

## 3. Autres plateformes

- X (Twitter), Instagram, TikTok : connexion souvent obligatoire, limitations de débit strictes,
  structure de page spécifique. Seul un adaptateur minimal pour X est fourni (`article[data-testid="tweet"]`) ;
  Instagram et TikTok relèvent de l'adaptateur générique, peu adapté. Prévoir l'analyse manuelle.
- Sites d'actualité classiques : l'adaptateur générique (`article`, `[role=article]`, classes *post / entry / story*)
  fonctionne sur la plupart des sites WordPress et assimilés, pas sur tous.

## 4. Analyse

- L'analyse est **heuristique, locale et explicable** : lexiques français/anglais, règles sur les dates,
  les sources citées, la ponctuation, les incitations au partage, comparaison d'empreintes.
  Elle ne comprend pas le sens comme un humain : ironie, langues nationales (haoussa, zarma…),
  arabe ou orthographe phonétique sont mal couverts. Les lexiques sont modifiables dans `src/Analysis/Lexicon.php`.
- Aucun modèle d'IA externe n'est appelé : aucune donnée ne quitte le poste pour l'analyse.
- « Confiance de l'analyse » mesure la quantité d'éléments sur lesquels repose l'analyse,
  **pas** la probabilité que la publication soit fausse.
- Une priorité « critique » signifie que plusieurs indices importants convergent et qu'un humain doit
  vérifier rapidement ; ce n'est pas un verdict.
- Opinion et satire déclarée ne sont jamais montées en priorité sur le seul critère du style ; une satire non
  déclarée peut en revanche être signalée comme une rumeur.

## 5. Images

- Les images sont capturées **telles qu'affichées** (capture d'écran de l'élément) : pas de métadonnées EXIF,
  qualité dépendante de l'affichage. Les EXIF ne sont disponibles que pour les fichiers importés via l'analyse manuelle.
- La comparaison porte sur les images **déjà rencontrées par VERIFIA**. La date associée est celle de la
  première détection par VERIFIA (et la date affichée par le site si elle a été lue) : **ce n'est pas la date de
  prise de vue**. Les fiches le rappellent systématiquement.
- La recherche d'occurrences sur le web (Google Lens, TinEye, Bing, Yandex) est **manuelle** : liens fournis,
  l'analyste ajoute ce qu'il trouve. Aucun résultat n'est simulé.
- Recadrages importants, miroirs, filtres forts ou incrustations peuvent faire échouer la correspondance
  perceptuelle ; des images différentes mais très proches (même lieu, même cadrage) peuvent correspondre.
- OCR : uniquement si Tesseract est installé ; sinon la fiche indique « OCR indisponible ».

## 6. Recherche de sources

- VERIFIA ne consulte aucun moteur de recherche automatiquement et **n'invente aucune source**.
  Chaque affirmation affiche « aucune recherche externe enregistrée » tant qu'un analyste n'a pas ajouté de source.
- Les sources ajoutées (URL, position, date, indépendance, méthode) et le verdict sont tracés (journal d'audit).

## 7. Capacité et performances

- Nombre de navigateurs limité par la mémoire ; jamais illimité.
- Les comparaisons de textes et d'images parcourent les 5 000 derniers textes et 50 000 dernières images
  (balayage linéaire). Mesuré : ~170 analyses/s sur un corpus de 1 000 publications,
  ~37/s sur 5 000 (voir TESTS.md). Largement suffisant pour quelques sessions ; au-delà de plusieurs centaines
  de milliers de publications, un index de voisinage (LSH) serait nécessaire.
- Sous Apache/WampServer, chaque client du flux temps réel occupe un processus ~25 s puis se reconnecte ;
  prévoir un nombre raisonnable d'écrans ouverts sur le centre d'alertes (quelques-uns, pas des dizaines).

## 8. Données personnelles et sécurité

- Aucun mot de passe de réseau social n'est demandé ni enregistré par VERIFIA. L'utilisateur se connecte
  dans la fenêtre du navigateur ; comme tout navigateur, celui-ci conserve sa connexion dans **son profil local**
  (`storage\profiles\session-N`). VERIFIA ne lit ni ne transmet ces cookies. *Tout supprimer* efface ces profils.
- Contenus conservés : texte visible, lien, auteur et date affichés, captures. Durée de conservation réglable
  (`retention_days`) pour les publications non signalées ; les publications liées à une alerte sont conservées
  jusqu'à suppression explicite.
- L'application est conçue pour un **poste local** (ou un réseau interne de confiance). L'exposer sur Internet
  exigerait HTTPS, un durcissement d'Apache et une revue de sécurité dédiée.
