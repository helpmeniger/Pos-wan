<?php $this->layout('layouts/app');
$s = $data['spending'];
$verif = ['NON_VERIFIEE' => 'Non vérifiée', 'EN_COURS' => 'Vérification en cours', 'VERIFIEE' => 'Vérifiée', 'REFUSEE' => 'Refusée'];
$level = (int) ($user['verification_level'] ?? 0);
$businessVerified = (bool) array_filter($data['businesses'], fn ($b) => $b['verification_status'] === 'VERIFIEE');
?>
<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div><h1 class="h3 mb-0">Bonjour <?= e($user['first_name']) ?> 👋</h1><p class="text-muted small mb-0"><?= e($user['city_name'] ?? '') ?></p></div>
    <div class="d-flex gap-2 flex-wrap">
      <a class="btn btn-primary" href="<?= e(url('/recruteur/offres/nouvelle')) ?>"><i class="bi bi-plus-lg" aria-hidden="true"></i> Publier une offre</a>
      <?php if ($level < 2 && !$businessVerified): ?><a class="btn btn-outline-primary" href="<?= e(url('/verification')) ?>"><i class="bi bi-patch-check" aria-hidden="true"></i> Faire vérifier mon compte</a><?php endif; ?>
    </div>
  </div>

  <?php if ($data['businesses']): ?>
    <div class="jn-card p-3 mb-3">
      <?php foreach ($data['businesses'] as $b): ?>
        <div class="d-flex justify-content-between flex-wrap gap-2">
          <span><i class="bi bi-building text-primary" aria-hidden="true"></i> <strong><?= e($b['name']) ?></strong></span>
          <?= badge($verif[$b['verification_status']] ?? $b['verification_status'], $b['verification_status'] === 'VERIFIEE' ? 'success' : 'warning') ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="row g-3 mb-3">
    <div class="col-6 col-lg-3"><?= $this->partial('partials/kpi', ['label' => 'Offres ouvertes', 'value' => (string) (($data['posts']['CANDIDATURES_OUVERTES'] ?? 0) + ($data['posts']['PUBLIEE'] ?? 0)), 'icon' => 'megaphone', 'hint' => (int) ($data['posts']['EN_MODERATION'] ?? 0) . ' en vérification']) ?></div>
    <div class="col-6 col-lg-3"><?= $this->partial('partials/kpi', ['label' => 'Candidatures à traiter', 'value' => (string) $data['applicationsReceived'], 'icon' => 'people']) ?></div>
    <div class="col-6 col-lg-3"><?= $this->partial('partials/kpi', ['label' => 'Employés actifs', 'value' => (string) count(array_filter($employments, fn ($e) => $e['as_employer'] && $e['status'] === 'ACTIF')), 'icon' => 'person-badge']) ?></div>
    <div class="col-6 col-lg-3"><?= $this->partial('partials/kpi', ['label' => 'Fiches de paie à régler', 'value' => (string) count(array_filter($payslips, fn ($p) => $p['as_employer'])), 'icon' => 'receipt']) ?></div>
  </div>

  <div class="row g-3">
    <div class="col-lg-8">
      <?= $this->partial('dashboard/_activity', ['interviews' => $interviews, 'employments' => $employments, 'payslips' => $payslips]) ?>
      <div class="jn-card p-3 mb-3">
        <div class="jn-section-title">Mes dernières offres</div>
        <?php if (!$posts): ?><p class="small text-muted mb-0">Aucune offre. <a href="<?= e(url('/recruteur/offres/nouvelle')) ?>">Publiez votre première offre</a> : c'est gratuit.</p><?php endif; ?>
        <?php foreach ($posts as $p): ?>
          <div class="d-flex justify-content-between flex-wrap gap-2 border-bottom py-2 small">
            <a href="<?= e(url('/recruteur/offres/' . $p['uuid'])) ?>"><?= e($p['title']) ?></a>
            <span><?= (int) $p['applications'] ?> candidature(s)<?= (int) $p['new_applications'] > 0 ? ' ' . badge((int) $p['new_applications'] . ' nouvelle(s)', 'danger') : '' ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="col-lg-4">
      <div class="jn-card p-3 mb-3">
        <div class="jn-section-title">Notifications</div>
        <?= $this->partial('partials/notifications-list', ['notifications' => $notifications]) ?>
        <a href="<?= e(url('/notifications')) ?>" class="small">Toutes mes notifications</a>
      </div>
      <div class="jn-card p-3">
        <div class="jn-section-title">Comment recruter ?</div>
        <ol class="jn-steps small mb-0">
          <li>Publiez une offre (gratuit)</li><li>Recevez des candidatures</li><li>Proposez un entretien en ligne ou en présentiel</li>
          <li>Embauchez : poste, horaires, salaire</li><li>Les fiches de paie se génèrent seules ; payez par Mobile Money ou en main propre</li>
        </ol>
      </div>
    </div>
  </div>
</div>
