<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Routeur HTTP : routes nommées, paramètres {id}, groupes et middlewares.
 */
final class Router
{
    private array $routes = [];
    private array $groupStack = [];
    private array $named = [];

    public function get(string $path, array|\Closure $action, string $name = ''): self
    {
        return $this->add(['GET', 'HEAD'], $path, $action, $name);
    }

    public function post(string $path, array|\Closure $action, string $name = ''): self
    {
        return $this->add(['POST'], $path, $action, $name);
    }

    public function put(string $path, array|\Closure $action, string $name = ''): self
    {
        return $this->add(['PUT', 'PATCH'], $path, $action, $name);
    }

    public function delete(string $path, array|\Closure $action, string $name = ''): self
    {
        return $this->add(['DELETE'], $path, $action, $name);
    }

    /**
     * @param array{prefix?:string, middleware?:array} $attributes
     */
    public function group(array $attributes, callable $callback): void
    {
        $this->groupStack[] = $attributes;
        $callback($this);
        array_pop($this->groupStack);
    }

    private function add(array $methods, string $path, array|\Closure $action, string $name): self
    {
        $prefix = '';
        $middleware = [];
        foreach ($this->groupStack as $g) {
            $prefix .= '/' . trim($g['prefix'] ?? '', '/');
            $middleware = array_merge($middleware, $g['middleware'] ?? []);
        }
        $full = '/' . trim(preg_replace('#/+#', '/', $prefix . '/' . trim($path, '/')), '/');
        $regex = '#^' . preg_replace('#\{([a-zA-Z_]+)\}#', '(?P<$1>[^/]+)', $full) . '$#';
        $route = compact('methods', 'full', 'regex', 'action', 'middleware', 'name');
        $this->routes[] = $route;
        if ($name !== '') {
            $this->named[$name] = $full;
        }
        return $this;
    }

    public function urlFor(string $name, array $params = []): string
    {
        $path = $this->named[$name] ?? throw new \InvalidArgumentException("Route inconnue : {$name}");
        foreach ($params as $k => $v) {
            $path = str_replace('{' . $k . '}', rawurlencode((string) $v), $path);
        }
        return Url::to($path);
    }

    public function dispatch(Request $request): Response
    {
        $allowed = [];
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $request->path, $m)) {
                continue;
            }
            if (!in_array($request->method, $route['methods'], true)) {
                $allowed = array_merge($allowed, $route['methods']);
                continue;
            }
            $request->routeParams = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            return $this->runPipeline($request, $route);
        }
        if ($allowed) {
            throw new HttpException(405, 'Méthode non autorisée.', ['Allow' => implode(', ', array_unique($allowed))]);
        }
        throw new HttpException(404, 'Page introuvable.');
    }

    private function runPipeline(Request $request, array $route): Response
    {
        $core = function (Request $req) use ($route): Response {
            $action = $route['action'];
            if ($action instanceof \Closure) {
                $result = $action($req, ...array_values($req->routeParams));
            } else {
                [$class, $method] = $action;
                $controller = new $class();
                $result = $controller->$method($req, ...array_values($req->routeParams));
            }
            return $result instanceof Response ? $result : Response::html((string) $result);
        };

        $pipeline = array_reduce(
            array_reverse($route['middleware']),
            static function (\Closure $next, string $spec): \Closure {
                [$name, $arg] = array_pad(explode(':', $spec, 2), 2, null);
                $class = Middleware::resolve($name);
                return static fn (Request $req): Response => (new $class())->handle($req, $next, $arg);
            },
            $core
        );
        return $pipeline($request);
    }
}
