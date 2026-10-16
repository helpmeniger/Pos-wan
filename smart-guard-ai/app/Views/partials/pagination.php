<?php
/** @var int $page @var int $pages */
if (($pages ?? 1) <= 1) { return; }
$q = $_GET;
?>
<nav class="mt-3"><ul class="pagination pagination-sm flex-wrap">
<?php for ($i = max(1, $page - 4); $i <= min($pages, $page + 4); $i++): $q['page'] = $i; ?>
  <li class="page-item<?= $i === $page ? ' active' : '' ?>"><a class="page-link" href="?<?= e(http_build_query($q)) ?>"><?= $i ?></a></li>
<?php endfor; ?>
<li class="page-item disabled"><span class="page-link"><?= (int) $page ?> / <?= (int) $pages ?></span></li>
</ul></nav>
