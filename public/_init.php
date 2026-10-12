<?php
declare(strict_types=1);
// Initialisation commune à toutes les pages et API de l'interface.
require dirname(__DIR__) . '/src/bootstrap.php';

use Verifia\Auth;
use Verifia\Database;
use Verifia\Http;

Http::securityHeaders();
Auth::startSession();

// Première utilisation : aucun utilisateur → page de création de l'administrateur.
$script = basename($_SERVER['SCRIPT_NAME'] ?? '');
if (!in_array($script, ['setup.php', 'monitor.php'], true) && !defined('VERIFIA_API')) {
    try {
        if (!(int) Database::value('SELECT COUNT(*) FROM users')) {
            Http::redirect('setup.php');
        }
    } catch (\PDOException $e) {
        http_response_code(503);
        exit('Base de données indisponible. Vérifiez que WampServer est démarré et lancez scripts\\windows\\install.bat. (' . e($e->getMessage()) . ')');
    }
}
