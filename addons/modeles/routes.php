<?php
/** Routes du module Modèles de documents. @var App\Core\Router $router */
use Addons\Modeles\ModeleController as M;

$router->get('/modeles', [M::class, 'index'], 'modeles.manage');
$router->post('/modeles/affectations', [M::class, 'assign'], 'modeles.manage');
$router->get('/modeles/nouveau', [M::class, 'create'], 'modeles.manage');
$router->post('/modeles', [M::class, 'store'], 'modeles.manage');
$router->post('/modeles/apercu', [M::class, 'preview'], 'modeles.manage');
$router->get('/modeles/apercu/{id}', [M::class, 'previewSaved'], 'modeles.manage');
$router->post('/modeles/importer', [M::class, 'import'], 'modeles.manage');
$router->get('/modeles/{id}', [M::class, 'edit'], 'modeles.manage');
$router->post('/modeles/{id}', [M::class, 'update'], 'modeles.manage');
$router->post('/modeles/{id}/dupliquer', [M::class, 'duplicate'], 'modeles.manage');
$router->post('/modeles/{id}/supprimer', [M::class, 'delete'], 'modeles.manage');
$router->get('/modeles/{id}/exporter', [M::class, 'export'], 'modeles.manage');
