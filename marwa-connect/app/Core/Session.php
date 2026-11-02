<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Sessions sécurisées : cookies HttpOnly/SameSite, mode strict, expiration
 * d'inactivité et durée de vie absolue, messages flash et anciennes saisies.
 */
final class Session
{
    public static function start(bool $secure = false, bool $rotateFlash = true): void
    {
        if (session_status() === PHP_SESSION_ACTIVE || PHP_SESSION_DISABLED === session_status()) {
            return;
        }
        $path = STORAGE_PATH . '/sessions';
        if (is_dir($path) && is_writable($path)) {
            session_save_path($path);
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.sid_length', '48');
        ini_set('session.sid_bits_per_character', '6');
        ini_set('session.gc_maxlifetime', (string) (config('security.session_lifetime') * 60));
        session_name((string) config('security.session_name', 'MARWASESSID'));
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => Url::basePath() === '' ? '/' : Url::basePath() . '/',
            'secure' => $secure || (bool) config('security.cookie_secure'),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();

        $now = time();
        $idle = (int) config('security.session_idle_timeout', 30) * 60;
        $absolute = (int) config('security.session_lifetime', 480) * 60;
        $created = $_SESSION['_created'] ?? $now;
        $last = $_SESSION['_last_activity'] ?? $now;
        if (isset($_SESSION['_user_id']) && (($now - $last) > $idle || ($now - $created) > $absolute)) {
            self::destroy();
            session_start();
            $_SESSION['_flash']['warning'] = 'Votre session a expiré. Veuillez vous reconnecter.';
            $created = $now;
        }
        $_SESSION['_created'] = $created;
        $_SESSION['_last_activity'] = $now;

        // Rotation périodique de l'identifiant de session.
        if (($now - ($_SESSION['_regenerated'] ?? 0)) > 900) {
            session_regenerate_id(true);
            $_SESSION['_regenerated'] = $now;
        }

        // Rotation des messages flash : ceux de la requête précédente deviennent lisibles.
        // Les requêtes AJAX (sondages) ne consomment pas les messages flash.
        if (!$rotateFlash) {
            return;
        }
        $_SESSION['_flash_now'] = $_SESSION['_flash'] ?? [];
        $_SESSION['_old_now'] = $_SESSION['_old'] ?? [];
        $_SESSION['_errors_now'] = $_SESSION['_errors'] ?? [];
        unset($_SESSION['_flash'], $_SESSION['_old'], $_SESSION['_errors']);
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function put(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
            $_SESSION['_regenerated'] = time();
            $_SESSION['_created'] = time();
        }
    }

    public static function destroy(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000, 'path' => $p['path'], 'domain' => $p['domain'],
                'secure' => $p['secure'], 'httponly' => $p['httponly'], 'samesite' => $p['samesite'] ?? 'Lax',
            ]);
        }
        session_destroy();
    }

    public static function flash(string $type, string $message): void
    {
        $_SESSION['_flash'][$type] = $message;
    }

    public static function flashes(): array
    {
        return $_SESSION['_flash_now'] ?? [];
    }

    public static function flashOld(array $input): void
    {
        unset($input['password'], $input['password_confirmation'], $input['_token'], $input['current_password'], $input['admin_password'], $input['db_password']);
        $_SESSION['_old'] = $input;
    }

    public static function old(string $key, mixed $default = ''): mixed
    {
        return $_SESSION['_old_now'][$key] ?? $default;
    }

    public static function flashErrors(array $errors): void
    {
        $_SESSION['_errors'] = $errors;
    }

    public static function errors(): array
    {
        return $_SESSION['_errors_now'] ?? [];
    }
}
