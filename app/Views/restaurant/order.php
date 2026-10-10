<?php /** Prise de commande en salle — même ergonomie que la caisse. */ ?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, user-scalable=no">
<meta name="base-url" content="<?= e(url('/')) ?>">
<title><?= e($o['number']) ?> · Restaurant · POS Wan</title>
<link rel="icon" href="<?= e(asset('img/logo.svg')) ?>">
<link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/bootstrap.min.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/pos.css')) ?>">
</head>
<body class="pos-body">
<div class="pos rest">
  <section class="pos-left">
    <div class="cat-bar" id="catBar">
      <button class="cat-tile active" data-cat="0"><span class="cat-ico" style="background:#14213d"><i class="bi bi-grid-3x3-gap-fill"></i></span><span>TOUT</span></button>
      <?php foreach ($categories as $c): ?>
        <button class="cat-tile" data-cat="<?= (int) $c['id'] ?>"><?php if ($c['image']): ?><img src="<?= e(upload_url($c['image'])) ?>" alt=""><?php else: ?><span class="cat-ico" style="background:<?= e($c['color']) ?>"><i class="bi <?= e($c['icon'] ?: 'bi-tag') ?>"></i></span><?php endif; ?><span><?= e(mb_strtoupper($c['name'])) ?></span></button>
      <?php endforeach; ?>
    </div>
    <div class="search-row">
      <div class="input-group"><span class="input-group-text"><i class="bi bi-search"></i></span><input type="search" id="productSearch" class="form-control" placeholder="Rechercher un plat, une boisson…" autocomplete="off"></div>
      <div class="btn-group" role="group" aria-label="Service" id="courseSel">
        <button class="btn btn-light active" data-course="" title="Service déduit du poste (bar → boisson)">Auto</button>
        <?php foreach ($config['courses'] as $k => $l): ?><button class="btn btn-light" data-course="<?= (int) $k ?>"><?= e($l) ?></button><?php endforeach; ?>
      </div>
    </div>
    <div class="product-grid" id="productGrid"></div>
  </section>
  <nav class="qty-strip" aria-label="Quantité rapide">
    <a class="strip-nav d-flex align-items-center justify-content-center text-white text-decoration-none" href="<?= e(url('/restaurant')) ?>" title="Plan de salle"><i class="bi bi-grid-3x3"></i></a>
    <?php for ($i = 2; $i <= 9; $i++): ?><button class="qty-key" data-qty="<?= $i ?>"><?= $i ?></button><?php endfor; ?>
  </nav>
  <aside class="pos-right">
    <div class="pr-head">
      <div>
        <div class="clock" id="orderTitle"></div>
        <div class="small text-muted-2" id="orderMeta"></div>
      </div>
      <div class="head-icons">
        <a href="<?= e(url('/restaurant')) ?>" title="Plan de salle"><i class="bi bi-grid-3x3"></i></a>
        <a href="<?= e(url('/menu')) ?>" title="Menu de gestion"><i class="bi bi-grid-3x3-gap-fill"></i></a>
      </div>
    </div>
    <div class="pr-total"><span>Reste dû :</span><output id="totalOut">0 F</output></div>
    <div class="pr-customer" id="customerBadge"></div>
    <div class="pr-actions">
      <button data-action="covers"><i class="bi bi-people"></i><span>Couverts</span></button>
      <button data-action="note"><i class="bi bi-chat-left-text"></i><span>Note plat</span></button>
      <button data-action="quantity"><i class="bi bi-123"></i><span>Quantité</span></button>
      <button data-action="cancel"><i class="bi bi-x-octagon"></i><span>Annuler</span></button>
      <button data-action="serve"><i class="bi bi-bell"></i><span>Servi</span></button>
      <button data-action="transfer"><i class="bi bi-arrow-left-right"></i><span>Table</span></button>
      <button data-action="bill"><i class="bi bi-receipt"></i><span>Addition</span></button>
      <button data-action="customer"><i class="bi bi-person"></i><span>Client</span></button>
    </div>
    <div class="cart-head"><span>Article</span><span>Qté</span><span>P.U.</span><span>Total</span></div>
    <div class="cart" id="cart" tabindex="0"></div>
    <div class="pr-summary" id="summary"></div>
    <button class="btn-send" data-action="send"><i class="bi bi-fire"></i> Envoyer en cuisine</button>
    <?php if (can('pos.sell')): ?><a class="btn-validate text-decoration-none" id="payBtn" href="<?= e(url('/restaurant/orders/' . $o['id'] . '/pay')) ?>"><i class="bi bi-cash-coin"></i> Encaisser</a><?php endif; ?>
  </aside>
</div>

<!-- Saisie numérique / texte -->
<div class="modal fade" id="numModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-sm modal-dialog-centered"><div class="modal-content">
  <div class="modal-header"><h6 class="modal-title" id="numTitle"></h6><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button></div>
  <div class="modal-body">
    <input type="text" class="form-control form-control-lg text-end fw-bold mb-2" id="numInput" inputmode="decimal" autocomplete="off">
    <div class="keypad-pos small-keys" id="numKeypad"><?php foreach (['7', '8', '9', '4', '5', '6', '1', '2', '3', ',', '0', '⌫'] as $k): ?><button type="button" data-k="<?= e($k) ?>"><?= e($k) ?></button><?php endforeach; ?></div>
  </div>
  <div class="modal-footer"><button class="btn btn-primary w-100" id="numOk">OK</button></div>
