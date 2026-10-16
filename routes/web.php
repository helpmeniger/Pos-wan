<?php

declare(strict_types=1);

use App\Controllers\Admin;
use App\Controllers\Web;
use App\Core\Router;

/*
 * Routes web. Middleware globaux (bootstrap/app.php) : en-têtes de sécurité, session,
 * CSRF sur toute requête non sûre, obligation de changer un mot de passe temporaire.
 */
return function (Router $r): void {
    // ---- Pages publiques
    $r->get('/', [Web\HomeController::class, 'index'], 'home');
    $r->get('/categories', [Web\HomeController::class, 'categories'], 'categories');
    $r->get('/categories/{slug:[a-z0-9-]+}', [Web\HomeController::class, 'category'], 'categories.show');
    $r->get('/a-propos', [Web\PageController::class, 'about'], 'about');
    $r->get('/aide', [Web\PageController::class, 'help'], 'help');
    $r->get('/conditions-utilisation', [Web\PageController::class, 'terms'], 'terms');
    $r->get('/confidentialite', [Web\PageController::class, 'privacy'], 'privacy');
    $r->get('/contact', [Web\PageController::class, 'contact'], 'contact');
    $r->post('/contact', [Web\PageController::class, 'sendContact'])->middleware('throttle:contact');

    // ---- Invités uniquement
    $r->group('', ['guest'], function (Router $r) {
        $r->get('/connexion', [Web\AuthController::class, 'showLogin'], 'login');
        $r->post('/connexion', [Web\AuthController::class, 'login']);
        $r->get('/inscription', [Web\AuthController::class, 'showRegister'], 'register');
        $r->post('/inscription', [Web\AuthController::class, 'register'])->middleware('throttle:register_ip');
        $r->get('/mot-de-passe/oublie', [Web\PasswordController::class, 'showForgot'], 'password.forgot');
        $r->post('/mot-de-passe/oublie', [Web\PasswordController::class, 'sendForgot'])->middleware('throttle:password_reset');
        $r->get('/mot-de-passe/reinitialiser/{token:[A-Za-z0-9_-]+}', [Web\PasswordController::class, 'showReset'], 'password.reset');
        $r->post('/mot-de-passe/reinitialiser/{token:[A-Za-z0-9_-]+}', [Web\PasswordController::class, 'reset']);
    });

    // ---- Utilisateurs connectés
    $r->group('', ['auth'], function (Router $r) {
        $r->post('/deconnexion', [Web\AuthController::class, 'logout'], 'logout');
        $r->get('/tableau-de-bord', [Web\DashboardController::class, 'home'], 'dashboard');
        $r->get('/mot-de-passe/changer', [Web\PasswordController::class, 'showChange'], 'password.change');
        $r->post('/mot-de-passe/changer', [Web\PasswordController::class, 'change']);
        $r->get('/verifier-telephone', [Web\PhoneVerificationController::class, 'show'], 'phone.verify');
        $r->post('/verifier-telephone/envoyer', [Web\PhoneVerificationController::class, 'send']);
        $r->post('/verifier-telephone', [Web\PhoneVerificationController::class, 'verify']);
        $r->get('/notifications', [Web\NotificationController::class, 'index'], 'notifications');
        $r->post('/notifications/tout-lire', [Web\NotificationController::class, 'readAll']);
        $r->post('/notifications/{id:\d+}/lue', [Web\NotificationController::class, 'read']);

        $r->get('/espace/prestataire', [Web\DashboardController::class, 'worker'], 'worker.dashboard')->middleware('role:WORKER');
        $r->get('/espace/demandeur', [Web\DashboardController::class, 'client'], 'client.dashboard')->middleware('role:CLIENT');
    });

    // ---- Administration (personnel uniquement, permission explicite)
    $r->group('/admin', ['auth', 'permission:admin.dashboard.view'], function (Router $r) {
        $r->get('/', [Admin\DashboardController::class, 'index'], 'admin.dashboard');
    });
};
