<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;

final class RequireVerifiedProvider extends Middleware
{
    public function handle(Request $request, callable $next): Response
    {
        Auth::requireUser();
        $status = Database::value('SELECT verification_status FROM provider_profiles WHERE user_id = :id', ['id' => Auth::id()]);
        if ($status !== 'verified') {
            throw HttpException::forbidden('Votre compte prestataire doit être validé par MARWA avant de recevoir des commandes.');
        }
        return $next($request);
    }
}
