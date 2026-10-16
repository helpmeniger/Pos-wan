<div class="mb-3 small text-muted">Créée le <?= dt($v['created_at']) ?> — extracteur : <?= e($v['embedder'] ?? '—') ?> — fichier : <code><?= e($v['artifact_file'] ?? '—') ?></code>
<?= $v['notes'] ? '<br>Note : ' . e($v['notes']) : '' ?></div>
<?php require __DIR__ . '/_results.php'; ?>
<div class="d-flex gap-2 mt-3">
<?php if (can('ai.manage') && in_array($v['status'], ['prete', 'archivee'], true)): ?>
  <form method="post" action="<?= url('training/versions/' . (int) $v['id'] . '/activate') ?>"><?= csrf_field() ?><button class="btn btn-success"><?= $v['status'] === 'archivee' ? 'Revenir à cette version' : 'Activer cette version' ?></button></form>
<?php endif; ?>
<a href="<?= url('training') ?>" class="btn btn-light">Retour</a></div>
