<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Services\SaleService;

/**
 * Tableau de bord : indicateurs commerciaux et réseau, graphiques. Données réelles uniquement.
 */
final class DashboardController extends Controller
{
    private function period(Request $request): array
    {
        $p = $request->str('period', 'today');
        $today = date('Y-m-d');
        [$from, $to, $label] = match ($p) {
            'yesterday' => [date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('-1 day')), 'Hier'],
            '7d' => [date('Y-m-d', strtotime('-6 days')), $today, '7 derniers jours'],
            '30d' => [date('Y-m-d', strtotime('-29 days')), $today, '30 derniers jours'],
            'custom' => [
                preg_match('/^\d{4}-\d{2}-\d{2}$/', $request->str('from')) ? $request->str('from') : $today,
                preg_match('/^\d{4}-\d{2}-\d{2}$/', $request->str('to')) ? $request->str('to') : $today,
                'Période personnalisée',
            ],
            default => [$today, $today, "Aujourd'hui"],
        };
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        return ['key' => $p, 'from' => $from, 'to' => $to, 'label' => $label];
    }

    public function index(Request $request): Response
    {
        if (!Auth::can('dashboard.view') && Auth::can('reseller.portal')) {
            return $this->redirect('/reseller');
        }
        $this->authorize('dashboard.view');
        $p = $this->period($request);
        $rid = $request->int('router_id', active_router_id());
        $rs = $rid ? ' AND s.router_id = ' . $rid : '';
        $rt = $rid ? ' AND t.router_id = ' . $rid : '';
        $rw = $rid ? ' AND ws.router_id = ' . $rid : '';
        $rr = $rid ? ' AND r.id = ' . $rid : '';
        $range = ['a' => $p['from'] . ' 00:00:00', 'b' => $p['to'] . ' 23:59:59'];
        $showMoney = Auth::can('reports.view', 'sales.view');

        $sales = Database::fetch(
            "SELECT COUNT(*) n, COALESCE(SUM(s.total),0) total,
                    SUM(s.channel = 'physical') physical_n, COALESCE(SUM(CASE WHEN s.channel='physical' THEN s.total END),0) physical,
                    SUM(s.channel = 'online') online_n, COALESCE(SUM(CASE WHEN s.channel='online' THEN s.total END),0) online,
                    SUM(s.channel = 'reseller') reseller_n, COALESCE(SUM(CASE WHEN s.channel='reseller' THEN s.total END),0) reseller
               FROM sales s WHERE s.status = 'completed' AND s.created_at BETWEEN :a AND :b{$rs}", $range);
        $kpi = [
            'today' => (float) Database::value("SELECT COALESCE(SUM(s.total),0) FROM sales s WHERE s.status='completed' AND s.created_at >= CURDATE(){$rs}"),
            'month' => (float) Database::value("SELECT COALESCE(SUM(s.total),0) FROM sales s WHERE s.status='completed' AND s.created_at >= :m{$rs}", ['m' => date('Y-m-01')]),
            'tickets_sold' => (int) Database::value("SELECT COUNT(*) FROM sale_items si JOIN sales s ON s.id = si.sale_id WHERE s.status='completed' AND s.created_at BETWEEN :a AND :b{$rs}", $range),
            'cash' => array_sum(array_map(static fn ($s) => SaleService::expectedCash($s), Database::fetchAll("SELECT * FROM cash_sessions WHERE status = 'open'"))),
        ];
        $tickets = array_column(Database::fetchAll("SELECT t.status, COUNT(*) n FROM tickets t WHERE 1=1{$rt} GROUP BY t.status"), 'n', 'status');
        $notPushed = (int) Database::value("SELECT COUNT(*) FROM tickets t WHERE t.on_router = 0 AND t.status = 'available'{$rt}");

        $routers = Database::fetchAll(
            "SELECT r.id, r.name, r.connection_state, r.last_cpu, r.last_memory_used_pct, r.last_active_users, r.last_uptime, r.last_checked_at,
                    (SELECT COUNT(*) FROM router_interfaces i WHERE i.router_id = r.id) ifaces, (SELECT SUM(i.running) FROM router_interfaces i WHERE i.router_id = r.id AND i.disabled = 0) ifaces_up,
                    (SELECT l.rx_bps FROM router_status_logs l WHERE l.router_id = r.id AND l.online = 1 ORDER BY l.id DESC LIMIT 1) rx_bps,
                    (SELECT l.tx_bps FROM router_status_logs l WHERE l.router_id = r.id AND l.online = 1 ORDER BY l.id DESC LIMIT 1) tx_bps
               FROM routers r WHERE r.deleted_at IS NULL AND r.status = 'active'{$rr} ORDER BY r.name");
        $net = [
            'total' => count($routers),
            'online' => count(array_filter($routers, static fn ($r) => $r['connection_state'] === 'online')),
            'offline' => count(array_filter($routers, static fn ($r) => $r['connection_state'] === 'offline')),
            'clients' => array_sum(array_map(static fn ($r) => $r['connection_state'] === 'online' ? (int) $r['last_active_users'] : 0, $routers)),
            'rx_bps' => array_sum(array_map(static fn ($r) => (int) $r['rx_bps'], $routers)),
            'tx_bps' => array_sum(array_map(static fn ($r) => (int) $r['tx_bps'], $routers)),
        ];
        $usage = Database::fetch("SELECT COUNT(*) sessions, COALESCE(SUM(ws.bytes_out),0) download, COALESCE(SUM(ws.bytes_in),0) upload, COALESCE(AVG(ws.uptime_seconds),0) avg_up FROM wifi_sessions ws WHERE ws.login_at BETWEEN :a AND :b{$rw}", $range);

        $byRouter = $showMoney ? Database::fetchAll("SELECT r.name, COUNT(*) n, SUM(s.total) total FROM sales s JOIN routers r ON r.id = s.router_id WHERE s.status='completed' AND s.created_at BETWEEN :a AND :b{$rs} GROUP BY r.id ORDER BY total DESC", $range) : [];
        $byReseller = $showMoney ? Database::fetchAll("SELECT rs.business_name name, COUNT(*) n, SUM(s.total) total, SUM(s.commission_amount) commission FROM sales s JOIN resellers rs ON rs.id = s.reseller_id WHERE s.status='completed' AND s.created_at BETWEEN :a AND :b{$rs} GROUP BY rs.id ORDER BY total DESC", $range) : [];
        $byOffer = Database::fetchAll("SELECT o.name, COUNT(*) n, SUM(si.total) total FROM sale_items si JOIN sales s ON s.id = si.sale_id JOIN offers o ON o.id = si.offer_id WHERE s.status='completed' AND s.created_at BETWEEN :a AND :b{$rs} GROUP BY o.id ORDER BY n DESC", $range);

        // Séries journalières sur la période (au moins 7 jours pour lisibilité).
        $start = min($p['from'], date('Y-m-d', strtotime($p['to'] . ' -6 days')));
        $days = [];
        for ($d = strtotime($start); $d <= strtotime($p['to']); $d += 86400) {
            $days[date('Y-m-d', $d)] = ['physical' => 0, 'online' => 0, 'reseller' => 0, 'sessions' => 0, 'bytes' => 0];
        }
        $dr = ['a' => $start . ' 00:00:00', 'b' => $p['to'] . ' 23:59:59'];
        foreach (Database::fetchAll("SELECT DATE(s.created_at) d, s.channel, SUM(s.total) t FROM sales s WHERE s.status='completed' AND s.created_at BETWEEN :a AND :b{$rs} GROUP BY d, s.channel", $dr) as $r) {
            if (isset($days[$r['d']])) {
                $days[$r['d']][$r['channel']] = (float) $r['t'];
            }
        }
        foreach (Database::fetchAll("SELECT DATE(ws.login_at) d, COUNT(*) n, SUM(ws.bytes_in + ws.bytes_out) b FROM wifi_sessions ws WHERE ws.login_at BETWEEN :a AND :b{$rw} GROUP BY d", $dr) as $r) {
            if (isset($days[$r['d']])) {
                $days[$r['d']]['sessions'] = (int) $r['n'];
                $days[$r['d']]['bytes'] = round((int) $r['b'] / 1048576, 1);
            }
        }
        $weekly = Database::fetchAll("SELECT YEARWEEK(s.created_at, 3) w, MIN(DATE(s.created_at)) d, SUM(s.total) t FROM sales s WHERE s.status='completed' AND s.created_at >= DATE_SUB(CURDATE(), INTERVAL 12 WEEK){$rs} GROUP BY w ORDER BY w");
        $monthly = Database::fetchAll("SELECT DATE_FORMAT(s.created_at, '%Y-%m') m, SUM(s.total) t FROM sales s WHERE s.status='completed' AND s.created_at >= DATE_SUB(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 11 MONTH){$rs} GROUP BY m ORDER BY m");

        $chart = [
            'money' => $showMoney,
            'days' => array_map(static fn ($d) => date('d/m', strtotime($d)), array_keys($days)),
            'physical' => array_column($days, 'physical'), 'online' => array_column($days, 'online'), 'reseller' => array_column($days, 'reseller'),
            'sessions' => array_column($days, 'sessions'), 'traffic' => array_column($days, 'bytes'),
            'weeks' => array_map(static fn ($w) => 'S' . substr((string) $w['w'], 4) . ' (' . date('d/m', strtotime($w['d'])) . ')', $weekly), 'weekly' => array_map('floatval', array_column($weekly, 't')),
            'months' => array_column($monthly, 'm'), 'monthly' => array_map('floatval', array_column($monthly, 't')),
            'routers' => array_column($byRouter, 'name'), 'routerTotals' => array_map('floatval', array_column($byRouter, 'total')),
            'offers' => array_column($byOffer, 'name'), 'offerCounts' => array_map('intval', array_column($byOffer, 'n')),
        ];
        $setup = [
            'routers' => (int) Database::value('SELECT COUNT(*) FROM routers WHERE deleted_at IS NULL'),
            'offers_linked' => (int) Database::value('SELECT COUNT(*) FROM offer_router'),
            'tickets' => array_sum(array_map('intval', $tickets)),
        ];
        $allRouters = Database::fetchAll("SELECT id, name FROM routers WHERE deleted_at IS NULL AND status = 'active' ORDER BY name");
        return $this->view('dashboard/index', compact('p', 'rid', 'sales', 'kpi', 'tickets', 'notPushed', 'routers', 'net', 'usage', 'byRouter', 'byReseller', 'byOffer', 'chart', 'setup', 'allRouters', 'showMoney'));
    }
}
