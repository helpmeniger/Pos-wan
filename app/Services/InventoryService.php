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
            if (!DB::value('SELECT 1 FROM warehouses WHERE id = ? AND active = 1', [$warehouseId])) {
                throw new BusinessException('Entrepôt introuvable.');
            }
            // Un seul inventaire en cours par emplacement (et par catégorie) : pas de comptages mélangés
            $open = DB::value("SELECT number FROM inventories WHERE warehouse_id = ? AND status = 'draft' AND (category_id IS NULL OR ? IS NULL OR category_id = ?)", [$warehouseId, $categoryId, $categoryId]);
            if ($open) {
                throw new BusinessException('Un inventaire est déjà en cours pour cet entrepôt (' . $open . ') : terminez-le ou annulez-le d\'abord.');
            }
            $number = Sequence::next('inventory');
            $id = DB::insert('inventories', ['number' => $number, 'warehouse_id' => $warehouseId, 'category_id' => $categoryId, 'note' => $note, 'user_id' => Auth::id()]);
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

    private static function draft(int $inventoryId, bool $lock = false): array
    {
        $inv = DB::one('SELECT * FROM inventories WHERE id = ?' . ($lock ? ' FOR UPDATE' : ''), [$inventoryId]);
        if (!$inv || $inv['status'] !== 'draft') {
            throw new BusinessException('Inventaire validé ou annulé : il ne peut plus être modifié.');
        }
        return $inv;
    }

    private static function qty(mixed $v): ?float
    {
        $v = is_string($v) ? str_replace([',', ' '], ['.', ''], trim($v)) : $v;
        if ($v === '' || $v === null) {
            return null;
        }
        if (!is_numeric($v) || (float) $v < 0 || (float) $v > 1e9) {
            throw new BusinessException('Quantité comptée invalide.');
        }
        return round((float) $v, 3);
    }

    /** Enregistre une quantité (saisie manuelle, correction ou remise à zéro du comptage). */
    private static function write(array $item, ?float $new, string $method, ?string $note = null, ?int $scansDelta = null): void
    {
        $old = $item['counted_qty'] !== null ? (float) $item['counted_qty'] : null;
        $set = ['counted_qty' => $new, 'counted_by' => Auth::id(), 'counted_at' => date('Y-m-d H:i:s')];
        if ($note !== null) {
            $set['note'] = mb_substr(trim($note), 0, 255) ?: null;
        }
        if ($method === 'reset') {
            $set['scans'] = 0;
        } elseif ($scansDelta) {
            $set['scans'] = (int) $item['scans'] + $scansDelta;
        }
        DB::update('inventory_items', $set, 'id = ?', [$item['id']]);
        if ($old !== $new) {
            DB::insert('inventory_count_log', ['inventory_id' => $item['inventory_id'], 'item_id' => $item['id'], 'old_qty' => $old, 'new_qty' => $new, 'method' => $method, 'user_id' => Auth::id()]);
        }
    }

    /** Saisie groupée (formulaire) : quantités et observations. */
    public static function saveCounts(int $inventoryId, array $counts, array $notes = []): void
    {
        DB::transaction(function () use ($inventoryId, $counts, $notes) {
            self::draft($inventoryId, true);
            foreach ($counts as $itemId => $v) {
                $item = DB::one('SELECT * FROM inventory_items WHERE id = ? AND inventory_id = ? FOR UPDATE', [(int) $itemId, $inventoryId]);
                if (!$item) {
                    continue;
                }
                $new = self::qty($v);
                $note = array_key_exists($itemId, $notes) ? (string) $notes[$itemId] : null;
                $oldQ = $item['counted_qty'] !== null ? (float) $item['counted_qty'] : null;
                if ($new !== $oldQ || ($note !== null && $note !== (string) ($item['note'] ?? ''))) {
                    self::write($item, $new, 'manual', $note);
                }
            }
        });
    }

    /** Saisie manuelle d'une ligne (enregistrement immédiat, reprise possible plus tard). */
    public static function setCount(int $inventoryId, int $itemId, mixed $qty, ?string $note = null, bool $reset = false): array
    {
        return DB::transaction(function () use ($inventoryId, $itemId, $qty, $note, $reset) {
            self::draft($inventoryId, true);
            $item = DB::one('SELECT * FROM inventory_items WHERE id = ? AND inventory_id = ? FOR UPDATE', [$itemId, $inventoryId]) ?? throw new BusinessException('Ligne introuvable.');
            self::write($item, $reset ? null : self::qty($qty), $reset ? 'reset' : 'manual', $note);
            return self::itemInfo($itemId);
        });
    }

    /**
     * Comptage par scan : chaque lecture valide ajoute une unité (ou le nombre d'unités du
     * conditionnement scanné). Un article absent de la liste est ajouté avec son stock actuel.
     */
    public static function scan(int $inventoryId, string $code): array
    {
        $code = trim($code);
        if ($code === '') {
            throw new BusinessException('Code vide.');
        }
        return DB::transaction(function () use ($inventoryId, $code) {
            $inv = self::draft($inventoryId, true);
            $factor = 1.0;
            $p = DB::one("SELECT id, name, track_stock, category_id FROM products WHERE status = 'active' AND (barcode = ? OR reference = ?) LIMIT 1", [$code, $code]);
            if (!$p) {
                $pack = DB::one('SELECT product_id, factor FROM product_packagings WHERE barcode = ?', [$code]);
                if ($pack) {
                    $p = DB::one("SELECT id, name, track_stock, category_id FROM products WHERE id = ? AND status = 'active'", [$pack['product_id']]);
                    $factor = (float) $pack['factor'];
                }
            }
            if (!$p) {
                throw new BusinessException('Aucun article pour le code « ' . $code . ' ».');
            }
            if ((int) $p['track_stock'] !== 1) {
                throw new BusinessException('« ' . $p['name'] . ' » n\'est pas suivi en stock.');
            }
            if ($inv['category_id'] && (int) $p['category_id'] !== (int) $inv['category_id']) {
                throw new BusinessException('« ' . $p['name'] . ' » n\'appartient pas à la catégorie inventoriée.');
            }
            $item = DB::one('SELECT * FROM inventory_items WHERE inventory_id = ? AND product_id = ? FOR UPDATE', [$inventoryId, $p['id']]);
            if (!$item) {
                $expected = (float) DB::value('SELECT COALESCE(quantity,0) FROM stock_levels WHERE product_id = ? AND warehouse_id = ?', [$p['id'], $inv['warehouse_id']]);
                $iid = DB::insert('inventory_items', ['inventory_id' => $inventoryId, 'product_id' => $p['id'], 'expected_qty' => $expected]);
                $item = DB::one('SELECT * FROM inventory_items WHERE id = ?', [$iid]);
            }
            self::write($item, round((float) ($item['counted_qty'] ?? 0) + $factor, 3), 'scan', null, 1);
            return self::itemInfo((int) $item['id']) + ['added' => $factor];
        });
    }

    public static function itemInfo(int $itemId): array
    {
        $r = DB::one('SELECT ii.*, p.name, p.reference, p.barcode FROM inventory_items ii JOIN products p ON p.id = ii.product_id WHERE ii.id = ?', [$itemId]);
        return ['item_id' => (int) $r['id'], 'name' => $r['name'], 'reference' => $r['reference'], 'barcode' => $r['barcode'], 'expected' => (float) $r['expected_qty'],
            'counted' => $r['counted_qty'] !== null ? (float) $r['counted_qty'] : null, 'scans' => (int) $r['scans'], 'note' => $r['note'],
            'diff' => $r['counted_qty'] !== null ? round((float) $r['counted_qty'] - (float) $r['expected_qty'], 3) : null];
    }

    /** Synthèse : comptés, conformes, excédents, manquants (quantités et valeur au coût). */
    public static function summary(int $inventoryId): array
    {
        $s = ['items' => 0, 'counted' => 0, 'uncounted' => 0, 'conform' => 0, 'surplus' => 0, 'missing' => 0, 'surplus_qty' => 0.0, 'missing_qty' => 0.0, 'surplus_value' => 0.0, 'missing_value' => 0.0];
        foreach (DB::all('SELECT ii.expected_qty, ii.counted_qty, p.cost_price FROM inventory_items ii JOIN products p ON p.id = ii.product_id WHERE ii.inventory_id = ?', [$inventoryId]) as $r) {
            $s['items']++;
            if ($r['counted_qty'] === null) {
                $s['uncounted']++;
                continue;
            }
            $s['counted']++;
            $d = round((float) $r['counted_qty'] - (float) $r['expected_qty'], 3);
            if (abs($d) < 0.0005) {
                $s['conform']++;
            } elseif ($d > 0) {
                $s['surplus']++;
                $s['surplus_qty'] += $d;
                $s['surplus_value'] += $d * (float) $r['cost_price'];
            } else {
                $s['missing']++;
                $s['missing_qty'] += -$d;
                $s['missing_value'] += -$d * (float) $r['cost_price'];
            }
        }
        return $s;
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
