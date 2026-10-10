<?php $old = fn($k, $d = '') => e(App\Core\Session::old($k, (string) $d)); ?>
<form method="post" action="<?= e(url('/restaurant/orders/' . $o['id'] . '/pay')) ?>" id="payForm"><?= csrf_field() ?>
<input type="hidden" name="client_uuid" value="<?= e($uuid) ?>">
<div class="row g-3">
  <div class="col-xl-7">
    <div class="card"><div class="card-header d-flex align-items-center"><span><i class="bi bi-list-check"></i> Articles à encaisser</span>
      <div class="ms-auto btn-group btn-group-sm"><button type="button" class="btn btn-outline-primary" data-select="all">Tout</button><button type="button" class="btn btn-outline-secondary" data-select="none">Rien</button>
      <button type="button" class="btn btn-outline-secondary" data-split title="Diviser en parts égales">÷ parts</button></div></div>
      <div class="table-responsive"><table class="table table-sm mb-0 align-middle"><thead><tr><th>Article</th><th class="num">Reste</th><th class="num">P.U.</th><th style="width:130px">À payer</th><th class="num">Montant</th></tr></thead><tbody>
      <?php foreach ($o['items'] as $i): $left = (float) $i['quantity'] - (float) $i['paid_qty']; if ($i['status'] === 'cancelled' || $left <= 0.0005) continue; ?>
        <tr data-price="<?= e((float) $i['unit_price']) ?>"><td><?= e($i['name']) ?><?= $i['note'] ? '<div class="small text-secondary">' . e($i['note']) . '</div>' : '' ?></td><td class="num"><?= qty($left) ?></td><td class="num"><?= money($i['unit_price']) ?></td>
        <td><div class="input-group input-group-sm"><button type="button" class="btn btn-outline-secondary" data-step="-1">−</button><input type="number" step="any" min="0" max="<?= e($left) ?>" name="qty[<?= (int) $i['id'] ?>]" value="<?= e($left) ?>" data-max="<?= e($left) ?>" class="form-control text-center" data-q><button type="button" class="btn btn-outline-secondary" data-step="1">+</button></div></td>
        <td class="num fw-semibold" data-line></td></tr>
      <?php endforeach; ?>
      </tbody></table></div>
      <div class="card-footer small text-secondary">Addition séparée : ne cochez que la part de chaque client ; la commande reste ouverte jusqu'au paiement complet. Déjà payé : <?= money($o['paid']) ?>.</div>
    </div>
  </div>
  <div class="col-xl-5">
    <div class="card mb-3"><div class="card-body">
      <div class="pay-summary d-flex justify-content-between align-items-baseline"><span>Sélection</span><b class="fs-5" id="selTotal"><?= e(money(0)) ?></b></div>
      <div class="row g-2 mt-1">
        <div class="col-7"><select name="discount_type" class="form-select form-select-sm"><option value="amount">Remise en <?= e(currency()['symbol']) ?></option><option value="percent" <?= App\Core\Session::old('discount_type') === 'percent' ? 'selected' : '' ?>>Remise en %</option></select></div>
        <div class="col-5"><input type="number" step="any" min="0" name="discount_value" value="<?= $old('discount_value', '0') ?>" class="form-control form-control-sm" data-disc></div>
      </div>
      <div class="d-flex justify-content-between align-items-baseline mt-2 border-top pt-2"><span class="fs-5">Total à payer</span><b class="fs-3 text-success" id="toPay"><?= e(money(0)) ?></b></div>
    </div></div>
    <div class="card mb-3"><div class="card-header"><i class="bi bi-wallet2"></i> Paiement</div><div class="card-body">
      <?php foreach ([0, 1, 2] as $n): ?>
      <div class="row g-1 mb-1 pay-row">
        <div class="col-4"><select name="payments[<?= $n ?>][method]" class="form-select form-select-sm"><?php foreach (payment_methods() as $k => $l): ?><?php if ($k === 'mobile_money' && mobile_money_providers()): ?><?php foreach (mobile_money_providers() as $pi => $prov): ?><option value="mobile_money|<?= e($prov) ?>" <?= $n === 1 && $pi === 0 ? 'selected' : '' ?>><?= e($prov) ?></option><?php endforeach; ?><?php else: ?><option value="<?= e($k) ?>" <?= ($n === 1 && $k === 'mobile_money') || ($n === 2 && $k === 'card') ? 'selected' : '' ?>><?= e($l) ?></option><?php endif; ?><?php endforeach; ?></select></div>
        <div class="col-4"><input type="number" step="any" min="0" name="payments[<?= $n ?>][amount]" class="form-control form-control-sm" placeholder="Montant" data-amount <?= $n === 0 ? 'autofocus' : '' ?>></div>
        <div class="col-4"><input name="payments[<?= $n ?>][reference]" class="form-control form-control-sm" placeholder="Réf." maxlength="100"></div>
      </div>
      <?php endforeach; ?>
      <div class="d-flex flex-wrap gap-1 my-2" id="quick"></div>
      <div class="d-flex justify-content-between"><span>Reçu</span><b id="received"><?= e(money(0)) ?></b></div>
      <div class="d-flex justify-content-between fs-4"><span>À rendre</span><b class="text-success" id="change"><?= e(money(0)) ?></b></div>
      <div class="d-flex justify-content-between text-danger d-none" id="restRow"><span>Reste (crédit client)</span><b id="rest"><?= e(money(0)) ?></b></div>
      <hr>
      <label class="form-label small">Client (obligatoire pour une vente à crédit)</label>
      <select name="customer_id" class="form-select form-select-sm"><option value="">— <?= e($o['customer'] ?? $o['customer_name'] ?? 'Client comptoir') ?></option>
        <?php foreach (App\Core\DB::all('SELECT id, name, phone FROM customers WHERE active = 1 ORDER BY name LIMIT 500') as $c): ?><option value="<?= (int) $c['id'] ?>" <?= (int) $o['customer_id'] === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name'] . ' ' . ($c['phone'] ?? '')) ?></option><?php endforeach; ?>
      </select>
      <details class="mt-2 small" <?= App\Core\Session::old('sup_username') !== '' ? 'open' : '' ?>><summary>Autorisation d'un responsable (remise élevée, crédit…)</summary>
        <div class="row g-1 mt-1"><div class="col-6"><input name="sup_username" class="form-control form-control-sm" placeholder="Identifiant" autocomplete="off"></div><div class="col-6"><input type="password" name="sup_secret" class="form-control form-control-sm" placeholder="Mot de passe / PIN" autocomplete="off"></div></div>
      </details>
    </div></div>
    <div class="d-grid gap-2">
      <button class="btn btn-success btn-lg"><i class="bi bi-check2-circle"></i> Valider le paiement</button>
      <a class="btn btn-light" href="<?= e(url('/restaurant/orders/' . $o['id'])) ?>">Retour à la commande</a>
    </div>
  </div>
</div>
</form>
