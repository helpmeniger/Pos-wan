<?php
/** Écouteurs du module Marwa Fiscal Connect (chargés seulement si le module est actif). */
use Addons\Fiscal\FiscalDocument;
use Addons\Fiscal\FiscalEngine;
use App\Core\Events;

// Après l'enregistrement d'une vente : certification immédiate, file d'attente en cas de panne.
Events::on('sale.created', function (array $p): array {
    $r = FiscalEngine::certifySale((int) $p['sale_id'], true);
    return $r['status'] === 'not_required' ? [] : ['fiscal_status' => $r['status'], 'fiscal_message' => $r['message'],
        'fiscal_number' => ($r['certification']['status'] ?? '') === 'certified' ? $r['certification']['fiscal_number'] : null];
});
Events::on('sale.returned', function (array $p): void {
    FiscalEngine::certifyReturn((int) $p['return_id'], true);
});
Events::on('sale.cancelling', fn(array $p) => FiscalEngine::cancelBlocker((int) $p['sale_id']));
Events::on('sale.cancelled', function (array $p): void {
    FiscalEngine::onCancelled((int) $p['sale_id']);
});
Events::on('document.sale', function (array $p): ?array {
    $b = FiscalDocument::block((int) $p['sale_id']);
    return $b ? ['fiscal' => $b] : null;
});
Events::on('document.print', fn(array $p) => FiscalDocument::printBlocker((int) $p['sale_id']));
