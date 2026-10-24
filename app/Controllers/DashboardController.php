<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\DB;
use App\Services\Menu;
use App\Services\ReportService;

final class DashboardController extends Controller
{
    public function home(): void
    {
        // Accueil selon le mode de fonctionnement : le personnel de caisse arrive directement
        // sur la caisse de son activité (clinique, pressing), sans navigation supplémentaire.
        $mode = \App\Services\BusinessMode::current();
        if ($mode === 'clinique' || $mode === 'clinique_pharmacie') {
            if (!can('dashboard.view') && can('clinique.reception')) {
                redirect('/clinique/caisse');
            }
            if (!can('dashboard.view') && !can('pos.sell') && can('clinique.view')) {
                redirect('/clinique');
            }
        }
        if ($mode === 'ecole') {
            foreach (['ecole.view' => '/ecole', 'ecole.caisse' => '/ecole/caisse', 'ecole.presence' => '/ecole/presences', 'ecole.notes' => '/ecole/notes'] as $perm => $to) {
                if (can($perm)) {
                    redirect($to);
                }
            }
        }
        if ($mode === 'pressing' && !can('dashboard.view')) {
            if (can('pressing.deposit')) {
                redirect('/pressing/caisse');
            }
            if (can('pressing.view')) {
                redirect('/pressing');
            }
        }
        if ($mode === 'hotel' && !can('dashboard.view')) {
            foreach (['hotel.reception' => '/hotel', 'hotel.housekeeping' => '/hotel/menage', 'hotel.view' => '/hotel'] as $perm => $to) {
                if (can($perm)) {
                    redirect($to);
                }
            }
        }
        if (can('dashboard.view')) {
            redirect('/dashboard');
        }
        if (feature('restaurant') && can('restaurant.order')) {
            redirect('/restaurant');
        }
        if (feature('restaurant') && can('restaurant.kitchen')) {
            redirect('/kitchen');
        }
        redirect(can('pos.sell') ? '/pos' : '/menu');
    }

    public function menu(): void
    {
        view('dashboard/menu', ['title' => 'Menu de gestion', 'groups' => Menu::visible()]);
    }

    public function index(): void
    {
        if (\App\Services\BusinessMode::is('ecole') && \App\Services\BusinessMode::hides('/dashboard') && can('ecole.view')) {
            redirect('/ecole'); // mode Établissement : tableau de bord de l'établissement
        }
        $u = $this->user();
        $storeId = (int) ($_GET['store'] ?? 0) ?: null;
        if (!can('sales.view_all')) {
            $storeId = $u['store_id'] ? (int) $u['store_id'] : null;
        }
        $today = date('Y-m-d');
        $monthStart = date('Y-m-01');
        $from30 = date('Y-m-d', strtotime('-29 days'));
        $data = [
            'title' => 'Tableau de bord',
            'storeId' => $storeId,
            'stores' => DB::all('SELECT id, name FROM stores WHERE active = 1 ORDER BY name'),
            'today' => ReportService::summary($today, $today, $storeId),
            'yesterday' => ReportService::summary(date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('-1 day')), $storeId),
            'month' => ReportService::summary($monthStart, $today, $storeId),
            'receivables' => ReportService::receivables(),
            'payables' => ReportService::payables(),
            'cash' => ReportService::cashInDrawers(),
            'stockValue' => can('products.cost_view') ? \App\Services\StockService::valuation() : null,
            'daily' => ReportService::daily($from30, $today, $storeId),
            'top' => ReportService::topProducts($monthStart, $today, $storeId, 8),
            'categories' => ReportService::byCategory($monthStart, $today, $storeId),
            'users' => ReportService::byUser($monthStart, $today, $storeId),
            'methods' => ReportService::byMethod($monthStart, $today, $storeId),
            'low' => ReportService::lowStock(8),
            'expiring' => feature('lots') ? ReportService::expiring((int) setting('expiry_alert_days', '60'), 8) : [],
            'recent' => DB::all("SELECT s.id, s.number, s.total, s.status, s.created_at, u.full_name AS cashier, c.name AS customer FROM sales s JOIN users u ON u.id = s.user_id LEFT JOIN customers c ON c.id = s.customer_id" . ($storeId ? ' WHERE s.store_id = ' . (int) $storeId : '') . ' ORDER BY s.id DESC LIMIT 8'),
            'scripts' => ['vendor/chartjs/chart.umd.js', 'js/dashboard.js'],
        ];
        view('dashboard/index', $data);
    }
}
