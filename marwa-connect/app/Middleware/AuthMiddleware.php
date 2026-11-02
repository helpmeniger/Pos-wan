<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use Closure;

/** Exige un utilisateur authentifié et actif. */
final class AuthMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, Closure $next, ?string $argument = null): Response
    {
        if (Auth::check() && !Auth::validateFingerprint($request)) {
            Auth::logout();
        }
        if (!Auth::check()) {
            if ($request->isAjax()) {
                return Response::json(['success' => false, 'message' => 'Session expirée. Veuillez vous reconnecter.'], 401);
            }
            if ($request->method === 'GET') {
                Session::put('_intended', $request->path);
            }
            return Response::redirect('/login');
        }
        $user = Auth::user();
        if (!empty($user['must_change_password']) && !in_array($request->path, ['/profile', '/profile/password', '/logout'], true) && !$request->isAjax()) {
            Session::flash('warning', 'Pour des raisons de sécurité, vous devez changer votre mot de passe.');
            return Response::redirect('/profile');
        }
        return $next($request);
    }
}
