<?php
declare(strict_types=1);
// Initialisation commune des points d'API JSON de l'interface.
define('VERIFIA_API', true);
require dirname(__DIR__) . '/_init.php';

use Verifia\EventLog;
use Verifia\Http;

set_exception_handler(function (\Throwable $e): void {
    if ($e instanceof \InvalidArgumentException) {
        Http::json(['error' => $e->getMessage()], 422);
    }
    EventLog::log('error', 'web', 'Erreur API : ' . $e->getMessage(), null, ['uri' => $_SERVER['REQUEST_URI'] ?? '']);
    Http::json(['error' => 'Erreur interne. Consultez le journal des événements.'], 500);
});

function api_post_only(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        Http::json(['error' => 'Méthode non autorisée'], 405);
    }
    \Verifia\Csrf::verifyRequest(true);
}
