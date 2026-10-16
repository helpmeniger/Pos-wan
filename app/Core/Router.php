<?php

declare(strict_types=1);

namespace App\Core;

use App\Middleware\MiddlewareInterface;
use Closure;

/**
 * Routeur : méthodes HTTP, paramètres {nom} ou {nom:regex}, routes nommées,
 * groupes avec préfixe et middleware. Middleware déclarés par alias, avec
 * paramètres éventuels : 'permission:admin.dashboard.view'.
 */
final class Router
{
    /** @var list<array{method: string, path: string, regex: string, handler: mixed, middleware: list<string>, name: ?string}> */
    private array $routes = [];
    /** @var array<string, int> */
    private array $named = [];
    /** @var list<array{prefix: string, middleware: list<string>}> */
    private array $groupStack = [];
    /** @var array<string, class-string<MiddlewareInterface>> */
    private array $aliases = [];
    /** @var list<string> */
    private array $global = [];

    public function __construct(private readonly Container $container)
    {
    }

    /** @param array<string, class-string<MiddlewareInterface>> $aliases */
    public function aliasMiddleware(array $aliases): void
    {
        $this->aliases = array_merge($this->aliases, $aliases);
    }

    /** @param list<string> $middleware */
    public function globalMiddleware(array $middleware): void
    {
        $this->global = $middleware;
    }

    public function get(string $path, mixed $handler, ?string $name = null): self
    {
        return $this->add(['GET', 'HEAD'], $path, $handler, $name);
    }

    public function post(string $path, mixed $handler, ?string $name = null): self
    {
        return $this->add(['POST'], $path, $handler, $name);
    }

    public function put(string $path, mixed $handler, ?string $name = null): self
    {
        return $this->add(['PUT'], $path, $handler, $name);
    }

    public function delete(string $path, mixed $handler, ?string $name = null): self
    {
        return $this->add(['DELETE'], $path, $handler, $name);
    }

    /** @param list<string> $middleware */
    public function group(string $prefix, array $middleware, Closure $routes): void
    {
        $this->groupStack[] = ['prefix' => $prefix, 'middleware' => $middleware];
        $routes($this);
        array_pop($this->groupStack);
    }

    /** Ajoute un middleware à la dernière route déclarée. */
    public function middleware(string ...$middleware): self
    {
        $last = array_key_last($this->routes);
        foreach ($this->routesWithSameDeclaration($last) as $i) {
            $this->routes[$i]['middleware'] = array_merge($this->routes[$i]['middleware'], $middleware);
        }
        return $this;
    }

    /** @param list<string> $methods */
    private function add(array $methods, string $path, mixed $handler, ?string $name): self
    {
        $prefix = '';
        $middleware = [];
        foreach ($this->groupStack as $group) {
            $prefix .= '/' . trim($group['prefix'], '/');
            $middleware = array_merge($middleware, $group['middleware']);
        }
        $full = '/' . trim($prefix . '/' . trim($path, '/'), '/');
        $full = preg_replace('#/+#', '/', $full) ?: '/';
        $regex = $this->compile($full);
        foreach ($methods as $method) {
            $this->routes[] = [
                'method' => $method, 'path' => $full, 'regex' => $regex,
                'handler' => $handler, 'middleware' => $middleware, 'name' => $name,
            ];
        }
        if ($name !== null) {
            $this->named[$name] = array_key_last($this->routes);
        }
        return $this;
    }

    /** @return list<int> */
    private function routesWithSameDeclaration(?int $last): array
    {
        if ($last === null) {
            return [];
        }
        $ref = $this->routes[$last];
        $ids = [];
        foreach ($this->routes as $i => $r) {
            if ($r['path'] === $ref['path'] && $r['handler'] === $ref['handler']) {
                $ids[] = $i;
            }
        }
        return $ids;
    }

    private function compile(string $path): string
    {
        $regex = '';
        foreach (self::tokenize($path) as $token) {
            $regex .= is_string($token)
                ? preg_quote($token, '#')
                : '(?P<' . $token['name'] . '>' . ($token['pattern'] ?? '[^/]+') . ')';
        }
        return '#^' . $regex . '$#u';
    }

