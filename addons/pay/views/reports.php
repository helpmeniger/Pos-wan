<?= App\Core\View::partial('pay::_nav', ['tab' => $tab]) ?>
<form method="get" class="row g-2 mb-2 align-items-end"><div class="col-auto"><label class="form-label small mb-0"><?= e(t('Rapport')) ?></label><select name="rapport" class="form-select form-select-sm"><?php foreach (Addons\Pay\PayController::REPORTS as $k => $l): ?><option value="<?= $k ?>" <?= $type === $k ? 'selected' : '' ?>><?= e(t($l)) ?></option><?php endforeach; ?></select></div>
<?= App\Core\View::partial('partials/period', ['from' => $from, 'to' => $to]) ?><div class="col-auto"><button class="btn btn-sm btn-primary"><i class="bi bi-search"></i></button></div>
<div class="col-auto ms-auto"><a class="btn btn-sm btn-outline-success" href="<?= e(query_with(['export' => 'csv'])) ?>">Excel (CSV)</a> <a class="btn btn-sm btn-outline-danger" href="<?= e(query_with(['export' => 'pdf'])) ?>">PDF</a></div></form>
<p class="small text-secondary"><?= e(t('Transactions réelles uniquement : les transactions TEST sont exclues. Frais : montants communiqués par les prestataires.')) ?></p>
<div class="card"><div class="table-responsive"><table class="table table-sm mb-0 small"><thead><tr><?php foreach ($headers as $h): ?><th><?= e(t($h)) ?></th><?php endforeach; ?></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr><?php foreach ($r as $v): ?><td><?= e((string) $v) ?></td><?php endforeach; ?></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="<?= count($headers) ?>" class="text-secondary"><?= e(t('Aucune donnée.')) ?></td></tr><?php endif; ?></tbody></table></div></div>
