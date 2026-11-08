<?php
/** Boutique en ligne : catalogue public (rendu par shop.js à partir des données ci-dessous). */
$withSearch = true;
$withCart = true;
$pageTitle = '';
include __DIR__ . '/_head.php';
$featured = array_values(array_filter($catalog, fn ($p) => $p['featured']));
$roots = array_values(array_filter($categories, fn ($c) => $c['parent_id'] === null || !in_array((int) $c['parent_id'], array_column($categories, 'id'))));
?>
<section class="shop-hero">
  <div class="container">
    <h1><?= e($shopTitle) ?></h1>
    <?php if ($tagline !== ''): ?><p class="lead mb-0"><?= e($tagline) ?></p><?php endif; ?>
    <div class="shop-hero-tags"><span><i class="bi bi-bag-check"></i> Commande en 1 minute</span><span><i class="bi bi-receipt"></i> Reçu immédiat</span><?php if ($config['delivery']): ?><span><i class="bi bi-truck"></i> Livraison<?= $config['fee'] > 0 ? ' ' . e(money($config['fee'])) : '' ?></span><?php endif; ?><span><i class="bi bi-shop"></i> Retrait en boutique</span></div>
  </div>
</section>

<main class="container pb-5">
  <?php if (!$catalog): ?>
    <div class="shop-empty"><i class="bi bi-box-seam"></i><p>Aucun article en ligne pour le moment. Revenez bientôt !</p></div>
  <?php else: ?>
  <nav class="shop-cats" aria-label="Catégories">
    <button type="button" class="active" data-cat="">Tout <span><?= count($catalog) ?></span></button>
    <?php foreach ($roots as $c): ?><button type="button" data-cat="<?= (int) $c['id'] ?>" style="--c:<?= e($c['color'] ?: '#6c757d') ?>"><?php if ($c['icon']): ?><i class="bi <?= e($c['icon']) ?>"></i> <?php endif; ?><?= e($c['name']) ?></button><?php endforeach; ?>
  </nav>
  <?php if ($featured): ?>
  <section class="mb-4" data-featured-section>
    <h2 class="shop-h2"><i class="bi bi-stars"></i> À la une</h2>
    <div class="shop-grid" data-grid="featured"></div>
  </section>
  <?php endif; ?>
  <section>
    <div class="d-flex align-items-baseline gap-2 flex-wrap"><h2 class="shop-h2 me-auto" data-list-title>Tous les articles</h2>
      <select class="form-select form-select-sm w-auto" data-sort aria-label="Trier"><option value="">Trier : pertinence</option><option value="price">Prix croissant</option><option value="-price">Prix décroissant</option><option value="name">Nom (A → Z)</option></select></div>
    <div class="shop-grid" data-grid="all"></div>
    <div class="shop-empty d-none" data-no-result><i class="bi bi-search"></i><p>Aucun article ne correspond à votre recherche.</p></div>
  </section>
  <?php endif; ?>
</main>

<!-- Fiche article -->
<div class="modal fade" id="shopItem" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-lg modal-fullscreen-sm-down"><div class="modal-content shop-modal">
  <button type="button" class="btn-close shop-modal-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
  <div class="row g-0">
    <div class="col-md-6"><div class="shop-modal-img" data-m-img></div></div>
    <div class="col-md-6 p-4 d-flex flex-column">
      <h3 class="h4 fw-bold" data-m-name></h3>
      <div class="shop-price fs-4 mb-2" data-m-price></div>
      <div class="mb-3" data-m-stock></div>
      <div class="shop-desc flex-grow-1" data-m-desc></div>
      <div class="d-flex gap-2 align-items-center mt-3">
        <div class="shop-qty"><button type="button" data-m-minus aria-label="Moins">−</button><input type="number" min="1" step="1" value="1" data-m-qty aria-label="Quantité"><button type="button" data-m-plus aria-label="Plus">+</button></div>
        <button type="button" class="btn btn-shop btn-lg flex-grow-1" data-m-add><i class="bi bi-bag-plus"></i> Ajouter au panier</button>
      </div>
    </div>
  </div>
</div></div></div>

<!-- Panier -->
<div class="offcanvas offcanvas-end shop-drawer" tabindex="-1" id="shopCart" aria-labelledby="shopCartTitle">
  <div class="offcanvas-header"><h5 class="offcanvas-title fw-bold" id="shopCartTitle"><i class="bi bi-bag"></i> Mon panier</h5><button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Fermer"></button></div>
  <div class="offcanvas-body d-flex flex-column">
    <div class="flex-grow-1" data-cart-lines></div>
    <div class="shop-cart-total border-top pt-3">
      <div class="d-flex justify-content-between fs-5 fw-bold"><span>Total articles</span><span data-cart-total>0</span></div>
      <div class="small text-secondary mb-2" data-cart-hint></div>
      <a class="btn btn-shop btn-lg w-100" href="<?= e(url('/boutique/panier')) ?>" data-checkout><i class="bi bi-lock"></i> Commander</a>
    </div>
  </div>
</div>
<button type="button" class="shop-fab d-md-none" data-open-cart aria-label="Panier"><i class="bi bi-bag"></i><span class="shop-badge" data-cart-count hidden>0</span><span class="shop-fab-total" data-cart-total-fab></span></button>
<div class="shop-toast" data-toast role="status" aria-live="polite"></div>

<?php include __DIR__ . '/_foot.php'; ?>
<script type="application/json" id="shop-config"><?= json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
<script type="application/json" id="shop-catalog"><?= json_encode($catalog, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
<script type="application/json" id="shop-categories"><?= json_encode(array_map(fn ($c) => ['id' => (int) $c['id'], 'parent' => $c['parent_id'] ? (int) $c['parent_id'] : null, 'name' => $c['name']], $categories), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
<script src="<?= e(asset('vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(asset('js/progress.js')) ?>"></script>
<script src="<?= e(asset('addons/boutique/shop.js')) ?>"></script>
</body>
</html>
