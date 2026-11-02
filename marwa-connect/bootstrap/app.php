<?php
/**
 * MARWA CONNECT — Amorçage de l'application.
 * Charge l'autoloader, l'environnement, la configuration et les gestionnaires d'erreurs.
 */
declare(strict_types=1);

define('MARWA_START', microtime(true));
define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH . '/app');
define('STORAGE_PATH', BASE_PATH . '/storage');
define('VIEW_PATH', BASE_PATH . '/resources/views');

// Autoloader PSR-4 minimal (aucune dépendance Composer requise sous WampServer).
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

// Autoloader Composer facultatif (bibliothèques tierces futures).
if (is_file(BASE_PATH . '/vendor/autoload.php')) {
    require BASE_PATH . '/vendor/autoload.php';
}

require APP_PATH . '/Helpers/functions.php';

App\Core\Env::load(BASE_PATH . '/.env');
App\Core\Config::load(BASE_PATH . '/config');

date_default_timezone_set((string) config('app.timezone', 'Africa/Niamey'));
mb_internal_encoding('UTF-8');

App\Core\ErrorHandler::register();

return new App\Core\App();
