<?php
/** Routes du module Banques et coffres (chargées seulement si le module est actif). @var App\Core\Router $router */
use Addons\Banques\BankController as B;

$router->get('/banques', [B::class, 'index'], 'banques.view');
$router->get('/banques/historique', [B::class, 'history'], 'banques.view');
$router->get('/banques/versements', [B::class, 'sessions'], 'banques.operate');
$router->post('/banques/versements/{id}', [B::class, 'depositSession'], 'banques.operate');
$router->post('/banques/operations', [B::class, 'operation'], 'banques.operate');
$router->post('/banques/virements', [B::class, 'transfer'], 'banques.operate');
$router->post('/banques/ajustements', [B::class, 'adjust'], 'banques.manage');
$router->post('/banques/comptes', [B::class, 'accountStore'], 'banques.manage');
$router->get('/banques/comptes/{id}', [B::class, 'statement'], 'banques.view');
$router->post('/banques/comptes/{id}', [B::class, 'accountUpdate'], 'banques.manage');
$router->get('/banques/operations/{id}', [B::class, 'receipt'], 'banques.view');
$router->post('/banques/operations/{id}/annuler', [B::class, 'cancel'], 'banques.manage');
