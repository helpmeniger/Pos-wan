# Module Modèles de documents (1.0.0)

Plusieurs mises en page pour les tickets de caisse et les documents A4, personnalisables, avec le choix du modèle pour
chaque document. Requiert POS Marwa 3.15.0.
Installation : Configuration → Modules complémentaires → « Modèles de documents » → Installer. Le menu
**Configuration → Modèles de tickets et factures** apparaît (droit `modeles.manage` : propriétaire et gérant).

## Mises en page
| Type | Mise en page | Pour qui |
|---|---|---|
| Ticket | **Encadré à colonnes** | Numéro encadré, colonnes Qté / Produit / PU / Total, « NET À PAYER », vente à crédit avec le reste, solde de créance du client, signature |
| Ticket | **Tableau à colonnes** | En-tête de colonnes, montants alignés, total sur fond noir |
| Ticket | **Économique** | Une ligne par article, sans logo ni QR : le moins de papier |
| Ticket | **Grands caractères** | Lecture facile (texte à 130 %, articles en gras) |
| Ticket | POS Marwa Classique / Compact / Moderne | Modèles d'origine, avec les options de présentation |
| A4 | **Facture encadrée** | Grille complète, cartouche du document, montant en lettres encadré, conditions de vente |
| A4 | **Bandeau latéral** | Coordonnées dans une bande de couleur à gauche |
| A4 | **Demi-page A5** | Format A5 (148 × 210 mm) |
| A4 | POS Marwa Classique / Moderne / Épuré | Modèles d'origine |

Huit modèles prêts à l'emploi sont créés à l'installation, dont un **ticket de vente à crédit**. **Aucun n'est appliqué**
tant que vous ne l'avez pas choisi : les documents restent identiques après l'installation.

## Personnalisation (par modèle, avec aperçu immédiat)
Titre du document, phrase sous le nom (activité, slogan), logo, adresse et téléphone, NIF / RCCM, police (Courier, Arial,
Times, étroite), taille du texte (85 à 130 %), couleur (A4), séparateurs (tirets, points, trait plein, double trait),
numéro encadré, date, caisse, caissier, client, noms des articles en majuscules, références des articles, libellé du total
(« NET À PAYER »…), montant en lettres, **solde de créance du client**, zone de signature, QR code, nombre d'articles,
conditions de vente, message de pied, ligne de contact (téléphone, WhatsApp), filigrane A4 (PAYÉ, COPIE…).

Chaque modèle se **duplique**, s'**exporte** dans un fichier `.json` et s'**importe** dans une autre boutique.

## Choix par document
- Ticket de caisse, **ticket de vente à crédit** (sinon il suit le ticket de caisse), facture A4, devis, bon de livraison.
- **Tickets et reçus des autres pages** : règlements des clients, retours, additions du restaurant, et selon les modules
  installés pressing, clinique, école, hôtel. Leur contenu reste celui de chaque module ; le modèle choisi règle la police,
  la taille du texte, les séparateurs (tickets) et la couleur (A4).

## Ce que le module ne fait pas
- Il ne change **jamais** les montants, articles, numéros ni le bloc de certification fiscale : il ne fait que présenter
  les données de la vente enregistrée.
- Le **solde de créance** affiché est le solde du client **au moment de l'impression** (indiqué sous le montant).
- L'**impression directe** sur imprimante thermique réseau ou USB (ESC/POS, menu Imprimantes) garde son format propre :
  les modèles s'appliquent aux impressions depuis le navigateur (tickets 58/80 mm et A4).

## Sécurité
- Toutes les options sont contrôlées (listes fermées, couleur `#rrggbb`, textes de longueur bornée) ; les textes sont
  échappés à l'affichage. Un fichier importé ne peut contenir que des options de présentation.
- Création, modification, suppression et changement d'affectation sont inscrits au journal d'audit.
- Supprimer un modèle ne supprime aucune vente : les documents qui l'utilisaient reprennent le modèle des Paramètres.
