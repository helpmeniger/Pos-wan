<?= App\Core\View::partial('fiscal::_nav', ['tab' => $tab]) ?>
<div class="alert alert-warning small py-2"><i class="bi bi-exclamation-triangle"></i> <?= e(t('Les règles fiscales ne sont jamais préremplies : chaque pays doit être configuré à partir des textes officiels (loi de finances, arrêtés, spécifications de l\'administration). Figurer dans cette liste ne signifie pas que POS Marwa est homologué dans ce pays.')) ?></div>
<div class="card mb-3"><div class="table-responsive"><table class="table table-sm table-hover mb-0 align-middle">
<thead><tr><th><?= e(t('Pays')) ?></th><th><?= e(t('Devise')) ?></th><th><?= e(t('Mode de certification')) ?></th><th><?= e(t('Taux')) ?></th><th><?= e(t('Règles')) ?></th><th><?= e(t('Homologation')) ?></th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr>
  <td><?= can('fiscal.configure') ? '<a href="' . e(url('/fiscal/pays/' . $r['id'])) . '">' . e($r['name']) . '</a>' : e($r['name']) ?> <span class="text-secondary small"><?= e($r['code']) ?></span></td><td><?= e($r['currency']) ?></td>
  <td class="small"><?= e(t(Addons\Fiscal\FiscalAdmin::CERT_MODES[$r['certification_mode']])) ?></td><td><?= (int) $r['rates'] ?></td>
  <td><?= (int) $r['configured'] ? '<span class="badge text-bg-success">' . e(t('configurées')) . '</span>' : '<span class="badge text-bg-warning">' . e(t('à configurer')) . '</span>' ?></td>
  <td><?= (int) $r['homologated'] ? '<span class="badge text-bg-success">' . e(t('déclarée')) . '</span>' : '<span class="text-secondary small">' . e(t('non')) . '</span>' ?></td></tr><?php endforeach; ?>
</tbody></table></div></div>
<?php if (can('fiscal.configure')): ?><div class="card"><div class="card-body"><form method="post" action="<?= e(url('/fiscal/pays')) ?>" class="row g-2 align-items-end"><?= csrf_field() ?>
  <div class="col-auto fw-semibold small"><?= e(t('Ajouter un pays')) ?></div>
  <div class="col-md-2"><input name="code" maxlength="2" class="form-control form-control-sm" placeholder="<?= e(t('Code (CM)')) ?>" required></div>
  <div class="col-md-3"><input name="name" maxlength="80" class="form-control form-control-sm" placeholder="<?= e(t('Nom')) ?>" required></div>
  <div class="col-md-2"><input name="currency" maxlength="3" class="form-control form-control-sm" placeholder="<?= e(t('Devise (XAF)')) ?>" required></div>
  <div class="col-md-2"><button class="btn btn-sm btn-outline-primary"><?= e(t('Ajouter')) ?></button></div></form></div></div><?php endif; ?>
