<?php
$messages = ['success' => flash('success'), 'error' => flash('error'), 'warning' => flash('warning'), 'info' => flash('info')];
$classes = ['success' => 'success', 'error' => 'danger', 'warning' => 'warning', 'info' => 'info'];
$icons = ['success' => 'check-circle', 'error' => 'exclamation-octagon', 'warning' => 'exclamation-triangle', 'info' => 'info-circle'];
$hasErrors = errors() !== [];
?>
<?php if (array_filter($messages) || $hasErrors): ?>
<div class="container mt-3">
  <?php foreach ($messages as $type => $message): if (!$message) continue; ?>
    <div class="alert alert-<?= $classes[$type] ?> alert-dismissible fade show" role="<?= $type === 'error' ? 'alert' : 'status' ?>">
      <i class="bi bi-<?= $icons[$type] ?> me-1" aria-hidden="true"></i> <?= e($message) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fermer"></button>
    </div>
  <?php endforeach; ?>
  <?php if ($hasErrors && !$messages['error']): ?>
    <div class="alert alert-danger" role="alert"><i class="bi bi-exclamation-octagon me-1" aria-hidden="true"></i> Merci de corriger les champs signalés ci-dessous.</div>
  <?php endif; ?>
</div>
<?php endif; ?>
