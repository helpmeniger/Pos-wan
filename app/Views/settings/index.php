<div class="row g-3">
  <div class="col-xl-7">
    <form method="post" action="<?= e(url('/settings')) ?>"><?= csrf_field() ?>
    <div class="card mb-3"><div class="card-header"><i class="bi bi-building"></i> Entreprise, documents et règles de gestion</div><div class="card-body row g-3">
      <?php foreach ($fields as $key => $f): [$label, $type] = $f; $val = (string) setting($key, ''); ?>
        <?php if ($type === 'bool'): ?>
          <div class="col-md-6"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="<?= e($key) ?>" value="1" id="s_<?= e($key) ?>" <?= $val === '1' ? 'checked' : '' ?>><label class="form-check-label" for="s_<?= e($key) ?>"><?= e($label) ?></label></div></div>
        <?php elseif ($type === 'textarea'): ?>
          <div class="col-md-6"><label class="form-label"><?= e($label) ?></label><textarea name="<?= e($key) ?>" class="form-control" rows="2" maxlength="1000"><?= e($val) ?></textarea></div>
        <?php elseif ($type === 'select'): $opts = $f[2] === 'LANG' ? array_map(fn($l) => $l[0], App\Core\Lang::LANGUAGES) : $f[2]; ?>
          <div class="col-md-6"><label class="form-label"><?= e($label) ?><?php if (in_array($key, ['ticket_template', 'invoice_template'], true)): ?> <a class="small" target="_blank" rel="noopener" href="<?= e(url('/settings/template-preview', ['type' => $key === 'ticket_template' ? 'ticket' : 'invoice'])) ?>">aperçu</a><?php endif; ?></label><select name="<?= e($key) ?>" class="form-select"><?php foreach ($opts as $k => $l): ?><option value="<?= e($k) ?>" <?= $val === (string) $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <?php else: ?>
          <div class="col-md-6"><label class="form-label"><?= e($label) ?></label><input type="<?= $type === 'number' ? 'number' : 'text' ?>" step="any" min="0" name="<?= e($key) ?>" value="<?= e($val) ?>" class="form-control" <?= $key === 'company_name' ? 'required' : '' ?>></div>
        <?php endif; ?>
      <?php endforeach; ?>
      <div class="col-12"><label class="form-label">Modes de paiement acceptés</label><div><?php $en = explode(',', (string) setting('payment_methods', 'cash')); foreach ($methods as $k => $l): ?><div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="payment_methods[]" value="<?= e($k) ?>" id="pm_<?= e($k) ?>" <?= in_array($k, $en, true) ? 'checked' : '' ?>><label class="form-check-label" for="pm_<?= e($k) ?>"><?= e($l) ?></label></div><?php endforeach; ?></div>
        <div class="form-text">Les paiements par carte et mobile money sont enregistrés avec leur référence de transaction ; aucune intégration directe avec un opérateur n'est réalisée (encaissement sur le terminal de l'opérateur).</div></div>
      <div class="col-12"><label class="form-label">Raccourcis clavier de la caisse</label><div class="row g-2"><?php $scl = ['search' => 'Recherche', 'customer' => 'Client', 'quantity' => 'Quantité', 'discount' => 'Remise', 'hold' => 'Attente', 'pay' => 'Encaisser', 'cashExact' => 'Espèces exact', 'clear' => 'Vider']; foreach ($scl as $k => $l): ?><div class="col-3"><div class="input-group input-group-sm"><span class="input-group-text"><?= e($l) ?></span><input name="sc_<?= e($k) ?>" value="<?= e($shortcuts[$k] ?? '') ?>" class="form-control" maxlength="3"></div></div><?php endforeach; ?></div></div>
    </div><div class="card-footer"><button class="btn btn-primary"><i class="bi bi-check2"></i> Enregistrer</button></div></div>
    </form>
  </div>
  <div class="col-xl-5">
    <div class="card mb-3"><div class="card-header"><i class="bi bi-percent"></i> TVA</div><div class="card-body">
      <?php $vMode = App\Services\Tax::mode(); ?>
      <form method="post" action="<?= e(url('/settings/vat')) ?>" class="row g-2"><?= csrf_field() ?>
        <div class="col-12"><?php foreach (App\Services\Tax::MODES as $k => $l): ?><div class="form-check"><input class="form-check-input" type="radio" name="vat_mode" value="<?= e($k) ?>" id="vm-<?= e($k) ?>" <?= $vMode === $k ? 'checked' : '' ?>><label class="form-check-label" for="vm-<?= e($k) ?>"><?= e($l) ?></label></div><?php endforeach; ?></div>
        <div class="col-6"><label class="form-label small mb-0" for="vatRate">Taux général (%)</label><input type="number" step="any" min="0" max="100" name="vat_rate" id="vatRate" value="<?= e((string) (float) setting('vat_rate', '19')) ?>" class="form-control form-control-sm" list="vatRates"><datalist id="vatRates"><?php foreach (App\Services\Tax::COMMON_RATES as $r): ?><option value="<?= $r ?>"><?php endforeach; ?></datalist></div>
        <div class="col-6"><label class="form-label small mb-0" for="vatLabel">Libellé</label><input name="vat_label" id="vatLabel" value="<?= e(App\Services\Tax::label()) ?>" class="form-control form-control-sm" maxlength="15"></div>
        <div class="col-12 d-flex flex-wrap gap-1"><?php foreach (App\Services\Tax::COMMON_RATES as $r): ?><button type="button" class="btn btn-sm btn-outline-secondary" data-set-value="#vatRate" data-value="<?= $r ?>"><?= $r ?> %</button><?php endforeach; ?></div>
        <div class="col-12 small text-secondary">Les prix de vente sont saisis <b>TVA comprise (TTC)</b> : la TVA est calculée sur chaque ligne et indiquée sur les tickets, factures et devis. En TVA générale, cochez « Exonéré » dans la fiche des articles non taxés. Rapport : <a href="<?= e(url('/reports/vat')) ?>">TVA collectée</a>.</div>
        <div class="col-12"><button class="btn btn-sm btn-outline-primary">Enregistrer la TVA</button></div>
      </form></div></div>
    <div class="card mb-3"><div class="card-header"><i class="bi bi-currency-exchange"></i> Devise</div><div class="card-body">
      <?php $cur = currency(); ?>
      <form method="post" action="<?= e(url('/settings/currency')) ?>" class="row g-2"><?= csrf_field() ?>
        <div class="col-12"><select name="currency_code" class="form-select"><?php foreach (App\Services\Catalog::CURRENCIES as $code => [$n, $sym]): ?><option value="<?= e($code) ?>" <?= $cur['code'] === $code ? 'selected' : '' ?>><?= e($code . ' — ' . $n . ' (' . $sym . ')') ?></option><?php endforeach; ?></select></div>
        <div class="col-12 form-check ms-1"><input type="checkbox" class="form-check-input" name="custom" value="1" id="cc"><label for="cc" class="form-check-label small">Personnaliser le symbole et l'affichage</label></div>
        <div class="col-3"><input name="currency_symbol" value="<?= e($cur['symbol']) ?>" class="form-control form-control-sm" title="Symbole" maxlength="10"></div>
        <div class="col-5"><input name="currency_name" value="<?= e($cur['name']) ?>" class="form-control form-control-sm" title="Nom (montant en lettres)" maxlength="50"></div>
        <div class="col-2"><input type="number" min="0" max="3" name="currency_decimals" value="<?= (int) $cur['decimals'] ?>" class="form-control form-control-sm" title="Décimales"></div>
        <div class="col-2"><select name="currency_position" class="form-select form-select-sm" title="Position"><option value="after">1 000 <?= e($cur['symbol']) ?></option><option value="before" <?= $cur['position'] === 'before' ? 'selected' : '' ?>><?= e($cur['symbol']) ?> 1 000</option></select></div>
        <div class="col-12 small text-secondary">Exemple : <b><?= e(money(13500)) ?></b>. Changer de devise ne convertit pas les prix déjà saisis.</div>
        <div class="col-12"><button class="btn btn-sm btn-outline-primary">Enregistrer la devise</button></div>
      </form></div></div>
    <div class="card mb-3"><div class="card-header"><i class="bi bi-phone"></i> Opérateurs de mobile money</div><div class="card-body">
      <form method="post" action="<?= e(url('/settings/mobile-money')) ?>"><?= csrf_field() ?>
        <textarea name="providers" class="form-control mb-2" rows="4" placeholder="Un opérateur par ligne"><?= e(str_replace(',', "\n", (string) setting('mobile_money_providers', ''))) ?></textarea>
        <div class="d-flex flex-wrap gap-1 mb-2"><?php foreach (App\Services\Catalog::MOBILE_MONEY_SUGGESTIONS as $sug): ?><button type="button" class="btn btn-sm btn-outline-secondary" data-add-provider="<?= e($sug) ?>">+ <?= e($sug) ?></button><?php endforeach; ?></div>
        <div class="small text-secondary mb-2">Proposés à l'encaissement quand le mode « Mobile money » est choisi ; l'opérateur est enregistré avec chaque paiement.</div>
        <button class="btn btn-sm btn-outline-primary">Enregistrer la liste</button>
      </form></div></div>
    <div class="card mb-3"><div class="card-header"><i class="bi bi-printer"></i> Imprimantes</div><div class="card-body small">Plusieurs imprimantes (caisse, cuisine, bar, factures…) : <a href="<?= e(url('/printers')) ?>">gérer les imprimantes</a>.</div></div>
    <div class="card mb-3"><div class="card-header"><i class="bi bi-image"></i> Logo (tickets, factures)</div><div class="card-body">
      <?php if ($l = setting('company_logo')): ?><img src="<?= e(upload_url($l)) ?>" alt="" class="mb-2" style="max-height:80px"><?php endif; ?>
      <form method="post" enctype="multipart/form-data" action="<?= e(url('/settings/logo')) ?>" class="d-flex gap-2"><?= csrf_field() ?><input type="file" name="logo" accept="image/png,image/jpeg,image/webp" class="form-control form-control-sm" required><button class="btn btn-sm btn-outline-primary">Envoyer</button></form>
    </div></div>
    <div class="card mb-3"><div class="card-header"><i class="bi bi-shop"></i> Type d'activité et modules</div><div class="card-body">
      <form method="post" action="<?= e(url('/settings/business')) ?>"><?= csrf_field() ?>
        <select name="business_type" class="form-select mb-2"><?php foreach ($types as $k => [$l]): ?><option value="<?= e($k) ?>" <?= setting('business_type') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
        <?php foreach ($features as $k => $l): ?><div class="form-check"><input class="form-check-input" type="checkbox" name="features[]" value="<?= e($k) ?>" id="f_<?= e($k) ?>" <?= feature($k) ? 'checked' : '' ?>><label class="form-check-label" for="f_<?= e($k) ?>"><?= e($l) ?></label></div><?php endforeach; ?>
        <div class="form-check mt-2"><input class="form-check-input" type="checkbox" name="apply_defaults" value="1" id="ad"><label class="form-check-label small" for="ad">Appliquer les modules recommandés pour ce type (ignore les cases ci-dessus)</label></div>
        <button class="btn btn-outline-primary mt-2">Mettre à jour</button>
      </form>
    </div></div>
    <?php if (setting('demo_data') === '1'): ?>
    <div class="card border-warning"><div class="card-header text-warning"><i class="bi bi-cone-striped"></i> Données de démonstration</div><div class="card-body">
      <p class="small">Archive les articles, clients et fournisseurs « [DÉMO] » (l'historique des ventes de test est conservé).</p>
      <form method="post" action="<?= e(url('/settings/demo/archive')) ?>" data-confirm="Archiver toutes les données de démonstration ?"><?= csrf_field() ?><button class="btn btn-warning">Archiver les données de démonstration</button></form>
    </div></div>
    <?php endif; ?>
  </div>
</div>
