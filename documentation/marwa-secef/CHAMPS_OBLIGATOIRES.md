# Champs de l'e-SFE : obligatoires, facultatifs, conditions

Sources : écrans de l'e-SFE observés le 20/11/2026 (nom technique `formcontrolname` entre parenthèses),
messages de l'application (`LIBELLES_E-SFE_fr.txt`) et manuel officiel DGI (février 2023).
✅ observé · 📄 message/manuel de la DGI · ❓ non vérifiable sans certifier

## Article (« Programmer l'article »)

| Champ | Obligatoire | Règle | Source |
|---|---|---|---|
| Désignation (`name`) | **oui** | « La désignation est un champ obligatoire » | ✅ 📄 |
| Code (`code`) | non | code de l'article | ✅ |
| Groupe de taxation (`taxGroup`) | **oui** | A, B, C, D, F, G, H, I, J, K, L ; « Groupe de taxation est un champ obligatoire » | ✅ 📄 |
| Mode TTC / HT | oui (un des deux) | MODE TTC → Prix [TTC] saisi, Prix [HT] grisé ; MODE HT → l'inverse | ✅ |
| Prix [TTC] (`priceInc`) / Prix [HT] (`priceExc`) | **oui** | nombre ; « La prix unitaire est un champ obligatoire » ; « Le prix doit être supérieur ou égal à … » | ✅ 📄 |
| Taxe spécifique applicable (`isTsApplicable`) | non | si activée : montant par unité ou pourcentage | ✅ 📄 |

## Groupes de taxation (liste de l'e-SFE)

| Lettre | Libellé exact du portail |
|---|---|
| A | EXONERE |
| B | TAXABLE 19% |
| C | TAXABLE 10% |
| D | TAXABLE 5% |
| F | EXPORTATION |
| G | TVA REGIME DEROGATOIRE (commentaire A : « réf exo en cas d'articles du groupe G ») |
| H | REGIME IS |
| I | CONSIGNATION D'EMBALLAGE |
| J | DEPOTS |
| K | DEBOURS |
| L | TIMBRE |

(La lettre E n'apparaît pas dans la liste.) Le choix du groupe relève du contribuable et de son régime
fiscal : POS Marwa ne doit **jamais le deviner** à partir du taux de TVA ; l'administrateur fait la
correspondance, article par article ou par catégorie.

## Facture

| Élément | Obligatoire | Règle | Source |
|---|---|---|---|
| Type | **oui** | FV, FT, FA, EV, ET, EA (les types désactivés dans Configuration ne sont pas proposés) | ✅ |
| Mode de prix | **oui** | PRIX TTC ou PRIX HT | ✅ |
| Opérateur | **oui** | « Il est nécessaire d'enregistrer au moins un nom d'opérateur… afin d'émettre des factures » | ✅ 📄 |
| Au moins un article | **oui** | « Le montant de la facture ne peut pas être 0… » | ✅ |
| Article | **doit être programmé** | ajout depuis la liste des articles programmés (icône sac +), quantité 1 puis modifiable | ✅ |
| Quantité | oui | décimale, 3 décimales au plus ; « Quantité est un champ obligatoire » | 📄 |
| Remise | non | pourcentage, par prix unitaire (sans TS) ou montant total (sans TS) | 📄 |
| Client | **non**, sauf précompte ISB | « Les informations du client ne sont pas obligatoires mais au cas où la facture doit faire figurer le précompte ISB, les informations sur le client sont obligatoires » | 📄 |
| Type de client | si client | Personne physique / Personne morale, à choisir **avant** les autres champs | ✅ |
| NIF du client (`tin`) | si client avec ISB | 13 caractères (« L'NIF ne comporte pas 13 caractères », « L'NIF n'est pas valide ») | ✅ 📄 |
| Nom, Adresse, Contact du client | si client | — | ✅ |
| Précompte ISB | non | N/A, ISB 2%, ISB 5% | ✅ |
| Paiement | oui (ESPECES par défaut d'après le manuel) | ESPECES, MOBILE MONEY, VIREMENT, CARTE BANCAIRE, CHEQUES, AUTRE 1, AUTRE 2 ; répartition possible | ✅ 📄 |
| Commentaires | non | lignes A… ; A obligatoire en pratique pour le groupe G (référence d'exonération) | 📄 |
| Référence (avoir FA/EA) | **oui pour un avoir** | Code SECeF/DGI de la facture de vente d'origine ; « La référence à la facture originale de vente est obligatoire en cas de facture d'avoir ! » | 📄 |

## Éléments renvoyés après certification ❓ (non observés : aucune certification faite)

D'après l'application (📄) : **Code SECeF/DGI** (29 caractères au plus d'après la page de vérification),
**SECeF NIM**, **SECeF Compteurs**, **SECeF Heure**, PDF officiel (« Télécharger le PDF »). Le contenu du
QR code et la mise en page du PDF restent à relever sur une facture certifiée réelle.
