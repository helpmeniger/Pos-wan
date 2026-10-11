<?php
/**
 * En-tête commun. Variables optionnelles définies par la page :
 *   $pageTitle   (string)  titre
 *   $activeMenu  (string)  entrée de menu active
 *   $vendor      (array)   bibliothèques : leaflet, geoman, turf, chart
 *   $bodyClass   (string)  classe CSS du <body>
 */
declare(strict_types=1);

$pageTitle = $pageTitle ?? 'Niger Parcelles';
$vendor = $vendor ?? [];
$user = current_user();
$jsConfig = [
    'base' => base_path(),
    'csrf' => csrf_token(),
    'user' => $user ? ['id' => (int) $user['id'], 'name' => $user['full_name'], 'role' => $user['role_code']] : null,
    'perms' => $user ? array_values(array_filter(array_keys(PERMISSIONS), 'can')) : [],
];
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($pageTitle) ?> · Niger Parcelles</title>
    <link rel="icon" href="<?= e(asset('img/logo.svg')) ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/css/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
    <?php if (in_array('leaflet', $vendor, true)): ?>
    <link rel="stylesheet" href="<?= e(asset('vendor/leaflet/leaflet.css')) ?>">
    <?php endif; ?>
    <?php if (in_array('geoman', $vendor, true)): ?>
    <link rel="stylesheet" href="<?= e(asset('vendor/geoman/leaflet-geoman.css')) ?>">
    <?php endif; ?>
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <script type="application/json" id="np-config"><?= json_encode($jsConfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
</head>
<body class="<?= e($bodyClass ?? '') ?>">
<?php if ($user): ?>
<div class="np-layout">
    <?php require __DIR__ . '/sidebar.php'; ?>
    <div class="np-main">
        <header class="np-topbar">
            <button class="btn btn-sm btn-outline-secondary d-lg-none me-2" type="button" data-bs-toggle="offcanvas" data-bs-target="#npSidebar" aria-label="Menu">
                <i class="bi bi-list"></i>
            </button>
            <form class="np-search flex-grow-1 position-relative" action="<?= e(url('parcelles.php')) ?>" method="get" role="search" autocomplete="off">
                <i class="bi bi-search np-search-icon"></i>
                <input class="form-control form-control-sm" type="search" name="q" id="npGlobalSearch"
                       placeholder="Référence de parcelle, référence cadastrale<?= can('owner.view_personal') ? ', propriétaire' : '' ?>…"
                       value="<?= e($_GET['q'] ?? '') ?>" aria-label="Recherche">
                <div class="np-autocomplete list-group shadow" id="npGlobalSearchResults" hidden></div>
            </form>
            <div class="dropdown ms-2">
                <button class="btn btn-sm btn-light dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="bi bi-person-circle"></i> <span class="d-none d-md-inline"><?= e($user['full_name']) ?></span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><span class="dropdown-item-text small text-muted"><?= e($user['role_name']) ?></span></li>
                    <li><a class="dropdown-item" href="<?= e(url('profil.php')) ?>"><i class="bi bi-key"></i> Mon mot de passe</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li>
                        <form action="<?= e(url('logout.php')) ?>" method="post" class="px-3">
                            <?= csrf_field() ?>
                            <button class="btn btn-sm btn-outline-danger w-100"><i class="bi bi-box-arrow-right"></i> Déconnexion</button>
                        </form>
                    </li>
                </ul>
            </div>
        </header>
        <main class="np-content">
            <div class="np-flashes">
            <?php foreach (take_flashes() as $f): ?>
                <div class="alert alert-<?= e($f['type']) ?> alert-dismissible fade show" role="alert">
                    <?= e($f['message']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fermer"></button>
                </div>
            <?php endforeach; ?>
            </div>
<?php else: ?>
<main class="np-content-public">
<?php endif; ?>
