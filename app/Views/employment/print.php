<?php $this->layout('layouts/print'); ?>
<div class="sheet-head d-flex justify-content-between align-items-end">
  <div><img src="<?= e(asset('images/logo.svg')) ?>" alt="JOB NIGER" width="180" height="42"><h1 class="h4 mt-2 mb-0">Contrat de travail / de prestation</h1></div>
  <div class="text-end small">N° <?= e($e['reference']) ?><br>Émis le <?= e(date_fr($e['created_at'])) ?></div>
</div>
<?= $this->partial('employment/terms', ['terms' => $terms, 'e' => $e]) ?>
<hr>
<p class="small">Statut : <?= e(App\Services\Employment\EmploymentService::STATUS_LABELS[$e['status']] ?? $e['status']) ?>.
  <?php if ($e['employee_accepted_at']): ?>Accepté électroniquement par l'employé(e) le <?= e(date_fr($e['employee_accepted_at'], true)) ?> via JOB NIGER.<?php endif; ?></p>
<p class="small text-muted">Empreinte d'intégrité (SHA-256) : <?= e($e['content_hash']) ?><br>
  Document généré par JOB NIGER, plateforme de mise en relation. Il ne remplace pas les obligations légales de l'employeur (Code du travail du Niger).</p>
