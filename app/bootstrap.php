<?php
/**
 * POS Marwa — amorçage commun (web + CLI).
 * Charge l'autoloader, l'environnement (.env), la configuration,
 * le fuseau horaire et la gestion des erreurs.
 */
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH . '/app');
define('STORAGE_PATH', BASE_PATH . '/storage');
define('PUBLIC_PATH', BASE_PATH . '/public');

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = APP_PATH . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require APP_PATH . '/helpers.php';
App\Core\Addons::registerAutoload(); // modules du dossier addons/

App\Core\Env::load(BASE_PATH . '/.env');
App\Core\Config::load(BASE_PATH . '/config');

date_default_timezone_set((string) config('app.timezone', 'Africa/Niamey'));
mb_internal_encoding('UTF-8');

App\Core\ErrorHandler::register((bool) config('app.debug', false));
