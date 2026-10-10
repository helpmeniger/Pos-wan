<div class="card mb-3"><div class="card-body">
  <form class="row g-2 align-items-end filters" method="get">
    <?= App\Core\View::partial('partials/period', compact('from', 'to')) ?>
    <div class="col-auto"><label class="form-label small mb-0">Recherche</label><input name="q" value="<?= e($_GET['q'] ?? '') ?>" class="form-control form-control-sm" placeholder="N°, client, téléphone"></div>
    <?php if ($users): ?><div class="col-auto"><label class="form-label small mb-0">Vendeur</label><select name="user" class="form-select form-select-sm"><option value="">Tous</option><?php foreach ($users as $x): ?><option value="<?= (int) $x['id'] ?>" <?= (int) ($_GET['user'] ?? 0) === (int) $x['id'] ? 'selected' : '' ?>><?= e($x['full_name']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
    <div class="col-auto"><label class="form-label small mb-0">Statut</label><select name="status" class="form-select form-select-sm"><option value="">Tous</option><?php foreach (['completed' => 'Validée', 'cancelled' => 'Annulée', 'partially_returned' => 'Retour partiel', 'returned' => 'Retournée'] as $k => $v): ?><option value="<?= $k ?>" <?= ($_GET['status'] ?? '') === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
    <div class="col-auto form-check ms-2 mb-1"><input type="checkbox" class="form-check-input" name="credit" value="1" id="cr" <?= ($_GET['credit'] ?? '') === '1' ? 'checked' : '' ?>><label for="cr" class="form-check-label small">À crédit</label></div>
    <div class="col-auto"><button class="btn btn-sm btn-primary"><i class="bi bi-funnel"></i> Filtrer</button></div>
    <?php if (can('reports.export')): ?><div class="col-auto ms-auto"><a class="btn btn-sm btn-outline-success" href="<?= e(query_with(['export' => 'csv'])) ?>"><i class="bi bi-filetype-csv"></i> Exporter</a> <a class="btn btn-sm btn-outline-danger" href="<?= e(query_with(['export' => 'pdf'])) ?>"><i class="bi bi-filetype-pdf"></i> PDF</a></div><?php endif; ?>
  </form>
</div></div>
<div class="card"><div class="card-header d-flex"><span><?= (int) $tot['n'] ?> vente(s)</span><span class="ms-auto">Total (hors annulées) : <b><?= money($tot['t']) ?></b></span></div>
<div class="table-responsive"><table class="table table-hover table-sm mb-0 align-middle">
<thead><tr><th>N°</th><th>Date</th><th>Vendeur</th><th>Client</th><th class="num">Remise</th><th class="num">Total</th><th class="num">Crédit</th><th>Statut</th><th></th></tr></thead><tbody>
<?php foreach ($p['rows'] as $s): ?>
<tr><td><a href="<?= e(url('/sales/' . $s['id'])) ?>" class="fw-semibold"><?= e($s['number']) ?></a></td><td><?= e(fdate($s['created_at'])) ?></td><td><?= e($s['cashier']) ?></td><td><?= e($s['customer'] ?? '—') ?></td>
<td class="num"><?= (float) $s['discount_total'] > 0 ? money($s['discount_total']) : '' ?></td><td class="num fw-semibold"><?= money($s['total']) ?></td><td class="num text-danger"><?= (float) $s['credit_amount'] > 0 ? money($s['credit_amount']) : '' ?></td>
<td><?= App\Core\View::partial('partials/status', ['status' => $s['status']]) ?></td>
<td class="text-end"><a class="btn btn-sm btn-light" href="<?= e(url('/sales/' . $s['id'])) ?>" title="Détail"><i class="bi bi-eye"></i></a></td></tr>
<?php endforeach; ?>
<?php if (!$p['rows']): ?><tr><td colspan="9" class="text-center text-secondary py-4">Aucune vente sur la période.</td></tr><?php endif; ?>
</tbody></table></div></div>
<?= App\Core\View::partial('partials/pagination', ['p' => $p]) ?>
