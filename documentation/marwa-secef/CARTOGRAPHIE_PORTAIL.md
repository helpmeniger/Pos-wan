# Cartographie du portail e-SECeF (DGI Niger)

> **État : phase 1 (partie publique) faite ; phase 2 (connecté) faite pour l'espace contribuable, mais
> l'application e-SFE n'a pas pu s'ouvrir dans le navigateur d'exploration (voir § 7).**
> Source complémentaire : *Plateforme e-SECeF — Manuel de l'utilisateur*, v1.0, février 2023 (DGI Niger,
> rubrique Documentation de l'espace contribuable), résumé dans `PROCESSUS_FACTURATION.md`.
>
> Phase 1 : Observée le 20/11/2026 avec Chromium
> (Playwright), sans aucune saisie ni envoi de formulaire. Les écrans réservés aux comptes connectés
> (facturation, articles, journal, rapports, configuration) **n'ont pas encore été vus** : ce qui les concerne
> ci-dessous vient uniquement des libellés publics de l'application (`/esfe/assets/i18n/fr.json`, fichier que
> tout navigateur télécharge en ouvrant le portail) et doit être confirmé à l'écran (phase 2).
>
> Aucun identifiant, mot de passe, cookie ni jeton ne figure dans ce document.

Légende : ✅ observé à l'écran · 📄 libellé public de l'application, écran non encore vu · ❓ inconnu

## 1. Sites et adresses

| Adresse | Rôle | État |
|---|---|---|
| `https://esecef.impots.gouv.ne/` | Page d'accueil e-SECeF : « BIENVENUE SUR LA PLATEFORME e-SECeF ! — Éditez vos factures certifiées en ligne » ; boutons « Accès à la plateforme e-SECeF » (→ `https://sygmef.impots.gouv.ne`) et « Création de compte d'accès » (→ `/esfe/registration`) | ✅ |
| `https://esecef.impots.gouv.ne/esfe/…` | Application de facturation **e-SFE** (application Angular, « Système de facturation e-SFE de la DGI Niger », version affichée v.0.95) | ✅ chargement |
| `https://esecef.impots.gouv.ne/esfe/pos/invoice` | Adresse donnée pour la facturation. Sans session : redirection vers `/esfe/init?url=%2Fpos%2Finvoice` puis page « **La session a expiré** — Veuillez ouvrir une nouvelle session en cliquant ICI » (lien → `/`) | ✅ |
| `https://sygmef.impots.gouv.ne/Account/Login` | Connexion **SyGMEF** (« Système de Gestion des Machines Électroniques de Facturation ») | ✅ |
| `https://sygmef.impots.gouv.ne/Verification` | Vérification publique d'une facture | ✅ |
| `https://esecef.impots.gouv.ne/esfe/registration` | Demande de création de compte (assistant en 6 étapes) | ✅ étape 1 |

Captures : `captures/00-esecef-accueil.png`, `01-sygmef-connexion.png`, `02-sygmef-verification.png`,
`03-esecef-demande-de-compte.png`.

## 2. Connexion (SyGMEF) ✅

