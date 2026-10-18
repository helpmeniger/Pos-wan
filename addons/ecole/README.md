# Module « Établissement » (ecole) — 1.0.0

Gestion complète d'une école, d'un collège / lycée ou d'une université : élèves ou étudiants,
personnel et enseignants, inscriptions et frais de scolarité, caisse, comptabilité, salaires,
notes, bulletins, appel et pointage par caméra avec reconnaissance faciale.

Requiert POS Marwa 2.3.0. Activation : Configuration → Modules complémentaires → « Établissement »,
puis Paramètres → Mode de fonctionnement → **Établissement scolaire / université**.

## Mode Établissement

Une fois ce mode choisi :
- **« Ouvrir la caisse »** ouvre la caisse de l'établissement (`/ecole/caisse`). La caisse
  commerciale (`/pos`) y renvoie aussi.
- **L'accueil et le tableau de bord** sont ceux de l'établissement.
- **Menus commerciaux masqués :** ventes, articles, stock, achats, fournisseurs, clients,
  rapports de vente, surveillance des articles. Les groupes de menu deviennent « Établissement »,
  « Caisse », « Finances » et « Configuration ».
- **Restent disponibles :** les sessions de caisse (ouverture / clôture), les dépenses et les
  caméras.
- **Rien n'est supprimé :** revenir au mode Commerce réaffiche tout. L'option « Afficher aussi
  les menus des autres activités » existe toujours.

## Fonctions

| Domaine | Ce que fait le module |
|---|---|
| Année scolaire | Années, trimestres ou semestres (dates, clôture qui verrouille les notes), changement d'année |
| Classes | Cycle, salle, capacité, titulaire, frais d'inscription, scolarité et nombre de tranches ; programme (matières, coefficients, crédits ECTS, enseignant) |
| Élèves / étudiants | Fiche complète (parent / tuteur, informations médicales), photo par caméra ou fichier, matricule automatique, carte scolaire imprimable (code-barres), listes CSV / PDF |
| Personnel | Enseignants, direction, administration, service ; salaire ; carte professionnelle |
| Caisse | Recherche par nom, matricule, téléphone du parent, carte scannée ou visage ; échéancier ; paiement partiel ou d'échéances choisies ; espèces, mobile money, chèque… ; reçu ticket 80 mm ou A4 ; inscription d'un nouvel élève avec premier versement |
| Finances | Reçus annulables avec motif (contre-passés en caisse), impayés échus (relances, exports), salaires mensuels (complément possible, annulation), comptabilité de la période (recettes par mode / type / classe, salaires, dépenses, solde) |
| Notes | Évaluations (barème, coefficient), saisie rapide au clavier, absence (« abs ») ignorée ou comptée 0 au choix |
| Bulletins | Moyennes par matière et générale pondérées, rang (ex æquo), statistiques de classe, mention, distinction, appréciation du conseil, conduite, décision, absences et retards ; une page A4 par élève, pour un élève ou toute la classe, par période ou pour l'année |
| Présences | Appel par classe et du personnel, clôture de la journée (non pointés = absents), relevé des absences et retards |
| Pointage caméra | Reconnaissance faciale des élèves et du personnel à l'entrée, à la sortie ou dans un couloir : arrivées, retards, départs, avec la fiche de la personne affichée |

## Calcul des moyennes

- **Moyenne d'une matière** = Σ (note ramenée sur 20 × coefficient de l'évaluation) / Σ coefficients.
- **Moyenne générale** = Σ (moyenne de matière arrondie × coefficient de la matière) / Σ coefficients
  des matières évaluées. Elle est calculée sur les moyennes arrondies, comme la colonne
  « Moy. × coef. » imprimée, pour que le bulletin se vérifie à la main.
- **Moyenne annuelle** = moyenne des périodes évaluées.
- **Rang :** les ex æquo ont le même rang ; le rang suivant est décalé.

