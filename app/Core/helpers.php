<?php

declare(strict_types=1);

use App\Core\Config;
use App\Core\Container;
use App\Core\Csrf;
use App\Core\Router;
use App\Core\Session;
use App\Support\Money;

/** Fonctions utilitaires globales pour les vues et le code applicatif. */

if (!function_exists('app')) {
    function app(?string $id = null): mixed
    {
        $c = Container::getInstance();
        return $id === null ? $c : $c->get($id);
    }
}

if (!function_exists('config')) {
    function config(string $key, mixed $default = null): mixed
    {
        return app(Config::class)->get($key, $default);
    }
}

if (!function_exists('e')) {
    /** Échappement HTML systématique (protection XSS). */
    function e(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        return htmlspecialchars(is_scalar($value) ? (string) $value : '', ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}

if (!function_exists('url')) {
    /** URL interne, préfixée par le sous-dossier d'installation éventuel. */
    function url(string $path = '/'): string
    {
        $base = rtrim((string) config('app.base_path', ''), '/');
        return $base . '/' . ltrim($path, '/');
    }
}

if (!function_exists('route')) {
    /** @param array<string, string|int> $params */
    function route(string $name, array $params = []): string
    {
        return url(app(Router::class)->url($name, $params));
    }
}

if (!function_exists('asset')) {
    function asset(string $path): string
    {
        $file = dirname(__DIR__, 2) . '/public/assets/' . ltrim($path, '/');
        $v = is_file($file) ? substr(md5((string) filemtime($file)), 0, 8) : '0';
        return url('/assets/' . ltrim($path, '/')) . '?v=' . $v;
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        return app(Csrf::class)->token();
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return '<input type="hidden" name="_token" value="' . e(csrf_token()) . '">';
    }
}

if (!function_exists('method_field')) {
    function method_field(string $method): string
    {
        return '<input type="hidden" name="_method" value="' . e(strtoupper($method)) . '">';
    }
}

if (!function_exists('old')) {
    function old(string $key, mixed $default = ''): mixed
    {
        $old = app(Session::class)->getFlash('_old', []);
        return is_array($old) && array_key_exists($key, $old) ? $old[$key] : $default;
    }
}

if (!function_exists('errors')) {
    /** @return array<string, list<string>> */
    function errors(): array
    {
        $errors = app(Session::class)->getFlash('_errors', []);
        return is_array($errors) ? $errors : [];
    }
}

if (!function_exists('error_for')) {
    function error_for(string $field): ?string
    {
        return errors()[$field][0] ?? null;
    }
}

if (!function_exists('field_class')) {
    function field_class(string $field, string $base = 'form-control'): string
    {
        return $base . (error_for($field) ? ' is-invalid' : '');
    }
}

if (!function_exists('flash')) {
    function flash(string $key): mixed
    {
        return app(Session::class)->getFlash($key);
    }
}

if (!function_exists('money')) {
    /** 10000 / "10000.00" → « 10 000 FCFA » */
    function money(int|string|null $amount): string
    {
        if ($amount === null || $amount === '') {
            return '—';
        }
        return Money::parse(is_int($amount) ? $amount : (string) $amount)->format();
    }
}

if (!function_exists('date_fr')) {
    /** Format JJ/MM/AAAA (avec l'heure si $withTime). */
    function date_fr(?string $datetime, bool $withTime = false): string
    {
        if ($datetime === null || $datetime === '') {
            return '—';
        }
        try {
            $d = new DateTimeImmutable($datetime, new DateTimeZone('Africa/Niamey'));
        } catch (Exception) {
            return '—';
        }
        return $d->format($withTime ? 'd/m/Y à H:i' : 'd/m/Y');
    }
}

if (!function_exists('auth_user')) {
    /** @return array<string, mixed>|null */
    function auth_user(): ?array
    {
        return app(\App\Services\Auth\AuthService::class)->user();
    }
}

if (!function_exists('can')) {
    function can(string $permission): bool
    {
        $user = auth_user();
        return $user !== null && app(\App\Services\Auth\PermissionService::class)->allows((int) $user['id'], $permission);
    }
}

if (!function_exists('has_role')) {
    function has_role(string $role): bool
    {
        $user = auth_user();
        return $user !== null && app(\App\Services\Auth\PermissionService::class)->hasRole((int) $user['id'], $role);
    }
}

if (!function_exists('setting')) {
    function setting(string $key, mixed $default = null): mixed
    {
        return app(\App\Repositories\SettingRepository::class)->get($key, $default);
    }
}

if (!function_exists('is_active_path')) {
    function is_active_path(string $prefix): string
    {
        $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        return str_starts_with($path, url($prefix)) ? ' active' : '';
    }
}

if (!function_exists('badge')) {
    /** Pastille de statut colorée : success | warning | danger | info | secondary | primary */
    function badge(string $label, string $tone = 'secondary'): string
    {
        $classes = [
            'success' => 'text-bg-success', 'warning' => 'text-bg-warning', 'danger' => 'text-bg-danger',
            'info' => 'text-bg-info', 'primary' => 'text-bg-primary', 'secondary' => 'text-bg-light border',
        ];
        return '<span class="badge ' . ($classes[$tone] ?? $classes['secondary']) . '">' . e($label) . '</span>';
    }
}

if (!function_exists('status_tone')) {
    function status_tone(string $status): string
    {
        return match ($status) {
            'ACTIF', 'RECUE', 'ACCEPTEE', 'CONFIRME', 'CONCLUANT', 'APPROUVEE', 'CANDIDATURES_OUVERTES', 'PUBLIEE', 'ACTIVE', 'RESOLU' => 'success',
            'EN_ATTENTE_ACCEPTATION', 'PAIEMENT_DEMANDE', 'PAYEE_DECLAREE', 'PROPOSE', 'REPORT_DEMANDE', 'SOUMISE', 'EN_MODERATION', 'PRESELECTIONNEE', 'EMISE', 'OUVERT', 'EN_COURS' => 'warning',
            'CONTESTEE', 'REFUSEE', 'REFUSE', 'NON_CONCLUANT', 'SUSPENDED', 'BANNED', 'ANNULEE', 'ANNULE' => 'danger',
            'VUE', 'ATTRIBUEE' => 'info',
            default => 'secondary',
        };
    }
}

if (!function_exists('time_fr')) {
    function time_fr(?string $time): string
    {
        return $time ? substr($time, 0, 5) : '';
    }
}

if (!function_exists('query_with')) {
    /** URL de la page courante avec des paramètres modifiés (pagination, filtres). */
    function query_with(array $changes): string
    {
        $q = array_merge($_GET, $changes);
        $q = array_filter($q, fn ($v) => $v !== null && $v !== '');
        $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        return $path . ($q ? '?' . http_build_query($q) : '');
    }
}
