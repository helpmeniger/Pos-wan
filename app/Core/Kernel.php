<?php
declare(strict_types=1);

namespace App\Core;

use App\Middleware\Middleware;
use Throwable;

/** Point d'entrée HTTP : routage, middlewares, gestion uniforme des erreurs. */
final class Kernel
{
    private Router $router;

    public function __construct()
    {
        $this->router = new Router();
        $router = $this->router;
        require MARWA_ROOT . '/routes/api.php';
        require MARWA_ROOT . '/routes/web.php';
    }

    public function router(): Router
    {
        return $this->router;
    }

    public function handle(Request $request): Response
    {
        Audit::setRequest($request);
        try {
            [$route, $params] = $this->router->match($request->method, $request->path);
            $request->params = $params;

            if (!$request->isApi() || $request->bearerToken() === null) {
                Session::start();
            }
            Auth::resolve($request);

            // CSRF : obligatoire pour toute requête modifiante authentifiée par cookie.
            $exempt = str_starts_with($request->path, '/api/webhooks/') || $request->path === '/api/auth/token';
            if (!$request->isSafeMethod() && !$exempt && Auth::via() !== 'token' && !Csrf::verify($request)) {
                throw new HttpException(419, 'Votre session a expiré. Rechargez la page et réessayez.', 'csrf_mismatch');
            }

            $handler = $route['handler'];
            $core = static function (Request $req) use ($handler): Response {
                if ($handler instanceof \Closure) {
                    return $handler($req);
                }
                [$class, $method] = $handler;
                return (new $class())->$method($req);
            };
            $pipeline = array_reduce(
                array_reverse($route['mw']),
                static fn (callable $next, string $mw) => static fn (Request $r) => Middleware::resolve($mw)->handle($r, $next),
                $core
            );
            $response = $pipeline($request);
        } catch (Throwable $e) {
            $response = $this->renderError($request, $e);
        }
        return $this->secureHeaders($request, $response);
    }

    private function renderError(Request $request, Throwable $e): Response
    {
        if ($e instanceof HttpException) {
            $status = $e->status;
            $payload = ['error' => ['code' => $e->errorCode, 'message' => $e->getMessage()] + $e->details];
        } else {
            $status = 500;
            $id = substr(Str::uuid(), 0, 8);
            Logger::error('Exception non gérée [' . $id . '] ' . $e::class . ': ' . $e->getMessage(), ['file' => $e->getFile() . ':' . $e->getLine()]);
            $msg = 'Une erreur interne est survenue (réf. ' . $id . '). Réessayez ou contactez l\'assistance.';
            if (Config::get('app.debug')) {
                $msg .= ' — ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
            }
            $payload = ['error' => ['code' => 'server_error', 'message' => $msg]];
        }
        if ($request->isApi()) {
            return Response::json($payload, $status);
        }
        if ($status === 401) {
            return Response::redirect(url('connexion') . '?retour=' . rawurlencode($request->path));
        }
        try {
            $user = Auth::user() ? \App\Services\AuthService::present(Auth::user()) : null;
            return Response::html(View::render('pages/error', ['status' => $status, 'message' => $payload['error']['message'], 'title' => 'Erreur ' . $status,
                'csrf' => Csrf::token(), 'user' => $user]), $status);
        } catch (Throwable $inner) {
            Logger::error('Rendu de la page d\'erreur impossible : ' . $inner->getMessage());
            return Response::html('<!doctype html><meta charset="utf-8"><title>Erreur</title><p>' . e($payload['error']['message']) . '</p>', $status);
        }
    }

    private function secureHeaders(Request $request, Response $response): Response
    {
        $tiles = (string) Config::get('services.maps.tile_host', 'https://tile.openstreetmap.org');
        $response->headers += [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'geolocation=(self), camera=(self), microphone=()',
            'Content-Security-Policy' => "default-src 'self'; img-src 'self' data: blob: {$tiles} https://*.tile.openstreetmap.org; "
                . "script-src 'self'; style-src 'self' 'unsafe-inline'; connect-src 'self'; font-src 'self'; "
                . "frame-ancestors 'none'; base-uri 'self'; form-action 'self'",
        ];
        if (Config::get('security.cookie_secure')) {
            $response->headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }
        return $response;
    }
}
