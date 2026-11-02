<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Authentification et autorisations (RBAC). Les permissions sont toujours
 * vérifiées côté serveur ; l'interface ne fait que masquer les éléments.
 */
final class Auth
{
    private static ?array $user = null;
    private static ?array $permissions = null;

    public static function attempt(string $login, string $password): ?array
    {
        $user = Database::fetch(
            'SELECT * FROM users WHERE (email = :l1 OR username = :l2) AND deleted_at IS NULL LIMIT 1',
            ['l1' => $login, 'l2' => $login]
        );
        // Vérification à temps constant même si l'utilisateur n'existe pas.
        $hash = $user['password_hash'] ?? self::hash(bin2hex(random_bytes(8)));
        $valid = password_verify($password, $hash);
        if (!$user || !$valid) {
            return null;
        }
        if (password_needs_rehash($user['password_hash'], self::algo(), self::algoOptions())) {
            Database::update('users', ['password_hash' => self::hash($password)], 'id = :id', ['id' => $user['id']]);
        }
        return $user;
    }

    public static function login(array $user, Request $request): void
    {
        Session::regenerate();
        Csrf::rotate();
        Session::put('_user_id', (int) $user['id']);
        Session::put('_user_fp', self::fingerprint($request));
        Database::update('users', [
            'last_login_at' => date('Y-m-d H:i:s'),
            'last_login_ip' => $request->ip(),
        ], 'id = :id', ['id' => $user['id']]);
        self::$user = null;
        self::$permissions = null;
    }

    public static function logout(): void
    {
        self::$user = null;
        self::$permissions = null;
        Session::destroy();
    }

    private static function fingerprint(Request $r): string
    {
        return hash('sha256', $r->userAgent());
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function id(): ?int
    {
        return self::user()['id'] ?? null;
    }

    public static function user(): ?array
    {
        if (self::$user !== null) {
            return self::$user;
        }
        $id = Session::get('_user_id');
        if (!$id) {
            return null;
        }
        $user = Database::fetch(
            'SELECT u.*, r.slug AS role_slug, r.name AS role_name
               FROM users u JOIN roles r ON r.id = u.role_id
              WHERE u.id = :id AND u.deleted_at IS NULL',
            ['id' => (int) $id]
        );
        if (!$user || $user['status'] !== 'active') {
            Session::destroy();
            return null;
        }
        unset($user['password_hash']);
        return self::$user = $user;
    }

    /** Vérifie l'empreinte du navigateur (limite le vol de session). */
    public static function validateFingerprint(Request $r): bool
    {
        $fp = Session::get('_user_fp');
        return $fp === null || hash_equals($fp, self::fingerprint($r));
    }

    public static function permissions(): array
    {
        if (self::$permissions !== null) {
            return self::$permissions;
        }
        $user = self::user();
        if (!$user) {
            return self::$permissions = [];
        }
        if ($user['role_slug'] === 'super_admin') {
            return self::$permissions = ['*'];
        }
        $rows = Database::fetchAll(
            'SELECT p.slug FROM permissions p JOIN role_permissions rp ON rp.permission_id = p.id WHERE rp.role_id = :r',
            ['r' => (int) $user['role_id']]
        );
        return self::$permissions = array_column($rows, 'slug');
    }

    public static function can(string ...$permissions): bool
    {
        $granted = self::permissions();
        if (in_array('*', $granted, true)) {
            return true;
        }
        foreach ($permissions as $p) {
            if (in_array($p, $granted, true)) {
                return true;
            }
        }
        return false;
    }

    public static function authorize(string ...$permissions): void
    {
        if (!self::can(...$permissions)) {
            throw new HttpException(403, "Vous n'avez pas l'autorisation d'effectuer cette action.");
        }
    }

    public static function hasRole(string ...$roles): bool
    {
        return in_array(self::user()['role_slug'] ?? '', $roles, true);
    }

    public static function algo(): string|int|null
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    }

    private static function algoOptions(): array
    {
        return self::algo() === PASSWORD_BCRYPT ? ['cost' => 12] : [];
    }

    public static function hash(string $password): string
    {
        return password_hash($password, self::algo(), self::algoOptions());
    }

    /** Règles de robustesse du mot de passe ; retourne un message d'erreur ou null. */
    public static function passwordPolicyError(string $password): ?string
    {
        $min = (int) config('security.password_min_length', 10);
        if (mb_strlen($password) < $min) {
            return "Le mot de passe doit contenir au moins {$min} caractères.";
        }
        if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            return 'Le mot de passe doit contenir des lettres et des chiffres.';
        }
        return null;
    }

    public static function reset(): void
    {
        self::$user = null;
        self::$permissions = null;
    }
}
