<?php
/** Menu latéral (offcanvas sur mobile, fixe sur grand écran). */
declare(strict_types=1);

$activeMenu = $activeMenu ?? '';
$menu = [
    ['dashboard', 'dashboard.php', 'speedometer2', 'Tableau de bord', 'parcel.view'],
    ['carte', 'carte.php', 'map', 'Carte foncière', 'parcel.view'],
    ['parcelles', 'parcelles.php', 'bounding-box', 'Parcelles', 'parcel.view'],
    ['proprietaires', 'proprietaires.php', 'people', 'Propriétaires', 'owner.view_personal'],
    ['documents', 'documents.php', 'folder2-open', 'Documents', 'document.view'],
    ['importation', 'importation.php', 'cloud-upload', 'Importation', 'import.run'],
    ['rapports', 'rapports.php', 'file-earmark-bar-graph', 'Rapports', 'report.view'],
    ['utilisateurs', 'utilisateurs.php', 'person-gear', 'Utilisateurs', 'user.manage'],
    ['parametres', 'parametres.php', 'gear', 'Paramètres', 'settings.manage'],
];
?>
<aside class="np-sidebar offcanvas-lg offcanvas-start" tabindex="-1" id="npSidebar" aria-label="Navigation principale">
    <div class="np-brand">
        <img src="<?= e(asset('img/logo.svg')) ?>" alt="" width="34" height="34">
        <div>
            <div class="np-brand-title">Niger Parcelles</div>
            <div class="np-brand-sub">Gestion foncière</div>
        </div>
        <button type="button" class="btn-close btn-close-white ms-auto d-lg-none" data-bs-dismiss="offcanvas" data-bs-target="#npSidebar" aria-label="Fermer"></button>
    </div>
    <nav class="nav flex-column np-nav">
        <?php foreach ($menu as [$key, $href, $icon, $label, $perm]): if (!can($perm)) { continue; } ?>
            <a class="nav-link<?= $activeMenu === $key ? ' active' : '' ?>" href="<?= e(url($href)) ?>"<?= $activeMenu === $key ? ' aria-current="page"' : '' ?>>
                <i class="bi bi-<?= e($icon) ?>"></i> <?= e($label) ?>
            </a>
        <?php endforeach; ?>
        <form action="<?= e(url('logout.php')) ?>" method="post" class="mt-2">
            <?= csrf_field() ?>
            <button class="nav-link btn btn-link text-start w-100"><i class="bi bi-box-arrow-right"></i> Déconnexion</button>
        </form>
    </nav>
    <div class="np-sidebar-foot small">
        Document de gestion : les fiches ne constituent pas des titres fonciers officiels.
    </div>
</aside>
