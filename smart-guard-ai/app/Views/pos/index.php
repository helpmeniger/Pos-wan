<div class="row g-3">
<div class="col-lg-7">
  <div class="card mb-3"><div class="card-body">
    <label class="form-label">Code-barres ou nom du produit</label>
    <input id="posSearch" class="form-control form-control-lg" placeholder="Scannez ou tapez puis Entrée" autocomplete="off" autofocus>
    <div id="posResults" class="list-group mt-2"></div>
  </div></div>
  <div class="d-flex gap-2"><a href="<?= url('pos/sales') ?>" class="btn btn-light"><i class="bi bi-receipt me-1"></i>Ventes</a>
  <?php if (can('sales.import')): ?><a href="<?= url('pos/import') ?>" class="btn btn-light"><i class="bi bi-upload me-1"></i>Importer des ventes</a><?php endif; ?></div>
</div>
<div class="col-lg-5">
  <div class="card"><div class="card-header bg-white">Panier</div>
  <div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Article</th><th style="width:90px">Qté</th><th class="text-end">Total</th><th></th></tr></thead><tbody id="cart"></tbody></table></div>
  <div class="card-body border-top">
    <div class="d-flex justify-content-between fs-5 fw-bold mb-2"><span>Total</span><span id="cartTotal">0 FCFA</span></div>
    <div class="row g-2 mb-2">
      <div class="col-6"><select id="payMethod" class="form-select"><?php foreach (App\Services\SaleService::PAYMENTS as $pm): ?><option value="<?= $pm ?>"><?= e(label('payment', $pm)) ?></option><?php endforeach; ?></select></div>
      <div class="col-6"><input id="payAmount" type="number" min="0" class="form-control" placeholder="Montant reçu"></div>
    </div>
    <div class="small text-muted mb-2" id="change"></div>
    <button class="btn btn-success btn-lg w-100" id="btnPay" disabled><i class="bi bi-check2-circle me-1"></i>Valider la vente</button>
    <div id="posMsg" class="small mt-2"></div>
  </div></div>
</div>
</div>
