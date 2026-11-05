<section class="container py-5" style="max-width:720px">
  <div class="saas-card text-center">
    <i class="bi bi-hourglass-split display-4 text-warning"></i>
    <h1 class="h3 mt-2">Inscription enregistrée</h1>
    <p>Merci, <b><?= e($tenant['contact_name']) ?></b>. L'espace <b><?= e($tenant['company']) ?></b> (<?= e($tenant['slug'] . '.' . config('saas.domain')) ?>) est réservé.</p>
    <p class="text-muted">Son ouverture est en cours de préparation par notre équipe : nous vous appelons au <b><?= e($tenant['phone']) ?></b> dès qu'il est prêt. Votre période d'essai commence à l'ouverture.</p>
    <?php if ($operator['operator_phone']): ?><p class="mb-0">Une question ? <i class="bi bi-telephone"></i> <?= e($operator['operator_phone']) ?></p><?php endif; ?>
  </div>
</section>
