<?php

declare(strict_types=1);

use App\Controllers\Api\V1;
use App\Core\Router;

/*
 * API REST v1 (JSON). Référentiels publics en lecture seule pour l'instant ;
 * l'authentification par jeton Bearer (table api_tokens) arrive avec la Phase 3.
 */
return function (Router $r): void {
    $r->group('/api/v1', [], function (Router $r) {
        $r->get('/health', [V1\MetaController::class, 'health']);
        $r->get('/cities', [V1\MetaController::class, 'cities']);
        $r->get('/cities/{id:\d+}/neighborhoods', [V1\MetaController::class, 'neighborhoods']);
        $r->get('/categories', [V1\MetaController::class, 'categories']);
    });
};
