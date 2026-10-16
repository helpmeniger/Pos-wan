<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Authentification et autorisations.
 * - Mots de passe : password_hash()/password_verify() (bcrypt/argon selon PHP).
 * - Code PIN et badge RFID : connexions rapides, également limitées en tentatives.
 * - Verrouillage du compte après N échecs, limitation par adresse IP.
 * - Identifiant de session régénéré à la connexion, expiration d'inactivité.
 */
final class Auth
{
    private static ?array $user = null;
    private static ?array $perms = null;

    public static function user(): ?array
    {
        if (self::$user !== null) {
            return self::$user;
        }
        $id = Session::get('uid');
        if (!$id) {
            return null;
        }
        $cfg = config('app.session');
        $now = time();
        if ($now - (int) Session::get('last_seen', 0) > $cfg['idle_timeout']
            || $now - (int) Session::get('login_at', 0) > $cfg['absolute_timeout']) {
            Audit::log('auth.session_expired', 'user', (int) $id, null, (int) $id);
            Session::destroy();
            return null;
        }
        $u = DB::one('SELECT u.*, r.code AS role_code, r.name AS role_name FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ? AND u.active = 1', [$id]);
        if (!$u || (string) Session::get('pwd_sig') !== self::pwdSig($u)) {
            // Compte désactivé ou mot de passe changé ailleurs : session invalidée
            Session::destroy();
            return null;
        }
        // Appareil révoqué par l'administrateur : session coupée immédiatement
        if (!\App\Services\DeviceService::sessionAllowed()) {
            Audit::log('device.session_cut', 'user', (int) $id, null, (int) $id);
            Session::destroy();
            return null;
        }
        // Le « ping » de surveillance ne compte pas comme une activité (sinon la session n'expirerait jamais)
        if (PHP_SAPI === 'cli' || Request::path() !== '/session/ping') {
            Session::set('last_seen', $now);
        }
        return self::$user = $u;
    }

