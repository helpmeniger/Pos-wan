<?php
/** Routes de MARWA REMOTE ASSIST (chargées seulement si le module est actif). @var App\Core\Router $router */
use Addons\MarwaAssist\AssistController as A;

$router->get('/assist', [A::class, 'index'], 'assist.request');
$router->get('/assist/etat', [A::class, 'state'], 'assist.request');
$router->post('/assist/demande', [A::class, 'request'], 'assist.request');
$router->post('/assist/{id}/partager', [A::class, 'share'], 'assist.request');
$router->post('/assist/{id}/controle', [A::class, 'control'], 'assist.request');
$router->post('/assist/{id}/terminer', [A::class, 'end'], 'assist.request');
$router->post('/assist/{id}/terminer.json', [A::class, 'endJson'], 'assist.request');
$router->post('/assist/kit', [A::class, 'kit'], 'assist.manage');
$router->post('/assist/postes/{id}/id', [A::class, 'posteId'], 'assist.manage');
$router->post('/assist/postes/{id}/revoquer', [A::class, 'revoke'], 'assist.manage');
$router->post('/assist/reglages', [A::class, 'settings'], 'assist.manage');
$router->post('/assist/boutiques', [A::class, 'boutique'], 'assist.manage');
$router->post('/assist/boutiques/{id}', [A::class, 'boutique'], 'assist.manage');
$router->post('/assist/boutiques/{id}/archiver', [A::class, 'archiveBoutique'], 'assist.manage');
// Appelée par le script du kit d'installation (pas de session) : protégée par un jeton secret
$router->get('/assist/enregistrer', [A::class, 'register'], null, true);
// Agent automatique du poste (tâche planifiée Windows) : état à appliquer, protégé par le jeton du kit
$router->get('/assist/agent', [A::class, 'agent'], null, true);
