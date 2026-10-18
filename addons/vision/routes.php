<?php
/** Routes du module Reconnaissance par photo (chargées seulement si le module est actif). @var App\Core\Router $router */
use Addons\Vision\VisionController as V;

$router->get('/vision', [V::class, 'index'], 'vision.teach');
$router->get('/vision/config', [V::class, 'config'], 'vision.use');
$router->get('/vision/index', [V::class, 'refsIndex'], 'vision.use');
$router->get('/vision/products', [V::class, 'products'], 'vision.use');
$router->get('/vision/search', [V::class, 'search'], 'vision.use');
$router->get('/vision/refs', [V::class, 'refs'], 'vision.teach');
$router->get('/vision/fiches', [V::class, 'fiches'], 'vision.teach');
$router->post('/vision/refs', [V::class, 'addRef'], 'vision.use');
$router->post('/vision/refs/{id}/delete', [V::class, 'deleteRef'], 'vision.teach');
$router->post('/vision/products/{id}/clear', [V::class, 'clearProduct'], 'vision.teach');
$router->post('/vision/log', [V::class, 'log'], 'vision.use');
$router->post('/vision/settings', [V::class, 'settings'], 'vision.teach');
