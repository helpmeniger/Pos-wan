<?php use Addons\Ecole\Ecole; ?>
<form method="get" class="d-flex gap-2 align-items-end mb-3 small"><div><label class="form-label mb-0">Mois</label><input type="month" name="mois" value="<?= e($mois) ?>" class="form-control form-control-sm"></div><button class="btn btn-sm btn-primary">Afficher</button>
  <span class="ms-auto">Versé ce mois : <b><?= money(array_sum(array_map(fn($v) => $v['annule'] ? 0 : (float) $v['montant'], $versements))) ?></b></span></form>
<div class="card mb-3"><div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Personnel</th><th class="text-end">Salaire prévu</th><th class="text-end">Versé <?= e($mois) ?></th><th>Verser</th></tr></thead><tbody>
<?php foreach ($personnel as $p): ?><tr><td><b><?= e($p['nom']) ?></b> <?= e($p['prenom']) ?><br><span class="small text-secondary"><?= e(Ecole::FONCTIONS[$p['fonction']]) ?></span></td>
  <td class="text-end"><?= $p['salaire'] !== null ? money($p['salaire']) : '—' ?></td><td class="text-end <?= (float) $p['verse'] > 0 ? 'text-success fw-bold' : '' ?>"><?= money($p['verse']) ?></td>
  <td><form method="post" action="<?= e(url('/ecole/salaires')) ?>" class="d-flex flex-wrap gap-1" data-confirm="Verser le salaire de <?= e($mois) ?> à <?= e($p['prenom'] . ' ' . $p['nom']) ?> ?"><?= csrf_field() ?><input type="hidden" name="personnel_id" value="<?= (int) $p['id'] ?>"><input type="hidden" name="periode" value="<?= e($mois) ?>">
    <input name="montant" class="form-control form-control-sm" style="width:110px" inputmode="numeric" value="<?= e($p['salaire'] !== null ? (string) max(0, (float) $p['salaire'] - (float) $p['verse']) : '') ?>" required>
    <select name="mode" class="form-select form-select-sm" style="width:130px"><?php foreach ($modes as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select>
    <?php if ((float) $p['verse'] > 0): ?><label class="small"><input type="checkbox" name="complement" value="1" required> complément</label><?php endif; ?>
    <button class="btn btn-sm btn-outline-success">Verser</button></form></td></tr><?php endforeach; ?>
</tbody></table></div></div>
<div class="card"><div class="card-header">Versements de <?= e($mois) ?></div><ul class="list-group list-group-flush small">
<?php foreach ($versements as $v): ?><li class="list-group-item d-flex align-items-center <?= $v['annule'] ? 'text-secondary' : '' ?>"><a href="<?= e(url('/ecole/salaires/' . $v['id'])) ?>"><?= e($v['numero']) ?></a><span class="ms-2"><?= e($v['prenom'] . ' ' . $v['nom']) ?></span><span class="ms-auto <?= $v['annule'] ? 'text-decoration-line-through' : 'fw-bold' ?>"><?= money($v['montant']) ?></span>
  <?php if (!$v['annule']): ?><form method="post" action="<?= e(url('/ecole/salaires/' . $v['id'] . '/annuler')) ?>" class="ms-2" data-confirm="Annuler ce versement ?" data-reason="1"><?= csrf_field() ?><button class="btn btn-sm btn-outline-danger">Annuler</button></form><?php else: ?><span class="badge bg-secondary ms-2">annulé</span><?php endif; ?></li><?php endforeach; ?>
<?php if (!$versements): ?><li class="list-group-item text-secondary">Aucun versement.</li><?php endif; ?>
</ul></div>
