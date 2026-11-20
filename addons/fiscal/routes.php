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

// MARWA SECEF CONNECT (Niger, e-SECeF) : Paramètres → Certification fiscale
use Addons\Fiscal\Secef\SecefController as S;

$router->get('/fiscal/secef', [S::class, 'index'], 'fiscal.configure');
$router->post('/fiscal/secef', [S::class, 'save'], 'fiscal.configure');
$router->post('/fiscal/secef/autorisation', [S::class, 'authorize'], 'fiscal.secrets');
$router->post('/fiscal/secef/acces', [S::class, 'saveAccess'], 'fiscal.secrets');
$router->post('/fiscal/secef/jeton', [S::class, 'token'], 'fiscal.secrets');
$router->post('/fiscal/secef/jeton/masquer', [S::class, 'hideToken'], 'fiscal.secrets');
$router->post('/fiscal/secef/tester', [S::class, 'test'], 'fiscal.configure');
$router->post('/fiscal/secef/deconnecter', [S::class, 'logout'], 'fiscal.configure');
$router->get('/fiscal/secef/articles', [S::class, 'articles'], 'fiscal.configure');
$router->post('/fiscal/secef/articles', [S::class, 'saveArticle'], 'fiscal.configure');
$router->post('/fiscal/secef/articles/releve', [S::class, 'syncPortal'], 'fiscal.configure');
$router->post('/fiscal/secef/articles/{id}/programmer', [S::class, 'programArticle'], 'fiscal.configure');
$router->post('/fiscal/secef/categories', [S::class, 'saveCategories'], 'fiscal.configure');
// Caisse : suivi de la certification d'une vente (droit de vendre)
$router->post('/fiscal/ventes/{id}/certifier', [S::class, 'certifySale'], 'pos.sell');
$router->get('/fiscal/ventes/{id}/etat', [S::class, 'saleStatus'], 'pos.sell');
