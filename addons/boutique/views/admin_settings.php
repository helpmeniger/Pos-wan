<?php
/** Boutique en ligne : réglages et articles publiés. */
use Addons\Boutique\ShopService as S;
$v = fn (string $k, string $d = '') => S::setting($k, $d);
?>
<div class="row g-3">
  <div class="col-xl-5">
    <div class="card mb-3"><div class="card-body d-flex gap-3 align-items-center">
      <div data-qr="<?= e($shopUrl) ?>" data-qr-size="3" style="width:110px;flex:none"></div>
      <div class="min-w-0"><div class="fw-bold">Adresse de votre boutique</div>
        <div class="input-group input-group-sm my-1"><input class="form-control" value="<?= e($shopUrl) ?>" readonly aria-label="Adresse"><a class="btn btn-primary" href="<?= e($shopUrl) ?>" target="_blank" rel="noopener" data-no-progress><i class="bi bi-box-arrow-up-right"></i></a></div>
        <div class="small text-secondary">Partagez ce lien ou ce QR code (WhatsApp, Facebook, affiche en boutique). <?= (int) $online ?> article(s) en ligne.</div></div>
    </div></div>
    <form method="post" action="<?= e(url('/boutique-admin/reglages')) ?>" class="card"><?= csrf_field() ?>
      <div class="card-header"><i class="bi bi-sliders"></i> Réglages</div>
      <div class="card-body row g-3">
        <div class="col-12 form-check form-switch ms-2"><input class="form-check-input" type="checkbox" name="enabled" value="1" id="sEn" <?= $v('enabled', '1') === '1' ? 'checked' : '' ?>><label class="form-check-label fw-bold" for="sEn">Boutique en ligne ouverte</label></div>
        <div class="col-md-8"><label class="form-label">Nom affiché</label><input name="title" class="form-control" value="<?= e($v('title')) ?>" placeholder="<?= e((string) setting('company_name')) ?>" maxlength="100"></div>
        <div class="col-md-4"><label class="form-label">Couleur</label><input type="color" name="color" class="form-control form-control-color w-100" value="<?= e($v('color', '#0d6efd')) ?>"></div>
        <div class="col-12"><label class="form-label">Phrase d'accueil</label><input name="tagline" class="form-control" value="<?= e($v('tagline')) ?>" maxlength="200"></div>
        <div class="col-12"><label class="form-label">Caisse de la boutique en ligne <span class="text-secondary small">(son entrepôt fournit le stock affiché)</span></label>
          <select name="register_id" class="form-select"><?php foreach ($registers as $r): ?><option value="<?= (int) $r['id'] ?>" <?= (int) ($current['id'] ?? 0) === (int) $r['id'] ? 'selected' : '' ?>><?= e($r['name']) ?> — <?= e($r['store']) ?> (stock : <?= e($r['warehouse']) ?>)</option><?php endforeach; ?></select></div>
        <div class="col-12 form-check form-switch ms-2"><input class="form-check-input" type="checkbox" name="require_open" value="1" id="sOpen" <?= $v('require_open', '0') === '1' ? 'checked' : '' ?>><label class="form-check-label" for="sOpen">Accepter les commandes seulement quand cette caisse est ouverte</label></div>
        <div class="col-md-6"><label class="form-label">Affichage du stock</label><select name="stock_display" class="form-select"><?php foreach (['badge' => '« En stock » / « Plus que 3 »', 'qty' => 'Quantité exacte', 'none' => 'Ne pas afficher'] as $k => $l): ?><option value="<?= $k ?>" <?= $v('stock_display', 'badge') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-6"><label class="form-label">Articles épuisés</label><select name="out_of_stock" class="form-select"><option value="show" <?= $v('out_of_stock', 'show') === 'show' ? 'selected' : '' ?>>Affichés « Épuisé »</option><option value="hide" <?= $v('out_of_stock') === 'hide' ? 'selected' : '' ?>>Masqués</option></select></div>
        <div class="col-12 form-check form-switch ms-2"><input class="form-check-input" type="checkbox" name="delivery" value="1" id="sDel" <?= $v('delivery', '1') === '1' ? 'checked' : '' ?>><label class="form-check-label" for="sDel">Proposer la livraison</label></div>
        <div class="col-md-6"><label class="form-label">Frais de livraison</label><div class="input-group"><input type="number" name="delivery_fee" min="0" step="1" class="form-control" value="<?= e($v('delivery_fee', '0')) ?>"><span class="input-group-text"><?= e(currency()['symbol']) ?></span></div></div>
        <div class="col-md-6"><label class="form-label">Commande minimum</label><div class="input-group"><input type="number" name="min_order" min="0" step="1" class="form-control" value="<?= e($v('min_order', '0')) ?>"><span class="input-group-text"><?= e(currency()['symbol']) ?></span></div></div>
        <div class="col-12"><label class="form-label">Numéro WhatsApp de la boutique <span class="text-secondary small">(facultatif, avec l'indicatif : 22790000000)</span></label><input name="whatsapp" class="form-control" value="<?= e($v('whatsapp')) ?>" maxlength="20" inputmode="tel"></div>
      </div>
      <div class="card-footer"><button class="btn btn-primary"><i class="bi bi-check2"></i> Enregistrer</button></div>
    </form>
  </div>
  <div class="col-xl-7">
    <div class="card"><div class="card-header d-flex flex-wrap gap-2 align-items-center"><span><i class="bi bi-grid"></i> Articles en ligne</span>
      <form class="ms-auto d-flex gap-1" method="get"><input name="q" value="<?= e($q) ?>" class="form-control form-control-sm" placeholder="Rechercher un article"><button class="btn btn-sm btn-outline-secondary"><i class="bi bi-search"></i></button></form></div>
      <div class="card-body small text-secondary py-2">Tous les articles actifs sont en ligne, sauf ceux que vous masquez ici (et les médicaments sur ordonnance ou les articles réservés au restaurant). La photo et la description viennent de la fiche article ; vous pouvez écrire un texte spécial pour la boutique.</div>
      <ul class="list-group list-group-flush">
      <?php foreach ($p['rows'] as $a): $blocked = (int) $a['requires_prescription'] || (int) $a['is_controlled']; ?>
        <li class="list-group-item">
          <form method="post" action="<?= e(url('/boutique-admin/articles/' . $a['id'])) ?>" class="d-flex gap-2 align-items-start flex-wrap"><?= csrf_field() ?><input type="hidden" name="q" value="<?= e($q) ?>"><input type="hidden" name="page" value="<?= (int) $p['page'] ?>">
            <div style="width:52px;height:52px;flex:none" class="rounded overflow-hidden bg-body-tertiary d-grid"><?php if ($a['image']): ?><img src="<?= e(upload_url($a['image'])) ?>" alt="" style="width:100%;height:100%;object-fit:cover" loading="lazy"><?php else: ?><i class="bi bi-image text-secondary m-auto"></i><?php endif; ?></div>
            <div class="flex-grow-1" style="min-width:200px"><div class="fw-bold"><?= e($a['name']) ?> <?php if ((int) $a['featured']): ?><span class="badge text-bg-warning"><i class="bi bi-star-fill"></i> À la une</span><?php endif; ?></div>
              <div class="small text-secondary"><?= e($a['category'] ?? 'Sans catégorie') ?> · <?= e(money($a['promo_price'] ?: $a['sale_price'])) ?><?= !$a['image'] ? ' · <span class="text-warning-emphasis">sans photo</span>' : '' ?></div>
              <textarea name="web_description" rows="1" class="form-control form-control-sm mt-1" placeholder="<?= e(mb_substr((string) ($a['description'] ?? 'Description pour la boutique (facultatif)'), 0, 90)) ?>"><?= e($a['web_description'] ?? '') ?></textarea></div>
            <div class="d-flex flex-column gap-1" style="min-width:150px">
              <?php if ($blocked): ?><span class="badge text-bg-secondary">Sur ordonnance : jamais en ligne</span><?php else: ?>
              <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="online" value="1" id="on<?= (int) $a['id'] ?>" <?= (int) $a['hidden'] ? '' : 'checked' ?>><label class="form-check-label small" for="on<?= (int) $a['id'] ?>">En ligne</label></div>
              <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="featured" value="1" id="ft<?= (int) $a['id'] ?>" <?= (int) $a['featured'] ? 'checked' : '' ?>><label class="form-check-label small" for="ft<?= (int) $a['id'] ?>">À la une</label></div>
              <button class="btn btn-sm btn-outline-primary py-0">Enregistrer</button><?php endif; ?>
            </div>
          </form>
        </li>
      <?php endforeach; ?>
      </ul></div>
    <?= App\Core\View::partial('partials/pagination', ['p' => $p]) ?>
  </div>
</div>
