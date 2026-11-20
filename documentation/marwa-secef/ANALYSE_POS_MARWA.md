# Analyse de POS Marwa pour la certification e-SECeF

## Caisse et ventes

- Caisse : `app/Views/pos/index.php` + `public/assets/js/pos.js` ; validation par `POST /pos/checkout`
  (`PosController::checkout` → `SaleService::create`). Une vente porte un `client_uuid` (clé anti-doublon côté
  caisse : une validation répétée ne crée jamais deux ventes) ; elle peut être gardée hors connexion et envoyée plus tard.
- `SaleService::create` : contrôle des prix, du stock, des remises, du crédit, de l'ordonnance (pharmacie) ;
  enregistrement transactionnel ; **sortie de stock** ; puis événement `sale.created` (les modules réagissent sans
  jamais annuler la vente).
- Retours : `SaleService::createReturn` (événement `sale.returned`) ; annulation : `SaleService::cancel`
  (bloquée par Fiscal Connect pour une facture certifiée : un avoir est nécessaire).

## Tables

| Table | Contenu utile pour la certification |
|---|---|
| `sales` | `number` (n° POS), `client_uuid`, `customer_id`, `user_id`, `subtotal`, `discount_total` (remise globale), `tax_total`, `total`, `status` |
| `sale_items` | `product_id`, `product_name`, `quantity` (3 décimales), `unit_price` (TTC), `discount` (remise de ligne), `tax_rate`, `tax_amount`, `line_total` |
| `sale_payments` | `method` (cash, card, transfer, mobile_money, credit…), `provider`, `amount`, `reference` |
| `customers` | `name`, `nif`, `phone`, `address` |
| `products` | `reference`, `name`, `sale_price` (TTC), `tax_rate`, `category_id`, `track_stock` |
| `sale_returns`, `sale_return_items` | avoirs |

## Taxes, remises, paiements, numéros

- **Taxes** : un taux de TVA par article (`products.tax_rate`), prix TTC. L'e-SFE raisonne en **groupes de taxation**
  (A–L) qui dépendent aussi du **régime** du contribuable (ex. groupe H « REGIME IS », 0 %) : la correspondance est
  donc une décision de l'administrateur (article > catégorie > règle du régime), jamais déduite du seul taux.
- **Remises** : de ligne (`sale_items.discount`) et globale (`sales.discount_total`). L'e-SFE propose trois sortes de
  remise (pourcentage, prix unitaire, montant total) dont la saisie n'a pas encore été observée : une vente remisée
  n'est pas transmise (message clair) plutôt que mal reproduite.
- **Paiements** : correspondance configurable vers les 7 modes de l'e-SFE ; un paiement en plusieurs modes n'est pas
  encore transmis (répartition non observée).
- **Numéros** : le n° POS (`sales.number`) est conservé ; il est placé dans la ligne de commentaire de la facture
  e-SFE pour la retrouver dans le Journal. Les références officielles (Code SECeF/DGI, NIM, compteurs) ne viennent que
  du portail.

## Impressions

- Ticket 80 mm (`/sales/{id}/ticket`, impression navigateur ou ESC/POS serveur) et facture A4 (`/sales/{id}/invoice`) :
  les deux reçoivent le bloc fiscal par l'événement `document.sale` (`FiscalDocument::block`). Pour l'e-SECeF :
  titre « FACTURE CERTIFIÉE e-SECeF », Code SECeF/DGI, SECeF NIM, compteurs, heure, ISF, mention de vérification
  (sygmef.impots.gouv.ne/Verification) ; QR code imprimé seulement s'il provient du portail. Le design reste celui de POS Marwa.
- Facture non certifiée : bandeau « NON CERTIFIÉE » (ou impression bloquée si le profil le demande).

## Droits

| Droit | Usage |
|---|---|
| `pos.sell` | vendre, cocher « Facture certifiée DGI », suivre la certification |
| `fiscal.configure` | configuration MARWA SECEF CONNECT, articles, catégories, test, session |
| `fiscal.secrets` | identifiants e-SECeF, jeton du pont, **autorisation de la certification réelle** |
| `fiscal.view`, `fiscal.queue`, `fiscal.logs` | tableau de bord, file d'attente, journaux (Fiscal Connect) |

## Modifications apportées au cœur

Une seule : `SaleService::create` transmet `fiscal_certify` (choix du caissier) dans l'événement `sale.created`.
Tout le reste est dans le module « fiscal » et dans la caisse (case, fenêtre de suivi, ticket différé).