    /**
     * Découpe un chemin en littéraux et paramètres {nom} / {nom:regex}.
     * Les accolades d'une contrainte (ex : [0-9a-f]{36}) sont prises en compte.
     *
     * @return list<string|array{name: string, pattern: ?string}>
     */
    private static function tokenize(string $path): array
    {
        $tokens = [];
        $literal = '';
        $len = strlen($path);
        for ($i = 0; $i < $len; $i++) {
            if ($path[$i] !== '{') {
                $literal .= $path[$i];
                continue;
            }
            $depth = 1;
            $j = $i + 1;
            while ($j < $len && $depth > 0) {
                if ($path[$j] === '{') {
                    $depth++;
                } elseif ($path[$j] === '}') {
                    $depth--;
                }
                $j++;
            }
            if ($depth !== 0) {
                throw new \InvalidArgumentException("Accolade non fermée dans la route : {$path}");
            }
            $inner = substr($path, $i + 1, $j - $i - 2);
            [$name, $pattern] = array_pad(explode(':', $inner, 2), 2, null);
            if (!preg_match('/^[a-z_][a-z0-9_]*$/i', (string) $name)) {
                throw new \InvalidArgumentException("Nom de paramètre invalide dans la route : {$path}");
            }
            if ($literal !== '') {
                $tokens[] = $literal;
                $literal = '';
            }
            $tokens[] = ['name' => $name, 'pattern' => $pattern];
            $i = $j - 1;
        }
        if ($literal !== '') {
            $tokens[] = $literal;
        }
        return $tokens;
    }

    /** @param array<string, string|int> $params */
    public function url(string $name, array $params = []): string
    {
        if (!isset($this->named[$name])) {
            throw new \InvalidArgumentException("Route inconnue : {$name}");
        }
        $path = '';
        foreach (self::tokenize($this->routes[$this->named[$name]]['path']) as $token) {
            if (is_string($token)) {
                $path .= $token;
                continue;
            }
            $path .= rawurlencode((string) ($params[$token['name']] ?? ''));
            unset($params[$token['name']]);
        }
        return $path . ($params ? '?' . http_build_query($params) : '');
    }

    public function dispatch(Request $request): Response
    {
        $allowed = [];
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $request->path(), $m)) {
                continue;
            }
            if ($route['method'] !== $request->method()) {
                $allowed[] = $route['method'];
                continue;
            }
            $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            $request->setRouteParams(array_map('rawurldecode', $params));
            $request->setAttribute('route_name', $route['name']);
            $stack = array_merge($this->global, $route['middleware']);
            return $this->runPipeline($stack, $request, fn (Request $r) => $this->callHandler($route['handler'], $r));
        }
        $core = function (): Response {
            throw new HttpException(404);
        };
        if ($allowed) {
            $core = function () use ($allowed): Response {
                throw new HttpException(405, '', ['Allow' => implode(', ', array_unique($allowed))]);
            };
        }
        // Les middleware globaux (session, en-têtes) s'appliquent aussi aux pages d'erreur
        return $this->runPipeline($this->global, $request, $core);
    }

    /** @param list<string> $stack */
    private function runPipeline(array $stack, Request $request, Closure $core): Response
    {
        $next = $core;
        foreach (array_reverse($stack) as $definition) {
            [$alias, $argString] = array_pad(explode(':', $definition, 2), 2, null);
            $class = $this->aliases[$alias] ?? $alias;
            $args = $argString !== null ? explode(',', $argString) : [];
            $next = function (Request $r) use ($class, $args, $next): Response {
                /** @var MiddlewareInterface $mw */
                $mw = $this->container->get($class);
                return $mw->handle($r, $next, ...$args);
            };
        }
        return $next($request);
    }

    private function callHandler(mixed $handler, Request $request): Response
    {
        if ($handler instanceof Closure) {
            $result = $handler($request);
        } elseif (is_array($handler) && count($handler) === 2) {
            [$class, $method] = $handler;
            $controller = $this->container->get($class);
            $result = $controller->{$method}($request);
        } else {
            throw new \LogicException('Gestionnaire de route invalide');
        }
        if ($result instanceof Response) {
            return $result;
        }
        if (is_array($result)) {
            return Response::json($result);
        }
        return Response::html((string) $result);
    }
}