- Formulaire `POST /Account/Login?ReturnUrl=…`
- Champs : `UserName` (« Identifiant de l'utilisateur » — message du portail : *« Si vous êtes un contribuable
  (un utilisateur d'e-SECeF), veuillez utiliser votre NIF comme l'identifiant de l'utilisateur »*),
  `Password` (« Mot de passe »), case `RememberMe` (« Mémoriser mes informations »).
- Bouton « Se connecter » ; lien « Mot de passe oublié ? » (`/Account/ForgotPassword`) ; bouton « Vérifier la facture ».
- Aucun CAPTCHA visible sur la page. ❓ Double authentification (OTP) après connexion : à observer.

**Utilité pour POS Marwa** : première authentification faite par l'administrateur (jamais le mot de passe
dans le code ni dans les journaux) ; détection de la page « La session a expiré » pour savoir qu'il faut
se reconnecter.

## 3. Vérification d'une facture (SyGMEF) ✅

- Texte : « Saisissez le code SECeF/DGI et le NIM de la machine afin de vérifier la facture ».
- Formulaire `POST /Verification/Verification` :
  - `signature` — « Code SECeF/DGI », **obligatoire**, 29 caractères au plus ;
  - `mrc` — « NIM », 14 caractères au plus.
- Bouton « Vérifier », lien « Arrière ».

**Utilité pour POS Marwa** : le reçu certifié doit porter le **Code SECeF/DGI** et le **NIM** exactement tels
que fournis par le portail, pour que le client puisse vérifier sa facture ici. ❓ Le format du QR code
officiel (contenu exact) reste à relever sur une facture certifiée réelle.

## 4. Demande de compte ✅ (étape 1/6)

Texte : demande de création de compte, adresse e-mail valide vérifiée, validation par la DGI après contrôle
d'identité (72 h), contact 20 72 23 66. Bouton « CONTINUER ». Champs annoncés par les libellés 📄 : Raison
sociale, NIF, E-mail, Téléphone, Code de validation. *Sans objet pour POS Marwa (compte déjà existant).*

## 5. Application e-SFE après connexion 📄 (à confirmer à l'écran)

Menu (`menu.*`) : **Accueil · Factures · Articles · Journal · Rapports · Configuration · Test · À propos · Sortir**.

| Rubrique | Fonction annoncée par le portail | Champs / éléments annoncés | Utilité pour POS Marwa |
|---|---|---|---|
| Accueil | Présentation, « e-SECeF ID » | — | Lire l'identifiant e-SECeF |
| **Factures** — « Enregistrement d'une nouvelle facture » | Choix du type : **FACTURE DE VENTE**, **FACTURE D'AVOIR**, **FACTURE DE VENTE À L'EXPORTATION**, **FACTURE D'AVOIR À L'EXPORTATION** ; mode **Prix TTC** ou **Prix HT** | 1. Informations générales (client : Nom, NIF, Adresse, Contact ; opérateur ; précompte ISB) · 2. Articles (désignation, code, prix, quantité jusqu'à 3 décimales, groupe de taxation, taxe spécifique T.S., remise) · Paiement (répartition par mode) · 3. Répartition (HT, TVA, ISB, T.S., total par groupe) · Commentaires (« Commentaire A : réf exo en cas d'articles du groupe G ») · Références (avoir : « Code SECeF/DGI de la facture de vente originale », obligatoire) | **Cœur du module** : correspondance vente POS → facture |
| Validation | Message « Facture non certifiée… appuyer sur le bouton **CERTIFIER** » ; « Erreurs de facturation » à corriger avant certification ; bouton « Vérifier » | — | Ne cliquer sur CERTIFIER qu'après contrôle des totaux |
| Résultat | « Facture certifiée » ; **ÉLÉMENTS DE SÉCURITÉ DE LA FACTURE NORMALISÉE** : **Code SECeF/DGI**, **SECeF NIM**, **SECeF Compteurs**, **SECeF Heure** ; « Télécharger le PDF », « Imprimer » ; « Terminer la facture » | — | Données à récupérer et à imprimer sur le reçu |
| Brouillon | « Projet de la facture trouvée dans le dépôt local. Voulez-vous le charger et continuer ? » | — | ⚠️ Un brouillon local peut subsister : à gérer pour éviter les doublons |
| **Articles** — « Gestion des articles » | « Programmer » les articles (biens ou services) : nom, prix unitaire, groupe de taxation | Code, Désignation, Prix HT/TTC, Mode, Groupe de taxation (obligatoire), T.S., ISB applicable, Stock | Correspondance des articles POS ↔ articles programmés |
| **Journal** | Historique des factures certifiées (plus récentes en haut), « Voir la facture », PDF, « Rembourser » (crée un projet d'avoir lié) | Date de / à, recherche, pagination | **Vérifier si une facture existe déjà** (anti-doublon, retour perdu) |
| **Rapports** | Rapport X quotidien, **Rapport Z** (réinitialise les cumuls), rapports périodiques, rapport A (articles) | Totaux par type, par groupe de taxation, par mode de paiement | ⚠️ Rapports Z/A **irréversibles** : jamais générés automatiquement |
| **Configuration** | Paramètres du e-SFE | Nom, NIF, RCCM, Point de vente, **ISF**, **NIM**, Message commercial, Adresse, Contact ; modes de paiement ESPECES, MOBILE MONEY, CHEQUES, CARTE BANCAIRE, VIREMENT, AUTRE 1, AUTRE 2 ; remises (aucune, %, par prix unitaire, montant total) ; contrôle du stock ; mode de prix par défaut (TTC/HT) ; types de facture disponibles | Lecture des informations du contribuable et des réglages |
| Opérateurs | « Il est nécessaire d'enregistrer au moins un nom d'opérateur (nom du vendeur) afin d'émettre des factures » | Nom de l'opérateur, opérateur actif | Le caissier POS correspond à un opérateur |
| **Test** | « Factures de test » : totaux provisoires, totaux de test, « Totaux de e-MCF » | — | 🔎 **Environnement de test à examiner en premier** |
| À propos | État du système ; « e-MCF EST DISPONIBLE / N'EST PAS DISPONIBLE » ; « Vérifier la connexion avec l'e-MCF » | — | Test de disponibilité avant une certification |

Le portail parle d'**e-MCF** (module de contrôle de facturation côté DGI) : l'application e-SFE du navigateur
communique avec ce service. ❓ Aucune documentation publique d'intégration e-MCF pour le Niger n'a été trouvée
(seule celle du Bénin existe, système différent : elle ne doit pas être supposée valable au Niger).

## 7. Phase 2 : espace contribuable (connecté) ✅ — 20/11/2026

Connexion : `https://sygmef.impots.gouv.ne/Account/Login` avec le NIF comme identifiant ; **aucun OTP ni
CAPTCHA** ; après connexion, retour sur `https://esecef.impots.gouv.ne/Manage`. Le portail bloque le compte
pour un temps après plusieurs mots de passe erronés (message « Tentatives restantes… »).

### « Gérer les e-SECeF » (`/Manage`)

| Élément | Contenu | Action (formulaire) |
|---|---|---|
| En-tête | Raison sociale, NIF, RCCM, e-mail, nombre d'e-SECeF attribués et actifs | — |
| Tableau des e-SECeF | Colonnes **NIM**, **Type** (ESFE / EMCF), **État**, **Nom du point de vente**, **Factures** (nombre émis) | — |
| Bouton « e-SFE » | Ouvre l'application de facturation de cet e-SECeF | `POST /Manage/OpenEsfe` (champs : `Nim`, `Tin`) |
| Bouton « e-MCF (n jetons) » *(compte e-MCF seulement, d'après le manuel)* | Gestion des **jetons** d'accès de l'e-MCF : créer (date d'expiration), supprimer | — |
| « Modifier » | Demande de modification de l'e-SECeF | `POST /Manage/RequestModification` |
| « Soumettre une demande pour un nouveau e-SECeF » | Nouvelle demande (type **e-SFE** ou **e-MCF**) | `POST /Manage/RequestNew` |
| « Imprimer l'état des e-SECeF » | État imprimable | `POST /Manage/TaxpayerStatus` |
| Lien NIF (en haut) | Changement du mot de passe | `/Account/ChangePassword` |
| « Documentation » | Manuel d'utilisation v1.0 (PDF, 41 pages) | `/Home/Documentation` |
| « Se déconnecter » | Fin de session | `/Account/Logoff` |

Le compte exploré possède **un e-SECeF de type e-SFE, actif** (NIM au format `ES0101xxxx-1`), et aucun e-MCF.

### Application e-SFE (`/esfe`) ✅ observée le 20/11/2026 (lecture seule)

Ouverture : bouton vert « e-SFE » → `POST /Manage/OpenEsfe` → `/esfe` → `/esfe/init?url=/pos` → `/esfe/pos`.
Le jeton de la page (`VAR_TOKEN`) est vide : c'est normal, l'application obtient sa session auprès du serveur
au démarrage (`init`). Depuis l'environnement d'exploration, le chargement complet prend **2 à 3 minutes**
(réseau lent) ; chez l'utilisateur, quelques secondes. Application Angular/Ionic, version **V.0.95**.

Garde-fou de l'exploration : **toute requête d'écriture (autre que GET) vers `/api/` était bloquée par le
navigateur d'exploration**. Lectures effectivement faites par l'application : `init`, `items`, `invoices`,
`initReports`, `reports`. Aucun article créé, aucune facture enregistrée ni certifiée, aucun rapport créé.

Menu supérieur : **ACCUEIL · FACTURES · ARTICLES · JOURNAL · RAPPORTS · CONFIGURATION · À PROPOS**, icône
de sortie à droite.

| Écran (URL) | Contenu observé | Actions | Utilité pour POS Marwa |
|---|---|---|---|
| **Accueil** (`/esfe/pos`) | « Bienvenue sur la plateforme de facturation en ligne e-SFE » ; **e-SECeF ID** (= NIM) ; cartes Factures, Articles, Journal, Rapports, Configuration | ÉMETTRE UNE FACTURE, GESTION DES ARTICLES, JOURNAL, RAPPORTS, CONFIGURATION | Contrôle que la session e-SFE est ouverte (texte d'accueil + NIM) |
| **Factures** (`/esfe/pos/invoice`) | « Opérateur actuel : … » ; « Enregistrement d'une nouvelle facture » ; 6 types **FV, FT, FA, EV, ET, EA**, chacun avec **PRIX TTC** / **PRIX HT** | CHANGER D'OPÉRATEUR ; PRIX TTC / PRIX HT (ouvre le formulaire) | Point d'entrée d'une facture |
| **Formulaire de facture** (même URL) | Bandeau « Type: FV, Mode: TTC » ; **1. Informations générales** (vendeur : nom, NIF, NIM, opérateur, adresse, contact) ; « Informations sur le client » (MODIFIER) ; **PRECOMPTE ISB** (liste « ISB sélectionné ») ; Commentaires (MODIFIER) ; **2. Articles** (recherche, liste des articles programmés « Désignation / Prix [groupe] / Actions », PROGRAMMER UN ARTICLE, pagination 10 par page) ; **Panier** (Désignation, Prix, Quantité, Montant [TTC], Actions) ; **Paiement** (liste) ; Total ; **3. Répartition** (« Répartition des impôts » : Groupe, Montant, HT, TVA) ; « Erreurs de facturation » ; « Facture non certifiée » | icône **sac +** (ajoute l'article au panier, quantité 1) ; icône **sac −** (retire) ; menu « trois barres » (modifier la ligne) ; **APERÇU** ; **CERTIFIER** ; **ANNULER** ; FERMER (facture vide) | Cœur de l'automatisation |
| Fenêtre **client** | « Veuillez sélectionner le type de client » : **Personne physique / Personne morale** (obligatoire avant les champs), puis **NIF** (`tin`), **Nom** (`name`), **Adresse** (`address`), **Contact** (`contact`) | MODIFIER, fermer | Client POS → client de la facture |
| Liste **ISB** | N/A · ISB 2% · ISB 5% | — | Précompte (client alors obligatoire) |
| Liste **Paiement** | ESPECES · MOBILE MONEY · VIREMENT · CARTE BANCAIRE · CHEQUES · AUTRE 1 · AUTRE 2 | — | Correspondance des modes de paiement POS |
| **Articles** (`/esfe/pos/items`) | « Gérer les articles programmés dans le e-SFE » ; tableau **Désignation · Groupe de taxation · Prix [TTC] · Prix [HT] · Mode · Taxe spécifique (T.S.) · Actions** ; pagination | CHERCHER ; PROGRAMMER UN ARTICLE ; supprimer (−) ; modifier (trois barres) | Correspondance article POS ↔ article programmé |
| Fenêtre **Programmer l'article** | **Désignation** (`name`), **Code** (`code`), **Groupe de taxation** (`taxGroup`), **MODE TTC / MODE HT**, **Prix [TTC]** (`priceInc`) ou **Prix [HT]** (`priceExc`, grisé en mode TTC), **Taxe spécifique applicable** (`isTsApplicable`) | AJOUTER, fermer | Création d'un article manquant (action à confirmer par l'utilisateur) |
| Liste **Groupe de taxation** | **[A] EXONERE · [B] TAXABLE 19% · [C] TAXABLE 10% · [D] TAXABLE 5% · [F] EXPORTATION · [G] TVA REGIME DEROGATOIRE · [H] REGIME IS · [I] CONSIGNATION D'EMBALLAGE · [J] DEPOTS · [K] DEBOURS · [L] TIMBRE** | Abandonner | Table de correspondance des taxes |
| **Journal** (`/esfe/pos/journal-invoices`) | « Journal des factures — en commençant par les plus récentes » ; **Date de / Date à** (calendrier, aujourd'hui par défaut) ; champ Chercher ; « Affichage de n sur n résultats » | CHERCHER ; ouverture d'une facture | Retrouver une facture déjà certifiée (anti-doublon) |
| **Rapports** (`/esfe/pos/reports`) | Rapports X/Z, Rapports périodiques, Rapports A ; « Historique des rapports » (dates, recherche) | CHERCHER (création non explorée) | Aucune action automatique (Z et A remettent à zéro) |
| **Configuration** (`/esfe/pos/configuration`) | Information : Nom, NIF, RCCM, Point de vente, **ISF** (identifiant du système de facturation, du type `DGI-…`), **NIM**, Message commercial, Adresse, Contact ; « Mode des prix des articles par défaut » PRIX TTC / PRIX HT ; « Types de facture disponibles » : interrupteurs FV, FT, FA, EV, ET, EA | (sauvegarde non explorée) | Lecture des informations du vendeur pour le reçu |
| **À propos** (`/esfe/pos/about`) | « Système de facturation e-SFE de la DGI Niger », version V.0.95 | — | Contrôle de version (détecter un changement du portail) |

Essai local (bloqué côté serveur) : un article du groupe H ajouté au panier donne quantité 1, total = prix,
répartition « **H - 0%** : Montant = HT, TVA 0 », bandeau « Facture non certifiée », boutons APERÇU,
CERTIFIER, ANNULER. Facture vide : erreur « Le montant de la facture ne peut pas être 0… ».

## 6. Inconnus à lever en phase 2 (connecté)

1. ~~Groupes de taxation~~ ✅ relevés (§ 7).
2. Champs obligatoires : relevés pour les formulaires d'article, de client et de facture (`CHAMPS_OBLIGATOIRES.md`) ; messages d'erreur de certification : non observables sans certifier.
3. Comportement du bouton CERTIFIER, contenu exact du PDF et du QR code : **non observés** (aucune certification faite) ; à relever sur le PDF d'une facture déjà certifiée fourni par l'utilisateur.
4. ~~OTP~~ : aucun. Durée de la session e-SFE : 3 600 s annoncées par la page.
5. Le menu « Test » produit-il des factures **non fiscales** ? (à confirmer avant tout essai de certification).
7. **Documentation technique e-MCF** : le manuel (p. 41) indique qu'un éditeur de SFE la trouve dans la rubrique
   Documentation ; sur ce compte (e-SFE seulement), seule la notice utilisateur y figure.
6. Recherche dans le Journal : par quels critères retrouver une facture (montant, date, référence) ?
