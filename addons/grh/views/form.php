<?php use Addons\Grh\GrhService as S; require __DIR__ . '/_h.php'; $v = fn (string $k, mixed $d = '') => $e[$k] ?? $d; ?>
<form method="post" action="<?= e(url('/grh/employes')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $v('id', 0) ?>">
<div class="card mb-3"><div class="card-header"><i class="bi bi-person-vcard"></i> <?= e(t('Identité')) ?></div><div class="card-body row g-2">
  <?= grh_field('matricule', 'Matricule', $v('matricule', $nextMat), 'text', 'required maxlength="30"', 'col-md-2') ?><?= grh_select('civility', 'Civilité', S::CIVILITIES, $v('civility', 'M.'), '', 'col-md-1', false) ?>
  <?= grh_field('last_name', 'Nom', $v('last_name'), 'text', 'required maxlength="80"') ?><?= grh_field('first_name', 'Prénom', $v('first_name'), 'text', 'required maxlength="80"') ?><?= grh_select('sex', 'Sexe', ['M' => 'Homme', 'F' => 'Femme'], $v('sex', 'M'), '', 'col-md-1') ?>
  <?= grh_field('birth_date', 'Date de naissance', $v('birth_date'), 'date', '', 'col-md-2') ?><?= grh_field('birth_place', 'Lieu de naissance', $v('birth_place'), 'text', 'maxlength="100"', 'col-md-2') ?><?= grh_field('nationality', 'Nationalité', $v('nationality', 'Nigérienne'), 'text', 'maxlength="60"', 'col-md-2') ?>
  <?= grh_select('marital', 'Situation familiale', S::MARITAL, $v('marital', 'celibataire'), '', 'col-md-2') ?><?= grh_field('children', 'Enfants à charge', $v('children', 0), 'number', 'min="0" max="40"', 'col-md-2') ?><?= grh_field('id_number', 'N° de pièce d\'identité', $v('id_number'), 'text', 'maxlength="60"', 'col-md-2') ?>
</div></div>
<div class="card mb-3"><div class="card-header"><i class="bi bi-telephone"></i> <?= e(t('Coordonnées')) ?></div><div class="card-body row g-2">
  <?= grh_field('phone', 'Téléphone', $v('phone'), 'text', 'maxlength="40"', 'col-md-2') ?><?= grh_field('email', 'E-mail', $v('email'), 'email', 'maxlength="150"') ?><?= grh_field('address', 'Adresse', $v('address'), 'text', 'maxlength="200"', 'col-md-4') ?><?= grh_field('emergency_contact', 'Personne à prévenir (nom et téléphone)', $v('emergency_contact'), 'text', 'maxlength="150"') ?>
</div></div>
<div class="card mb-3"><div class="card-header"><i class="bi bi-briefcase"></i> <?= e(t('Emploi')) ?></div><div class="card-body row g-2">
  <?= grh_field('hire_date', 'Date d\'embauche', $v('hire_date', date('Y-m-d')), 'date', 'required', 'col-md-2') ?><?= grh_select('departement_id', 'Département', grh_opts($deps, 'name'), $v('departement_id'), '', 'col-md-3', false) ?>
  <?= grh_select('poste_id', 'Poste', grh_opts($postes, fn ($r) => $r['title'] . ($r['grade'] ? ' (' . $r['grade'] . ')' : '')), $v('poste_id'), '', 'col-md-3', false) ?>
  <?= grh_select('manager_id', 'Responsable hiérarchique', grh_opts(array_filter($actifs, fn ($a) => (int) $a['id'] !== (int) $v('id', 0)), fn ($r) => $r['last_name'] . ' ' . $r['first_name']), $v('manager_id'), '', 'col-md-4', false) ?>
  <?= grh_field('cnss_number', 'N° CNSS', $v('cnss_number'), 'text', 'maxlength="40"') ?><?= grh_field('bank_account', 'Compte bancaire ou mobile money (paie)', $v('bank_account'), 'text', 'maxlength="80"', 'col-md-4') ?>
</div></div>
<p class="small text-secondary"><i class="bi bi-shield-lock"></i> <?= e(t('Données personnelles protégées par la loi n° 2017-28 : chaque consultation d\'une fiche est inscrite au journal d\'audit. Le salaire se définit dans le contrat, après la création de la fiche.')) ?></p>
<button class="btn btn-primary"><i class="bi bi-check-lg"></i> <?= e(t('Enregistrer')) ?></button> <a class="btn btn-outline-secondary" href="<?= e(url($v('id') ? '/grh/employes/' . $v('id') : '/grh/employes')) ?>"><?= e(t('Annuler')) ?></a>
</form>
