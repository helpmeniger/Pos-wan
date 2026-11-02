<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;

/**
 * Tableau de bord. Phase 1 : indicateurs calculés depuis la base réelle
 * (aucune valeur fictive). Les graphiques et indicateurs réseau détaillés
 * arrivent en phase 2/3.
 */
final class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('dashboard.view', 'reseller.portal');

        $today = date('Y-m-d');
        $month = date('Y-m-01');
        $stats = [
            'revenue_today' => (float) Database::value("SELECT COALESCE(SUM(total),0) FROM sales WHERE status = 'completed' AND created_at >= :d", ['d' => $today]),
            'revenue_month' => (float) Database::value("SELECT COALESCE(SUM(total),0) FROM sales WHERE status = 'completed' AND created_at >= :d", ['d' => $month]),
            'sales_today' => (int) Database::value("SELECT COUNT(*) FROM sales WHERE status = 'completed' AND created_at >= :d", ['d' => $today]),
            'tickets' => Database::fetchAll('SELECT status, COUNT(*) AS n FROM tickets GROUP BY status'),
            'routers_total' => (int) Database::value('SELECT COUNT(*) FROM routers WHERE deleted_at IS NULL'),
            'routers_online' => (int) Database::value("SELECT COUNT(*) FROM routers WHERE deleted_at IS NULL AND status = 'active' AND connection_state = 'online'"),
            'routers_offline' => (int) Database::value("SELECT COUNT(*) FROM routers WHERE deleted_at IS NULL AND status = 'active' AND connection_state = 'offline'"),
            'active_sessions' => (int) Database::value('SELECT COUNT(*) FROM wifi_sessions WHERE is_active = 1'),
            'offers' => (int) Database::value("SELECT COUNT(*) FROM offers WHERE status = 'active'"),
            'users' => (int) Database::value('SELECT COUNT(*) FROM users WHERE deleted_at IS NULL'),
        ];
        $ticketCounts = array_column($stats['tickets'], 'n', 'status');

        $recentActivity = Auth::can('audit.view')
            ? Database::fetchAll('SELECT a.action, a.description, a.created_at, u.full_name FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id ORDER BY a.id DESC LIMIT 8')
            : [];

        return $this->view('dashboard/index', compact('stats', 'ticketCounts', 'recentActivity'));
    }
}
