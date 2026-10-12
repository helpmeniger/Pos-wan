<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

use Verifia\Auth;
use Verifia\Csrf;
use Verifia\Http;
use Verifia\View;

if (Auth::user()) {
    Http::redirect('index.php');
}
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verifyRequest();
    [$ok, $error] = Auth::attempt(trim((string) ($_POST['username'] ?? '')), (string) ($_POST['password'] ?? ''));
    if ($ok) {
        $next = (string) ($_GET['next'] ?? 'index.php');
        Http::redirect(preg_match('/^[a-z_]+\.php(\?[\w=&%-]*)?$/', $next) ? $next : 'index.php');
    }
}
View::header('Connexion', '', ['bare' => true]);
?>
<div class="auth-box card"><div class="card-body">
<h1 class="h4 mb-1">VERIFIA</h1>
<p class="text-body-secondary small">Surveillance, analyse et centre d'alertes</p>
<?php if (isset($_GET['created'])): ?><div class="alert alert-success">Administrateur créé. Connectez-vous.</div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<form method="post"><?= Csrf::field() ?>
<div class="mb-2"><label class="form-label">Identifiant</label><input class="form-control" name="username" autocomplete="username" required autofocus></div>
<div class="mb-3"><label class="form-label">Mot de passe</label><input class="form-control" type="password" name="password" autocomplete="current-password" required></div>
<button class="btn btn-primary w-100">Se connecter</button>
</form></div></div>
<?php View::footer();
