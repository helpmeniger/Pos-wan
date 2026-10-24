# Module Comptabilité générale (POS Marwa)

Comptabilité en partie double, inspirée des logiciels de comptabilité du marché (journaux, brouillard, validation, lettrage, états), avec les plans comptables de l'espace OHADA.

## Activation

Menu **Système & maintenance → Modules complémentaires → Comptabilité générale → Installer**. Le module n'est pas activé d'office : il apparaît ensuite dans le menu, catégorie **Comptabilité**. Une sauvegarde de la base est faite avant l'installation.

À la première ouverture, l'assistant demande :

1. **le plan comptable** : SYSCOHADA révisé complet (environ 600 comptes, recommandé au Niger), SYSCOHADA simplifié (environ 250 comptes, commerce / services / PME), PCG français abrégé, ou plan vide (création manuelle ou import CSV « numéro;libellé ») ;
2. **le premier exercice** (code, dates) ;
3. la date à partir de laquelle l'activité de la caisse est reprise en comptabilité.

Les journaux AC (achats), VT (ventes), CA (caisse), BQ (banque), MM (mobile money), OD (opérations diverses) et AN (à-nouveaux) sont créés ; ils restent modifiables.

## Droits

| Droit | Permet |
|---|---|
| `compta.view` | consulter écritures, comptes, états |
| `compta.saisie` | saisir / modifier / supprimer le brouillard, gérer les tiers |
| `compta.valider` | valider, extourner, lettrer, générer les écritures de la caisse |
| `compta.admin` | plan comptable, journaux, exercices, verrouillage, clôture, paramètres |

Le rôle **Comptable** est créé avec tous ces droits ; le propriétaire les reçoit, l'auditeur reçoit la consultation.

## Règles tenues par le logiciel

- Une écriture compte au moins deux lignes et elle est équilibrée au centime (débit = crédit), sinon elle est refusée.
- On impute des comptes de détail : un compte qui a des sous-comptes sert au regroupement.
- Un compte collectif (clients, fournisseurs, personnel) demande le tiers sur chaque ligne.
- **Brouillard puis validation** : le brouillard se modifie et se supprime ; la validation donne le numéro définitif, chronologique et sans trou par journal et par exercice. Une écriture validée ne se modifie plus et ne se supprime pas : on l'**extourne** (écriture inverse validée, liée à l'originale).
- **Verrouillage de période** : aucune écriture jusqu'à la date choisie (clôture mensuelle).
- **Clôture** : refusée tant qu'il reste du brouillard, que la balance est déséquilibrée ou que l'exercice précédent est ouvert. Elle fait une sauvegarde complète, crée au besoin l'exercice suivant, y passe les à-nouveaux (classes 1 à 5, détaillés ligne par ligne pour les comptes lettrables non lettrés) et le résultat (131 bénéfice / 139 perte en SYSCOHADA, 120 / 129 en PCG). Rien n'est effacé.
- Chaque action (création, validation, extourne, lettrage, clôture, paramètres) est inscrite au journal d'audit.

## États

Journal, journal centralisateur, grand livre (avec report et solde progressif), balance générale (par compte, groupe ou classe, 6 colonnes), balance des tiers (non lettré, plus ancienne échéance), bilan et compte de résultat **présentés selon le SYSCOHADA révisé** avec la colonne N-1. Pour un plan PCG, le bilan et le résultat sont présentés de façon simplifiée (regroupements à 2 chiffres). Chaque état : écran, impression A4, export CSV et PDF. Par défaut les états incluent le brouillard (indiqué « provisoire ») ; l'option « Validées seulement » donne les états définitifs.

Ces états sont des états de gestion. Les états financiers annuels et la liasse fiscale (DSF) restent sous la responsabilité du comptable ou de l'expert-comptable de l'entreprise.

## Écritures de la caisse

Page **Écritures de la caisse** : une écriture par source et par journée terminée (jusqu'à la veille), au brouillard par défaut. Une journée n'est passée qu'une fois (clé unique) ; une écriture générée puis supprimée au brouillard est proposée de nouveau.

| Source | Écriture (comptes de liaison des paramètres) |
|---|---|
| Ventes | débit caisse / banque / mobile money / chèques selon le mode de paiement, débit client (tiers) pour le crédit ; crédit ventes HT et TVA facturée. Annulation : écriture inverse le jour de l'annulation. Retour : ventes et TVA (au prorata de la vente) au débit, remboursement ou avoir client au crédit. |
| Règlements | règlements et acomptes clients (trésorerie / client), paiements fournisseurs (fournisseur / trésorerie). |
| Achats | réception = facture : achats de marchandises + frais sur achats / fournisseur (tiers) ; retour fournisseur = avoir. |
| Recettes des modules | encaissements clinique, pressing, établissement… en prestations de services ; salaires payés en caisse en charges de personnel ; entrées / sorties manuelles de caisse au compte de virements de fonds (à reclasser). |
| Dépenses | compte de la catégorie (paramètres) ou compte par défaut / trésorerie ; contrepassation le jour où elle est faite. |
| Banques | versement de clôture : banque (ou coffre) / caisse ; frais, intérêts, ajustements ; dépôts, retraits et virements entre comptes par le compte de virements de fonds (à reclasser selon l'origine réelle). |

Les clients et fournisseurs de la caisse deviennent automatiquement des tiers comptables. Un écart entre les paiements et le total d'une vente est porté au compte de virements de fonds et signalé.

Limites connues : pas de comptabilité analytique, pas de gestion des immobilisations ni des amortissements automatiques, pas de rapprochement bancaire avec import de relevé, pas de déclaration de TVA automatique ; les stocks sont suivis en inventaire intermittent (variation de stock à passer en OD à la clôture).

## Données personnelles

Les tiers (nom, téléphone, NIF, adresse) sont des données personnelles au sens de la loi nigérienne n° 2017-28 relative à la protection des données à caractère personnel : accès limité par les droits ci-dessus, conservation selon les obligations comptables (10 ans pour les pièces et écritures selon l'Acte uniforme OHADA).
