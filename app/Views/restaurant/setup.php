<?php $shapes = ['square' => 'Carrée', 'round' => 'Ronde', 'long' => 'Longue (double)']; ?>
<div class="row g-3">
  <div class="col-xl-8"><div class="card"><div class="card-header">Tables</div><div class="table-responsive"><table class="table table-sm table-hover mb-0"><thead><tr><th>Salle</th><th>Table</th><th class="num">Places</th><th>Forme</th><th>État</th><th></th></tr></thead><tbody>
    <?php foreach ($tables as $t): ?><tr class="<?= (int) $t['active'] ? '' : 'text-secondary' ?>"><td><?= e($t['area']) ?></td><td class="fw-semibold"><?= e($t['name']) ?></td><td class="num"><?= (int) $t['seats'] ?></td><td><?= e($shapes[$t['shape']]) ?></td><td><?= (int) $t['active'] ? 'active' : 'désactivée' ?></td><td><a class="btn btn-sm btn-light" href="<?= e(url('/restaurant/setup', ['edit' => $t['id']])) ?>"><i class="bi bi-pencil"></i></a></td></tr><?php endforeach; ?>
    <?php if (!$tables): ?><tr><td colspan="6" class="text-secondary text-center py-3">Créez d'abord une salle, puis ses tables.</td></tr><?php endif; ?>
  </tbody></table></div></div></div>
  <div class="col-xl-4">
    <?php if ($edit): ?>
    <div class="card mb-3 border-primary"><div class="card-header">Modifier la table <?= e($edit['name']) ?></div><div class="card-body">
      <form method="post" action="<?= e(url('/restaurant/tables/' . $edit['id'])) ?>" class="row g-2"><?= csrf_field() ?>
        <div class="col-6"><input name="name" value="<?= e($edit['name']) ?>" class="form-control" required maxlength="50"></div>
        <div class="col-6"><select name="area_id" class="form-select"><?php foreach ($areas as $a): ?><option value="<?= (int) $a['id'] ?>" <?= (int) $a['id'] === (int) $edit['area_id'] ? 'selected' : '' ?>><?= e($a['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-4"><input type="number" name="seats" min="1" max="50" value="<?= (int) $edit['seats'] ?>" class="form-control" title="Places"></div>
        <div class="col-4"><select name="shape" class="form-select"><?php foreach ($shapes as $k => $l): ?><option value="<?= $k ?>" <?= $edit['shape'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-4"><input type="number" name="sort_order" value="<?= (int) $edit['sort_order'] ?>" class="form-control" title="Ordre"></div>
        <div class="col-12 form-check ms-1"><input type="checkbox" class="form-check-input" name="active" value="1" id="ta" <?= (int) $edit['active'] ? 'checked' : '' ?>><label class="form-check-label" for="ta">Active</label></div>
        <div class="col-12"><button class="btn btn-primary">Enregistrer</button> <a href="<?= e(url('/restaurant/setup')) ?>" class="btn btn-light">Annuler</a></div>
      </form></div></div>
    <?php endif; ?>
    <div class="card mb-3"><div class="card-header"><i class="bi bi-plus-square"></i> Ajouter des tables</div><div class="card-body">
      <form method="post" action="<?= e(url('/restaurant/tables')) ?>" class="row g-2"><?= csrf_field() ?>
        <div class="col-12"><select name="area_id" class="form-select" required><?php foreach ($areas as $a): ?><option value="<?= (int) $a['id'] ?>"><?= e($a['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-5"><input name="name" class="form-control" placeholder="Préfixe (T)" value="T" required maxlength="40"></div>
        <div class="col-3"><input type="number" name="count" min="1" max="50" value="1" class="form-control" title="Nombre"></div>
        <div class="col-4"><input type="number" name="seats" min="1" max="50" value="4" class="form-control" title="Places"></div>
        <div class="col-12"><select name="shape" class="form-select"><?php foreach ($shapes as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-12 form-text">Plusieurs tables : numérotation automatique (T1, T2…). Une table : nom exact saisi.</div>
        <div class="col-12"><button class="btn btn-primary w-100" <?= $areas ? '' : 'disabled' ?>>Créer</button></div>
      </form></div></div>
    <div class="card"><div class="card-header"><i class="bi bi-house"></i> Salles</div><table class="table table-sm mb-0"><tbody><?php foreach ($areas as $a): ?><tr><td><?= e($a['name']) ?></td><td class="num"><?= (int) $a['n'] ?> table(s)</td></tr><?php endforeach; ?></tbody></table>
      <div class="card-body border-top"><form method="post" action="<?= e(url('/restaurant/areas')) ?>" class="row g-1"><?= csrf_field() ?><div class="col-7"><input name="name" class="form-control form-control-sm" placeholder="Salle, terrasse, VIP…" required maxlength="100"></div><div class="col-2"><input type="number" name="sort_order" class="form-control form-control-sm" value="0" title="Ordre"></div><div class="col-3"><button class="btn btn-sm btn-outline-primary w-100">Ajouter</button></div><?php if (count($stores) > 1): ?><div class="col-12"><select name="store_id" class="form-select form-select-sm"><?php foreach ($stores as $s): ?><option value="<?= (int) $s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?></select></div><?php endif; ?></form></div></div>
    <div class="alert alert-light border small mt-3">Le <b>poste de préparation</b> (cuisine, bar…) de chaque article se règle sur sa <a href="<?= e(url('/categories')) ?>">catégorie</a>. Une catégorie sans poste (ex. boissons en bouteille) ne génère pas de ticket.</div>
  </div>
</div>
