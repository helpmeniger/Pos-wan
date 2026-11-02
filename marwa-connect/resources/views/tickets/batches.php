<?php $title = 'Lots de tickets'; ?>
<div class="mc-page-head">
    <div><h1>Lots de <span class="mc-accent">tickets</span></h1><p>Historique des générations et état de création sur les routeurs.</p></div>
    <div class="d-flex gap-2"><a class="btn btn-outline-secondary" href="<?= e(url('/tickets')) ?>"><i class="bi bi-arrow-left"></i> Tickets</a>
        <?php if (can('tickets.generate')): ?><a class="btn btn-primary" href="<?= e(url('/tickets/generate')) ?>"><i class="bi bi-plus-lg"></i> Générer</a><?php endif; ?></div>
</div>
<div class="mc-card"><div class="table-responsive"><table class="table mc-table table-hover">
    <thead><tr><th>Lot</th><th>Offre</th><th>Routeur</th><th class="text-end">Quantité</th><th class="text-end">Sur routeur</th><th class="text-end">Disponibles</th><th>État</th><th>Créé par</th><th>Date</th></tr></thead><tbody>
    <?php foreach ($batches as $b): ?>
        <tr><td><a class="fw-semibold" href="<?= e(url('/tickets/batches/' . $b['id'])) ?>"><?= e($b['batch_code']) ?></a><?= $b['reseller_name'] ? '<br><small class="text-muted"><i class="bi bi-shop"></i> ' . e($b['reseller_name']) . '</small>' : '' ?></td>
            <td><?= e($b['offer_name']) ?></td><td><?= e($b['router_name']) ?></td><td class="text-end"><?= (int) $b['quantity'] ?></td>
            <td class="text-end"><?= (int) $b['pushed_count'] ?></td><td class="text-end"><?= (int) $b['available'] ?></td>
            <td><?= status_badge($b['status']) ?></td><td class="small"><?= e($b['creator'] ?? '—') ?></td><td class="small text-nowrap"><?= e(date_fr($b['created_at'])) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$batches): ?><tr><td colspan="9"><div class="mc-empty"><i class="bi bi-collection"></i>Aucun lot généré.</div></td></tr><?php endif; ?>
    </tbody></table></div></div>
