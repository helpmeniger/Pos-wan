<?php
/** Reçu de commande en ligne + suivi (page du client, imprimable). */
$withCart = false;
$pageTitle = 'Commande ' . $o['number'];
$open = true;
include __DIR__ . '/_head.php';
$steps = $o['delivery'] === 'delivery' ? ['new' => 'Reçue', 'confirmed' => 'Validée', 'paid' => 'Payée', 'delivered' => 'Livrée'] : ['new' => 'Reçue', 'confirmed' => 'Prête à préparer', 'paid' => 'Payée', 'delivered' => 'Retirée'];
$order = array_keys($steps);
$cur = array_search($o['status'], $order, true);
?>
<main class="container py-4 pb-5">
  <div class="shop-receipt mx-auto">
    <div class="text-center mb-3">
      <div class="shop-ok"><i class="bi <?= $o['status'] === 'cancelled' ? 'bi-x-lg' : 'bi-check-lg' ?>"></i></div>
      <h1 class="h4 fw-bold mb-1"><?= $o['status'] === 'cancelled' ? 'Commande annulée' : 'Merci, votre commande est enregistrée !' ?></h1>
      <div class="text-secondary">Commande <b><?= e($o['number']) ?></b> du <?= e(fdate($o['created_at'])) ?></div>
    </div>
    <?php if ($o['status'] !== 'cancelled'): ?>
    <ol class="shop-steps d-print-none"><?php foreach ($steps as $k => $label): $i = array_search($k, $order, true); ?><li class="<?= $cur !== false && $i <= $cur ? 'done' : '' ?><?= $i === $cur ? ' current' : '' ?>"><span></span><?= e($label) ?></li><?php endforeach; ?></ol>
    <?php else: ?><div class="alert alert-secondary">Motif : <?= e($o['cancel_reason'] ?? '') ?></div><?php endif; ?>
    <div class="shop-card p-3 p-md-4">
      <div class="d-flex justify-content-between flex-wrap gap-2 small mb-2">
        <div><b><?= e($o['customer_name']) ?></b><br><?= e($o['phone']) ?><?= $o['email'] ? '<br>' . e($o['email']) : '' ?></div>
        <div class="text-end"><?= $o['delivery'] === 'delivery' ? '<i class="bi bi-truck"></i> Livraison<br>' . e($o['address'] ?? '') : '<i class="bi bi-shop"></i> Retrait en boutique' ?></div>
      </div>
      <table class="table table-sm align-middle mb-2"><thead><tr><th>Article</th><th class="text-end">Qté</th><th class="text-end">Prix</th><th class="text-end">Total</th></tr></thead><tbody>
      <?php foreach ($o['items'] as $i): ?><tr><td><?= e($i['product_name']) ?></td><td class="text-end"><?= e(rtrim(rtrim(number_format((float) $i['quantity'], 3, ',', ' '), '0'), ',')) ?></td><td class="text-end text-nowrap"><?= e(money($i['unit_price'])) ?></td><td class="text-end text-nowrap"><?= e(money($i['line_total'])) ?></td></tr><?php endforeach; ?>
      </tbody><tfoot>
        <?php if ((float) $o['delivery_fee'] > 0): ?><tr><td colspan="3">Livraison</td><td class="text-end text-nowrap"><?= e(money($o['delivery_fee'])) ?></td></tr><?php endif; ?>
        <tr class="fw-bold fs-5"><td colspan="3">Total à payer</td><td class="text-end text-nowrap"><?= e(money($o['total'])) ?></td></tr>
      </tfoot></table>
      <?php if ($o['note']): ?><p class="small mb-2"><i class="bi bi-chat-left-text"></i> <?= e($o['note']) ?></p><?php endif; ?>
      <div class="d-flex align-items-center gap-3 border-top pt-3">
        <div data-qr="<?= e($self) ?>" data-qr-size="3" class="shop-qr"></div>
        <div class="small text-secondary">Présentez ce reçu (ou son QR code) au retrait ou au livreur. Le paiement se fait à ce moment-là. Cette page se met à jour avec l'avancement de votre commande.</div>
      </div>
    </div>
    <div class="d-flex flex-wrap gap-2 justify-content-center mt-3 d-print-none">
      <button type="button" class="btn btn-outline-dark" data-print><i class="bi bi-printer"></i> Imprimer / enregistrer en PDF</button>
      <?php if ($whatsapp !== ''): ?><a class="btn btn-success" target="_blank" rel="noopener" href="https://wa.me/<?= e($whatsapp) ?>?text=<?= rawurlencode('Bonjour, voici ma commande ' . $o['number'] . ' (' . money($o['total']) . ') : ' . $self) ?>"><i class="bi bi-whatsapp"></i> Envoyer à la boutique</a><?php endif; ?>
      <a class="btn btn-shop" href="<?= e(url('/boutique')) ?>"><i class="bi bi-bag"></i> Continuer mes achats</a>
    </div>
  </div>
</main>
<?php include __DIR__ . '/_foot.php'; ?>
<script src="<?= e(asset('vendor/qrcode/qrcode.js')) ?>"></script>
<script src="<?= e(asset('js/qr.js')) ?>"></script>
<script src="<?= e(asset('addons/boutique/shop.js')) ?>"></script>
</body>
</html>
