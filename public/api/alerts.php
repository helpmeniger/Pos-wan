<?php
declare(strict_types=1);
require __DIR__ . '/_api.php';

use Verifia\Alerts\AlertService;
use Verifia\Auth;
use Verifia\Http;

$user = Auth::require('viewer', true);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $f = array_intersect_key($_GET, array_flip(['priority', 'status', 'session_id', 'site', 'category', 'date_from', 'date_to', 'q', 'since_id', 'include_ignored', 'assigned_to']));
    $res = AlertService::search($f, (int) ($_GET['limit'] ?? 100), (int) ($_GET['offset'] ?? 0));
    $res['unread'] = AlertService::unreadCount();
    Http::json($res);
}

api_post_only();
Auth::require('analyst', true);
$in = Http::input();
$id = (int) ($in['id'] ?? 0);
switch ((string) ($in['op'] ?? '')) {
    case 'status':
        $ok = AlertService::setStatus($id, (string) ($in['status'] ?? ''), $user['id'], isset($in['comment']) ? mb_substr((string) $in['comment'], 0, 2000) : null);
        Http::json(['ok' => $ok], $ok ? 200 : 422);
    case 'assign':
        $ok = AlertService::assign($id, empty($in['user_id']) ? null : (int) $in['user_id'], $user['id']);
        Http::json(['ok' => $ok], $ok ? 200 : 422);
    case 'mark_all_read':
        Http::json(['ok' => true, 'count' => AlertService::markAllRead($user['id'])]);
    default:
        Http::json(['error' => 'Opération inconnue'], 400);
}
