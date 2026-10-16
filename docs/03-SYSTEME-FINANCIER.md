# JOB NIGER — 03. Conception du système financier

> Objectif : savoir à tout moment, au franc près, **ce que les clients ont payé**, **ce qui revient
> aux prestataires**, **ce que la plateforme a réellement gagné** et **ce qui est encore en attente** —
> sans jamais présenter comme revenu de JOB NIGER l'argent qui appartient aux prestataires.

## 1. Principes non négociables

1. **Monnaie XOF, sans sous-unité.** Montants en `DECIMAL(15,2)` en base, calculés en PHP en **entiers de
   francs** (classe `Money`) ; arrondi **demi-supérieur à l'unité** (`finance.rounding_mode`).
2. **Partie double.** Chaque événement financier = un journal (`financial_ledger_journals`) dont les
   lignes (`financial_ledger_entries`) sont équilibrées : Σ débits = Σ crédits. Vue de contrôle
   `v_ledger_unbalanced_journals` (doit être vide).
3. **Ajout seul.** Écritures, journaux, transactions, paiements, commissions, contrats et audit sont
   protégés par triggers : aucune suppression, aucune modification de montant. Une erreur se corrige par
   une **contre-passation** (`reverses_journal_id`) ou un **ajustement justifié** (permission
   `finance.adjustments.create`, motif obligatoire, audit).
4. **Idempotence.** Clés uniques : `(gateway, event_id)` des webhooks, `(gateway, gateway_reference)`,
   `payments.idempotency_key`, `commission_records.payment_id`, `financial_ledger_journals.idempotency_key`
   (ex. `payment:123:confirmed`). Rejouer un événement ne produit **rien**.
5. **Atomicité.** Chaque traitement financier s'exécute dans **une** transaction SQL
   (`Database::transaction()`), avec verrou `SELECT … FOR UPDATE` sur le paiement. Toute exception ⇒ `ROLLBACK`.
6. **Statut du paiement ≠ statut de la mission.** Deux machines à états distinctes.
7. **Le navigateur ne fait jamais foi.** Les montants viennent du contrat figé ; la confirmation vient du
   webhook signé du prestataire de paiement (PSP) ou d'une vérification manuelle autorisée.
8. **Pas de détention de fonds simulée.** Tant qu'aucun PSP habilité n'est contractualisé, seul le **mode 1**
   (direct / suivi manuel) est actif. Séquestre et paiement différé : `finance.escrow_enabled = 0`.

## 2. Calcul des commissions

### 2.1 Choix de la règle
`CommissionService::resolveRule(mission)` sélectionne la règle **active**, valide à la date, la plus
spécifique : `CAMPAIGN` > `BUSINESS` > `SUBCATEGORY` > `CATEGORY` > `GLOBAL`, puis `priority` décroissante.
La règle et ses taux sont **copiés dans le contrat** : modifier une règle n'affecte jamais un contrat signé.

Paramètres par défaut (modifiables sans toucher au code) : `worker_rate = 10 %`, `client_rate = 0 %`,
`fixed_fee = 0 FCFA`.

### 2.2 Formule (S = prix de la prestation convenu)
```
client_commission = arrondi(S × client_rate / 100) + (fixed_fee si fixed_fee_payer = CLIENT)
worker_commission = arrondi(S × worker_rate / 100) + (fixed_fee si fixed_fee_payer = WORKER)
total_commission  = client_commission + worker_commission
  → bornée par [min_commission ; max_commission] ; l'écart est réparti au prorata des parts,
    le reliquat d'arrondi est affecté à la part prestataire
client_total = S + client_commission          (ce que paie le demandeur)
worker_net   = S − worker_commission          (ce que reçoit le prestataire, ≥ 0)
commission_included_in_price = (worker_commission > 0)
```
Les contraintes `CHECK` de `contracts` rejettent tout contrat incohérent.

### 2.3 Modèles couverts
| Modèle | client_rate | worker_rate | fixed_fee | S = 10 000 → Client paie | Prestataire reçoit | Plateforme |
|---|---|---|---|---|---|---|
| **Défaut : prestataire 10 %** | 0 | 10 | 0 | **10 000** | **9 000** | **1 000** |
| Demandeur 10 % | 10 | 0 | 0 | 11 000 | 10 000 | 1 000 |
| Partagé 5 / 5 | 5 | 5 | 0 | 10 500 | 9 500 | 1 000 |
| Frais fixes 200 (client) | 0 | 0 | 200 | 10 200 | 10 000 | 200 |
| Mixte 8 % + 100 fixe | 0 | 8 | 100 (client) | 10 100 | 9 200 | 900 |

