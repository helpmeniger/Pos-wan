<?php
/** Routes du module Doublons d'articles. @var App\Core\Router $router */
use Addons\Doublons\DoublonController as D;

$router->get('/doublons', [D::class, 'index'], 'doublons.manage');
$router->post('/doublons/fusionner', [D::class, 'merge'], 'doublons.manage');
$router->post('/doublons/fusionner-tout', [D::class, 'mergeAll'], 'doublons.manage');
$router->get('/doublons/journal', [D::class, 'journal'], 'doublons.manage');
