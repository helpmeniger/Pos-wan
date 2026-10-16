<?php $this->layout('layouts/app'); ?>
<?php
$icons = [403 => 'shield-lock', 404 => 'signpost-split', 419 => 'hourglass-split', 429 => 'stopwatch', 500 => 'tools', 503 => 'cone-striped'];
?>
<div class="container py-5 text-center" style="max-width:640px">
  <div class="jn-icon accent mb-3 mx-auto" style="width:4rem;height:4rem;font-size:2rem"><i class="bi bi-<?= e($icons[$status] ?? 'exclamation-circle') ?>" aria-hidden="true"></i></div>
  <h1 class="h3">Erreur <?= (int) $status ?></h1>
  <p class="text-muted"><?= e($message) ?></p>
  <div class="d-flex gap-2 justify-content-center flex-wrap">
    <a class="btn btn-primary" href="<?= e(url('/')) ?>">Retour à l'accueil</a>
    <?php if ($status === 403 || $status === 404): ?><a class="btn btn-outline-primary" href="<?= e(url('/contact')) ?>">Contacter le support</a><?php endif; ?>
  </div>
</div>
