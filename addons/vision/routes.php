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

// Caméras et surveillance des passages (1.1)
use Addons\Vision\SurveillanceController as S;

$router->get('/vision/cameras', [S::class, 'cameras'], 'vision.watch');
$router->post('/vision/cameras', [S::class, 'createCamera'], 'vision.watch');
$router->post('/vision/cameras/settings', [S::class, 'settings'], 'vision.watch');
$router->post('/vision/cameras/{id}', [S::class, 'saveCamera'], 'vision.watch');
$router->post('/vision/cameras/{id}/delete', [S::class, 'deleteCamera'], 'vision.watch');
$router->post('/vision/cameras/{id}/test', [S::class, 'testCamera'], 'vision.watch');
$router->post('/vision/cameras/{id}/geometry', [S::class, 'geometry'], 'vision.watch');
$router->get('/vision/cameras/{id}/frame', [S::class, 'frame'], 'vision.use');
$router->get('/vision/sources', [S::class, 'sources'], 'vision.use');
$router->get('/vision/surveillance', [S::class, 'surveillance'], 'vision.watch');
$router->get('/vision/surveillance/today', [S::class, 'today'], 'vision.watch');
$router->post('/vision/events', [S::class, 'addEvent'], 'vision.watch');
$router->post('/vision/events/{id}', [S::class, 'review'], 'vision.watch');
$router->get('/vision/rapport', [S::class, 'report'], 'vision.watch');

// Contrôle « rayon → caisse » (1.3) : emplacements d'étagère, suivi des articles, alertes de vérification
use Addons\Vision\ControleController as K;

$router->post('/vision/cameras/{id}/slots', [K::class, 'slots'], 'vision.watch');
$router->post('/vision/parcours/settings', [K::class, 'settings'], 'vision.watch');
$router->get('/vision/controles', [K::class, 'index'], 'vision.watch');
$router->get('/vision/controles/live', [K::class, 'live'], 'vision.watch');
$router->post('/vision/controles/{id}', [K::class, 'review'], 'vision.watch');
$router->get('/vision/controles/caisse', [K::class, 'cashier'], 'vision.use');
