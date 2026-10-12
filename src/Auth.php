<?php
declare(strict_types=1);

namespace Verifia;

/** Authentification locale et contrôle d'accès par rôle. */
final class Auth
{
    /** Hiérarchie des rôles : un rôle hérite des droits des rôles inférieurs. */
    private const LEVELS = ['viewer' => 1, 'analyst' => 2, 'admin' => 3];

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        session_name((string) Config::get('security.session_name', 'VERIFIASESSID'));
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        ini_set('session.use_strict_mode', '1');
        session_start();
    }

    public static function attempt(string $username, string $password): array
    {
        $user = Database::one(
            'SELECT u.*, r.code AS role FROM users u JOIN roles r ON r.id = u.role_id WHERE u.username = ?',
            [$username]
        );
        // Temps constant approximatif même si l'utilisateur n'existe pas
        $hash = $user['password_hash'] ?? '$2y$10$usesomesillystringforsaltYfCJ8f3Z2a8W1L2bq3Ww9O7q2nHn6';
        if ($user && $user['locked_until'] && strtotime($user['locked_until'] . ' UTC') > time()) {
            return [false, 'Compte temporairement verrouillé après plusieurs échecs.'];
        }
        $ok = password_verify($password, $hash);
        if (!$user || !$ok || !(int) $user['is_active']) {
            if ($user) {
                $max = (int) Config::get('security.login_max_attempts', 5);
                $fails = (int) $user['failed_logins'] + 1;
                $lock = $fails >= $max
                    ? gmdate('Y-m-d H:i:s', time() + 60 * (int) Config::get('security.login_lock_minutes', 15))
                    : null;
                Database::run('UPDATE users SET failed_logins = ?, locked_until = ? WHERE id = ?', [$lock ? 0 : $fails, $lock, $user['id']]);
            }
            EventLog::audit($user['id'] ?? null, 'login_failed', 'user', null, ['username' => mb_substr($username, 0, 64)]);
            return [false, 'Identifiants invalides.'];
        }
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            Database::run('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $user['id']]);
        }
        Database::run('UPDATE users SET failed_logins = 0, locked_until = NULL, last_login_at = ? WHERE id = ?', [Database::now(), $user['id']]);
        session_regenerate_id(true);
        $_SESSION['uid'] = (int) $user['id'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['name'] = $user['display_name'];
        EventLog::audit((int) $user['id'], 'login', 'user', $user['id']);
        return [true, null];
    }

    public static function logout(): void
    {
        if (isset($_SESSION['uid'])) {
            EventLog::audit((int) $_SESSION['uid'], 'logout', 'user', $_SESSION['uid']);
        }
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    public static function user(): ?array
    {
        if (empty($_SESSION['uid'])) {
            return null;
        }
        return ['id' => (int) $_SESSION['uid'], 'role' => (string) $_SESSION['role'], 'name' => (string) $_SESSION['name']];
    }

    public static function id(): ?int
    {
        return isset($_SESSION['uid']) ? (int) $_SESSION['uid'] : null;
    }

    public static function can(string $role): bool
    {
        $u = self::user();
        return $u !== null && (self::LEVELS[$u['role']] ?? 0) >= (self::LEVELS[$role] ?? 99);
    }

    /** Exige une connexion et un rôle minimal ; sinon redirige (page) ou répond 401/403 (API). */
    public static function require(string $role = 'viewer', bool $api = false): array
    {
        $u = self::user();
        if ($u === null) {
            if ($api) {
                Http::json(['error' => 'Authentification requise'], 401);
            }
            Http::redirect('login.php');
        }
        if (!self::can($role)) {
            if ($api) {
                Http::json(['error' => 'Droits insuffisants'], 403);
            }
            http_response_code(403);
            exit('Accès refusé : rôle « ' . htmlspecialchars($role) . ' » requis.');
        }
        return $u;
    }

    public static function createUser(string $username, string $displayName, string $password, string $role): int
    {
        if (mb_strlen($password) < 10) {
            throw new \InvalidArgumentException('Le mot de passe doit contenir au moins 10 caractères.');
        }
        if (!preg_match('/^[A-Za-z0-9_.-]{3,64}$/', $username)) {
            throw new \InvalidArgumentException('Identifiant invalide (3 à 64 caractères : lettres, chiffres, _ . -).');
        }
        $roleId = Database::value('SELECT id FROM roles WHERE code = ?', [$role]);
        if (!$roleId) {
            throw new \InvalidArgumentException('Rôle inconnu.');
        }
        return Database::insert('users', [
            'role_id'       => $roleId,
            'username'      => $username,
            'display_name'  => $displayName,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        ]);
    }
}
