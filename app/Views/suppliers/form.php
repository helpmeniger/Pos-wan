<?php $v = fn($k) => e(App\Core\Session::old($k, (string) ($s[$k] ?? ''))); ?>
<div class="card" style="max-width:820px"><div class="card-body">
<form method="post" action="<?= e(url(!empty($s['id']) ? '/suppliers/' . $s['id'] : '/suppliers')) ?>" class="row g-3"><?= csrf_field() ?>
  <div class="col-md-8"><label class="form-label required">Raison sociale</label><input name="name" value="<?= $v('name') ?>" class="form-control" required maxlength="150"></div>
  <div class="col-md-4"><label class="form-label">NIF</label><input name="nif" value="<?= $v('nif') ?>" class="form-control" maxlength="50"></div>
  <div class="col-md-4"><label class="form-label">Contact</label><input name="contact_name" value="<?= $v('contact_name') ?>" class="form-control" maxlength="150"></div>
  <div class="col-md-4"><label class="form-label">Téléphone</label><input name="phone" value="<?= $v('phone') ?>" class="form-control" maxlength="30"></div>
  <div class="col-md-4"><label class="form-label">E-mail</label><input type="email" name="email" value="<?= $v('email') ?>" class="form-control" maxlength="150"></div>
  <div class="col-12"><label class="form-label">Adresse</label><input name="address" value="<?= $v('address') ?>" class="form-control" maxlength="255"></div>
  <div class="col-12"><label class="form-label">Notes</label><textarea name="notes" class="form-control" rows="2" maxlength="2000"><?= $v('notes') ?></textarea></div>
  <?php if (!empty($s['id'])): ?><div class="col-12 form-check ms-2"><input type="checkbox" class="form-check-input" name="archive" value="1" id="ar"><label class="form-check-label" for="ar">Archiver ce fournisseur (historique conservé)</label></div><?php endif; ?>
  <div class="col-12"><button class="btn btn-primary">Enregistrer</button> <a href="<?= e(url('/suppliers')) ?>" class="btn btn-light">Annuler</a></div>
</form></div></div>
