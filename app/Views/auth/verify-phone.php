<?php $this->layout('layouts/app'); use App\Support\Phone; ?>
<div class="container py-5 jn-auth">
  <h1 class="h3 mb-1">Vérifier mon téléphone</h1>
  <p class="text-muted">Numéro : <strong><?= e(Phone::format($user['phone'])) ?></strong></p>
  <?php if ($user['phone_verified_at'] !== null): ?>
    <div class="alert alert-success"><i class="bi bi-patch-check" aria-hidden="true"></i> Votre numéro a été vérifié le <?= e(date_fr($user['phone_verified_at'], true)) ?>.</div>
  <?php elseif (!$enabled): ?>
    <div class="alert alert-info">La vérification par SMS sera activée dès qu'un fournisseur SMS sera configuré. En attendant, un modérateur peut vérifier votre numéro manuellement.</div>
  <?php else: ?>
    <div class="jn-card p-4">
      <form method="post" action="<?= e(url('/verifier-telephone/envoyer')) ?>" class="mb-4">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-outline-primary" data-loading="Envoi…"><i class="bi bi-chat-square-text" aria-hidden="true"></i> Recevoir un code par SMS</button>
      </form>
      <form method="post" action="<?= e(url('/verifier-telephone')) ?>" novalidate>
        <?= csrf_field() ?>
        <label for="code" class="form-label">Code reçu (6 chiffres)</label>
        <input class="form-control mb-3" id="code" name="code" inputmode="numeric" pattern="\d{6}" maxlength="6" autocomplete="one-time-code" required>
        <button type="submit" class="btn btn-primary">Valider</button>
      </form>
    </div>
  <?php endif; ?>
</div>
