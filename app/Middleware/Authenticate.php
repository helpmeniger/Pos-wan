<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\Auth\AuthService;

final class Authenticate implements MiddlewareInterface
{
    public function __construct(private readonly AuthService $auth, private readonly Session $session)
    {
    }

    public function handle(Request $request, callable $next, string ...$args): Response
    {
        if ($this->auth->user() === null) {
            if ($request->wantsJson()) {
                throw new HttpException(401);
            }
            if ($request->method() === 'GET') {
                $this->session->put('_intended', $request->path());
            }
            $this->session->flash('warning', $this->session->pull('_expired') ? 'Votre session a expiré, veuillez vous reconnecter.' : 'Veuillez vous connecter pour accéder à cette page.');
            return Response::redirect('/connexion');
        }
        return $next($request);
    }
}
