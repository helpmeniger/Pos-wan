<?php $v = fn($k, $d = '') => e(App\Core\Session::old($k, (string) ($c[$k] ?? $d))); ?>
<div class="card" style="max-width:820px"><div class="card-body">
<form method="post" action="<?= e(url(!empty($c['id']) ? '/customers/' . $c['id'] : '/customers')) ?>" class="row g-3"><?= csrf_field() ?>
  <div class="col-md-8"><label class="form-label required">Nom</label><input name="name" value="<?= $v('name') ?>" class="form-control" required maxlength="150"></div>
  <div class="col-md-4"><label class="form-label">Téléphone</label><input name="phone" value="<?= $v('phone') ?>" class="form-control" maxlength="30" placeholder="+227 …"></div>
  <div class="col-md-6"><label class="form-label">E-mail</label><input type="email" name="email" value="<?= $v('email') ?>" class="form-control" maxlength="150"></div>
  <div class="col-md-6"><label class="form-label">NIF (clients professionnels)</label><input name="nif" value="<?= $v('nif') ?>" class="form-control" maxlength="50"></div>
  <div class="col-12"><label class="form-label">Adresse</label><input name="address" value="<?= $v('address') ?>" class="form-control" maxlength="255"></div>
  <?php if (can('pos.credit_sale')): ?><div class="col-md-4"><label class="form-label">Plafond de crédit (F)</label><input type="number" step="any" min="0" name="credit_limit" value="<?= $v('credit_limit', '0') ?>" class="form-control"><div class="form-text">0 = aucun crédit sans autorisation d'un responsable.</div></div><?php endif; ?>
  <div class="col-12"><label class="form-label">Notes</label><textarea name="notes" class="form-control" rows="2" maxlength="2000"><?= $v('notes') ?></textarea></div>
  <?php if (!empty($c['id'])): ?><div class="col-12 form-check ms-2"><input type="checkbox" class="form-check-input" name="archive" value="1" id="ar"><label class="form-check-label" for="ar">Archiver ce client (solde nul requis)</label></div><?php endif; ?>
  <div class="col-12"><button class="btn btn-primary">Enregistrer</button> <a href="<?= e(url('/customers')) ?>" class="btn btn-light">Annuler</a></div>
  <div class="col-12 small text-secondary"><i class="bi bi-shield-lock"></i> Données personnelles : accès réservé aux utilisateurs autorisés ; ne collectez que le nécessaire.</div>
</form></div></div>
