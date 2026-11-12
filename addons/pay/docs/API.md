# Marwa Pay Connect — Documentation technique (API internes)

## Interface `PaymentDriver` (connecteurs et terminaux)
`info()`, `capabilities()`, `testConnection()`, `initiate(array $p)`, `getStatus(array $tx)`, `cancel(array $tx)`, `refund(array $tx, array $r)`,
`getRefundStatus(array $tx, array $refund)`, `parseWebhook(string $body, array $headers)` ; `webhookSignatureValid()` dans `AbstractDriver`.

`$p` (demande) : `number`, `idempotency_key` (SHA-256 unique), `amount` (montant à débiter, devise du moyen), `currency`, `description`,
`payer_phone`, `webhook_url`, `register`, `store_id`, `authorization_code` (TPE autonome).

## `GatewayResult::code`
Paiement : `succeeded`, `pending`, `processing`, `declined`, `cancelled`, `expired`, `to_verify`, `refunded`.
Technique : `ok`, `unsupported`, `unreachable`, `timeout`, `auth_failed`, `invalid_response`, `config_error`, `not_found`.
Données reconnues : `provider_reference`, `authorization_code`, `redirect_url`, `amount`, `currency`, `fees`, `payer_hint`.

## Règles du moteur (`PayEngine`)
| Situation | Résultat |
|---|---|
| `succeeded` + montant, devise, référence cohérents | Réussi (preuve conservée, secrets masqués) |
| `succeeded` incohérent | À vérifier (jamais réussi) |
| `timeout` / réponse illisible à l'envoi | En cours si le prestataire peut être interrogé, sinon À vérifier |
| `unreachable`, authentification, configuration à l'envoi | Échec technique (rien n'a été débité) |
| Délai dépassé | Annulation demandée au prestataire ; Expiré ; un paiement tardif confirmé reste traçable |
| Même demande renvoyée (double clic, réseau) | Même transaction, aucun second débit |
| Notification | Signature vérifiée, doublon ignoré, statut revérifié auprès du prestataire si possible |

Statuts d'une transaction : `pending`, `processing`, `succeeded`, `declined`, `cancelled`, `expired`, `refunded`, `partially_refunded`, `to_verify`, `failed`.

## Liaison avec les ventes
- La caisse envoie chaque ligne électronique avec `transaction` (id) dans `payments` de `/pos/checkout`.
- Événement `sale.payments` (avant enregistrement) : la transaction doit être réussie (ou TPE autonome avec n° d'autorisation), de même montant,
  même moyen, même caisse, non TEST (sauf option de formation), et appartenir à ce panier ; elle est alors réservée.
- Événement `sale.created` : liaison définitive `payment_transactions.sale_id`.
- Le règlement est inscrit dans `sale_payments` avec la méthode existante (carte, mobile money, virement) et la référence du prestataire.

## Points d'accès
| Méthode | Route | Droit |
|---|---|---|
| GET | `/pay/caisse/moyens` | pay.use |
| POST | `/pay/caisse/payer` `{method_id, amount, cart_uuid, request_key, payer_phone?, customer_id?, authorization_code?}` | pay.use |
| GET | `/pay/caisse/transactions/{id}` (vérifie l'état auprès du prestataire si en attente) | pay.use (sa caisse) |
| POST | `/pay/caisse/transactions/{id}/annuler` | pay.use (sa caisse) |
| POST | `/pay/webhook/{id}?t=<jeton>` en-tête `X-Pay-Signature: sha256=<HMAC>` (ou schéma configuré) | public, signé |

## Pont local
`POST /v1/ping`, `POST /v1/terminal {device, baud, data_bits, parity, stop_bits, timeout, payload}` ;
en-têtes `X-Bridge-Timestamp`, `X-Bridge-Nonce`, `X-Bridge-Signature = HMAC-SHA256(ts + "\n" + nonce + "\n" + corps, secret)`.

## Tables
`payment_providers`, `payment_connectors`, `payment_api_credentials` (valeurs chiffrées), `payment_terminals`, `payment_methods`,
`payment_transactions`, `payment_transaction_logs` (requêtes et réponses masquées), `payment_refunds`, `payment_webhooks`,
`payment_currencies`, `payment_exchange_rates`, `payment_reconciliation`, `payment_settings`.
