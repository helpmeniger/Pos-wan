<?php $this->layout('layouts/app'); use App\Services\Employment\EmploymentService; use App\Services\Employment\PayslipService; ?>
<?= $this->partial('partials/admin-nav') ?>
<div class="container pb-4" style="max-width:960px">
  <a class="small" href="<?= e(url('/admin/emplois')) ?>">← Emplois</a>
  <h1 class="h4 mt-2"><?= e($e['position_title']) ?> <?= badge(EmploymentService::STATUS_LABELS[$e['status']] ?? $e['status'], status_tone($e['status'])) ?></h1>
  <div class="jn-card p-3 mb-3"><?= $this->partial('employment/terms', ['terms' => $terms, 'e' => $e]) ?>
    <div class="small text-muted mt-2">Empreinte : <?= e($e['content_hash']) ?><?= $e['employee_accepted_at'] ? ' · accepté le ' . e(date_fr($e['employee_accepted_at'], true)) . ' (IP ' . e($e['employee_accept_ip'] ?? '') . ')' : '' ?></div></div>
  <div class="jn-card table-responsive"><table class="table table-jn mb-0">
    <thead><tr><th>Fiche</th><th class="text-end">Brut</th><th class="text-end">Commission</th><th class="text-end">Net</th><th>Statut</th></tr></thead>
    <tbody><?php foreach ($payslips as $p): ?><tr><td class="small"><?= can('finance.dashboard.view') ? '<a href="' . e(url('/admin/fiches-de-paie/' . $p['uuid'])) . '">' . e($p['number']) . '</a>' : e($p['number']) ?> — <?= e($p['period_label']) ?></td>
      <td class="text-end jn-amount small"><?= e(money((string) $p['gross_amount'])) ?></td><td class="text-end jn-amount small"><?= e(money((string) $p['commission_amount'])) ?></td><td class="text-end jn-amount small"><?= e(money((string) $p['net_amount'])) ?></td>
      <td><?= badge(PayslipService::LABELS[$p['status']] ?? $p['status'], status_tone($p['status'])) ?></td></tr><?php endforeach; ?>
      <?php if (!$payslips): ?><tr><td colspan="5" class="small text-muted">Aucune fiche.</td></tr><?php endif; ?></tbody></table></div>
</div>
