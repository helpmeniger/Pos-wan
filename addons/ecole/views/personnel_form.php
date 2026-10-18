<?php use Addons\Ecole\Ecole; $v = fn($k) => e((string) ($e[$k] ?? \App\Core\Session::old($k))); $ptype = 'personnel'; $eleve = false; ?>
<form method="post" action="<?= e(url($e ? '/ecole/personnel/' . $e['id'] : '/ecole/personnel')) ?>" enctype="multipart/form-data" class="row g-3" data-ecole-form><?= csrf_field() ?>
  <div class="col-lg-3"><div class="card"><div class="card-body"><?php include __DIR__ . '/_photo.php'; ?></div></div></div>
  <div class="col-lg-9"><div class="card"><div class="card-header"><i class="bi bi-person-badge"></i> Fiche</div><div class="card-body row g-2 small">
    <div class="col-md-4"><label class="form-label mb-0 required">Nom</label><input name="nom" class="form-control" required maxlength="80" value="<?= $v('nom') ?>"></div>
    <div class="col-md-5"><label class="form-label mb-0 required">Prénom(s)</label><input name="prenom" class="form-control" required maxlength="100" value="<?= $v('prenom') ?>"></div>
    <div class="col-md-3"><label class="form-label mb-0">Sexe</label><select name="sexe" class="form-select"><option value="">—</option><option value="M" <?= ($e['sexe'] ?? '') === 'M' ? 'selected' : '' ?>>Masculin</option><option value="F" <?= ($e['sexe'] ?? '') === 'F' ? 'selected' : '' ?>>Féminin</option></select></div>
    <div class="col-md-4"><label class="form-label mb-0">Fonction</label><select name="fonction" class="form-select"><?php foreach (Ecole::FONCTIONS as $k => $l): ?><option value="<?= e($k) ?>" <?= ($e['fonction'] ?? 'enseignant') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-5"><label class="form-label mb-0">Poste / spécialité</label><input name="poste" class="form-control" maxlength="100" value="<?= $v('poste') ?>" placeholder="Professeur de mathématiques, Surveillant général…"></div>
    <div class="col-md-3"><label class="form-label mb-0">Matricule</label><input name="matricule" class="form-control" maxlength="30" value="<?= $v('matricule') ?>" <?= $e ? 'disabled' : 'placeholder="automatique"' ?>></div>
    <div class="col-md-4"><label class="form-label mb-0">Téléphone</label><input name="telephone" class="form-control" maxlength="40" value="<?= $v('telephone') ?>"></div>
    <div class="col-md-4"><label class="form-label mb-0">E-mail</label><input type="email" name="email" class="form-control" maxlength="150" value="<?= $v('email') ?>"></div>
    <div class="col-md-4"><label class="form-label mb-0">Adresse</label><input name="adresse" class="form-control" maxlength="255" value="<?= $v('adresse') ?>"></div>
    <div class="col-md-4"><label class="form-label mb-0">Né(e) le</label><input type="date" name="date_naissance" class="form-control" value="<?= $v('date_naissance') ?>"></div>
    <div class="col-md-4"><label class="form-label mb-0">Embauché(e) le</label><input type="date" name="date_embauche" class="form-control" value="<?= $v('date_embauche') ?>"></div>
    <div class="col-md-4"><label class="form-label mb-0">Salaire mensuel</label><input name="salaire" class="form-control" inputmode="numeric" value="<?= e($e && $e['salaire'] !== null ? (string) (float) $e['salaire'] : '') ?>"></div>
    <?php if ($e): ?><div class="col-md-4"><label class="form-label mb-0">Statut</label><select name="statut" class="form-select"><option value="actif">Actif</option><option value="inactif" <?= $e['statut'] === 'inactif' ? 'selected' : '' ?>>Inactif</option></select></div><?php endif; ?>
    <?php include __DIR__ . '/_consentement.php'; ?>
  </div></div>
  <div class="mt-3 d-flex gap-2"><button class="btn btn-primary"><i class="bi bi-check2"></i> Enregistrer</button><a class="btn btn-outline-secondary" href="<?= e(url($e ? '/ecole/personnel/' . $e['id'] : '/ecole/personnel')) ?>">Annuler</a></div>
  </div>
</form>
