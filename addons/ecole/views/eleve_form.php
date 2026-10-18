<?php use Addons\Ecole\Ecole; use Addons\Ecole\Finance; $v = fn($k) => e((string) ($e[$k] ?? \App\Core\Session::old($k))); $ptype = 'eleve'; $eleve = true; ?>
<form method="post" action="<?= e(url($e ? '/ecole/eleves/' . $e['id'] : '/ecole/eleves')) ?>" enctype="multipart/form-data" class="row g-3" data-ecole-form><?= csrf_field() ?>
  <div class="col-lg-3"><div class="card"><div class="card-body"><?php include __DIR__ . '/_photo.php'; ?></div></div></div>
  <div class="col-lg-9"><div class="card"><div class="card-header"><i class="bi bi-person-vcard"></i> Identité</div><div class="card-body row g-2 small">
    <div class="col-md-4"><label class="form-label mb-0 required">Nom</label><input name="nom" class="form-control" required maxlength="80" value="<?= $v('nom') ?>"></div>
    <div class="col-md-5"><label class="form-label mb-0 required">Prénom(s)</label><input name="prenom" class="form-control" required maxlength="100" value="<?= $v('prenom') ?>"></div>
    <div class="col-md-3"><label class="form-label mb-0 required">Sexe</label><select name="sexe" class="form-select" required><option value="">—</option><option value="M" <?= ($e['sexe'] ?? '') === 'M' ? 'selected' : '' ?>>Masculin</option><option value="F" <?= ($e['sexe'] ?? '') === 'F' ? 'selected' : '' ?>>Féminin</option></select></div>
    <div class="col-md-3"><label class="form-label mb-0">Né(e) le</label><input type="date" name="date_naissance" class="form-control" value="<?= $v('date_naissance') ?>"></div>
    <div class="col-md-3"><label class="form-label mb-0">à</label><input name="lieu_naissance" class="form-control" maxlength="100" value="<?= $v('lieu_naissance') ?>"></div>
    <div class="col-md-3"><label class="form-label mb-0">Nationalité</label><input name="nationalite" class="form-control" maxlength="60" value="<?= $e ? $v('nationalite') : 'Nigérienne' ?>"></div>
    <div class="col-md-3"><label class="form-label mb-0">Matricule</label><input name="matricule" class="form-control" maxlength="30" value="<?= $v('matricule') ?>" <?= $e ? 'disabled' : 'placeholder="automatique"' ?>></div>
    <div class="col-md-6"><label class="form-label mb-0">Adresse / quartier</label><input name="adresse" class="form-control" maxlength="255" value="<?= $v('adresse') ?>"></div>
    <div class="col-md-3"><label class="form-label mb-0">Téléphone</label><input name="telephone" class="form-control" maxlength="40" value="<?= $v('telephone') ?>"></div>
    <div class="col-md-3"><label class="form-label mb-0">E-mail</label><input type="email" name="email" class="form-control" maxlength="150" value="<?= $v('email') ?>"></div>
    <div class="col-md-4"><label class="form-label mb-0">Parent / tuteur</label><input name="tuteur_nom" class="form-control" maxlength="150" value="<?= $v('tuteur_nom') ?>"></div>
    <div class="col-md-2"><label class="form-label mb-0">Lien</label><input name="tuteur_lien" class="form-control" maxlength="40" value="<?= $v('tuteur_lien') ?>" placeholder="Père, mère…"></div>
    <div class="col-md-3"><label class="form-label mb-0">Téléphone du tuteur</label><input name="tuteur_tel" class="form-control" maxlength="40" value="<?= $v('tuteur_tel') ?>"></div>
    <div class="col-md-3"><label class="form-label mb-0">2ᵉ téléphone</label><input name="tuteur_tel2" class="form-control" maxlength="40" value="<?= $v('tuteur_tel2') ?>"></div>
    <div class="col-12"><label class="form-label mb-0">Informations médicales utiles (allergies, traitement…)</label><textarea name="infos_medicales" class="form-control" rows="2" maxlength="2000"><?= $v('infos_medicales') ?></textarea></div>
    <?php if ($e): ?><div class="col-md-4"><label class="form-label mb-0">Statut</label><select name="statut" class="form-select"><?php foreach (['actif' => 'Actif', 'suspendu' => 'Suspendu', 'sorti' => 'Sorti de l\'établissement', 'diplome' => 'Diplômé'] as $k => $l): ?><option value="<?= e($k) ?>" <?= $e['statut'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div><?php endif; ?>
    <?php include __DIR__ . '/_consentement.php'; ?>
  </div></div>
  <?php if (!$e && $classes): ?>
  <div class="card mt-3"><div class="card-header"><i class="bi bi-journal-plus"></i> Inscription pour l'année en cours (facultatif)</div><div class="card-body row g-2 small">
    <div class="col-md-4"><label class="form-label mb-0">Classe</label><select name="classe_id" class="form-select"><option value="">Ne pas inscrire maintenant</option><?php foreach ($classes as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['nom']) ?> — <?= money($c['frais_inscription'] + $c['frais_scolarite']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-4"><label class="form-label mb-0">Type</label><select name="type" class="form-select"><?php foreach (Finance::TYPES_INSCRIPTION as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2"><label class="form-label mb-0">Remise</label><input name="remise" class="form-control" value="0" inputmode="numeric"></div>
    <div class="col-md-2"><label class="form-label mb-0">Motif</label><input name="motif_remise" class="form-control" maxlength="150"></div>
  </div></div>
  <?php endif; ?>
  <div class="mt-3 d-flex gap-2"><button class="btn btn-primary"><i class="bi bi-check2"></i> Enregistrer</button>
    <?php if (!$e && can('ecole.caisse')): ?><button class="btn btn-success" name="aller_caisse" value="1"><i class="bi bi-cash-coin"></i> Enregistrer et encaisser</button><?php endif; ?>
    <a class="btn btn-outline-secondary" href="<?= e(url($e ? '/ecole/eleves/' . $e['id'] : '/ecole/eleves')) ?>">Annuler</a></div>
  </div>
</form>
