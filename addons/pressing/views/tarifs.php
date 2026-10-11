<?php use Addons\Pressing\PressingService as PS; ?>
<div class="alert alert-light border small">Prix en <?= e(currency()['symbol']) ?> par pièce. Laissez un prix vide si le service n'est pas proposé pour cet article. Décochez « Actif » pour retirer un article de la saisie (l'historique est conservé).</div>
<form method="post" action="<?= e(url('/pressing/tarifs')) ?>"><?= csrf_field() ?>
<div class="card mb-3"><div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Article</th><th>Catégorie</th><?php foreach (PS::SERVICES as $l): ?><th style="width:140px"><?= e($l) ?></th><?php endforeach; ?><th>Actif</th></tr></thead><tbody>
<?php foreach ($tarifs as $a): ?>
  <tr class="<?= $a['active'] ? '' : 'text-secondary' ?>"><td><input name="names[<?= (int) $a['id'] ?>]" value="<?= e($a['name']) ?>" class="form-control form-control-sm" maxlength="100" required></td><td class="small"><?= e($a['category']) ?></td>
  <?php foreach (array_keys(PS::SERVICES) as $s): ?><td><input name="prices[<?= (int) $a['id'] ?>][<?= e($s) ?>]" value="<?= isset($a['prices'][$s]) ? e((string) (float) $a['prices'][$s]) : '' ?>" class="form-control form-control-sm text-end" inputmode="numeric"></td><?php endforeach; ?>
  <td><input type="checkbox" class="form-check-input" name="active[<?= (int) $a['id'] ?>]" value="1" <?= $a['active'] ? 'checked' : '' ?>></td></tr>
<?php endforeach; ?>
</tbody></table></div><div class="card-footer"><button class="btn btn-primary"><i class="bi bi-save"></i> Enregistrer les tarifs</button></div></div>
</form>
<form method="post" action="<?= e(url('/pressing/tarifs')) ?>" class="card"><div class="card-body row g-2 align-items-end"><?= csrf_field() ?>
  <div class="col-md-5"><label class="form-label">Nouvel article</label><input name="new_name" class="form-control" maxlength="100" required placeholder="ex. Tapis (m²), Sac, Chaussures…"></div>
  <div class="col-md-4"><label class="form-label">Catégorie</label><input name="new_category" class="form-control" maxlength="50" list="pCats" value="Vêtements"><datalist id="pCats"><?php foreach (array_unique(array_column($tarifs, 'category')) as $c): ?><option value="<?= e($c) ?>"><?php endforeach; ?></datalist></div>
  <div class="col-md-3"><button class="btn btn-outline-primary w-100"><i class="bi bi-plus"></i> Ajouter</button></div>
</div></form>
