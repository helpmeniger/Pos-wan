# Processus de facturation certifiée e-SECeF (Niger)

Source : **Plateforme e-SECeF — Manuel de l'utilisateur**, DGI Niger, février 2023, v1.0 (rubrique
Documentation de l'espace contribuable), complété par les libellés de l'application e-SFE
(`LIBELLES_E-SFE_fr.txt`) et l'observation du portail (`CARTOGRAPHIE_PORTAIL.md`).
Les écrans de facturation **n'ont pas été vus directement** (voir la cartographie, § 7) : ce qui suit est
ce que dit la DGI, à confirmer à l'écran avant tout développement d'automatisation.

## 1. Deux façons officielles de certifier

Le manuel (chap. III et V) distingue deux types d'e-SECeF, demandés depuis l'espace contribuable :

| Type | Pour qui (texte du manuel) | Fonctionnement |
|---|---|---|
| **e-SFE** | « les contribuables qui **n'ont pas** un logiciel de facturation homologué » | Facturation dans le navigateur, sur l'application e-SFE de la DGI |
| **e-MCF** | « ceux qui ont leurs **logiciels de facturation homologués** » ; « utilisé par les usagers qui disposent d'un logiciel de facturation et permet de certifier leurs factures » | Le logiciel de facturation certifie par le service e-MCF, avec un **jeton** créé dans l'espace contribuable (date d'expiration choisie) |

Manuel, p. 41 : *« Si vous êtes un éditeur/développeur de SFE, vérifiez la section documentation afin
d'accéder aux instructions techniques sur l'intégration de SFE avec e-MCF. »*

**Conséquence pour POS Marwa** : la voie prévue par la DGI pour un logiciel de caisse est **e-MCF**
(logiciel homologué + jeton). Faire remplir l'e-SFE par un robot depuis POS Marwa revient à utiliser un
logiciel non homologué à travers l'outil destiné aux contribuables sans logiciel : sa conformité doit être
confirmée par la DGI avant toute facture réelle (voir `ARCHITECTURE_TECHNIQUE.md`, à rédiger après décision).

## 2. Préalables (e-SFE)

1. Compte e-SECeF validé par la DGI (identifiant = NIF).
2. e-SECeF de type e-SFE **actif** (NIM attribué, point de vente).
3. **Au moins un opérateur** enregistré (nom du vendeur) : « nécessaire… afin d'émettre des factures ».
4. **Articles programmés** (Articles → PROGRAMMER UN ARTICLE) :
   - Désignation (obligatoire) ;
   - Groupe de taxation (obligatoire, liste du portail — valeurs à relever) ;
   - Prix [TTC] ou Prix [HT] (prix unitaire obligatoire) ;
   - Taxe spécifique (T.S.) si applicable (montant par unité ou pourcentage) ;
   - Précompte ISB applicable (option), Stock (option, si le contrôle du stock est activé).
5. Configuration (facultatif) : désactiver les types de facture inutiles, mode de prix par défaut (TTC/HT),
   contrôle du stock, message commercial.

## 3. Types de facture (écran « Enregistrement d'une nouvelle facture », manuel p. 21-22)

| Code | Type | Remarque |
|---|---|---|
| FV | Facture de vente (biens ou services) | |
| FT | Facture d'acompte ou d'avance | biens/services approvisionnés avec retard |
| FA | Facture d'avoir | **doit être liée à la facture de vente** |
| EV | Facture de vente à l'exportation | |
| ET | Facture d'acompte ou d'avance à l'exportation | |
| EA | Facture d'avoir à l'exportation | liée à la facture de vente à l'exportation |

Pour chaque type : bouton **PRIX TTC** ou **PRIX HT** (mode de saisie des prix).

## 4. Facture de vente — étapes officielles (manuel p. 20-29)

1. FACTURES ; opérateur actuel (ajout la première fois) ; choix du type et du mode TTC/HT.
2. **1. Informations générales** — client **facultatif** (Nom, NIF à 13 caractères, Adresse, Contact),
   **sauf** si le **précompte ISB** est appliqué : les informations du client deviennent alors **obligatoires**.
   Lignes de commentaire (A : « réf exo en cas d'articles du groupe G »).
3. **2. Articles** — recherche dans les articles programmés, bouton vert **PLUS** (article + quantité au
   panier) ; quantité modifiable (menu « trois barres » → MODIFIER), décimale jusqu'à 3 chiffres ; ajout d'un
   nouvel article possible depuis la facture ; remises : pourcentage, par prix unitaire, montant total.
4. **Paiement** — type de paiement (ESPECES par défaut ; MOBILE MONEY, CHEQUES, CARTE BANCAIRE, VIREMENT,
   AUTRE 1, AUTRE 2), répartition possible entre plusieurs modes.
5. **3. Répartition** — totaux par groupe de taxation (Montant, HT, TVA), ISB, T.S., total général ;
   bandeau « Facture non certifiée » ; « Erreurs de facturation » à corriger avant la certification.
6. **APERÇU** (contrôle visuel).
7. **CERTIFIER** — opération fiscale (irréversible).
8. Résultat : « Facture certifiée », **éléments de sécurité** : **Code SECeF/DGI**, **SECeF NIM**,
   **SECeF Compteurs**, **SECeF Heure** ; **TÉLÉCHARGER LE PDF** ; **FERMER LA FACTURE**
   (avertissement : vérifier d'abord que la facture est téléchargée).

## 5. Facture d'avoir (p. 30-32)

FACTURES → Facture d'avoir + mode → Références : **Modifier** → saisir la **référence de la facture de
vente** (Code SECeF/DGI de la facture d'origine ; obligatoire : « La référence à la facture originale de vente
est obligatoire en cas de facture d'avoir ») → articles et quantités → APERÇU → CERTIFIER → PDF.
Le Journal propose aussi « Rembourser » : projet d'avoir créé à partir de la facture de vente.

## 6. Retrouver et vérifier une facture

- **Journal** (p. 33-34) : filtre par dates (début, fin), clic sur la facture → affichage du duplicata,
  téléchargement. Usage POS Marwa : **vérifier si une facture a déjà été certifiée** avant toute nouvelle
  tentative (retour perdu, coupure).
- **Vérification publique** : `https://sygmef.impots.gouv.ne/Verification` — Code SECeF/DGI (29 caractères
  au plus) + NIM (14 au plus).

## 7. Rapports (p. 35-36)

Rapport X quotidien (sans remise à zéro), **Rapport Z** (remet les cumuls à zéro), rapports périodiques,
**Rapport A** (articles, remet à zéro). Bouton CRÉER, puis consultation et PDF.
**POS Marwa ne doit jamais créer de rapport Z ou A automatiquement.**

## 8. Différences avec POS Marwa (à traiter dans la correspondance)

| Sujet | e-SECeF | POS Marwa actuel | À faire |
|---|---|---|---|
| Taxes | Groupes de taxation de la DGI (lettres ; liste et taux à relever), T.S., ISB | Taux de TVA par article (paramètres) | Table de correspondance groupe DGI ↔ taxe POS, validée par l'utilisateur |
| Articles | Doivent être **programmés** sur le portail | Catalogue local | Correspondance article POS ↔ article programmé (création sur le portail = action à confirmer) |
| Client | Facultatif, sauf ISB ; NIF à 13 caractères | Client facultatif | Contrôle du NIF à 13 caractères |
| Prix | Saisie TTC ou HT par facture | Prix TTC | Mode TTC |
| Avoir | Lié au Code SECeF/DGI de la vente | Retours | Avoir certifié lié à la vente certifiée |
| Numérotation | Compteurs SECeF fournis par le portail | Numéros POS | Conserver les deux ; n'afficher que les références réellement reçues |
