<?php /** @var string $label @var string $value @var ?string $hint @var ?string $icon */ ?>
<div class="jn-card jn-kpi h-100">
  <div class="d-flex justify-content-between align-items-start">
    <div class="label"><?= e($label) ?></div>
    <?php if (!empty($icon)): ?><i class="bi bi-<?= e($icon) ?> text-primary" aria-hidden="true"></i><?php endif; ?>
  </div>
  <div class="value"><?= e($value) ?></div>
  <?php if (!empty($hint)): ?><div class="hint"><?= e($hint) ?></div><?php endif; ?>
</div>
