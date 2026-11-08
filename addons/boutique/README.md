# Module Boutique en ligne

Vos articles sur une page publique pour vos clients : photo, description, prix, disponibilité,
panier, commande et reçu automatique. Les commandes arrivent dans POS Marwa où le caissier
les vérifie, les valide, les encaisse (le stock diminue) puis les livre.

## Installation
Configuration → Modules → « Boutique en ligne » → Installer. Puis
Boutique en ligne → Réglages : choisir la **caisse de la boutique en ligne** (son entrepôt
fournit le stock affiché), la couleur, la livraison et ses frais, le numéro WhatsApp.

Adresse de la boutique : `https://votre-site/…/boutique` (QR code dans les réglages).

## Circuit d'une commande
1. **Nouvelle** : le client commande. Prix et stock recalculés par le serveur ; les quantités
   sont **réservées** (elles disparaissent du stock affiché en ligne) mais le stock réel ne bouge pas.
   Le client reçoit un **reçu** avec QR code et suivi (lien secret).
2. **Validée** : le caissier a vérifié la commande (stock réel de son entrepôt affiché par ligne).
3. **Encaissée** : une vente normale est créée dans la caisse du caissier (ticket, rapports,
   clôture) ; le **stock diminue** ; les frais de livraison sont une recette de caisse à part.
4. **Livrée / retirée**.

Annulation possible tant que la commande n'est pas encaissée (les réservations sont libérées).
Après encaissement : annulation ou retour sur la vente (menu Ventes).

## Sécurité
Pas de compte client ni de paiement en ligne : le paiement se fait au retrait ou à la livraison.
Anti-robot (champ piège), 6 commandes par heure au plus par connexion, médicaments sur
ordonnance et articles réservés au restaurant jamais publiés. Les données des clients (nom,
téléphone, adresse) servent uniquement à traiter la commande (loi n° 2017-28).