**Récapitulatif obligatoire avant paiement / acceptation :** prix de la prestation, commission (et qui la
paie), montant total payé par le demandeur, montant net du prestataire, et la mention explicite
« commission **incluse** dans le prix » ou « commission **ajoutée** au prix ».

### 2.4 Exemple multi-postes
5 distributeurs de prospectus × 10 000 FCFA = 50 000 FCFA de prestations (5 missions, 5 contrats).
Commission 10 % prestataire : 5 × 1 000 = **5 000 FCFA** de revenu brut ; **45 000 FCFA** dus aux travailleurs.
Les frais du PSP et les remboursements sont comptabilisés **séparément**.

## 3. Deux modes de paiement

| | Mode 1 — Direct / suivi manuel (**actif au lancement**) | Mode 2 — Intégré (**après contrat PSP**) |
|---|---|---|
| Qui encaisse ? | Le prestataire, directement (Mobile Money perso, espèces) | Le PSP habilité, pour le compte de la plateforme |
| La plateforme détient-elle les fonds ? | **Non** | Fonds chez le PSP, selon son contrat |
| Confirmation | Déclaration du demandeur + **confirmation du prestataire** (+ preuve facultative), ou validation manuelle `finance.payments.verify` | **Webhook signé** + vérification référence / montant / devise |
| Commission | **À recevoir** du prestataire (compte 1210) | Prélevée à la source |
| Statut `verification_type` | `MANUELLE` | `AUTOMATIQUE` |

Les **espèces** sont toujours identifiées comme telles et ne sont **jamais** comptées comme encaissées par la plateforme.

## 4. Plan de comptes interne

| Code | Compte | Nature |
|---|---|---|
| 1010 | Fonds chez le PSP (par passerelle) | Actif |
| 1020 | Trésorerie propre de la plateforme | Actif |
| 1030 | Fonds en transit (versements émis) | Actif |
| 1210 | Commissions à recevoir des prestataires (par prestataire) | Actif |
| 2010 | Dû prestataires — **retenu** (mission non validée) | Passif |
| 2011 | Dû prestataires — **disponible** au versement | Passif |
| 2020 | Remboursements à verser aux demandeurs | Passif |
| 2030 | Commissions **constatées d'avance** (non acquises) | Passif |
| 2040 | Paiements contestés (fonds gelés) | Passif |
| 4010 / 4020 / 4030 | Produits : commissions, abonnements, sponsoring | Produit |
| 4090 | Annulations de commissions | Contre-produit |
| 6010 / 6020 / 6030 | Frais PSP, gestes commerciaux, ajustements | Charge |
| 9010 / 9020 | **Mémo hors bilan** : paiements directs déclarés | Hors fonds plateforme |

> Ce grand livre est un **registre de gestion** vérifiable ; il ne remplace pas la comptabilité légale
> (SYSCOHADA) tenue par un expert-comptable, mais l'alimente via les exports.

## 5. Schémas d'écritures (S = 10 000, commission prestataire 10 %)

### Mode 2 — intégré
| Événement | Débit | Crédit |
|---|---|---|
| Paiement **confirmé** (webhook) | 1010 : 10 000 | 2010 (prestataire) : 9 000 · 2030 : 1 000 |
| Frais PSP (ex. 150) | 6010 : 150 | 1010 : 150 |
| Mission **TERMINÉE** | 2030 : 1 000 · 2010 : 9 000 | 4010 : 1 000 · 2011 : 9 000 |
| Versement émis | 2011 : 9 000 | 1030 : 9 000 |
| Versement confirmé | 1030 : 9 000 | 1010 : 9 000 |
| Versement échoué | 1030 : 9 000 | 2011 : 9 000 (re-disponible) |

Si le PSP confirme le versement immédiatement, une écriture unique `2011 → 1010` est passée.
Une commission n'est un **revenu acquis** (4010) qu'à la fin de la mission ; avant, elle est **en attente** (2030).

### Mode 1 — direct
| Événement | Débit | Crédit |
|---|---|---|
| Paiement direct confirmé par les deux parties | 9010 : 10 000 (mémo) | 9020 : 10 000 (mémo) |
| Mission TERMINÉE | 1210 (prestataire) : 1 000 | 4010 : 1 000 |
| Commission reçue du prestataire | 1020 : 1 000 | 1210 (prestataire) : 1 000 |

