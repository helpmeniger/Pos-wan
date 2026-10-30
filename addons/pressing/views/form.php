<link rel="stylesheet" href="<?= e(asset('addons/pressing/pressing.css')) ?>">
<?php
use Addons\Pressing\PressingService as PS;
$manage = can('pressing.manage');
$cats = [];
foreach ($tarifs as $a) { $cats[$a['category']][] = $a; }
$data = ['tarifs' => array_values($tarifs), 'services' => PS::SERVICES, 'expressPct' => (float) setting('pressing_express_pourcent', '50'),
  'delai' => (int) setting('pressing_delai_jours', '3'), 'delaiExpress' => (int) setting('pressing_express_delai_jours', '1'), 'canPrice' => $manage,
  'expressChoices' => PS::expressChoices(), 'expressFree' => $manage];
$data['expressPct'] = PS::expressDefault();
?>
<?php if (!empty($caisse)): ?>
<div class="card mb-3 border-success"><div class="card-body py-2 d-flex flex-wrap gap-2 align-items-center">
  <span class="badge bg-success fs-6"><i class="bi bi-basket2"></i> Caisse pressing</span>
  <form method="get" action="<?= e(url('/pressing/find')) ?>" class="d-flex gap-1"><input name="q" id="pkFind" class="form-control form-control-sm" placeholder="Retrait : n° de dépôt, téléphone, nom" style="min-width:240px"><button type="button" class="btn btn-sm btn-outline-secondary" data-camera-scan="#pkFind" data-scan-submit data-scan-title="Scanner le QR code du ticket"><i class="bi bi-qr-code-scan"></i></button><button class="btn btn-sm btn-success">Retrait</button></form>
  <a class="btn btn-sm btn-outline-success" href="<?= e(url('/pressing/suivi', ['f' => 'ready'])) ?>">Prêts à retirer <span class="badge bg-success"><?= (int) $readyCount ?></span></a>
  <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/pressing/suivi')) ?>"><i class="bi bi-list-check"></i> Suivi</a>
  <?php foreach ($ready as $r): ?><a class="badge bg-light text-dark border text-decoration-none" href="<?= e(url('/pressing/' . $r['id'])) ?>"><?= e($r['number']) ?> · <?= e($r['customer_name']) ?><?= (float) $r['due'] > 0 ? ' · ' . e(money($r['due'])) : '' ?></a><?php endforeach; ?>
