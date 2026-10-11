<?php
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $file = BASE_PATH . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require BASE_PATH . '/app/helpers.php';

App\Core\Config::load();
date_default_timezone_set((string) App\Core\Config::get('app.timezone', 'UTC'));
mb_internal_encoding('UTF-8');

if (App\Core\Config::get('app.env') === 'production') {
    ini_set('display_errors', '0');
}
