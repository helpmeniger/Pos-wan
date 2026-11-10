# Module ONG — projets, bailleurs et terrain (1.0.0)

Gestion d'une ONG ou d'une association. Requiert POS Marwa 3.5.0.
Installation : Configuration → Modules complémentaires → « ONG » → Installer. Le menu **ONG** apparaît.

## Ce que fait le module
| Écran | Contenu |
|---|---|
| Tableau de bord | Financements actifs, fonds reçus, dépensé, avances de mission à justifier, dépenses à approuver et à payer, conventions qui se terminent bientôt, soldes des comptes, stocks sous le seuil |
| Projets et bailleurs | Fiches des bailleurs et des projets ; fiche projet avec conventions, missions, personnel affecté, distributions et **indicateurs de résultat** (cible / réalisé) |
| Conventions et budgets | Convention de financement (bailleur, projet, montant, période, périodicité des rapports) et **lignes budgétaires** ; suivi budget / dépensé / engagé / disponible ; **rapport financier** imprimable pour le bailleur ; export CSV / PDF |
| Dépenses | Saisie imputée à une ligne budgétaire ; circuit **soumise → approuvée → payée** ; rejet et annulation motivés ; pièce de dépense imprimable ; journal exportable |
| Trésorerie et fonds | Comptes bancaires, caisses, mobile money ; tranches reçues des bailleurs ; virements internes ; journal de trésorerie |
| Missions terrain | Demande, approbation, **ordre de mission** imprimable, avance, départ, rapport de retour, justification et clôture |
| Logistique | Véhicules et **carnet de bord** (kilométrage, carburant, consommation), registre des **équipements** (financement, détenteur, état), **stocks et distributions** aux bénéficiaires |
| Personnel | Fiches du personnel (salariés, consultants, volontaires), affectation aux projets, fin de contrat signalée |

## Règles tenues par le logiciel
- Le total des lignes budgétaires ne dépasse jamais le montant de la convention.
- Une dépense est refusée si elle dépasse le disponible de sa ligne (dépenses soumises, approuvées et payées + missions approuvées non clôturées), ou si sa date est hors de la période de la convention. Réglage `ong_depassement_ligne` = 1 pour autoriser le dépassement.
- **Séparation des tâches** : la personne qui saisit une dépense ou une demande de mission ne peut pas l'approuver (réglage `ong_separation`, activé par défaut).
- L'argent ne bouge que par un mouvement de trésorerie ; un solde ne devient jamais négatif. Rien n'est effacé : une dépense payée s'annule avec un motif et son paiement est contre-passé.
- Mission : l'avance sort du compte et reste « à justifier ». À la clôture, le total justifié devient une dépense imputée au budget ; le reliquat est reversé, un dépassement est payé en complément. Le rapport de mission est obligatoire.
- Carnet de bord : le compteur d'un véhicule ne recule jamais. Stocks : jamais de stock négatif ; une distribution exige le site.

## Droits
`ong.view`, `ong.projets`, `ong.depenses`, `ong.approve`, `ong.tresorerie`, `ong.logistique`, `ong.personnel`, `ong.admin`.
Rôles créés : **Coordinateur (ONG)**, **Comptable (ONG)**, **Logisticien (ONG)**.

## Limites
- Une seule devise (celle de POS Marwa) : pas de conversion pour les financements en euros ou en dollars.
- Pas de demandes d'achat avec comparaison de devis, ni de cofinancement d'une même dépense par plusieurs bailleurs.
- Le rapport financier est un état de gestion : à rapprocher des pièces et de la comptabilité avant transmission au bailleur. Il ne remplace pas le format propre à chaque bailleur.
- Le personnel est une fiche simple ; contrats, congés et paie sont dans le module Ressources humaines.
- Données personnelles (personnel, bénéficiaires) : loi n° 2017-28 du Niger ; le module ne stocke pas la liste nominative des bénéficiaires.
