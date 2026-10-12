<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

use Verifia\Auth;
use Verifia\Csrf;
use Verifia\Database;
use Verifia\Http;
use Verifia\View;

// Accessible uniquement tant qu'aucun compte n'existe, et seulement depuis le poste local.
if ((int) Database::value('SELECT COUNT(*) FROM users')) {
    Http::redirect('login.php');
}
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit('La création du premier administrateur doit être faite depuis le poste local.');
}
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verifyRequest();
    if (($_POST['password'] ?? '') !== ($_POST['password2'] ?? '')) {
        $error = 'Les mots de passe ne correspondent pas.';
    } else {
        try {
            Auth::createUser(trim($_POST['username'] ?? ''), trim($_POST['display_name'] ?? '') ?: 'Administrateur', (string) $_POST['password'], 'admin');
            Http::redirect('login.php?created=1');
        } catch (\InvalidArgumentException $e) {
            $error = $e->getMessage();
        }
    }
}
View::header('Première configuration', '', ['bare' => true]);
?>
<div class="auth-box card"><div class="card-body">
<h1 class="h4">VERIFIA — Première configuration</h1>
<p class="text-body-secondary">Créez le compte administrateur. Ce compte sert uniquement à VERIFIA : ne saisissez jamais ici un mot de passe de réseau social.</p>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<form method="post"><?= Csrf::field() ?>
<div class="mb-2"><label class="form-label">Identifiant</label><input class="form-control" name="username" required pattern="[A-Za-z0-9_.\-]{3,64}" value="admin"></div>
<div class="mb-2"><label class="form-label">Nom affiché</label><input class="form-control" name="display_name" value="Administrateur"></div>
<div class="mb-2"><label class="form-label">Mot de passe (10 caractères minimum)</label><input class="form-control" type="password" name="password" minlength="10" required></div>
<div class="mb-3"><label class="form-label">Confirmation</label><input class="form-control" type="password" name="password2" minlength="10" required></div>
<button class="btn btn-primary w-100">Créer l'administrateur</button>
</form></div></div>
<?php View::footer();
