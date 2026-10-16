<?php $this->layout('layouts/app'); ?>
<div class="container py-4">
  <h1 class="h3">Catégories de métiers</h1>
  <p class="text-muted"><?= count($categories) ?> domaines, <?= (int) $tradesCount ?> métiers — ce catalogue évolue : l'équipe ajoute régulièrement de nouveaux métiers.</p>
  <div class="row g-3">
    <?php foreach ($categories as $c): ?>
      <div class="col-sm-6 col-lg-4">
        <a class="jn-card jn-card-link p-3" href="<?= e(url('/categories/' . $c['slug'])) ?>">
          <div class="d-flex gap-3">
            <span class="jn-icon"><i class="bi <?= e($c['icon'] ?: 'bi-briefcase') ?>" aria-hidden="true"></i></span>
            <div>
              <div class="fw-semibold"><?= e($c['name']) ?></div>
              <div class="text-muted small"><?= (int) $c['trades_count'] ?> métiers · <?= (int) $c['open_posts'] ?> mission(s) ouverte(s)</div>
            </div>
          </div>
        </a>
      </div>
    <?php endforeach; ?>
  </div>
</div>
