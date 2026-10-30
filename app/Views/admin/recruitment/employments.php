<?php $this->layout('layouts/app'); use App\Services\Employment\EmploymentService; ?>
<?= $this->partial('partials/admin-nav') ?>
<div class="container pb-4">
  <h1 class="h4">Emplois et contrats <span class="text-muted fs-6">(<?= (int) $result['total'] ?>)</span></h1>
  <form class="row g-2 mb-3" method="get">
    <div class="col-md-5"><input class="form-control form-control-sm" name="q" value="<?= e($f['q']) ?>" placeholder="Poste, référence, nom" aria-label="Recherche"></div>
    <div class="col-md-4"><select class="form-select form-select-sm" name="status" aria-label="Statut"><option value="">Tous les statuts</option><?php foreach (EmploymentService::STATUS_LABELS as $k => $v): ?><option value="<?= $k ?>"<?= $f['status'] === $k ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-3 d-grid"><button class="btn btn-sm btn-primary">Filtrer</button></div>
  </form>
  <div class="jn-card table-responsive"><table class="table table-jn align-middle mb-0">
    <thead><tr><th>Contrat</th><th>Employé</th><th>Employeur</th><th class="text-end">Salaire</th><th>Temps</th><th>Statut</th><th class="text-center">Fiches</th></tr></thead>
    <tbody><?php foreach ($result['rows'] as $e): ?><tr>
      <td class="small"><a href="<?= e(url('/admin/emplois/' . $e['uuid'])) ?>"><?= e($e['reference']) ?></a><div><?= e($e['position_title']) ?></div><div class="text-muted"><?= e(date_fr($e['start_date'])) ?> → <?= $e['end_date'] ? e(date_fr($e['end_date'])) : '…' ?></div></td>
      <td class="small"><a href="<?= e(url('/admin/utilisateurs/' . $e['employee_uuid'])) ?>"><?= e($e['employee']) ?></a></td>
      <td class="small"><a href="<?= e(url('/admin/utilisateurs/' . $e['employer_uuid'])) ?>"><?= e($e['employer']) ?></a></td>
      <td class="text-end small jn-amount"><?= e(money((string) $e['salary_amount'])) ?><div class="text-muted"><?= $e['pay_period'] === 'MENSUEL' ? '/ mois' : 'prestation' ?></div></td>
      <td class="small"><?= e(rtrim(rtrim((string) $e['hours_per_week'], '0'), '.')) ?> h/sem.</td>
      <td><?= badge(EmploymentService::STATUS_LABELS[$e['status']] ?? $e['status'], status_tone($e['status'])) ?></td>
      <td class="text-center"><?= (int) $e['payslips'] ?></td></tr>
    <?php endforeach; ?></tbody></table></div>
  <?= $this->partial('partials/pagination', ['result' => $result]) ?>
</div>
