<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\AuditService;
use App\Services\MikroTik\RouterOSClient;
use App\Services\MikroTik\RouterService;
use App\Services\SyncService;
use Throwable;

/**
 * Utilisateurs Wi-Fi : sessions en direct (lues sur les routeurs), historique, actions.
 */
final class HotspotController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('hotspot.view');
        $tab = $request->str('tab', 'live');
        $routerId = $request->int('router_id', active_router_id());
        $q = $request->str('q');
        $routers = Database::fetchAll("SELECT * FROM routers WHERE deleted_at IS NULL AND status = 'active'" . ($routerId ? ' AND id = ' . $routerId : '') . ' ORDER BY name');
        $allRouters = Database::fetchAll("SELECT id, name FROM routers WHERE deleted_at IS NULL AND status = 'active' ORDER BY name");
        $data = compact('tab', 'routerId', 'q', 'allRouters');

        if ($tab === 'live') {
            $sessions = [];
            $errors = [];
            foreach ($routers as $r) {
                try {
                    $rows = RouterService::activeSessions($r);
                    SyncService::recordSessions($r, $rows);
                    foreach ($rows as $s) {
                        $s['router_id'] = $r['id'];
                        $s['router_name'] = $r['name'];
                        $sessions[] = $s;
                    }
                } catch (Throwable $e) {
                    RouterService::close((int) $r['id']);
                    $errors[] = $r['name'] . ' : ' . $e->getMessage();
                }
            }
            $perUser = array_count_values(array_map(static fn ($s) => $s['user'] ?? '', $sessions));
            if ($q !== '') {
                $sessions = array_values(array_filter($sessions, static fn ($s) => stripos(($s['user'] ?? '') . ' ' . ($s['address'] ?? '') . ' ' . ($s['mac-address'] ?? ''), $q) !== false));
            }
            $names = array_unique(array_map(static fn ($s) => $s['user'] ?? '', $sessions));
            $tickets = [];
            if ($names) {
                $ph = implode(',', array_fill(0, count($names), '?'));
                foreach (Database::fetchAll("SELECT t.id, t.username, t.status, t.expires_at, o.name AS offer_name FROM tickets t JOIN offers o ON o.id = t.offer_id WHERE t.username IN ({$ph})", array_values($names)) as $t) {
                    $tickets[$t['username']] = $t;
                }
            }
            $data += compact('sessions', 'errors', 'perUser', 'tickets');
        } elseif ($tab === 'history') {
            $page = max(1, $request->int('page', 1));
            $where = '1=1';
            $p = [];
            if ($routerId) {
                $where .= ' AND ws.router_id = :r';
                $p['r'] = $routerId;
            }
            if ($q !== '') {
                $where .= ' AND (ws.username LIKE :q OR ws.mac_address LIKE :q2 OR ws.ip_address LIKE :q3)';
                $p += ['q' => "%{$q}%", 'q2' => "%{$q}%", 'q3' => "%{$q}%"];
            }
            $total = (int) Database::value("SELECT COUNT(*) FROM wifi_sessions ws WHERE {$where}", $p);
            $history = Database::fetchAll("SELECT ws.*, r.name AS router_name FROM wifi_sessions ws JOIN routers r ON r.id = ws.router_id WHERE {$where} ORDER BY ws.login_at DESC LIMIT 50 OFFSET " . (($page - 1) * 50), $p);
            $data += ['history' => $history, 'page' => $page, 'pages' => (int) ceil($total / 50)];
        } else {
            $top = Database::fetchAll(
                "SELECT ws.username, COUNT(*) sessions, SUM(ws.bytes_in) bin, SUM(ws.bytes_out) bout, SUM(ws.uptime_seconds) up, MAX(ws.login_at) last, COUNT(DISTINCT ws.mac_address) devices
                   FROM wifi_sessions ws WHERE ws.login_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)" . ($routerId ? ' AND ws.router_id = ' . $routerId : '') . '
                  GROUP BY ws.username ORDER BY (SUM(ws.bytes_in) + SUM(ws.bytes_out)) DESC LIMIT 50');
            $data += ['top' => $top];
        }
        return $this->view('hotspot/index', $data);
    }

    public function disconnect(Request $request): Response
    {
        $this->authorize('hotspot.manage');
        $router = RouterService::find($request->int('router_id'));
        $this->notFoundUnless($router);
        try {
            RouterService::disconnectActive($router, $request->str('active_id'));
            AuditService::log('hotspot.disconnect', 'router', (int) $router['id'], 'Déconnexion de ' . $request->str('user') . ' sur ' . $router['name']);
            Session::flash('success', 'Utilisateur ' . $request->str('user') . ' déconnecté.');
        } catch (Throwable $e) {
            Session::flash('danger', $e->getMessage());
        }
        return $this->back($request, '/hotspot');
    }
}
