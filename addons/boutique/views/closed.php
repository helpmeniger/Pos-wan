<?php $withCart = false; $pageTitle = 'Fermée'; $open = true; include __DIR__ . '/_head.php'; ?>
<main class="container py-5"><div class="shop-empty"><i class="bi bi-door-closed"></i><h1 class="h4">La boutique en ligne est fermée</h1><p>Revenez bientôt<?= $phone !== '' ? ' ou appelez le ' . e($phone) : '' ?>.</p></div></main>
<?php include __DIR__ . '/_foot.php'; ?>
</body></html>
