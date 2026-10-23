# Banques et coffres (module `banques` 1.0.0, POS Marwa 2.8)

Suit l'argent **sorti de la caisse** : versement des clôtures de caisse, dépôts et retraits, virements entre
comptes. Chaque opération garde son historique ; rien n'est jamais effacé. Menu **Caisse → Banques et
coffres** et **Clôtures à verser**. Le module est installé d'office : désactivez-le dans Modules
complémentaires si vous ne l'utilisez pas.

## Comptes
Un compte peut être :
- un **compte bancaire** (banque, n° de compte) ;
- un **coffre / lieu sûr** ;
- l'**argent gardé par le propriétaire** ;
- un **compte mobile money** ;
- un autre lieu.

Le **solde actuel** saisi à la création devient une première opération « Ajustement ». Ensuite, le solde
ne change que par des opérations. Un compte qui a encore un solde ne peut pas être désactivé.

## Opérations
- **Verser une clôture de caisse** (*Clôtures à verser*) :
  - pour chaque caisse clôturée : montant compté, écart, déjà versé, reste ;
  - le versement ne peut pas dépasser le reste ;
  - il peut être réparti (banque + coffre + propriétaire) ;
  - n° de bordereau et nom de la personne qui verse ;
  - le reste inclut le fond de caisse laissé dans le tiroir : versez seulement ce qui sort réellement.
- **Dépôt**, **retrait** (motif obligatoire), **virement** entre deux comptes (deux lignes liées, même
  numéro), **frais bancaires**, **intérêts**.
- **Ajuster un solde** (propriétaire) : après comparaison avec le relevé de la banque, motif obligatoire.
- Un solde ne peut pas devenir négatif. Exception : le réglage `banques_decouvert` = 1 autorise le
  découvert.
- **Reçu imprimable** pour chaque opération : montant en lettres, signatures « remis par / reçu par ».

## Annulation
Réservée au propriétaire (`banques.manage`), motif obligatoire.
- L'opération reste dans l'historique, barrée, et le solde est rétabli.
- Un virement annulé est annulé sur les deux comptes.
- L'annulation est refusée si elle rendait un solde négatif (l'argent a déjà été retiré).
- Un versement annulé remet la clôture « à verser ».

## Relevé et historique
- **Relevé par compte** sur une période : solde au début, entrées, sorties, solde après chaque ligne,
  solde à la fin ; export CSV ou PDF.
- **Historique de toutes les opérations** : filtres par compte, type et recherche (n°, bordereau,
  personne).
- La page d'accueil signale toute incohérence entre un solde et ses opérations ; il n'y en a pas en
  temps normal.

## Droits
| Droit | Rôles par défaut | Permet |
|---|---|---|
| `banques.view` | propriétaire, gérant, auditeur | voir |
| `banques.operate` | propriétaire, gérant | verser, déposer, retirer, virer |
| `banques.manage` | propriétaire | comptes, ajustements, annulations |

Le caissier n'y a pas accès.

## Limites
- Pas de liaison directe avec les banques : les opérations sont saisies.
- Un retrait vers la caisse n'alimente pas automatiquement le fond de caisse : faites-le dans
  « Ma caisse → Entrée d'espèces ».
