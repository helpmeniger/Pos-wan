<div class="error-page text-center py-5">
    <div class="display-5 text-muted"><?= (int) $status ?></div>
    <h1 class="h4"><?= e($title) ?></h1>
    <p class="text-muted"><?= e($message) ?></p>
    <a class="btn btn-primary" href="<?= e(url('dashboard')) ?>">Retour au tableau de bord</a>
</div>
