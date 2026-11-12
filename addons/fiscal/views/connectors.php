<?= App\Core\View::partial('fiscal::_nav', ['tab' => $tab]) ?>
<div class="d-flex justify-content-between align-items-center mb-2"><p class="small text-secondary mb-0"><?= e(t($type === 'api' ? 'API REST/JSON, SOAP/XML et plateformes en ligne de facturation normalisée.' : 'Modules de contrôle fiscal (USB, série, Ethernet). USB et série : le dispositif doit être branché sur le serveur POS Marwa.')) ?></p>
<?php if (can('fiscal.configure')): ?><a class="btn btn-sm btn-primary" href="<?= e(url('/fiscal/connecteurs/nouveau', ['type' => $type])) ?>"><i class="bi bi-plus-lg"></i> <?= e(t('Ajouter')) ?></a><?php endif; ?></div>
<div class="card"><div class="table-responsive"><table class="table table-sm table-hover mb-0 align-middle">
<thead><tr><th><?= e(t('Nom')) ?></th><th><?= e(t('Pilote')) ?></th><th><?= e(t('Environnement')) ?></th><th><?= e(t('Portée')) ?></th><th><?= e(t('État')) ?></th><th><?= e(t('Dispositif')) ?></th><th class="text-end"><?= e(t('Documents')) ?></th></tr></thead><tbody>
<?php foreach ($rows as $c): ?><tr>
  <td><?= can('fiscal.configure') ? '<a href="' . e(url('/fiscal/connecteurs/' . $c['id'])) . '">' . e($c['name']) . '</a>' : e($c['name']) ?><?= (int) $c['is_default'] ? ' <span class="badge text-bg-primary">' . e(t('défaut')) . '</span>' : '' ?></td>
  <td class="small"><?= e(Addons\Fiscal\DriverRegistry::all()[$c['driver']]['label'] ?? $c['driver']) ?></td>
  <td><span class="badge text-bg-<?= $c['environment'] === 'prod' ? 'danger' : 'secondary' ?>"><?= $c['environment'] === 'prod' ? 'PRODUCTION' : 'TEST' ?></span></td>
  <td class="small"><?= e($c['register'] ? t('Caisse') . ' ' . $c['register'] : ($c['store'] ? t('Boutique') . ' ' . $c['store'] : t('Toutes'))) ?></td>
  <td><?= (int) $c['active'] ? '<span class="badge text-bg-success">' . e(t('actif')) . '</span>' : '<span class="badge text-bg-light border">' . e(t('inactif')) . '</span>' ?> <span class="small <?= $c['status'] === 'error' ? 'text-danger' : 'text-secondary' ?>" title="<?= e($c['last_test_message'] ?? '') ?>"><?= e($c['status']) ?></span></td>
  <td class="small"><?= e(trim(($c['device_id'] ?? '') . ' ' . ($c['model'] ?? ''))) ?></td><td class="text-end"><?= (int) $c['used'] ?></td></tr>
<?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="7" class="text-secondary small"><?= e(t('Aucun connecteur de ce type.')) ?></td></tr><?php endif; ?>
</tbody></table></div></div>
