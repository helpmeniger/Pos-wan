<?php

declare(strict_types=1);

/**
 * JOB NIGER — point d'entrée unique. Seul le dossier public/ doit être exposé par le serveur web.
 */

// Serveur PHP intégré (php -S) : servir directement les fichiers statiques existants
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if ($file !== __DIR__ . '/' && is_file($file) && !str_ends_with($file, '.php')) {
        return false;
    }
}

$container = require dirname(__DIR__) . '/bootstrap/app.php';

$response = $container->get(App\Core\Application::class)->handle(App\Core\Request::fromGlobals());
$response->send();
