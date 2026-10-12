<?php
declare(strict_types=1);
require __DIR__ . '/_api.php';

use Verifia\Auth;
use Verifia\Database;
use Verifia\Http;
use Verifia\Stats\StatsService;

Auth::require('viewer', true);
session_write_close();
$out = ['overview' => StatsService::overview()];
if (!empty($_GET['timeline'])) {
    $out['timeline'] = StatsService::timeline((int) ($_GET['hours'] ?? 24));
}
if (!empty($_GET['events'])) {
    $out['events'] = Database::all(
        'SELECT e.id, e.level, e.component, e.session_id, s.name AS session_name, e.message, e.created_at
           FROM system_events e LEFT JOIN monitoring_sessions s ON s.id = e.session_id ORDER BY e.id DESC LIMIT 30'
    );
}
Http::json($out);