### Remboursements
| Cas | Débit | Crédit |
|---|---|---|
| Total **avant** fin de mission (approuvé) | 2010 : 9 000 · 2030 : 1 000 | 2020 (demandeur) : 10 000 |
| Partiel 3 000 **après** fin (90 % prestataire / 10 % commission) | 2011 : 2 700 · 4090 : 300 | 2020 : 3 000 |
| Geste commercial de la plateforme | 6020 : x | 2020 : x |
| Remboursement exécuté par le PSP | 2020 : montant | 1010 : montant |

Si le prestataire a déjà été payé, la part qui lui est reprise devient une créance (1210) **sur décision
motivée**, ou est prise en charge par la plateforme (6020). Jamais d'effacement.

### Contestation / rétrofacturation
Dr 2010 ou 2011 (selon l'état) → Cr 2040 (gel) ; à la résolution, contre-passation ou remboursement.

## 6. Traitement d'une confirmation de paiement (WebhookProcessor)

```
POST /webhooks/{gateway}
 1. Enregistrer l'événement brut (payment_webhook_events) — INSERT IGNORE sur (gateway, event_id)
    → déjà présent : répondre 200, statut IGNORE_DOUBLON, STOP.
 2. Vérifier la signature (HMAC / certificat du PSP) et, si fourni, l'IP source → sinon REJETE + security_event.
 3. Interroger l'API du PSP (« verify transaction ») quand elle existe — ne pas se fier au seul payload.
BEGIN TRANSACTION
 4. SELECT payment … WHERE reference = ? FOR UPDATE   (référence que nous avons émise)
 5. Si statut déjà CONFIRMED → IGNORE_DOUBLON, COMMIT, STOP.
 6. Contrôler montant == payments.amount ET devise == 'XOF' → sinon statut DISPUTED + alerte finance.
 7. UPDATE payments : CONFIRMED, confirmed_amount, gateway_reference, confirmed_at ; payment_status_history.
 8. CommissionService : commission_records (unique par paiement) à partir du CONTRAT.
 9. FinancialLedgerService::post('payment:{id}:confirmed', lignes…) → vérifie l'équilibre avant INSERT.
10. TransactionService : lignes PAIEMENT_MISSION (+ FRAIS_TRANSACTION si frais connus).
11. ReceiptService : reçu numéroté (document_sequences verrouillée FOR UPDATE).
COMMIT
12. Après COMMIT seulement : notifications (paiement confirmé) mises en file.
13. Répondre 200 au PSP (sinon il réessaie ; l'idempotence absorbe les répétitions).
```
Un paiement `PENDING` dont aucun webhook n'arrive est **re-vérifié** par la tâche `payments:reconcile`
(interrogation de l'API du PSP), jamais confirmé par défaut.

## 7. Machines à états

**payments.status :**
```
INITIATED → PENDING → CONFIRMED → REFUND_PENDING → PARTIALLY_REFUNDED → REFUNDED
          ↘ FAILED   ↘ CANCELLED    CONFIRMED → DISPUTED → CONFIRMED | REFUNDED
```
**refunds.status :** `DEMANDE → APPROUVE | REFUSE → EN_COURS → EFFECTUE | ECHOUE` (ou `ANNULE`).
**payouts.status :** `EN_ATTENTE → APPROUVE → EN_COURS → PAYE | ECHOUE` (ou `ANNULE`).
**commission_records.status :** `DIFFEREE → ACQUISE → PARTIELLEMENT_ANNULEE | ANNULEE` ; mode direct
`A_RECEVOIR → ENCAISSEE`.

## 8. Procédure de remboursement (RefundService)
1. Identifier le paiement initial (`CONFIRMED` / `PARTIALLY_REFUNDED`), vérifier montant remboursable
   = `confirmed_amount − refunded_amount`.
2. Appliquer les conditions d'annulation du **contrat** (délai, pénalité) → montant proposé.
3. Enregistrer la demande (`DEMANDE`) avec motif ; notifier.
4. Décision par un détenteur de `finance.refunds.approve` **différent** du demandeur ; ventilation
   `from_worker_share + from_commission + from_platform = approved_amount` (contrainte CHECK).
5. Écritures (section 5), `commission_records.reversed_amount` augmenté, `payments.refunded_amount` augmenté
   (ne peut jamais diminuer), statut du paiement mis à jour.
6. Exécution via le PSP (`supports_refund`) ou manuellement avec preuve ; `EFFECTUE` ; notifications ; audit.

## 9. Versements aux prestataires (PayoutService)
- Montant versable = solde du prestataire sur **2011** − versements en cours. Jamais de versement sur une
  mission en litige ou non validée.
- Préparation (`finance.payouts.request`) puis approbation par une **autre personne**
  (`finance.payouts.approve`, trigger « quatre yeux »), puis exécution PSP ou virement manuel avec preuve.
- `payout_items` relie chaque versement aux paiements/missions couverts.
- Confirmation OTP facultative au prestataire pour un changement de numéro de réception.

## 10. Tableau de bord financier — définitions

| Indicateur | Source | Remarque |
|---|---|---|
| Volume des prestations réalisées | Σ `contracts.service_amount` des missions TERMINÉES | Valeur économique, **pas** un revenu |
| Paiements clients confirmés | Σ `payments.amount` CONFIRMED…, séparés **intégré / direct / espèces** | Direct = déclaré, hors fonds plateforme |
| Commissions brutes générées | Σ `commission_records.total_commission` | dont **en attente** (2030 / A_RECEVOIR) et **acquises** (4010) |
| Commissions annulées | solde 4090 | |
| Frais de paiement | solde 6010 | |
| Remboursements | Σ `refunds.approved_amount` EFFECTUE | |
| **Revenu net estimé** | 4010 + 4020 + 4030 − 4090 − 6010 − 6020 − 6030 | « estimé » : avant impôts et charges d'exploitation |
| Montants dus aux prestataires | soldes 2010 (retenu) + 2011 (disponible) | |
| Montants effectivement reversés | Σ `payouts.amount` PAYE | |
| **Disponible pour la plateforme** | 1020 + (1010 + 1030 − 2010 − 2011 − 2020 − 2030 − 2040) | la part de fonds PSP qui n'est due à personne d'autre |
| Commissions à recevoir (mode direct) | solde 1210 | **non encaissé** — affiché à part |
| En attente / échoués / contestés | `payments` par statut | jamais comptés comme revenus |

Filtres : période, ville, catégorie, statut, passerelle, utilisateur. Séries journalières, mensuelles,
annuelles (Chart.js). Exports CSV (UTF-8 BOM, séparateur `;` pour Excel FR) et XLSX (PhpSpreadsheet).

## 11. Rapprochement
`ReconciliationService` importe le relevé du PSP (CSV/API), apparie par `gateway_reference`, et classe :
apparié, absent chez nous, absent chez le PSP, écart de montant. Les écarts créent une alerte et ne sont
corrigés que par écriture d'ajustement justifiée. Un contrôle quotidien vérifie aussi
`v_ledger_unbalanced_journals` et la cohérence `payments.refunded_amount` ↔ `refunds`.

## 12. Interface des passerelles

```php
interface PaymentGatewayInterface {
    public function code(): string;
    public function capabilities(): GatewayCapabilities;          // webhook, refund, payout, holdsFunds
    public function environment(): GatewayEnvironment;            // INDISPONIBLE | A_CONFIGURER | TEST | PRODUCTION
    public function initiate(Payment $p, Payer $payer): InitiationResult;   // URL hébergée / push USSD
    public function verifyWebhook(Request $r): WebhookVerification;          // signature + parsing
    public function fetchStatus(Payment $p): GatewayStatus;                  // vérification active
    public function refund(Refund $r): GatewayRefundResult;                  // si supporté
    public function payout(Payout $p): GatewayPayoutResult;                  // si supporté
}
```
Implémentations prévues : `ManualGateway`, `CashGateway`, `BankTransferGateway` (manuelles),
`SandboxGateway` (tests, désactivée en production), `MobileMoneyGateway` et `CardGateway`
(**squelettes** jusqu'à obtention des contrats, identifiants et documentation des opérateurs).
Aucun numéro de carte ni code secret Mobile Money n'est jamais saisi ou stocké par JOB NIGER.

## 13. Vérifié en Phase 1
`tests/Database/run_schema_smoke.sh` rejoue le scénario 10 000 FCFA en mode intégré et contrôle :
soldes (4010 = 1 000, 2030 = 0, 2011 = 0 après versement), équilibre global, refus des doublons
(webhook, paiement, commission, journal), refus des suppressions et modifications de montants,
principe des quatre yeux — **30/30 contrôles réussis** sur MariaDB 10.11.
