<div class="hub">
  <div class="d-flex justify-content-between align-items-center mb-2">
    <div class="fw-semibold"><i class="bi bi-grid-3x3-gap-fill text-warning"></i> <?= e(setting('company_name', 'POS Wan')) ?></div>
    <a href="<?= e(url(can('pos.sell') ? '/pos' : '/')) ?>" class="btn btn-sm btn-light"><i class="bi bi-x-lg"></i> Fermer</a>
  </div>
  <?php foreach ($groups as $g => $items): ?>
    <div class="hub-group-title"><?= e($g) ?> :</div>
    <div class="hub-grid">
      <?php foreach ($items as $it): ?>
        <a class="hub-item" href="<?= e(url($it[2])) ?>">
          <span class="hub-circle" style="background:<?= e($it[4]) ?>"><i class="bi <?= e($it[1]) ?>"></i></span>
          <span><?= e($it[0]) ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
</div>
