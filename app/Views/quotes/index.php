<div class="d-flex flex-wrap gap-2 mb-3 align-items-center">
  <?php foreach (['' => 'Tous'] + App\Services\QuoteService::STATUSES as $k => $l): ?><a class="btn btn-sm <?= ($_GET['status'] ?? '') === $k ? 'btn-dark' : 'btn-outline-secondary' ?>" href="<?= e(url('/quotes', $k ? ['status' => $k] : [])) ?>"><?= e($l) ?></a><?php endforeach; ?>
  <form class="ms-auto d-flex gap-2" method="get" action="<?= e(url('/quotes')) ?>"><input name="q" value="<?= e($_GET['q'] ?? '') ?>" class="form-control form-control-sm" placeholder="N° ou client"><button class="btn btn-sm btn-outline-secondary"><i class="bi bi-search"></i></button></form>
  <a href="<?= e(url('/quotes/create')) ?>" class="btn btn-primary"><i class="bi bi-plus"></i> Nouveau devis</a>
</div>
<div class="card"><div class="table-responsive"><table class="table table-sm table-hover mb-0"><thead><tr><th>N°</th><th>Date</th><th>Client</th><th>Valable jusqu'au</th><th>Établi par</th><th class="num">Montant</th><th>Statut</th></tr></thead><tbody>
<?php foreach ($p['rows'] as $q): $expired = in_array($q['status'], ['draft', 'sent', 'accepted'], true) && $q['valid_until'] && $q['valid_until'] < date('Y-m-d'); ?>
  <tr><td><a href="<?= e(url('/quotes/' . $q['id'])) ?>" class="fw-semibold"><?= e($q['number']) ?></a></td><td><?= e(fdate($q['created_at'], false)) ?></td><td><?= e($q['customer_name']) ?></td>
  <td class="<?= $expired ? 'text-danger fw-semibold' : '' ?>"><?= e(fdate($q['valid_until'], false)) ?><?= $expired ? ' (expiré)' : '' ?></td><td class="small"><?= e($q['user_name']) ?></td><td class="num"><?= money($q['total']) ?></td><td><?= App\Core\View::partial('partials/status', ['status' => $q['status']]) ?></td></tr>
<?php endforeach; ?>
<?php if (!$p['rows']): ?><tr><td colspan="7" class="text-center text-secondary py-4">Aucun devis.</td></tr><?php endif; ?>
</tbody></table></div></div>
<?= App\Core\View::partial('partials/pagination', ['p' => $p]) ?>
