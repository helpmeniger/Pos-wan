<?php
declare(strict_types=1);

namespace App\Core;

final class Auth
{
    private static ?array $user = null;
    private static ?array $permissions = null;
    private static bool $loaded = false;

    /** Raison de la dernière déconnexion forcée (affichée sur la page de connexion). */
    public static ?string $logoutReason = null;

    public static function hashPassword(string $password): string
    {
        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
        return password_hash($password, $algo);
    }

    /** @return string[] liste des erreurs (vide si le mot de passe est conforme) */
    public static function passwordPolicyErrors(string $password, string $username = ''): array
    {
        $errors = [];
        $min = Settings::int('password_min_length') ?: 12;
        if (mb_strlen($password) < $min) {
            $errors[] = "Le mot de passe doit comporter au moins $min caractères.";
        }
        $classes = (int) preg_match('/[a-z]/', $password) + (int) preg_match('/[A-Z]/', $password)
            + (int) preg_match('/\d/', $password) + (int) preg_match('/[^a-zA-Z\d]/', $password);
        if ($classes < 3) {
            $errors[] = 'Le mot de passe doit combiner au moins trois types de caractères (minuscules, majuscules, chiffres, symboles).';
        }
        if ($username !== '' && stripos($password, $username) !== false) {
            $errors[] = "Le mot de passe ne doit pas contenir l'identifiant.";
        }
        return $errors;
    }

