<?php $this->layout('layouts/print'); use App\Services\Employment\PayslipService; use App\Support\Phone; ?>
<div class="sheet-head d-flex justify-content-between align-items-end">
  <div><img src="<?= e(asset('images/logo.svg')) ?>" alt="JOB NIGER" width="180" height="42"><h1 class="h4 mt-2 mb-0">Fiche de paie</h1></div>
  <div class="text-end small">N° <?= e($p['number']) ?><br>Émise le <?= e(date_fr($p['created_at'])) ?></div>
</div>
<div class="row small mb-3">
  <div class="col-6"><div class="fw-bold">Employeur</div><?= e($p['business_name'] ?: $p['employer_name']) ?><br>
    <?= $p['business_name'] ? 'Représenté par ' . e($p['employer_name']) . '<br>' : '' ?><?= $p['business_address'] ? e($p['business_address']) . '<br>' : '' ?>
    <?= $p['rccm_number'] ? 'RCCM : ' . e($p['rccm_number']) . '<br>' : '' ?><?= $p['nif_number'] ? 'NIF : ' . e($p['nif_number']) . '<br>' : '' ?>Tél. : <?= e(Phone::format($p['employer_phone'])) ?></div>
  <div class="col-6"><div class="fw-bold">Employé(e)</div><?= e($p['employee_name']) ?><br>Tél. : <?= e(Phone::format($p['employee_phone'])) ?><br><?= e($p['employee_city'] ?? '') ?></div>
</div>
<table class="table table-bordered table-sm small">
  <tr><th style="width:45%">Emploi</th><td><?= e($p['position_title']) ?> (contrat <?= e($p['employment_reference']) ?>, depuis le <?= e(date_fr($p['contract_start'])) ?>)</td></tr>
  <tr><th>Temps de travail</th><td><?= e($p['work_days']) ?><?= $p['daily_start_time'] ? ', ' . e(time_fr($p['daily_start_time'])) . ' – ' . e(time_fr($p['daily_end_time'])) : '' ?> — <?= e(rtrim(rtrim((string) $p['hours_per_week'], '0'), '.')) ?> h / semaine</td></tr>
  <tr><th>Période payée</th><td><?= e($p['period_label']) ?> : du <?= e(date_fr($p['period_start'])) ?> au <?= e(date_fr($p['period_end'])) ?> (<?= (int) $p['days_in_period'] ?> jours)</td></tr>
</table>
<table class="table table-bordered table-sm">
  <thead class="table-light"><tr><th>Désignation</th><th class="text-end">Montant (FCFA)</th></tr></thead>
  <tr><td>Salaire de référence (<?= $p['pay_period'] === 'MENSUEL' ? 'mensuel' : 'prestation' ?>)</td><td class="text-end jn-amount"><?= e(money((string) $p['reference_salary'])) ?></td></tr>
  <tr><td>Salaire brut de la période</td><td class="text-end jn-amount"><?= e(money((string) $p['gross_amount'])) ?></td></tr>
  <tr><td>Commission JOB NIGER (<?= e(rtrim(rtrim((string) $p['commission_rate'], '0'), '.') ?: '0') ?> %)</td><td class="text-end jn-amount">− <?= e(money((string) $p['commission_amount'])) ?></td></tr>
  <tr class="fw-bold"><td>NET À PAYER</td><td class="text-end jn-amount"><?= e(money((string) $p['net_amount'])) ?></td></tr>
</table>
<p class="small">Statut : <strong><?= e(PayslipService::LABELS[$p['status']] ?? $p['status']) ?></strong>
  <?= $p['payment_method'] ? ' — mode : ' . ($p['payment_method'] === 'ESPECES' ? 'en main propre' : 'Mobile Money' . ($p['payout_phone'] ? ' (' . e(Phone::mask($p['payout_phone'])) . ')' : '')) : '' ?>
  <?= $p['employee_confirmed_at'] ? '<br>Réception confirmée par l\'employé(e) le ' . e(date_fr($p['employee_confirmed_at'], true)) . '.' : '' ?></p>
<p class="small text-muted mt-4">Empreinte d'intégrité : <?= e($p['content_hash']) ?><br>Fiche générée automatiquement par JOB NIGER.
  Elle atteste du salaire convenu et de son suivi dans l'application ; elle ne remplace pas les déclarations sociales et fiscales prévues par la loi.</p>
