<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Csv;
use App\Core\DB;
use App\Core\Request;
use App\Services\ReportService;

final class ReportController extends Controller
{
    private function store(): ?int
    {
        $s = (int) Request::query('store', 0) ?: null;
        if (!can('sales.view_all')) {
            $u = $this->user();
            return $u['store_id'] ? (int) $u['store_id'] : null;
        }
        return $s;
    }

    private function common(string $title): array
    {
        [$from, $to] = date_range('first day of this month');
        return ['title' => $title, 'from' => $from, 'to' => $to, 'storeId' => $this->store(), 'stores' => DB::all('SELECT id, name FROM stores ORDER BY name'), 'scripts' => ['vendor/chartjs/chart.umd.js', 'js/charts.js']];
    }

    private function exportAllowed(): bool
    {
        return Request::query('export') === 'csv' && can('reports.export');
    }

    public function index(): void
    {
        redirect('/reports/sales');
    }

    public function sales(): void
    {
        $d = $this->common('Rapport des ventes');
        [$from, $to, $s] = [$d['from'], $d['to'], $d['storeId']];
        $d += [
            'sum' => ReportService::summary($from, $to, $s),
            'daily' => ReportService::daily($from, $to, $s),
            'categories' => ReportService::byCategory($from, $to, $s),
            'users' => ReportService::byUser($from, $to, $s),
            'registers' => ReportService::byRegister($from, $to, $s),
            'methods' => ReportService::byMethod($from, $to, $s),
            'hours' => ReportService::byHour($from, $to, $s),
        ];
        if ($this->exportAllowed()) {
            Csv::download("rapport_ventes_{$from}_{$to}.csv", ['Date', 'Tickets', 'Chiffre d\'affaires', 'Marge brute'], array_map(fn($r) => [$r['d'], $r['n'], $r['total'], can('reports.financial') ? round($r['margin']) : ''], $d['daily']));
        }
        view('reports/sales', $d);
    }

    public function products(): void
    {
        $d = $this->common('Ventes par article');
        $d['rows'] = ReportService::topProducts($d['from'], $d['to'], $d['storeId'], 500, Request::query('order') === 'qty' ? 'qty' : 'revenue');
        if ($this->exportAllowed()) {
            Csv::download("ventes_articles_{$d['from']}_{$d['to']}.csv", ['Référence', 'Article', 'Catégorie', 'Quantité', 'CA', 'Marge'], array_map(fn($r) => [$r['reference'], $r['name'], $r['category'], round((float) $r['qty'], 3), round((float) $r['revenue']), can('reports.financial') ? round((float) $r['margin']) : ''], $d['rows']));
        }
        view('reports/products', $d);
    }

    public function profit(): void
    {
        $d = $this->common('Bénéfices & marges');
        $d['sum'] = ReportService::summary($d['from'], $d['to'], $d['storeId']);
        $d['categories'] = ReportService::byCategory($d['from'], $d['to'], $d['storeId']);
        $d['expenses'] = DB::all("SELECT c.name, e.type, SUM(e.amount) AS total FROM expenses e JOIN expense_categories c ON c.id = e.category_id WHERE e.status = 'valid' AND e.expense_date BETWEEN ? AND ? GROUP BY c.name, e.type ORDER BY e.type, total DESC", [$d['from'], $d['to']]);
        // Évolution mensuelle sur 12 mois
        $months = [];
        for ($i = 11; $i >= 0; $i--) {
            $start = date('Y-m-01', strtotime("-$i months", strtotime(date('Y-m-01'))));
            $end = date('Y-m-t', strtotime($start));
            $sm = ReportService::summary($start, $end, $d['storeId']);
            $months[] = ['m' => $start, 'net' => $sm['net'], 'margin' => $sm['margin'], 'profit' => $sm['profit'], 'expenses' => $sm['expenses']];
        }
        $d['months'] = $months;
        if ($this->exportAllowed()) {
            Csv::download('evolution_benefices.csv', ['Mois', 'CA net', 'Marge brute', 'Dépenses', 'Bénéfice estimé'], array_map(fn($m) => [substr($m['m'], 0, 7), round($m['net']), round($m['margin']), round($m['expenses']), round($m['profit'])], $months));
        }
        view('reports/profit', $d);
    }

