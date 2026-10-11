<?php $v = fn(string $k) => App\Core\Session::old($k, (string) ($p[$k] ?? '')); ?>
<form method="post" action="<?= e(url($p ? '/clinique/patients/' . $p['id'] : '/clinique/patients')) ?>" class="card"><?= csrf_field() ?>
<div class="card-body row g-3">
  <div class="col-md-4"><label class="form-label">Nom *</label><input name="last_name" value="<?= e($v('last_name')) ?>" class="form-control" required maxlength="100" autofocus></div>
  <div class="col-md-4"><label class="form-label">Prénom(s) *</label><input name="first_name" value="<?= e($v('first_name')) ?>" class="form-control" required maxlength="100"></div>
  <div class="col-md-2"><label class="form-label">Sexe *</label><select name="sex" class="form-select" required><option value="">—</option><option value="M"<?= $v('sex') === 'M' ? ' selected' : '' ?>>Masculin</option><option value="F"<?= $v('sex') === 'F' ? ' selected' : '' ?>>Féminin</option></select></div>
  <div class="col-md-2"><label class="form-label">Date de naissance</label><input type="date" name="birth_date" value="<?= e($v('birth_date')) ?>" max="<?= date('Y-m-d') ?>" class="form-control"></div>
  <div class="col-md-3"><label class="form-label">Téléphone</label><input name="phone" value="<?= e($v('phone')) ?>" class="form-control" maxlength="50"></div>
  <div class="col-md-5"><label class="form-label">Adresse / quartier</label><input name="address" value="<?= e($v('address')) ?>" class="form-control" maxlength="255"></div>
  <div class="col-md-4"><label class="form-label">Personne à prévenir</label><input name="emergency_contact" value="<?= e($v('emergency_contact')) ?>" class="form-control" maxlength="150" placeholder="Nom et téléphone"></div>
  <div class="col-md-2"><label class="form-label">Groupe sanguin</label><select name="blood_group" class="form-select"><option value="">Inconnu</option><?php foreach (['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'] as $g): ?><option<?= $v('blood_group') === $g ? ' selected' : '' ?>><?= $g ?></option><?php endforeach; ?></select></div>
  <div class="col-md-10"><label class="form-label">Allergies connues</label><input name="allergies" value="<?= e($v('allergies')) ?>" class="form-control" maxlength="500" placeholder="ex. pénicilline, aspirine…"></div>
  <div class="col-md-12"><label class="form-label">Antécédents</label><textarea name="history" rows="2" class="form-control" placeholder="Médicaux, chirurgicaux, familiaux, traitements en cours…"><?= e($v('history')) ?></textarea></div>
  <div class="col-md-6"><label class="form-label">Assurance / organisme payeur</label><input name="insurer" value="<?= e($v('insurer')) ?>" class="form-control" maxlength="100" placeholder="ex. mutuelle, entreprise…"></div>
  <div class="col-md-3"><label class="form-label">Taux de prise en charge (%)</label><input name="coverage_pct" value="<?= e((string) ($v('coverage_pct') === '' ? '0' : (float) $v('coverage_pct'))) ?>" class="form-control" inputmode="decimal"></div>
</div>
<div class="card-footer d-flex gap-2">
  <a href="<?= e(url($p ? '/clinique/patients/' . $p['id'] : '/clinique/patients')) ?>" class="btn btn-outline-secondary">Annuler</a>
  <button class="btn btn-primary ms-auto"><i class="bi bi-check2"></i> Enregistrer le dossier</button>
</div>
</form>
