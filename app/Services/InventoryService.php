<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\BusinessException;
use App\Core\DB;

/**
 * Inventaire physique : un brouillon fige les quantités théoriques, les
 * comptages sont saisis, puis la validation génère les mouvements d'écart.
 */
final class InventoryService
{
    public static function start(int $warehouseId, ?int $categoryId, ?string $note): int
    {
        return DB::transaction(function () use ($warehouseId, $categoryId, $note) {
            $number = Sequence::next('inventory');
            $id = DB::insert('inventories', ['number' => $number, 'warehouse_id' => $warehouseId, 'note' => $note, 'user_id' => Auth::id()]);
            $sql = "SELECT p.id, COALESCE(sl.quantity,0) AS q FROM products p
                    LEFT JOIN stock_levels sl ON sl.product_id = p.id AND sl.warehouse_id = ?
                    WHERE p.status = 'active' AND p.track_stock = 1";
            $params = [$warehouseId];
            if ($categoryId) {
                $sql .= ' AND p.category_id = ?';
                $params[] = $categoryId;
            }
            foreach (DB::all($sql, $params) as $r) {
                DB::insert('inventory_items', ['inventory_id' => $id, 'product_id' => (int) $r['id'], 'expected_qty' => (float) $r['q']]);
            }
            Audit::log('inventory.start', 'inventory', $id, ['warehouse' => $warehouseId]);
            return $id;
        });
    }

    public static function saveCounts(int $inventoryId, array $counts): void
    {
        $inv = DB::one("SELECT status FROM inventories WHERE id = ?", [$inventoryId]);
        if (!$inv || $inv['status'] !== 'draft') {
            throw new BusinessException('Inventaire non modifiable.');
        }
        foreach ($counts as $itemId => $v) {
            $v = is_string($v) ? str_replace(',', '.', trim($v)) : $v;
            if ($v === '' || $v === null) {
                DB::run('UPDATE inventory_items SET counted_qty = NULL WHERE id = ? AND inventory_id = ?', [(int) $itemId, $inventoryId]);
                continue;
            }
            if (!is_numeric($v) || (float) $v < 0) {
                throw new BusinessException('Quantité comptée invalide.');
            }
            DB::run('UPDATE inventory_items SET counted_qty = ? WHERE id = ? AND inventory_id = ?', [(float) $v, (int) $itemId, $inventoryId]);
        }
    }

    /**
     * Validation : l'écart est calculé par rapport au stock ACTUEL (les ventes
     * réalisées pendant l'inventaire sont ainsi prises en compte).
     * Les articles non comptés sont ignorés.
     */
    public static function validate(int $inventoryId): array
    {
        return DB::transaction(function () use ($inventoryId) {
            $inv = DB::one('SELECT * FROM inventories WHERE id = ? FOR UPDATE', [$inventoryId]);
            if (!$inv || $inv['status'] !== 'draft') {
                throw new BusinessException('Inventaire déjà validé ou annulé.');
            }
            $wh = (int) $inv['warehouse_id'];
            $adjusted = 0;
            $value = 0.0;
            foreach (DB::all('SELECT ii.*, p.track_lots, p.cost_price, p.name FROM inventory_items ii JOIN products p ON p.id = ii.product_id WHERE ii.inventory_id = ? AND ii.counted_qty IS NOT NULL', [$inventoryId]) as $it) {
                // écart = compté - théorique au moment du lancement ; appliqué au stock actuel
                $delta = round((float) $it['counted_qty'] - (float) $it['expected_qty'], 3);
                if (abs($delta) < 0.0005) {
                    continue;
                }
                $reason = 'Inventaire ' . $inv['number'];
                if ($delta > 0) {
                    $lotId = null;
                    if ((int) $it['track_lots'] === 1) {
                        $lotId = StockService::lot((int) $it['product_id'], $wh, 'INV-' . $inv['number'], null, (float) $it['cost_price']);
                    }
                    StockService::move((int) $it['product_id'], $wh, $delta, 'inventory', 'inventory', $inventoryId, $reason, (float) $it['cost_price'], $lotId);
                } else {
                    StockService::issue((int) $it['product_id'], $wh, -$delta, 'inventory', 'inventory', $inventoryId, $reason);
                }
                $adjusted++;
                $value += $delta * (float) $it['cost_price'];
            }
            DB::update('inventories', ['status' => 'validated', 'validated_by' => Auth::id(), 'validated_at' => date('Y-m-d H:i:s')], 'id = ?', [$inventoryId]);
            Audit::log('inventory.validate', 'inventory', $inventoryId, ['adjusted' => $adjusted, 'value' => round($value, 2)]);
            return ['adjusted' => $adjusted, 'value' => round($value, 2)];
        });
    }
}
