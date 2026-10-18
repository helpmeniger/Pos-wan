<?php
$draft = $inv['status'] === 'draft';
$cost = can('products.cost_view');
$tabs = ['' => ['Tous', $sum['items']], 'uncounted' => ['Non comptés', $sum['uncounted']], 'diff' => ['Écarts', $sum['surplus'] + $sum['missing']], 'surplus' => ['Excédents', $sum['surplus']], 'missing' => ['Manquants', $sum['missing']], 'conform' => ['Conformes', $sum['conform']]];
?>
<div class="d-flex flex-wrap gap-2 mb-3 align-items-center">
  <a class="btn btn-outline-secondary" href="<?= e(url('/inventories')) ?>"><i class="bi bi-arrow-left"></i></a>
  <span><b><?= e($inv['warehouse']) ?></b><?= $inv['category'] ? ' · ' . e($inv['category']) : '' ?> · créé le <?= e(fdate($inv['created_at'])) ?> par <?= e($inv['full_name']) ?> · <?= App\Core\View::partial('partials/status', ['status' => $inv['status']]) ?><?= $inv['validator'] ? ' par ' . e($inv['validator']) . ' le ' . e(fdate($inv['validated_at'])) : '' ?></span>
  <div class="ms-auto d-flex flex-wrap gap-2">
    <a class="btn btn-outline-secondary" target="_blank" rel="noopener" href="<?= e(url('/inventories/' . $inv['id'], ['print' => 1])) ?>"><i class="bi bi-printer"></i> Feuille de comptage</a>
    <a class="btn btn-outline-primary" target="_blank" rel="noopener" href="<?= e(url('/inventories/' . $inv['id'] . '/report')) ?>"><i class="bi bi-file-earmark-text"></i> Rapport d'écarts</a>
    <?php if (can('reports.export')): ?><a class="btn btn-outline-success" href="<?= e(url('/inventories/' . $inv['id'] . '/report', ['f' => 'all', 'export' => 'csv'])) ?>"><i class="bi bi-filetype-csv"></i></a><a class="btn btn-outline-danger" href="<?= e(url('/inventories/' . $inv['id'] . '/report', ['f' => 'all', 'export' => 'pdf'])) ?>"><i class="bi bi-filetype-pdf"></i></a><?php endif; ?>
    <?php if ($draft && can('stock.inventory_validate')): ?><form method="post" action="<?= e(url('/inventories/' . $inv['id'] . '/validate')) ?>" data-confirm="Valider l'inventaire ? Les écarts des <?= (int) $sum['counted'] ?> article(s) comptés seront appliqués au stock (définitif, tracé). Les <?= (int) $sum['uncounted'] ?> article(s) non comptés ne seront pas modifiés."><?= csrf_field() ?><button class="btn btn-success"><i class="bi bi-check2-all"></i> Valider l'inventaire</button></form><?php endif; ?>
    <?php if ($draft): ?><form method="post" action="<?= e(url('/inventories/' . $inv['id'] . '/cancel')) ?>" data-confirm="Annuler ce brouillon d'inventaire ? Aucun stock n'est modifié."><?= csrf_field() ?><button class="btn btn-outline-danger">Annuler</button></form><?php endif; ?>
  </div>
</div>

<div class="row g-2 mb-3">
  <?php foreach ([['Comptés', $sum['counted'] . ' / ' . $sum['items'], 'primary'], ['Conformes', $sum['conform'], 'success'], ['Excédents', $sum['surplus'] . ($sum['surplus'] ? ' (+' . qty($sum['surplus_qty']) . ')' : ''), 'info'], ['Manquants', $sum['missing'] . ($sum['missing'] ? ' (−' . qty($sum['missing_qty']) . ')' : ''), 'danger'], ['Non comptés', $sum['uncounted'], 'secondary']] as [$l, $v, $c]): ?>
    <div class="col-6 col-md"><div class="card border-<?= e($c) ?> h-100"><div class="card-body py-2"><div class="small text-secondary"><?= e($l) ?></div><div class="fs-5 fw-bold text-<?= e($c) ?>" data-sum="<?= e($l) ?>"><?= e((string) $v) ?></div></div></div></div>
  <?php endforeach; ?>
  <?php if ($cost): ?><div class="col-12 col-md"><div class="card h-100"><div class="card-body py-2"><div class="small text-secondary">Écart valorisé (coût)</div><div class="fs-6 fw-bold"><span class="text-success">+<?= money($sum['surplus_value']) ?></span> / <span class="text-danger">−<?= money($sum['missing_value']) ?></span></div></div></div></div><?php endif; ?>
</div>

