<?php $this->layout('layouts/app'); use App\Services\Employment\EmploymentService; use App\Services\Employment\PayslipService; ?>
<div class="container py-4">
  <div class="d-flex justify-content-between flex-wrap gap-2 mb-3 align-items-center">
    <h1 class="h3 mb-0">Mes archives</h1>
    <form method="get" class="d-flex gap-2" action="<?= e(url('/archives')) ?>"><label class="visually-hidden" for="annee">Année</label>
      <select class="form-select form-select-sm" id="annee" name="annee"><option value="">Toutes les années</option>
        <?php foreach ($years as $y): ?><option value="<?= (int) $y ?>"<?= $year === (int) $y ? ' selected' : '' ?>><?= (int) $y ?></option><?php endforeach; ?></select>
      <button class="btn btn-sm btn-outline-primary" type="submit">Filtrer</button></form>
  </div>
  <p class="text-muted small">Tous vos contrats (avec leurs clauses), fiches de paie, candidatures et entretiens restent consultables et imprimables ici, même après la fin d'un emploi.</p>
  <div class="row g-3 mb-3">
    <div class="col-6 col-md-3"><?= $this->partial('partials/kpi', ['label' => 'Salaires reçus (confirmés)', 'value' => money((string) $totals['received']), 'icon' => 'wallet2']) ?></div>
    <div class="col-6 col-md-3"><?= $this->partial('partials/kpi', ['label' => 'Salaires versés (confirmés)', 'value' => money((string) $totals['paid']), 'icon' => 'cash-stack']) ?></div>
  </div>

  <h2 class="h5">Contrats</h2>
  <div class="jn-card table-responsive mb-4"><table class="table table-jn align-middle mb-0">
    <thead><tr><th>Contrat</th><th>Partie</th><th>Période</th><th>Statut</th><th></th></tr></thead>
    <tbody>
      <?php if (!$employments): ?><tr><td colspan="5" class="text-muted small">Aucun contrat.</td></tr><?php endif; ?>
      <?php foreach ($employments as $e): ?><tr>
        <td><?= e($e['position_title']) ?><div class="small text-muted"><?= e($e['reference']) ?></div></td>
        <td class="small"><?= $e['as_employer'] ? 'Employé : ' . e($e['employee_name']) : 'Employeur : ' . e($e['business_name'] ?: $e['employer_name']) ?></td>
        <td class="small"><?= e(date_fr($e['start_date'])) ?> → <?= $e['end_date'] ? e(date_fr($e['end_date'])) : '…' ?></td>
        <td><?= badge(EmploymentService::STATUS_LABELS[$e['status']] ?? $e['status'], status_tone($e['status'])) ?></td>
        <td class="text-nowrap"><a class="btn btn-sm btn-outline-primary" href="<?= e(url('/emplois/' . $e['uuid'])) ?>">Voir</a>
          <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/emplois/' . $e['uuid'] . '/imprimer')) ?>" aria-label="Imprimer"><i class="bi bi-printer" aria-hidden="true"></i></a></td></tr>
      <?php endforeach; ?>
    </tbody></table></div>

  <h2 class="h5">Fiches de paie</h2>
  <div class="jn-card table-responsive mb-4"><table class="table table-jn align-middle mb-0">
    <thead><tr><th>Période</th><th>Poste</th><th class="text-end">Brut</th><th class="text-end">Net</th><th>Statut</th><th></th></tr></thead>
    <tbody>
      <?php if (!$payslips): ?><tr><td colspan="6" class="text-muted small">Aucune fiche de paie.</td></tr><?php endif; ?>
      <?php foreach ($payslips as $p): ?><tr>
        <td><?= e($p['period_label']) ?><div class="small text-muted"><?= e($p['number']) ?></div></td>
        <td class="small"><?= e($p['position_title']) ?></td>
        <td class="text-end jn-amount"><?= e(money((string) $p['gross_amount'])) ?></td><td class="text-end jn-amount fw-semibold"><?= e(money((string) $p['net_amount'])) ?></td>
        <td><?= badge(PayslipService::LABELS[$p['status']] ?? $p['status'], status_tone($p['status'])) ?></td>
        <td class="text-nowrap"><a class="btn btn-sm btn-outline-primary" href="<?= e(url('/fiches-de-paie/' . $p['uuid'])) ?>">Voir</a>
          <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/fiches-de-paie/' . $p['uuid'] . '/imprimer')) ?>" aria-label="Télécharger"><i class="bi bi-file-earmark-pdf" aria-hidden="true"></i></a></td></tr>
      <?php endforeach; ?>
    </tbody></table></div>

  <h2 class="h5">Historique</h2>
  <div class="jn-card p-3"><ul class="list-unstyled small mb-0">
    <?php if (!$history): ?><li class="text-muted">Rien pour le moment.</li><?php endif; ?>
    <?php foreach ($history as $h): ?>
      <li class="mb-1"><span class="text-muted"><?= e(date_fr($h['at'])) ?></span> — <?= e($h['kind']) ?> « <a href="<?= e(url('/offres/' . $h['post_uuid'])) ?>"><?= e($h['title']) ?></a> » : <?= e(strtolower(str_replace('_', ' ', $h['status']))) ?></li>
    <?php endforeach; ?>
  </ul></div>
</div>
