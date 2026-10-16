<?php $this->layout('layouts/app');
$p = $data['posts']; $m = $data['missions']; $s = $data['spending'];
$sum = fn (array $set, array $keys) => array_sum(array_intersect_key($set, array_flip($keys)));
$verif = ['NON_VERIFIEE' => 'Non vérifiée', 'EN_COURS' => 'Vérification en cours', 'VERIFIEE' => 'Vérifiée', 'REFUSEE' => 'Refusée'];
?>
<div class="container py-4">
  <h1 class="h3 mb-0">Bonjour <?= e($user['first_name']) ?> 👋</h1>
  <p class="text-muted small"><?= e($user['city_name'] ?? '') ?></p>

  <?php if ($data['businesses']): ?>
    <div class="jn-card p-3 mb-3">
      <div class="jn-section-title">Mes organisations</div>
      <?php foreach ($data['businesses'] as $b): ?>
        <div class="d-flex justify-content-between flex-wrap gap-2 border-bottom py-2">
          <span><i class="bi bi-building text-primary" aria-hidden="true"></i> <strong><?= e($b['name']) ?></strong> <span class="text-muted small">(<?= e($b['member_role']) ?>)</span></span>
          <span class="badge text-bg-light"><?= e($verif[$b['verification_status']] ?? $b['verification_status']) ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="row g-3 mb-3">
    <div class="col-6 col-lg-3"><?= $this->partial('partials/kpi', ['label' => 'Missions publiées', 'value' => (string) $sum($p, ['PUBLIEE', 'CANDIDATURES_OUVERTES', 'EN_NEGOCIATION', 'ATTRIBUEE', 'EN_COURS', 'EN_ATTENTE_DE_VALIDATION', 'TERMINEE']), 'icon' => 'megaphone', 'hint' => (int) ($p['BROUILLON'] ?? 0) . ' brouillon(s)']) ?></div>
    <div class="col-6 col-lg-3"><?= $this->partial('partials/kpi', ['label' => 'Candidatures à traiter', 'value' => (string) $data['applicationsReceived'], 'icon' => 'people']) ?></div>
    <div class="col-6 col-lg-3"><?= $this->partial('partials/kpi', ['label' => 'Missions en cours', 'value' => (string) $sum($m, ['ATTRIBUEE', 'EN_COURS', 'EN_ATTENTE_DE_VALIDATION']), 'icon' => 'hourglass-split']) ?></div>
    <div class="col-6 col-lg-3"><?= $this->partial('partials/kpi', ['label' => 'Missions terminées', 'value' => (string) ($m['TERMINEE'] ?? 0), 'icon' => 'check2-circle']) ?></div>
  </div>

  <div class="row g-3">
    <div class="col-lg-8">
      <div class="jn-card p-3 mb-3">
        <div class="jn-section-title">Mes dépenses</div>
        <div class="row g-3">
          <div class="col-6 col-md-4"><div class="small text-muted">Paiements confirmés</div><div class="fw-bold"><?= e(money((string) $s['paid'])) ?></div></div>
          <div class="col-6 col-md-4"><div class="small text-muted">En attente</div><div class="fw-bold"><?= e(money((string) $s['pending'])) ?></div></div>
          <div class="col-6 col-md-4"><div class="small text-muted">Remboursements</div><div class="fw-bold"><?= e(money((string) $s['refunded'])) ?></div></div>
        </div>
      </div>
      <div class="jn-card p-3">
        <div class="jn-section-title">Notifications récentes</div>
        <?= $this->partial('partials/notifications-list', ['notifications' => $notifications]) ?>
        <a href="<?= e(url('/notifications')) ?>" class="small">Toutes mes notifications</a>
      </div>
    </div>
    <div class="col-lg-4">
      <div class="jn-card p-3">
        <div class="jn-section-title">En bref</div>
        <ul class="list-unstyled small mb-2">
          <li class="mb-1"><i class="bi bi-star text-primary" aria-hidden="true"></i> Travailleurs favoris : <?= (int) $data['favorites'] ?></li>
          <li class="mb-1"><i class="bi bi-exclamation-diamond text-primary" aria-hidden="true"></i> Litiges ouverts : <?= (int) $data['openDisputes'] ?></li>
        </ul>
        <p class="small text-muted mb-0">La publication de missions et la recherche de prestataires arrivent avec la prochaine mise à jour.
          En attendant, découvrez les <a href="<?= e(url('/categories')) ?>">métiers disponibles</a>.</p>
      </div>
    </div>
  </div>
</div>
