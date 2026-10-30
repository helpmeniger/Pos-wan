<?php $this->layout('layouts/app');
/* Revenus : les salaires confirmés figurent dans les archives ; ici, paiements de missions */
$a = $data['applications']; $m = $data['missions']; $earn = $data['earnings'];
$levels = [0 => 'Inscrit', 1 => 'Téléphone vérifié', 2 => 'Identité vérifiée', 3 => 'Qualification vérifiée'];
$level = (int) ($data['profile']['verification_level'] ?? 0);
$sum = fn (array $set, array $keys) => array_sum(array_intersect_key($set, array_flip($keys)));
?>
<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div>
      <h1 class="h3 mb-0">Bonjour <?= e($user['first_name']) ?> 👋</h1>
      <div class="text-muted small"><?= e($user['city_name'] ?? '') ?> · Niveau de vérification : <strong><?= e($levels[$level] ?? (string) $level) ?></strong></div>
    </div>
    <div class="d-flex gap-2 flex-wrap">
      <a class="btn btn-primary" href="<?= e(url('/offres')) ?>"><i class="bi bi-search" aria-hidden="true"></i> Trouver un travail</a>
      <?php if ($level < 2): ?><a class="btn btn-outline-primary" href="<?= e(url('/verification')) ?>"><i class="bi bi-patch-check" aria-hidden="true"></i> Faire vérifier mon profil</a><?php endif; ?>
    </div>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-6 col-lg-3"><?= $this->partial('partials/kpi', ['label' => 'Candidatures envoyées', 'value' => (string) array_sum($a), 'icon' => 'send', 'hint' => (int) $sum($a, ['SOUMISE', 'VUE', 'PRESELECTIONNEE', 'INFOS_DEMANDEES', 'EN_NEGOCIATION']) . ' en cours']) ?></div>
    <div class="col-6 col-lg-3"><?= $this->partial('partials/kpi', ['label' => 'Candidatures acceptées', 'value' => (string) ($a['ACCEPTEE'] ?? 0), 'icon' => 'hand-thumbs-up']) ?></div>
    <div class="col-6 col-lg-3"><?= $this->partial('partials/kpi', ['label' => 'Missions en cours', 'value' => (string) $sum($m, ['ATTRIBUEE', 'EN_COURS', 'EN_ATTENTE_DE_VALIDATION']), 'icon' => 'hourglass-split']) ?></div>
    <div class="col-6 col-lg-3"><?= $this->partial('partials/kpi', ['label' => 'Missions terminées', 'value' => (string) ($m['TERMINEE'] ?? 0), 'icon' => 'check2-circle']) ?></div>
  </div>

  <div class="row g-3">
    <div class="col-lg-8">
      <?= $this->partial('dashboard/_activity', ['interviews' => $interviews, 'employments' => $employments, 'payslips' => $payslips]) ?>
      <?php if ($offers): ?>
      <div class="jn-card p-3 mb-3">
        <div class="jn-section-title"><i class="bi bi-lightning text-primary" aria-hidden="true"></i> Offres près de chez vous</div>
        <?php foreach ($offers as $o): ?>
          <div class="d-flex justify-content-between flex-wrap gap-2 border-bottom py-2 small">
            <a href="<?= e(url('/offres/' . $o['uuid'])) ?>"><?= e($o['title']) ?> — <?= e($o['recruiter']) ?></a>
            <span class="fw-semibold text-primary jn-amount"><?= e(App\Services\Jobs\JobPostService::payLabel($o)) ?></span>
          </div>
        <?php endforeach; ?>
        <a class="small" href="<?= e(url('/offres')) ?>">Toutes les offres →</a>
      </div>
      <?php endif; ?>
      <div class="jn-card p-3 mb-3">
        <div class="jn-section-title">Mes revenus</div>
        <div class="row g-3">
          <div class="col-6 col-md-3"><div class="small text-muted">Revenus bruts</div><div class="fw-bold"><?= e(money((string) $earn['gross'])) ?></div></div>
          <div class="col-6 col-md-3"><div class="small text-muted">Commissions</div><div class="fw-bold"><?= e(money((string) $earn['commission'])) ?></div></div>
          <div class="col-6 col-md-3"><div class="small text-muted">Revenus nets</div><div class="fw-bold text-primary"><?= e(money((string) $earn['net'])) ?></div></div>
          <div class="col-6 col-md-3"><div class="small text-muted">Paiements en attente</div><div class="fw-bold"><?= e(money((string) $earn['pending'])) ?></div></div>
        </div>
        <p class="small text-muted mb-0 mt-2">Seuls les paiements confirmés sont comptés dans vos revenus. Déjà versé : <?= e(money((string) $earn['paid_out'])) ?>.</p>
      </div>
      <div class="jn-card p-3">
        <div class="jn-section-title">Notifications récentes</div>
        <?= $this->partial('partials/notifications-list', ['notifications' => $notifications]) ?>
        <a href="<?= e(url('/notifications')) ?>" class="small">Toutes mes notifications</a>
      </div>
    </div>
    <div class="col-lg-4">
      <div class="jn-card p-3">
        <div class="jn-section-title">Mon profil est complet à <?= (int) $data['completionPercent'] ?> %</div>
        <div class="progress mb-3" role="progressbar" aria-label="Complétude du profil" aria-valuenow="<?= (int) $data['completionPercent'] ?>" aria-valuemin="0" aria-valuemax="100">
          <div class="progress-bar" style="width: <?= (int) $data['completionPercent'] ?>%"></div>
        </div>
        <ul class="list-unstyled small mb-2">
          <?php foreach ($data['completion'] as $label => $ok): ?>
            <li class="mb-1"><i class="bi <?= $ok ? 'bi-check-circle-fill text-primary' : 'bi-circle text-muted' ?>" aria-hidden="true"></i> <?= e($label) ?></li>
          <?php endforeach; ?>
        </ul>
        <a class="btn btn-sm btn-outline-primary" href="<?= e(url('/profil')) ?>">Compléter mon profil</a>
      </div>
    </div>
  </div>
</div>
