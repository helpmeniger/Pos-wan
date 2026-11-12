<?= App\Core\View::partial('pay::_nav', ['tab' => $tab]) ?>
<div class="d-flex justify-content-between align-items-center mb-2"><p class="small text-secondary mb-0"><?= e(t('Connexions aux API des prestataires (un prestataire peut avoir plusieurs comptes, en TEST et en PRODUCTION).')) ?></p>
<a class="btn btn-sm btn-primary" href="<?= e(url('/pay/connecteurs/nouveau')) ?>"><i class="bi bi-plus-lg"></i> <?= e(t('Ajouter')) ?></a></div>
<div class="card"><div class="table-responsive"><table class="table table-sm table-hover mb-0 align-middle">
<thead><tr><th><?= e(t('Nom')) ?></th><th><?= e(t('Fournisseur')) ?></th><th><?= e(t('Connecteur')) ?></th><th><?= e(t('Env.')) ?></th><th><?= e(t('Établissement')) ?></th><th><?= e(t('État')) ?></th><th class="text-end"><?= e(t('Transactions')) ?></th></tr></thead><tbody>
<?php foreach ($rows as $c): $i = Addons\Pay\DriverRegistry::info($c['driver']); ?><tr>
  <td><a href="<?= e(url('/pay/connecteurs/' . $c['id'])) ?>"><?= e($c['name']) ?></a> <span class="small text-secondary"><?= e($c['account_label'] ?? '') ?></span></td><td><?= e($c['provider'] ?? '—') ?></td>
  <td class="small"><?= e($i['label'] ?? $c['driver']) ?><?= !empty($i['skeleton']) ? ' <span class="badge text-bg-danger">' . e(t('non développé')) . '</span>' : '' ?></td>
  <td><span class="badge text-bg-<?= $c['environment'] === 'prod' ? 'danger' : 'secondary' ?>"><?= $c['environment'] === 'prod' ? 'PRODUCTION' : 'TEST' ?></span></td><td class="small"><?= e($c['store'] ?? t('Tous')) ?></td>
  <td><?= (int) $c['active'] ? '<span class="badge text-bg-success">' . e(t('actif')) . '</span>' : '<span class="badge text-bg-light border">' . e(t('inactif')) . '</span>' ?> <span class="small <?= $c['status'] === 'error' ? 'text-danger' : 'text-secondary' ?>" title="<?= e($c['last_test_message'] ?? '') ?>"><?= e($c['status']) ?></span></td>
  <td class="text-end"><?= (int) $c['used'] ?></td></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="7" class="small text-secondary"><?= e(t('Aucune connexion. Commencez par la simulation pour tester la caisse.')) ?></td></tr><?php endif; ?></tbody></table></div></div>
