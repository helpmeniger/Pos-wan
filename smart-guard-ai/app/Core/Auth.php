<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Authentification et autorisations (contrôlées côté serveur).
 * - Mots de passe : Argon2id (repli bcrypt si Argon2 indisponible).
 * - Limitation des tentatives par identifiant ET par adresse IP.
 * - Permissions rechargées depuis la base à chaque requête (un compte
 *   désactivé ou un droit retiré prend effet immédiatement).
 */
final class Auth
{
    private static ?array $user = null;
    private static ?array $permissions = null;
    private static bool $loaded = false;

    public static function hashPassword(string $password): string
    {
        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
        return password_hash($password, $algo);
    }

    public static function passwordPolicyError(string $password): ?string
    {
        if (mb_strlen($password) < 10) {
            return 'Le mot de passe doit contenir au moins 10 caractères.';
        }
        if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            return 'Le mot de passe doit contenir des lettres et des chiffres.';
        }
        return null;
    }

    /** @return array{ok:bool, error?:string} */
    public static function attempt(string $username, string $password, string $ip): array
    {
        $max = Env::int('LOGIN_MAX_ATTEMPTS', 5);
        $lockMin = Env::int('LOGIN_LOCK_MINUTES', 15);
        $since = date('Y-m-d H:i:s', time() - $lockMin * 60);

        $failsUser = (int) Database::value('SELECT COUNT(*) FROM login_attempts WHERE username = :u AND success = 0 AND attempted_at > :s', ['u' => $username, 's' => $since]);
        $failsIp = (int) Database::value('SELECT COUNT(*) FROM login_attempts WHERE ip_address = :ip AND success = 0 AND attempted_at > :s', ['ip' => $ip, 's' => $since]);
        if ($failsUser >= $max || $failsIp >= $max * 3) {
            Audit::log('connexion_bloquee', 'user', null, ['username' => $username, 'ip' => $ip]);
            return ['ok' => false, 'error' => "Trop de tentatives échouées. Réessayez dans {$lockMin} minutes."];
        }

        $user = Database::fetch('SELECT * FROM users WHERE username = :u AND deleted_at IS NULL', ['u' => $username]);
        // Vérification à temps constant même si l'utilisateur n'existe pas
        $hash = $user['password_hash'] ?? '$argon2id$v=19$m=65536,t=4,p=1$RlMyTmFrWWMyci53UDBlcw$KXGgKRfWwooPEfABRcnb5o8hKaYZWG/hwc1M/ee7uyk';
        $valid = password_verify($password, $hash);

        Database::insert('login_attempts', ['username' => mb_substr($username, 0, 60), 'ip_address' => $ip, 'success' => ($valid && $user && $user['is_active']) ? 1 : 0]);

        if (!$valid || !$user) {
            return ['ok' => false, 'error' => 'Identifiant ou mot de passe incorrect.'];
        }
        if (!$user['is_active']) {
            Audit::log('connexion_compte_desactive', 'user', $user['id']);
            return ['ok' => false, 'error' => 'Ce compte est désactivé. Contactez l\'administrateur.'];
        }
        if (password_needs_rehash($user['password_hash'], defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT)) {
            Database::update('users', ['password_hash' => self::hashPassword($password)], 'id = :id', ['id' => $user['id']]);
        }

        Session::regenerate();
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['login_at'] = time();
        self::$loaded = false;
        $stores = self::accessibleStores((int) $user['id']);
        $_SESSION['store_id'] = $stores[0]['id'] ?? null;
        Database::update('users', ['last_login_at' => date('Y-m-d H:i:s'), 'last_login_ip' => $ip], 'id = :id', ['id' => $user['id']]);
        Audit::log('connexion', 'user', $user['id']);
        return ['ok' => true];
    }

    public static function logout(): void
    {
        Audit::log('deconnexion', 'user', self::id());
        Session::destroy();
        self::$user = null;
        self::$permissions = null;
        self::$loaded = false;
    }

    private static function load(): void
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;
        $id = $_SESSION['user_id'] ?? null;
        if (!$id) {
            return;
        }
        $user = Database::fetch('SELECT id, uuid, username, full_name, email, phone, is_active, must_change_password FROM users WHERE id = :id AND deleted_at IS NULL', ['id' => $id]);
        if (!$user || !$user['is_active']) {
            unset($_SESSION['user_id']);
            return;
        }
        self::$user = $user;
        $rows = Database::fetchAll(
            'SELECT DISTINCT p.code FROM permissions p
             JOIN role_permissions rp ON rp.permission_id = p.id
             JOIN user_roles ur ON ur.role_id = rp.role_id
             WHERE ur.user_id = :id', ['id' => $id]);
        self::$permissions = array_column($rows, 'code');
        self::$user['roles'] = array_column(Database::fetchAll('SELECT r.code FROM roles r JOIN user_roles ur ON ur.role_id = r.id WHERE ur.user_id = :id', ['id' => $id]), 'code');
    }

    /** Utilisé par la CLI et les tests pour agir au nom d'un utilisateur. */
    public static function actAs(?int $userId): void
    {
        $_SESSION['user_id'] = $userId;
        self::$loaded = false;
        self::$user = null;
        self::$permissions = null;
        if ($userId) {
            $stores = self::accessibleStores($userId);
            $_SESSION['store_id'] = $_SESSION['store_id'] ?? ($stores[0]['id'] ?? null);
        }
    }

    public static function check(): bool
    {
        self::load();
        return self::$user !== null;
    }

    public static function user(): ?array
    {
        self::load();
        return self::$user;
    }

    public static function id(): ?int
    {
        self::load();
        return self::$user ? (int) self::$user['id'] : null;
    }

    public static function isAdmin(): bool
    {
        self::load();
        return self::$user !== null && in_array('admin', self::$user['roles'], true);
    }

    public static function can(string $permission): bool
    {
        self::load();
        return self::$permissions !== null && in_array($permission, self::$permissions, true);
    }

    public static function permissions(): array
    {
        self::load();
        return self::$permissions ?? [];
    }

    public static function accessibleStores(?int $userId = null): array
    {
        $userId ??= self::id();
        $isAdmin = (bool) Database::value("SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = :u AND r.code = 'admin'", ['u' => $userId]);
        if ($isAdmin) {
            return Database::fetchAll('SELECT id, code, name FROM stores WHERE is_active = 1 ORDER BY id');
        }
        return Database::fetchAll('SELECT s.id, s.code, s.name FROM stores s JOIN store_users su ON su.store_id = s.id WHERE su.user_id = :u AND s.is_active = 1 ORDER BY s.id', ['u' => $userId]);
    }

    /** Magasin courant ; vérifie à chaque appel que l'utilisateur y a toujours accès. */
    public static function storeId(): int
    {
        $sid = (int) ($_SESSION['store_id'] ?? 0);
        $ids = array_map('intval', array_column(self::accessibleStores(), 'id'));
        if (!in_array($sid, $ids, true)) {
            $sid = $ids[0] ?? 0;
            $_SESSION['store_id'] = $sid;
        }
        if ($sid === 0) {
            throw new HttpException(403, 'Aucun magasin n\'est associé à votre compte.');
        }
        return $sid;
    }

    public static function switchStore(int $storeId): bool
    {
        $ids = array_map('intval', array_column(self::accessibleStores(), 'id'));
        if (in_array($storeId, $ids, true)) {
            $_SESSION['store_id'] = $storeId;
            return true;
        }
        return false;
    }
}
