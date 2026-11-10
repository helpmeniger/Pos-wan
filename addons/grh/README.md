# Module Ressources humaines (1.0.0)

Gestion des ressources humaines d'une entreprise, d'une ONG ou d'une administration. Requiert POS Marwa 3.5.0.
Installation : Configuration → Modules complémentaires → « Ressources humaines » → Installer. Le menu **Ressources humaines** apparaît.

## Ce que fait le module
| Écran | Contenu |
|---|---|
| Tableau de bord RH | Effectif (femmes / hommes), masse salariale, contrats qui se terminent, employés sans contrat, congés du jour et demandes à traiter, pointage du jour, effectif par département et par type de contrat |
| Employés | Annuaire avec recherche et filtres, export CSV / PDF ; fiche complète : identité, coordonnées, emploi, responsable hiérarchique, ancienneté |
| Fiche employé | Contrats successifs, dossier (évaluations notées sur 20, sanctions, promotions, formations, notes), congés et solde, présences du mois, bulletins et avances, suspension, sortie, **attestation / certificat de travail** imprimable |
| Structure et organigramme | Départements en arbre avec leur responsable et leur équipe, postes avec catégorie et salaire de référence |
| Congés | Demande, approbation ou refus motivé, annulation ; jours ouvrés calculés ; solde de congé annuel |
| Présences | Pointage quotidien (présent, retard, absent, mission, repos), cumul du mois, export |
| Paie et bulletins | Préparation mensuelle, éléments variables par bulletin, validation, paiement, **bulletin de paie** imprimable, état de paie CSV / PDF |
| Avances sur salaire | Avance avec retenue mensuelle, retenue automatique à la validation de la paie |

## Règles tenues par le logiciel
- Un employé n'a qu'**un contrat en cours** ; un nouveau contrat (avenant, renouvellement) termine le précédent la veille. Un CDD, un essai ou un stage a une date de fin.
- Congés : comptés en **jours ouvrés** (samedi et dimanche exclus) ; deux congés ne se chevauchent pas ; un congé annuel n'est approuvé que si le **solde** suffit. Solde = mois complets depuis l'embauche × `grh_conges_par_mois` (2,5 par défaut) − jours approuvés.
- Paie : brut = base + primes fixes + prime exceptionnelle + heures supplémentaires − absences non payées (base ÷ `grh_jours_mois` × jours d'absence et de congé sans solde). Net = brut − cotisation sociale − impôt − avances − autres retenues. **Jamais de net négatif.**
- La paie est un brouillon modifiable et recalculable ; la **validation** verrouille les bulletins et les présences du mois, et retient les avances.
- Les retenues mensuelles d'avances ne dépassent pas le **tiers du salaire**.
- Rien n'est effacé : un employé **sort** (date et motif), sa fiche et son historique restent.

## Réglages (à vérifier avec votre comptable)
| Clé | Défaut | Rôle |
|---|---|---|
| `grh_cnss_salarie` | 5.25 | Cotisation sociale, part salariale (%) |
| `grh_cnss_employeur` | 16.5 | Cotisation sociale, part patronale (%) |
| `grh_cnss_plafond` | 500000 | Plafond mensuel de l'assiette (0 = sans plafond) |
| `grh_impot_taux` | 0 | Taux d'impôt appliqué au brut moins la cotisation (%) |
| `grh_conges_par_mois` | 2.5 | Jours de congé acquis par mois |
| `grh_jours_mois` | 30 | Diviseur pour la retenue d'absence |
| `grh_alerte_contrat_jours` | 30 | Alerte de fin de contrat |

## Droits
`grh.view`, `grh.manage`, `grh.conges`, `grh.presences`, `grh.paie` (salaires, contrats, avances, bulletins). Sans `grh.paie`, aucun salaire n'est affiché.
Rôles créés : **Responsable RH**, **Assistant RH**.

## Limites
- **L'impôt sur les salaires n'est pas calculé selon le barème officiel** (tranches, charges de famille) : un taux unique en réglage, ou un montant saisi sur chaque bulletin. Les taux de cotisation fournis sont indicatifs. Le module n'est pas un logiciel de paie certifié : faites valider les bulletins par votre comptable.
- Jours fériés non déduits des congés ; pas de calcul automatique des heures supplémentaires (montant saisi) ni du solde de tout compte.
- La paie n'écrit pas dans la caisse ni dans la comptabilité : le paiement des salaires se saisit à part (dépense, banque).
- Pas de recrutement (candidatures), ni de portail employé.
- Données personnelles (loi n° 2017-28 du Niger) : chaque consultation d'une fiche et chaque export sont inscrits au journal d'audit ; les données ne sont pas chiffrées dans la base.
