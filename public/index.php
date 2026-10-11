<?php
/**
 * POS Wan — contrôleur frontal. Seul fichier PHP exposé au navigateur.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Request;
use App\Core\Router;
use App\Core\Session;
use App\Services\InstallService;

// En-têtes de sécurité HTTP
header('X-Frame-Options: SAMEORIGIN'); // SAMEORIGIN : impression du ticket via un cadre interne
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self'; connect-src 'self'; frame-ancestors 'self'; form-action 'self'; base-uri 'self'; object-src 'none'");
header_remove('X-Powered-By');

Session::start();

$path = Request::path();
if (!InstallService::isInstalled() && !str_starts_with($path, '/install')) {
    redirect('/install');
}

$router = new Router();
require BASE_PATH . '/routes/web.php';
App\Core\Addons::registerRoutes($router); // routes des modules actifs (addons/)
$router->dispatch(Request::method(), $path);
