<?php
/** Routes du module Marwa Pay Connect. @var App\Core\Router $router */
use Addons\Pay\PayController as P;
use Addons\Pay\CaisseController as K;

$router->get('/pay', [P::class, 'dashboard'], 'pay.view');
$router->get('/pay/prestataires', [P::class, 'providers'], 'pay.view');
$router->post('/pay/prestataires', [P::class, 'providerSave'], 'pay.configure');
$router->post('/pay/prestataires/{id}', [P::class, 'providerSave'], 'pay.configure');
$router->get('/pay/connecteurs', [P::class, 'connectors'], 'pay.configure');
$router->get('/pay/connecteurs/nouveau', [P::class, 'connectorForm'], 'pay.configure');
$router->post('/pay/connecteurs', [P::class, 'connectorSave'], 'pay.configure');
$router->get('/pay/connecteurs/{id}', [P::class, 'connectorForm'], 'pay.configure');
$router->post('/pay/connecteurs/{id}', [P::class, 'connectorSave'], 'pay.configure');
$router->post('/pay/connecteurs/{id}/tester', [P::class, 'connectorTest'], 'pay.configure');
$router->post('/pay/connecteurs/{id}/activer', [P::class, 'connectorToggle'], 'pay.configure');
$router->post('/pay/connecteurs/{id}/supprimer', [P::class, 'connectorDelete'], 'pay.configure');
$router->post('/pay/connecteurs/{id}/secrets', [P::class, 'secretsSave'], 'pay.secrets');
$router->post('/pay/connecteurs/{id}/certificat', [P::class, 'certificateUpload'], 'pay.secrets');
$router->post('/pay/connecteurs/{id}/webhook', [P::class, 'webhookCreate'], 'pay.secrets');
$router->post('/pay/connecteurs/{id}/openapi', [P::class, 'openapiImport'], 'pay.configure');
$router->get('/pay/moyens', [P::class, 'methods'], 'pay.configure');
$router->post('/pay/moyens', [P::class, 'methodSave'], 'pay.configure');
$router->post('/pay/moyens/{id}', [P::class, 'methodSave'], 'pay.configure');
$router->post('/pay/moyens/{id}/activer', [P::class, 'methodToggle'], 'pay.configure');
$router->get('/pay/transactions', [P::class, 'transactions'], 'pay.view');
$router->get('/pay/transactions/{id}', [P::class, 'transaction'], 'pay.view');
$router->post('/pay/transactions/{id}/verifier', [P::class, 'refresh'], 'pay.view');
$router->post('/pay/transactions/{id}/annuler', [P::class, 'cancel'], 'pay.refund');
$router->post('/pay/transactions/{id}/rembourser', [P::class, 'refund'], 'pay.view');
$router->get('/pay/remboursements', [P::class, 'refunds'], 'pay.view');
$router->post('/pay/remboursements/{id}/verifier', [P::class, 'refundRefresh'], 'pay.view');
$router->get('/pay/rapprochement', [P::class, 'reconciliation'], 'pay.reconcile');
$router->post('/pay/rapprochement', [P::class, 'reconciliationImport'], 'pay.reconcile');
$router->post('/pay/rapprochement/{id}/traiter', [P::class, 'reconciliationResolve'], 'pay.reconcile');
$router->get('/pay/rapports', [P::class, 'reports'], 'pay.reports');
$router->get('/pay/journaux', [P::class, 'logs'], 'pay.logs');
$router->get('/pay/journaux/{id}', [P::class, 'logDetail'], 'pay.logs');
$router->get('/pay/parametres', [P::class, 'settings'], 'pay.configure');
$router->post('/pay/parametres', [P::class, 'settingsSave'], 'pay.configure');
$router->post('/pay/parametres/devise', [P::class, 'currencyAdd'], 'pay.configure');
$router->post('/pay/parametres/taux', [P::class, 'rateAdd'], 'pay.configure');
// Caisse (JSON)
$router->get('/pay/caisse/moyens', [K::class, 'methods'], 'pay.use');
$router->post('/pay/caisse/payer', [K::class, 'initiate'], 'pay.use');
$router->get('/pay/caisse/transactions/{id}', [K::class, 'status'], 'pay.use');
$router->post('/pay/caisse/transactions/{id}/annuler', [K::class, 'cancel'], 'pay.use');
// Notifications des prestataires : authentifiées par jeton + signature dans le contrôleur
$router->webhook('/pay/webhook/{id}', [Addons\Pay\WebhookController::class, 'receive']);
