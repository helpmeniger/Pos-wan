<?php $this->layout('layouts/app'); use App\Services\Jobs\ApplicationService; use App\Services\Jobs\InterviewService; ?>
<?= $this->partial('partials/admin-nav') ?>
<div class="container pb-4">
  <h1 class="h4">Candidatures et entretiens <span class="text-muted fs-6">(<?= (int) $result['total'] ?>)</span></h1>
  <form class="row g-2 mb-3" method="get">
    <div class="col-md-5"><input class="form-control form-control-sm" name="q" value="<?= e($f['q']) ?>" placeholder="Offre, candidat, référence" aria-label="Recherche"></div>
    <div class="col-md-4"><select class="form-select form-select-sm" name="status" aria-label="Statut"><option value="">Tous les statuts</option><?php foreach (ApplicationService::LABELS as $k => $v): ?><option value="<?= $k ?>"<?= $f['status'] === $k ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-3 d-grid"><button class="btn btn-sm btn-primary">Filtrer</button></div>
  </form>
  <div class="jn-card table-responsive"><table class="table table-jn align-middle mb-0">
    <thead><tr><th>Candidat</th><th>Offre</th><th>Recruteur</th><th>Statut</th><th>Entretien</th><th>Date</th></tr></thead>
    <tbody><?php foreach ($result['rows'] as $a): ?><tr>
      <td class="small"><a href="<?= e(url('/admin/utilisateurs/' . $a['candidate_uuid'])) ?>"><?= e($a['candidate']) ?></a></td>
      <td class="small"><a href="<?= e(url('/offres/' . $a['post_uuid'])) ?>"><?= e($a['title']) ?></a><div class="text-muted"><?= e($a['reference']) ?></div></td>
      <td class="small"><?= e($a['recruiter']) ?></td>
      <td><?= badge(ApplicationService::LABELS[$a['status']] ?? $a['status'], status_tone($a['status'])) ?></td>
      <td class="small"><?= $a['interview_at'] ? e(date_fr($a['interview_at'], true)) . ' · ' . ($a['interview_mode'] === 'EN_LIGNE' ? 'en ligne' : 'présentiel') . '<br>' . badge(InterviewService::LABELS[$a['interview_status']] ?? '', status_tone($a['interview_status'])) : '—' ?></td>
      <td class="small"><?= e(date_fr($a['created_at'])) ?></td></tr>
    <?php endforeach; ?></tbody></table></div>
  <?= $this->partial('partials/pagination', ['result' => $result]) ?>
</div>
