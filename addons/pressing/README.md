# Module « Pressing » pour POS Marwa

Gestion complète d'un pressing / blanchisserie : tarifs, dépôts, étiquettes, suivi du
traitement, retrait avec paiement, relances et rapport. Les encaissements passent par la
caisse POS Marwa (ils apparaissent dans la clôture de caisse).

## Installation
1. Copier le dossier `pressing` dans `addons/` (déjà présent dans POS Marwa 1.4.1).
2. Gestion → **Modules complémentaires** → Pressing → **Installer**.
3. Menu **Pressing** : Dépôts, Nouveau dépôt, Tarifs, Rapport.

## Utilisation au comptoir
1. **Nouveau dépôt** : nom et téléphone du client (les clients déjà venus sont proposés),
   choisir le service (Lavage + repassage, Nettoyage à sec, Repassage seul, Teinture), puis
   toucher les articles (Chemise, Grand boubou, Complet pagne…, ou **Linge au kilo** : saisir le
   poids, ex. 4,5). Pour chaque ligne :
   quantité, couleur, marque et **défauts constatés** (tache, trou… : ils figurent sur le
   ticket pour éviter les litiges).
2. **EXPRESS** : supplément (50 % par défaut) et délai court (1 jour).
3. **Acompte** facultatif (espèces, mobile money, carte…), boutons « Tout payer » / « 50 % ».
4. Enregistrer : le **ticket client** (avec QR code) s'imprime ; imprimer les
   **étiquettes** (une par pièce, ex. `12-2`) et les attacher aux vêtements.
5. Atelier : **En cours** puis **Prêt** ; bouton **Prévenir le client** (message WhatsApp
   prêt à envoyer, avec le reste à payer).
6. **Retrait** : le client présente son ticket (recherche par n°, nom, téléphone ou n°
   d'étiquette) ; encaisser le solde (monnaie calculée) puis **Remettre le linge**.

Tableau de bord : reçus, en cours, prêts, **en retard** (date de retrait dépassée),
**non retirés** depuis plus de 30 jours, encaissé du jour.

## Règles et droits
| Droit | Rôles par défaut |
|---|---|
| Voir les dépôts | Gérant, responsable, caissier, auditeur |
| Dépôts, encaissements, retraits | Gérant, responsable, caissier |
| Traitement (en cours, prêt) | Gérant, responsable, caissier |
| Tarifs, remises, prix modifiés, annulations, rapport | Gérant, responsable |

- Les prix viennent des **tarifs** (Pressing → Tarifs) ; un prix différent exige le droit
  « Tarifs, remises… ». Remise : même règle (ou droit de remise de la caisse).
- Encaissement : caisse ouverte obligatoire (comme pour les ventes).
- Le linge ne peut être remis que s'il est **payé** ; un responsable peut le remettre avec un
  solde impayé en indiquant un motif (tracé dans l'historique).
- **Annulation** (responsable, motif obligatoire) : les sommes déjà versées sont
  remboursées en espèces depuis la caisse.
- Tout est historisé (statuts, paiements, journal d'audit).

## Paramètres (Gestion → Paramètres, ou base `settings`)
| Clé | Défaut | Rôle |
|---|---|---|
| `pressing_delai_jours` | 3 | Délai de retrait normal |
| `pressing_express_pourcent` | 50 | Supplément express (%) |
| `pressing_express_delai_jours` | 1 | Délai express |
| `pressing_relance_jours` | 30 | Seuil « non retirés » |
| `pressing_conditions` | texte | Conditions imprimées sur le ticket |

Les tarifs fournis (FCFA) sont **indicatifs** : adaptez-les avant la mise en service.

## Version 1.4.0 : express au choix, livraison à domicile
- **Supplément express au choix** : quand EXPRESS est coché, le caissier choisit l'un des
  pourcentages proposés (boutons, ex. 25 %, 50 %, 100 %) ; un autre pourcentage (ex. 37,5 %) est
  réservé au responsable (droit « Tarifs, remises… »). Le pourcentage figure sur la fiche et le ticket.
- **Réglages** (Pressing → Tarifs, en haut) : pourcentage express par défaut, choix proposés
  (`25 ; 50 ; 100`), délais express et normal, frais de livraison par défaut.
- **Livraison à domicile** : case au dépôt, adresse obligatoire, frais modifiables ajoutés au total ;
  badge « LIVRAISON » dans les listes, adresse sur la fiche et le ticket.
- **Écran des dépôts : colonne « Prêts à livrer »** (4ᵉ colonne) et compteur « à livrer ». Un dépôt
  prêt avec livraison y apparaît (et non dans « Prêts à retirer ») avec l'adresse ; bouton
  « Encaisser … puis livrer » tant qu'il reste à payer, puis « Livré ». L'affichage côté clients ne
  montre pas les livraisons.
- Migration `0004_express_livraison.sql` (pourcentage des dépôts express existants retrouvé).

## Version 1.3.2
- Au dépôt, un clic dans une ligne n'ajoute plus de pièce ; la virgule n'est plus jamais
  ignorée : poids « 4,5 » accepté pour un article au kilo, message clair pour un article à la
  pièce (passer son unité sur « Au kilo » dans Tarifs pour le peser).

## Version 1.3.1
- Caisse : le téléphone d'un client déjà venu ne peut plus être saisi en double (il est
  sélectionné au clic, la saisie le remplace) ; téléphone de plus de 15 chiffres refusé.

## Version 1.3.0 : articles au kilo

- Chaque article a une **unité** (Pressing → Tarifs, colonne « Unité ») : **À la pièce** ou
  **Au kilo (kg)**. Pour un article au kilo, le prix saisi est le prix **par kg**.
- Article fourni : **Linge au kilo** (lavage + repassage 1 000 F/kg, repassage seul 500 F/kg,
  tarifs indicatifs à adapter).
- Au dépôt, toucher un article au kilo ouvre la saisie du **poids, avec virgule** (ex. `4,5`).
  Le dépôt ne peut pas être enregistré tant qu'un poids est vide.
- Les articles à la pièce restent en **nombre entier** : « 1,5 chemise » est refusé.
- Un sac pesé compte pour **un colis** (une étiquette, qui indique le poids). Le poids total
  figure sur la fiche, le ticket, les listes, l'écran de l'atelier et le rapport.
- Retrait partiel possible au poids (ex. 2,5 kg sur 4,5 kg).
- Migration `0003_articles_au_poids.sql`, appliquée automatiquement à la mise à jour ; les
  dépôts existants sont conservés.

## Version 1.1.0 (POS Marwa 2.0)

- **Caisse pressing** (`/pressing/caisse`) :
  - nouveau dépôt ;
  - retrait par numéro, téléphone ou scan du ticket ;
  - ouverte automatiquement en mode Pressing.
- **Statuts** : Déposé, En attente, En traitement, Prêt, Partiellement retiré, Retiré,
  Annulé.
- **Retrait partiel** :
  - pièces cochées une à une, date de chaque retrait ;
  - réglage `pressing_retrait_partiel` (1 = autorisé).
- **Suivi des vêtements** (`/pressing/suivi`) :
  - filtres : en cours, en retard, retrait proche, prêts, impayés, retirés, tous ;
  - jours de retard ;
  - export CSV ou PDF.
- **Alertes** :
  - dépôts en retard ;
  - dépôts à retirer dans `pressing_alerte_jours` jours (1 par défaut).
- **Historique d'un client** : liste de ses dépôts avec les montants payés.
- Migration `0002_suivi_retraits.sql` (Modules → Pressing → Mettre à jour). Les dépôts déjà
  retirés sont marqués entièrement retirés.
