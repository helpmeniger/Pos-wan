<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

/** Alertes affichées dans la barre supérieure. */
final class Notifications
{
    public static function get(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $items = [];
        if (can('stock.view')) {
            $low = (int) DB::value("SELECT COUNT(*) FROM products p LEFT JOIN (SELECT product_id, SUM(quantity) q FROM stock_levels GROUP BY product_id) s ON s.product_id = p.id WHERE p.status = 'active' AND p.track_stock = 1 AND p.parent_id IS NULL AND COALESCE(s.q,0) <= p.min_stock");
            if ($low > 0) {
                $items[] = ['bi-exclamation-triangle text-warning', "$low article(s) en stock bas ou en rupture", '/stock?filter=low'];
            }
            if (feature('lots')) {
                $days = (int) setting('expiry_alert_days', '60');
                $exp = (int) DB::value('SELECT COUNT(*) FROM stock_lots WHERE quantity > 0 AND expiry_date IS NOT NULL AND expiry_date <= CURDATE() + INTERVAL ? DAY', [$days]);
                if ($exp > 0) {
                    $items[] = ['bi-calendar-x text-danger', "$exp lot(s) périmé(s) ou proches de la péremption", '/stock/expiry'];
                }
            }
        }
        if (\App\Core\Addons::isActive('pressing') && can('pressing.view')) {
            $late = (int) DB::value("SELECT COUNT(*) FROM pressing_orders WHERE status IN ('received','processing') AND due_date < CURDATE()");
            $soon = (int) DB::value("SELECT COUNT(*) FROM pressing_orders WHERE status IN ('received','processing') AND due_date BETWEEN CURDATE() AND CURDATE() + INTERVAL ? DAY", [max(0, (int) setting('pressing_alerte_jours', '1'))]);
            if ($late > 0) {
                $items[] = ['bi-alarm text-danger', "$late dépôt(s) pressing en retard", '/pressing/suivi?f=late'];
            }
            if ($soon > 0) {
                $items[] = ['bi-hourglass-split text-warning', "$soon dépôt(s) pressing à livrer bientôt", '/pressing/suivi?f=soon'];
            }
        }
        if (can('customers.view')) {
            $late = (int) DB::value("SELECT COUNT(DISTINCT customer_id) FROM customer_transactions ct JOIN customers c ON c.id = ct.customer_id WHERE ct.type = 'credit_sale' AND ct.due_date < CURDATE() AND c.balance > 0");
            if ($late > 0) {
                $items[] = ['bi-hourglass-split text-danger', "$late client(s) avec crédit échu", '/reports/debts'];
            }
        }
        return $cache = $items;
    }
}
