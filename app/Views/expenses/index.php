<div class="row g-3">
  <div class="col-xl-8">
    <div class="card mb-3"><div class="card-body py-2"><form class="row g-2 align-items-end" method="get">
      <?= App\Core\View::partial('partials/period', compact('from', 'to')) ?>
      <div class="col-auto"><label class="form-label small mb-0">Type</label><select name="type" class="form-select form-select-sm"><option value="">Tous</option><option value="expense" <?= ($_GET['type'] ?? '') === 'expense' ? 'selected' : '' ?>>Dépenses</option><option value="income" <?= ($_GET['type'] ?? '') === 'income' ? 'selected' : '' ?>>Recettes</option></select></div>
      <div class="col-auto"><button class="btn btn-sm btn-primary">Filtrer</button></div>
      <div class="col-auto ms-auto"><a class="btn btn-sm btn-outline-success" href="<?= e(query_with(['export' => 'csv'])) ?>"><i class="bi bi-filetype-csv"></i> Exporter</a> <a class="btn btn-sm btn-outline-danger" href="<?= e(query_with(['export' => 'pdf'])) ?>"><i class="bi bi-filetype-pdf"></i> PDF</a></div>
    </form></div></div>
    <div class="card"><div class="table-responsive"><table class="table table-sm table-hover mb-0"><thead><tr><th>N°</th><th>Date</th><th>Catégorie</th><th>Description</th><th>Mode</th><th>Saisi par</th><th class="num">Montant</th><th></th></tr></thead><tbody>
    <?php foreach ($p['rows'] as $x): ?><tr class="<?= $x['status'] === 'reversed' ? 'text-decoration-line-through text-secondary' : '' ?>"><td class="small"><?= e($x['number']) ?></td><td><?= e(fdate($x['expense_date'], false)) ?></td><td><?= e($x['category']) ?></td><td><?= e($x['description']) ?></td><td><?= e(method_label($x['method'])) ?><?= $x['cash_session_id'] ? ' <i class="bi bi-safe2" title="Caisse"></i>' : '' ?></td><td class="small"><?= e($x['full_name']) ?></td>
    <td class="num fw-semibold <?= $x['type'] === 'expense' ? 'text-danger' : 'text-success' ?>"><?= $x['type'] === 'expense' ? '−' : '+' ?><?= money($x['amount']) ?></td>
    <td><?php if ($x['status'] === 'valid' && can('expenses.reverse')): ?><form method="post" action="<?= e(url('/expenses/' . $x['id'] . '/reverse')) ?>" data-confirm="Contrepasser cette opération ? Une écriture inverse sera créée." data-reason="1"><?= csrf_field() ?><button class="btn btn-sm btn-link text-danger p-0" title="Contrepasser"><i class="bi bi-arrow-return-left"></i></button></form><?php endif; ?></td></tr><?php endforeach; ?>
    <?php if (!$p['rows']): ?><tr><td colspan="8" class="text-center text-secondary py-4">Aucune opération.</td></tr><?php endif; ?>
    </tbody></table></div></div>
    <?= App\Core\View::partial('partials/pagination', ['p' => $p]) ?>
  </div>
  <div class="col-xl-4">
    <div class="card mb-3"><div class="card-header"><i class="bi bi-plus-circle"></i> Nouvelle opération</div><div class="card-body">
      <form method="post" action="<?= e(url('/expenses')) ?>" class="row g-2"><?= csrf_field() ?>
        <div class="col-12"><div class="btn-group w-100"><input type="radio" class="btn-check" name="type" value="expense" id="tE" checked><label class="btn btn-outline-danger" for="tE">Dépense</label><input type="radio" class="btn-check" name="type" value="income" id="tI"><label class="btn btn-outline-success" for="tI">Recette</label></div></div>
        <div class="col-12"><select name="category_id" class="form-select" required><option value="">Catégorie…</option><?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?> (<?= $c['type'] === 'expense' ? 'dépense' : 'recette' ?>)</option><?php endforeach; ?></select></div>
        <div class="col-6"><input type="number" name="amount" min="1" step="any" class="form-control" placeholder="Montant" required></div>
        <div class="col-6"><input type="date" name="expense_date" value="<?= e(date('Y-m-d')) ?>" max="<?= e(date('Y-m-d')) ?>" class="form-control" required></div>
        <div class="col-12"><input name="description" class="form-control" placeholder="Description (bénéficiaire, objet…)" required maxlength="255"></div>
        <div class="col-6"><select name="method" class="form-select"><?php foreach (payment_methods() as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 form-check mt-2 ps-4"><input type="checkbox" class="form-check-input" name="from_register" value="1" id="fr" checked><label class="form-check-label small" for="fr">Via ma caisse ouverte</label></div>
        <div class="col-12"><button class="btn btn-primary w-100">Enregistrer</button></div>
      </form></div></div>
    <div class="card mb-3"><div class="card-header">Synthèse par catégorie</div><table class="table table-sm mb-0"><tbody>
      <?php foreach ($byCat as $b): ?><tr><td><?= e($b['name']) ?></td><td class="num <?= $b['type'] === 'expense' ? 'text-danger' : 'text-success' ?>"><?= money($b['total']) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <div class="card"><div class="card-body"><form method="post" action="<?= e(url('/expense-categories')) ?>" class="row g-1"><?= csrf_field() ?><div class="col-6"><input name="name" class="form-control form-control-sm" placeholder="Nouvelle catégorie" required maxlength="100"></div><div class="col-3"><select name="type" class="form-select form-select-sm"><option value="expense">Dépense</option><option value="income">Recette</option></select></div><div class="col-3"><button class="btn btn-sm btn-outline-primary w-100">Ajouter</button></div></form></div></div>
  </div>
</div>
