<?php
declare(strict_types=1);

use App\Core\Auth;
use App\Core\Config;
use App\Core\Csrf;
use App\Support\Labels;

/** Échappement HTML systématique (protection XSS). */
function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $route, array $params = []): string
{
    $base = rtrim((string) Config::get('app.base_url', ''), '/');
    return $base . '/index.php?' . http_build_query(['r' => $route] + $params);
}

function asset(string $path): string
{
    $base = rtrim((string) Config::get('app.base_url', ''), '/');
    $file = BASE_PATH . '/public/assets/' . ltrim($path, '/');
    $v = is_file($file) ? substr(md5((string) filemtime($file)), 0, 8) : '1';
    return $base . '/assets/' . ltrim($path, '/') . '?v=' . $v;
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(Csrf::token()) . '">';
}

function can(string $permission): bool
{
    return Auth::can($permission);
}

function fmt_date(?string $value, bool $withTime = false): string
{
    if (!$value) {
        return '—';
    }
    $ts = strtotime($value);
    return $ts ? date($withTime ? 'd/m/Y H:i' : 'd/m/Y', $ts) : '—';
}

function status_badge(string $status): string
{
    $s = Labels::PROCEDURE_STATUS[$status] ?? [$status, 'bg-secondary', ''];
    return '<span class="badge status-badge ' . e($s[1]) . '" title="' . e($s[2]) . '">' . e($s[0]) . '</span>';
}

function old(array $data, string $key, mixed $default = ''): string
{
    return e($data[$key] ?? $default);
}

function field_error(array $errors, string $key): string
{
    return isset($errors[$key]) ? '<div class="invalid-feedback d-block">' . e($errors[$key]) . '</div>' : '';
}

function invalid(array $errors, string $key): string
{
    return isset($errors[$key]) ? ' is-invalid' : '';
}

function selected(mixed $a, mixed $b): string
{
    return (string) $a === (string) $b ? ' selected' : '';
}

function checked(mixed $v): string
{
    return $v ? ' checked' : '';
}

function human_size(int $bytes): string
{
    return $bytes >= 1048576 ? round($bytes / 1048576, 1) . ' Mo' : max(1, (int) round($bytes / 1024)) . ' Ko';
}

function person_age(array $p): string
{
    if (!empty($p['birth_date'])) {
        return (string) (new DateTime($p['birth_date']))->diff(new DateTime('today'))->y . ' ans';
    }
    return !empty($p['declared_age']) ? $p['declared_age'] . ' ans (déclaré)' : '—';
}
