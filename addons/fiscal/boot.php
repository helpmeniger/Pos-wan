<?php
/** Écouteurs du module Marwa Fiscal Connect (chargés seulement si le module est actif). */
use Addons\Fiscal\FiscalDocument;
use Addons\Fiscal\FiscalEngine;
use App\Core\Events;

// Après l'enregistrement d'une vente : certification immédiate, file d'attente en cas de panne.
Events::on('sale.created', function (array $p): array {
    // Mode « au choix » : seule une vente pour laquelle le caissier a coché « Facture certifiée DGI » est certifiée.
    $choice = FiscalEngine::saleChoice((int) $p['sale_id'], $p['fiscal_certify'] ?? null);
    if ($choice === 'ordinaire') {
        return ['fiscal_status' => 'not_requested'];
    }
    // Pont e-SECeF (plusieurs dizaines de secondes) : la vente est enregistrée tout de suite, la caisse suit la
    // certification par /fiscal/ventes/{id}/certifier ; les autres connecteurs restent synchrones.
    $r = FiscalEngine::certifySale((int) $p['sale_id'], !FiscalEngine::isSlowConnector((int) $p['sale_id']));
    return $r['status'] === 'not_required' ? [] : ['fiscal_status' => $r['status'], 'fiscal_message' => $r['message'],
        'fiscal_certification_id' => $r['certification']['id'] ?? null,
        'fiscal_number' => ($r['certification']['status'] ?? '') === 'certified' ? $r['certification']['fiscal_number'] : null];
});
Events::on('sale.returned', function (array $p): void {
    $saleId = (int) (App\Core\DB::value('SELECT sale_id FROM sale_returns WHERE id = ?', [(int) $p['return_id']]) ?? 0);
    FiscalEngine::certifyReturn((int) $p['return_id'], !FiscalEngine::isSlowConnector($saleId));
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
