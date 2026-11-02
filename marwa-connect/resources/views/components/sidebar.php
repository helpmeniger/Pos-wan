<?php
$menu = config('menu', []);
// Ne garder que les sections ayant au moins un élément autorisé.
$visible = [];
$pendingSection = null;
foreach ($menu as $item) {
    if (isset($item['section'])) {
        $pendingSection = $item;
        continue;
    }
    if (!can(...$item['can']) || (isset($item['roles']) && !App\Core\Auth::hasRole(...$item['roles']))) {
        continue;
    }
    if ($pendingSection) {
        $visible[] = $pendingSection;
        $pendingSection = null;
    }
    $visible[] = $item;
}
?>
<aside class="mc-sidebar" id="mc-sidebar" aria-label="Navigation principale">
    <div class="mc-sidebar-head">
        <span class="mc-sidebar-title">Navigation</span>
        <button class="btn mc-icon-btn mc-icon-btn-dark d-lg-none" type="button" data-mc-sidebar-close aria-label="Fermer le menu"><i class="bi bi-x-lg"></i></button>
    </div>
    <nav class="mc-nav">
        <?php foreach ($visible as $item): ?>
            <?php if (isset($item['section'])): ?>
                <div class="mc-nav-section"><?= e($item['section']) ?></div>
            <?php else: ?>
                <a class="mc-nav-link <?= is_active_path($item['path']) ? 'active' : '' ?>" href="<?= e(url($item['path'])) ?>" title="<?= e($item['label']) ?>">
                    <i class="bi <?= e($item['icon']) ?>"></i>
                    <span class="mc-nav-label"><?= e($item['label']) ?></span>
                </a>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>
    <div class="mc-sidebar-foot">
        <button class="btn mc-collapse-btn d-none d-lg-flex" type="button" id="mc-sidebar-collapse" aria-label="Réduire le menu">
            <i class="bi bi-chevron-double-right"></i><span class="mc-nav-label">Réduire</span>
        </button>
    </div>
</aside>
