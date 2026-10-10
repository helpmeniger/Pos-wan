<?php $tl = ['invoice' => 'Facture / réception', 'payment' => 'Paiement', 'return' => 'Retour', 'adjustment' => 'Ajustement']; ?>
<div class="d-flex flex-wrap gap-2 mb-3">
  <a class="btn btn-outline-secondary" href="<?= e(url('/suppliers')) ?>"><i class="bi bi-arrow-left"></i></a>
  <?php if (can('suppliers.manage')): ?><a class="btn btn-outline-primary" href="<?= e(url('/suppliers/' . $s['id'] . '/edit')) ?>"><i class="bi bi-pencil"></i> Modifier</a><?php endif; ?>
  <?php if (can('purchases.manage')): ?><a class="btn btn-primary" href="<?= e(url('/purchases/create', ['supplier' => $s['id']])) ?>"><i class="bi bi-cart-plus"></i> Commande</a><a class="btn btn-success" href="<?= e(url('/receptions/create', ['supplier' => $s['id']])) ?>"><i class="bi bi-box-arrow-in-down"></i> Réception directe</a><?php endif; ?>
</div>
<div class="row g-3">
  <div class="col-lg-4">
    <div class="card mb-3"><div class="card-body small">
      <h5><?= e($s['name']) ?></h5><?= e($s['contact_name'] ?? '') ?><br><?= e($s['phone'] ?? '') ?> <?= e($s['email'] ?? '') ?><br><?= e($s['address'] ?? '') ?><?= $s['nif'] ? '<br>NIF : ' . e($s['nif']) : '' ?>
      <div class="fs-4 mt-2">Solde dû : <b class="<?= (float) $s['balance'] > 0 ? 'text-danger' : 'text-success' ?>"><?= money($s['balance']) ?></b></div>
    </div></div>
    <?php if (can('suppliers.pay')): ?>
    <div class="card"><div class="card-header"><i class="bi bi-cash"></i> Enregistrer un paiement</div><div class="card-body">
      <form method="post" action="<?= e(url('/suppliers/' . $s['id'] . '/pay')) ?>" class="row g-2" data-confirm="Enregistrer ce paiement fournisseur ?"><?= csrf_field() ?>
        <div class="col-6"><input type="number" name="amount" min="1" step="any" class="form-control" placeholder="Montant" required></div>
        <div class="col-6"><select name="method" class="form-select"><?php foreach (payment_methods() as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-12"><input name="note" class="form-control" placeholder="Référence / note" maxlength="255"></div>
        <div class="col-12 form-check ms-1"><input type="checkbox" class="form-check-input" name="from_register" value="1" id="fr"><label class="form-check-label small" for="fr">Espèces prélevées dans ma caisse ouverte</label></div>
        <div class="col-12"><button class="btn btn-success w-100">Payer</button></div>
      </form></div></div>
    <?php endif; ?>
  </div>
  <div class="col-lg-8">
    <div class="card mb-3"><div class="card-header">Relevé de compte</div><div class="table-responsive" style="max-height:400px"><table class="table table-sm mb-0"><thead><tr><th>Date</th><th>Opération</th><th>Note</th><th>Échéance</th><th class="num">Montant</th><th class="num">Solde</th></tr></thead><tbody>
      <?php foreach ($ledger as $t): ?><tr><td><?= e(fdate($t['created_at'])) ?></td><td><?= e($tl[$t['type']] ?? $t['type']) ?> <?= $t['method'] ? '<small class="text-secondary">(' . e(method_label($t['method'])) . ')</small>' : '' ?></td><td class="small"><?= e($t['note'] ?? '') ?></td><td class="<?= $t['due_date'] && $t['due_date'] < date('Y-m-d') ? 'text-danger' : '' ?>"><?= e(fdate($t['due_date'], false)) ?></td><td class="num <?= (float) $t['amount'] < 0 ? 'text-success' : 'text-danger' ?>"><?= money($t['amount']) ?></td><td class="num"><?= money($t['balance_after']) ?></td></tr><?php endforeach; ?>
    </tbody></table></div></div>
    <div class="row g-3">
      <div class="col-md-6"><div class="card"><div class="card-header">Commandes</div><table class="table table-sm mb-0"><tbody><?php foreach ($orders as $o): ?><tr><td><a href="<?= e(url('/purchases/' . $o['id'])) ?>"><?= e($o['number']) ?></a></td><td><?= e(fdate($o['created_at'], false)) ?></td><td><?= App\Core\View::partial('partials/status', ['status' => $o['status']]) ?></td><td class="num"><?= money($o['total']) ?></td></tr><?php endforeach; ?></tbody></table></div></div>
      <div class="col-md-6"><div class="card"><div class="card-header">Réceptions</div><table class="table table-sm mb-0"><tbody><?php foreach ($receptions as $r): ?><tr><td><a href="<?= e(url('/receptions/' . $r['id'])) ?>"><?= e($r['number']) ?></a></td><td><?= e(fdate($r['created_at'], false)) ?></td><td class="num"><?= money($r['total']) ?></td></tr><?php endforeach; ?></tbody></table></div></div>
    </div>
  </div>
</div>
