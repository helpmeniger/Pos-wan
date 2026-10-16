<?php
declare(strict_types=1);

/**
 * Amorçage commun (web + CLI) : autoload, .env, fuseau horaire, gestion des erreurs.
 */

define('BASE_PATH', dirname(__DIR__));
define('STORAGE_PATH', BASE_PATH . '/storage');

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $file = BASE_PATH . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

if (is_file(BASE_PATH . '/vendor/autoload.php')) {
    require BASE_PATH . '/vendor/autoload.php';
}

require BASE_PATH . '/app/helpers.php';

App\Core\Env::load(BASE_PATH . '/.env');

date_default_timezone_set((string) App\Core\Env::get('APP_TIMEZONE', 'Africa/Niamey'));
mb_internal_encoding('UTF-8');

$debug = App\Core\Env::bool('APP_DEBUG', false);
error_reporting(E_ALL);
ini_set('display_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', STORAGE_PATH . '/logs/php-error.log');

set_exception_handler(static function (Throwable $e) use ($debug): void {
    App\Core\Logger::error('Exception non gérée : ' . $e->getMessage(), [
        'file' => $e->getFile(), 'line' => $e->getLine(),
    ]);
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, '[ERREUR] ' . $e->getMessage() . PHP_EOL . ($debug ? $e->getTraceAsString() . PHP_EOL : ''));
        exit(1);
    }
    if (!headers_sent()) {
        http_response_code(500);
    }
    $wantsJson = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
        || str_contains($_SERVER['REQUEST_URI'] ?? '', '/api/');
    if ($wantsJson) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $debug ? $e->getMessage() : 'Erreur interne du serveur.']);
        return;
    }
    $message = $debug ? $e->getMessage() . "\n" . $e->getTraceAsString() : null;
    require BASE_PATH . '/app/Views/errors/500.php';
});
