<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\SecurityService;

/**
 * Administration : adresses IP autorisées (ADMIN_ALLOWED_IPS) et double authentification
 * obligatoire pour le personnel lorsque le paramètre security.staff_2fa_required vaut 1.
 */
final class StaffGuard extends Middleware
{
    /** $check2fa = false pour la page /admin elle-même (le profil doit rester accessible). */
    public function __construct(private bool $check2fa = true)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        if (!SecurityService::adminIpAllowed($request->ip())) {
            \App\Core\Logger::warning('Accès administration refusé (adresse IP non autorisée)', ['ip' => $request->ip()]);
            throw HttpException::forbidden('Accès à l\'administration non autorisé depuis cette adresse.');
        }
        $u = Auth::user();
        if ($this->check2fa && $u && SecurityService::totpRequired($u) && !SecurityService::totpEnabled($u)) {
            throw new HttpException(403, 'Activez la double authentification dans « Mon profil » pour accéder à l\'administration.', 'two_factor_setup_required');
        }
        return $next($request);
    }
}
