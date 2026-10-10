<div class="labels">
<?php foreach ($products as $p): for ($i = 0; $i < $copies; $i++): ?>
  <div class="label">
    <div class="lname"><?= e($p['name']) ?></div>
    <?= App\Services\Barcode::svg($p['barcode'] ?: $p['reference'], 46) ?>
    <div class="lprice"><?= money($p['sale_price']) ?></div>
  </div>
<?php endfor; endforeach; ?>
</div>
