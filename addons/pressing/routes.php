<?php
/** Routes du module Pressing (chargées seulement si le module est actif). @var App\Core\Router $router */
use Addons\Pressing\PressingController as P;

$router->get('/pressing', [P::class, 'index'], 'pressing.view');
$router->get('/pressing/create', [P::class, 'create'], 'pressing.deposit');
$router->post('/pressing', [P::class, 'store'], 'pressing.deposit');
$router->get('/pressing/tarifs', [P::class, 'tarifs'], 'pressing.manage');
$router->post('/pressing/tarifs', [P::class, 'tarifsSave'], 'pressing.manage');
$router->get('/pressing/report', [P::class, 'report'], 'pressing.manage');
$router->get('/pressing/caisse', [P::class, 'caisse'], 'pressing.deposit');
$router->get('/pressing/suivi', [P::class, 'suivi'], 'pressing.view');
$router->get('/pressing/client', [P::class, 'client'], 'pressing.view');
$router->get('/pressing/ecran', [P::class, 'ecran'], 'pressing.view');
$router->get('/pressing/ecran/feed', [P::class, 'ecranFeed'], 'pressing.view');
$router->post('/pressing/ecran/{id}/statut', [P::class, 'ecranStatus'], 'pressing.process');
$router->post('/pressing/ecran/{id}/livrer', [P::class, 'ecranDeliver'], 'pressing.deposit');
$router->post('/pressing/reglages', [P::class, 'reglages'], 'pressing.manage');
$router->get('/pressing/find', [P::class, 'find'], 'pressing.view');
$router->get('/pressing/clients', [P::class, 'clients'], 'pressing.clients');
$router->get('/pressing/clients/{id}', [P::class, 'clientEdit'], 'pressing.clients');
$router->post('/pressing/clients/{id}', [P::class, 'clientSave'], 'pressing.clients');
$router->get('/pressing/livraisons', [P::class, 'livraisons'], 'pressing.deliver');
$router->post('/pressing/livraisons/{id}', [P::class, 'livraisonDone'], 'pressing.deliver');
$router->get('/pressing/{id}', [P::class, 'show'], 'pressing.view');
$router->post('/pressing/{id}/status', [P::class, 'status'], 'pressing.process');
$router->post('/pressing/{id}/pay', [P::class, 'pay'], 'pressing.deposit');
$router->post('/pressing/{id}/deliver', [P::class, 'deliver'], 'pressing.deposit');
$router->post('/pressing/{id}/partial', [P::class, 'partial'], 'pressing.deposit');
$router->post('/pressing/{id}/cancel', [P::class, 'cancel'], 'pressing.manage');
$router->get('/pressing/{id}/ticket', [P::class, 'ticket'], 'pressing.view');
$router->get('/pressing/{id}/labels', [P::class, 'labels'], 'pressing.view');