## Reconnaissance faciale : fonctionnement

- **Tout se calcule dans le navigateur du poste de pointage, sans Internet.** La bibliothèque
  face-api (MIT) et ses modèles (≈ 13 Mo) sont fournis avec le module.
  - Détection du visage : SSD MobileNet.
  - 68 points de repère.
  - Empreinte faciale de 128 nombres (ResNet-34).
- **Le serveur ne garde que les empreintes**, pas les photos de visage. Les vignettes des
  passages sont effacées après le délai choisi (30 jours par défaut).
- **Inscription du visage :** sur la fiche, 2 ou 3 photos de face. Chaque photo est contrôlée :
  - un seul visage, assez grand, net, de face (pas de profil, tête pas trop penchée) ;
  - ressemblance avec les autres photos de la même fiche ;
  - pas de ressemblance forte avec une autre personne déjà enregistrée (doublon ou erreur de
    fiche) ;
  - une photo refusée reste la photo de la fiche mais ne sert pas au pointage.
- **Décision au pointage — une personne n'est pointée que si :**
  1. son visage est de bonne qualité sur l'image ;
  2. sur au moins 3 des 5 dernières images de ce visage, elle est la plus proche, avec une
     distance sous le seuil (0,50) **et** un écart d'au moins 0,05 avec la 2ᵉ personne la plus
     proche ;
  3. l'empreinte moyenne de ces images confirme encore la même personne.
- **Visage inconnu ou douteux :** après 6 bonnes images sans certitude (ou 15 images dans tous
  les cas), il est enregistré comme « inconnu » avec sa vignette, dans « Passages à vérifier ».
  On l'attribue alors à la bonne personne (elle est pointée à l'heure du passage) ou on
  l'ignore. **Le système n'attribue jamais un passage au hasard.**
- **Suivi :** une personne déjà pointée n'est pas renvoyée au serveur avant 2 minutes. Si une
  personne en remplace une autre au même endroit entre deux analyses, un nouveau suivi commence.
- **Apprentissage prudent :** quand la reconnaissance est très sûre (distance ≤ 0,40, écart
  ≥ 0,15), une empreinte du jour est ajoutée, au plus une par personne et par jour, et
  seulement si l'utilisateur a le droit « données faciales ». Cela suit les changements
  d'apparence. Les photos d'inscription ne sont jamais remplacées par ces empreintes (12
  empreintes au plus par personne).
- **Caméras utilisables :** webcam ou caméra USB du poste, carte d'acquisition, ou caméra IP
  déclarée dans le module Reconnaissance (Configuration → Caméras), relayée par le serveur
  sans exposer ses identifiants.

## Résultats mesurés (et leurs limites)

**Mesure hors ligne avec les modèles fournis :**
- **Échantillon :** 8 personnes, 36 visages détectés (photos d'acteurs en niveaux de gris, de
  face ou de trois quarts), 2 photos d'inscription par personne, 84 combinaisons dont 2
  personnes « inconnues » à chaque fois.
- **Seuil 0,50 :** 95,2 % des visages connus reconnus correctement, 0 % attribués à une
  mauvaise personne, 0 % des inconnus acceptés à tort.
- **Seuil 0,55 :** 98,4 % reconnus.
- **Distances :** même personne médiane 0,43 et maximum 0,59 ; personnes différentes minimum
  0,61.

**Essai de bout en bout dans Chromium**, caméra simulée par une vidéo (une élève inscrite avec
2 photos, un enseignant avec 2 photos, une personne inconnue) :
- l'élève et l'enseignant ont été reconnus et pointés (distances 0,34 et 0,45) ;
- l'inconnu a été classé « inconnu » à chaque passage ;
- aucun double pointage ;
- la photo de profil proposée à l'inscription a été refusée.

