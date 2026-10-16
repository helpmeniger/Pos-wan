<?php
declare(strict_types=1);

/** Création (ou réinitialisation) d'un compte administrateur général. */
require __DIR__ . '/cli.php';

use App\Core\Auth;
use App\Core\Database;

$opts = getopt('', ['username::', 'name::', 'password::']);
$username = $opts['username'] ?? ask('Identifiant administrateur [admin] : ') ?: 'admin';
$name = $opts['name'] ?? ask('Nom complet [Administrateur] : ') ?: 'Administrateur';
$password = $opts['password'] ?? ask('Mot de passe (10 caractères min., lettres et chiffres) : ', true);
if (!preg_match('/^[a-zA-Z0-9._-]{3,60}$/', $username)) {
    out('[ERREUR] Identifiant invalide.');
    exit(1);
}
if ($err = Auth::passwordPolicyError($password)) {
    out('[ERREUR] ' . $err);
    exit(1);
}
$existing = Database::value('SELECT id FROM users WHERE username = :u', ['u' => $username]);
if ($existing) {
    Database::update('users', ['password_hash' => Auth::hashPassword($password), 'is_active' => 1, 'deleted_at' => null, 'full_name' => $name], 'id = :id', ['id' => $existing]);
    $id = (int) $existing;
    out('Compte existant réinitialisé.');
} else {
    $id = Database::insert('users', ['uuid' => uuid4(), 'username' => $username, 'full_name' => $name, 'password_hash' => Auth::hashPassword($password)]);
    out('Compte créé.');
}
Database::execute("INSERT IGNORE INTO user_roles (user_id, role_id) SELECT :u, id FROM roles WHERE code = 'admin'", ['u' => $id]);
Database::execute('INSERT IGNORE INTO store_users (store_id, user_id) SELECT id, :u FROM stores', ['u' => $id]);
App\Core\Audit::log('admin_cree_cli', 'user', $id);
out("Administrateur « {$username} » prêt.");
