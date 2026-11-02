<?php $title = 'Session de caisse #' . $s['id']; ?>
<div class="mc-page-head"><div><h1>Session <span class="mc-accent">#<?= (int) $s['id'] ?></span> <?= status_badge($s['status']) ?></h1>
    <p><?= e($s['full_name']) ?> · <?= e(date_fr($s['opened_at'])) ?> → <?= e(date_fr($s['closed_at'])) ?></p></div>
    <div class="d-flex gap-2"><button class="btn btn-outline-secondary" type="button" data-print><i class="bi bi-printer"></i> Imprimer</button>
        <a class="btn btn-outline-secondary" href="<?= e(url('/pos/sessions')) ?>"><i class="bi bi-arrow-left"></i></a></div></div>
<div class="row g-3 mb-3">
    <?php foreach (['Fond de caisse' => $s['opening_amount'], 'Espèces attendues' => $expected, 'Espèces déclarées' => $s['declared_amount'], 'Écart' => $s['difference']] as $l => $v): ?>
        <div class="col-6 col-lg-3"><div class="mc-card mc-kpi <?= $l === 'Écart' && $v !== null && abs((float) $v) > 0 ? '' : 'navy' ?>"><div class="mc-kpi-label"><?= e($l) ?></div><div class="mc-kpi-value"><?= $v !== null ? e(money($v)) : '—' ?></div></div></div>
    <?php endforeach; ?>
</div>
<div class="row g-3">
    <div class="col-lg-4"><div class="mc-card"><div class="mc-card-head"><h2>Par moyen de paiement</h2></div>
        <ul class="list-group list-group-flush"><?php foreach ($byMethod as $m): ?><li class="list-group-item d-flex justify-content-between"><span><?= e($m['name']) ?> (<?= (int) $m['n'] ?>)</span><strong><?= e(money($m['total'])) ?></strong></li><?php endforeach; ?>
        <?php if (!$byMethod): ?><li class="list-group-item text-muted small">Aucune vente.</li><?php endif; ?></ul>
        <?php if ($s['notes']): ?><div class="mc-card-body small border-top"><strong>Observations :</strong> <?= e($s['notes']) ?></div><?php endif; ?></div></div>
    <div class="col-lg-8"><div class="mc-card"><div class="table-responsive"><table class="table mc-table table-sm">
        <thead><tr><th>Référence</th><th>Heure</th><th>Moyen</th><th class="text-end">Total</th><th>Statut</th></tr></thead><tbody>
        <?php foreach ($sales as $x): ?><tr><td><a href="<?= e(url('/sales/' . $x['id'])) ?>"><?= e($x['reference']) ?></a></td><td class="small"><?= e(date_fr($x['created_at'], 'H:i')) ?></td><td class="small"><?= e($x['method']) ?></td><td class="text-end"><?= e(money($x['total'])) ?></td><td><?= status_badge($x['status']) ?></td></tr><?php endforeach; ?>
        </tbody></table></div></div></div>
</div>
