<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Session PHP durcie : stockage dans storage/sessions, cookie HttpOnly + SameSite=Lax
 * (+ Secure en HTTPS), mode strict, expiration d'inactivité et durée absolue,
 * messages flash et anciennes saisies de formulaire.
 */
class Session
{
    private bool $started = false;

    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config)
    {
    }

    public function start(bool $secure): void
    {
        if ($this->started || session_status() === PHP_SESSION_ACTIVE) {
            $this->started = true;
            return;
        }
        if (PHP_SAPI === 'cli') {
            // En ligne de commande (tests, console), session en mémoire uniquement
            $_SESSION ??= [];
            $this->started = true;
            $this->ageFlash();
            return;
        }
        $path = $this->config['path'];
        if (!is_dir($path)) {
            @mkdir($path, 0700, true);
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.sid_length', '48');
        ini_set('session.sid_bits_per_character', '6');
        ini_set('session.gc_maxlifetime', (string) ($this->config['idle_minutes'] * 60));
        session_save_path($path);
        session_name($this->config['cookie']);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $secure || (bool) $this->config['secure'],
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
        $this->started = true;

        $now = time();
        $created = (int) ($_SESSION['_created'] ?? $now);
        $last = (int) ($_SESSION['_last'] ?? $now);
        if ($now - $last > $this->config['idle_minutes'] * 60 || $now - $created > $this->config['absolute_hours'] * 3600) {
            $this->invalidate();
            $_SESSION['_expired'] = true;
        }
        $_SESSION['_created'] ??= $now;
        $_SESSION['_last'] = $now;
        $this->ageFlash();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public function put(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $_SESSION[$key] ?? $default;
        unset($_SESSION[$key]);
        return $value;
    }

    /** Change l'identifiant de session (après connexion / changement de privilèges). */
    public function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION['_created'] = time();
        unset($_SESSION['_csrf']);
    }

    public function invalidate(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION['_created'] = time();
    }

    public function flash(string $key, mixed $value): void
    {
        $_SESSION['_flash']['new'][$key] = $value;
    }

    public function getFlash(string $key, mixed $default = null): mixed
    {
        return $_SESSION['_flash']['old'][$key] ?? $_SESSION['_flash']['new'][$key] ?? $default;
    }

    /** @param array<string, mixed> $input */
    public function flashInput(array $input): void
    {
        foreach (['password', 'password_confirmation', 'current_password', '_token', 'code'] as $secret) {
            unset($input[$secret]);
        }
        $this->flash('_old', $input);
    }

    private function ageFlash(): void
    {
        $_SESSION['_flash']['old'] = $_SESSION['_flash']['new'] ?? [];
        $_SESSION['_flash']['new'] = [];
    }
}
