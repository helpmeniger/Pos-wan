<?= App\Core\View::partial('fiscal::_nav', ['tab' => $tab]) ?>
<?php foreach ($warnings as $w): ?><div class="alert alert-warning py-2 small mb-2"><i class="bi bi-exclamation-triangle"></i> <?= e(t($w)) ?></div><?php endforeach; ?>
<div class="alert alert-light border small py-2"><i class="bi bi-info-circle"></i> <?= e(t('Marwa Fiscal Connect transmet les factures au dispositif ou à la plateforme configurés. Il ne rend pas POS Marwa homologué : l\'homologation relève de l\'administration fiscale de chaque pays. Aucun pilote n\'est déclaré compatible sans vérification du protocole officiel.')) ?></div>
<div class="row g-2 mb-3">
<?php foreach ([['Certifiées (réel)', $by['certified'] ?? 0, 'success'], ['Simulation (sans valeur)', $by['certified_sim'] ?? 0, 'dark'], ['En file d\'attente', $queue, 'warning'], ['Refusées / échecs', ($by['rejected'] ?? 0) + ($by['failed'] ?? 0) + ($by['rejected_sim'] ?? 0) + ($by['failed_sim'] ?? 0), 'danger']] as [$l, $v, $c]): ?>
  <div class="col-6 col-lg-3"><div class="card border-<?= $c ?>"><div class="card-body py-2"><div class="small text-secondary"><?= e(t($l)) ?></div><div class="fs-4 fw-bold text-<?= $c ?>"><?= (int) $v ?></div></div></div></div>
<?php endforeach; ?>
</div>
<p class="small text-secondary"><?= e(t('Aujourd\'hui')) ?> : <?= (int) ($today['ok'] ?? 0) ?> <?= e(t('certifiée(s)')) ?> · <?= (int) ($today['wait'] ?? 0) ?> <?= e(t('en attente')) ?> · <?= (int) ($today['ko'] ?? 0) ?> <?= e(t('refusée(s) ou en échec')) ?></p>
<div class="row g-3">
  <div class="col-lg-6"><div class="card h-100"><div class="card-header fw-semibold"><?= e(t('Connecteurs')) ?></div><div class="table-responsive"><table class="table table-sm mb-0 align-middle">
    <thead><tr><th><?= e(t('Nom')) ?></th><th><?= e(t('Env.')) ?></th><th><?= e(t('État')) ?></th><th><?= e(t('Dernier test')) ?></th></tr></thead><tbody>
    <?php foreach ($connectors as $c): ?><tr><td><a href="<?= e(url('/fiscal/connecteurs/' . $c['id'])) ?>"><?= e($c['name']) ?></a><?= (int) $c['is_default'] ? ' <span class="badge text-bg-primary">' . e(t('défaut')) . '</span>' : '' ?></td>
      <td><span class="badge text-bg-<?= $c['environment'] === 'prod' ? 'danger' : 'secondary' ?>"><?= $c['environment'] === 'prod' ? 'PROD' : 'TEST' ?></span></td>
      <td><?= (int) $c['active'] ? '<span class="text-success">' . e(t('actif')) . '</span>' : '<span class="text-secondary">' . e(t('inactif')) . '</span>' ?> · <span class="<?= $c['status'] === 'ok' ? 'text-success' : ($c['status'] === 'error' ? 'text-danger' : 'text-secondary') ?>"><?= e($c['status']) ?></span></td>
      <td class="small"><?= e(fdate($c['last_test_at'])) ?></td></tr><?php endforeach; ?>
    <?php if (!$connectors): ?><tr><td colspan="4" class="text-secondary small"><?= e(t('Aucun connecteur. Commencez par « API » ou « MCF ».')) ?></td></tr><?php endif; ?></tbody></table></div></div></div>
  <div class="col-lg-6"><div class="card h-100"><div class="card-header fw-semibold"><?= e(t('Dernières erreurs')) ?></div><ul class="list-group list-group-flush small">
    <?php foreach ($lastErrors as $r): ?><li class="list-group-item"><a href="<?= e(url('/fiscal/certifications/' . $r['id'])) ?>"><?= e($r['local_number']) ?></a> <?= App\Core\View::partial('fiscal::_status', ['s' => $r['status']]) ?> <span class="text-secondary"><?= e($r['last_error']) ?></span></li><?php endforeach; ?>
    <?php if (!$lastErrors): ?><li class="list-group-item text-secondary"><?= e(t('Aucune erreur récente.')) ?></li><?php endif; ?></ul></div></div>
</div>