<?php if ($draft): ?>
<div class="card mb-3 border-primary inv-scan" data-inv="<?= (int) $inv['id'] ?>">
  <div class="card-body">
    <div class="row g-2 align-items-center">
      <div class="col-lg-6">
        <label class="form-label small mb-1 fw-semibold"><i class="bi bi-upc-scan"></i> Comptage par scan — chaque lecture ajoute 1 unité</label>
        <div class="input-group">
          <input id="invScanInput" class="form-control form-control-lg" placeholder="Scannez avec la douchette (ou saisissez le code + Entrée)" autocomplete="off" inputmode="numeric">
          <button type="button" class="btn btn-primary" id="invCamera"><i class="bi bi-camera"></i> Caméra</button>
        </div>
        <div class="small text-secondary mt-1">Une lecture répétée trop rapprochée du même code par la caméra n'est pas comptée deux fois. Les quantités sont enregistrées immédiatement : vous pouvez reprendre l'inventaire plus tard, depuis plusieurs appareils.</div>
      </div>
      <div class="col-lg-6"><div class="inv-last" id="invLast"><div class="text-secondary small">Derniers scans</div></div></div>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="d-flex flex-wrap gap-1 mb-2 align-items-center">
  <?php foreach ($tabs as $k => [$l, $n]): ?><a class="btn btn-sm <?= $filter === $k ? 'btn-dark' : 'btn-outline-secondary' ?>" href="<?= e(url('/inventories/' . $inv['id'], array_filter(['f' => $k, 'q' => $q]))) ?>"><?= e($l) ?> <span class="badge bg-secondary"><?= (int) $n ?></span></a><?php endforeach; ?>
  <form method="get" class="ms-auto" style="max-width:320px"><input type="hidden" name="f" value="<?= e($filter) ?>"><div class="input-group input-group-sm"><input name="q" id="invFilterQ" value="<?= e($q) ?>" class="form-control" placeholder="Rechercher un article"><button type="button" class="btn btn-outline-secondary" data-camera-scan="#invFilterQ" data-scan-submit data-scan-products title="Caméra"><i class="bi bi-camera"></i></button><button class="btn btn-outline-secondary">Filtrer</button></div></form>
</div>

<form method="post" action="<?= e(url('/inventories/' . $inv['id'])) ?>" id="invForm"><?= csrf_field() ?>
<div class="card"><div class="table-responsive" style="max-height:62vh"><table class="table table-sm table-hover mb-0 align-middle inv-table"><thead class="sticky-top"><tr><th>Article</th><th>Code-barres</th><th class="num">Théorique</th><th style="width:130px">Compté</th><th class="num">Écart</th><th style="min-width:160px">Observation</th><th class="small">Par</th><?php if ($draft): ?><th></th><?php endif; ?></tr></thead><tbody>
<?php foreach ($items as $i): $diff = $i['counted_qty'] !== null ? (float) $i['counted_qty'] - (float) $i['expected_qty'] : null; ?>
<tr data-item="<?= (int) $i['id'] ?>" class="<?= $diff === null ? '' : (abs($diff) < 0.0005 ? 'inv-ok' : ($diff > 0 ? 'inv-plus' : 'inv-minus')) ?>">
  <td><?= e($i['name']) ?> <small class="text-secondary"><?= e($i['reference']) ?><?= $i['category'] ? ' · ' . e($i['category']) : '' ?></small></td>
  <td class="small"><?= e($i['barcode'] ?? '') ?></td>
  <td class="num" data-expected="<?= e((string) (float) $i['expected_qty']) ?>"><?= qty($i['expected_qty']) ?></td>
  <td><?php if ($draft): ?><input type="number" step="any" min="0" name="counted[<?= (int) $i['id'] ?>]" value="<?= $i['counted_qty'] !== null ? e((string) (float) $i['counted_qty']) : '' ?>" class="form-control form-control-sm" data-count><?php else: ?><?= $i['counted_qty'] !== null ? qty($i['counted_qty']) : '—' ?><?php endif; ?><?= (int) $i['scans'] ? '<div class="small text-secondary">' . (int) $i['scans'] . ' scan(s)</div>' : '' ?></td>
  <td class="num fw-semibold" data-diff><?= $diff === null ? '' : (($diff > 0 ? '+' : '') . qty($diff)) ?></td>
  <td><?php if ($draft): ?><input name="notes[<?= (int) $i['id'] ?>]" value="<?= e($i['note'] ?? '') ?>" class="form-control form-control-sm" maxlength="255" data-note placeholder="ex. carton abîmé"><?php else: ?><span class="small"><?= e($i['note'] ?? '') ?></span><?php endif; ?></td>
  <td class="small text-secondary" data-counter><?= e($i['counter'] ?? '') ?></td>
  <?php if ($draft): ?><td class="text-nowrap"><button type="button" class="btn btn-sm btn-outline-secondary" data-zero title="Article absent : quantité 0">0</button> <button type="button" class="btn btn-sm btn-outline-warning" data-reset title="Recommencer le comptage de cet article"><i class="bi bi-arrow-counterclockwise"></i></button></td><?php endif; ?>
</tr>
<?php endforeach; ?>
<?php if (!$items): ?><tr><td colspan="8" class="text-center text-secondary py-4">Aucun article dans cette vue.</td></tr><?php endif; ?>
</tbody></table></div>
<?php if ($draft): ?><div class="card-footer d-flex flex-wrap gap-2 align-items-center"><button class="btn btn-primary"><i class="bi bi-save"></i> Enregistrer</button> <span class="small text-secondary">Les saisies sont aussi enregistrées automatiquement à chaque modification. Laissez vide un article non compté : il ne sera pas ajusté. « 0 » = article absent du stock physique.</span></div><?php endif; ?>
</div></form>

<?php if ($log): ?>
<div class="card mt-3"><div class="card-header small"><i class="bi bi-clock-history"></i> Historique des comptages et corrections</div><ul class="list-group list-group-flush small">
  <?php foreach ($log as $l): ?><li class="list-group-item"><?= e(fdate($l['created_at'])) ?> · <b><?= e($l['name']) ?></b> : <?= $l['old_qty'] === null ? '—' : e(qty($l['old_qty'])) ?> → <?= $l['new_qty'] === null ? '—' : e(qty($l['new_qty'])) ?> <span class="badge bg-light text-dark border"><?= e(['scan' => 'scan', 'manual' => 'saisie', 'reset' => 'remis à zéro'][$l['method']]) ?></span> · <?= e($l['full_name']) ?></li><?php endforeach; ?>
</ul></div>
<?php endif; ?>
