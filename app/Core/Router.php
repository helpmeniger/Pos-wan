<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Routeur simple. Chaque route déclare : méthode, motif, action,
 * et éventuellement la permission exigée. Les routes POST sont
 * systématiquement protégées par CSRF.
 */
final class Router
{
    private array $routes = [];

    public function get(string $pattern, array $action, ?string $perm = null, bool $public = false): void
    {
        $this->add('GET', $pattern, $action, $perm, $public);
    }

    public function post(string $pattern, array $action, ?string $perm = null, bool $public = false): void
    {
        $this->add('POST', $pattern, $action, $perm, $public);
    }

    private function add(string $method, string $pattern, array $action, ?string $perm, bool $public): void
    {
        $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[0-9]+)', $pattern) . '$#';
        $this->routes[] = compact('method', 'pattern', 'regex', 'action', 'perm', 'public');
    }

    public function dispatch(string $method, string $path): void
    {
        $allowed = false;
        foreach ($this->routes as $r) {
            if (!preg_match($r['regex'], $path, $m)) {
                continue;
            }
            $allowed = true;
            if ($r['method'] !== $method) {
                continue;
            }
            if ($method === 'POST') {
                Csrf::verify();
            }
            if (!$r['public']) {
                Auth::requireLogin();
                if ($r['perm'] !== null) {
                    Auth::authorize($r['perm']);
                }
            }
            $params = array_map('intval', array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY));
            [$class, $fn] = $r['action'];
            (new $class())->$fn(...$params);
            return;
        }
        throw new HttpException($allowed ? 405 : 404, $allowed ? 'Méthode non autorisée.' : '');
    }
}
