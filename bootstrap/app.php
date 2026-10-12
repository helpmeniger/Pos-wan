<?php
declare(strict_types=1);

/**
 * Amorçage de l'application MARWA : autoload, environnement, configuration,
 * fuseau horaire et gestion des erreurs. Utilisé par le web, la CLI et les tests.
 */

if (!defined('MARWA_ROOT')) {
    define('MARWA_ROOT', dirname(__DIR__));
}

if (PHP_VERSION_ID < 80100) {
    http_response_code(500);
    exit('MARWA nécessite PHP 8.1 ou supérieur (8.2 recommandé).');
}

// Autoloader PSR-4 interne : aucune dépendance Composer n'est obligatoire.
spl_autoload_register(static function (string $class): void {
    $map = ['App\\' => MARWA_ROOT . '/app/', 'Tests\\' => MARWA_ROOT . '/tests/'];
    foreach ($map as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
            return;
        }
    }
});

if (is_file(MARWA_ROOT . '/vendor/autoload.php')) {
    require MARWA_ROOT . '/vendor/autoload.php';
}

require_once MARWA_ROOT . '/app/Core/helpers.php';

App\Core\Env::load(getenv('MARWA_ENV_FILE') ?: MARWA_ROOT . '/.env');
App\Core\Config::load(MARWA_ROOT . '/config');

date_default_timezone_set((string) App\Core\Config::get('app.timezone', 'Africa/Niamey'));
mb_internal_encoding('UTF-8');

error_reporting(E_ALL);
ini_set('display_errors', App\Core\Config::get('app.debug') ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', MARWA_ROOT . '/storage/logs/php-errors.log');

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});
