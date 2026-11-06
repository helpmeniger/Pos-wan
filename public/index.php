<?php
/**
 * POS Marwa — contrôleur frontal. Seul fichier PHP exposé au navigateur.
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
header('Permissions-Policy: geolocation=(), microphone=(), camera=(self)');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; media-src 'self' blob:; font-src 'self'; connect-src 'self'; frame-ancestors 'self'; form-action 'self'; base-uri 'self'; object-src 'none'");
header_remove('X-Powered-By');

$path = Request::path();
// Photos et vidéos téléversées : servies par l'application (indépendant de la configuration Apache)
if (str_starts_with($path, '/media/') && in_array(Request::method(), ['GET', 'HEAD'], true)) {
    App\Core\Media::serve(substr($path, 7));
}

Session::start();

// Mode SaaS : site public et console de l'exploitant (domaine principal)…
if (App\Saas\Saas::isCentral()) {
    App\Saas\Gate::central($path);
}
// … ou espace d'un client : suspension, création de l'espace à la première visite, pages réservées
if (App\Saas\Saas::isTenant()) {
    App\Saas\Gate::tenant($path);
}
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
        . '<h1>POS Marwa est en cours de mise à jour</h1><p>La page se rechargera automatiquement dans quelques instants.</p></body>';
    exit;
}
// Fichiers mis à jour (nouvelle version copiée) : la base est mise à jour automatiquement
InstallService::autoMigrate();
// … puis les modules actifs dont la version a changé (nouvelles tables avant leurs nouveaux écrans)
if (InstallService::isInstalled()) {
    App\Core\Addons::autoUpgrade();
    // … puis contrôle de la structure : tables et colonnes manquantes recréées (une fois par mise à jour)
    App\Services\SchemaSync::auto();
}
// Licence : sans clé valide après la période d'essai, lecture seule (aucune donnée bloquée)
if (App\Services\LicenceService::blocks(Request::method(), $path)) {
    $msg = 'Licence non activée : POS Marwa est en lecture seule. Activez la licence (Configuration → Licence et activation) ou contactez l\'auteur : ' . implode(' / ', (array) config('licence.author.phones')) . '.';
    if (Request::wantsJson()) {
        json_response(['ok' => false, 'error' => $msg], 403);
    }
    flash('danger', $msg);
    redirect('/licence');
}

// Écran d'affichage (téléviseur, écran connecté) : cantonné aux pages de son écran
if (InstallService::isInstalled() && ($to = App\Services\Screens::guard($path)) !== null) {
    if (Request::wantsJson()) {
        json_response(['ok' => false, 'error' => 'Cet écran n\'a accès qu\'à son affichage.'], 403);
    }
    redirect($to);
}

$router = new Router();
require BASE_PATH . '/routes/web.php';
App\Core\Addons::registerRoutes($router); // routes des modules actifs (addons/)
$router->dispatch(Request::method(), $path);
