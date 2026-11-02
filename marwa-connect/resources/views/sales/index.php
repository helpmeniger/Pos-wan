<?php
$title = 'Ventes';
$qs = array_filter($f, static fn ($v) => $v !== '' && $v !== 0);
$channels = ['physical' => 'Caisse', 'online' => 'En ligne', 'reseller' => 'Revendeur'];
?>
<div class="mc-page-head">
    <div><h1><span class="mc-accent">Ventes</span></h1><p>Historique des ventes, annulations et remboursements.</p></div>
    <div class="d-flex gap-2"><a class="btn btn-outline-secondary" href="<?= e(url('/sales/export', ['from' => $f['from'], 'to' => $f['to']])) ?>"><i class="bi bi-filetype-csv"></i> Export</a>
        <?php if (can('sales.create')): ?><a class="btn btn-primary" href="<?= e(url('/pos')) ?>"><i class="bi bi-cash-coin"></i> Caisse</a><?php endif; ?></div>
</div>
<ul class="nav nav-tabs mb-3">
    <li class="nav-item"><a class="nav-link active" href="<?= e(url('/sales')) ?>">Ventes</a></li>
    <li class="nav-item"><a class="nav-link" href="<?= e(url('/sales', ['tab' => 'online'])) ?>">Commandes en ligne</a></li>
    <li class="nav-item"><a class="nav-link" href="<?= e(url('/pos/sessions')) ?>">Sessions de caisse</a></li>
</ul>
<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3"><div class="mc-card mc-kpi"><div class="mc-kpi-label">Chiffre d'affaires</div><div class="mc-kpi-value"><?= e(money($totals['total'])) ?></div></div></div>
    <div class="col-6 col-lg-3"><div class="mc-card mc-kpi navy"><div class="mc-kpi-label">Ventes</div><div class="mc-kpi-value"><?= (int) $totals['n'] ?></div></div></div>
    <div class="col-6 col-lg-3"><div class="mc-card mc-kpi amber"><div class="mc-kpi-label">Remises</div><div class="mc-kpi-value"><?= e(money($totals['discount'])) ?></div></div></div>
    <div class="col-6 col-lg-3"><div class="mc-card mc-kpi green"><div class="mc-kpi-label">Commissions</div><div class="mc-kpi-value"><?= e(money($totals['commission'])) ?></div></div></div>
</div>
<div class="mc-card">
    <form class="mc-card-head mc-filter-bar" method="get" action="<?= e(url('/sales')) ?>">
        <input class="form-control form-control-sm" type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Référence, client…">
        <input class="form-control form-control-sm" type="date" name="from" value="<?= e($f['from']) ?>" aria-label="Du">
        <input class="form-control form-control-sm" type="date" name="to" value="<?= e($f['to']) ?>" aria-label="Au">
        <select class="form-select form-select-sm" name="channel"><option value="">Tous canaux</option><?php foreach ($channels as $k => $l): ?><option value="<?= $k ?>" <?= $f['channel'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
        <select class="form-select form-select-sm" name="status"><option value="">Tous statuts</option><?php foreach (['completed' => 'Terminée', 'cancelled' => 'Annulée', 'refunded' => 'Remboursée'] as $k => $l): ?><option value="<?= $k ?>" <?= $f['status'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
        <select class="form-select form-select-sm" name="method"><option value="0">Tous moyens</option><?php foreach ($methods as $m): ?><option value="<?= (int) $m['id'] ?>" <?= $f['method'] === (int) $m['id'] ? 'selected' : '' ?>><?= e($m['name']) ?></option><?php endforeach; ?></select>
        <select class="form-select form-select-sm" name="router_id"><option value="0">Tous routeurs</option><?php foreach ($routers as $r): ?><option value="<?= (int) $r['id'] ?>" <?= $f['router_id'] === (int) $r['id'] ? 'selected' : '' ?>><?= e($r['name']) ?></option><?php endforeach; ?></select>
        <button class="btn btn-sm btn-navy"><i class="bi bi-funnel"></i></button>
    </form>
    <div class="table-responsive"><table class="table mc-table table-hover">
        <thead><tr><th>Référence</th><th>Date</th><th>Canal</th><th>Caissier / revendeur</th><th>Routeur</th><th class="text-end">Tickets</th><th>Paiement</th><th class="text-end">Total</th><th>Statut</th></tr></thead><tbody>
        <?php foreach ($sales as $s): ?>
            <tr><td><a class="fw-semibold" href="<?= e(url('/sales/' . $s['id'])) ?>"><?= e($s['reference']) ?></a><?= $s['customer_phone'] ? '<br><small class="text-muted">' . e($s['customer_phone']) . '</small>' : '' ?></td>
                <td class="small text-nowrap"><?= e(date_fr($s['created_at'])) ?></td><td><?= e($channels[$s['channel']] ?? $s['channel']) ?></td>
                <td class="small"><?= e($s['reseller_name'] ?: $s['cashier'] ?: '—') ?></td><td class="small"><?= e($s['router_name'] ?? '—') ?></td>
                <td class="text-end"><?= (int) $s['items'] ?></td><td class="small"><?= e($s['method'] ?? '—') ?></td>
                <td class="text-end fw-bold"><?= e(money($s['total'])) ?></td><td><?= status_badge($s['status']) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$sales): ?><tr><td colspan="9"><div class="mc-empty"><i class="bi bi-receipt"></i>Aucune vente sur la période.</div></td></tr><?php endif; ?>
        </tbody></table></div>
    <?= pagination($page, $pages, '/sales', $qs) ?>
</div>
