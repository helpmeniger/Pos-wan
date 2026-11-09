# Module Doublons d'articles

Détecte les articles en double, compare les fiches et fusionne les doublons dans l'article le
plus utilisé. Compatible avec POS Marwa / POS Wan **1.4.0 et toutes les versions suivantes**.

## Installation
1. Copier le dossier `doublons` dans le dossier `addons/` de POS Marwa
   (ou Configuration → Modules → Ajouter un module, selon la version).
2. Configuration → Modules → « Doublons d'articles » → Installer.
3. Menu Gestion des articles → Doublons d'articles.

## Ce que fait la fusion
- L'article conservé garde son nom, sa référence et son prix de vente.
- Ventes, mouvements de stock, lots, achats, réceptions, inventaires, recettes, devis,
  boutique en ligne et tables des autres modules passent sur l'article conservé (les tables
  sont découvertes automatiquement dans la base, quelle que soit la version).
- Stock du même entrepôt et lots identiques : les quantités sont additionnées.
- Coût moyen recalculé (moyenne pondérée par les stocks) ; code-barres, photo, description,
  catégorie repris du doublon si l'article conservé n'en a pas.
- Le doublon est ensuite supprimé (archivé si une donnée inconnue le retient encore).

## Sécurité
Sauvegarde de la base avant chaque fusion, transaction (tout ou rien) par doublon, journal
avec une copie complète de chaque fiche supprimée. Droit `doublons.manage` (propriétaire,
gérant, administrateur). Les noms « ressemblants » ne sont jamais fusionnés automatiquement.
