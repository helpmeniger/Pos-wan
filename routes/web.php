<?php
/**
 * Pages web (rendu serveur du squelette ; les données sont chargées via l'API).
 * @var App\Core\Router $router
 */

use App\Controllers\PageController;

$router->get('/', [PageController::class, 'home']);
$router->get('/connexion', [PageController::class, 'login']);
$router->get('/inscription', [PageController::class, 'register']);
$router->get('/mot-de-passe-oublie', [PageController::class, 'forgot']);
$router->get('/reinitialiser', [PageController::class, 'reset']);
$router->get('/hors-ligne', [PageController::class, 'offline']);
$router->get('/pages/{slug}', [PageController::class, 'content']);

$router->get('/client', [PageController::class, 'client'], ['auth', 'role:client,business']);
$router->get('/entreprise', [PageController::class, 'business'], ['auth', 'role:business,client']);
$router->get('/prestataire', [PageController::class, 'provider'], ['auth', 'role:driver,courier']);
$router->get('/commerce', [PageController::class, 'merchant'], ['auth', 'role:merchant']);
$router->get('/admin', [PageController::class, 'admin'], ['auth', 'role:admin,support,finance']);
$router->get('/paiement/demo/{uuid}', [PageController::class, 'demoPayment'], ['auth']);
$router->get('/fichiers/{id}', [PageController::class, 'file']);
