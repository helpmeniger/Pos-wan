<form class="row g-2 mb-3 no-print" method="get">
  <div class="col-6 col-md-2"><input type="date" name="from" class="form-control form-control-sm" value="<?= e($from) ?>"></div>
  <div class="col-6 col-md-2"><input type="date" name="to" class="form-control form-control-sm" value="<?= e($to) ?>"></div>
  <div class="col-auto"><button class="btn btn-sm btn-outline-primary">Calculer</button> <button type="button" class="btn btn-sm btn-light" onclick="window.print()"><i class="bi bi-printer"></i></button></div>
</form>
<div class="alert alert-light border small">Les colonnes comptables (stock, entrées, ventes, sorties autorisées) proviennent des mouvements validés. Les colonnes vidéo sont des <strong>observations</strong> affichées pour comparaison : elles ne sont jamais soustraites du stock et un écart n'est pas un nombre de vols.</div>
<div class="card"><div class="table-responsive"><table class="table table-sm table-hover mb-0">
<thead><tr><th>Produit</th><th class="text-end">Stock initial</th><th class="text-end">Entrées</th><th class="text-end">Ventes</th><th class="text-end">Sorties autorisées</th><th class="text-end">Autres</th><th class="text-end">Stock théorique</th><th class="text-end">Stock physique</th><th class="text-end">Écart d'inventaire</th><th class="text-end table-warning">Sorties observées</th><th class="text-end table-warning">Non rapprochées</th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr><td><?= e($r['name']) ?></td><td class="text-end"><?= qty($r['initial']) ?></td><td class="text-end"><?= qty($r['entries']) ?></td><td class="text-end"><?= qty($r['sales']) ?></td>
  <td class="text-end"><?= qty($r['authorized_exits']) ?></td><td class="text-end"><?= qty($r['other']) ?></td><td class="text-end fw-semibold"><?= qty($r['theoretical']) ?></td>
  <td class="text-end"><?= $r['physical'] !== null ? qty($r['physical']) : '—' ?></td>
  <td class="text-end <?= $r['variance'] !== null && abs((float) $r['variance']) > 0.0005 ? 'text-danger fw-bold' : '' ?>"><?= $r['variance'] !== null ? qty($r['variance']) : '—' ?></td>
  <td class="text-end table-warning"><?= (int) $r['observed_exits'] ?></td><td class="text-end table-warning"><?= (int) $r['unmatched_exits'] ?></td></tr>
<?php endforeach; ?>
</tbody></table></div></div>
