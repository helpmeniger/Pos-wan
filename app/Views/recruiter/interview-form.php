<?php $this->layout('layouts/app'); ?>
<div class="container py-4" style="max-width:680px">
  <h1 class="h4">Proposer un entretien</h1>
  <p class="text-muted">Candidat : <strong><?= e($candidate['first_name'] . ' ' . $candidate['last_name']) ?></strong> — <?= e($application['post_title']) ?></p>
  <form method="post" action="<?= e(url('/recruteur/candidatures/' . $application['uuid'] . '/entretien')) ?>" class="jn-card p-4" novalidate data-toggle-group="mode">
    <?= csrf_field() ?>
    <fieldset class="mb-3"><legend class="form-label fs-6">Type d'entretien</legend>
      <div class="d-flex gap-3">
        <label class="account-type-option flex-fill"><input class="form-check-input me-1" type="radio" name="mode" value="EN_LIGNE"<?= old('mode', 'EN_LIGNE') === 'EN_LIGNE' ? ' checked' : '' ?>> <i class="bi bi-camera-video" aria-hidden="true"></i> En ligne</label>
        <label class="account-type-option flex-fill"><input class="form-check-input me-1" type="radio" name="mode" value="PRESENTIEL"<?= old('mode') === 'PRESENTIEL' ? ' checked' : '' ?>> <i class="bi bi-geo-alt" aria-hidden="true"></i> En présentiel</label>
      </div>
    </fieldset>
    <div class="row g-3">
      <div class="col-6"><label class="form-label" for="date">Date</label><input class="<?= e(field_class('date')) ?>" type="date" id="date" name="date" min="<?= e(date('Y-m-d')) ?>" value="<?= e(old('date', date('Y-m-d', strtotime('+1 day')))) ?>" required><?= $this->partial('partials/field-error', ['name' => 'date']) ?></div>
      <div class="col-6"><label class="form-label" for="time">Heure</label><input class="form-control" type="time" id="time" name="time" value="<?= e(old('time', '10:00')) ?>" required></div>
      <div class="col-6"><label class="form-label" for="duration">Durée (minutes)</label><input class="form-control" type="number" id="duration" name="duration" min="10" max="240" value="<?= e(old('duration', '30')) ?>"></div>
      <div class="col-12" data-when="EN_LIGNE"><label class="form-label" for="meeting_link">Lien de la réunion</label>
        <input class="<?= e(field_class('meeting_link')) ?>" id="meeting_link" name="meeting_link" type="url" value="<?= e(old('meeting_link')) ?>" placeholder="https://meet.google.com/… ou lien d'appel vidéo WhatsApp">
        <?= $this->partial('partials/field-error', ['name' => 'meeting_link']) ?></div>
      <div class="col-12" data-when="PRESENTIEL"><label class="form-label" for="location">Adresse du rendez-vous</label>
        <input class="<?= e(field_class('location')) ?>" id="location" name="location" maxlength="255" value="<?= e(old('location')) ?>" placeholder="Ex : Boutique X, rue de la Corniche, Plateau, Niamey">
        <?= $this->partial('partials/field-error', ['name' => 'location']) ?></div>
      <div class="col-12"><label class="form-label" for="notes">Message au candidat (facultatif)</label>
        <textarea class="form-control" id="notes" name="notes" rows="2" maxlength="1000" placeholder="Ex : apportez une pièce d'identité"><?= e(old('notes')) ?></textarea></div>
    </div>
    <button type="submit" class="btn btn-primary mt-3" data-loading="Envoi…">Envoyer la proposition</button>
  </form>
</div>
