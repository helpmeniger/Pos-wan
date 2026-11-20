# Cartographie du portail e-SECeF (DGI Niger)

> **État : phase 1 — partie publique seulement (sans connexion).** Observée le 20/11/2026 avec Chromium
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

## 6. Inconnus à lever en phase 2 (connecté)

1. Liste réelle et taux des **groupes de taxation** (lettres, TVA, exonérations).
2. Champs obligatoires réels de chaque écran et messages d'erreur.
3. Comportement du bouton CERTIFIER (confirmation ? délai ? message ?), contenu exact du PDF et du QR code.
4. Présence d'un OTP, durée de session (« Durée de la session »), déconnexion automatique.
5. Le menu « Test » produit-il des factures **non fiscales** ? (à confirmer avant tout essai de certification).
6. Recherche dans le Journal : par quels critères retrouver une facture (montant, date, référence) ?
