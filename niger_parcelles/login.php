<?php
/** Page de connexion. */
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

if (current_user()) {
    redirect('dashboard.php');
}

$error = null;
$username = '';
if (!empty($_SESSION['expired'])) {
    unset($_SESSION['expired']);
    $error = 'Votre session a expiré après une période d\'inactivité. Veuillez vous reconnecter.';
}
if (request_method() === 'POST') {
    require_csrf();
    $username = (string) ($_POST['username'] ?? '');
    $error = attempt_login($username, (string) ($_POST['password'] ?? ''));
    if ($error === null) {
        $target = $_SESSION['after_login'] ?? null;
        unset($_SESSION['after_login']);
        // Redirection uniquement vers une page interne
        if (is_string($target) && strpos($target, base_path()) === 0 && strpos($target, '//') === false
            && strpos($target, 'logout') === false && strpos($target, '/api/') === false) {
            header('Location: ' . $target);
            exit;
        }
        redirect('dashboard.php');
    }
}

$pageTitle = 'Connexion';
require __DIR__ . '/includes/header.php';
?>
<div class="login-wrap">
    <div class="card login-card shadow-sm">
        <div class="card-body p-4">
            <div class="text-center mb-3">
                <img src="<?= e(asset('img/logo.svg')) ?>" alt="" width="64" height="64">
                <h1 class="h4 mt-2 mb-0 fw-bold">Niger Parcelles</h1>
                <div class="text-muted small">Cartographie et gestion foncière</div>
            </div>
            <?php foreach (take_flashes() as $f): ?>
                <div class="alert alert-<?= e($f['type']) ?> py-2"><?= e($f['message']) ?></div>
            <?php endforeach; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger py-2" role="alert"><i class="bi bi-exclamation-triangle"></i> <?= e($error) ?></div>
            <?php endif; ?>
            <form method="post" action="<?= e(url('login.php')) ?>" novalidate>
                <?= csrf_field() ?>
                <div class="mb-3">
                    <label class="form-label" for="username">Identifiant</label>
                    <input class="form-control" id="username" name="username" required autocomplete="username" autofocus maxlength="60" value="<?= e($username) ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label" for="password">Mot de passe</label>
                    <input class="form-control" id="password" name="password" type="password" required autocomplete="current-password">
                </div>
                <button class="btn btn-primary w-100" type="submit"><i class="bi bi-box-arrow-in-right"></i> Se connecter</button>
            </form>
            <p class="small text-muted mt-3 mb-0 text-center">Accès réservé aux agents habilités. Les connexions sont journalisées.</p>
        </div>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
