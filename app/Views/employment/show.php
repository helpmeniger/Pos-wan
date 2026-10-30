<?php $this->layout('layouts/app'); use App\Services\Employment\EmploymentService; use App\Services\Employment\PayslipService; use App\Support\Phone; ?>
<div class="container py-4" style="max-width:960px">
  <nav aria-label="Fil d'Ariane"><ol class="breadcrumb small"><li class="breadcrumb-item"><a href="<?= e(url('/emplois')) ?>">Emplois</a></li><li class="breadcrumb-item active" aria-current="page"><?= e($e['reference']) ?></li></ol></nav>
  <div class="d-flex justify-content-between flex-wrap gap-2 mb-3">
    <div><h1 class="h4 mb-1"><?= e($e['position_title']) ?></h1>
      <div class="small text-muted"><?= $isEmployer ? 'Employé(e) : ' . e($e['employee_name']) . ' · ' . e(Phone::format($e['employee_phone'])) : 'Employeur : ' . e($e['business_name'] ?: $e['employer_name']) . ' · ' . e(Phone::format($e['employer_phone'])) ?></div></div>
    <div class="d-flex gap-2 align-items-start"><?= badge(EmploymentService::STATUS_LABELS[$e['status']] ?? $e['status'], status_tone($e['status'])) ?>
      <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/emplois/' . $e['uuid'] . '/imprimer')) ?>"><i class="bi bi-printer" aria-hidden="true"></i> Imprimer / PDF</a></div>
  </div>

  <div class="jn-card p-3 mb-3">
    <h2 class="jn-section-title">Contrat — clauses</h2>
    <?= $this->partial('employment/terms', ['terms' => $terms, 'e' => $e]) ?>
    <div class="small text-muted mt-3">
      <?php if ($e['employee_accepted_at']): ?><i class="bi bi-lock" aria-hidden="true"></i> Accepté par l'employé(e) le <?= e(date_fr($e['employee_accepted_at'], true)) ?> — clauses figées (empreinte <?= e(substr($e['content_hash'], 0, 16)) ?>…).<?php endif; ?>
      <?php if ($e['ended_at'] && in_array($e['status'], ['TERMINE', 'REFUSE', 'ANNULE'], true)): ?><br>Fin : <?= e(date_fr($e['end_date'] ?? $e['ended_at'])) ?><?= $e['end_reason'] ? ' — ' . e($e['end_reason']) : '' ?><?php endif; ?>
    </div>
  </div>

  <?php if (!$isEmployer && $e['status'] === 'EN_ATTENTE_ACCEPTATION'): ?>
    <div class="jn-card p-3 mb-3 border-success">
      <h2 class="jn-section-title">Votre réponse</h2>
      <form method="post" action="<?= e(url('/emplois/' . $e['uuid'] . '/accepter')) ?>" class="mb-3">
        <?= csrf_field() ?>
        <div class="form-check mb-2"><input class="form-check-input" type="checkbox" value="1" id="confirm" name="confirm" required>
          <label class="form-check-label" for="confirm">J'ai lu les clauses ci-dessus et j'accepte ce contrat.</label></div>
        <button class="btn btn-success" type="submit"><i class="bi bi-check2-circle" aria-hidden="true"></i> Accepter le contrat</button>
      </form>
      <form method="post" action="<?= e(url('/emplois/' . $e['uuid'] . '/refuser')) ?>" class="d-flex flex-wrap gap-2" data-confirm="Refuser ce contrat ?">
        <?= csrf_field() ?><input class="form-control form-control-sm" style="max-width:320px" name="reason" maxlength="500" placeholder="Motif (facultatif)">
        <button class="btn btn-sm btn-outline-danger" type="submit">Refuser</button>
      </form>
    </div>
  <?php endif; ?>

  <h2 class="h5">Fiches de paie</h2>
  <?php if (!$payslips): ?>
    <div class="jn-card p-3 text-muted small mb-3"><?= $e['status'] === 'ACTIF' ? 'La première fiche de paie sera générée automatiquement ' . ($e['pay_period'] === 'MENSUEL' ? 'à la fin du mois.' : 'à la fin de la prestation.') : 'Aucune fiche de paie.' ?></div>
  <?php else: ?>
    <div class="jn-card table-responsive mb-3"><table class="table table-jn align-middle mb-0">
      <thead><tr><th>Période</th><th class="text-end">Brut</th><th class="text-end">Commission</th><th class="text-end">Net</th><th>Statut</th><th></th></tr></thead>
      <tbody><?php foreach ($payslips as $p): ?><tr>
        <td><?= e($p['period_label']) ?><div class="small text-muted"><?= e($p['number']) ?></div></td>
        <td class="text-end jn-amount"><?= e(money((string) $p['gross_amount'])) ?></td><td class="text-end jn-amount"><?= e(money((string) $p['commission_amount'])) ?></td>
        <td class="text-end jn-amount fw-semibold"><?= e(money((string) $p['net_amount'])) ?></td>
        <td><?= badge(PayslipService::LABELS[$p['status']] ?? $p['status'], status_tone($p['status'])) ?></td>
        <td><a class="btn btn-sm btn-outline-primary" href="<?= e(url('/fiches-de-paie/' . $p['uuid'])) ?>">Ouvrir</a></td></tr><?php endforeach; ?></tbody></table></div>
  <?php endif; ?>

  <?php if ($e['status'] === 'ACTIF' || ($isEmployer && $e['status'] === 'EN_ATTENTE_ACCEPTATION')): ?>
    <details class="jn-card p-3"><summary class="fw-semibold"><?= $e['status'] === 'ACTIF' ? 'Mettre fin au contrat' : 'Retirer la proposition' ?></summary>
      <form method="post" action="<?= e(url('/emplois/' . $e['uuid'] . '/terminer')) ?>" class="row g-2 mt-2" data-confirm="Confirmer la fin du contrat ?">
        <?= csrf_field() ?>
        <div class="col-md-4"><label class="form-label small" for="end_date">Dernier jour travaillé</label><input class="<?= e(field_class('end_date')) ?>" type="date" id="end_date" name="end_date" value="<?= e(old('end_date', date('Y-m-d'))) ?>" required><?= $this->partial('partials/field-error', ['name' => 'end_date']) ?></div>
        <div class="col-md-8"><label class="form-label small" for="reason">Motif</label><input class="<?= e(field_class('reason')) ?>" id="reason" name="reason" maxlength="500" required value="<?= e(old('reason')) ?>"><?= $this->partial('partials/field-error', ['name' => 'reason']) ?></div>
        <div class="col-12"><button class="btn btn-outline-danger" type="submit">Confirmer</button>
          <?php if ($e['status'] === 'ACTIF'): ?><span class="small text-muted ms-2">La dernière fiche de paie sera calculée au prorata.</span><?php endif; ?></div>
      </form>
    </details>
  <?php endif; ?>
</div>
