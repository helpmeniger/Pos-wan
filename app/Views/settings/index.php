<div class="row g-3">
  <div class="col-xl-7">
    <form method="post" action="<?= e(url('/settings')) ?>"><?= csrf_field() ?>
    <div class="card mb-3"><div class="card-header"><i class="bi bi-building"></i> Entreprise, documents et règles de gestion</div><div class="card-body row g-3">
      <?php foreach ($fields as $key => $f): [$label, $type] = $f; $val = (string) setting($key, ''); ?>
        <?php if ($type === 'bool'): ?>
          <div class="col-md-6"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="<?= e($key) ?>" value="1" id="s_<?= e($key) ?>" <?= $val === '1' ? 'checked' : '' ?>><label class="form-check-label" for="s_<?= e($key) ?>"><?= e($label) ?></label></div></div>
        <?php elseif ($type === 'textarea'): ?>
          <div class="col-md-6"><label class="form-label"><?= e($label) ?></label><textarea name="<?= e($key) ?>" class="form-control" rows="2" maxlength="1000"><?= e($val) ?></textarea></div>
        <?php elseif ($type === 'select'): ?>
          <div class="col-md-6"><label class="form-label"><?= e($label) ?></label><select name="<?= e($key) ?>" class="form-select"><?php foreach ($f[2] as $k => $l): ?><option value="<?= e($k) ?>" <?= $val === (string) $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <?php else: ?>
          <div class="col-md-6"><label class="form-label"><?= e($label) ?></label><input type="<?= $type === 'number' ? 'number' : 'text' ?>" step="any" min="0" name="<?= e($key) ?>" value="<?= e($val) ?>" class="form-control" <?= $key === 'company_name' ? 'required' : '' ?>></div>
        <?php endif; ?>
      <?php endforeach; ?>
      <div class="col-12"><label class="form-label">Modes de paiement acceptés</label><div><?php $en = explode(',', (string) setting('payment_methods', 'cash')); foreach ($methods as $k => $l): ?><div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="payment_methods[]" value="<?= e($k) ?>" id="pm_<?= e($k) ?>" <?= in_array($k, $en, true) ? 'checked' : '' ?>><label class="form-check-label" for="pm_<?= e($k) ?>"><?= e($l) ?></label></div><?php endforeach; ?></div>
        <div class="form-text">Les paiements par carte et mobile money sont enregistrés avec leur référence de transaction ; aucune intégration directe avec un opérateur n'est réalisée (encaissement sur le terminal de l'opérateur).</div></div>
      <div class="col-12"><label class="form-label">Raccourcis clavier de la caisse</label><div class="row g-2"><?php $scl = ['search' => 'Recherche', 'customer' => 'Client', 'quantity' => 'Quantité', 'discount' => 'Remise', 'hold' => 'Attente', 'pay' => 'Encaisser', 'cashExact' => 'Espèces exact', 'clear' => 'Vider']; foreach ($scl as $k => $l): ?><div class="col-3"><div class="input-group input-group-sm"><span class="input-group-text"><?= e($l) ?></span><input name="sc_<?= e($k) ?>" value="<?= e($shortcuts[$k] ?? '') ?>" class="form-control" maxlength="3"></div></div><?php endforeach; ?></div></div>
    </div><div class="card-footer"><button class="btn btn-primary"><i class="bi bi-check2"></i> Enregistrer</button></div></div>
    </form>
  </div>
  <div class="col-xl-5">
    <div class="card mb-3"><div class="card-header"><i class="bi bi-image"></i> Logo (tickets, factures)</div><div class="card-body">
      <?php if ($l = setting('company_logo')): ?><img src="<?= e(upload_url($l)) ?>" alt="" class="mb-2" style="max-height:80px"><?php endif; ?>
      <form method="post" enctype="multipart/form-data" action="<?= e(url('/settings/logo')) ?>" class="d-flex gap-2"><?= csrf_field() ?><input type="file" name="logo" accept="image/png,image/jpeg,image/webp" class="form-control form-control-sm" required><button class="btn btn-sm btn-outline-primary">Envoyer</button></form>
    </div></div>
    <div class="card mb-3"><div class="card-header"><i class="bi bi-shop"></i> Type d'activité et modules</div><div class="card-body">
      <form method="post" action="<?= e(url('/settings/business')) ?>"><?= csrf_field() ?>
        <select name="business_type" class="form-select mb-2"><?php foreach ($types as $k => [$l]): ?><option value="<?= e($k) ?>" <?= setting('business_type') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
        <?php foreach ($features as $k => $l): ?><div class="form-check"><input class="form-check-input" type="checkbox" name="features[]" value="<?= e($k) ?>" id="f_<?= e($k) ?>" <?= feature($k) ? 'checked' : '' ?>><label class="form-check-label" for="f_<?= e($k) ?>"><?= e($l) ?></label></div><?php endforeach; ?>
        <div class="form-check mt-2"><input class="form-check-input" type="checkbox" name="apply_defaults" value="1" id="ad"><label class="form-check-label small" for="ad">Appliquer les modules recommandés pour ce type (ignore les cases ci-dessus)</label></div>
        <button class="btn btn-outline-primary mt-2">Mettre à jour</button>
      </form>
    </div></div>
    <?php if (setting('demo_data') === '1'): ?>
    <div class="card border-warning"><div class="card-header text-warning"><i class="bi bi-cone-striped"></i> Données de démonstration</div><div class="card-body">
      <p class="small">Archive les articles, clients et fournisseurs « [DÉMO] » (l'historique des ventes de test est conservé).</p>
      <form method="post" action="<?= e(url('/settings/demo/archive')) ?>" data-confirm="Archiver toutes les données de démonstration ?"><?= csrf_field() ?><button class="btn btn-warning">Archiver les données de démonstration</button></form>
    </div></div>
    <?php endif; ?>
  </div>
</div>
