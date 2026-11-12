# Ajouter un prestataire de paiement

## Règles
- Jamais de connexion « fictive » : un connecteur ne renvoie un succès que sur réponse authentifiée du prestataire.
- Jamais de numéro de carte complet, CVV ou PIN dans POS Marwa : page de paiement hébergée, tokenisation ou terminal certifié (PCI DSS).
- Documentation officielle, contrat marchand et accès sandbox **avant** tout développement.

## Étape 1 — Essayer le connecteur générique
- API REST/JSON → `generic_rest` : `endpoints`, `auth`, gabarits `body`, `response`, `status_map`, `refund_status_map`, `amount_unit`, `webhook`.
- Service SOAP → `generic_soap` : enveloppes XML (`template`) + XPath dans `response`.
- Page hébergée sans API de consultation → `generic_webhook` : `payment_url_template` + notification signée.
Tester en TEST (sandbox) : paiement accepté, refusé, en attente, annulé, remboursé, notification en double.

## Étape 2 — Connecteur dédié (signature spécifique, flux particulier)
1. Créer `addons/pay/src/Drivers/<Dossier>/<Nom>Gateway.php` (`Addons\Pay\Drivers\<Dossier>`), étendre `AbstractDriver` (ou `RestApiGateway`).
2. `info()` avec `'verified' => false` tant que les essais officiels ne sont pas concluants ; `capabilities()` = fonctions réellement disponibles.
3. `initiate()` : renvoyer `SUCCEEDED` uniquement sur confirmation du prestataire, sinon `PENDING`/`PROCESSING` (avec `redirect_url` éventuel).
4. `getStatus()` : indispensable (vérification serveur à serveur, reprise après coupure).
5. `refund()` / `getRefundStatus()` si l'API le permet ; sinon le remboursement électronique est refusé et l'opérateur rembourse autrement.
6. `webhookSignatureValid()` et `parseWebhook()` selon le schéma de signature du prestataire.
7. Déclarer le connecteur dans `DriverRegistry::GATEWAYS` ; ajouter des tests dans `tests/run.php`.
8. Passer `verified` à `true` seulement après validation en sandbox, en citant la version de la documentation.

## Squelettes livrés (non compatibles)
`Cards/VisaGateway`, `Cards/MastercardGateway`, `Cards/GenericCardGateway`, `Wallets/PaypalGateway`, `Wallets/MobileMoneyGateway`,
`Niger/MyNitaGateway`, `Niger/AmanaGateway`, `Banking/BankApiGateway` : ils répondent tous « non pris en charge ».
Visa et Mastercard s'acceptent via un acquéreur ou un prestataire certifié, pas directement.
