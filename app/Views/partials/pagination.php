<?php /** @var array{page: int, pages: int, total: int} $result */ if ($result['pages'] > 1): ?>
<nav aria-label="Pagination" class="mt-3">
  <ul class="pagination pagination-sm flex-wrap">
    <li class="page-item<?= $result['page'] <= 1 ? ' disabled' : '' ?>"><a class="page-link" href="<?= e(query_with(['page' => $result['page'] - 1])) ?>">Précédent</a></li>
    <?php for ($i = max(1, $result['page'] - 3); $i <= min($result['pages'], $result['page'] + 3); $i++): ?>
      <li class="page-item<?= $i === $result['page'] ? ' active' : '' ?>"><a class="page-link" href="<?= e(query_with(['page' => $i])) ?>"><?= $i ?></a></li>
    <?php endfor; ?>
    <li class="page-item<?= $result['page'] >= $result['pages'] ? ' disabled' : '' ?>"><a class="page-link" href="<?= e(query_with(['page' => $result['page'] + 1])) ?>">Suivant</a></li>
  </ul>
</nav>
<?php endif; ?>
