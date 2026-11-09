<?php
/** Un groupe de doublons : comparaison côte à côte, choix de l'article conservé. Variables : $g, $kind, $gi */
$fmtQ = fn ($q) => rtrim(rtrim(number_format((float) $q, 3, ',', ' '), '0'), ',');
$prices = array_unique(array_map(fn ($x) => (float) $x['sale_price'], $g['list']));
?>
<form method="post" action="<?= e(url('/doublons/fusionner')) ?>" class="card mb-3 <?= $kind === 'similar' ? 'border-warning' : '' ?>"
      data-confirm="Fusionner les articles cochés dans l'article conservé ? Leurs ventes, leur stock et leur historique passent sur l'article conservé, puis ils sont supprimés. Une sauvegarde est faite avant.">
  <?= csrf_field() ?><input type="hidden" name="back" value="1"><input type="hidden" name="archives" value="<?= $arch ? '1' : '' ?>"><input type="hidden" name="ressemblants" value="<?= $withSimilar ? '1' : '0' ?>">
  <div class="card-header d-flex flex-wrap gap-2 align-items-center">
    <b><?= e($g['list'][0]['name']) ?></b>
    <span class="badge <?= $kind === 'similar' ? 'text-bg-warning' : 'text-bg-danger' ?>"><?= count($g['list']) ?> fiches · <?= $kind === 'similar' ? 'noms ressemblants, à vérifier' : 'même nom' ?></span>
    <?php if (count($prices) > 1): ?><span class="badge text-bg-light border text-danger"><i class="bi bi-exclamation-triangle"></i> prix de vente différents</span><?php endif; ?>
    <span class="ms-auto small text-secondary">Conseillé : conserver <b>#<?= (int) $g['keep'] ?></b> — <?= e($g['why']) ?></span>
  </div>
  <div class="table-responsive"><table class="table table-sm align-middle mb-0">
    <thead><tr><th>Conserver</th><th>Fusionner</th><th>Article</th><th>Catégorie</th><th class="text-end">Prix</th><th class="text-end">Stock</th><th class="text-end">Ventes</th><th>Dernière vente</th><th class="text-end">Mouvements</th><th class="text-end">Achats</th><th>Code-barres</th><th>État</th></tr></thead>
    <tbody>
    <?php foreach ($g['list'] as $a): $isKeep = $a['id'] === $g['keep']; ?>
      <tr class="<?= $isKeep ? 'table-success' : '' ?>">
        <td><input class="form-check-input" type="radio" name="keep" value="<?= (int) $a['id'] ?>" <?= $isKeep ? 'checked' : '' ?> aria-label="Conserver #<?= (int) $a['id'] ?>"></td>
        <td><input class="form-check-input" type="checkbox" name="merge[]" value="<?= (int) $a['id'] ?>" <?= !$isKeep && $kind === 'same' ? 'checked' : '' ?> aria-label="Fusionner #<?= (int) $a['id'] ?>"></td>
        <td><a href="<?= e(url('/products/' . $a['id'])) ?>" target="_blank" rel="noopener"><?= e($a['name']) ?></a><div class="small text-secondary">#<?= (int) $a['id'] ?> · <?= e((string) $a['reference']) ?><?= $a['image'] ? ' · <i class="bi bi-image"></i>' : '' ?></div></td>
        <td class="small"><?= e((string) ($a['category'] ?? '—')) ?></td>
        <td class="text-end text-nowrap <?= count($prices) > 1 ? 'fw-bold' : '' ?>"><?= e(money($a['sale_price'])) ?></td>
        <td class="text-end"><?= e($fmtQ($a['stock'])) ?></td>
        <td class="text-end"><?= (int) $a['sales'] ?><?= $a['sales'] ? '<div class="small text-secondary">' . e($fmtQ($a['sold_qty'])) . ' vendus</div>' : '' ?></td>
        <td class="small"><?= $a['last_sale'] ? e(fdate($a['last_sale'])) : '<span class="text-secondary">jamais</span>' ?></td>
        <td class="text-end"><?= (int) $a['moves'] ?></td>
        <td class="text-end"><?= (int) $a['purchases'] ?></td>
        <td class="small"><?= $a['barcode'] ? e((string) $a['barcode']) : '<span class="text-secondary">—</span>' ?></td>
        <td><?= $a['status'] === 'active' ? '<span class="badge text-bg-success">actif</span>' : '<span class="badge text-bg-secondary">archivé</span>' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
  <div class="card-footer d-flex flex-wrap gap-2 align-items-center small">
    <span class="text-secondary"><i class="bi bi-info-circle"></i> Les articles cochés « Fusionner » sont fondus dans l'article « Conserver » : stock additionné, ventes et historique rattachés, code-barres et photo repris s'il n'en a pas. Son nom et son prix de vente sont gardés.</span>
    <button class="btn btn-sm btn-danger ms-auto"><i class="bi bi-intersect"></i> Fusionner</button>
  </div>
</form>
