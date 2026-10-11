<?php
/**
 * Authentification : sessions sécurisées, connexion avec limitation des
 * tentatives, déconnexion, contrôle d'accès aux pages.
 */
declare(strict_types=1);

function start_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    session_name((string) config('session_name', 'NPSESSID'));
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => base_path(),
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();

    // Expiration après inactivité
    $now = time();
    $lifetime = (int) config('session_lifetime', 7200);
    if (isset($_SESSION['last_activity']) && ($now - (int) $_SESSION['last_activity']) > $lifetime) {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['expired'] = true;
    }
    $_SESSION['last_activity'] = $now;
}

/** Utilisateur connecté (rechargé depuis la base à chaque requête). */
function current_user(): ?array
{
    static $cache = [];
    if (defined('NP_CLI') && isset($GLOBALS['np_cli_user'])) {
        return $GLOBALS['np_cli_user'];
    }
    $id = (int) ($_SESSION['user_id'] ?? 0);
    if ($id <= 0) {
        return null;
    }
    if (!array_key_exists($id, $cache)) {
        $u = db_one('SELECT u.id, u.username, u.full_name, u.email, u.phone, u.is_active, u.must_change_password,
                            r.code AS role_code, r.name AS role_name
                     FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?', [$id]);
        if (!$u || (int) $u['is_active'] !== 1) {
            $u = null;
            unset($_SESSION['user_id']);
        }
        $cache[$id] = $u;
    }
    return $cache[$id];
}

function require_login(): array
{
    $u = current_user();
    if ($u === null) {
        if (defined('NP_API')) {
            json_error('Authentification requise.', 401);
        }
        $_SESSION['after_login'] = $_SERVER['REQUEST_URI'] ?? null;
        redirect('login.php');
    }
    // Mot de passe provisoire : changement obligatoire avant toute autre action
    if ((int) $u['must_change_password'] === 1 && !in_array(basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')), ['profil.php', 'logout.php'], true)) {
        if (defined('NP_API')) {
            json_error('Vous devez d\'abord changer votre mot de passe provisoire.', 403);
        }
        redirect('profil.php');
    }
    return $u;
}

/** Vérifie si l'identifiant ou l'IP est temporairement bloqué. */
function login_is_locked(string $username, string $ip): bool
{
    $minutes = (int) config('login_lockout_minutes', 15);
    $since = date('Y-m-d H:i:s', time() - $minutes * 60);
    $byUser = (int) db_value('SELECT COUNT(*) FROM login_attempts WHERE username = ? AND success = 0 AND attempted_at >= ?
                              AND attempted_at > COALESCE((SELECT MAX(attempted_at) FROM login_attempts la2 WHERE la2.username = ? AND la2.success = 1), \'1970-01-01\')',
        [$username, $since, $username]);
    $byIp = (int) db_value('SELECT COUNT(*) FROM login_attempts WHERE ip_address = ? AND success = 0 AND attempted_at >= ?', [$ip, $since]);
    return $byUser >= (int) config('login_max_attempts_user', 5) || $byIp >= (int) config('login_max_attempts_ip', 20);
}

/**
 * Tente une connexion. Retourne null si succès, sinon un message d'erreur
 * volontairement générique (ne révèle pas si l'identifiant existe).
 */
function attempt_login(string $username, string $password): ?string
{
    $username = mb_substr(trim($username), 0, 60);
    $ip = client_ip();
    if ($username === '' || $password === '') {
        return 'Veuillez saisir votre identifiant et votre mot de passe.';
    }
    if (login_is_locked($username, $ip)) {
        audit_log('connexion_bloquee', 'user', null, ['username' => $username]);
        return 'Trop de tentatives échouées. Réessayez dans ' . (int) config('login_lockout_minutes', 15) . ' minutes.';
    }
    $user = db_one('SELECT id, password_hash, is_active FROM users WHERE username = ?', [$username]);
    // Vérification à temps quasi constant même si l'utilisateur n'existe pas
    $hash = $user['password_hash'] ?? '$2y$10$K6HJsNcQA1GVyLYd5gNy6erwyn13uFn52xOlfC4ikCvBw5U4hcQMa';
    $ok = password_verify($password, $hash) && $user && (int) $user['is_active'] === 1;

    db_query('INSERT INTO login_attempts (username, ip_address, success) VALUES (?, ?, ?)', [$username, $ip, $ok ? 1 : 0]);
    if (!$ok) {
        audit_log('connexion_echec', 'user', $user ? (int) $user['id'] : null, ['username' => $username]);
        return 'Identifiant ou mot de passe incorrect.';
    }

    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
        db_query('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $user['id']]);
    }
    session_regenerate_id(true);          // prévention de la fixation de session
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $_SESSION['login_at'] = time();
    db_query('UPDATE users SET last_login_at = NOW() WHERE id = ?', [$user['id']]);
    audit_log('connexion', 'user', (int) $user['id']);
    return null;
}

function logout_user(): void
{
    if (current_user()) {
        audit_log('deconnexion', 'user', (int) current_user()['id']);
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', ['expires' => time() - 42000, 'path' => $p['path'], 'secure' => $p['secure'],
                                        'httponly' => true, 'samesite' => 'Lax']);
    }
    session_destroy();
}

/** Règles minimales de robustesse d'un mot de passe. Retourne un message ou null. */
function password_policy_error(string $password): ?string
{
    if (mb_strlen($password) < 10) {
        return 'Le mot de passe doit contenir au moins 10 caractères.';
    }
    if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
        return 'Le mot de passe doit contenir au moins une lettre et un chiffre.';
    }
    return null;
}
