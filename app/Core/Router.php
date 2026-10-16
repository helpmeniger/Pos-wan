<?php
declare(strict_types=1);

namespace App\Core;

final class Router
{
    private array $routes = [];
    private array $groupStack = [];

    public function get(string $path, array|\Closure $handler, array $mw = []): void { $this->add('GET', $path, $handler, $mw); }
    public function post(string $path, array|\Closure $handler, array $mw = []): void { $this->add('POST', $path, $handler, $mw); }
    public function put(string $path, array|\Closure $handler, array $mw = []): void { $this->add('PUT', $path, $handler, $mw); }
    public function patch(string $path, array|\Closure $handler, array $mw = []): void { $this->add('PATCH', $path, $handler, $mw); }
    public function delete(string $path, array|\Closure $handler, array $mw = []): void { $this->add('DELETE', $path, $handler, $mw); }

    /** Regroupe des routes partageant un préfixe et des middlewares. */
    public function group(string $prefix, array $mw, callable $fn): void
    {
        $this->groupStack[] = ['prefix' => $prefix, 'mw' => $mw];
        $fn($this);
        array_pop($this->groupStack);
    }

    public function add(string $method, string $path, array|\Closure $handler, array $mw = []): void
    {
        $prefix = '';
        $groupMw = [];
        foreach ($this->groupStack as $g) {
            $prefix .= $g['prefix'];
            $groupMw = array_merge($groupMw, $g['mw']);
        }
        $full = '/' . trim($prefix . $path, '/');
        $regex = '#^' . preg_replace('#\{([a-z_]+)\}#', '(?P<$1>[^/]+)', $full) . '$#';
        $this->routes[] = ['method' => $method, 'path' => $full, 'regex' => $regex, 'handler' => $handler, 'mw' => array_merge($groupMw, $mw)];
    }

    /** @return array{0: array, 1: array} route + paramètres */
    public function match(string $method, string $path): array
    {
        $allowed = [];
        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $path, $m)) {
                if ($route['method'] !== $method && !($method === 'HEAD' && $route['method'] === 'GET')) {
                    $allowed[] = $route['method'];
                    continue;
                }
                return [$route, array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY)];
            }
        }
        if ($allowed) {
            throw new HttpException(405, 'Méthode non autorisée.', 'method_not_allowed');
        }
        throw HttpException::notFound('Page ou ressource introuvable.');
    }

    public function routes(): array
    {
        return $this->routes;
    }
}
