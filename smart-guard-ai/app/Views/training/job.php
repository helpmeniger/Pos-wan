<div class="card mb-3" id="jobBox" data-id="<?= (int) $job['id'] ?>" data-status="<?= e($job['status']) ?>"><div class="card-body">
  <div class="d-flex justify-content-between"><strong>Tâche #<?= (int) $job['id'] ?></strong><span id="jobStatus"><?= e(label('job', $job['status'])) ?></span></div>
  <div class="progress my-2"><div class="progress-bar" id="jobBar" style="width:<?= (int) $job['progress'] ?>%"><?= (int) $job['progress'] ?> %</div></div>
  <div id="jobMsg" class="small text-muted"><?= e($job['message']) ?></div>
</div></div>
<?php if ($version && in_array($job['status'], ['termine', 'echec'], true)): $v = $version; ?>
  <h2 class="h6">Version <?= (int) $v['version_number'] ?> — <?= e(label('version', $v['status'])) ?></h2>
  <?php require __DIR__ . '/_results.php'; ?>
  <?php if ($v['status'] === 'prete' && can('ai.manage')): ?>
  <form method="post" action="<?= url('training/versions/' . (int) $v['id'] . '/activate') ?>" class="mt-3"><?= csrf_field() ?><button class="btn btn-success"><i class="bi bi-check2-circle me-1"></i>Activer la détection avec cette version</button></form>
  <?php endif; ?>
<?php endif; ?>
<a href="<?= url('training') ?>" class="btn btn-light mt-3">Retour</a>
