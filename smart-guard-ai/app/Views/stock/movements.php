<?php if (can('stock.manage')): ?>
<div class="card mb-3"><div class="card-header bg-white">Nouveau mouvement</div><div class="card-body">
<form method="post" action="<?= url('stock/movements') ?>" class="row g-2"><?= csrf_field() ?>
  <div class="col-md-3"><select name="product_id" class="form-select form-select-sm" required><option value="">Produit…</option><?php foreach ($products as $p): ?><option value="<?= (int) $p['id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-3"><select name="movement_type" class="form-select form-select-sm" required>
    <?php foreach (['reception', 'retour_client', 'retour_fournisseur', 'transfert', 'casse', 'perime', 'ajustement', 'sortie_autorisee', 'mouvement_observe', 'mouvement_non_explique'] as $t): ?><option value="<?= $t ?>"><?= e(label('movement', $t)) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-2"><input name="quantity" type="number" step="0.001" class="form-control form-control-sm" placeholder="Quantité" required></div>
  <div class="col-md-2"><input name="reference" class="form-control form-control-sm" placeholder="Référence (BL…)" maxlength="80"></div>
  <div class="col-md-2"><input name="occurred_at" type="datetime-local" class="form-control form-control-sm"></div>
  <div class="col-md-3"><input name="from_zone" class="form-control form-control-sm" placeholder="Zone d'origine (transfert)" maxlength="60"></div>
  <div class="col-md-3"><input name="to_zone" class="form-control form-control-sm" placeholder="Zone de destination (transfert)" maxlength="60"></div>
  <div class="col-md-4"><input name="comment" class="form-control form-control-sm" placeholder="Commentaire" maxlength="500"></div>
  <div class="col-md-2"><button class="btn btn-primary btn-sm w-100">Enregistrer</button></div>
  <div class="col-12 form-text">Saisissez une quantité positive : le sens (entrée/sortie) est déterminé par le type. Pour « Ajustement », une valeur négative diminue le stock. Un transfert entre zones ne modifie pas le stock total.</div>
</form></div></div>
<?php endif; ?>
<form class="row g-2 mb-3" method="get">
  <div class="col-6 col-md-2"><input type="date" name="from" class="form-control form-control-sm" value="<?= e($from) ?>"></div>
  <div class="col-6 col-md-2"><input type="date" name="to" class="form-control form-control-sm" value="<?= e($to) ?>"></div>
  <div class="col-md-3"><select name="product_id" class="form-select form-select-sm"><option value="">Tous les produits</option><?php foreach ($products as $p): ?><option value="<?= (int) $p['id'] ?>" <?= (int) ($_GET['product_id'] ?? 0) === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-3"><select name="type" class="form-select form-select-sm"><option value="">Tous les types</option><?php foreach (array_keys(App\Services\StockService::TYPES) as $t): ?><option value="<?= $t ?>" <?= ($_GET['type'] ?? '') === $t ? 'selected' : '' ?>><?= e(label('movement', $t)) ?></option><?php endforeach; ?></select></div>
  <div class="col-auto"><button class="btn btn-sm btn-outline-primary">Filtrer</button></div>
</form>
<div class="card"><div class="table-responsive"><table class="table table-sm mb-0">
<thead><tr><th>Date</th><th>Produit</th><th>Type</th><th class="text-end">Quantité</th><th class="text-end">Avant → après</th><th>Référence</th><th>Utilisateur</th><th>Commentaire</th></tr></thead><tbody>
<?php foreach ($rows as $m): ?><tr><td class="small"><?= dt($m['occurred_at']) ?></td><td><?= e($m['product']) ?></td><td><?= e(label('movement', $m['movement_type'])) ?></td>
  <td class="text-end <?= (float) $m['quantity'] < 0 ? 'text-danger' : 'text-success' ?>"><?= ((float) $m['quantity'] > 0 ? '+' : '') . qty($m['quantity']) ?></td>
  <td class="text-end small"><?= qty($m['stock_before']) ?> → <?= qty($m['stock_after']) ?></td><td class="small"><?= e($m['reference']) ?></td><td class="small"><?= e($m['user']) ?></td><td class="small"><?= e($m['comment']) ?></td></tr>
<?php endforeach; if (!$rows): ?><tr><td colspan="8" class="text-muted text-center py-3">Aucun mouvement.</td></tr><?php endif; ?>
</tbody></table></div></div>
<?php require __DIR__ . '/../partials/pagination.php'; ?>
