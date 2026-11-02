<?php $title = 'Revendeurs'; ?>
<div class="mc-page-head">
    <div><h1><span class="mc-accent">Revendeurs</span></h1><p>Points de vente partenaires : vente en gros ou à la commission.</p></div>
    <?php if (can('resellers.manage')): ?><a class="btn btn-primary" href="<?= e(url('/resellers/create')) ?>"><i class="bi bi-plus-lg me-1"></i> Nouveau revendeur</a><?php endif; ?>
</div>
<div class="mc-card"><div class="table-responsive"><table class="table mc-table table-hover">
    <thead><tr><th>Revendeur</th><th>Modèle</th><th>Routeurs</th><th class="text-end">Stock</th><th class="text-end">Ventes du mois</th><th class="text-end">Solde</th><th>Statut</th></tr></thead><tbody>
    <?php foreach ($resellers as $r): ?>
        <tr><td><a class="fw-semibold" href="<?= e(url('/resellers/' . $r['id'])) ?>"><?= e($r['business_name']) ?></a><br><small class="text-muted"><code><?= e($r['code']) ?></code> · <?= e($r['phone'] ?? '') ?><?= $r['username'] ? ' · <i class="bi bi-person"></i> ' . e($r['username']) : '' ?></small></td>
            <td class="small"><?= $r['business_model'] === 'wholesale' ? 'Gros (−' . (float) $r['wholesale_discount'] . ' %)' : 'Commission ' . (float) $r['commission_rate'] . ' %' ?></td>
            <td class="small"><?= e($r['routers'] ?: '—') ?></td><td class="text-end"><?= (int) $r['stock'] ?></td><td class="text-end"><?= e(money($r['sales_month'])) ?></td>
            <td class="text-end fw-bold <?= (float) $r['balance'] < 0 ? 'text-danger' : 'text-success' ?>"><?= e(money($r['balance'])) ?></td>
            <td><?= $r['status'] === 'active' ? '<span class="mc-status ok">Actif</span>' : status_badge('suspended') ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$resellers): ?><tr><td colspan="7"><div class="mc-empty"><i class="bi bi-shop"></i>Aucun revendeur.</div></td></tr><?php endif; ?>
    </tbody></table></div></div>
