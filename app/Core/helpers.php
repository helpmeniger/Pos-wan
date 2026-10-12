<?php
declare(strict_types=1);

use App\Core\Config;

if (!function_exists('e')) {
    /** Échappement HTML systématique pour les vues PHP. */
    function e(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('url')) {
    function url(string $path = ''): string
    {
        $base = rtrim((string) parse_url((string) Config::get('app.url', ''), PHP_URL_PATH), '/');
        return $base . '/' . ltrim($path, '/');
    }
}

if (!function_exists('asset')) {
    function asset(string $path): string
    {
        $file = MARWA_ROOT . '/public/assets/' . ltrim($path, '/');
        $v = is_file($file) ? substr(md5((string) filemtime($file)), 0, 8) : '0';
        return url('assets/' . ltrim($path, '/')) . '?v=' . $v;
    }
}

if (!function_exists('fcfa')) {
    function fcfa(int|float|null $amount): string
    {
        return number_format((float) ($amount ?? 0), 0, ',', "\u{202F}") . ' FCFA';
    }
}

if (!function_exists('now')) {
    function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
