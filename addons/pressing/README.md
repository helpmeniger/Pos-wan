# Module « Pressing » pour POS Wan

Gestion complète d'un pressing / blanchisserie : tarifs, dépôts, étiquettes, suivi du
traitement, retrait avec paiement, relances et rapport. Les encaissements passent par la
caisse POS Wan (ils apparaissent dans la clôture de caisse).

## Installation
1. Copier le dossier `pressing` dans `addons/` (déjà présent dans POS Wan 1.4.1).
2. Gestion → **Modules complémentaires** → Pressing → **Installer**.
3. Menu **Pressing** : Dépôts, Nouveau dépôt, Tarifs, Rapport.

## Utilisation au comptoir
1. **Nouveau dépôt** : nom et téléphone du client (les clients déjà venus sont proposés),
   choisir le service (Lavage + repassage, Nettoyage à sec, Repassage seul, Teinture), puis
   toucher les articles (Chemise, Grand boubou, Complet pagne…). Pour chaque ligne :
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
