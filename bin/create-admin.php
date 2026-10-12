<?php
declare(strict_types=1);

/**
 * Création du premier administrateur.
 *   php bin/create-admin.php --email=vous@domaine.ne --name="Prénom Nom" [--phone=+227XXXXXXXX]
 * Le mot de passe est demandé de façon interactive (non affiché, jamais stocké en clair).
 */
require dirname(__DIR__) . '/bootstrap/app.php';

use App\Core\Auth;
use App\Core\Database;
use App\Core\Str;
use App\Core\Validator;

$o = getopt('', ['email:', 'name:', 'phone:', 'password:']);
try {
    $password = $o['password'] ?? null;
    if ($password === null) {
        echo 'Mot de passe (8 caractères min., lettre + chiffre) : ';
        $tty = DIRECTORY_SEPARATOR === '/' && function_exists('posix_isatty') && posix_isatty(STDIN);
        if ($tty) {
            system('stty -echo');
        }
        $password = trim((string) fgets(STDIN));
        if ($tty) {
            system('stty echo');
        }
        echo PHP_EOL;
    }
    $v = Validator::make(['email' => $o['email'] ?? null, 'full_name' => $o['name'] ?? null, 'phone' => $o['phone'] ?? null, 'password' => $password],
        ['email' => 'required|email', 'full_name' => 'required|string|max:120', 'phone' => 'nullable|phone', 'password' => 'required|password']);
} catch (App\Core\ValidationException $e) {
    foreach ($e->errors as $f => $m) {
        fwrite(STDERR, "$f : $m\n");
    }
    exit(1);
}
if (Database::value('SELECT 1 FROM users WHERE email = :e', ['e' => $v['email']])) {
    fwrite(STDERR, "Un compte existe déjà avec cet e-mail.\n");
    exit(1);
}
$id = Database::insert('users', [
    'uuid' => Str::uuid(),
    'role_id' => (int) Database::value("SELECT id FROM roles WHERE code = 'admin'"),
    'full_name' => $v['full_name'], 'email' => $v['email'], 'phone' => $v['phone'] ?? null,
    'password_hash' => Auth::hashPassword($v['password']), 'status' => 'active',
]);
App\Core\Audit::log('staff.created_cli', 'user', $id, null, ['email' => $v['email'], 'role' => 'admin'], $id);
echo "Administrateur créé (id $id). Connectez-vous sur /connexion avec {$v['email']}.\n";
