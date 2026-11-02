<?php
declare(strict_types=1);

use App\Core\App;
use App\Core\Auth;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Session;
use App\Core\Url;

/**
 * Fonctions utilitaires globales.
 */

if (!function_exists('config')) {
    function config(string $key, mixed $default = null): mixed
    {
        return Config::get($key, $default);
    }
}

if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        return \App\Core\Env::get($key, $default);
    }
}

/** Échappement HTML (protection XSS). */
function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path = '/', array $query = []): string
{
    return Url::to($path, $query);
}

function route(string $name, array $params = []): string
{
    return App::router()->urlFor($name, $params);
}

function asset(string $path): string
{
    return Url::asset($path);
}

function csrf_field(): string
{
    return Csrf::field();
}

function csrf_token(): string
{
    return Csrf::token();
}

function method_field(string $method): string
{
    return '<input type="hidden" name="_method" value="' . e(strtoupper($method)) . '">';
}

function old(string $key, mixed $default = ''): mixed
{
    return Session::old($key, $default);
}

function errors(): array
{
    return Session::errors();
}

function error_for(string $field): string
{
    $err = Session::errors()[$field] ?? null;
    return $err ? '<div class="invalid-feedback d-block">' . e($err) . '</div>' : '';
}

function invalid(string $field): string
{
    return isset(Session::errors()[$field]) ? ' is-invalid' : '';
}

function auth(): ?array
{
    return Auth::user();
}

function can(string ...$permissions): bool
{
    return Auth::can(...$permissions);
}

/** Montant en FCFA (ex. 1 200 FCFA). */
function money(int|float|string|null $amount, bool $withCurrency = true): string
{
    $s = number_format((float) $amount, 0, ',', ' ');
    return $withCurrency ? $s . ' ' . config('app.currency', 'FCFA') : $s;
}

function bytes_human(int|float|null $bytes, int $precision = 1): string
{
    $bytes = (float) $bytes;
    $units = ['o', 'Ko', 'Mo', 'Go', 'To'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return number_format($bytes, $i === 0 ? 0 : $precision, ',', ' ') . ' ' . $units[$i];
}

function date_fr(?string $datetime, string $format = 'd/m/Y H:i'): string
{
    if (!$datetime) {
        return '—';
    }
    try {
        return (new DateTime($datetime))->format($format);
    } catch (Throwable) {
        return '—';
    }
}

function is_active_path(string $prefix): bool
{
    $path = (string) ($GLOBALS['__marwa_path'] ?? '/');
    return $prefix === '/' ? $path === '/' : str_starts_with($path, $prefix);
}

function setting(string $key, mixed $default = null): mixed
{
    return \App\Services\SettingsService::get($key, $default);
}
