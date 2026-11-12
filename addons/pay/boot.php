<?php
/** Écouteurs du module Marwa Pay Connect (chargés seulement si le module est actif). */
use Addons\Pay\PayEngine;
use App\Core\Events;

// Avant l'enregistrement d'une vente : chaque ligne électronique doit correspondre à un paiement confirmé et non utilisé.
Events::on('sale.payments', fn(array $p) => PayEngine::claimForSale((string) $p['cart_uuid'], (array) $p['payments'], $p['register_id'] ?? null));
// Après l'enregistrement : liaison définitive des transactions à la vente.
Events::on('sale.created', function (array $p): ?array {
    if (!empty($p['client_uuid'])) {
        PayEngine::linkSale((string) $p['client_uuid'], (int) $p['sale_id']);
    }
    return null;
});
