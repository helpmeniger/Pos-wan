<?php
declare(strict_types=1);
// API interne du moteur de surveillance (Node/Playwright). Jamais utilisée par le navigateur de l'utilisateur.
define('VERIFIA_API', true);
require dirname(__DIR__, 2) . '/src/bootstrap.php';

use Verifia\EventLog;
use Verifia\Http;
use Verifia\Monitor\MonitorApi;

header('X-Content-Type-Options: nosniff');
if (!MonitorApi::authorize($_SERVER['REMOTE_ADDR'] ?? null, $_SERVER['HTTP_X_VERIFIA_TOKEN'] ?? null)) {
    EventLog::log('warning', 'web', 'Appel refusé à l\'API du moteur', null, ['ip' => $_SERVER['REMOTE_ADDR'] ?? '']);
    Http::json(['error' => 'Non autorisé'], 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Http::json(['error' => 'Méthode non autorisée'], 405);
}
$raw = file_get_contents('php://input', false, null, 0, 40_000_000) ?: '';
$in = json_decode($raw, true);
if (!is_array($in)) {
    Http::json(['error' => 'JSON invalide'], 400);
}
try {
    Http::json(MonitorApi::handle((string) ($_GET['action'] ?? ''), $in));
} catch (\InvalidArgumentException $e) {
    Http::json(['error' => $e->getMessage()], 422);
} catch (\Throwable $e) {
    EventLog::log('error', 'web', 'Erreur API moteur : ' . $e->getMessage());
    Http::json(['error' => 'Erreur interne'], 500);
}
