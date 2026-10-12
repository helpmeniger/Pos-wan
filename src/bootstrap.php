<?php
declare(strict_types=1);

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($autoload)) {
    require $autoload;
} else {
    // Chargeur de secours si Composer n'a pas encore été exécuté (Dompdf sera alors indisponible).
    spl_autoload_register(function (string $class): void {
        if (str_starts_with($class, 'Verifia\\')) {
            $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, 8)) . '.php';
            if (is_file($file)) {
                require $file;
            }
        }
    });
}
require_once __DIR__ . '/helpers.php';

\Verifia\Config::load();
date_default_timezone_set('UTC');
