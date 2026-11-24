<div class="card mb-3"><div class="card-body">
  <form class="row g-2 align-items-end filters" method="get">
    <?= App\Core\View::partial('partials/period', compact('from', 'to')) ?>
    <div class="col-auto"><label class="form-label small mb-0">Recherche</label><input name="q" value="<?= e($_GET['q'] ?? '') ?>" class="form-control form-control-sm" placeholder="N°, client, téléphone, commande"></div>
    <?php if ($users): ?><div class="col-auto"><label class="form-label small mb-0">Vendeur</label><select name="user" class="form-select form-select-sm"><option value="">Tous</option><?php foreach ($users as $x): ?><option value="<?= (int) $x['id'] ?>" <?= (int) ($_GET['user'] ?? 0) === (int) $x['id'] ? 'selected' : '' ?>><?= e($x['full_name']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
    <div class="col-auto"><label class="form-label small mb-0">Statut</label><select name="status" class="form-select form-select-sm"><option value="">Tous</option><?php foreach (['completed' => 'Validée', 'cancelled' => 'Annulée', 'partially_returned' => 'Retour partiel', 'returned' => 'Retournée'] as $k => $v): ?><option value="<?= $k ?>" <?= ($_GET['status'] ?? '') === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
    <?php if ($resto): ?>
      <div class="col-auto"><label class="form-label small mb-0">Serveur</label><select name="waiter" class="form-select form-select-sm"><option value="">Tous</option><?php foreach ($waiters as $x): ?><option value="<?= (int) $x['id'] ?>" <?= (int) ($_GET['waiter'] ?? 0) === (int) $x['id'] ? 'selected' : '' ?>><?= e($x['full_name']) ?></option><?php endforeach; ?></select></div>
      <div class="col-auto"><label class="form-label small mb-0">Service</label><select name="service" class="form-select form-select-sm"><option value="">Tous</option><?php foreach (['direct' => 'Vente directe', 'dine_in' => 'Sur place', 'takeaway' => 'À emporter', 'delivery' => 'Livraison'] as $k => $v): ?><option value="<?= $k ?>" <?= ($_GET['service'] ?? '') === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
      <?php if ($tables): ?><div class="col-auto"><label class="form-label small mb-0">Table</label><select name="table" class="form-select form-select-sm"><option value="">Toutes</option><?php foreach ($tables as $x): ?><option value="<?= (int) $x['id'] ?>" <?= (int) ($_GET['table'] ?? 0) === (int) $x['id'] ? 'selected' : '' ?>><?= e($x['name']) ?> · <?= e($x['area']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
    <?php endif; ?>
    <div class="col-auto"><label class="form-label small mb-0">Paiement</label><select name="method" class="form-select form-select-sm"><option value="">Tous</option><?php foreach (payment_methods() as $k => $v): ?><option value="<?= e($k) ?>" <?= ($_GET['method'] ?? '') === $k ? 'selected' : '' ?>><?= e(t($v)) ?></option><?php endforeach; ?></select></div>
    <div class="col-auto"><label class="form-label small mb-0">Trier par</label><select name="sort" class="form-select form-select-sm"><?php foreach ($sorts as $k => $v): if (!$resto && $k === 'serveur') continue; ?><option value="<?= $k ?>" <?= ($_GET['sort'] ?? 'recent') === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
    <div class="col-auto"><label class="form-label small mb-0">Regrouper par</label><select name="group" class="form-select form-select-sm"><option value="">—</option><?php foreach ($groupList as $k => [$v]): ?><option value="<?= $k ?>" <?= $group === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
    <div class="col-auto form-check ms-2 mb-1"><input type="checkbox" class="form-check-input" name="credit" value="1" id="cr" <?= ($_GET['credit'] ?? '') === '1' ? 'checked' : '' ?>><label for="cr" class="form-check-label small">À crédit</label></div>
    <div class="col-auto"><button class="btn btn-sm btn-primary"><i class="bi bi-funnel"></i> Filtrer</button></div>
    <?php if (can('reports.export')): ?><div class="col-auto ms-auto"><a class="btn btn-sm btn-outline-success" href="<?= e(query_with(['export' => 'csv'])) ?>"><i class="bi bi-filetype-csv"></i> Exporter</a> <a class="btn btn-sm btn-outline-danger" href="<?= e(query_with(['export' => 'pdf'])) ?>"><i class="bi bi-filetype-pdf"></i> PDF</a></div><?php endif; ?>
  </form>
</div></div>
<?php if ($groups !== null): $gsum = array_sum(array_column($groups, 't')); ?>
<div class="card mb-3"><div class="card-header fw-semibold"><i class="bi bi-diagram-3"></i> Totaux par <?= e(mb_strtolower($groupList[$group][0])) ?> <span class="small text-secondary fw-normal">(ventes annulées exclues<?= $group === 'paiement' ? ' ; une vente payée de deux façons compte dans les deux' : '' ?>)</span></div>
<div class="table-responsive"><table class="table table-sm mb-0 align-middle">
<thead><tr><th><?= e($groupList[$group][0]) ?></th><th class="num">Ventes</th><th class="num">Total</th><th class="num">Panier moyen</th><?php if ($group !== 'paiement'): ?><th class="num">Dont crédit</th><?php endif; ?><th style="width:30%">Part</th></tr></thead><tbody>
<?php foreach ($groups as $g): $pct = $gsum > 0 ? 100 * (float) $g['t'] / $gsum : 0; ?>
<tr><td class="fw-semibold"><?= e($group === 'jour' ? fdate((string) $g['label'], false) : (string) $g['label']) ?></td><td class="num"><?= (int) $g['n'] ?></td><td class="num fw-semibold"><?= money($g['t']) ?></td><td class="num"><?= money((int) $g['n'] > 0 ? (float) $g['t'] / (int) $g['n'] : 0) ?></td><?php if ($group !== 'paiement'): ?><td class="num text-danger"><?= (float) $g['credit'] > 0 ? money($g['credit']) : '' ?></td><?php endif; ?>
<td><div class="progress" style="height:8px" role="progressbar" aria-label="Part" aria-valuenow="<?= (int) round($pct) ?>" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar" style="width:<?= round($pct, 1) ?>%"></div></div><span class="small text-secondary"><?= number_format($pct, 1, ',', ' ') ?> %</span></td></tr>
<?php endforeach; ?>
<?php if (!$groups): ?><tr><td colspan="6" class="text-center text-secondary py-3">Aucune vente sur la période.</td></tr><?php endif; ?>
</tbody></table></div></div>
<?php endif; ?>
<div class="card"><div class="card-header d-flex"><span><?= (int) $tot['n'] ?> vente(s)</span><span class="ms-auto">Total (hors annulées) : <b><?= money($tot['t']) ?></b></span></div>
<div class="table-responsive"><table class="table table-hover table-sm mb-0 align-middle">
<thead><tr><th>N°</th><th>Date</th><th>Vendeur</th><?php if ($resto): ?><th>Serveur</th><th>Service</th><?php endif; ?><th>Client</th><th class="num">Remise</th><th class="num">Total</th><th class="num">Crédit</th><th>Statut</th><th></th></tr></thead><tbody>
<?php foreach ($p['rows'] as $s): ?>
<tr><td><a href="<?= e(url('/sales/' . $s['id'])) ?>" class="fw-semibold"><?= e($s['number']) ?></a></td><td><?= e(fdate($s['created_at'])) ?></td><td><?= e($s['cashier']) ?></td><?php if ($resto): ?><td><?= e($s['waiter'] ?? '') ?></td><td class="small"><?= $s['service_type'] === null ? '<span class="text-secondary">Vente directe</span>' : e((App\Services\RestaurantService::TYPES[$s['service_type']] ?? '') . ($s['table_name'] ? ' · ' . $s['table_name'] : '')) ?></td><?php endif; ?><td><?= e($s['customer'] ?? '—') ?></td>
<td class="num"><?= (float) $s['discount_total'] > 0 ? money($s['discount_total']) : '' ?></td><td class="num fw-semibold"><?= money($s['total']) ?></td><td class="num text-danger"><?= (float) $s['credit_amount'] > 0 ? money($s['credit_amount']) : '' ?></td>
<td><?= App\Core\View::partial('partials/status', ['status' => $s['status']]) ?></td>
<td class="text-end"><a class="btn btn-sm btn-light" href="<?= e(url('/sales/' . $s['id'])) ?>" title="Détail"><i class="bi bi-eye"></i></a></td></tr>
<?php endforeach; ?>
<?php if (!$p['rows']): ?><tr><td colspan="<?= $resto ? 11 : 9 ?>" class="text-center text-secondary py-4">Aucune vente sur la période.</td></tr><?php endif; ?>
</tbody></table></div></div>
<?= App\Core\View::partial('partials/pagination', ['p' => $p]) ?>
