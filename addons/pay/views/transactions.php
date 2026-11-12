<?= App\Core\View::partial('pay::_nav', ['tab' => $tab]) ?>
<form method="get" class="row g-2 mb-2 align-items-end"><?= App\Core\View::partial('partials/period', ['from' => $from, 'to' => $to]) ?>
<?php foreach ([['caisse', 'Caisse', $registers, 'name'], ['utilisateur', 'Utilisateur', $users, 'full_name'], ['prestataire', 'Prestataire', $connectors, 'name'], ['terminal', 'Terminal', $terminals, 'name']] as [$k, $l, $list, $col]): ?>
  <div class="col-auto"><label class="form-label small mb-0"><?= e(t($l)) ?></label><select name="<?= $k ?>" class="form-select form-select-sm"><option value=""><?= e(t('Tous')) ?></option><?php foreach ($list as $o): ?><option value="<?= (int) $o['id'] ?>" <?= (int) ($_GET[$k] ?? 0) === (int) $o['id'] ? 'selected' : '' ?>><?= e($o[$col]) ?></option><?php endforeach; ?></select></div>
<?php endforeach; ?>
  <div class="col-auto"><label class="form-label small mb-0"><?= e(t('Statut')) ?></label><select name="statut" class="form-select form-select-sm"><option value=""><?= e(t('Tous')) ?></option><?php foreach (Addons\Pay\PayEngine::STATUSES as $k => $l): ?><option value="<?= $k ?>" <?= ($_GET['statut'] ?? '') === $k ? 'selected' : '' ?>><?= e(t($l)) ?></option><?php endforeach; ?></select></div>
  <div class="col-auto"><label class="form-label small mb-0"><?= e(t('Nature')) ?></label><select name="test" class="form-select form-select-sm"><option value=""><?= e(t('Réelles')) ?></option><option value="1" <?= ($_GET['test'] ?? '') === '1' ? 'selected' : '' ?>>TEST</option><option value="tous" <?= ($_GET['test'] ?? '') === 'tous' ? 'selected' : '' ?>><?= e(t('Toutes')) ?></option></select></div>
  <div class="col-auto"><label class="form-label small mb-0"><?= e(t('Numéro / référence')) ?></label><input name="q" value="<?= e($_GET['q'] ?? '') ?>" class="form-control form-control-sm"></div>
  <div class="col-auto"><button class="btn btn-sm btn-primary"><i class="bi bi-search"></i></button></div>
  <?php if (can('pay.reports')): ?><div class="col-auto ms-auto"><a class="btn btn-sm btn-outline-success" href="<?= e(query_with(['export' => 'csv'])) ?>">Excel (CSV)</a> <a class="btn btn-sm btn-outline-danger" href="<?= e(query_with(['export' => 'pdf'])) ?>">PDF</a></div><?php endif; ?>
</form>
<div class="card"><div class="table-responsive"><table class="table table-sm table-hover mb-0 align-middle small">
<thead><tr><th><?= e(t('Vente')) ?></th><th><?= e(t('Transaction')) ?></th><th><?= e(t('Client')) ?></th><th class="text-end"><?= e(t('Montant')) ?></th><th><?= e(t('Devise')) ?></th><th><?= e(t('Moyen')) ?></th><th><?= e(t('Prestataire / terminal')) ?></th><th><?= e(t('Date')) ?></th><th><?= e(t('Référence')) ?></th><th><?= e(t('Statut')) ?></th></tr></thead><tbody>
<?php foreach ($p['rows'] as $r): ?><tr>
  <td><?= $r['sale_id'] ? '<a href="' . e(url('/sales/' . $r['sale_id'])) . '">' . e($r['sale_number']) . '</a>' : '<span class="text-secondary">—</span>' ?></td>
  <td><a href="<?= e(url('/pay/transactions/' . $r['id'])) ?>"><?= e($r['number']) ?></a></td><td><?= e($r['customer'] ?? '') ?></td>
  <td class="text-end"><?= e(money($r['amount'], false)) ?><?= (float) $r['refunded_amount'] ? '<div class="text-danger">− ' . e(money($r['refunded_amount'], false)) . '</div>' : '' ?></td><td><?= e($r['currency']) ?><?= $r['provider_currency'] && $r['provider_currency'] !== $r['currency'] ? ' → ' . e($r['provider_currency']) : '' ?></td>
  <td><?= e($r['method']) ?></td><td><?= e($r['connector'] ?? $r['terminal'] ?? '') ?></td><td><?= e(fdate($r['created_at'])) ?><div class="text-secondary"><?= e($r['register'] ?? '') ?> · <?= e($r['cashier'] ?? '') ?></div></td>
  <td><code><?= e($r['provider_reference'] ?? '') ?></code><?= $r['authorization_code'] ? '<div>' . e(t('Autor.')) . ' ' . e($r['authorization_code']) . '</div>' : '' ?></td>
  <td><?= App\Core\View::partial('pay::_status', ['s' => $r['status'], 'test' => (int) $r['is_test']]) ?></td></tr><?php endforeach; ?>
<?php if (!$p['rows']): ?><tr><td colspan="10" class="text-secondary"><?= e(t('Aucune transaction.')) ?></td></tr><?php endif; ?></tbody></table></div></div>
<?= App\Core\View::partial('partials/pagination', ['p' => $p]) ?>
