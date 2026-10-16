<?php /* Filtres communs : période, caméra, produit. Variables : $from, $to, $cameras, $products, $extra (html) */ ?>
<form class="row g-2 mb-3 no-print" method="get">
  <div class="col-6 col-md-2"><label class="form-label small mb-0">Du</label><input type="date" name="from" class="form-control form-control-sm" value="<?= e($from) ?>"></div>
  <div class="col-6 col-md-2"><label class="form-label small mb-0">Au</label><input type="date" name="to" class="form-control form-control-sm" value="<?= e($to) ?>"></div>
  <div class="col-6 col-md-2"><label class="form-label small mb-0">Caméra</label><select name="camera_id" class="form-select form-select-sm"><option value="">Toutes</option>
    <?php foreach ($cameras as $c): ?><option value="<?= (int) $c['id'] ?>" <?= (int) ($_GET['camera_id'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
  <div class="col-6 col-md-2"><label class="form-label small mb-0">Produit</label><select name="product_id" class="form-select form-select-sm"><option value="">Tous</option>
    <?php foreach ($products as $p): ?><option value="<?= (int) $p['id'] ?>" <?= (int) ($_GET['product_id'] ?? 0) === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select></div>
  <?= $extra ?? '' ?>
  <div class="col-12 col-md-auto d-flex align-items-end"><button class="btn btn-sm btn-outline-primary">Filtrer</button></div>
</form>
