<?php $this->layout('layouts/app');
$categories = ['COMPTE' => 'Mon compte', 'MISSION' => 'Une mission', 'PAIEMENT' => 'Un paiement', 'VERSEMENT' => 'Un versement', 'TECHNIQUE' => 'Problème technique', 'SECURITE' => 'Sécurité / signalement urgent', 'AUTRE' => 'Autre'];
$defaultName = $user ? $user['first_name'] . ' ' . $user['last_name'] : '';
?>
<div class="container py-4 jn-auth-wide">
  <h1 class="h3">Contacter l'équipe JOB NIGER</h1>
  <p class="text-muted">Votre message crée un ticket de support. Nous vous répondons par téléphone ou par e-mail.</p>
  <form method="post" action="<?= e(url('/contact')) ?>" class="jn-card p-4" novalidate>
    <?= csrf_field() ?>
    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label" for="name">Nom complet</label>
        <input class="<?= e(field_class('name')) ?>" id="name" name="name" value="<?= e(old('name', $defaultName)) ?>" required maxlength="160" autocomplete="name">
        <?= $this->partial('partials/field-error', ['name' => 'name']) ?>
      </div>
      <div class="col-md-6">
        <label class="form-label" for="category">Sujet</label>
        <select class="<?= e(field_class('category', 'form-select')) ?>" id="category" name="category" required>
          <?php foreach ($categories as $k => $v): ?><option value="<?= e($k) ?>"<?= old('category') === $k ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?>
        </select>
        <?= $this->partial('partials/field-error', ['name' => 'category']) ?>
      </div>
      <div class="col-md-6">
        <label class="form-label" for="phone">Téléphone <span class="text-muted small">(facultatif)</span></label>
        <input class="<?= e(field_class('phone')) ?>" id="phone" name="phone" type="tel" inputmode="tel" placeholder="90 12 34 56" value="<?= e(old('phone', $user['phone'] ?? '')) ?>" autocomplete="tel">
        <?= $this->partial('partials/field-error', ['name' => 'phone']) ?>
      </div>
      <div class="col-md-6">
        <label class="form-label" for="email">E-mail <span class="text-muted small">(facultatif)</span></label>
        <input class="<?= e(field_class('email')) ?>" id="email" name="email" type="email" value="<?= e(old('email', $user['email'] ?? '')) ?>" autocomplete="email">
        <?= $this->partial('partials/field-error', ['name' => 'email']) ?>
      </div>
      <div class="col-12">
        <label class="form-label" for="subject">Objet</label>
        <input class="<?= e(field_class('subject')) ?>" id="subject" name="subject" value="<?= e(old('subject')) ?>" required maxlength="200">
        <?= $this->partial('partials/field-error', ['name' => 'subject']) ?>
      </div>
      <div class="col-12">
        <label class="form-label" for="message">Message</label>
        <textarea class="<?= e(field_class('message')) ?>" id="message" name="message" rows="6" required maxlength="4000"><?= e(old('message')) ?></textarea>
        <?= $this->partial('partials/field-error', ['name' => 'message']) ?>
        <div class="form-text">Ne communiquez jamais de mot de passe ni de code secret Mobile Money.</div>
      </div>
    </div>
    <button type="submit" class="btn btn-primary mt-3" data-loading="Envoi…"><i class="bi bi-send" aria-hidden="true"></i> Envoyer</button>
  </form>
</div>
