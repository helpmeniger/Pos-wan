<?php use Addons\Ecole\Ecole; ?>
<form method="get" class="card card-body py-2 mb-3"><div class="row g-2 align-items-end small">
  <div class="col-md-5"><input name="q" value="<?= e($q) ?>" class="form-control form-control-sm" placeholder="Nom, matricule, téléphone"></div>
  <div class="col-md-4"><select name="fonction" class="form-select form-select-sm"><option value="">Toutes fonctions</option><?php foreach (Ecole::FONCTIONS as $k => $l): ?><option value="<?= e($k) ?>" <?= $fonction === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-3 d-flex gap-1"><button class="btn btn-sm btn-primary flex-grow-1"><i class="bi bi-search"></i></button><?php if (can('ecole.manage')): ?><a class="btn btn-sm btn-success" href="<?= e(url('/ecole/personnel/nouveau')) ?>"><i class="bi bi-person-plus"></i> Nouveau</a><?php endif; ?></div>
</div></form>
<div class="card"><div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th></th><th>Nom</th><th>Matricule</th><th>Fonction</th><th>Téléphone</th><th class="text-center">Visage</th></tr></thead><tbody>
<?php foreach ($p['rows'] as $r): ?>
  <tr class="<?= $r['statut'] === 'inactif' ? 'text-secondary' : '' ?>"><td style="width:44px"><?php if ($r['photo']): ?><img class="ec-mini" src="<?= e(url('/ecole/photo/personnel/' . $r['id'])) ?>" alt="" loading="lazy"><?php else: ?><span class="ec-mini ec-mini-empty"><i class="bi bi-person"></i></span><?php endif; ?></td>
    <td><a href="<?= e(url('/ecole/personnel/' . $r['id'])) ?>"><b><?= e($r['nom']) ?></b> <?= e($r['prenom']) ?></a></td><td class="small"><?= e($r['matricule']) ?></td>
    <td class="small"><?= e(Ecole::FONCTIONS[$r['fonction']]) ?><?= $r['poste'] ? ' · ' . e($r['poste']) : '' ?></td><td class="small"><?= e((string) $r['telephone']) ?></td>
    <td class="text-center small"><?= (int) $r['visages'] ? '<span class="badge bg-success">' . (int) $r['visages'] . '</span>' : '—' ?></td></tr>
<?php endforeach; ?>
<?php if (!$p['rows']): ?><tr><td colspan="6" class="text-center text-secondary py-4">Aucune fiche.</td></tr><?php endif; ?>
</tbody></table></div></div>
<?= App\Core\View::partial('partials/pagination', ['p' => $p]) ?>
