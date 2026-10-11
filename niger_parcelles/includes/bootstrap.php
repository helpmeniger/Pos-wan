<?php
/**
 * Point d'entrée commun à toutes les pages et API.
 *
 * Avant d'inclure ce fichier, une page peut définir :
 *   NP_API       => true pour une API JSON (erreurs renvoyées en JSON)
 *   NP_INSTALLER => true pour install.php (pas de redirection vers l'installateur)
 *   NP_CLI       => true pour les scripts en ligne de commande (tests, outils)
 */
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

$GLOBALS['np_config'] = require APP_ROOT . '/config/app.php';

date_default_timezone_set($GLOBALS['np_config']['timezone']);
mb_internal_encoding('UTF-8');

// --- Gestion des erreurs : journalisées, jamais affichées en détail ---------
ini_set('log_errors', '1');
ini_set('error_log', $GLOBALS['np_config']['storage_path'] . '/logs/php_errors.log');
ini_set('display_errors', $GLOBALS['np_config']['debug'] ? '1' : '0');
error_reporting(E_ALL);

// --- Chargement automatique des classes de includes/lib et includes/services
spl_autoload_register(static function (string $class): void {
    foreach (['lib', 'services'] as $dir) {
        $file = APP_ROOT . '/includes/' . $dir . '/' . $class . '.php';
        if (is_file($file)) {
            require $file;
            return;
        }
    }
});

require APP_ROOT . '/includes/functions.php';
require APP_ROOT . '/includes/csrf.php';
require APP_ROOT . '/includes/permissions.php';
require APP_ROOT . '/includes/auth.php';

set_exception_handler('np_exception_handler');

if (!defined('NP_CLI')) {
    send_security_headers();
    start_secure_session();

    if (!defined('NP_INSTALLER') && !app_is_installed()) {
        if (defined('NP_API')) {
            json_error('Application non installée.', 503);
        }
        redirect('install.php');
    }
}
