<?php
declare(strict_types=1);
require __DIR__ . '/_api.php';

use Verifia\Auth;
use Verifia\Http;
use Verifia\Sessions\SessionService;

$user = Auth::require('viewer', true);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['id'])) {
        $s = SessionService::get((int) $_GET['id']);
        if (!$s) {
            Http::json(['error' => 'Session introuvable'], 404);
        }
        Http::json(['session' => $s, 'errors' => SessionService::errors((int) $_GET['id'])]);
    }
    Http::json([
        'sessions' => SessionService::list(!empty($_GET['closed'])),
        'capacity' => SessionService::capacity(),
        'emergency_stop' => \Verifia\Settings::get('emergency_stop') === '1',
    ]);
}

api_post_only();
Auth::require('analyst', true);
$in = Http::input();
$op = (string) ($in['op'] ?? '');

switch ($op) {
    case 'create':
        $id = SessionService::create($in, $user['id']);
        Http::json(['ok' => true, 'id' => $id]);
    case 'update':
        SessionService::update((int) ($in['id'] ?? 0), $in, $user['id']);
        Http::json(['ok' => true]);
    case 'action':
        [$ok, $msg] = SessionService::action((int) ($in['id'] ?? 0), (string) ($in['action'] ?? ''), $user['id']);
        Http::json(['ok' => $ok, 'message' => $msg], $ok ? 200 : 409);
    case 'emergency_stop':
        $n = SessionService::emergencyStop($user['id']);
        Http::json(['ok' => true, 'stopped' => $n]);
    case 'emergency_release':
        SessionService::releaseEmergencyStop($user['id']);
        Http::json(['ok' => true]);
    default:
        Http::json(['error' => 'Opération inconnue'], 400);
}
