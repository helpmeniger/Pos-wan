<?php
/**
 * Mode SaaS — routes du domaine principal : site public et console de l'exploitant.
 * Toutes déclarées « publiques » pour le routeur (pas de compte client ici) : la console
 * vérifie elle-même la connexion de l'exploitant (App\Saas\ConsoleAuth). CSRF sur tout POST.
 */
declare(strict_types=1);

use App\Controllers\Saas\ConsoleController as K;
use App\Controllers\Saas\PublicController as P;

/** @var App\Core\Router $router */
$router->get('/', [P::class, 'home'], null, true);
$router->get('/inscription', [P::class, 'signupForm'], null, true);
$router->post('/inscription', [P::class, 'signup'], null, true);
$router->get('/trouver', [P::class, 'find'], null, true);
$router->post('/trouver', [P::class, 'findPost'], null, true);
$router->get('/conditions', [P::class, 'terms'], null, true);

$router->get('/console/login', [K::class, 'loginForm'], null, true);
$router->post('/console/login', [K::class, 'login'], null, true);
$router->post('/console/logout', [K::class, 'logout'], null, true);
$router->get('/console', [K::class, 'dashboard'], null, true);
$router->get('/console/clients', [K::class, 'tenants'], null, true);
$router->get('/console/clients/{id}', [K::class, 'tenant'], null, true);
$router->post('/console/clients/{id}/paiement', [K::class, 'recordPayment'], null, true);
$router->post('/console/clients/{id}/prolonger', [K::class, 'extend'], null, true);
$router->post('/console/clients/{id}/suspendre', [K::class, 'suspend'], null, true);
$router->post('/console/clients/{id}/reactiver', [K::class, 'reactivate'], null, true);
$router->post('/console/clients/{id}/offre', [K::class, 'changePlan'], null, true);
$router->post('/console/clients/{id}/note', [K::class, 'note'], null, true);
$router->post('/console/clients/{id}/attribuer', [K::class, 'allocate'], null, true);
$router->get('/console/paiements', [K::class, 'payments'], null, true);
$router->post('/console/paiements/{id}/valider', [K::class, 'validatePayment'], null, true);
$router->post('/console/paiements/{id}/refuser', [K::class, 'rejectPayment'], null, true);
$router->get('/console/factures/{id}', [K::class, 'invoice'], null, true);
$router->get('/console/offres', [K::class, 'plans'], null, true);
$router->post('/console/offres', [K::class, 'planSave'], null, true);
$router->post('/console/offres/{id}', [K::class, 'planSave'], null, true);
$router->get('/console/bases', [K::class, 'pool'], null, true);
$router->post('/console/bases', [K::class, 'poolAdd'], null, true);
$router->get('/console/parametres', [K::class, 'settings'], null, true);
$router->post('/console/parametres', [K::class, 'settingsSave'], null, true);
$router->get('/console/journal', [K::class, 'audit'], null, true);
