<div class="row justify-content-center"><div class="col-lg-8">
<div class="card mb-3"><div class="card-body">
  <div class="d-flex justify-content-between align-items-center"><div><div class="text-secondary small">Devis <?= e($q['number']) ?> — <?= e($q['customer_name']) ?></div><div class="fs-3 fw-bold"><?= money($q['total']) ?></div></div>
  <a href="<?= e(url('/quotes/' . $q['id'])) ?>" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Retour</a></div>
  <p class="small text-secondary mt-2 mb-0">La vente reprend les articles et les prix du devis. Le stock est contrôlé et déduit, l'encaissement est enregistré dans votre caisse ouverte. <?= $q['customer_id'] ? 'Un paiement partiel laisse le reste au crédit du client (si votre rôle l\'autorise).' : 'Client de passage : le paiement doit couvrir le total (sélectionnez une fiche client sur le devis pour une vente à crédit).' ?></p>
</div></div>
<form method="post" action="<?= e(url('/quotes/' . $q['id'] . '/convert')) ?>" class="card"><div class="card-body"><?= csrf_field() ?>
  <input type="hidden" name="client_uuid" value="<?= e($uuid) ?>">
  <?php foreach ([0, 1] as $n): ?>
  <div class="row g-2 mb-2">
    <div class="col-4"><select name="payments[<?= $n ?>][method]" class="form-select"><?php foreach (payment_methods() as $k => $l): ?><?php if ($k === 'mobile_money' && mobile_money_providers()): ?><?php foreach (mobile_money_providers() as $prov): ?><option value="mobile_money|<?= e($prov) ?>"><?= e($prov) ?></option><?php endforeach; ?><?php else: ?><option value="<?= e($k) ?>" <?= $n === 1 && $k === 'card' ? 'selected' : '' ?>><?= e($l) ?></option><?php endif; ?><?php endforeach; ?></select></div>
    <div class="col-4"><input name="payments[<?= $n ?>][amount]" class="form-control text-end" inputmode="decimal" value="<?= $n === 0 ? e((string) (float) $q['total']) : '' ?>" placeholder="Montant"></div>
    <div class="col-4"><input name="payments[<?= $n ?>][reference]" class="form-control" placeholder="Réf. transaction" maxlength="100"></div>
  </div>
  <?php endforeach; ?>
  <button class="btn btn-success btn-lg w-100 mt-2"><i class="bi bi-check-circle"></i> Enregistrer la vente</button>
</div></form>
</div></div>
