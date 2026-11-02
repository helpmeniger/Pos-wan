<?php
$title = 'Vente ' . $sale['reference'];
$channels = ['physical' => 'Caisse', 'online' => 'En ligne', 'reseller' => 'Revendeur'];
?>
<div class="mc-page-head">
    <div><h1>Vente <span class="mc-accent"><?= e($sale['reference']) ?></span> <?= status_badge($sale['status']) ?></h1>
        <p><?= e(date_fr($sale['created_at'])) ?> · <?= e($channels[$sale['channel']] ?? '') ?> · <?= e($sale['reseller_name'] ?: $sale['cashier'] ?: '') ?> · <?= e($sale['router_name'] ?? '') ?></p></div>
    <div class="d-flex gap-2 flex-wrap">
        <a class="btn btn-primary" target="_blank" href="<?= e(url('/sales/' . $sale['id'] . '/receipt')) ?>"><i class="bi bi-printer me-1"></i> Reçu + tickets</a>
        <a class="btn btn-outline-secondary" target="_blank" href="<?= e(url('/tickets/print', ['sale_id' => $sale['id'], 'output' => 'pdf', 'format' => 'thermal80'])) ?>"><i class="bi bi-filetype-pdf"></i> PDF</a>
        <a class="btn btn-outline-secondary" href="<?= e(url(can('sales.create') ? '/pos' : '/sales')) ?>"><i class="bi bi-arrow-left"></i></a>
    </div>
</div>
<div class="row g-3">
    <div class="col-lg-7">
        <div class="mc-card mb-3"><div class="mc-card-head"><h2>Tickets vendus</h2></div>
            <div class="table-responsive"><table class="table mc-table"><thead><tr><th>Identifiant</th><th>Offre</th><th>Statut</th><th class="text-end">Prix</th></tr></thead><tbody>
            <?php foreach ($tickets as $t): ?><tr><td><a href="<?= e(url('/tickets/' . $t['id'])) ?>"><code><?= e($t['username']) ?></code></a></td><td><?= e($t['offer_name']) ?></td><td><?= status_badge($t['status']) ?></td><td class="text-end"><?= e(money($t['price'])) ?></td></tr><?php endforeach; ?>
            <?php if (!$tickets): ?><tr><td colspan="4" class="text-muted text-center py-3">Aucun ticket (vente annulée : les tickets ont été remis en stock).</td></tr><?php endif; ?>
            </tbody></table></div>
            <div class="mc-card-body border-top small">
                <div class="d-flex justify-content-between"><span>Sous-total</span><span><?= e(money($sale['subtotal'])) ?></span></div>
                <div class="d-flex justify-content-between"><span>Remise</span><span>− <?= e(money($sale['discount'])) ?></span></div>
                <?php if ((float) $sale['commission_amount'] > 0): ?><div class="d-flex justify-content-between"><span>Commission revendeur</span><span><?= e(money($sale['commission_amount'])) ?></span></div><?php endif; ?>
                <div class="d-flex justify-content-between fw-bold fs-5 mt-1"><span>Total</span><span><?= e(money($sale['total'])) ?></span></div>
            </div></div>
        <div class="mc-card"><div class="mc-card-head"><h2>Paiements</h2></div><ul class="list-group list-group-flush">
            <?php foreach ($payments as $p): ?><li class="list-group-item d-flex justify-content-between small"><span><?= e($p['name']) ?><?= $p['provider_reference'] ? ' · réf. <code>' . e($p['provider_reference']) . '</code>' : '' ?> · <?= e($p['confirmed_via'] ?? '') ?></span><span><?= status_badge($p['status']) ?> <?= e(money($p['amount'])) ?></span></li><?php endforeach; ?>
            <?php foreach ($refunds as $r): ?><li class="list-group-item d-flex justify-content-between small text-danger"><span>Remboursement — <?= e($r['reason']) ?> (<?= e($r['full_name'] ?? '') ?>)</span><span>− <?= e(money($r['amount'])) ?></span></li><?php endforeach; ?>
        </ul></div>
    </div>
    <div class="col-lg-5">
        <div class="mc-card mb-3"><div class="mc-card-body small">
            <div class="d-flex justify-content-between"><span class="text-muted">Client</span><span><?= e(trim(($sale['customer_name'] ?? '') . ' ' . ($sale['customer_phone'] ?? '')) ?: '—') ?></span></div>
            <div class="d-flex justify-content-between"><span class="text-muted">Paiement</span><span><?= e($sale['method'] ?? '—') ?> · <?= status_badge($sale['payment_status']) ?></span></div>
            <?php if ($sale['cancelled_at']): ?><div class="alert alert-warning mt-2 mb-0">Annulée le <?= e(date_fr($sale['cancelled_at'])) ?> par <?= e($sale['cancelled_by_name'] ?? '') ?> : <?= e($sale['cancel_reason']) ?></div><?php endif; ?>
        </div></div>
        <?php if ($sale['status'] === 'completed' && can('sales.cancel')): ?>
        <form class="mc-card mb-3" method="post" action="<?= e(url('/sales/' . $sale['id'] . '/cancel')) ?>" data-confirm="Annuler cette vente ?"><?= csrf_field() ?>
            <div class="mc-card-head"><h2 class="text-danger">Annuler la vente</h2></div>
            <div class="mc-card-body"><p class="small text-muted">Possible uniquement si aucun ticket n'a été utilisé. Les tickets redeviennent disponibles.</p>
                <input class="form-control mb-2" name="reason" placeholder="Motif (obligatoire)" required><button class="btn btn-outline-danger w-100">Annuler la vente</button></div></form>
        <?php endif; ?>
        <?php if ($sale['status'] === 'completed' && can('sales.refund')): ?>
        <form class="mc-card" method="post" action="<?= e(url('/sales/' . $sale['id'] . '/refund')) ?>" data-confirm="Enregistrer ce remboursement ?"><?= csrf_field() ?>
            <div class="mc-card-head"><h2>Rembourser</h2></div>
            <div class="mc-card-body"><p class="small text-muted">Un remboursement total annule les tickets et les désactive sur le routeur.</p>
                <input class="form-control mb-2" name="amount" value="<?= (float) $sale['total'] ?>" inputmode="numeric" required>
                <input class="form-control mb-2" name="reason" placeholder="Motif" required><button class="btn btn-outline-warning w-100">Rembourser</button></div></form>
        <?php endif; ?>
    </div>
</div>
