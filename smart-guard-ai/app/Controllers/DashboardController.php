<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Services\AiClient;
use App\Services\MaintenanceService;
use App\Services\NotificationService;
use App\Services\ReportService;

final class DashboardController extends Controller
{
    public function home(Request $r): void
    {
        Response::redirect(Auth::check() ? 'dashboard' : 'login');
    }

    public function index(Request $r): void
    {
        $this->view('dashboard/index', ['title' => 'Tableau de bord', 'scripts' => ['vendor/chartjs/chart.umd.js', 'js/dashboard.js']]);
    }

    public function data(Request $r): void
    {
        $s = $this->storeId();
        $today = date('Y-m-d');
        $sum = ReportService::summary($s, $today, $today);
        $cams = Database::fetch("SELECT SUM(status='en_ligne') online, SUM(status<>'en_ligne') offline, COUNT(*) total FROM cameras WHERE store_id = :s AND is_active = 1", ['s' => $s]);
        $topDetected = Database::fetchAll("SELECT p.name, COUNT(*) n FROM detection_results d JOIN products p ON p.id = d.product_id
            WHERE p.store_id = :s AND d.is_test = 0 AND d.detected_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY p.id ORDER BY n DESC LIMIT 8", ['s' => $s]);
        $topExits = Database::fetchAll("SELECT COALESCE(p.name,'Non identifié') name, COUNT(*) n FROM exit_events e LEFT JOIN products p ON p.id = e.product_id
            WHERE e.store_id = :s AND e.direction = 'sortie' AND e.occurred_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY e.product_id ORDER BY n DESC LIMIT 8", ['s' => $s]);
        $byCamera = Database::fetchAll("SELECT c.name, COUNT(e.id) n FROM cameras c LEFT JOIN exit_events e ON e.camera_id = c.id AND e.occurred_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            WHERE c.store_id = :s AND c.is_active = 1 GROUP BY c.id ORDER BY c.name", ['s' => $s]);
        $byLevel = Database::fetchAll("SELECT level, COUNT(*) n FROM alerts WHERE store_id = :s AND occurred_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY level", ['s' => $s]);
        $reviews = Database::fetchAll("SELECT status, COUNT(*) n FROM alerts WHERE store_id = :s AND level <> 'information' AND occurred_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY status", ['s' => $s]);
        $variance = Database::fetchAll("SELECT DATE(taken_at) d, SUM(ABS(variance)) v FROM stock_snapshots WHERE store_id = :s AND snapshot_type = 'inventaire_physique'
            AND taken_at >= DATE_SUB(NOW(), INTERVAL 90 DAY) GROUP BY DATE(taken_at) ORDER BY d", ['s' => $s]);
        $model = Database::fetch("SELECT v.version_number, v.metrics_json FROM ai_models m JOIN ai_model_versions v ON v.id = m.active_version_id WHERE m.store_id = :s", ['s' => $s]);
        $metrics = $model ? (json_decode((string) $model['metrics_json'], true)['metrics'] ?? null) : null;

        $health = AiClient::health();
        $perf = $health['ok'] ? AiClient::request('GET', '/system/performance', null, ['timeout' => 5]) : ['ok' => false];
        $disk = MaintenanceService::diskStatus();
        Response::json([
            'ok' => true,
            'kpi' => [
                'cameras_online' => (int) ($cams['online'] ?? 0), 'cameras_offline' => (int) ($cams['offline'] ?? 0),
                'monitored' => ReportService::duration((int) $sum['monitored_seconds']),
                'identified' => $sum['products_identified'], 'exits' => $sum['exits'], 'entries' => $sum['entries'],
                'to_verify' => $sum['open_alerts'], 'closed' => $sum['verified'],
                'validated_accuracy' => $metrics['accuracy'] ?? null, 'validated_tests' => $metrics['test_images'] ?? null,
                'model_version' => $model['version_number'] ?? null,
            ],
            'system' => [
                'ai_ok' => $health['ok'], 'ai_error' => $health['error'], 'ai' => $health['data'] ?? null,
                'perf' => $perf['ok'] ? $perf['data'] : null, 'disk_free' => human_bytes($disk['free']), 'disk_low' => $disk['low'],
            ],
            'charts' => [
                'hourly' => ReportService::hourly($s, $today),
                'daily' => ReportService::daily($s, date('Y-m-d', strtotime('-13 days')), $today),
                'top_detected' => $topDetected, 'top_exits' => $topExits, 'by_camera' => $byCamera,
                'by_level' => $byLevel, 'reviews' => array_map(static fn ($x) => ['label' => label('alert_status', $x['status']), 'n' => (int) $x['n']], $reviews),
                'variance' => $variance,
            ],
        ]);
    }

    public function notifications(Request $r): void
    {
        $uid = (int) Auth::id();
        $s = $this->storeId();
        Response::json(['ok' => true, 'count' => NotificationService::countUnread($uid, $s),
            'items' => array_map(static fn ($n) => ['id' => (int) $n['id'], 'title' => $n['title'], 'severity' => $n['severity'], 'link' => $n['link'],
                'created_at' => dt($n['created_at'], 'd/m H:i')], NotificationService::unreadFor($uid, $s))]);
    }

    public function markRead(Request $r): void
    {
        $id = $r->input('id');
        NotificationService::markRead((int) Auth::id(), $this->storeId(), is_numeric($id) ? (int) $id : null);
        Response::json(['ok' => true]);
    }
}
