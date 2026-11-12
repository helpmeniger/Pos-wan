<?php
/** Routes du module Marwa Fiscal Connect. @var App\Core\Router $router */
use Addons\Fiscal\FiscalController as F;

$router->get('/fiscal', [F::class, 'dashboard'], 'fiscal.view');
$router->get('/fiscal/connecteurs', [F::class, 'connectors'], 'fiscal.view');
$router->get('/fiscal/connecteurs/nouveau', [F::class, 'connectorForm'], 'fiscal.configure');
$router->post('/fiscal/connecteurs', [F::class, 'connectorSave'], 'fiscal.configure');
$router->get('/fiscal/connecteurs/{id}', [F::class, 'connectorForm'], 'fiscal.configure');
$router->post('/fiscal/connecteurs/{id}', [F::class, 'connectorSave'], 'fiscal.configure');
$router->post('/fiscal/connecteurs/{id}/tester', [F::class, 'connectorTest'], 'fiscal.configure');
$router->post('/fiscal/connecteurs/{id}/activer', [F::class, 'connectorToggle'], 'fiscal.configure');
$router->post('/fiscal/connecteurs/{id}/defaut', [F::class, 'connectorDefault'], 'fiscal.configure');
$router->post('/fiscal/connecteurs/{id}/supprimer', [F::class, 'connectorDelete'], 'fiscal.configure');
$router->post('/fiscal/connecteurs/{id}/secrets', [F::class, 'secretsSave'], 'fiscal.secrets');
$router->post('/fiscal/connecteurs/{id}/openapi', [F::class, 'openapiImport'], 'fiscal.configure');
$router->post('/fiscal/connecteurs/{id}/webhook', [F::class, 'webhookCreate'], 'fiscal.secrets');
$router->get('/fiscal/certificats', [F::class, 'certificates'], 'fiscal.configure');
$router->post('/fiscal/certificats', [F::class, 'certificateUpload'], 'fiscal.secrets');
$router->post('/fiscal/certificats/{id}/supprimer', [F::class, 'certificateDelete'], 'fiscal.secrets');
$router->get('/fiscal/pays', [F::class, 'countries'], 'fiscal.view');
$router->post('/fiscal/pays', [F::class, 'countryAdd'], 'fiscal.configure');
$router->get('/fiscal/pays/{id}', [F::class, 'countryForm'], 'fiscal.configure');
$router->post('/fiscal/pays/{id}', [F::class, 'countrySave'], 'fiscal.configure');
$router->post('/fiscal/pays/{id}/taux', [F::class, 'taxRateSave'], 'fiscal.configure');
$router->post('/fiscal/taux/{id}/supprimer', [F::class, 'taxRateDelete'], 'fiscal.configure');
$router->get('/fiscal/parametres', [F::class, 'profiles'], 'fiscal.configure');
$router->post('/fiscal/parametres', [F::class, 'profileSave'], 'fiscal.configure');
$router->post('/fiscal/parametres/{id}', [F::class, 'profileSave'], 'fiscal.configure');
$router->post('/fiscal/parametres/{id}/activer', [F::class, 'profileToggle'], 'fiscal.configure');
$router->get('/fiscal/historique', [F::class, 'history'], 'fiscal.view');
$router->get('/fiscal/certifications/{id}', [F::class, 'certification'], 'fiscal.view');
$router->post('/fiscal/certifications/{id}/relancer', [F::class, 'retry'], 'fiscal.queue');
$router->get('/fiscal/attente', [F::class, 'pending'], 'fiscal.view');
$router->post('/fiscal/attente/traiter', [F::class, 'processQueue'], 'fiscal.queue');
$router->get('/fiscal/rapports', [F::class, 'reports'], 'fiscal.reports');
$router->post('/fiscal/rapports/dispositif/{id}', [F::class, 'deviceReport'], 'fiscal.reports');
$router->get('/fiscal/journaux', [F::class, 'logs'], 'fiscal.logs');
$router->get('/fiscal/journaux/{id}', [F::class, 'logDetail'], 'fiscal.logs');
$router->get('/fiscal/documentation', [F::class, 'docs'], 'fiscal.view');
// Notifications d'un service fiscal : authentifiées par jeton + signature HMAC dans le contrôleur
$router->webhook('/fiscal/webhook/{id}', [Addons\Fiscal\WebhookController::class, 'receive']);
