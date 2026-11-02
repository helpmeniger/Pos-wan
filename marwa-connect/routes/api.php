<?php
/** @var App\Core\Router $router */
use App\Controllers\Api\SystemController;

/*
 * API interne (JSON) utilisée par l'interface. Authentification par session +
 * en-tête X-CSRF-Token pour toute requête d'écriture. Jamais d'identifiants
 * MikroTik dans les réponses.
 */
$router->group(['prefix' => '/api', 'middleware' => ['csrf', 'auth', 'throttle:240,1']], static function ($r) {
    $r->get('/ping', [SystemController::class, 'ping'], 'api.ping');
    $r->get('/me', [SystemController::class, 'me'], 'api.me');
    $r->get('/notifications', [SystemController::class, 'notifications'], 'api.notifications');
    $r->post('/notifications/read', [SystemController::class, 'markRead'], 'api.notifications.read');
    $r->post('/preferences/theme', [SystemController::class, 'theme'], 'api.theme');
    $r->post('/preferences/router', [SystemController::class, 'selectRouter'], 'api.router');
});
