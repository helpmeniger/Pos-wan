<?php
$typeLabels = ['sale' => 'Ventes', 'sale_cancel' => 'Annulations', 'refund' => 'Remboursements', 'cash_in' => 'Apports', 'cash_out' => 'Retraits', 'expense' => 'Dépenses', 'income' => 'Recettes diverses', 'customer_payment' => 'Règlements clients', 'supplier_payment' => 'Paiements fournisseurs', 'expense_reversal' => 'Contrepassation dépense', 'income_reversal' => 'Contrepassation recette'];
?>
<?php if (!$register): ?>
  <div class="alert alert-warning">Aucune caisse n'est associée à votre compte. Un administrateur doit vous en affecter une (menu Utilisateurs).</div>
<?php elseif (!$session): ?>
  <div class="row g-3">
    <div class="col-lg-5"><div class="card"><div class="card-header"><i class="bi bi-unlock"></i> Ouvrir <?= e($register['name']) ?> — <?= e($register['store_name']) ?></div><div class="card-body">
      <form method="post" action="<?= e(url('/cash/open')) ?>"><?= csrf_field() ?>
        <label class="form-label required">Fond de caisse compté</label>
        <div class="input-group input-group-lg mb-3"><input type="number" name="opening_amount" min="0" step="1" class="form-control text-end" required autofocus><span class="input-group-text"><?= e(currency()['symbol']) ?></span></div>
        <button class="btn btn-success btn-lg w-100"><i class="bi bi-unlock"></i> Ouvrir la caisse</button></form>
    </div></div></div>
    <div class="col-lg-7"><div class="card"><div class="card-header">Dernières clôtures</div><table class="table table-sm mb-0"><thead><tr><th>Ouverture</th><th>Clôture</th><th class="num">Théorique</th><th class="num">Compté</th><th class="num">Écart</th><th></th></tr></thead><tbody>
      <?php foreach ($last as $l): ?><tr><td><?= e(fdate($l['opened_at'])) ?></td><td><?= e(fdate($l['closed_at'])) ?></td><td class="num"><?= money($l['expected_amount']) ?></td><td class="num"><?= money($l['counted_amount']) ?></td><td class="num <?= abs((float) $l['difference']) >= 1 ? 'text-danger fw-bold' : 'text-success' ?>"><?= money($l['difference']) ?></td><td><a href="<?= e(url('/cash/sessions/' . $l['id'])) ?>"><i class="bi bi-printer"></i></a></td></tr><?php endforeach; ?>
    </tbody></table></div></div>
  </div>
<?php else: ?>
  <div class="row g-3 mb-3">
    <div class="col-md-3"><div class="kpi bg-g2"><div class="kpi-label">Fond de caisse</div><div class="kpi-value"><?= money($session['opening_amount']) ?></div><span class="small">Ouverte le <?= e(fdate($session['opened_at'])) ?> par <?= e($opener) ?></span></div></div>
    <div class="col-md-3"><div class="kpi bg-g1"><div class="kpi-label">Ventes de la session</div><div class="kpi-value"><?= money($summary['sales']['total']) ?></div><span class="small"><?= (int) $summary['sales']['n'] ?> ticket(s) · <?= (int) $summary['cancelled'] ?> annulé(s)</span></div></div>
    <div class="col-md-3"><div class="kpi bg-g4"><div class="kpi-label">Ventes à crédit</div><div class="kpi-value"><?= money($summary['sales']['credit']) ?></div></div></div>
    <div class="col-md-3"><div class="kpi bg-g3"><div class="kpi-label">Espèces théoriques</div><div class="kpi-value"><?= money($summary['expected_cash']) ?></div></div></div>
  </div>
  <div class="row g-3">
    <div class="col-lg-7">
      <div class="card mb-3"><div class="card-header">Répartition par type et mode</div><table class="table table-sm mb-0"><thead><tr><th>Opération</th><th>Mode</th><th class="num">Nb</th><th class="num">Montant</th></tr></thead><tbody>
        <?php foreach ($summary['by_type'] as $t): ?><tr><td><?= e($typeLabels[$t['type']] ?? $t['type']) ?></td><td><?= e(method_label($t['method'])) ?></td><td class="num"><?= (int) $t['n'] ?></td><td class="num <?= (float) $t['total'] < 0 ? 'text-danger' : '' ?>"><?= money($t['total']) ?></td></tr><?php endforeach; ?>
      </tbody></table></div>
      <div class="card"><div class="card-header">Derniers mouvements</div><div class="table-responsive" style="max-height:380px"><table class="table table-sm mb-0"><tbody>
        <?php foreach ($movements as $m): ?><tr><td><?= e(fdate($m['created_at'])) ?></td><td><?= e($typeLabels[$m['type']] ?? $m['type']) ?></td><td><?= e(method_label($m['method'])) ?></td><td class="small"><?= e($m['reason']) ?></td><td class="num <?= (float) $m['amount'] < 0 ? 'text-danger' : '' ?>"><?= money($m['amount']) ?></td></tr><?php endforeach; ?>
      </tbody></table></div></div>
    </div>
    <div class="col-lg-5">
      <?php if (can('cash.movements')): ?>
      <div class="card mb-3"><div class="card-header"><i class="bi bi-arrow-down-up"></i> Entrée / sortie d'espèces</div><div class="card-body">
        <form method="post" action="<?= e(url('/cash/movement')) ?>" class="row g-2"><?= csrf_field() ?>
          <div class="col-5"><select name="direction" class="form-select"><option value="out">Sortie (retrait, dépôt banque)</option><option value="in">Entrée (apport)</option></select></div>
          <div class="col-7"><input type="number" name="amount" min="1" step="1" class="form-control" placeholder="Montant" required></div>
          <div class="col-12"><input name="reason" class="form-control" placeholder="Motif (obligatoire)" required maxlength="255"></div>
          <div class="col-12"><button class="btn btn-outline-primary w-100">Enregistrer</button></div>
        </form></div></div>
      <?php endif; ?>
      <div class="card border-danger"><div class="card-header text-danger"><i class="bi bi-lock"></i> Clôture de caisse</div><div class="card-body">
        <form method="post" action="<?= e(url('/cash/close')) ?>" data-confirm="Clôturer la caisse ? Les ventes seront bloquées jusqu'à la prochaine ouverture."><?= csrf_field() ?>
          <p class="small text-secondary mb-2">Comptez les espèces par coupure (ou saisissez directement le total).</p>
          <div class="row g-1 mb-2">
            <?php foreach ([10000, 5000, 2000, 1000, 500, 250, 200, 100, 50, 25, 10, 5] as $d): ?>
              <div class="col-4"><div class="input-group input-group-sm"><span class="input-group-text" style="min-width:64px"><?= number_format($d, 0, ',', ' ') ?></span><input type="number" min="0" name="denom[<?= $d ?>]" class="form-control" placeholder="0"></div></div>
            <?php endforeach; ?>
          </div>
          <label class="form-label">ou total compté</label>
          <input type="number" name="counted_amount" min="0" step="1" class="form-control mb-2" placeholder="Montant total compté">
          <input name="note" class="form-control mb-2" maxlength="255" placeholder="Commentaire (obligatoire en cas d'écart)">
          <button class="btn btn-danger w-100"><i class="bi bi-lock"></i> Clôturer et imprimer l'état</button>
        </form>
      </div></div>
    </div>
  </div>
<?php endif; ?>
