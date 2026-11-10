<?php
/** Routes du module Ressources humaines (chargées seulement si le module est actif). @var App\Core\Router $router */
use Addons\Grh\GrhController as G;

$router->get('/grh', [G::class, 'dashboard'], 'grh.view');
$router->get('/grh/employes', [G::class, 'employes'], 'grh.view');
$router->get('/grh/employes/fiche', [G::class, 'form'], 'grh.manage');
$router->post('/grh/employes', [G::class, 'save'], 'grh.manage');
$router->get('/grh/employes/{id}', [G::class, 'show'], 'grh.view');
$router->get('/grh/employes/{id}/attestation', [G::class, 'attestation'], 'grh.manage');
$router->post('/grh/employes/{id}/contrats', [G::class, 'contrat'], 'grh.paie');
$router->post('/grh/employes/{id}/dossier', [G::class, 'dossier'], 'grh.manage');
$router->post('/grh/employes/{id}/etat', [G::class, 'etat'], 'grh.manage');
$router->post('/grh/employes/{id}/sortie', [G::class, 'sortie'], 'grh.manage');
$router->get('/grh/structure', [G::class, 'structure'], 'grh.view');
$router->post('/grh/structure/departements', [G::class, 'departement'], 'grh.manage');
$router->post('/grh/structure/postes', [G::class, 'poste'], 'grh.manage');
$router->get('/grh/conges', [G::class, 'conges'], 'grh.view');
$router->post('/grh/conges', [G::class, 'congeRequest'], 'grh.presences');
$router->post('/grh/conges/{id}/decision', [G::class, 'congeDecide'], 'grh.conges');
$router->post('/grh/conges/{id}/annuler', [G::class, 'congeCancel'], 'grh.conges');
$router->get('/grh/presences', [G::class, 'presences'], 'grh.view');
$router->post('/grh/presences', [G::class, 'presencesSave'], 'grh.presences');
$router->get('/grh/paie', [G::class, 'paies'], 'grh.paie');
$router->post('/grh/paie', [G::class, 'paieGenerate'], 'grh.paie');
$router->get('/grh/paie/{id}', [G::class, 'paie'], 'grh.paie');
$router->post('/grh/paie/{id}/valider', [G::class, 'paieValidate'], 'grh.paie');
$router->post('/grh/paie/{id}/payer', [G::class, 'paiePay'], 'grh.paie');
$router->post('/grh/bulletins/{id}', [G::class, 'bulletinUpdate'], 'grh.paie');
$router->get('/grh/bulletins/{id}', [G::class, 'bulletin'], 'grh.paie');
$router->get('/grh/avances', [G::class, 'avances'], 'grh.paie');
$router->post('/grh/avances', [G::class, 'avanceGrant'], 'grh.paie');
$router->post('/grh/avances/{id}/annuler', [G::class, 'avanceCancel'], 'grh.paie');
