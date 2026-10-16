<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Validator;
use App\Services\StockService;

final class StockController extends Controller
{
    public function index(Request $r): void
    {
        $s = $this->storeId();
        $rows = Database::fetchAll("SELECT p.id, p.name, p.unit, p.stock_quantity, p.stock_alert_threshold, p.purchase_price, c.name category,
              (SELECT MAX(taken_at) FROM stock_snapshots ss WHERE ss.product_id = p.id AND ss.snapshot_type = 'inventaire_physique') last_inventory
            FROM products p LEFT JOIN product_categories c ON c.id = p.category_id WHERE p.store_id = :s AND p.deleted_at IS NULL ORDER BY p.name", ['s' => $s]);
        $value = array_sum(array_map(static fn ($r) => (float) $r['stock_quantity'] * (float) $r['purchase_price'], $rows));
        $this->view('stock/index', ['title' => 'Stocks', 'rows' => $rows, 'value' => $value]);
    }

    public function movements(Request $r): void
    {
        [$from, $to] = $this->dateRange('-30 days');
        [$limit, $offset, $page] = $this->paginate(50);
        $w = 'm.store_id = :s AND m.occurred_at BETWEEN :f AND :t';
        $p = ['s' => $this->storeId(), 'f' => "$from 00:00:00", 't' => "$to 23:59:59"];
        if ($pid = (int) $r->query('product_id')) { $w .= ' AND m.product_id = :p'; $p['p'] = $pid; }
        if (array_key_exists((string) $r->query('type'), StockService::TYPES)) { $w .= ' AND m.movement_type = :ty'; $p['ty'] = $r->query('type'); }
        $total = (int) Database::value("SELECT COUNT(*) FROM inventory_movements m WHERE $w", $p);
        $rows = Database::fetchAll("SELECT m.*, pr.name product, u.full_name user FROM inventory_movements m JOIN products pr ON pr.id = m.product_id
            LEFT JOIN users u ON u.id = m.user_id WHERE $w ORDER BY m.occurred_at DESC, m.id DESC LIMIT $limit OFFSET $offset", $p);
        $this->view('stock/movements', ['title' => 'Mouvements de stock', 'rows' => $rows, 'from' => $from, 'to' => $to, 'page' => $page,
            'pages' => (int) ceil($total / $limit), 'products' => Database::fetchAll('SELECT id, name FROM products WHERE store_id = :s AND deleted_at IS NULL ORDER BY name', ['s' => $this->storeId()])]);
    }

    public function storeMovement(Request $r): void
    {
        $types = array_diff(array_keys(StockService::TYPES), ['vente', 'inventaire']); // ventes via la caisse, inventaire via le formulaire dédié
        $v = Validator::make($r->all(), ['product_id' => 'required|int', 'movement_type' => 'required|in:' . implode(',', $types),
            'quantity' => 'required|numeric|min:-100000|max:100000', 'reference' => 'string|max:80', 'comment' => 'string|max:500',
            'from_zone' => 'string|max:60', 'to_zone' => 'string|max:60', 'occurred_at' => 'datetime']);
        if ($v->fails()) {
            $this->back('error', $v->firstError(), 'stock/movements');
        }
        $d = $v->data;
        try {
            StockService::record($this->storeId(), (int) $d['product_id'], $d['movement_type'], (float) $d['quantity'], [
                'reference' => $d['reference'], 'comment' => $d['comment'], 'from_zone' => $d['from_zone'], 'to_zone' => $d['to_zone'],
                'occurred_at' => $d['occurred_at'] ? date('Y-m-d H:i:s', min(time(), strtotime($d['occurred_at']))) : null,
            ]);
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            $this->back('error', $e->getMessage(), 'stock/movements');
        }
        $this->back('success', 'Mouvement enregistré.', 'stock/movements');
    }

    public function inventory(Request $r): void
    {
        $counts = $r->input('counts');
        if (!is_array($counts)) {
            $this->back('error', 'Aucune quantité saisie.', 'stock');
        }
        $adjust = (bool) $r->input('adjust');
        $n = 0;
        $variances = 0;
        foreach ($counts as $pid => $val) {
            if ($val === '' || $val === null || !is_numeric(str_replace(',', '.', (string) $val))) {
                continue;
            }
            $res = StockService::physicalCount($this->storeId(), (int) $pid, (float) str_replace(',', '.', (string) $val), $adjust, mb_substr((string) $r->input('comment', ''), 0, 255) ?: null);
            $n++;
            $variances += abs($res['variance']) > 0.0005 ? 1 : 0;
        }
        $this->back('success', "Inventaire enregistré pour {$n} produit(s), {$variances} écart(s) constaté(s)" . ($adjust ? ' et régularisé(s).' : '.'), 'stock');
    }

    public function reconciliation(Request $r): void
    {
        [$from, $to] = $this->dateRange('-7 days');
        $rows = StockService::reconciliation($this->storeId(), $from, $to, (int) $r->query('product_id') ?: null);
        $this->view('stock/reconciliation', ['title' => 'Rapprochement stock / ventes / vidéo', 'rows' => $rows, 'from' => $from, 'to' => $to]);
    }
}
