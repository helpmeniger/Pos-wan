<?php
/** Boutique en ligne : panier et validation de la commande (le panier est gardé dans le navigateur). */
$withCart = false;
$pageTitle = 'Ma commande';
include __DIR__ . '/_head.php';
?>
<main class="container py-4 pb-5">
  <a href="<?= e(url('/boutique')) ?>" class="shop-back"><i class="bi bi-arrow-left"></i> Continuer mes achats</a>
  <h1 class="h3 fw-bold mt-2 mb-3">Ma commande</h1>
  <div class="shop-empty d-none" data-empty><i class="bi bi-bag-x"></i><p>Votre panier est vide.</p><a class="btn btn-shop" href="<?= e(url('/boutique')) ?>">Voir les articles</a></div>
  <form class="row g-4" data-checkout-form novalidate>
    <div class="col-lg-7 order-lg-1 order-2">
      <div class="shop-card p-3 p-md-4">
        <h2 class="h5 fw-bold mb-3"><span class="shop-step">1</span> Vos coordonnées</h2>
        <div class="row g-3">
          <div class="col-md-6"><label class="form-label" for="cName">Nom et prénom</label><input class="form-control form-control-lg" id="cName" name="name" autocomplete="name" required maxlength="150"></div>
          <div class="col-md-6"><label class="form-label" for="cPhone">Téléphone</label><input class="form-control form-control-lg" id="cPhone" name="phone" type="tel" inputmode="tel" autocomplete="tel" required maxlength="50" placeholder="+227 …"></div>
          <div class="col-12"><label class="form-label" for="cMail">E-mail <span class="text-secondary small">(facultatif)</span></label><input class="form-control" id="cMail" name="email" type="email" autocomplete="email" maxlength="150"></div>
          <div class="shop-hp" aria-hidden="true"><label>Site web <input name="website" tabindex="-1" autocomplete="off"></label></div>
        </div>
        <h2 class="h5 fw-bold mt-4 mb-3"><span class="shop-step">2</span> Retrait ou livraison</h2>
        <div class="shop-choice">
          <label><input type="radio" name="delivery" value="pickup" checked><span><i class="bi bi-shop"></i><b>Retrait en boutique</b><small>Gratuit · nous vous appelons quand c'est prêt</small></span></label>
          <?php if ($config['delivery']): ?><label><input type="radio" name="delivery" value="delivery"><span><i class="bi bi-truck"></i><b>Livraison</b><small><?= $config['fee'] > 0 ? e(money($config['fee'])) : 'Frais à convenir' ?></small></span></label><?php endif; ?>
        </div>
        <div class="mt-3 d-none" data-address><label class="form-label" for="cAddr">Adresse de livraison</label><input class="form-control" id="cAddr" name="address" maxlength="255" placeholder="Quartier, rue, repère"></div>
        <div class="mt-3"><label class="form-label" for="cNote">Message <span class="text-secondary small">(facultatif)</span></label><textarea class="form-control" id="cNote" name="note" rows="2" maxlength="500" placeholder="Taille, couleur, heure de passage…"></textarea></div>
        <div class="alert alert-danger mt-3 d-none" data-error role="alert"></div>
        <button class="btn btn-shop btn-lg w-100 mt-3" data-submit <?= $open ? '' : 'disabled' ?>><i class="bi bi-check2-circle"></i> Confirmer la commande · <span data-grand>0</span></button>
        <p class="small text-secondary mt-2 mb-0"><i class="bi bi-shield-check"></i> Paiement au retrait ou à la livraison. Vous recevez votre reçu de commande immédiatement.</p>
      </div>
    </div>
    <div class="col-lg-5 order-lg-2 order-1">
      <div class="shop-card p-3 p-md-4 shop-sticky">
        <h2 class="h5 fw-bold mb-3"><i class="bi bi-bag"></i> Récapitulatif</h2>
        <div data-cart-lines></div>
        <div class="border-top pt-2 mt-2">
          <div class="d-flex justify-content-between"><span>Articles</span><span data-sub>0</span></div>
          <div class="d-flex justify-content-between d-none" data-fee-row><span>Livraison</span><span data-fee>0</span></div>
          <div class="d-flex justify-content-between fs-5 fw-bold mt-1"><span>Total</span><span data-grand2>0</span></div>
          <div class="small text-warning-emphasis mt-1" data-cart-hint></div>
        </div>
      </div>
    </div>
  </form>
</main>
<?php include __DIR__ . '/_foot.php'; ?>
<script type="application/json" id="shop-config"><?= json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
<script src="<?= e(asset('vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(asset('js/progress.js')) ?>"></script>
<script src="<?= e(asset('addons/boutique/shop.js')) ?>"></script>
</body>
</html>
