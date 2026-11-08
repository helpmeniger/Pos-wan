<footer class="shop-footer">
  <div class="container d-flex flex-wrap gap-3 justify-content-between align-items-center">
    <div><b><?= e($shopTitle) ?></b><?php if ($address !== ''): ?><div class="small"><i class="bi bi-geo-alt"></i> <?= e($address) ?></div><?php endif; ?></div>
    <div class="d-flex gap-2 flex-wrap">
      <?php if ($phone !== ''): ?><a class="btn btn-sm btn-outline-light" href="tel:<?= e(preg_replace('/[^\d+]/', '', $phone)) ?>"><i class="bi bi-telephone"></i> <?= e($phone) ?></a><?php endif; ?>
      <?php if ($whatsapp !== ''): ?><a class="btn btn-sm btn-success" href="https://wa.me/<?= e($whatsapp) ?>" target="_blank" rel="noopener"><i class="bi bi-whatsapp"></i> WhatsApp</a><?php endif; ?>
    </div>
  </div>
  <div class="container small opacity-50 mt-2">Vos informations servent uniquement à traiter votre commande.</div>
</footer>
