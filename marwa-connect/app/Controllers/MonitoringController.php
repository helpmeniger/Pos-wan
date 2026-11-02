<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Services\MikroTik\RouterService;
use Throwable;

/**
 * Surveillance réseau : vue d'ensemble, mesures en direct et historiques.
 */
final class MonitoringController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('monitoring.view');
        $routers = Database::fetchAll("SELECT r.*, s.name AS site_name FROM routers r LEFT JOIN sites s ON s.id = r.site_id WHERE r.deleted_at IS NULL AND r.status = 'active' ORDER BY r.name");
        $selected = $request->int('router', active_router_id()) ?: (int) ($routers[0]['id'] ?? 0);
        $interfaces = $selected ? Database::fetchAll('SELECT name, type, running, disabled FROM router_interfaces WHERE router_id = :r ORDER BY name', ['r' => $selected]) : [];
        $uptime24 = Database::fetchAll(
            'SELECT router_id, COUNT(*) n, SUM(online) ok FROM router_status_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) GROUP BY router_id');
        $availability = [];
        foreach ($uptime24 as $u) {
            $availability[(int) $u['router_id']] = $u['n'] ? round($u['ok'] * 100 / $u['n'], 1) : null;
        }
        return $this->view('monitoring/index', compact('routers', 'selected', 'interfaces', 'availability'));
    }

    /** Mesure en direct (JSON) : CPU, RAM, clients, débit d'une interface. */
    public function live(Request $request, string $id): Response
    {
        $this->authorize('monitoring.view');
        $router = RouterService::find((int) $id);
        $this->notFoundUnless($router);
        try {
            $info = RouterService::info($router);
            $active = count(RouterService::client($router)->query('/ip/hotspot/active/print', ['.proplist' => '.id']));
            $iface = $request->str('interface') ?: (string) $router['wan_interface'];
            $traffic = ['rx_bps' => null, 'tx_bps' => null];
            if ($iface !== '') {
                $traffic = RouterService::traffic($router, $iface);
            }
            return $this->json(['success' => true, 'time' => date('H:i:s'), 'cpu' => $info['cpu_load'], 'memory' => $info['memory_used_pct'],
                'uptime' => human_duration($info['uptime']), 'active' => $active, 'interface' => $iface] + $traffic);
        } catch (Throwable $e) {
            RouterService::close((int) $router['id']);
            return $this->json(['success' => false, 'message' => $e->getMessage()], 503);
        }
    }

    /** Historique des relevés (24 h par défaut). */
    public function history(Request $request, string $id): Response
    {
        $this->authorize('monitoring.view');
        $hours = max(1, min(168, $request->int('hours', 24)));
        $rows = Database::fetchAll(
            'SELECT created_at, online, cpu_load, ROUND(100 - free_memory * 100 / NULLIF(total_memory,0)) AS mem, active_users, rx_bps, tx_bps, latency_ms
               FROM router_status_logs WHERE router_id = :r AND created_at >= DATE_SUB(NOW(), INTERVAL :h HOUR) ORDER BY created_at', ['r' => (int) $id, 'h' => $hours]);
        return $this->json(['success' => true, 'rows' => $rows]);
    }
}
