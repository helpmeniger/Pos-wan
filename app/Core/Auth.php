<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Authentification par session (navigateur) ou jeton Bearer (API mobile future).
 * Les permissions sont chargées depuis la base à chaque requête : un changement
 * de rôle ou une suspension prend effet immédiatement.
 */
final class Auth
{
    private static ?array $user = null;
    private static array $permissions = [];
    private static string $via = 'none';

    public static function resolve(Request $request): void
    {
        self::$user = null;
        self::$permissions = [];
        self::$via = 'none';

        $token = $request->bearerToken();
        if ($token !== null) {
            $row = Database::first(
                'SELECT t.id AS token_id, t.user_id FROM api_tokens t
                  WHERE t.token_hash = :h AND t.revoked_at IS NULL AND t.expires_at > NOW()',
                ['h' => hash('sha256', $token)]
            );
            if ($row) {
                Database::execute('UPDATE api_tokens SET last_used_at = NOW() WHERE id = :id', ['id' => $row['token_id']]);
                self::loadUser((int) $row['user_id'], 'token');
            }
            return;
        }

        $uid = Session::get('uid');
        if (is_int($uid)) {
            // Lien faible session ↔ navigateur pour limiter le vol de session
            if (Session::get('ua') !== hash('sha256', $request->userAgent())) {
                Session::destroy();
                return;
            }
            self::loadUser($uid, 'session');
            // « Déconnecter tous les appareils » / changement de mot de passe : sessions antérieures invalidées
            if (self::$user !== null && (int) (self::$user['session_version'] ?? 0) !== (int) Session::get('sv', 0)) {
                self::$user = null;
                self::$permissions = [];
                self::$via = 'none';
            }
            if (self::$user === null) {
                Session::destroy();
            }
        }
    }

    private static function loadUser(int $id, string $via): void
    {
        $user = Database::first(
            "SELECT u.*, r.code AS role, r.is_staff FROM users u JOIN roles r ON r.id = u.role_id
              WHERE u.id = :id AND u.status IN ('active','pending')",
            ['id' => $id]
        );
        if (!$user) {
            return;
        }
        self::$user = $user;
        self::$via = $via;
        self::$permissions = array_column(Database::select(
            'SELECT p.code FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = :r',
            ['r' => $user['role_id']]
        ), 'code');
    }

    public static function login(array $user, Request $request): void
    {
        Session::regenerate();
        Session::set('uid', (int) $user['id']);
        Session::set('ua', hash('sha256', $request->userAgent()));
        Session::set('sv', (int) ($user['session_version'] ?? 0));
        self::loadUser((int) $user['id'], 'session');
    }

    public static function logout(): void
    {
        Session::destroy();
        self::$user = null;
        self::$permissions = [];
        self::$via = 'none';
    }

    public static function issueToken(int $userId, string $name = 'api', int $days = 30): string
    {
        $token = Str::token(32);
        Database::insert('api_tokens', [
            'user_id' => $userId,
            'name' => mb_substr($name, 0, 80),
            'token_hash' => hash('sha256', $token),
            'expires_at' => date('Y-m-d H:i:s', time() + $days * 86400),
        ]);
        return $token;
    }

    /** Utilisé par les tests unitaires de services. */
    public static function actingAs(?int $userId): void
    {
        self::$user = null;
        self::$permissions = [];
        self::$via = 'none';
        if ($userId !== null) {
            self::loadUser($userId, 'token');
        }
    }

    public static function user(): ?array { return self::$user; }
    public static function id(): ?int { return self::$user ? (int) self::$user['id'] : null; }
    public static function role(): ?string { return self::$user['role'] ?? null; }
    public static function via(): string { return self::$via; }
    public static function check(): bool { return self::$user !== null; }
    public static function isStaff(): bool { return (bool) (self::$user['is_staff'] ?? false); }

    public static function hasRole(string ...$roles): bool
    {
        return self::$user !== null && in_array(self::$user['role'], $roles, true);
    }

    public static function can(string $permission): bool
    {
        return in_array($permission, self::$permissions, true);
    }

    public static function permissions(): array
    {
        return self::$permissions;
    }

    public static function requireUser(): array
    {
        if (self::$user === null) {
            throw HttpException::unauthorized();
        }
        return self::$user;
    }

    public static function hashPassword(string $password): string
    {
        return defined('PASSWORD_ARGON2ID')
            ? password_hash($password, PASSWORD_ARGON2ID)
            : password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }
}
