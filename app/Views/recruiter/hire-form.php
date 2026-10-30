<?php $this->layout('layouts/app');
$isMonthly = ($post['payment_terms'] ?? '') === 'Salaire mensuel';
$f = function (string $name, string $label, string $type = 'text', string $attrs = '', string $default = '', string $help = ''): string {
    return '<label class="form-label" for="' . e($name) . '">' . e($label) . '</label><input class="' . e(field_class($name)) . '" id="' . e($name)
        . '" name="' . e($name) . '" type="' . e($type) . '" value="' . e(old($name, $default)) . '" ' . $attrs . '>'
        . (error_for($name) ? '<div class="invalid-feedback">' . e(error_for($name)) . '</div>' : '') . ($help ? '<div class="form-text">' . $help . '</div>' : '');
};
?>
<div class="container py-4" style="max-width:820px">
  <h1 class="h4">Embaucher <?= e($candidate['first_name'] . ' ' . $candidate['last_name']) ?></h1>
  <p class="text-muted">Le contrat est envoyé au candidat, qui doit l'accepter dans l'application. Une fois accepté, ses clauses sont figées
    et archivées pour vous deux ; les fiches de paie sont ensuite générées automatiquement.</p>
  <form method="post" action="<?= e(url('/recruteur/candidatures/' . $application['uuid'] . '/embaucher')) ?>" class="jn-card p-4" novalidate data-toggle-group="pay_period">
    <?= csrf_field() ?>
    <h2 class="jn-section-title">Emploi</h2>
    <div class="row g-3 mb-3">
      <div class="col-12"><?= $f('position_title', 'Emploi / poste occupé', 'text', 'required maxlength="160"', (string) $post['title']) ?></div>
      <div class="col-12"><label class="form-label" for="duties">Tâches et clauses particulières</label>
        <textarea class="form-control" id="duties" name="duties" rows="4" maxlength="5000" placeholder="Missions confiées, tenue, période d'essai, préavis, avantages (repas, transport)…"><?= e(old('duties')) ?></textarea></div>
      <div class="col-12"><?= $f('work_location', 'Lieu de travail', 'text', 'maxlength="255"', (string) ($post['address_private'] ?? $post['city'] ?? '')) ?></div>
    </div>
    <h2 class="jn-section-title">Temps de travail</h2>
    <div class="row g-3 mb-3">
      <div class="col-md-6"><?= $f('work_days', 'Jours de travail', 'text', 'required maxlength="120"', (string) ($post['schedule_notes'] ?: 'Du lundi au samedi')) ?></div>
      <div class="col-6 col-md-3"><?= $f('daily_start_time', 'Début', 'time', '', time_fr($post['daily_start_time'] ?? '08:00') ?: '08:00') ?></div>
      <div class="col-6 col-md-3"><?= $f('daily_end_time', 'Fin', 'time', '', time_fr($post['daily_end_time'] ?? '17:00') ?: '17:00') ?></div>
      <div class="col-md-4"><?= $f('hours_per_week', 'Heures par semaine', 'number', 'required min="1" max="84" step="0.5"', '40') ?></div>
    </div>
    <h2 class="jn-section-title">Salaire et paiement</h2>
    <div class="row g-3 mb-3">
      <div class="col-md-6">
        <label class="form-label" for="pay_period">Type de rémunération</label>
        <select class="form-select" id="pay_period" name="pay_period">
          <option value="MENSUEL"<?= old('pay_period', $isMonthly ? 'MENSUEL' : 'PRESTATION') === 'MENSUEL' ? ' selected' : '' ?>>Salaire mensuel — fiche de paie chaque fin de mois</option>
          <option value="PRESTATION"<?= old('pay_period', $isMonthly ? 'MENSUEL' : 'PRESTATION') === 'PRESTATION' ? ' selected' : '' ?>>Prestation — fiche de paie à la fin de la prestation</option>
        </select>
      </div>
      <div class="col-md-6"><?= $f('salary_amount', 'Salaire mensuel ou montant de la prestation (FCFA)', 'text', 'required inputmode="numeric"', (string) (int) $post['pay_rate']) ?></div>
      <div class="col-md-6">
        <label class="form-label" for="payment_method">Mode de paiement habituel</label>
        <select class="form-select" id="payment_method" name="payment_method">
          <option value="MOBILE_MONEY"<?= old('payment_method') === 'ESPECES' ? '' : ' selected' ?>>Mobile Money</option>
          <option value="ESPECES"<?= old('payment_method') === 'ESPECES' ? ' selected' : '' ?>>En main propre (espèces)</option>
        </select>
      </div>
      <div class="col-6 col-md-3"><?= $f('start_date', 'Date de début', 'date', 'required', (string) max($post['start_date'], date('Y-m-d'))) ?></div>
      <div class="col-6 col-md-3"><?= $f('end_date', 'Date de fin', 'date', '', '', 'Obligatoire pour une prestation') ?></div>
    </div>
    <div class="alert alert-light border small">
      <i class="bi bi-info-circle" aria-hidden="true"></i>
      <?php if ((float) $commissionRate > 0): ?>
        Commission JOB NIGER : <strong><?= e(rtrim(rtrim($commissionRate, '0'), '.')) ?> %</strong> du salaire brut, déduite sur chaque fiche de paie.
        L'employé reçoit le net ; vous réglez la commission à JOB NIGER. Exemple pour 100 000 FCFA : net employé
        <?= e(money(100000 - App\Support\Money::xof(100000)->percentBps(App\Support\Money::rateToBps($commissionRate))->amount())) ?>.
      <?php else: ?>
        Aucune commission n'est appliquée sur les fiches de paie.
      <?php endif; ?>
      Le salaire est versé directement par vous (Mobile Money ou espèces) : l'employé confirme la réception dans l'application.
    </div>
    <button type="submit" class="btn btn-primary btn-lg" data-loading="Envoi…">Envoyer le contrat au candidat</button>
  </form>
</div>
