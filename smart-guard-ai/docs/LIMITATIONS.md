# Limitations connues

## Physiques (inhérentes à la vidéo)

* Une caméra **ne peut pas savoir** si un article a été vendu, déplacé, consommé, retourné ou volé :
  le système enregistre des faits (franchissements) et des hypothèses (rapprochement caisse).
* Article caché dans un sac, sous un vêtement, porté hors champ ou masqué par une personne :
  non vu, donc non compté (au mieux « perte de suivi »).
* Plusieurs articles tenus ensemble : souvent détectés comme **un seul objet** ; la quantité d'un
  événement vaut 1.
* Personnes qui se croisent, foule : risques de pertes de suivi et d'identités échangées ;
  ces cas sont signalés comme incertains plutôt que comptés.
* Le même article visible par deux caméras produit deux observations (pas de fusion multi-caméras).
* Le comptage d'articles **sur les étagères** ou de déplacements entre rayons n'est pas fiable
  avec ce prototype : il est conçu pour une **ligne de sortie** dans une scène contrôlée.

## Techniques (prototype)

* **Détecteur par défaut (mouvement)** : il détecte tout ce qui bouge, y compris les personnes ;
  la reconnaissance décide ensuite si l'objet ressemble à un produit, sinon « non identifié ».
  Une personne qui franchit la ligne sans article peut donc créer un événement « objet non
  identifié » à vérifier. Remèdes : cadrer la caméra sur la zone où passent les articles
  (comptoir, tapis, sortie de caisse), régler les niveaux d'alerte, ou utiliser YOLO
  (facultatif, licence AGPL) qui ignore les personnes.
* **Extracteur d'empreintes classique** (sans ONNX) : distingue bien des emballages de couleurs
  différentes, **moins bien** des emballages proches. Installer l'extracteur ONNX améliore la
  robustesse ; pour des produits très proches, un modèle supervisé spécialisé sera nécessaire.
* Pas de ré-entraînement de réseau dans cette version (méthode par similarité). Le pipeline
  supervisé (annotation de boîtes, entraînement, export) est prévu mais non implémenté ; la base
  et l'interface des versions le permettent (`ai_models.method`).
* Une image fixe testée avec le détecteur de mouvement est analysée **en entier** (le mouvement
  n'existe pas sur une photo).
* Extraits vidéo : séquences d'images JPEG à la fréquence d'analyse (lecture image par image dans
  le navigateur), pas de fichier MP4.
* Aperçu en direct : images JPEG rafraîchies (~2/s), pas de vidéo fluide ; WebSocket non utilisé
  (rafraîchissement périodique suffisant sur réseau local, plus simple sous WampServer).
* `camera_zones` (polygones d'analyse/exclusion) : table prête, **pas encore exploitée** par le
  service IA ni éditable dans l'interface (remplacement : cadrage de la caméra et segment de ligne).
* ONVIF : pas de découverte automatique ; saisir l'URL RTSP (obtenue avec ONVIF Device Manager).
* Notifications : internes uniquement. E-mail, SMS, WhatsApp, push : interface
  `NotificationChannel` prête, aucun fournisseur (souvent payant) intégré.
* Un service IA par ordinateur ; plusieurs sites = une installation par site (la base gère déjà
  plusieurs magasins et l'interface le changement de magasin).
* Les scripts `.bat` n'ont pas été exécutés sous Windows pendant le développement (voir TESTS.md).
* Le rapprochement est une **heuristique** (produit + fenêtre de temps + quantité) : achats
  groupés, horloges décalées et articles non visibles provoquent des erreurs ; il est présenté
  comme tel dans l'interface et les rapports.
