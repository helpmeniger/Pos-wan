<div class="card shadow-sm"><div class="card-body text-center py-5">
  <div class="display-5 text-muted mb-2"><?= (int) $status ?></div>
  <p class="lead"><?= e($message) ?></p>
  <a href="<?= url('dashboard') ?>" class="btn btn-primary">Retour au tableau de bord</a>
</div></div>
