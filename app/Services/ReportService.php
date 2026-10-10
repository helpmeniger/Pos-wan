<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

/**
 * Indicateurs calculés depuis les données réelles.
 *
 * Définitions (affichées dans l'interface) :
 * - Ventes brutes : total TTC des ventes non annulées de la période.
 * - Retours : remboursements/avoirs enregistrés pendant la période.
 * - Chiffre d'affaires net = ventes brutes − retours.
 * - Coût des marchandises vendues (CMV) = coût (CMP ou coût de lot) des articles vendus − coût des articles retournés.
 * - Marge brute = CA net − CMV.
 * - Bénéfice estimé = marge brute − dépenses + recettes diverses de la période.
 *   (n'inclut ni amortissements ni impôts sur les bénéfices).
 * La trésorerie (argent disponible) et la valeur du stock sont des notions distinctes.
 */
final class ReportService
{
    private static function storeCond(?int $storeId, string $alias = 's'): array
    {
        return $storeId ? [" AND {$alias}.store_id = ?", [$storeId]] : ['', []];
    }

    public static function summary(string $from, string $to, ?int $storeId = null, ?int $userId = null): array
    {
        [$sc, $sp] = self::storeCond($storeId);
        $uc = $userId ? ' AND s.user_id = ?' : '';
        $up = $userId ? [$userId] : [];
        $p = array_merge([$from . ' 00:00:00', $to . ' 23:59:59'], $sp, $up);
        $sales = DB::one("SELECT COUNT(*) AS n, COALESCE(SUM(s.total),0) AS gross, COALESCE(SUM(s.discount_total),0) AS discounts,
                 COALESCE(SUM(s.tax_total),0) AS tax, COALESCE(SUM(s.cost_total),0) AS cost, COALESCE(SUM(s.credit_amount),0) AS credit
                 FROM sales s WHERE s.status <> 'cancelled' AND s.created_at BETWEEN ? AND ? $sc $uc", $p);
        $ret = DB::one("SELECT COALESCE(SUM(ri.amount),0) AS amount, COALESCE(SUM(ri.quantity * si.unit_cost * si.base_quantity / si.quantity),0) AS cost, COUNT(DISTINCT r.id) AS n
                 FROM sale_returns r JOIN sale_return_items ri ON ri.return_id = r.id JOIN sale_items si ON si.id = ri.sale_item_id JOIN sales s ON s.id = r.sale_id
                 WHERE r.created_at BETWEEN ? AND ? $sc $uc", $p);
        $cancelled = (int) DB::value("SELECT COUNT(*) FROM sales s WHERE s.status = 'cancelled' AND s.created_at BETWEEN ? AND ? $sc $uc", $p);
        $expStore = $storeId ? ' AND (store_id = ? OR store_id IS NULL)' : '';
        $ep = array_merge([$from, $to], $storeId ? [$storeId] : []);
        $exp = (float) DB::value("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE status = 'valid' AND type = 'expense' AND expense_date BETWEEN ? AND ? $expStore", $ep);
        $inc = (float) DB::value("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE status = 'valid' AND type = 'income' AND expense_date BETWEEN ? AND ? $expStore", $ep);
        $net = (float) $sales['gross'] - (float) $ret['amount'];
        $cogs = (float) $sales['cost'] - (float) $ret['cost'];
        $margin = $net - $cogs;
        return [
            'tickets' => (int) $sales['n'],
            'gross' => (float) $sales['gross'],
            'discounts' => (float) $sales['discounts'],
            'tax' => (float) $sales['tax'],
            'returns' => (float) $ret['amount'],
            'returns_count' => (int) $ret['n'],
            'cancelled' => $cancelled,
            'net' => $net,
            'cogs' => $cogs,
            'margin' => $margin,
            'margin_rate' => $net > 0 ? $margin / $net * 100 : 0,
            'avg_basket' => (int) $sales['n'] > 0 ? (float) $sales['gross'] / (int) $sales['n'] : 0,
            'credit' => (float) $sales['credit'],
            'expenses' => $exp,
            'incomes' => $inc,
            'profit' => $margin - $exp + $inc,
        ];
    }

    public static function daily(string $from, string $to, ?int $storeId = null): array
    {
        [$sc, $sp] = self::storeCond($storeId);
        $rows = DB::all("SELECT DATE(s.created_at) AS d, COUNT(*) AS n, SUM(s.total) AS total, SUM(s.total - s.cost_total) AS margin
                FROM sales s WHERE s.status <> 'cancelled' AND s.created_at BETWEEN ? AND ? $sc GROUP BY DATE(s.created_at) ORDER BY d", array_merge([$from . ' 00:00:00', $to . ' 23:59:59'], $sp));
        $map = [];
        foreach ($rows as $r) {
            $map[$r['d']] = $r;
        }
        $out = [];
        for ($t = strtotime($from); $t <= strtotime($to); $t += 86400) {
            $d = date('Y-m-d', $t);
            $out[] = ['d' => $d, 'n' => (int) ($map[$d]['n'] ?? 0), 'total' => (float) ($map[$d]['total'] ?? 0), 'margin' => (float) ($map[$d]['margin'] ?? 0)];
        }
        return $out;
    }

    public static function topProducts(string $from, string $to, ?int $storeId = null, int $limit = 10, string $order = 'revenue'): array
    {
        [$sc, $sp] = self::storeCond($storeId);
        $ord = $order === 'qty' ? 'qty' : 'revenue';
        return DB::all("SELECT p.id, p.name, p.reference, c.name AS category, SUM(si.base_quantity - si.returned_qty * si.base_quantity / si.quantity) AS qty,
                SUM(si.line_total * (si.quantity - si.returned_qty) / si.quantity) AS revenue,
                SUM((si.line_total - si.unit_cost * si.base_quantity) * (si.quantity - si.returned_qty) / si.quantity) AS margin
                FROM sale_items si JOIN sales s ON s.id = si.sale_id JOIN products p ON p.id = si.product_id LEFT JOIN categories c ON c.id = p.category_id
                WHERE s.status <> 'cancelled' AND s.created_at BETWEEN ? AND ? $sc
                GROUP BY p.id, p.name, p.reference, c.name ORDER BY $ord DESC LIMIT " . max(1, min(1000, $limit)), array_merge([$from . ' 00:00:00', $to . ' 23:59:59'], $sp));
    }

    public static function byCategory(string $from, string $to, ?int $storeId = null): array
    {
        [$sc, $sp] = self::storeCond($storeId);
        return DB::all("SELECT COALESCE(c.name, 'Sans catégorie') AS label, COALESCE(c.color, '#95a5a6') AS color,
                SUM(si.line_total * (si.quantity - si.returned_qty) / si.quantity) AS revenue,
                SUM((si.line_total - si.unit_cost * si.base_quantity) * (si.quantity - si.returned_qty) / si.quantity) AS margin
                FROM sale_items si JOIN sales s ON s.id = si.sale_id JOIN products p ON p.id = si.product_id LEFT JOIN categories c ON c.id = p.category_id
                WHERE s.status <> 'cancelled' AND s.created_at BETWEEN ? AND ? $sc GROUP BY c.id, c.name, c.color ORDER BY revenue DESC", array_merge([$from . ' 00:00:00', $to . ' 23:59:59'], $sp));
    }

    public static function byUser(string $from, string $to, ?int $storeId = null): array
    {
        [$sc, $sp] = self::storeCond($storeId);
        return DB::all("SELECT u.full_name AS label, COUNT(*) AS n, SUM(s.total) AS total, SUM(s.discount_total) AS discounts
                FROM sales s JOIN users u ON u.id = s.user_id WHERE s.status <> 'cancelled' AND s.created_at BETWEEN ? AND ? $sc GROUP BY u.id, u.full_name ORDER BY total DESC", array_merge([$from . ' 00:00:00', $to . ' 23:59:59'], $sp));
    }

    public static function byRegister(string $from, string $to, ?int $storeId = null): array
    {
        [$sc, $sp] = self::storeCond($storeId);
        return DB::all("SELECT CONCAT(st.name, ' / ', COALESCE(r.name, '-')) AS label, COUNT(*) AS n, SUM(s.total) AS total
                FROM sales s JOIN stores st ON st.id = s.store_id LEFT JOIN cash_registers r ON r.id = s.register_id
                WHERE s.status <> 'cancelled' AND s.created_at BETWEEN ? AND ? $sc GROUP BY st.name, r.name ORDER BY total DESC", array_merge([$from . ' 00:00:00', $to . ' 23:59:59'], $sp));
    }

    public static function byMethod(string $from, string $to, ?int $storeId = null): array
    {
        [$sc, $sp] = self::storeCond($storeId);
        return DB::all("SELECT sp.method, SUM(sp.amount) AS total FROM sale_payments sp JOIN sales s ON s.id = sp.sale_id
                WHERE s.status <> 'cancelled' AND s.created_at BETWEEN ? AND ? $sc GROUP BY sp.method ORDER BY total DESC", array_merge([$from . ' 00:00:00', $to . ' 23:59:59'], $sp));
    }

    public static function byHour(string $from, string $to, ?int $storeId = null): array
    {
        [$sc, $sp] = self::storeCond($storeId);
        return DB::all("SELECT HOUR(s.created_at) AS h, COUNT(*) AS n, SUM(s.total) AS total FROM sales s
                WHERE s.status <> 'cancelled' AND s.created_at BETWEEN ? AND ? $sc GROUP BY HOUR(s.created_at) ORDER BY h", array_merge([$from . ' 00:00:00', $to . ' 23:59:59'], $sp));
    }

    public static function receivables(): float
    {
        return (float) DB::value('SELECT COALESCE(SUM(balance),0) FROM customers WHERE balance > 0');
    }

    public static function payables(): float
    {
        return (float) DB::value('SELECT COALESCE(SUM(balance),0) FROM suppliers WHERE balance > 0');
    }

    /** Espèces théoriquement présentes dans les caisses ouvertes. */
    public static function cashInDrawers(): float
    {
        $t = 0.0;
        foreach (DB::all("SELECT id FROM cash_sessions WHERE status = 'open'") as $s) {
            $t += CashService::expectedCash((int) $s['id']);
        }
        return $t;
    }

    public static function lowStock(int $limit = 10): array
    {
        return DB::all("SELECT p.id, p.name, p.reference, p.min_stock, COALESCE(s.q,0) AS qty FROM products p
                LEFT JOIN (SELECT product_id, SUM(quantity) q FROM stock_levels GROUP BY product_id) s ON s.product_id = p.id
                WHERE p.status = 'active' AND p.track_stock = 1 AND COALESCE(s.q,0) <= p.min_stock ORDER BY qty ASC LIMIT " . max(1, $limit));
    }

    public static function expiring(int $days, int $limit = 10): array
    {
        return DB::all('SELECT l.*, p.name, p.reference, w.name AS warehouse, DATEDIFF(l.expiry_date, CURDATE()) AS days_left FROM stock_lots l
                JOIN products p ON p.id = l.product_id JOIN warehouses w ON w.id = l.warehouse_id
                WHERE l.quantity > 0 AND l.expiry_date IS NOT NULL AND l.expiry_date <= CURDATE() + INTERVAL ? DAY ORDER BY l.expiry_date LIMIT ' . max(1, $limit), [$days]);
    }
}
