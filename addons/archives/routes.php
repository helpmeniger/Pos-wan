<?php
/** Routes du module Archives (chargées seulement si le module est actif). @var App\Core\Router $router */
use Addons\Archives\ArchiveController as A;

$router->get('/archives', [A::class, 'index'], 'archives.view');
$router->post('/archives/dossiers', [A::class, 'dossierSave'], 'archives.manage');
$router->post('/archives/dossiers/{id}/supprimer', [A::class, 'dossierDelete'], 'archives.manage');
$router->post('/archives/documents', [A::class, 'deposit'], 'archives.upload');
$router->get('/archives/documents/{id}', [A::class, 'show'], 'archives.view');
$router->post('/archives/documents/{id}', [A::class, 'update'], 'archives.manage');
$router->post('/archives/documents/{id}/version', [A::class, 'version'], 'archives.upload');
$router->post('/archives/documents/{id}/corbeille', [A::class, 'trash'], 'archives.manage');
$router->post('/archives/documents/{id}/restaurer', [A::class, 'restore'], 'archives.manage');
$router->post('/archives/documents/{id}/supprimer', [A::class, 'purge'], 'archives.admin');
$router->get('/archives/fichiers/{id}', [A::class, 'file'], 'archives.view');
