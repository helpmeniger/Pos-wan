<?php
$icons = ['success' => 'check-circle', 'danger' => 'exclamation-octagon', 'warning' => 'exclamation-triangle', 'info' => 'info-circle'];
foreach (App\Core\Session::flashes() as $type => $message):
    $type = isset($icons[$type]) ? $type : 'info'; ?>
    <div class="alert alert-<?= e($type) ?> alert-dismissible fade show d-flex align-items-start gap-2 mc-flash" role="alert">
        <i class="bi bi-<?= e($icons[$type]) ?> fs-5"></i>
        <div class="flex-grow-1"><?= e($message) ?></div>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fermer"></button>
    </div>
<?php endforeach; ?>
