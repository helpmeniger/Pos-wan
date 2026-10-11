<?php
/** Routes du module Livraisons (chargées seulement si le module est actif). @var App\Core\Router $router */
use Addons\Livraisons\LivraisonController as L;

$router->get('/livraisons', [L::class, 'index'], 'livraisons.view');
$router->post('/livraisons', [L::class, 'store'], 'livraisons.manage');
$router->post('/livraisons/{id}/status', [L::class, 'status'], 'livraisons.manage');