**Ces chiffres ne garantissent pas le même résultat chez vous.** L'échantillon est petit et
ne représente ni vos élèves, ni votre éclairage, ni vos caméras. Les facteurs connus de dégradation sont :
- un contre-jour, un éclairage faible ou une caméra de mauvaise qualité ;
- un visage trop loin ou de profil ;
- un masque, un voile couvrant le visage, des lunettes de soleil ;
- des jumeaux, ou des enfants qui grandissent (refaire les photos chaque année) ;
- un ordinateur lent : en dessous de 1 image par seconde, l'écran l'indique et la personne
  doit marquer un temps d'arrêt devant la caméra.

**Aucun système de reconnaissance faciale n'est infaillible.** Ce module est conçu pour se
tromper le moins possible : en cas de doute il ne pointe pas, et il garde le passage pour
vérification. Il **ne détecte pas** une photo ou une vidéo présentée devant la caméra
(pas d'anti-fraude « visage vivant »). Il ne doit donc pas servir seul à contrôler un accès
sensible ou à sanctionner quelqu'un. L'appel manuel reste toujours disponible et prioritaire.

**Conseils d'installation :**
- caméra à hauteur de visage (1,50 m à 1,70 m), à 1–2 m du passage, lumière de face ;
- un passage où l'on entre un par un ;
- un PC récent (de préférence avec carte graphique) pour plusieurs images par seconde.

## Données personnelles et loi (Niger)

- **Données concernées :** les photos et les empreintes faciales sont des données
  personnelles, et des **données biométriques**.
- **Cadre légal :** au Niger, la loi n° 2017-28 du 3 mai 2017 relative à la protection des
  données à caractère personnel (modifiée) et la Haute Autorité de Protection des Données à
  caractère Personnel (HAPDP) encadrent leur traitement. Un traitement biométrique est en
  principe soumis à une formalité préalable auprès de la HAPDP. **Renseignez-vous auprès
  d'elle avant la mise en service** : ce module ne remplace pas cette démarche, ni un avis
  juridique.
- **Ce que fait le module :**
  - fonction **désactivée par défaut** ;
  - **consentement obligatoire**, noté sur la fiche avec sa date et le nom de la personne qui
    l'a donné (le parent ou tuteur pour un mineur) ;
  - le retrait du consentement **efface immédiatement** les empreintes, et un bouton « Effacer
    les données faciales » existe sur chaque fiche ;
  - droits séparés : seul le droit « données faciales » (`ecole.biometrie`) enregistre des
    visages et vérifie les passages, le caissier n'y a pas accès ;
  - vignettes conservées 30 jours (réglable) ;
  - chaque enregistrement, effacement et vérification est journalisé (Journal d'audit).
- **À faire par l'établissement :**
  - informer les familles et le personnel par écrit (finalité, durée de conservation,
    droits d'accès et d'effacement) ;
  - proposer l'appel manuel à qui refuse ;
  - limiter l'accès au serveur et faire des sauvegardes chiffrées.

## Droits (rôles)

| Rôle | Droits |
|---|---|
| Direction (`ecole_direction`) | Tout |
| Secrétariat (`ecole_secretariat`) | Fiches, inscriptions, caisse, présences, bulletins, données faciales |
| Comptable (`ecole_comptable`) | Caisse, finances, impayés, salaires |
| Enseignant (`ecole_enseignant`) | Notes, appel, consultation |
| Caissier (rôle existant) | Consultation et caisse de l'établissement |
| Propriétaire, gérant (rôles existants) | Tout |

## Fichiers

- **`migrations/0001_ecole.sql`** : tables `ecole_*` ; compteurs EL (élèves), PE (personnel),
  REC (reçus), SAL (salaires).
- **`public/`** : scripts, styles, face-api et modèles (fragments de 1 Mo au plus pour
  WampServer). Voir `public/LISEZMOI-composants.txt`.
- **Photos :** dans `storage/ecole/photos`, ré-encodées en JPEG et servies seulement après
  contrôle des droits.
