<?php if ($p['pages'] > 1): ?>
<nav class="d-flex justify-content-between align-items-center mt-2 d-print-none">
  <small class="text-secondary"><?= (int) $p['total'] ?> résultat(s) · page <?= (int) $p['page'] ?>/<?= (int) $p['pages'] ?></small>
  <ul class="pagination pagination-sm mb-0">
    <li class="page-item <?= $p['page'] <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= e(query_with(['page' => $p['page'] - 1])) ?>">‹</a></li>
    <?php for ($i = max(1, $p['page'] - 3); $i <= min($p['pages'], $p['page'] + 3); $i++): ?>
      <li class="page-item <?= $i === $p['page'] ? 'active' : '' ?>"><a class="page-link" href="<?= e(query_with(['page' => $i])) ?>"><?= $i ?></a></li>
    <?php endfor; ?>
    <li class="page-item <?= $p['page'] >= $p['pages'] ? 'disabled' : '' ?>"><a class="page-link" href="<?= e(query_with(['page' => $p['page'] + 1])) ?>">›</a></li>
  </ul>
</nav>
<?php else: ?><small class="text-secondary d-block mt-2"><?= (int) $p['total'] ?> résultat(s)</small><?php endif; ?>
