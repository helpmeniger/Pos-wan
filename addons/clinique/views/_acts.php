<?php /* Choix des actes à facturer : $acts, $name (préfixe des champs) */ $name = $name ?? 'acts'; $cats = []; foreach ($acts as $a) { $cats[$a['category']][] = $a; } ?>
<div class="clin-acts" data-acts>
  <?php $i = 0; foreach ($cats as $cat => $list): ?>
  <div class="small fw-semibold text-secondary mt-2"><?= e($cat) ?></div>
  <?php foreach ($list as $a): ?>
  <div class="d-flex align-items-center gap-2 py-1 border-bottom">
    <input type="checkbox" class="form-check-input mt-0" name="<?= e($name) ?>[<?= $i ?>][act_id]" value="<?= (int) $a['id'] ?>" id="act<?= e($name) . $i ?>" data-act-price="<?= e((string) (float) $a['price']) ?>"<?= !empty($preselect) && in_array((int) $a['id'], $preselect, true) ? ' checked' : '' ?>>
    <label class="flex-grow-1 small" for="act<?= e($name) . $i ?>"><?= e($a['name']) ?></label>
    <input name="<?= e($name) ?>[<?= $i ?>][quantity]" value="1" class="form-control form-control-sm text-end" style="width:60px" inputmode="numeric" data-act-qty>
    <span class="small text-nowrap" style="min-width:90px;text-align:end"><?= money($a['price']) ?></span>
  </div>
  <?php $i++; endforeach; endforeach; ?>
  <div class="d-flex justify-content-between fw-bold mt-2"><span>Total</span><span data-acts-total><?= money(0) ?></span></div>
</div>
