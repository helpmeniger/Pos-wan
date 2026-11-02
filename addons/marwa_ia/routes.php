<?php
/** Routes du module MARWA IA. @var App\Core\Router $router */
use Addons\MarwaIa\IaController as I;

$router->get('/ia', [I::class, 'index'], 'ia.use');
$router->get('/ia/etat', [I::class, 'state'], 'ia.use');
$router->post('/ia/ask', [I::class, 'ask'], 'ia.use');
$router->get('/ia/rapport', [I::class, 'rapport'], 'ia.use');
$router->get('/ia/marketing', [I::class, 'marketing'], 'ia.use');
$router->post('/ia/oubli', [I::class, 'forget'], 'ia.use');
$router->get('/ia/reglages', [I::class, 'settings'], 'ia.manage');
$router->post('/ia/reglages', [I::class, 'settingsSave'], 'ia.manage');
