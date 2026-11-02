<?php
$title = $r['business_name'];
$types = ['topup' => 'Réapprovisionnement', 'purchase' => 'Achat en gros', 'sale' => 'Vente', 'commission' => 'Commission', 'payout' => 'Versement / paiement', 'adjustment' => 'Ajustement', 'refund' => 'Annulation'];
?>
<div class="mc-page-head">
    <div><h1><?= e($r['business_name']) ?> <?= $r['status'] === 'active' ? '<span class="mc-status ok">Actif</span>' : status_badge('suspended') ?></h1>
        <p><code><?= e($r['code']) ?></code> · <?= $r['business_model'] === 'wholesale' ? 'Vente en gros, remise ' . (float) $r['wholesale_discount'] . ' %' : 'Commission ' . (float) $r['commission_rate'] . ' %' ?> · <?= e($r['phone'] ?? '') ?></p></div>
    <div class="d-flex gap-2 flex-wrap">
        <?php if (can('resellers.manage')): ?>
            <a class="btn btn-outline-secondary" href="<?= e(url('/resellers/' . $r['id'] . '/edit')) ?>"><i class="bi bi-pencil"></i> Modifier</a>
            <form method="post" action="<?= e(url('/resellers/' . $r['id'] . '/toggle')) ?>" data-confirm="Confirmer ?"><?= csrf_field() ?><button class="btn btn-outline-<?= $r['status'] === 'active' ? 'warning' : 'success' ?>"><?= $r['status'] === 'active' ? 'Suspendre' : 'Réactiver' ?></button></form>
        <?php endif; ?>
        <a class="btn btn-outline-secondary" href="<?= e(url('/resellers')) ?>"><i class="bi bi-arrow-left"></i></a>
    </div>
</div>
<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3"><div class="mc-card mc-kpi <?= (float) $r['balance'] < 0 ? '' : 'green' ?>"><div class="mc-kpi-label">Solde</div><div class="mc-kpi-value"><?= e(money($r['balance'])) ?></div><div class="mc-kpi-sub"><?= (float) $r['balance'] < 0 ? 'Montant dû par le revendeur' : 'Crédit disponible' ?> · plafond <?= e(money($r['credit_limit'])) ?></div></div></div>
    <div class="col-6 col-lg-3"><div class="mc-card mc-kpi navy"><div class="mc-kpi-label">Ventes du mois</div><div class="mc-kpi-value"><?= e(money($stats['sales_month'])) ?></div><div class="mc-kpi-sub"><?= (int) $stats['sales_count'] ?> vente(s) au total</div></div></div>
    <div class="col-6 col-lg-3"><div class="mc-card mc-kpi amber"><div class="mc-kpi-label">Commissions du mois</div><div class="mc-kpi-value"><?= e(money($stats['commission_month'])) ?></div></div></div>
    <div class="col-6 col-lg-3"><div class="mc-card mc-kpi"><div class="mc-kpi-label">Stock disponible</div><div class="mc-kpi-value"><?= array_sum(array_map(static fn ($s) => (int) $s['available'] + (int) $s['unsold_wholesale'], $stats['stock'])) ?></div></div></div>
