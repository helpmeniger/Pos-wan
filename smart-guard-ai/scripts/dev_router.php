<?php
// Routeur pour le serveur PHP intégré (tests/développement) : php -S 127.0.0.1:8080 -t public scripts/dev_router.php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path !== '/' && is_file(__DIR__ . '/../public' . $path)) {
    return false;
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
require __DIR__ . '/../public/index.php';
