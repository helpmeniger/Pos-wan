<?php $this->layout('layouts/app'); use App\Services\Jobs\JobPostService; ?>
<div class="container py-4" style="max-width:900px">
  <nav aria-label="Fil d'Ariane"><ol class="breadcrumb small">
    <li class="breadcrumb-item"><a href="<?= e(url('/offres')) ?>">Offres</a></li>
    <li class="breadcrumb-item active" aria-current="page"><?= e($post['reference']) ?></li>
  </ol></nav>
  <div class="row g-3">
    <div class="col-lg-8">
      <div class="jn-card p-4">
        <h1 class="h4"><?= e($post['title']) ?></h1>
        <div class="text-muted mb-3">
          <?= e($post['recruiter']) ?>
          <?php if ($post['recruiter_verified']): ?><span class="badge bg-primary-soft text-primary"><i class="bi bi-patch-check-fill" aria-hidden="true"></i> Recruteur vérifié</span><?php endif; ?>
        </div>
        <dl class="row small mb-3">
          <dt class="col-5 col-sm-4">Rémunération</dt><dd class="col-7 col-sm-8 fw-bold text-primary"><?= e(JobPostService::payLabel($post)) ?></dd>
          <dt class="col-5 col-sm-4">Type</dt><dd class="col-7 col-sm-8"><?= e($post['contract_type']) ?> · <?= e($post['trade']) ?></dd>
          <dt class="col-5 col-sm-4">Lieu</dt><dd class="col-7 col-sm-8"><?= e($post['city'] ?? 'À distance') ?><?= $post['neighborhood'] ? ', ' . e($post['neighborhood']) : '' ?></dd>
          <dt class="col-5 col-sm-4">Dates</dt><dd class="col-7 col-sm-8">Du <?= e(date_fr($post['start_date'])) ?> au <?= e(date_fr($post['end_date'])) ?></dd>
          <?php if ($post['daily_start_time']): ?><dt class="col-5 col-sm-4">Horaires</dt><dd class="col-7 col-sm-8"><?= e(time_fr($post['daily_start_time'])) ?> – <?= e(time_fr($post['daily_end_time'])) ?></dd><?php endif; ?>
          <?php if ($post['schedule_notes']): ?><dt class="col-5 col-sm-4">Organisation</dt><dd class="col-7 col-sm-8"><?= e($post['schedule_notes']) ?></dd><?php endif; ?>
          <dt class="col-5 col-sm-4">Postes</dt><dd class="col-7 col-sm-8"><?= (int) $post['positions_total'] - (int) $post['positions_filled'] ?> disponible(s) sur <?= (int) $post['positions_total'] ?></dd>
          <dt class="col-5 col-sm-4">Candidater avant le</dt><dd class="col-7 col-sm-8"><?= e(date_fr($post['application_deadline'])) ?></dd>
        </dl>
        <h2 class="h6">Description</h2>
        <div style="white-space:pre-line"><?= e($post['description']) ?></div>
      </div>
    </div>
    <div class="col-lg-4">
      <div class="jn-card p-3">
        <?php if ($isOwner): ?>
          <p class="small mb-2">C'est votre offre.</p>
          <a class="btn btn-primary w-100" href="<?= e(url('/recruteur/offres/' . $post['uuid'])) ?>">Voir les candidatures</a>
        <?php elseif ($applied): ?>
          <div class="alert alert-success small mb-2"><i class="bi bi-check-circle" aria-hidden="true"></i> Vous avez postulé.</div>
          <a class="btn btn-outline-primary w-100" href="<?= e(url('/candidatures')) ?>">Suivre ma candidature</a>
        <?php elseif (!$open): ?>
          <div class="alert alert-secondary small mb-0">Cette offre n'accepte plus de candidatures.</div>
        <?php elseif ($user === null): ?>
          <p class="small">Créez votre compte en 1 minute pour postuler.</p>
          <a class="btn btn-primary w-100 mb-2" href="<?= e(url('/inscription?type=worker')) ?>">Créer mon compte</a>
          <a class="btn btn-outline-primary w-100" href="<?= e(url('/connexion')) ?>">J'ai déjà un compte</a>
        <?php elseif (!$isWorker): ?>
          <p class="small text-muted mb-0">Votre compte est un compte recruteur : pour postuler, utilisez un compte « Je cherche du travail ».</p>
        <?php else: ?>
          <form method="post" action="<?= e(url('/offres/' . $post['uuid'] . '/postuler')) ?>" novalidate>
            <?= csrf_field() ?>
            <?php if ($needsBirthDate): ?>
              <div class="mb-2">
                <label class="form-label small" for="birth_date">Votre date de naissance</label>
                <input class="<?= e(field_class('birth_date')) ?>" type="date" id="birth_date" name="birth_date" max="<?= e(date('Y-m-d')) ?>" value="<?= e(old('birth_date')) ?>" required>
                <div class="form-text">Demandée une seule fois, jamais affichée.</div>
                <?= $this->partial('partials/field-error', ['name' => 'birth_date']) ?>
              </div>
            <?php endif; ?>
            <div class="mb-2">
              <label class="form-label small" for="message">Message au recruteur (facultatif)</label>
              <textarea class="<?= e(field_class('message')) ?>" id="message" name="message" rows="3" maxlength="2000" placeholder="Ex : J'ai 2 ans d'expérience, disponible tout de suite."><?= e(old('message')) ?></textarea>
              <?= $this->partial('partials/field-error', ['name' => 'message']) ?>
            </div>
            <button type="submit" class="btn btn-primary btn-lg w-100" data-loading="Envoi…"><i class="bi bi-send" aria-hidden="true"></i> Postuler</button>
          </form>
        <?php endif; ?>
      </div>
      <div class="small text-muted mt-2 px-1"><i class="bi bi-shield-check" aria-hidden="true"></i> Ne payez jamais pour postuler : signalez toute demande d'argent.</div>
    </div>
  </div>
</div>
