<?php $this->layout('layouts/app'); use App\Services\Employment\EmploymentService; use App\Services\Employment\PayslipService; ?>
<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <h1 class="h3 mb-0">Emplois et fiches de paie</h1>
    <a class="btn btn-outline-primary" href="<?= e(url('/archives')) ?>"><i class="bi bi-archive" aria-hidden="true"></i> Mes archives</a>
  </div>
  <h2 class="h5">Contrats en cours</h2>
  <?php if (!$employments): ?><div class="jn-card p-4 text-muted text-center mb-4">Aucun contrat en cours.</div><?php endif; ?>
  <div class="row g-3 mb-4">
    <?php foreach ($employments as $e): ?>
      <div class="col-md-6">
        <a class="jn-card jn-card-link p-3" href="<?= e(url('/emplois/' . $e['uuid'])) ?>">
          <div class="d-flex justify-content-between gap-2">
            <div class="fw-semibold"><?= e($e['position_title']) ?></div>
            <?= badge(EmploymentService::STATUS_LABELS[$e['status']] ?? $e['status'], status_tone($e['status'])) ?>
          </div>
          <div class="small text-muted"><?= $e['as_employer'] ? 'Employé : ' . e($e['employee_name']) : 'Employeur : ' . e($e['business_name'] ?: $e['employer_name']) ?></div>
          <div class="small mt-1"><span class="jn-amount fw-semibold"><?= e(money((string) $e['salary_amount'])) ?></span> <?= $e['pay_period'] === 'MENSUEL' ? 'par mois' : 'pour la prestation' ?>
            · depuis le <?= e(date_fr($e['start_date'])) ?><?= (int) $e['open_payslips'] > 0 ? ' · ' . badge((int) $e['open_payslips'] . ' fiche(s) à traiter', 'warning') : '' ?></div>
        </a>
      </div>
    <?php endforeach; ?>
  </div>
  <h2 class="h5">Fiches de paie à traiter</h2>
  <?php if (!$payslips): ?><div class="jn-card p-4 text-muted text-center">Aucune fiche de paie en attente.</div><?php else: ?>
  <div class="jn-card table-responsive"><table class="table table-jn align-middle mb-0">
    <thead><tr><th>Période</th><th>Poste</th><th class="text-end">Net</th><th>Statut</th><th></th></tr></thead>
    <tbody><?php foreach ($payslips as $p): ?>
      <tr><td><?= e($p['period_label']) ?><div class="small text-muted"><?= e($p['number']) ?></div></td>
        <td class="small"><?= e($p['position_title']) ?><br><span class="text-muted"><?= $p['as_employer'] ? e($p['employee_name']) : e($p['employer_name']) ?></span></td>
        <td class="text-end jn-amount fw-semibold"><?= e(money((string) $p['net_amount'])) ?></td>
        <td><?= badge(PayslipService::LABELS[$p['status']] ?? $p['status'], status_tone($p['status'])) ?></td>
        <td><a class="btn btn-sm btn-primary" href="<?= e(url('/fiches-de-paie/' . $p['uuid'])) ?>">Ouvrir</a></td></tr>
    <?php endforeach; ?></tbody></table></div>
  <?php endif; ?>
</div>
