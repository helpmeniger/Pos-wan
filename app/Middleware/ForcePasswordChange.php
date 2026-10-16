<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\Auth\AuthService;

/** Un compte avec mot de passe temporaire ne peut rien faire d'autre que le changer (ou se déconnecter). */
final class ForcePasswordChange implements MiddlewareInterface
{
    private const ALLOWED = ['/mot-de-passe/changer', '/deconnexion'];

    public function __construct(private readonly AuthService $auth, private readonly Session $session)
    {
    }

    public function handle(Request $request, callable $next, string ...$args): Response
    {
        $user = $this->auth->user();
        if ($user !== null && (int) $user['must_change_password'] === 1
            && !in_array($request->path(), self::ALLOWED, true)
            && !str_starts_with($request->path(), '/assets/')) {
            $this->session->flash('warning', 'Vous devez définir un nouveau mot de passe avant de continuer.');
            return Response::redirect('/mot-de-passe/changer');
        }
        return $next($request);
    }
}