    public static function id(): ?int
    {
        $u = self::user();
        return $u ? (int) $u['id'] : null;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function permissions(): array
    {
        if (self::$perms === null) {
            $u = self::user();
            self::$perms = $u ? self::permissionsForRole((int) $u['role_id']) : [];
        }
        return self::$perms;
    }

    public static function permissionsForRole(int $roleId): array
    {
        $rows = DB::all('SELECT p.code FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = ?', [$roleId]);
        return array_fill_keys(array_column($rows, 'code'), true);
    }

    public static function can(string $perm): bool
    {
        $u = self::user();
        if (!$u) {
            return false;
        }
        if ($u['role_code'] === 'admin') {
            return true;
        }
        return isset(self::permissions()[$perm]);
    }

    public static function requireLogin(): void
    {
        $u = self::user();
        if (!$u) {
            if (Request::wantsJson()) {
                throw new HttpException(401, 'Session expirée, veuillez vous reconnecter.');
            }
            redirect('/login');
        }
        if ((int) $u['must_change_password'] === 1 && !in_array(Request::path(), ['/account/password', '/logout'], true)) {
            Session::flash('warning', 'Vous devez définir un nouveau mot de passe avant de continuer.');
            redirect('/account/password');
        }
    }

    public static function authorize(string $perm): void
    {
        if (!self::can($perm)) {
            Audit::log('auth.forbidden', null, null, ['permission' => $perm, 'path' => Request::path()]);
            throw new HttpException(403, 'Vous n\'avez pas l\'autorisation requise pour cette action.');
        }
    }

    /** Signature liée au hash du mot de passe : invalide les sessions après changement. */
    private static function pwdSig(array $u): string
    {
        return substr(hash('sha256', $u['password_hash'] . '|' . $u['id']), 0, 32);
    }

    // ------------------------------------------------------------------
    // Connexion
    // ------------------------------------------------------------------

    public static function ipBlocked(string $ip): bool
    {
        $max = (int) config('app.security.max_attempts_ip');
        $n = (int) DB::value('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND success = 0 AND created_at > (NOW() - INTERVAL 15 MINUTE)', [$ip]);
        return $n >= $max;
    }

    /** @return array{ok:bool, error?:string, user?:array} */
    public static function attemptPassword(string $username, string $password): array
    {
        $ip = Request::ip();
        if (self::ipBlocked($ip)) {
            return ['ok' => false, 'error' => 'Trop de tentatives depuis ce poste. Réessayez dans 15 minutes.'];
        }
        $u = DB::one('SELECT * FROM users WHERE username = ?', [$username]);
        // Vérification à temps constant même si l'utilisateur n'existe pas
        $hash = $u['password_hash'] ?? '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';
        $valid = password_verify($password, $hash);
        return self::finishAttempt($u, $valid && $u !== null, 'password', $username);
    }

    public static function attemptPin(string $username, string $pin): array
    {
        $ip = Request::ip();
        if (self::ipBlocked($ip)) {
            return ['ok' => false, 'error' => 'Trop de tentatives depuis ce poste. Réessayez dans 15 minutes.'];
        }
        if (!(bool) setting('pin_login_enabled', '1')) {
            return ['ok' => false, 'error' => 'La connexion par code PIN est désactivée.'];
        }
        $u = DB::one('SELECT * FROM users WHERE username = ?', [$username]);
        $valid = $u && $u['pin_hash'] && password_verify($pin, $u['pin_hash']);
        if (!$valid) {
            password_verify($pin, '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG');
        }
        return self::finishAttempt($u, (bool) $valid, 'pin', $username);
    }

    public static function attemptBadge(string $badge): array
    {
        $ip = Request::ip();
        if (self::ipBlocked($ip)) {
            return ['ok' => false, 'error' => 'Trop de tentatives depuis ce poste. Réessayez dans 15 minutes.'];
        }
        if (!(bool) setting('rfid_enabled', '0')) {
            return ['ok' => false, 'error' => 'La connexion par badge n\'est pas activée.'];
        }
        $u = $badge !== '' ? DB::one('SELECT * FROM users WHERE badge_hash = ?', [self::badgeHash($badge)]) : null;
        return self::finishAttempt($u, $u !== null, 'badge', $u['username'] ?? null);
    }

    public static function badgeHash(string $badge): string
    {
        return hash_hmac('sha256', trim($badge), (string) Env::get('APP_KEY', 'poswan'));
    }

    private static function finishAttempt(?array $u, bool $valid, string $method, ?string $username): array
    {
        $ip = Request::ip();
        $generic = 'Identifiants incorrects.';
        if ($u && $u['locked_until'] && strtotime($u['locked_until']) > time()) {
            DB::insert('login_attempts', ['ip' => $ip, 'username' => $username, 'method' => $method, 'success' => 0]);
            return ['ok' => false, 'error' => 'Compte temporairement verrouillé suite à plusieurs échecs. Réessayez plus tard ou contactez un administrateur.'];
        }
        if (!$valid || !$u || (int) $u['active'] !== 1) {
            DB::insert('login_attempts', ['ip' => $ip, 'username' => $username, 'method' => $method, 'success' => 0]);
            if ($u) {
                $fails = (int) $u['failed_attempts'] + 1;
                $lock = null;
                if ($fails >= (int) config('app.security.max_attempts_user')) {
                    $lock = date('Y-m-d H:i:s', time() + 60 * (int) config('app.security.lockout_minutes'));
                    Audit::log('auth.locked', 'user', (int) $u['id'], ['method' => $method], null);
                }
                DB::update('users', ['failed_attempts' => $fails, 'locked_until' => $lock], 'id = ?', [$u['id']]);
            }
            Audit::log('auth.failed', 'user', $u ? (int) $u['id'] : null, ['username' => $username, 'method' => $method], null);
            return ['ok' => false, 'error' => $generic];
        }
        DB::insert('login_attempts', ['ip' => $ip, 'username' => $u['username'], 'method' => $method, 'success' => 1]);
        DB::update('users', ['failed_attempts' => 0, 'locked_until' => null, 'last_login_at' => date('Y-m-d H:i:s')], 'id = ?', [$u['id']]);
        self::login($u);
        Audit::log('auth.login', 'user', (int) $u['id'], ['method' => $method], (int) $u['id']);
        return ['ok' => true, 'user' => $u];
    }

    public static function login(array $u): void
    {
        Session::regenerate();
        Session::set('uid', (int) $u['id']);
        Session::set('login_at', time());
        Session::set('last_seen', time());
        Session::set('pwd_sig', self::pwdSig($u));
        unset($_SESSION['_csrf']);
        self::$user = null;
        self::$perms = null;
    }

    public static function logout(): void
    {
        $id = self::id();
        if ($id) {
            Audit::log('auth.logout', 'user', $id, null, $id);
        }
        Session::destroy();
        self::$user = null;
        self::$perms = null;
    }

    /** Réinitialise le cache interne (utile pour les tests). */
    public static function reset(): void
    {
        self::$user = null;
        self::$perms = null;
    }

    /**
     * Autorisation d'un superviseur (remise élevée, remboursement, crédit...).
     * Le superviseur saisit son identifiant et son mot de passe ou son PIN.
     * Retourne l'id du superviseur ; lève une BusinessException sinon.
     */
    public static function verifySupervisor(string $username, string $secret, string $perm): int
    {
        if (self::ipBlocked(Request::ip())) {
            throw new BusinessException('Trop de tentatives. Réessayez plus tard.');
        }
        $u = DB::one('SELECT u.*, r.code AS role_code FROM users u JOIN roles r ON r.id = u.role_id WHERE u.username = ? AND u.active = 1', [$username]);
        $ok = $u && (!$u['locked_until'] || strtotime($u['locked_until']) <= time())
            && (password_verify($secret, $u['password_hash']) || ($u['pin_hash'] && password_verify($secret, $u['pin_hash'])));
        if (!$ok) {
            DB::insert('login_attempts', ['ip' => Request::ip(), 'username' => $username, 'method' => 'supervisor', 'success' => 0]);
            Audit::log('auth.supervisor_failed', null, null, ['username' => $username, 'permission' => $perm]);
            throw new BusinessException('Autorisation refusée : identifiants du superviseur incorrects.');
        }
        $has = $u['role_code'] === 'admin' || isset(self::permissionsForRole((int) $u['role_id'])[$perm]);
        if (!$has) {
            throw new BusinessException('Ce superviseur ne dispose pas de la permission requise.');
        }
        Audit::log('auth.supervisor_ok', 'user', (int) $u['id'], ['permission' => $perm]);
        return (int) $u['id'];
    }

    /** Règles de robustesse des mots de passe. Retourne un message d'erreur ou null. */
    public static function passwordPolicyError(string $pwd, string $username = ''): ?string
    {
        $min = (int) config('app.security.password_min_length');
        if (mb_strlen($pwd) < $min) {
            return "Le mot de passe doit contenir au moins {$min} caractères.";
        }
        if (!preg_match('/[A-Za-z]/', $pwd) || !preg_match('/\d/', $pwd)) {
            return 'Le mot de passe doit contenir des lettres et des chiffres.';
        }
        if ($username !== '' && stripos($pwd, $username) !== false) {
            return 'Le mot de passe ne doit pas contenir l\'identifiant.';
        }
        if (in_array(strtolower($pwd), ['admin12345', 'password123', 'motdepasse1', 'azerty12345', '1234567890a'], true)) {
            return 'Ce mot de passe est trop courant.';
        }
        return null;
    }

    public static function hash(string $secret): string
    {
        return password_hash($secret, PASSWORD_DEFAULT);
    }
}
