<form method="get" class="row g-2 mb-2 align-items-end"><?= App\Core\View::partial('partials/period', ['from' => $from, 'to' => $to]) ?>
  <div class="col-auto"><label class="form-label small mb-0"><?= e(t('Numéro')) ?></label><input name="q" value="<?= e($q) ?>" class="form-control form-control-sm"></div>
  <div class="col-auto"><label class="form-label small mb-0"><?= e(t('Statut')) ?></label><select name="statut" class="form-select form-select-sm"><option value=""><?= e(t('Tous')) ?></option><?php foreach (Addons\Fiscal\FiscalAdmin::STATUSES as $k => $l): ?><option value="<?= $k ?>" <?= $st === $k ? 'selected' : '' ?>><?= e(t($l)) ?></option><?php endforeach; ?></select></div>
  <div class="col-auto"><button class="btn btn-sm btn-primary"><i class="bi bi-search"></i></button></div>
  <?php if (can('fiscal.reports')): ?><div class="col-auto ms-auto"><a class="btn btn-sm btn-outline-success" href="<?= e(query_with(['export' => 'csv'])) ?>">CSV</a> <a class="btn btn-sm btn-outline-danger" href="<?= e(query_with(['export' => 'pdf'])) ?>">PDF</a></div><?php endif; ?>
</form>
