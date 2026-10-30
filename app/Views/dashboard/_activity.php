<?php use App\Services\Employment\EmploymentService; use App\Services\Employment\PayslipService; use App\Services\Jobs\InterviewService; ?>
<?php if ($interviews): ?>
<div class="jn-card p-3 mb-3">
  <div class="jn-section-title"><i class="bi bi-calendar-event text-primary" aria-hidden="true"></i> Entretiens à venir</div>
  <?php foreach ($interviews as $i): ?>
    <div class="d-flex justify-content-between flex-wrap gap-2 border-bottom py-2 small">
      <span><strong><?= e(date_fr($i['scheduled_at'], true)) ?></strong> — <?= e($i['title']) ?><?= $i['as_recruiter'] ? ' avec ' . e($i['candidate_name']) : '' ?>
        (<?= $i['mode'] === 'EN_LIGNE' ? 'en ligne' : 'présentiel : ' . e($i['location']) ?>)</span>
      <span><?= badge(InterviewService::LABELS[$i['status']] ?? $i['status'], status_tone($i['status'])) ?>
        <?php if ($i['mode'] === 'EN_LIGNE'): ?><a class="btn btn-sm btn-outline-primary py-0" href="<?= e($i['meeting_link']) ?>" target="_blank" rel="noopener noreferrer">Rejoindre</a><?php endif; ?>
        <a class="btn btn-sm btn-link py-0" href="<?= e(url($i['as_recruiter'] ? '/recruteur/offres/' . $i['post_uuid'] : '/candidatures')) ?>">Détails</a></span>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<?php if ($employments): ?>
<div class="jn-card p-3 mb-3">
  <div class="jn-section-title"><i class="bi bi-briefcase text-primary" aria-hidden="true"></i> Contrats en cours</div>
  <?php foreach ($employments as $e): ?>
    <div class="d-flex justify-content-between flex-wrap gap-2 border-bottom py-2 small">
      <a href="<?= e(url('/emplois/' . $e['uuid'])) ?>"><?= e($e['position_title']) ?> — <?= $e['as_employer'] ? e($e['employee_name']) : e($e['business_name'] ?: $e['employer_name']) ?></a>
      <?= badge(EmploymentService::STATUS_LABELS[$e['status']] ?? $e['status'], status_tone($e['status'])) ?>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<?php if ($payslips): ?>
<div class="jn-card p-3 mb-3">
  <div class="jn-section-title"><i class="bi bi-receipt text-primary" aria-hidden="true"></i> Fiches de paie à traiter</div>
  <?php foreach ($payslips as $p): ?>
    <div class="d-flex justify-content-between flex-wrap gap-2 border-bottom py-2 small">
      <a href="<?= e(url('/fiches-de-paie/' . $p['uuid'])) ?>"><?= e($p['period_label']) ?> — <?= e($p['position_title']) ?> — <strong class="jn-amount"><?= e(money((string) $p['net_amount'])) ?></strong></a>
      <?= badge(PayslipService::LABELS[$p['status']] ?? $p['status'], status_tone($p['status'])) ?>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