    /** @return array{ok: bool, error?: string} */
    public static function attempt(string $username, string $password): array
    {
        $username = mb_strtolower(trim($username));
        $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? 'cli'), 0, 45);
        $generic = 'Identifiant ou mot de passe incorrect.';
        $max = Settings::int('login_max_attempts') ?: 5;
        $window = Settings::int('lockout_minutes') ?: 15;
        $since = date('Y-m-d H:i:s', time() - $window * 60);

        $ipFailures = (int) Database::value(
            'SELECT COUNT(*) FROM login_attempts WHERE ip_address = :ip AND success = 0 AND attempted_at >= :s',
            ['ip' => $ip, 's' => $since]
        );
        if ($ipFailures >= $max * 4) {
            self::recordAttempt($username, $ip, false);
            Audit::log('auth.blocked_ip', null, null, ['username' => $username], null, $username);
            return ['ok' => false, 'error' => "Trop de tentatives depuis ce poste. Réessayez dans $window minutes."];
        }

        $user = $username === '' ? null : Database::one('SELECT * FROM users WHERE username = :u', ['u' => $username]);
        if (!$user) {
            // Temps de réponse homogène pour ne pas révéler l'existence d'un compte.
            self::hashPassword($password);
            self::recordAttempt($username, $ip, false);
            Audit::log('auth.failure', null, null, ['username' => $username, 'reason' => 'compte inconnu'], null, $username);
            return ['ok' => false, 'error' => $generic];
        }

        if ($user['locked_until'] !== null && strtotime((string) $user['locked_until']) > time()) {
            self::recordAttempt($username, $ip, false);
            Audit::log('auth.failure', 'user', (int) $user['id'], ['reason' => 'compte verrouillé'], (int) $user['id'], $username);
            return ['ok' => false, 'error' => 'Compte temporairement verrouillé après plusieurs échecs. Réessayez plus tard ou contactez l\'administrateur.'];
        }

        if (!password_verify($password, (string) $user['password_hash'])) {
            $failed = (int) $user['failed_attempts'] + 1;
            $lockUntil = $failed >= $max ? date('Y-m-d H:i:s', time() + $window * 60) : null;
            Database::update('users', [
                'failed_attempts' => $lockUntil ? 0 : $failed,
                'locked_until'    => $lockUntil,
            ], 'id = :id', ['id' => (int) $user['id']]);
            self::recordAttempt($username, $ip, false);
            Audit::log($lockUntil ? 'auth.locked' : 'auth.failure', 'user', (int) $user['id'],
                ['reason' => 'mot de passe incorrect', 'tentative' => $failed], (int) $user['id'], $username);
            return ['ok' => false, 'error' => $generic];
        }

        if (!(int) $user['is_active']) {
            self::recordAttempt($username, $ip, false);
            Audit::log('auth.failure', 'user', (int) $user['id'], ['reason' => 'compte désactivé'], (int) $user['id'], $username);
            return ['ok' => false, 'error' => 'Ce compte est désactivé. Contactez l\'administrateur.'];
        }

        if ((int) $user['is_demo'] && !Config::isDemo()) {
            self::recordAttempt($username, $ip, false);
            Audit::log('auth.failure', 'user', (int) $user['id'], ['reason' => 'compte de démonstration en production'], (int) $user['id'], $username);
            return ['ok' => false, 'error' => 'Les comptes de démonstration sont interdits en production.'];
        }

        $inactivityDays = Settings::int('account_inactivity_days') ?: 90;
        $threshold = time() - $inactivityDays * 86400;
        // Référence : dernière connexion, ou dernière intervention de l'administrateur (réactivation).
        if ($user['last_login_at'] !== null && strtotime((string) $user['last_login_at']) < $threshold
            && strtotime((string) $user['updated_at']) < $threshold) {
            Database::update('users', [
                'is_active' => 0,
                'disabled_reason' => "Inactivité supérieure à $inactivityDays jours",
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'id = :id', ['id' => (int) $user['id']]);
            self::recordAttempt($username, $ip, false);
            Audit::log('user.disabled_inactivity', 'user', (int) $user['id'], [], (int) $user['id'], $username);
            return ['ok' => false, 'error' => 'Compte désactivé pour inactivité prolongée. Contactez l\'administrateur.'];
        }

        // Succès
        $update = ['failed_attempts' => 0, 'locked_until' => null, 'last_login_at' => date('Y-m-d H:i:s')];
        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
        if (password_needs_rehash((string) $user['password_hash'], $algo)) {
            $update['password_hash'] = self::hashPassword($password);
        }
        Database::update('users', $update, 'id = :id', ['id' => (int) $user['id']]);
        self::recordAttempt($username, $ip, true);

        Session::regenerate();
        Csrf::rotate();
        Session::set('user_id', (int) $user['id']);
        Session::set('login_at', time());
        Session::set('last_activity', time());
        self::$loaded = false;
        Audit::log('auth.login', 'user', (int) $user['id'], [], (int) $user['id'], $username);
        return ['ok' => true];
    }

    private static function recordAttempt(string $username, string $ip, bool $success): void
    {
        Database::insert('login_attempts', [
            'username' => mb_substr($username, 0, 60),
            'ip_address' => $ip,
            'success' => $success ? 1 : 0,
            'attempted_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Vérifie la session courante : expiration d'inactivité, durée maximale,
     * compte toujours actif. À appeler à chaque requête.
     */
    public static function check(bool $touch = true): bool
    {
        $id = Session::get('user_id');
        if (!$id) {
            return false;
        }
        $idle = (Settings::int('session_idle_minutes') ?: 15) * 60;
        $max = (Settings::int('session_max_hours') ?: 8) * 3600;
        $now = time();
        if ($now - (int) Session::get('last_activity', 0) > $idle) {
            self::logout('session.expired', 'Votre session a expiré après une période d\'inactivité.');
            return false;
        }
        if ($now - (int) Session::get('login_at', 0) > $max) {
            self::logout('session.max_duration', 'Durée maximale de session atteinte. Veuillez vous reconnecter.');
            return false;
        }
        $user = self::user();
        if (!$user || !(int) $user['is_active']) {
            self::logout('session.revoked', 'Votre compte a été désactivé.');
            return false;
        }
        if ($touch) {
            Session::set('last_activity', $now);
        }
        return true;
    }

    public static function user(): ?array
    {
        if (!self::$loaded) {
            self::$loaded = true;
            self::$permissions = null;
            $id = Session::get('user_id');
            self::$user = $id ? Database::one(
                'SELECT u.*, r.code AS role_code, r.label AS role_label FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = :id',
                ['id' => (int) $id]
            ) : null;
            if (self::$user) {
                unset(self::$user['password_hash']);
            }
        }
        return self::$user;
    }

    public static function id(): ?int
    {
        return isset(self::user()['id']) ? (int) self::user()['id'] : null;
    }

    public static function permissions(): array
    {
        if (self::$permissions === null) {
            $user = self::user();
            self::$permissions = $user ? array_column(Database::all(
                'SELECT p.code FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = :r',
                ['r' => (int) $user['role_id']]
            ), 'code') : [];
        }
        return self::$permissions;
    }

    public static function can(string $permission): bool
    {
        return in_array($permission, self::permissions(), true);
    }

    public static function require(string $permission): void
    {
        if (!self::can($permission)) {
            Audit::log('access.denied', null, null, ['permission' => $permission, 'route' => (string) ($_GET['r'] ?? '')]);
            throw new HttpException(403, "Vous n'avez pas l'autorisation d'effectuer cette action.");
        }
    }

    public static function logout(string $action = 'auth.logout', ?string $reason = null): void
    {
        if (Session::get('user_id')) {
            $user = self::user();
            Audit::log($action, 'user', isset($user['id']) ? (int) $user['id'] : null, [],
                isset($user['id']) ? (int) $user['id'] : null, $user['username'] ?? null);
        }
        Session::destroy();
        Session::start();
        if ($reason) {
            Session::flash('warning', $reason);
        }
        self::$user = null;
        self::$permissions = null;
        self::$loaded = true;
    }

    /** Utilisé par les tests et les scripts CLI. */
    public static function loginAs(int $userId): void
    {
        $_SESSION['user_id'] = $userId;
        $_SESSION['login_at'] = time();
        $_SESSION['last_activity'] = time();
        self::$loaded = false;
    }

    public static function reset(): void
    {
        self::$user = null;
        self::$permissions = null;
        self::$loaded = false;
    }
}