    public function stock(): void
    {
        $d = ['title' => 'Valeur du stock'];
        $d['byWarehouse'] = DB::all('SELECT w.name, SUM(GREATEST(sl.quantity,0) * p.cost_price) AS cost_value, SUM(GREATEST(sl.quantity,0) * p.sale_price) AS sale_value, COUNT(DISTINCT CASE WHEN sl.quantity > 0 THEN p.id END) AS n FROM stock_levels sl JOIN products p ON p.id = sl.product_id JOIN warehouses w ON w.id = sl.warehouse_id GROUP BY w.id, w.name ORDER BY w.name');
        $d['byCategory'] = DB::all('SELECT COALESCE(c.name, \'Sans catégorie\') AS name, SUM(GREATEST(sl.quantity,0) * p.cost_price) AS cost_value, SUM(GREATEST(sl.quantity,0) * p.sale_price) AS sale_value FROM stock_levels sl JOIN products p ON p.id = sl.product_id LEFT JOIN categories c ON c.id = p.category_id GROUP BY c.id, c.name ORDER BY cost_value DESC');
        // Articles dormants : en stock mais non vendus depuis 90 jours
        $d['dormant'] = DB::all("SELECT p.id, p.name, SUM(sl.quantity) AS qty, SUM(sl.quantity) * p.cost_price AS value, (SELECT MAX(s.created_at) FROM sale_items si JOIN sales s ON s.id = si.sale_id WHERE si.product_id = p.id) AS last_sale
                FROM products p JOIN stock_levels sl ON sl.product_id = p.id WHERE p.status = 'active' GROUP BY p.id, p.name, p.cost_price HAVING qty > 0 AND (last_sale IS NULL OR last_sale < NOW() - INTERVAL 90 DAY) ORDER BY value DESC LIMIT 50");
        if ($this->exportAllowed()) {
            Csv::download('valeur_stock_' . date('Ymd') . '.csv', ['Catégorie', 'Valeur au coût', 'Valeur de vente'], array_map(fn($r) => [$r['name'], round((float) $r['cost_value']), round((float) $r['sale_value'])], $d['byCategory']));
        }
        view('reports/stock', $d);
    }

    public function debts(): void
    {
        $d = ['title' => 'Créances clients & dettes fournisseurs'];
        // Ancienneté des créances : basée sur la plus ancienne vente à crédit non couverte (approximation documentée)
        $d['customers'] = DB::all("SELECT c.id, c.name, c.phone, c.balance, c.credit_limit,
                (SELECT MIN(due_date) FROM customer_transactions ct WHERE ct.customer_id = c.id AND ct.type = 'credit_sale' AND ct.created_at >= COALESCE((SELECT MAX(created_at) FROM customer_transactions z WHERE z.customer_id = c.id AND z.balance_after <= 0), '1970-01-01')) AS oldest_due
                FROM customers c WHERE c.balance > 0 ORDER BY c.balance DESC");
        $d['suppliers'] = DB::all("SELECT s.id, s.name, s.phone, s.balance, (SELECT MIN(due_date) FROM supplier_transactions st WHERE st.supplier_id = s.id AND st.type = 'invoice' AND st.due_date IS NOT NULL AND st.created_at >= COALESCE((SELECT MAX(created_at) FROM supplier_transactions z WHERE z.supplier_id = s.id AND z.balance_after <= 0), '1970-01-01')) AS oldest_due FROM suppliers s WHERE s.balance > 0 ORDER BY s.balance DESC");
        if ($this->exportAllowed()) {
            Csv::download('creances_' . date('Ymd') . '.csv', ['Client', 'Téléphone', 'Solde dû', 'Plafond', 'Échéance la plus ancienne'], array_map(fn($r) => [$r['name'], $r['phone'], $r['balance'], $r['credit_limit'], $r['oldest_due']], $d['customers']));
        }
        view('reports/debts', $d);
    }

    public function cash(): void
    {
        redirect('/cash/sessions');
    }

    public function prescriptions(): void
    {
        [$from, $to] = date_range('-30 days');
        $rows = DB::all('SELECT pr.*, s.number, s.id AS sale_id, s.created_at AS sale_date, u.full_name AS validator,
                (SELECT GROUP_CONCAT(CONCAT(si.product_name, \' x\', TRIM(TRAILING \'.\' FROM TRIM(TRAILING \'0\' FROM si.quantity))) SEPARATOR \', \') FROM sale_items si JOIN products p ON p.id = si.product_id WHERE si.sale_id = s.id AND p.requires_prescription = 1) AS products
                FROM prescriptions pr JOIN sales s ON s.id = pr.sale_id JOIN users u ON u.id = pr.validated_by WHERE pr.created_at BETWEEN ? AND ? ORDER BY pr.id DESC', [$from . ' 00:00:00', $to . ' 23:59:59']);
        if ($this->exportAllowed()) {
            Csv::download("ordonnancier_{$from}_{$to}.csv", ['Date', 'Vente', 'Prescripteur', 'Patient', 'Réf. ordonnance', 'Date ordonnance', 'Produits', 'Validé par'], array_map(fn($r) => [$r['sale_date'], $r['number'], $r['prescriber'], $r['patient_name'], $r['prescription_ref'], $r['prescription_date'], $r['products'], $r['validator']], $rows));
        }
        view('reports/prescriptions', ['title' => 'Registre des délivrances sur ordonnance', 'rows' => $rows, 'from' => $from, 'to' => $to]);
    }
}
