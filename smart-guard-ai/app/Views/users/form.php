<?php $isEdit = !empty($u['id']); ?>
<form method="post" action="<?= url($isEdit ? 'users/' . (int) $u['id'] : 'users') ?>" class="card" style="max-width:860px" autocomplete="off"><div class="card-body"><?= csrf_field() ?>
<div class="row g-3">
  <div class="col-md-6"><label class="form-label">Nom complet *</label><input name="full_name" class="form-control" required maxlength="120" value="<?= e($u['full_name'] ?? '') ?>"></div>
  <div class="col-md-6"><label class="form-label">Identifiant de connexion *</label><input name="username" class="form-control" required maxlength="60" pattern="[a-zA-Z0-9._-]+" value="<?= e($u['username'] ?? '') ?>"></div>
  <div class="col-md-6"><label class="form-label">E-mail</label><input name="email" type="email" class="form-control" value="<?= e($u['email'] ?? '') ?>"></div>
  <div class="col-md-6"><label class="form-label">Téléphone</label><input name="phone" class="form-control" value="<?= e($u['phone'] ?? '') ?>"></div>
  <div class="col-md-6"><label class="form-label">Mot de passe <?= $isEdit ? '(laisser vide pour ne pas changer)' : '*' ?></label><input name="password" type="password" class="form-control" autocomplete="new-password" <?= $isEdit ? '' : 'required' ?> minlength="10">
    <div class="form-text">10 caractères minimum, lettres et chiffres.</div></div>
  <div class="col-md-6 d-flex flex-column justify-content-end">
    <div class="form-check"><input class="form-check-input" type="checkbox" name="is_active" value="1" id="ia" <?= !empty($u['is_active']) ? 'checked' : '' ?>><label class="form-check-label" for="ia">Compte actif</label></div>
    <div class="form-check"><input class="form-check-input" type="checkbox" name="must_change_password" value="1" id="mc" <?= !empty($u['must_change_password']) || !$isEdit ? 'checked' : '' ?>><label class="form-check-label" for="mc">Changement de mot de passe à la prochaine connexion</label></div></div>
  <div class="col-md-6"><label class="form-label">Rôles *</label><?php foreach ($roles as $r): ?><div class="form-check"><input class="form-check-input" type="checkbox" name="roles[]" value="<?= (int) $r['id'] ?>" id="r<?= (int) $r['id'] ?>" <?= in_array((int) $r['id'], $userRoles, true) ? 'checked' : '' ?>>
    <label class="form-check-label" for="r<?= (int) $r['id'] ?>"><?= e($r['name']) ?> <span class="small text-muted">— <?= e($r['description']) ?></span></label></div><?php endforeach; ?></div>
  <div class="col-md-6"><label class="form-label">Magasins autorisés</label><?php foreach ($stores as $s): ?><div class="form-check"><input class="form-check-input" type="checkbox" name="stores[]" value="<?= (int) $s['id'] ?>" id="s<?= (int) $s['id'] ?>" <?= in_array((int) $s['id'], $userStores, true) ? 'checked' : '' ?>>
    <label class="form-check-label" for="s<?= (int) $s['id'] ?>"><?= e($s['name']) ?></label></div><?php endforeach; ?><div class="form-text">L'administrateur général a accès à tous les magasins.</div></div>
</div></div><div class="card-footer bg-white d-flex gap-2"><button class="btn btn-primary">Enregistrer</button><a href="<?= url('users') ?>" class="btn btn-light">Annuler</a></div></form>
