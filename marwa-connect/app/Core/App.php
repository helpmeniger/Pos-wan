<?php
declare(strict_types=1);

namespace App\Core;

use Throwable;

/**
 * Noyau de l'application : démarre la session, charge les routes et traite la requête.
 */
final class App
{
    private static ?Router $router = null;

    public static function router(): Router
    {
        return self::$router ??= new Router();
    }

    public static function installed(): bool
    {
        return Env::exists() && is_file(STORAGE_PATH . '/installed.lock');
    }

    public function handle(Request $request): Response
    {
        try {
            Session::start($request->isSecure(), !$request->isAjax());
            View::share('request', $request);
            $GLOBALS['__marwa_path'] = $request->path;

            $router = self::router();
            $routes = BASE_PATH . '/routes';
            if (self::installed()) {
                require $routes . '/web.php';
                require $routes . '/api.php';
            }
            require $routes . '/install.php';

            if (!self::installed() && !str_starts_with($request->path, '/install')) {
                return Response::redirect('/install');
            }
            return $router->dispatch($request);
        } catch (\App\Controllers\ValidationFailed $e) {
            return $e->response;
        } catch (Throwable $e) {
            ErrorHandler::report($e);
            return ErrorHandler::render($e, $request);
        }
    }

    public function run(): void
    {
        $request = Request::capture();
        $this->handle($request)->send();
    }
}
