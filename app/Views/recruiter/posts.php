<?php $this->layout('layouts/app'); ?>
<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <h1 class="h3 mb-0">Mes offres</h1>
    <a class="btn btn-primary" href="<?= e(url('/recruteur/offres/nouvelle')) ?>"><i class="bi bi-plus-lg" aria-hidden="true"></i> Publier une offre</a>
  </div>
  <?php if (!$posts): ?>
    <div class="jn-card p-4 text-center">
      <p class="mb-2">Vous n'avez pas encore publié d'offre.</p>
      <a class="btn btn-primary" href="<?= e(url('/recruteur/offres/nouvelle')) ?>">Publier ma première offre</a>
    </div>
  <?php else: ?>
  <div class="jn-card table-responsive">
    <table class="table table-jn align-middle mb-0">
      <thead><tr><th>Offre</th><th>Statut</th><th class="text-center">Candidatures</th><th class="text-center">Postes</th><th>Clôture</th></tr></thead>
      <tbody>
        <?php foreach ($posts as $p): ?>
          <tr>
            <td><a class="fw-semibold" href="<?= e(url('/recruteur/offres/' . $p['uuid'])) ?>"><?= e($p['title']) ?></a><div class="small text-muted"><?= e($p['reference']) ?> · <?= e($p['city'] ?? '') ?></div></td>
            <td><?= badge(str_replace('_', ' ', ucfirst(strtolower($p['status']))), status_tone($p['status'])) ?></td>
            <td class="text-center"><?= (int) $p['applications'] ?><?php if ($p['new_applications'] > 0): ?> <span class="badge text-bg-danger"><?= (int) $p['new_applications'] ?> nouv.</span><?php endif; ?></td>
            <td class="text-center"><?= (int) $p['positions_filled'] ?> / <?= (int) $p['positions_total'] ?></td>
            <td class="small"><?= e(date_fr($p['application_deadline'])) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
