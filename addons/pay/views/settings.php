<?= App\Core\View::partial('pay::_nav', ['tab' => $tab]) ?>
<div class="row g-3"><div class="col-lg-6"><div class="card"><div class="card-header fw-semibold"><?= e(t('Règles')) ?></div><div class="card-body small">
<form method="post" action="<?= e(url('/pay/parametres')) ?>"><?= csrf_field() ?>
  <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="require_electronic" value="1" id="sre" <?= $s['require_electronic'] === '1' ? 'checked' : '' ?>><label class="form-check-label" for="sre"><?= e(t('Exiger « Paiement électronique » pour les règlements Carte et Mobile money (refuse la saisie manuelle d\'une référence)')) ?></label></div>
  <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="test_sales" value="1" id="sts" <?= $s['test_sales'] === '1' ? 'checked' : '' ?>><label class="form-check-label" for="sts"><?= e(t('Formation : autoriser des transactions TEST à régler des ventes')) ?></label>
    <div class="text-danger"><?= e(t('À n\'activer que sur une base de démonstration : ces ventes seraient comptées dans les chiffres de vente alors qu\'aucune somme n\'a été encaissée.')) ?></div></div>
  <div class="mb-2 fw-semibold"><?= e(t('Devises acceptées')) ?> (<?= e(t('devise de la boutique')) ?> : <?= e($shop) ?>)</div>
  <?php foreach ($currencies as $c): ?><div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="currencies[]" value="<?= e($c['code']) ?>" id="c<?= e($c['code']) ?>" <?= (int) $c['active'] ? 'checked' : '' ?> <?= $c['code'] === $shop ? 'disabled' : '' ?>><label class="form-check-label" for="c<?= e($c['code']) ?>"><?= e($c['code']) ?> <span class="text-secondary"><?= e($c['name']) ?></span></label></div><?php endforeach; ?>
  <div class="mt-2"><button class="btn btn-sm btn-primary"><?= e(t('Enregistrer')) ?></button></div></form>
<form method="post" action="<?= e(url('/pay/parametres/devise')) ?>" class="row g-2 mt-3 border-top pt-2"><?= csrf_field() ?><div class="col-3"><input name="code" maxlength="3" class="form-control form-control-sm" placeholder="XAF" required></div><div class="col-5"><input name="name" maxlength="60" class="form-control form-control-sm" placeholder="<?= e(t('Nom')) ?>" required></div><div class="col-2"><input type="number" name="decimals" min="0" max="3" value="2" class="form-control form-control-sm"></div><div class="col-2"><button class="btn btn-sm btn-outline-primary w-100"><?= e(t('Ajouter')) ?></button></div></form>
</div></div></div>
<div class="col-lg-6"><div class="card"><div class="card-header fw-semibold"><?= e(t('Taux de change')) ?></div><div class="card-body small">
<p class="text-secondary"><?= e(t('Aucune conversion sans taux saisi. Le taux appliqué par le prestataire peut différer : le montant réellement débité est celui qu\'il confirme.')) ?></p>
<form method="post" action="<?= e(url('/pay/parametres/taux')) ?>" class="row g-2"><?= csrf_field() ?>
  <div class="col-3"><select name="from_currency" class="form-select form-select-sm"><?php foreach ($currencies as $c): ?><option <?= $c['code'] === $shop ? 'selected' : '' ?>><?= e($c['code']) ?></option><?php endforeach; ?></select></div>
  <div class="col-3"><select name="to_currency" class="form-select form-select-sm"><?php foreach ($currencies as $c): ?><option><?= e($c['code']) ?></option><?php endforeach; ?></select></div>
  <div class="col-3"><input name="rate" class="form-control form-control-sm" placeholder="0.00152449" required></div>
  <div class="col-3"><input type="date" name="valid_from" value="<?= e(date('Y-m-d')) ?>" class="form-control form-control-sm"></div>
  <div class="col-9"><input name="source" maxlength="255" class="form-control form-control-sm" placeholder="<?= e(t('Source : prestataire, banque, parité officielle, date')) ?>" required></div>
  <div class="col-3"><button class="btn btn-sm btn-outline-primary w-100"><?= e(t('Ajouter')) ?></button></div></form>
<table class="table table-sm mt-2 mb-0"><?php foreach ($rates as $r): ?><tr><td>1 <?= e($r['from_currency']) ?> = <?= e(rtrim(rtrim((string) $r['rate'], '0'), '.')) ?> <?= e($r['to_currency']) ?></td><td><?= e(fdate($r['valid_from'], false)) ?></td><td><?= e($r['source']) ?></td><td><?= e($r['full_name'] ?? '') ?></td></tr><?php endforeach; ?></table>
</div></div></div></div>