</div></div>
<?php endif; ?>
<form method="post" action="<?= e(url('/pressing')) ?>" id="pressingForm" autocomplete="off"><?= csrf_field() ?>
<div class="row g-3">
  <div class="col-xl-8">
    <div class="card mb-3"><div class="card-header"><i class="bi bi-person"></i> Client</div><div class="card-body row g-2">
      <div class="col-md-5"><label class="form-label required">Nom</label><input name="customer_name" class="form-control" maxlength="150" required list="pClients" data-client-name></div>
      <div class="col-md-4"><label class="form-label required">Téléphone</label><input name="phone" class="form-control" maxlength="50" required inputmode="tel" data-client-phone></div>
      <div class="col-md-3"><label class="form-label">Retrait prévu</label><input type="date" name="due_date" class="form-control" min="<?= e(date('Y-m-d')) ?>" value="<?= e(date('Y-m-d', strtotime('+' . $data['delai'] . ' days'))) ?>" data-due></div>
      <datalist id="pClients"><?php foreach ($recent as $c): ?><option value="<?= e($c['customer_name']) ?>" data-phone="<?= e($c['phone']) ?>" data-address="<?= e($c['address'] ?? '') ?>"><?= e($c['phone']) ?></option><?php endforeach; ?></datalist>
    </div></div>

    <div class="card"><div class="card-header d-flex align-items-center"><span><i class="bi bi-basket2"></i> Articles déposés</span><span class="ms-auto small text-secondary">Touchez un article pour l'ajouter</span></div>
      <div class="card-body">
        <div class="d-flex flex-wrap gap-1 mb-2" role="group">
          <?php foreach (PS::SERVICES as $k => $l): ?><input type="radio" class="btn-check" name="cur_service" id="sv-<?= e($k) ?>" value="<?= e($k) ?>" <?= $k === 'lavage' ? 'checked' : '' ?>><label class="btn btn-sm btn-outline-primary" for="sv-<?= e($k) ?>"><?= e($l) ?></label><?php endforeach; ?>
        </div>
        <?php foreach ($cats as $cat => $list): ?>
          <div class="small text-secondary text-uppercase mt-2 mb-1"><?= e($cat) ?></div>
          <div class="pressing-tiles"><?php foreach ($list as $a): ?><button type="button" class="pressing-tile" data-article="<?= (int) $a['id'] ?>"><?= e($a['name']) ?><?php if (PS::measured($a['unit'])): ?> <span class="badge bg-info text-dark"><?= e(PS::UNIT_SYMBOLS[$a['unit']]) ?></span><?php endif; ?></button><?php endforeach; ?></div>
        <?php endforeach; ?>
        <div class="table-responsive mt-3"><table class="table table-sm align-middle"><thead><tr><th>Article / service</th><th style="width:120px">Qté / kg / m²</th><th style="width:110px">Prix</th><th>Couleur</th><th>Marque</th><th>Défauts constatés</th><th class="num">Total</th><th></th></tr></thead>
          <tbody id="pLines"><tr class="empty"><td colspan="8" class="text-center text-secondary py-3">Aucun article.</td></tr></tbody></table></div>
      </div></div>
  </div>
  <div class="col-xl-4">
    <div class="card position-sticky" style="top:70px"><div class="card-body">
      <div class="form-check form-switch mb-2"><input type="hidden" name="express" value="0"><input class="form-check-input" type="checkbox" name="express" value="1" id="pExpress" data-express><label class="form-check-label fw-semibold text-danger" for="pExpress">EXPRESS (prêt en <?= (int) $data['delaiExpress'] ?> j)</label></div>
      <div class="d-none mb-2 border border-danger-subtle rounded p-2" data-express-box>
        <div class="small fw-semibold mb-1">Supplément express</div>
        <div class="d-flex flex-wrap gap-1 align-items-center">
          <?php foreach ($data['expressChoices'] as $c): ?><button type="button" class="btn btn-sm btn-outline-danger" data-exp-pct="<?= e((string) $c) ?>"><?= e(PS::qty($c)) ?> %</button><?php endforeach; ?>
          <div class="input-group input-group-sm ms-auto" style="width:110px"><input name="express_pct" class="form-control text-end" inputmode="decimal" value="<?= e(PS::qty($data['expressPct'])) ?>" data-express-pct <?= $manage ? '' : 'readonly title="Autre pourcentage : réservé au responsable"' ?>><span class="input-group-text">%</span></div>
        </div>
      </div>
      <div class="form-check form-switch mb-2"><input type="hidden" name="delivery" value="0"><input class="form-check-input" type="checkbox" name="delivery" value="1" id="pDelivery" data-delivery><label class="form-check-label fw-semibold text-primary" for="pDelivery"><i class="bi bi-truck"></i> Livraison à domicile</label></div>
      <div class="d-none mb-2 border border-primary-subtle rounded p-2" data-delivery-box>
        <input name="delivery_address" class="form-control form-control-sm mb-1" maxlength="255" placeholder="Adresse : quartier, rue, repère…" data-delivery-address>
        <div class="input-group input-group-sm"><span class="input-group-text">Frais de livraison</span><input name="delivery_fee" class="form-control text-end" inputmode="numeric" value="<?= e((string) (float) setting('pressing_livraison_frais', '0')) ?>" data-delivery-fee></div>
      </div>
      <table class="table table-sm mb-2">
        <tr><td>Pièces</td><td class="num" data-t="pieces">0</td></tr>
        <tr class="d-none" data-weight-row><td>Poids</td><td class="num" data-t="weight">0 kg</td></tr>
        <tr class="d-none" data-area-row><td>Surface</td><td class="num" data-t="area">0 m²</td></tr>
        <tr><td>Sous-total</td><td class="num" data-t="sub"><?= e(money(0)) ?></td></tr>
        <tr><td>Supplément express <span data-t="exp-pct"></span></td><td class="num" data-t="exp"><?= e(money(0)) ?></td></tr>
        <tr class="d-none" data-liv-row><td>Livraison</td><td class="num" data-t="liv"><?= e(money(0)) ?></td></tr>
        <tr><td>Remise</td><td><input name="discount" class="form-control form-control-sm text-end" inputmode="numeric" value="0" data-discount <?= $manage || can('pos.discount') ? '' : 'readonly' ?>></td></tr>
        <tr class="fs-5 fw-bold"><td>TOTAL</td><td class="num" data-t="total"><?= e(money(0)) ?></td></tr>
      </table>
      <div class="small fw-semibold mb-1">Acompte (facultatif)</div>
      <div class="row g-1 mb-1"><?= App\Core\View::partial('pressing::_pay') ?></div>
      <div class="d-flex flex-wrap gap-1 mb-2"><button type="button" class="btn btn-sm btn-outline-secondary" data-pay-full>Tout payer</button><button type="button" class="btn btn-sm btn-outline-secondary" data-pay-half>50 %</button><span class="ms-auto small">Reste : <b data-t="rest"><?= e(money(0)) ?></b></span></div>
      <div class="small text-success mb-2 d-none" data-t="change"></div>
      <input name="note" class="form-control form-control-sm mb-2" placeholder="Note (consignes, repère…)" maxlength="255">
      <button class="btn btn-success btn-lg w-100" data-submit disabled><i class="bi bi-check2-circle"></i> Enregistrer le dépôt</button>
      <div class="small text-secondary mt-2">Le ticket client et les étiquettes s'impriment après l'enregistrement.</div>
    </div></div>
  </div>
</div>
</form>
<script type="application/json" id="pressing-data"><?= json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
