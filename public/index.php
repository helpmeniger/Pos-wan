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
// Mise à jour du logiciel en cours (copie des fichiers) : les autres requêtes patientent
$maint = STORAGE_PATH . '/maintenance.lock';
if (is_file($maint) && filemtime($maint) > time() - 900 && !str_starts_with($path, '/update')) {
    http_response_code(503);
    header('Retry-After: 30');
    if (Request::wantsJson()) {
        json_response(['ok' => false, 'error' => 'Mise à jour du logiciel en cours : réessayez dans une minute.'], 503);
    }
    echo '<!doctype html><meta charset="utf-8"><meta http-equiv="refresh" content="20"><title>Mise à jour en cours</title><body style="font-family:sans-serif;text-align:center;padding:60px">'
        . '<h1>POS Wan est en cours de mise à jour</h1><p>La page se rechargera automatiquement dans quelques instants.</p></body>';
    exit;
}
// Fichiers mis à jour (nouvelle version copiée) : la base est mise à jour automatiquement
InstallService::autoMigrate();
// Licence : sans clé valide après la période d'essai, lecture seule (aucune donnée bloquée)
if (App\Services\LicenceService::blocks(Request::method(), $path)) {
    $msg = 'Licence non activée : POS Wan est en lecture seule. Activez la licence (Configuration → Licence et activation) ou contactez l\'auteur : ' . implode(' / ', (array) config('licence.author.phones')) . '.';
    if (Request::wantsJson()) {
        json_response(['ok' => false, 'error' => $msg], 403);
    }
    flash('danger', $msg);
    redirect('/licence');
}

$router = new Router();
require BASE_PATH . '/routes/web.php';
App\Core\Addons::registerRoutes($router); // routes des modules actifs (addons/)
$router->dispatch(Request::method(), $path);
