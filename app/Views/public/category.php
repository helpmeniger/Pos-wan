<?php $this->layout('layouts/app');
$riskLabels = ['LOW' => 'Standard', 'MEDIUM' => 'Vérification renforcée', 'HIGH' => 'Profil vérifié requis', 'REGULATED' => 'Qualification obligatoire'];
?>
<div class="container py-4">
  <nav aria-label="Fil d'Ariane"><ol class="breadcrumb small">
    <li class="breadcrumb-item"><a href="<?= e(url('/')) ?>">Accueil</a></li>
    <li class="breadcrumb-item"><a href="<?= e(url('/categories')) ?>">Métiers</a></li>
    <li class="breadcrumb-item active" aria-current="page"><?= e($category['name']) ?></li>
  </ol></nav>
  <div class="d-flex align-items-center gap-3 mb-3">
    <span class="jn-icon"><i class="bi <?= e($category['icon'] ?: 'bi-briefcase') ?>" aria-hidden="true"></i></span>
    <h1 class="h3 mb-0"><?= e($category['name']) ?></h1>
  </div>
  <?php if (!empty($category['safety_notice'])): ?>
    <div class="alert alert-warning"><i class="bi bi-shield-exclamation me-1" aria-hidden="true"></i><strong>Règles de sécurité :</strong> <?= e($category['safety_notice']) ?></div>
  <?php endif; ?>
  <div class="jn-card">
    <ul class="list-group list-group-flush">
      <?php foreach ($subcategories as $s): ?>
        <li class="list-group-item d-flex justify-content-between align-items-center flex-wrap gap-2">
          <span><?= e($s['name']) ?><?php if ($s['is_remote_possible']): ?> <span class="badge text-bg-light"><i class="bi bi-wifi" aria-hidden="true"></i> à distance possible</span><?php endif; ?></span>
          <span class="d-flex gap-1 flex-wrap">
            <span class="badge badge-risk-<?= e($s['risk_level']) ?>"><?= e($riskLabels[$s['risk_level']] ?? $s['risk_level']) ?></span>
            <span class="badge text-bg-light"><?= (int) $s['min_age'] ?> ans min.</span>
            <?php if ($s['involves_minors_or_vulnerable']): ?><span class="badge text-bg-light">Mineurs / personnes vulnérables</span><?php endif; ?>
          </span>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
  <p class="text-muted small mt-3">Les annonces de missions et la recherche de prestataires par métier arrivent dans la prochaine version.
    <a href="<?= e(url('/inscription')) ?>">Créez votre compte</a> dès maintenant pour être prêt.</p>
</div>
