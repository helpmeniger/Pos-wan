<?php
declare(strict_types=1);

/**
 * SMART GUARD AI — contrôleur frontal. Seul ce dossier (public/) doit être servi par Apache.
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Request;
use App\Core\Router;
use App\Core\Session;

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob:; media-src 'self' blob:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; connect-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'");
header('Permissions-Policy: camera=(self), microphone=(), geolocation=()');

Session::start();
$router = new Router();
require BASE_PATH . '/config/routes.php';
$router->dispatch(new Request());