</div>
<div class="row g-3">
    <div class="col-lg-7">
        <div class="mc-card mb-3"><div class="mc-card-head"><h2>Stock par offre</h2><a class="small" href="<?= e(url('/tickets', ['reseller_id' => $r['id']])) ?>">Voir les tickets</a></div>
            <div class="table-responsive"><table class="table mc-table"><thead><tr><th>Offre</th><th class="text-end">Disponibles</th><th class="text-end">Total attribué</th></tr></thead><tbody>
            <?php foreach ($stats['stock'] as $s): ?><tr><td><?= e($s['name']) ?> (<?= e(money($s['price'])) ?>)</td><td class="text-end"><?= (int) $s['available'] + (int) $s['unsold_wholesale'] ?></td><td class="text-end"><?= (int) $s['total'] ?></td></tr><?php endforeach; ?>
            <?php if (!$stats['stock']): ?><tr><td colspan="3" class="text-muted text-center py-3">Aucun ticket attribué.</td></tr><?php endif; ?>
            </tbody></table></div></div>
        <div class="mc-card mb-3"><div class="mc-card-head"><h2>Performances (6 mois)</h2></div>
            <div class="table-responsive"><table class="table mc-table table-sm"><thead><tr><th>Mois</th><th class="text-end">Ventes</th><th class="text-end">Montant</th><th class="text-end">Commissions</th></tr></thead><tbody>
            <?php foreach ($monthly as $m): ?><tr><td><?= e($m['m']) ?></td><td class="text-end"><?= (int) $m['n'] ?></td><td class="text-end"><?= e(money($m['total'])) ?></td><td class="text-end"><?= e(money($m['commission'])) ?></td></tr><?php endforeach; ?>
            <?php if (!$monthly): ?><tr><td colspan="4" class="text-muted text-center py-3">Aucune vente.</td></tr><?php endif; ?>
            </tbody></table></div></div>
        <div class="mc-card"><div class="mc-card-head"><h2>Historique du compte</h2></div>
            <div class="table-responsive"><table class="table mc-table table-sm"><thead><tr><th>Date</th><th>Type</th><th>Description</th><th class="text-end">Montant</th><th class="text-end">Solde</th></tr></thead><tbody>
            <?php foreach ($transactions as $t): ?><tr><td class="small text-nowrap"><?= e(date_fr($t['created_at'])) ?></td><td class="small"><?= e($types[$t['type']] ?? $t['type']) ?></td><td class="small"><?= e($t['description']) ?></td>
                <td class="text-end <?= (float) $t['amount'] < 0 ? 'text-danger' : 'text-success' ?>"><?= e(money($t['amount'])) ?></td><td class="text-end"><?= e(money($t['balance_after'])) ?></td></tr><?php endforeach; ?>
            <?php if (!$transactions): ?><tr><td colspan="5" class="text-muted text-center py-3">Aucun mouvement.</td></tr><?php endif; ?>
            </tbody></table></div></div>
    </div>
    <div class="col-lg-5">
        <?php if (can('resellers.manage')): ?>
        <form class="mc-card mb-3" method="post" action="<?= e(url('/resellers/' . $r['id'] . '/stock')) ?>"><?= csrf_field() ?>
            <div class="mc-card-head"><h2><i class="bi bi-box-seam me-2 text-primary"></i>Attribuer du stock</h2></div>
            <div class="mc-card-body">
                <?php if (!$routers): ?><div class="alert alert-warning small">Affectez d'abord un routeur à ce revendeur.</div><?php endif; ?>
                <select class="form-select mb-2" name="offer_id"><?php foreach ($offers as $o): ?><option value="<?= (int) $o['id'] ?>"><?= e($o['name']) ?> — <?= e(money($o['price'])) ?></option><?php endforeach; ?></select>
                <select class="form-select mb-2" name="router_id"><?php foreach ($routers as $ro): ?><option value="<?= (int) $ro['id'] ?>"><?= e($ro['name']) ?></option><?php endforeach; ?></select>
                <input class="form-control mb-2" name="quantity" value="10" inputmode="numeric" aria-label="Quantité">
                <div class="d-flex gap-2"><button class="btn btn-primary flex-fill" name="mode" value="assign"><?= $r['business_model'] === 'wholesale' ? 'Vendre en gros' : 'Attribuer en dépôt' ?></button>
                    <?php if ($r['business_model'] === 'commission'): ?><button class="btn btn-outline-secondary" name="mode" value="recall" title="Reprendre des tickets non vendus">Reprendre</button><?php endif; ?></div>
            </div></form>
        <form class="mc-card" method="post" action="<?= e(url('/resellers/' . $r['id'] . '/topup')) ?>" data-confirm="Enregistrer ce mouvement ?"><?= csrf_field() ?>
            <div class="mc-card-head"><h2><i class="bi bi-wallet2 me-2 text-primary"></i>Mouvement de solde</h2></div>
            <div class="mc-card-body">
                <select class="form-select mb-2" name="type"><option value="topup">Réapprovisionnement / paiement reçu (+)</option><option value="payout">Versement au revendeur (−)</option><option value="adjustment">Ajustement (+/−)</option></select>
                <input class="form-control mb-2" name="amount" placeholder="Montant en FCFA" inputmode="decimal" required>
                <input class="form-control mb-2" name="note" placeholder="Référence / note">
                <button class="btn btn-navy w-100">Enregistrer</button>
            </div></form>
        <?php endif; ?>
    </div>
</div>
