<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;

/**
 * Middlewares disponibles (alias utilisés dans routes/*.php) :
 *  auth                       utilisateur connecté (statut actif ou en attente)
 *  role:client,business       rôle principal parmi la liste
 *  perm:finance.view          permission d'administration
 *  throttle:nom,max,secondes  limitation de débit par utilisateur ou IP
 *  verified_provider          chauffeur/livreur validé par l'administration
 *  active                     compte actif (pas en attente)
 *  staff_guard                IP autorisées + double authentification du personnel
 */
abstract class Middleware
{
    abstract public function handle(Request $request, callable $next): Response;

    public static function resolve(string $alias): self
    {
        [$name, $args] = array_pad(explode(':', $alias, 2), 2, '');
        $args = $args === '' ? [] : explode(',', $args);
        return match ($name) {
            'auth' => new Authenticate(),
            'active' => new RequireActive(),
            'role' => new RequireRole($args),
            'perm' => new RequirePermission($args),
            'throttle' => new Throttle($args[0] ?? 'global', (int) ($args[1] ?? 60), (int) ($args[2] ?? 60)),
            'verified_provider' => new RequireVerifiedProvider(),
            'staff_guard' => new StaffGuard(),
            'staff_ip' => new StaffGuard(false),
            default => throw new \LogicException("Middleware inconnu : $alias"),
        };
    }
}
