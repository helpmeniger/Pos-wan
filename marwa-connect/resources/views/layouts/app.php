<?php
use App\Core\Auth;
use App\Core\View;
$user = Auth::user();
$theme = $user['theme'] ?? 'auto';
$pageTitle = $title ?? 'Tableau de bord';
$primary = (string) setting('appearance.primary_color', '#D7192A');
$nav = (string) setting('appearance.nav_color', '#0B1A33');
$brandStyle = (preg_match('/^#[0-9A-Fa-f]{6}$/', $primary) ? '--mc-red:' . $primary . ';' : '') . (preg_match('/^#[0-9A-Fa-f]{6}$/', $nav) ? '--mc-navy:' . $nav . ';' : '');
if ($theme === 'auto') {
    $theme = (string) setting('appearance.default_theme', 'auto') === 'dark' ? 'dark' : 'auto';
}
?><!doctype html>
<html lang="fr" data-bs-theme="<?= $theme === 'dark' ? 'dark' : 'light' ?>" data-theme-pref="<?= e($theme) ?>" style="<?= e($brandStyle) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <meta name="app-base" content="<?= e(rtrim(url('/'), '/')) ?>">
    <title><?= e($pageTitle) ?> · <?= e(config('app.name')) ?></title>
    <link rel="icon" href="<?= e(asset('images/favicon.svg')) ?>" type="image/svg+xml">
    <script src="<?= e(asset('js/theme-init.js')) ?>"></script>
    <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/marwa.css')) ?>">
</head>
<body class="mc-app">
<a class="visually-hidden-focusable mc-skip" href="#mc-content">Aller au contenu</a>

<?= View::partial('components/topbar', ['user' => $user]) ?>

<div class="mc-shell">
    <main class="mc-main" id="mc-content" tabindex="-1">
        <?= View::partial('components/flash') ?>
        <?= View::section('content') ?>
        <footer class="mc-footer">
            <span><?= e(config('app.name')) ?> v<?= e(config('app.version')) ?> — <?= e(config('app.tagline')) ?></span>
            <span><?= e(date('d/m/Y H:i')) ?> (<?= e(config('app.timezone')) ?>)</span>
        </footer>
    </main>
    <?= View::partial('components/sidebar', ['user' => $user]) ?>
</div>
<div class="mc-backdrop" data-mc-sidebar-close></div>

<div class="toast-container position-fixed bottom-0 start-0 p-3" id="mc-toasts"></div>

<script src="<?= e(asset('vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
<?= View::section('scripts') ?>
</body>
</html>
