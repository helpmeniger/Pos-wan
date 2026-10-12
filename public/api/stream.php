<?php
declare(strict_types=1);
/**
 * Flux temps réel (Server-Sent Events) du centre d'alertes.
 * Sous Apache/WampServer chaque connexion occupe un processus : le flux se termine
 * après ~25 s et le navigateur se reconnecte automatiquement (Last-Event-ID).
 * Le client bascule sur une interrogation périodique si SSE est indisponible.
 */
require __DIR__ . '/_api.php';

use Verifia\Alerts\AlertService;
use Verifia\Auth;
use Verifia\Database;

Auth::require('viewer', true);
session_write_close(); // ne pas bloquer les autres requêtes de l'utilisateur

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-store');
header('X-Accel-Buffering: no');
@ini_set('zlib.output_compression', '0');
@ini_set('output_buffering', '0');
while (ob_get_level() > 0) {
    ob_end_flush();
}
set_time_limit(40);

$lastId = (int) ($_SERVER['HTTP_LAST_EVENT_ID'] ?? $_GET['since_id'] ?? 0);
if ($lastId === 0) {
    $lastId = (int) Database::value('SELECT COALESCE(MAX(id), 0) FROM alerts');
}
echo "retry: 2000\n\n";
$end = time() + 25;
$lastUnread = -1;
while (time() < $end && !connection_aborted()) {
    $res = AlertService::search(['since_id' => $lastId, 'include_ignored' => 1], 50);
    foreach (array_reverse($res['items']) as $a) {
        $lastId = max($lastId, (int) $a['id']);
        echo 'id: ' . $lastId . "\nevent: alert\ndata: " . json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
    }
    $unread = AlertService::unreadCount();
    if ($unread !== $lastUnread) {
        echo "event: unread\ndata: " . json_encode(['unread' => $unread]) . "\n\n";
        $lastUnread = $unread;
    }
    echo ": ping\n\n";
    flush();
    sleep(1);
}
