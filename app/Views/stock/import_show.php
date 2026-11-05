<?php $st = ['applied' => ['success', 'Appliquée'], 'skipped' => ['secondary', 'Sans changement'], 'error' => ['danger', 'Refusée']]; ?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
  <div><a href="<?= e(url('/stock/import')) ?>" class="small"><i class="bi bi-arrow-left"></i> Imports</a>
    <div class="small text-secondary mt-1">Le <?= e(fdate($i['created_at'])) ?> par <b><?= e($i['user_name']) ?></b> · <?= $i['mode'] === 'add' ? 'quantités ajoutées' : 'stock compté (écart calculé)' ?> · entrepôt par défaut : <?= e($i['warehouse'] ?? '—') ?></div>
    <div class="small text-secondary">Fichier : <b><?= e($i['file_name']) ?></b> · SHA-256 <code><?= e(substr($i['sha256'], 0, 16)) ?>…</code><?= $i['note'] ? ' · « ' . e($i['note']) . ' »' : '' ?></div></div>
  <div class="d-flex gap-2"><?php if (can('stock.adjust')): ?><a href="<?= e(url('/stock/imports/' . $i['id'] . '/file')) ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-download"></i> Fichier d'origine</a><?php endif; ?>
    <a href="<?= e(url('/stock/movements')) ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-arrow-left-right"></i> Mouvements de stock</a></div>
</div>
<div class="d-flex gap-2 mb-2"><span class="badge text-bg-success fs-6"><?= (int) $i['rows_applied'] ?> appliquée(s)</span><span class="badge text-bg-secondary fs-6"><?= (int) $i['rows_skipped'] ?> ignorée(s)</span></div>
<div class="card"><div class="table-responsive"><table class="table table-sm table-hover mb-0 align-middle"><thead class="table-light"><tr><th>Ligne</th><th>Article</th><th>Entrepôt</th><th class="text-end">Avant</th><th class="text-end">Variation</th><th class="text-end">Après</th><th>État</th></tr></thead><tbody>
<?php foreach ($rows as $r): [$c, $l] = $st[$r['status']]; ?>
  <tr><td><?= (int) $r['line_no'] ?></td><td><?= $r['product_id'] ? '<a href="' . e(url('/products/' . $r['product_id'])) . '">' . e($r['product']) . '</a>' : e($r['identifier']) ?><div class="small text-secondary"><?= e($r['reference'] ?? $r['identifier']) ?></div></td>
    <td class="small"><?= e($r['warehouse'] ?? '') ?></td><td class="text-end"><?= $r['qty_before'] !== null ? qty($r['qty_before']) : '' ?></td>
    <td class="text-end fw-semibold <?= (float) $r['qty_change'] > 0 ? 'text-success' : 'text-danger' ?>"><?= $r['status'] === 'applied' ? ((float) $r['qty_change'] > 0 ? '+' : '') . qty($r['qty_change']) : '' ?></td>
    <td class="text-end"><?= $r['qty_after'] !== null ? qty($r['qty_after']) : '' ?></td>
    <td><span class="badge text-bg-<?= $c ?>"><?= $l ?></span> <span class="small text-secondary"><?= e($r['message'] ?? '') ?></span></td></tr>
<?php endforeach; ?>
</tbody></table></div></div>
