<?php
use App\Core\Auth;
use App\Core\Config;
use App\Core\Session;
use App\Core\Settings;

$user = Auth::user();
$route = (string) ($_GET['r'] ?? 'dashboard');
$nav = [
    ['dashboard', 'Tableau de bord', 'speedometer2', null],
    ['persons.index', 'Fiches individuelles', 'person-vcard', 'persons.view'],
    ['persons.create', 'Nouvelle fiche', 'person-plus', 'persons.create'],
    ['visits.index', 'Passages', 'calendar-check', 'visits.view'],
    ['procedures.index', 'Procédures', 'folder2-open', 'procedures.view'],
    ['duplicates.index', 'Doublons potentiels', 'people', 'duplicates.review'],
    ['duplicates.compare', 'Comparaison visuelle', 'layout-split', 'photos.view'],
    ['users.index', 'Utilisateurs', 'person-gear', 'users.manage'],
    ['audit.index', "Journal d'audit", 'journal-text', 'audit.view'],
    ['backups.index', 'Sauvegardes', 'hdd-stack', 'backups.manage'],
    ['settings.index', 'Paramètres', 'sliders', 'settings.manage'],
    ['help.index', 'Documentation', 'question-circle', null],
];
$active = function (string $r) use ($route): bool {
    $prefix = explode('.', $r)[0];
    return $route === $r || ($r !== 'persons.create' && $r !== 'duplicates.compare' && str_starts_with($route, $prefix . '.') && $r === $prefix . '.index');
};
?><!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <link rel="icon" href="data:,">
    <title><?= e($title ?? 'DOSSIER ID') ?> — DOSSIER ID</title>
    <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/css/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body data-idle-seconds="<?= (int) Settings::int('session_idle_minutes') * 60 ?>"
      data-logout-url="<?= e(url('auth.login')) ?>">
<?php if (Config::isDemo()): ?>
    <div class="demo-banner"><i class="bi bi-exclamation-triangle-fill"></i> ENVIRONNEMENT DE DÉMONSTRATION — toutes les données sont fictives. Ne saisissez aucune donnée réelle.</div>
<?php endif; ?>
<div class="app-shell">
    <aside class="sidebar" id="sidebar">
        <div class="brand">
            <i class="bi bi-shield-lock"></i>
            <div>
                <div class="brand-title">DOSSIER ID</div>
                <div class="brand-sub"><?= e(Settings::get('institution_name')) ?></div>
            </div>
        </div>
        <nav class="nav flex-column">
            <?php foreach ($nav as [$r, $label, $icon, $perm]): ?>
                <?php if ($perm === null || can($perm)): ?>
                    <a class="nav-link<?= $active($r) ? ' active' : '' ?>" href="<?= e(url($r)) ?>">
                        <i class="bi bi-<?= e($icon) ?>"></i><span><?= e($label) ?></span>
                    </a>
                <?php endif; ?>
            <?php endforeach; ?>
        </nav>
        <div class="sidebar-footer small">
            Outil d'aide à la gestion administrative.<br>Toute consultation est journalisée.
        </div>
    </aside>
    <div class="main">
        <header class="topbar d-print-none">
            <button class="btn btn-sm btn-outline-secondary d-lg-none" type="button" data-toggle-sidebar aria-label="Menu"><i class="bi bi-list"></i></button>
            <?php if (can('persons.view')): ?>
            <form class="quick-search" method="get" action="<?= e(url('persons.index')) ?>" role="search">
                <input type="hidden" name="r" value="persons.index">
                <i class="bi bi-search"></i>
                <input class="form-control form-control-sm" type="search" name="q" placeholder="Recherche rapide : nom, prénom…" aria-label="Recherche rapide">
            </form>
            <?php endif; ?>
            <div class="ms-auto d-flex align-items-center gap-3">
                <span class="session-timer small text-muted" id="session-timer" title="Déconnexion automatique en cas d'inactivité"></span>
                <div class="dropdown">
                    <button class="btn btn-sm btn-light dropdown-toggle" data-bs-toggle="dropdown" type="button">
                        <i class="bi bi-person-circle"></i> <?= e($user['full_name'] ?? '') ?>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><span class="dropdown-item-text small text-muted"><?= e($user['role_label'] ?? '') ?><br><?= e($user['username'] ?? '') ?></span></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="<?= e(url('auth.password')) ?>"><i class="bi bi-key"></i> Changer le mot de passe</a></li>
                        <li>
                            <form method="post" action="<?= e(url('auth.logout')) ?>">
                                <?= csrf_field() ?>
                                <button class="dropdown-item text-danger" type="submit" id="logout-btn"><i class="bi bi-box-arrow-right"></i> Se déconnecter</button>
                            </form>
                        </li>
                    </ul>
                </div>
            </div>
        </header>
        <main class="content">
            <?php foreach (Session::pullFlashes() as $f): ?>
                <div class="alert alert-<?= e($f['type']) ?> alert-dismissible fade show" role="alert">
                    <?= e($f['message']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fermer"></button>
                </div>
            <?php endforeach; ?>
            <?= $content ?>
        </main>
    </div>
</div>

<div class="modal fade" id="confirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title">Confirmation</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button></div>
            <div class="modal-body" id="confirmModalBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                <button type="button" class="btn btn-primary" id="confirmModalOk">Confirmer</button>
            </div>
        </div>
    </div>
</div>
<script src="<?= e(asset('vendor/bootstrap/js/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
