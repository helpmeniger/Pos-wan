<section class="container py-5" style="max-width:720px">
  <div class="saas-card text-center">
    <i class="bi bi-check-circle-fill display-4 text-success"></i>
    <h1 class="h3 mt-2">Bienvenue, <?= e($tenant['contact_name']) ?> !</h1>
    <p>L'espace <b><?= e($tenant['company']) ?></b> est en cours d'installation : base de données, boutique, caisse et votre compte administrateur.</p>
    <p class="fs-5"><b><?= e($tenant['slug'] . '.' . config('saas.domain')) ?></b></p>
    <p class="text-muted small">Notez cette adresse : c'est celle de votre logiciel, sur ordinateur comme sur téléphone. Essai gratuit jusqu'au <?= e(date('d/m/Y', strtotime((string) $tenant['trial_ends']))) ?>.</p>
    <a href="<?= e($url) ?>" class="btn btn-warning btn-lg fw-semibold"><i class="bi bi-box-arrow-in-right"></i> Ouvrir mon espace</a>
    <p class="small text-muted mt-3 mb-0">Vous êtes redirigé automatiquement dans quelques secondes.</p>
  </div>
</section>
