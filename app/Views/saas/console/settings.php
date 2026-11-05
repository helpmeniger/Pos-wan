<h1 class="mb-3">Paramètres du service</h1>
<form method="post" action="<?= e(url('/console/parametres')) ?>" class="panel"><?= csrf_field() ?>
  <div class="row g-3">
  <?php foreach ($labels as $k => $label): $long = in_array($k, ['payment_instructions', 'invoice_note'], true); ?>
    <div class="<?= $long ? 'col-12' : 'col-md-6' ?>"><label class="form-label small fw-semibold"><?= e($label) ?></label>
      <?php if ($long): ?><textarea name="<?= e($k) ?>" class="form-control" rows="3"><?= e($v[$k]) ?></textarea>
      <?php else: ?><input name="<?= e($k) ?>" class="form-control" value="<?= e($v[$k]) ?>" <?= $k === 'trial_days' ? 'type="number" min="1" max="90"' : '' ?>><?php endif; ?></div>
  <?php endforeach; ?>
  </div>
  <button class="btn btn-primary mt-3">Enregistrer</button>
</form>
