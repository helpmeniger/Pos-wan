<?php $tl = ['credit_sale' => 'Vente à crédit', 'payment' => 'Règlement', 'deposit' => 'Acompte', 'refund' => 'Avoir / remboursement', 'adjustment' => 'Ajustement']; ?>
<div class="d-flex flex-wrap gap-2 mb-3">
  <a class="btn btn-outline-secondary" href="<?= e(url('/customers')) ?>"><i class="bi bi-arrow-left"></i></a>
  <?php if (can('customers.manage')): ?><a class="btn btn-outline-primary" href="<?= e(url('/customers/' . $c['id'] . '/edit')) ?>"><i class="bi bi-pencil"></i> Modifier</a><?php endif; ?>
  <a class="btn btn-outline-secondary" target="_blank" rel="noopener" href="<?= e(url('/customers/' . $c['id'] . '/statement')) ?>"><i class="bi bi-printer"></i> Relevé de compte</a>
</div>
<div class="row g-3">
  <div class="col-lg-4">
    <div class="card mb-3"><div class="card-body">
      <h5><?= e($c['name']) ?></h5><div class="small"><?= e($c['phone'] ?? '') ?> <?= e($c['email'] ?? '') ?><br><?= e($c['address'] ?? '') ?></div>
      <div class="fs-4 mt-2">Solde : <b class="<?= (float) $c['balance'] > 0 ? 'text-danger' : 'text-success' ?>"><?= money($c['balance']) ?></b></div>
      <div class="small text-secondary">Plafond de crédit : <?= money($c['credit_limit']) ?><?= feature('loyalty') ? ' · Points fidélité : ' . (int) $c['loyalty_points'] : '' ?></div>
      <div class="small text-secondary"><?= (int) $stats['n'] ?> achat(s) pour <?= money($stats['total']) ?></div>
    </div></div>
    <?php if (can('customers.payments')): ?>
    <div class="card"><div class="card-header"><i class="bi bi-cash"></i> Encaisser</div><div class="card-body">
      <form method="post" action="<?= e(url('/customers/' . $c['id'] . '/payment')) ?>" class="row g-2"><?= csrf_field() ?>
        <div class="col-12"><select name="type" class="form-select form-select-sm"><option value="payment">Règlement de dette</option><option value="deposit">Acompte (avoir)</option></select></div>
        <div class="col-6"><input type="number" name="amount" min="1" step="any" class="form-control" placeholder="Montant" required value="<?= (float) $c['balance'] > 0 ? e((float) $c['balance']) : '' ?>"></div>
        <div class="col-6"><select name="method" class="form-select"><?php foreach (payment_methods() as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-12"><input name="note" class="form-control" placeholder="Note / référence" maxlength="255"></div>
        <div class="col-12"><button class="btn btn-success w-100">Encaisser et imprimer le reçu</button></div>
      </form></div></div>
    <?php endif; ?>
  </div>
  <div class="col-lg-8">
    <div class="card mb-3"><div class="card-header">Compte client</div><div class="table-responsive" style="max-height:380px"><table class="table table-sm mb-0"><thead><tr><th>Date</th><th>Opération</th><th>Note</th><th>Échéance</th><th class="num">Montant</th><th class="num">Solde</th><th></th></tr></thead><tbody>
      <?php foreach ($ledger as $t): ?><tr><td><?= e(fdate($t['created_at'])) ?></td><td><?= e($tl[$t['type']] ?? $t['type']) ?></td><td class="small"><?= e($t['note'] ?? '') ?></td><td class="<?= $t['due_date'] && $t['due_date'] < date('Y-m-d') && (float) $c['balance'] > 0 ? 'text-danger fw-bold' : '' ?>"><?= e(fdate($t['due_date'], false)) ?></td><td class="num <?= (float) $t['amount'] > 0 ? 'text-danger' : 'text-success' ?>"><?= money($t['amount']) ?></td><td class="num"><?= money($t['balance_after']) ?></td><td><?php if (in_array($t['type'], ['payment', 'deposit'], true)): ?><a href="<?= e(url('/customer-payments/' . $t['id'])) ?>" target="_blank" rel="noopener"><i class="bi bi-printer"></i></a><?php endif; ?></td></tr><?php endforeach; ?>
      <?php if (!$ledger): ?><tr><td colspan="7" class="text-secondary">Aucune opération.</td></tr><?php endif; ?>
    </tbody></table></div></div>
    <div class="card"><div class="card-header">Historique des achats</div><table class="table table-sm mb-0"><tbody>
      <?php foreach ($sales as $s): ?><tr><td><a href="<?= e(url('/sales/' . $s['id'])) ?>"><?= e($s['number']) ?></a></td><td><?= e(fdate($s['created_at'])) ?></td><td><?= App\Core\View::partial('partials/status', ['status' => $s['status']]) ?></td><td class="num text-danger"><?= (float) $s['credit_amount'] > 0 ? 'crédit ' . money($s['credit_amount']) : '' ?></td><td class="num"><?= money($s['total']) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
  </div>
</div>
