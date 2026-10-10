# Guide d'utilisation rapide — POS Wan

## Se connecter
- **Accès rapide PIN** : choisir son nom, taper son code PIN sur le pavé, puis **GO**.
- **Identifiant** : identifiant + mot de passe.
- **Badge** (si activé) : passer le badge devant le lecteur.

Après plusieurs erreurs, le compte est bloqué 15 minutes (un administrateur peut le débloquer).

## La caisse

```
┌──────────────── Catégories (tuiles) ────────────────┐ ┌2┐ ┌──── Ticket ────┐
│ Recherche…                                          │ │3│ │ Total : 14 700 F│
│ ┌────┐ ┌────┐ ┌────┐  Articles : photo, stock, prix │ │…│ │ actions         │
│ └────┘ └────┘ └────┘                                │ │9│ │ lignes          │
│ [Auto][Manuel] [ code-barres ] ✓ 🗑 [Argent client] 🖩 [à rendre] │ │ VALIDER         │
└──────────────────────────────────────────────────────┘ └─┘ └────────────────┘
```

1. **Ouvrir la caisse** le matin : saisir le fond de caisse compté.
2. **Ajouter des articles** : scanner, toucher une tuile, ou chercher par nom.
   - Touches **2 à 9** (bande centrale) : quantité pour le prochain article. Toucher deux fois la même touche l'applique à la ligne sélectionnée.
   - Bouton « Pack de 6 », « Carton de 24 »… sur la tuile : vend le conditionnement.
3. **Modifier une ligne** : la toucher, puis *Quantité*, *Prix*, *Remise* ou *Retirer*.
   Clavier : `+` / `-` (quantité), `Suppr` (retirer), flèches (sélection).
4. **Client** : bouton *Client* (recherche par nom ou téléphone, création rapide).
5. **Encaisser** : *Valider* (ou F9). Choisir le mode (espèces, carte, mobile money…),
   ajouter un ou plusieurs paiements, les billets rapides proposent les montants usuels.
   La monnaie à rendre s'affiche en grand. Saisir « Argent client » en bas permet aussi
   de voir immédiatement la monnaie.
6. Le ticket s'imprime automatiquement (case **TICKET**). Case **Facture A4** pour une facture.

| Touche | Action |
|---|---|
| F2 | Recherche d'article |
| F4 | Client |
| F6 | Quantité de la ligne |
| F7 | Remise |
| F8 | Mettre en attente |
| F9 | Encaisser / valider le paiement |
| F10 | Encaissement espèces au montant exact |
| F12 | Vider le panier |
| Entrée | (après une vente) nouvelle vente |

Les touches sont modifiables dans Paramètres.

### Cas particuliers
- **Vente à crédit** : choisir le client, bouton *Crédit*, encaisser l'éventuel acompte, valider. Le reste est inscrit sur son compte (plafond contrôlé).
- **Remise au-delà du seuil, prix modifié, crédit hors plafond** : une fenêtre demande l'identifiant et le mot de passe (ou PIN) d'un responsable.
- **Mise en attente** (F8) : le client va chercher un article ; servir le suivant ; *Reprendre* pour rappeler la vente.
- **Médicament sur ordonnance** (pharmacie) : renseigner prescripteur et patient dans la fenêtre d'encaissement ; un pharmacien doit être connecté ou autoriser.
- **Coupure réseau / double clic** : la vente n'est jamais enregistrée deux fois ; réessayer sans crainte.
- **Réimpression** : *Tickets* → icône imprimante ; la copie porte la mention DUPLICATA (permission requise).

## Restaurant (si le module est activé)

**Serveur**
1. *Plan de salle* : les tables vertes sont libres, oranges occupées, bleues « addition
   demandée » ; une cloche violette signale des plats prêts, un badge jaune des articles
   non encore envoyés.
2. Toucher une table libre → choisir le nombre de couverts → la commande s'ouvre.
   *À emporter* / *Livraison* : saisir le nom et le téléphone du client.
3. Toucher les plats (2–9 pour la quantité). *Note plat* : « sans piment », « bien cuit »…
   Le service (boisson, entrée, plat, dessert) est automatique ou choisi en haut.
4. **Envoyer en cuisine** : un bon part à chaque poste (cuisine, grill, bar).
5. Quand la cuisine marque un plat « prêt », la table clignote : servir puis *Servi*.
6. *Table* : transférer vers une table libre ou fusionner avec une table occupée.
7. *Annuler* : un article non envoyé se retire librement ; un article envoyé exige un
   motif et l'accord d'un responsable ; cocher « déjà préparé » le déclare en perte.
8. *Addition* imprime la pré-note (montant par personne si plusieurs couverts).

**Caissier / responsable** — *Encaisser* : choisir les articles payés (addition séparée
par client) ou *÷ parts* pour diviser en parts égales ; la table se libère quand tout est payé.

**Cuisine / bar** — *Écran cuisine* : *Commencer* puis *Prêt* ; *Servi — retirer* quand le
plat est parti ; ↶ pour revenir en arrière. Le chronomètre passe à l'orange après 10 min
et au rouge après 20 min. Les tickets rouges sont des **annulations**.

**Gestion** — *Salles & tables*, *Fiches techniques* (ingrédients par portion : le stock
d'ingrédients baisse à chaque vente), *Rapport restaurant*.

## Fin de journée
*Ma caisse* → compter les billets et pièces par coupure → *Clôturer*. L'état de caisse
(théorique, compté, écart) s'imprime. Tout écart doit être commenté.

## Retours et annulations
- *Historique des ventes* → ouvrir la vente → **Retour / remboursement** : choisir les quantités, le mode de remboursement et le motif.
- **Annuler la vente** (responsable, dans les 24 h) : tout est contre-passé automatiquement.

## Gestion (bouton « Menu »)
Le **Menu de gestion** regroupe tous les modules en icônes rondes (comme l'ancien logiciel) :
ventes, articles, stocks, achats, finances, rapports et configuration. Chaque utilisateur ne
voit que les modules autorisés par son rôle.

- **Réception de marchandises** : Achats → Réceptions → Réception directe (ou depuis une commande). Scanner les articles, saisir quantités, prix d'achat, lot et péremption ; le stock, le coût moyen et la dette fournisseur sont mis à jour.
- **Inventaire** : Inventaires → Démarrer → imprimer la feuille de comptage → saisir les quantités → Valider.
- **Pertes / casse / périmés** : Ajustement de stock (motif obligatoire).
- **Étiquettes** : depuis une fiche article ou par catégorie (planche A4).
- **Rapports** : ventes (par jour, catégorie, vendeur, caisse, mode de paiement, heure), articles, bénéfices, valeur du stock, créances et dettes ; export CSV et impression/PDF.
