<?php
/** Routes du module ONG (chargées seulement si le module est actif). @var App\Core\Router $router */
use Addons\Ong\OngController as O;

$router->get('/ong', [O::class, 'dashboard'], 'ong.view');
$router->post('/ong/fiches', [O::class, 'saveSimple'], 'ong.view'); // droit précis contrôlé selon la fiche
$router->get('/ong/projets', [O::class, 'projets'], 'ong.view');
$router->get('/ong/projets/{id}', [O::class, 'projet'], 'ong.view');
$router->post('/ong/projets/{id}/indicateurs', [O::class, 'indicateur'], 'ong.projets');
$router->get('/ong/conventions', [O::class, 'conventions'], 'ong.view');
$router->post('/ong/conventions', [O::class, 'conventionSave'], 'ong.projets');
$router->get('/ong/conventions/{id}', [O::class, 'convention'], 'ong.view');
$router->post('/ong/conventions/{id}/lignes', [O::class, 'ligneSave'], 'ong.projets');
$router->get('/ong/conventions/{id}/rapport', [O::class, 'rapport'], 'ong.view');
$router->get('/ong/depenses', [O::class, 'depenses'], 'ong.view');
$router->post('/ong/depenses', [O::class, 'depenseSubmit'], 'ong.depenses');
$router->get('/ong/depenses/{id}', [O::class, 'depensePiece'], 'ong.view');
$router->post('/ong/depenses/{id}/approuver', [O::class, 'depenseApprove'], 'ong.approve');
$router->post('/ong/depenses/{id}/rejeter', [O::class, 'depenseReject'], 'ong.approve');
$router->post('/ong/depenses/{id}/payer', [O::class, 'depensePay'], 'ong.tresorerie');
$router->post('/ong/depenses/{id}/annuler', [O::class, 'depenseCancel'], 'ong.admin');
$router->get('/ong/tresorerie', [O::class, 'tresorerie'], 'ong.view');
$router->post('/ong/tresorerie/recettes', [O::class, 'recette'], 'ong.tresorerie');
$router->post('/ong/tresorerie/virements', [O::class, 'virement'], 'ong.tresorerie');
$router->post('/ong/tresorerie/mouvements/{id}/annuler', [O::class, 'mouvementCancel'], 'ong.admin');
$router->get('/ong/missions', [O::class, 'missions'], 'ong.view');
$router->post('/ong/missions', [O::class, 'missionRequest'], 'ong.depenses');
$router->get('/ong/missions/{id}', [O::class, 'mission'], 'ong.view');
$router->get('/ong/missions/{id}/ordre', [O::class, 'missionOrdre'], 'ong.view');
$router->post('/ong/missions/{id}/approuver', [O::class, 'missionApprove'], 'ong.approve');
$router->post('/ong/missions/{id}/avance', [O::class, 'missionAdvance'], 'ong.tresorerie');
$router->post('/ong/missions/{id}/depart', [O::class, 'missionStart'], 'ong.depenses');
$router->post('/ong/missions/{id}/retour', [O::class, 'missionFinish'], 'ong.depenses');
$router->post('/ong/missions/{id}/cloturer', [O::class, 'missionClose'], 'ong.tresorerie');
$router->post('/ong/missions/{id}/annuler', [O::class, 'missionCancel'], 'ong.approve');
$router->get('/ong/logistique', [O::class, 'logistique'], 'ong.view');
$router->post('/ong/logistique/carnet', [O::class, 'carnet'], 'ong.logistique');
$router->post('/ong/logistique/stock', [O::class, 'stock'], 'ong.logistique');
$router->get('/ong/personnel', [O::class, 'personnel'], 'ong.view');
