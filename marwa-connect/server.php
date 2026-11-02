<?php
/**
 * Routeur pour le serveur PHP intégré (développement / tests sans Apache) :
 *   php -S localhost:8000 -t public server.php
 * Sous WampServer, Apache et les fichiers .htaccess jouent ce rôle.
 */
$path = urldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
if ($path !== '/' && is_file(__DIR__ . '/public' . $path)) {
    return false;
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
require __DIR__ . '/public/index.php';
