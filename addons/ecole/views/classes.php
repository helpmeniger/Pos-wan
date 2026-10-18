<?php use Addons\Ecole\Ecole; ?>
<?php if (!$annee): ?><div class="alert alert-warning">Créez d'abord l'année scolaire (<a href="<?= e(url('/ecole/parametres')) ?>">Paramètres de l'établissement</a>).</div><?php endif; ?>
<div class="row g-3">
  <div class="col-xl-8"><div class="card"><div class="card-header"><i class="bi bi-diagram-3"></i> Classes <?= $annee ? e($annee['libelle']) : '' ?></div>
    <div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Classe</th><th>Cycle / niveau</th><th>Titulaire</th><th class="text-end">Inscription</th><th class="text-end">Scolarité</th><th class="text-center">Effectif</th></tr></thead><tbody>
    <?php foreach ($classes as $c): ?><tr class="<?= $c['active'] ? '' : 'text-secondary' ?>"><td><a href="<?= e(url('/ecole/classes/' . $c['id'])) ?>"><b><?= e($c['nom']) ?></b></a><?= $c['salle'] ? '<br><span class="small text-secondary">Salle ' . e($c['salle']) . '</span>' : '' ?></td>
      <td class="small"><?= e(Ecole::CYCLES[$c['cycle']] ?? '') ?><?= $c['niveau'] ? ' · ' . e($c['niveau']) : '' ?></td><td class="small"><?= e($c['titulaire'] ?? '—') ?></td>
      <td class="text-end"><?= money($c['frais_inscription']) ?></td><td class="text-end"><?= money($c['frais_scolarite']) ?><br><span class="small text-secondary"><?= (int) $c['nb_tranches'] ?> tranche(s)</span></td>
      <td class="text-center"><?= (int) $c['effectif'] ?><?= $c['capacite'] ? ' / ' . (int) $c['capacite'] : '' ?></td></tr><?php endforeach; ?>
    <?php if (!$classes): ?><tr><td colspan="6" class="text-center text-secondary py-3">Aucune classe.</td></tr><?php endif; ?>
    </tbody></table></div></div></div>
  <div class="col-xl-4">
    <?php if ($annee): ?>
    <form method="post" action="<?= e(url('/ecole/classes')) ?>" class="card mb-3"><?= csrf_field() ?><div class="card-header"><i class="bi bi-plus-lg"></i> Nouvelle classe</div><div class="card-body row g-2 small">
      <div class="col-12"><label class="form-label mb-0 required">Nom</label><input name="nom" class="form-control" required maxlength="80" placeholder="CM2 A, 6e B, Licence 1 Gestion…"></div>
      <div class="col-6"><label class="form-label mb-0">Cycle</label><select name="cycle" class="form-select"><?php foreach (Ecole::CYCLES as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
      <div class="col-6"><label class="form-label mb-0">Niveau</label><input name="niveau" class="form-control" maxlength="60" placeholder="CM2, 6e, L1…"></div>
      <div class="col-6"><label class="form-label mb-0">Salle</label><input name="salle" class="form-control" maxlength="50"></div>
      <div class="col-6"><label class="form-label mb-0">Capacité</label><input type="number" name="capacite" min="0" class="form-control"></div>
      <div class="col-12"><label class="form-label mb-0">Titulaire / responsable</label><select name="titulaire_id" class="form-select"><option value="">—</option><?php foreach ($personnel as $p): ?><option value="<?= (int) $p['id'] ?>"><?= e($p['nom'] . ' ' . $p['prenom']) ?></option><?php endforeach; ?></select></div>
      <div class="col-6"><label class="form-label mb-0">Frais d'inscription</label><input name="frais_inscription" class="form-control" inputmode="numeric" value="0"></div>
      <div class="col-6"><label class="form-label mb-0">Scolarité annuelle</label><input name="frais_scolarite" class="form-control" inputmode="numeric" value="0"></div>
      <div class="col-6"><label class="form-label mb-0">Nombre de tranches</label><input type="number" name="nb_tranches" min="1" max="12" value="3" class="form-control"></div>
      <div class="col-12"><button class="btn btn-primary btn-sm">Créer la classe</button></div>
    </div></form>
    <?php endif; ?>
    <div class="card"><div class="card-header"><i class="bi bi-book"></i> Matières</div><div class="card-body small">
      <form method="post" action="<?= e(url('/ecole/matieres')) ?>" class="d-flex gap-1 mb-2"><?= csrf_field() ?><input name="nom" class="form-control form-control-sm" placeholder="Nom de la matière" required maxlength="100"><input name="code" class="form-control form-control-sm" style="max-width:80px" placeholder="Code" maxlength="20"><button class="btn btn-sm btn-primary">Ajouter</button></form>
      <div class="d-flex flex-wrap gap-1"><?php foreach ($matieres as $m): ?><span class="badge bg-light text-dark border"><?= e($m['nom']) ?><?= $m['code'] ? ' (' . e($m['code']) . ')' : '' ?> · <?= (int) $m['classes'] ?></span><?php endforeach; ?></div>
      <div class="form-text">Les coefficients se règlent dans chaque classe (programme).</div>
    </div></div>
  </div>
</div>
