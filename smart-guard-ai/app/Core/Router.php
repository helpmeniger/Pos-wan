<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Routeur minimal avec paramètres ({id}) et middlewares :
 *  - auth           : utilisateur connecté
 *  - guest          : utilisateur NON connecté
 *  - perm:<code>    : permission requise (vérifiée côté serveur)
 *  - nocsrf         : désactive la vérification CSRF (API authentifiées par jeton)
 *  - ai             : appel du service IA (jeton X-AI-Token + IP autorisée)
 *  - sales_api      : API de ventes externe (jeton Bearer)
 * Toute requête POST/PUT/DELETE est protégée par CSRF sauf « nocsrf ».
 */
final class Router
{
    private array $routes = [];
    private static ?string $base = null;

    public function get(string $path, array $handler, array $mw = []): void { $this->add('GET', $path, $handler, $mw); }
    public function post(string $path, array $handler, array $mw = []): void { $this->add('POST', $path, $handler, $mw); }

    public function add(string $method, string $path, array $handler, array $mw): void
    {
        $regex = '#^' . preg_replace_callback('/\{(\w+)(?::([^}]+))?\}/', static function ($m) {
            return '(?P<' . $m[1] . '>' . ($m[2] ?? '[^/]+') . ')';
        }, rtrim($path, '/') ?: '/') . '$#';
        $this->routes[] = compact('method', 'path', 'regex', 'handler', 'mw');
    }

    public static function basePath(): string
    {
        if (self::$base !== null) {
            return self::$base;
        }
        $env = Env::get('APP_BASE_PATH');
        if ($env !== null) {
            return self::$base = rtrim((string) $env, '/');
        }
        if (PHP_SAPI === 'cli') {
            return self::$base = '';
        }
        $script = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        if (str_ends_with($script, '/public') && !str_starts_with($uri, $script)) {
            $script = substr($script, 0, -7);
        }
        return self::$base = rtrim($script, '/');
    }

    public function dispatch(Request $request): void
    {
        $path = $request->path();
        $base = self::basePath();
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }
        if (str_starts_with($path, '/public/') || $path === '/public') {
            $path = substr($path, 7);
        }
        $path = '/' . trim($path, '/');
        $method = $request->method();

        $allowed = [];
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $m)) {
                continue;
            }
            if ($route['method'] !== $method && !($method === 'HEAD' && $route['method'] === 'GET')) {
                $allowed[] = $route['method'];
                continue;
            }
            $request->params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            try {
                $this->runMiddleware($route['mw'], $request, $method);
                [$class, $action] = $route['handler'];
                (new $class())->$action($request);
            } catch (HttpException $e) {
                $this->renderError($request, $e->status, $e->getMessage());
            }
            return;
        }
        $this->renderError($request, $allowed ? 405 : 404, $allowed ? 'Méthode non autorisée.' : 'Page introuvable.');
    }

    private function runMiddleware(array $mw, Request $request, string $method): void
    {
        $isApi = in_array('nocsrf', $mw, true) || in_array('ai', $mw, true) || in_array('sales_api', $mw, true);
        if ($method !== 'GET' && $method !== 'HEAD' && !$isApi) {
            $token = $request->input('_csrf') ?? $request->header('X-CSRF-Token');
            if (!Csrf::verify(is_string($token) ? $token : null)) {
                throw new HttpException(419);
            }
        }
        foreach ($mw as $m) {
            if ($m === 'auth' && !Auth::check()) {
                if ($request->wantsJson()) {
                    throw new HttpException(401);
                }
                Session::set('_intended', $request->path());
                Response::redirect('login');
            }
            if ($m === 'guest' && Auth::check()) {
                Response::redirect('dashboard');
            }
            if (str_starts_with($m, 'perm:')) {
                foreach (explode('|', substr($m, 5)) as $perm) {
                    if (Auth::can($perm)) {
                        continue 2;
                    }
                }
                Audit::log('acces_refuse', 'route', null, ['path' => $request->path(), 'perm' => substr($m, 5)]);
                throw new HttpException(403);
            }
            if ($m === 'ai') {
                $this->checkAiCaller($request);
            }
            if ($m === 'sales_api') {
                $expected = (string) Env::get('SALES_API_TOKEN', '');
                $auth = (string) ($request->header('Authorization') ?? '');
                $given = str_starts_with($auth, 'Bearer ') ? substr($auth, 7) : '';
                if ($expected === '' || strlen($expected) < 24 || !hash_equals($expected, $given)) {
                    throw new HttpException(401, 'Jeton API invalide ou API désactivée.');
                }
            }
        }
    }

    private function checkAiCaller(Request $request): void
    {
        $allowedIps = Env::list('AI_CALLBACK_ALLOWED_IPS') ?: ['127.0.0.1', '::1'];
        if (!in_array($request->ip(), $allowedIps, true)) {
            Logger::warning('Appel /api/ai refusé (IP non autorisée)', ['ip' => $request->ip()]);
            throw new HttpException(403, 'IP non autorisée.');
        }
        $expected = (string) Env::get('AI_CALLBACK_TOKEN', '');
        $given = (string) ($request->header('X-AI-Token') ?? '');
        if ($expected === '' || !hash_equals($expected, $given)) {
            throw new HttpException(401, 'Jeton du service IA invalide.');
        }
    }

    private function renderError(Request $request, int $status, string $message): void
    {
        http_response_code($status);
        if ($request->wantsJson() || str_starts_with(ltrim(substr($request->path(), strlen(self::basePath())), '/'), 'api/')) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
            return;
        }
        View::render('errors/error', ['title' => 'Erreur ' . $status, 'status' => $status, 'message' => $message], Auth::check() ? 'layouts/app' : 'layouts/guest');
    }
}
