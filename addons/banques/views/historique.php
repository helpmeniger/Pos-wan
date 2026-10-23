<?php use Addons\Banques\BankService as BS; $tin = $tout = 0.0; foreach ($rows as $r) { if (!$r['cancelled_at']) { (int) $r['direction'] > 0 ? $tin += (float) $r['amount'] : $tout += (float) $r['amount']; } } ?>
<link rel="stylesheet" href="<?= e(asset('addons/banques/banques.css')) ?>">
<form class="d-flex flex-wrap gap-2 align-items-end mb-2" method="get">
  <a href="<?= e(url('/banques')) ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
  <div><label class="form-label small mb-0"><?= e(t('Du')) ?></label><input type="date" name="from" value="<?= e($f['from']) ?>" class="form-control form-control-sm"></div>
  <div><label class="form-label small mb-0"><?= e(t('Au')) ?></label><input type="date" name="to" value="<?= e($f['to']) ?>" class="form-control form-control-sm"></div>
  <div><label class="form-label small mb-0"><?= e(t('Compte')) ?></label><select name="account_id" class="form-select form-select-sm"><option value=""><?= e(t('Tous')) ?></option><?php foreach ($accounts as $a): ?><option value="<?= (int) $a['id'] ?>" <?= (int) $f['account_id'] === (int) $a['id'] ? 'selected' : '' ?>><?= e($a['name']) ?></option><?php endforeach; ?></select></div>
  <div><label class="form-label small mb-0"><?= e(t('Opération')) ?></label><select name="type" class="form-select form-select-sm"><option value=""><?= e(t('Toutes')) ?></option><?php foreach (BS::TYPES as $k => $l): ?><option value="<?= e($k) ?>" <?= $f['type'] === $k ? 'selected' : '' ?>><?= e(t($l)) ?></option><?php endforeach; ?></select></div>
  <div><label class="form-label small mb-0"><?= e(t('Recherche')) ?></label><input name="q" value="<?= e($f['q']) ?>" class="form-control form-control-sm" placeholder="<?= e(t('N°, bordereau, personne')) ?>"></div>
  <button class="btn btn-sm btn-primary"><i class="bi bi-funnel"></i></button>
  <?php if (can('reports.export')): ?><a class="btn btn-sm btn-outline-success" href="<?= e(query_with(['export' => 'csv'])) ?>"><i class="bi bi-filetype-csv"></i></a><a class="btn btn-sm btn-outline-danger" href="<?= e(query_with(['export' => 'pdf'])) ?>"><i class="bi bi-filetype-pdf"></i></a><?php endif; ?>
  <div class="ms-auto small text-end"><?= e(t('Entrées')) ?> <b class="text-success"><?= money($tin) ?></b> · <?= e(t('Sorties')) ?> <b class="text-danger"><?= money($tout) ?></b></div>
</form>
<div class="card"><div class="table-responsive"><table class="table table-sm align-middle mb-0 small"><thead><tr><th><?= e(t('Date')) ?></th><th>N°</th><th><?= e(t('Compte')) ?></th><th><?= e(t('Opération')) ?></th><th><?= e(t('Mode')) ?></th><th><?= e(t('Personne / libellé')) ?></th><th class="num"><?= e(t('Entrée')) ?></th><th class="num"><?= e(t('Sortie')) ?></th></tr></thead><tbody>
<?php foreach ($rows as $r) { $showAccount = true; require __DIR__ . '/_op_row.php'; } ?>
<?php if (!$rows): ?><tr><td colspan="8" class="text-center text-secondary py-4"><?= e(t('Aucune opération.')) ?></td></tr><?php endif; ?>
</tbody></table></div></div>
