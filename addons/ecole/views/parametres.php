<?php use Addons\Ecole\Ecole; use Addons\Ecole\Presences; ?>
<div class="row g-3">
  <div class="col-xl-6">
    <div class="card mb-3"><div class="card-header"><i class="bi bi-calendar3"></i> Années scolaires</div><div class="card-body">
      <table class="table table-sm align-middle"><thead><tr><th>Année</th><th>Du</th><th>Au</th><th></th></tr></thead><tbody>
      <?php foreach ($annees as $a): ?><tr><td><b><?= e($a['libelle']) ?></b> <?= $a['courante'] ? '<span class="badge bg-success">en cours</span>' : '' ?></td><td><?= e(fdate($a['debut'])) ?></td><td><?= e(fdate($a['fin'])) ?></td>
        <td class="text-end"><?php if (!$a['courante']): ?><form method="post" action="<?= e(url('/ecole/annees/' . $a['id'] . '/courante')) ?>" data-confirm="Passer à l'année <?= e($a['libelle']) ?> ? Les inscriptions, classes et frais affichés seront ceux de cette année."><?= csrf_field() ?><button class="btn btn-sm btn-outline-primary">Rendre courante</button></form><?php endif; ?></td></tr><?php endforeach; ?>
      <?php if (!$annees): ?><tr><td colspan="4" class="text-secondary">Aucune année : créez-la ci-dessous.</td></tr><?php endif; ?>
      </tbody></table>
      <form method="post" action="<?= e(url('/ecole/annees')) ?>" class="row g-2 small"><?= csrf_field() ?>
        <div class="col-4"><label class="form-label mb-0 required">Libellé</label><input name="libelle" class="form-control" placeholder="<?= e(date('Y') . '-' . (date('Y') + 1)) ?>" required maxlength="20"></div>
        <div class="col-4"><label class="form-label mb-0 required">Début</label><input type="date" name="debut" class="form-control" required></div>
        <div class="col-4"><label class="form-label mb-0 required">Fin</label><input type="date" name="fin" class="form-control" required></div>
        <div class="col-6"><select name="decoupage" class="form-select"><option value="trimestre">3 trimestres</option><option value="semestre">2 semestres</option></select></div>
        <div class="col-6 d-flex align-items-center"><label><input type="checkbox" name="courante" value="1" checked> Année en cours</label></div>
        <div class="col-12"><button class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i> Créer l'année</button></div>
      </form>
    </div></div>

    <?php if ($periodes): ?>
    <div class="card mb-3"><div class="card-header"><i class="bi bi-calendar-range"></i> Périodes de l'année <?= e($annee['libelle']) ?></div><div class="card-body small">
      <?php foreach ($periodes as $p): ?>
        <form method="post" action="<?= e(url('/ecole/periodes/' . $p['id'])) ?>" class="row g-1 align-items-end mb-2"><?= csrf_field() ?>
          <div class="col-4"><input name="nom" class="form-control form-control-sm" value="<?= e($p['nom']) ?>" maxlength="50"></div>
          <div class="col-3"><input type="date" name="debut" class="form-control form-control-sm" value="<?= e((string) $p['debut']) ?>"></div>
          <div class="col-3"><input type="date" name="fin" class="form-control form-control-sm" value="<?= e((string) $p['fin']) ?>"></div>
          <div class="col-2"><button class="btn btn-sm btn-outline-primary w-100">OK</button></div>
          <div class="col-12"><label><input type="checkbox" name="cloturee" value="1" <?= $p['cloturee'] ? 'checked' : '' ?>> Clôturée (notes verrouillées)</label></div>
        </form>
      <?php endforeach; ?>
    </div></div>
    <?php endif; ?>

    <div class="card"><div class="card-header"><i class="bi bi-person-bounding-box"></i> Points de pointage (caméras)</div><div class="card-body small">
      <p class="text-secondary">Un point = un endroit filmé (portail, entrée du bâtiment, couloir). Caméra du poste qui affiche le pointage, ou caméra IP / USB déclarée dans le module Reconnaissance (Configuration → Caméras).</p>
      <table class="table table-sm"><thead><tr><th>Point</th><th>Rôle</th><th>Caméra</th><th></th></tr></thead><tbody>
      <?php foreach ($points as $pt): ?><tr><td><?= e($pt['nom']) ?><?= $pt['actif'] ? '' : ' <span class="badge bg-secondary">inactif</span>' ?></td><td><?= e(Presences::ROLES[$pt['role']]) ?></td>
        <td><?php $cam = array_values(array_filter($cameras, fn($c) => (int) $c['id'] === (int) $pt['vision_camera_id']))[0] ?? null; ?><?= $cam ? e($cam['name']) : e($pt['device_label'] ?: 'caméra du poste') ?></td>
        <td class="text-end"><form method="post" action="<?= e(url('/ecole/points/' . $pt['id'] . '/delete')) ?>" data-confirm="Supprimer ce point ? Les passages enregistrés sont conservés."><?= csrf_field() ?><button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form></td></tr><?php endforeach; ?>
      <?php if (!$points): ?><tr><td colspan="4" class="text-secondary">Aucun point.</td></tr><?php endif; ?>
      </tbody></table>
      <form method="post" action="<?= e(url('/ecole/points')) ?>" class="row g-2"><?= csrf_field() ?>
        <div class="col-6"><input name="nom" class="form-control form-control-sm" placeholder="Nom (ex. : Portail principal)" required maxlength="100"></div>
        <div class="col-6"><select name="role" class="form-select form-select-sm"><?php foreach (Presences::ROLES as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-12"><select name="vision_camera_id" class="form-select form-select-sm"><option value="">Caméra du poste de pointage (webcam, USB, carte d'acquisition)</option><?php foreach ($cameras as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?> (<?= e($c['kind'] === 'ip' ? 'caméra IP' : 'caméra déclarée') ?>)</option><?php endforeach; ?></select></div>
        <div class="col-12"><button class="btn btn-sm btn-primary"><i class="bi bi-plus-lg"></i> Ajouter le point</button></div>
      </form>
    </div></div>
  </div>

  <div class="col-xl-6">
    <form method="post" action="<?= e(url('/ecole/parametres')) ?>" class="card"><?= csrf_field() ?>
      <div class="card-header"><i class="bi bi-sliders"></i> Établissement</div><div class="card-body row g-2 small">
        <div class="col-12"><label class="form-label mb-0">Type d'établissement</label><select name="ecole_type" class="form-select"><?php foreach (Ecole::TYPES as $k => $l): ?><option value="<?= e($k) ?>" <?= setting('ecole_type', 'mixte') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select><div class="form-text">Université : on parle d'« étudiants » ; sinon d'« élèves ». Le nom et le logo viennent de Paramètres entreprise.</div></div>
        <div class="col-12"><label class="form-label mb-0">Devise ou mention (bulletins, cartes)</label><input name="ecole_devise" class="form-control" maxlength="150" value="<?= e(setting('ecole_devise', '')) ?>" placeholder="Ex. : Travail – Discipline – Réussite"></div>
        <div class="col-4"><label class="form-label mb-0">Début des cours</label><input type="time" name="ecole_heure_debut" class="form-control" value="<?= e(setting('ecole_heure_debut', '07:30')) ?>"></div>
        <div class="col-4"><label class="form-label mb-0">Tolérance (min)</label><input type="number" name="ecole_tolerance" min="0" max="120" class="form-control" value="<?= e(setting('ecole_tolerance', '10')) ?>"></div>
        <div class="col-4"><label class="form-label mb-0">Fin des cours</label><input type="time" name="ecole_heure_fin" class="form-control" value="<?= e(setting('ecole_heure_fin', '17:00')) ?>"></div>
        <div class="col-6"><label class="form-label mb-0">Moyenne d'admission / validation</label><input name="ecole_seuil_passage" class="form-control" inputmode="decimal" value="<?= e(setting('ecole_seuil_passage', '10')) ?>"></div>
        <div class="col-6"><label class="form-label mb-0">Signature des bulletins</label><input name="ecole_signature" class="form-control" maxlength="100" value="<?= e(setting('ecole_signature', '')) ?>"></div>
        <div class="col-12"><label><input type="checkbox" name="ecole_absence_zero" value="1" <?= setting('ecole_absence_zero', '0') === '1' ? 'checked' : '' ?>> Une absence à une évaluation compte 0 (sinon elle est ignorée dans la moyenne)</label></div>
      </div>
      <div class="card-header border-top"><i class="bi bi-person-bounding-box"></i> Reconnaissance faciale (pointage par caméra)</div><div class="card-body row g-2 small">
        <div class="col-12"><div class="alert alert-warning py-2 mb-1">
          <b>Données biométriques :</b> à n'activer qu'après avoir informé les familles et le personnel. Le visage d'une personne n'est enregistré qu'avec son consentement noté sur sa fiche (celui d'un parent ou tuteur pour un mineur), et il est effacé dès que ce consentement est retiré. Respectez la réglementation sur les données personnelles en vigueur au Niger. La reconnaissance automatique n'est jamais infaillible : les passages douteux sont gardés pour vérification, et l'appel manuel reste possible.
        </div></div>
        <div class="col-12"><label class="fw-bold"><input type="checkbox" name="ecole_visage_actif" value="1" <?= setting('ecole_visage_actif', '0') === '1' ? 'checked' : '' ?>> Activer la reconnaissance faciale</label></div>
        <div class="col-6"><label class="form-label mb-0">Distance maximale (plus bas = plus strict)</label><input name="ecole_visage_seuil" class="form-control" inputmode="decimal" value="<?= e(setting('ecole_visage_seuil', '0.50')) ?>"><div class="form-text">0,50 recommandé (entre 0,30 et 0,70).</div></div>
        <div class="col-6"><label class="form-label mb-0">Écart minimal avec la 2ᵉ personne</label><input name="ecole_visage_marge" class="form-control" inputmode="decimal" value="<?= e(setting('ecole_visage_marge', '0.05')) ?>"><div class="form-text">Évite de confondre deux personnes qui se ressemblent.</div></div>
        <div class="col-6"><label class="form-label mb-0">Conservation des vignettes (jours)</label><input type="number" name="ecole_conservation" min="1" max="365" class="form-control" value="<?= e(setting('ecole_conservation', '30')) ?>"></div>
      </div>
      <div class="card-footer"><button class="btn btn-primary"><i class="bi bi-check2"></i> Enregistrer</button></div>
    </form>
  </div>
</div>
