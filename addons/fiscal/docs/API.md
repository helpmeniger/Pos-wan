# Pilotes et API internes

## Interface `FiscalDriver`
`connect`, `disconnect`, `testConnection`, `authenticate`, `certifyInvoice`, `getInvoiceStatus`, `getCertificate`,
`getDeviceInfo`, `getDailyReport`, `closeDay`, `cancelInvoice`, `issueCreditNote`. Chaque fonction renvoie un `DriverResult`.

## `DriverResult::code`
`certified`, `ok`, `rejected` (refus définitif), `unsupported`, `unreachable`, `timeout`, `invalid_response` (ces trois-là : nouvel essai),
`auth_failed`, `config_error` (intervention humaine), `not_found`.

## Facture normalisée (entrée des pilotes)
```
type (invoice|credit_note), idempotency_key (SHA-256, unique), local_number, issued_at, environment, country, currency,
seller {name, nif, rccm, regime, address, phone, store, register, operator},
buyer {name, nif, phone, address} | null,
lines [{ref, name, qty, unit_price_ttc, discount, total_ttc, tax_rate, tax_group, tax_amount, total_ht}],
taxes [{group, rate, base_ht, tax}], totals {ht, tva, ttc, discount}, payments [{method, provider, amount}],
original {local_number, fiscal_number, device_id, reason} (avoirs)
```

## Configuration du pilote REST (`generic_rest`)
```
{
  "base_url": "https://api-test.exemple/v1",
  "verify_ssl": true,
  "headers": {"X-Client": "POS-Marwa"},
  "auth": {"type": "api_key|bearer|basic|oauth2|none", "header": "X-Api-Key", "token_url": "https://…/token", "scope": "…"},
  "endpoints": {
    "test": {"method": "GET", "path": "/status"},
    "certify": {"method": "POST", "path": "/invoices"},
    "status": {"method": "GET", "path": "/invoices/{{idempotency_key}}"},
    "credit_note": {"method": "POST", "path": "/credit-notes"},
    "daily_report": {"method": "GET", "path": "/reports/{{date}}"},
    "close_day": {"method": "POST", "path": "/close"}
  },
  "request_template": "{\"number\":\"{{local_number}}\",\"total\":{{json:totals.ttc}},\"items\":{{json:lines}}}",
  "response_map": {"fiscal_number": "data.code", "device_id": "data.mcf", "signature": "data.sig", "qr_payload": "data.qr", "counters": "data.counters"},
  "success": {"path": "status", "values": ["ACCEPTED"]},
  "reject_http": [400, 409, 422],
  "required_fields": ["signature"]
}
```
- Production : HTTPS obligatoire, vérification SSL toujours active.
- HTTP 401/403 → `auth_failed` ; 404 sur l'état → `not_found` ; 408/429/5xx → `unreachable` ; 400/409/422 → `rejected`.
- OAuth2 « client credentials » : jeton demandé une fois, chiffré, réutilisé jusqu'à expiration, renouvelé une fois sur 401.

## Webhook
`POST /fiscal/webhook/{id}?t=<jeton>` avec l'en-tête `X-Fiscal-Signature: sha256=<HMAC-SHA256(corps, secret)>` et un corps JSON
contenant `idempotency_key`. Le contenu n'est jamais recopié : il déclenche seulement `getInvoiceStatus` auprès du service.

## Événements du cœur utilisés
`sale.created`, `sale.returned`, `sale.cancelling` (peut refuser), `sale.cancelled`, `document.sale` (bloc fiscal), `document.print` (peut bloquer).
Déclarés dans `addons/fiscal/boot.php`, chargés seulement si le module est actif. Une erreur du module n'annule jamais une vente.
