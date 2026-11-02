<?php
$title = 'Mon espace revendeur';
$types = ['topup' => 'Réapprovisionnement', 'purchase' => 'Achat en gros', 'sale' => 'Vente', 'commission' => 'Commission', 'payout' => 'Versement', 'adjustment' => 'Ajustement', 'refund' => 'Annulation'];
?>
<div class="mc-page-head"><div><h1>Mon espace <span class="mc-accent">revendeur</span></h1><p><?= e($r['business_name']) ?> · <?= $r['business_model'] === 'wholesale' ? 'Achat en gros' : 'Commission ' . (float) $r['commission_rate'] . ' %' ?></p></div></div>
<?php if ($r['status'] !== 'active'): ?><div class="alert alert-danger">Votre compte revendeur est suspendu.</div><?php endif; ?>
<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3"><div class="mc-card mc-kpi <?= (float) $r['balance'] < 0 ? '' : 'green' ?>"><div class="mc-kpi-label">Mon solde</div><div class="mc-kpi-value"><?= e(money($r['balance'])) ?></div><div class="mc-kpi-sub"><?= (float) $r['balance'] < 0 ? 'Montant à reverser' : 'Crédit' ?></div></div></div>
    <div class="col-6 col-lg-3"><div class="mc-card mc-kpi navy"><div class="mc-kpi-label">Ventes du mois</div><div class="mc-kpi-value"><?= e(money($stats['sales_month'])) ?></div></div></div>
    <div class="col-6 col-lg-3"><div class="mc-card mc-kpi amber"><div class="mc-kpi-label">Commissions du mois</div><div class="mc-kpi-value"><?= e(money($stats['commission_month'])) ?></div></div></div>
    <div class="col-6 col-lg-3"><div class="mc-card mc-kpi"><div class="mc-kpi-label">Tickets en stock</div><div class="mc-kpi-value"><?= count($tickets) ?></div></div></div>
</div>
<div class="row g-3">
    <div class="col-lg-7">
        <div class="mc-card mb-3">
            <form class="mc-card-head mc-filter-bar" method="get" action="<?= e(url('/reseller')) ?>">
                <h2 class="me-auto">Mon stock</h2>
                <select class="form-select form-select-sm" name="offer_id" data-autosubmit><option value="0">Toutes les offres</option><?php foreach ($stats['stock'] as $s): ?><option value="<?= (int) $s['offer_id'] ?>" <?= $offerId === (int) $s['offer_id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select>
                <input class="form-control form-control-sm" type="search" name="q" value="<?= e($q) ?>" placeholder="Identifiant">
            </form>
            <form method="get" action="<?= e(url('/tickets/print')) ?>" target="_blank">
            <div class="table-responsive" style="max-height: 55vh"><table class="table mc-table table-sm table-hover"><thead><tr><th><input class="form-check-input" type="checkbox" data-check-all="pt" aria-label="Tout"></th><th>Ticket</th><th>Offre</th><th class="text-end">Prix</th><th>Remettre à un client</th></tr></thead><tbody>
            <?php foreach ($tickets as $t): ?><tr><td><input class="form-check-input" type="checkbox" name="ids[]" value="<?= (int) $t['id'] ?>" data-check-group="pt"></td><td><code><?= e($t['username']) ?></code></td><td class="small"><?= e($t['offer_name']) ?></td><td class="text-end small"><?= e(money($t['price'])) ?></td>
                <td><button type="button" class="btn btn-sm btn-outline-primary" data-portal-sell="<?= (int) $t['id'] ?>" data-label="<?= e($t['username']) ?>"><i class="bi bi-person-check"></i> Vendre</button></td></tr><?php endforeach; ?>
            <?php if (!$tickets): ?><tr><td colspan="5"><div class="mc-empty"><i class="bi bi-box"></i>Aucun ticket en stock. Contactez votre fournisseur.</div></td></tr><?php endif; ?>
            </tbody></table></div>
            <?php if ($tickets): ?><div class="mc-card-head border-top border-bottom-0 mc-filter-bar"><select class="form-select form-select-sm" name="template_id"><?php foreach ($templates as $tp): ?><option value="<?= (int) $tp['id'] ?>"><?= e($tp['name']) ?></option><?php endforeach; ?></select>
                <button class="btn btn-sm btn-outline-secondary" name="output" value="html"><i class="bi bi-printer"></i> Imprimer la sélection</button><button class="btn btn-sm btn-outline-secondary" name="output" value="pdf"><i class="bi bi-filetype-pdf"></i> PDF</button></div><?php endif; ?>
            </form>
        </div>
        <form method="post" action="<?= e(url('/reseller/sell')) ?>" id="portal-sell-form" class="d-none"><?= csrf_field() ?><input type="hidden" name="ticket_id"><input type="hidden" name="phone"></form>
    </div>
    <div class="col-lg-5">
        <div class="mc-card mb-3"><div class="mc-card-head"><h2>Mes dernières ventes</h2></div><ul class="list-group list-group-flush">
            <?php foreach ($sales as $s): ?><li class="list-group-item d-flex justify-content-between small"><span><code><?= e($s['username']) ?></code> · <?= e($s['offer_name']) ?><br><span class="text-muted"><?= e($s['customer_phone'] ?? '') ?> · <?= e(date_fr($s['sold_at'])) ?></span></span><span><?= status_badge($s['status']) ?></span></li><?php endforeach; ?>
            <?php if (!$sales): ?><li class="list-group-item small text-muted">Aucune vente.</li><?php endif; ?></ul></div>
        <div class="mc-card"><div class="mc-card-head"><h2>Mon compte</h2></div><ul class="list-group list-group-flush">
            <?php foreach ($transactions as $t): ?><li class="list-group-item d-flex justify-content-between small"><span><?= e($types[$t['type']] ?? $t['type']) ?> — <?= e($t['description']) ?><br><span class="text-muted"><?= e(date_fr($t['created_at'])) ?></span></span><span class="<?= (float) $t['amount'] < 0 ? 'text-danger' : 'text-success' ?>"><?= e(money($t['amount'])) ?></span></li><?php endforeach; ?>
            <?php if (!$transactions): ?><li class="list-group-item small text-muted">Aucun mouvement.</li><?php endif; ?></ul></div>
    </div>
</div>