</div></div></div>

<div class="modal fade" id="noteModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
  <div class="modal-header"><h6 class="modal-title">Note pour la cuisine</h6><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button></div>
  <div class="modal-body">
    <div class="d-flex flex-wrap gap-1 mb-2" id="noteChips">
      <?php foreach (['Sans piment', 'Bien pimenté', 'Sans oignon', 'Bien cuit', 'Saignant', 'Sans sel', 'Sauce à part', 'Glacé', 'Sans glace', 'À emporter', 'Pour enfant', 'Urgent'] as $n): ?><button type="button" class="btn btn-sm btn-outline-secondary"><?= e($n) ?></button><?php endforeach; ?>
    </div>
    <input class="form-control" id="noteInput" maxlength="255" placeholder="Note libre…">
  </div>
  <div class="modal-footer"><button class="btn btn-light" id="noteClear">Effacer</button><button class="btn btn-primary" id="noteOk">OK</button></div>
</div></div></div>

<div class="modal fade" id="cancelModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
  <div class="modal-header bg-danger-subtle"><h6 class="modal-title"><i class="bi bi-x-octagon"></i> Annuler un article envoyé</h6><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button></div>
  <div class="modal-body">
    <p class="fw-semibold" id="cancelName"></p>
    <div class="row g-2">
      <div class="col-4"><label class="form-label small">Quantité</label><input type="number" min="0" step="any" id="cancelQty" class="form-control"></div>
      <div class="col-8"><label class="form-label small">Motif (obligatoire)</label><input id="cancelReason" class="form-control" maxlength="255" list="reasons"><datalist id="reasons"><option>Erreur de saisie</option><option>Client a changé d'avis</option><option>Attente trop longue</option><option>Plat refusé (qualité)</option><option>Rupture d'ingrédient</option></datalist></div>
      <div class="col-12 form-check ms-2"><input type="checkbox" class="form-check-input" id="cancelWaste"><label class="form-check-label" for="cancelWaste">Déjà préparé : déclarer en perte (déstockage des ingrédients)</label></div>
      <?php if (!can('restaurant.cancel_item')): ?>
      <div class="col-12"><div class="alert alert-warning py-2 small mb-1">Autorisation d'un responsable requise.</div></div>
      <div class="col-6"><input id="supUser" class="form-control" placeholder="Identifiant responsable" autocomplete="off"></div>
      <div class="col-6"><input type="password" id="supSecret" class="form-control" placeholder="Mot de passe ou PIN" autocomplete="off"></div>
      <?php endif; ?>
    </div>
    <div class="alert alert-danger d-none mt-2 mb-0 py-2 small" id="cancelError"></div>
  </div>
  <div class="modal-footer"><button class="btn btn-danger" id="cancelOk">Confirmer l'annulation</button></div>
</div></div></div>

<div class="modal fade" id="tableModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
  <div class="modal-header"><h6 class="modal-title">Transférer vers une table / fusionner</h6><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button></div>
  <form method="post" action="<?= e(url('/restaurant/orders/' . $o['id'] . '/transfer')) ?>"><div class="modal-body">
    <?= csrf_field() ?>
    <p class="small text-secondary">Table libre : la commande y est déplacée. Table occupée : les deux commandes sont fusionnées.</p>
    <select name="table_id" class="form-select" required><?php foreach ($tables as $t): if ((int) $t['id'] === (int) $o['table_id']) continue; ?><option value="<?= (int) $t['id'] ?>"><?= e($t['area'] . ' · ' . $t['name'] . ($t['order_id'] ? ' (occupée → fusion)' : ' (libre)')) ?></option><?php endforeach; ?></select>
  </div><div class="modal-footer"><button class="btn btn-primary">Valider</button></div></form>
</div></div></div>

<div class="modal fade" id="customerModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
  <div class="modal-header"><h6 class="modal-title">Client de la commande</h6><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button></div>
  <div class="modal-body">
    <input type="search" class="form-control mb-2" id="customerSearch" placeholder="Rechercher un client enregistré (nom, téléphone)…" autocomplete="off">
    <div class="list-group mb-3" id="customerResults"></div>
    <div class="row g-2"><div class="col-6"><input class="form-control" id="cName" placeholder="Nom (libre)" maxlength="150"></div><div class="col-6"><input class="form-control" id="cPhone" placeholder="Téléphone" maxlength="50"></div><div class="col-12"><input class="form-control" id="cAddress" placeholder="Adresse de livraison" maxlength="255"></div><div class="col-12"><input class="form-control" id="cNote" placeholder="Note de commande" maxlength="255"></div></div>
  </div>
  <div class="modal-footer"><button class="btn btn-primary" id="customerOk">Enregistrer</button></div>
</div></div></div>

<form method="post" action="<?= e(url('/restaurant/orders/' . $o['id'] . '/cancel')) ?>" id="cancelOrderForm" class="d-none"><?= csrf_field() ?><input type="hidden" name="reason" id="cancelOrderReason"></form>
<div class="toast-container position-fixed top-0 start-50 translate-middle-x p-3" id="toasts"></div>
<script type="application/json" id="rest-config"><?= json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
<script src="<?= e(asset('vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(asset('js/restaurant-order.js')) ?>"></script>
</body>
</html>
