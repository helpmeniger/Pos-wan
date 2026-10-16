<?php /** @var string $name @var string $label @var string $autocomplete @var bool $strength */ ?>
<div class="mb-3">
  <label for="<?= e($name) ?>" class="form-label"><?= e($label) ?></label>
  <div class="input-group has-validation">
    <input type="password" class="<?= e(field_class($name)) ?>" id="<?= e($name) ?>" name="<?= e($name) ?>"
           autocomplete="<?= e($autocomplete ?? 'current-password') ?>" required maxlength="128"<?= !empty($strength) ? ' data-strength' : '' ?>>
    <button class="btn btn-outline-secondary password-toggle" type="button" data-toggle-password="<?= e($name) ?>" aria-label="Afficher le mot de passe" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button>
    <?php if (error_for($name)): ?><div class="invalid-feedback"><?= e(error_for($name)) ?></div><?php endif; ?>
  </div>
  <?php if (!empty($strength)): ?><div class="form-text">Au moins 8 caractères, avec au moins une lettre et un chiffre. <span id="password-strength" aria-live="polite"></span></div><?php endif; ?>
</div>
