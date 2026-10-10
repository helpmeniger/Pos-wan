<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\BusinessException;
use App\Core\DB;

/**
 * Seul point d'entrée pour modifier le stock.
 * Chaque variation : verrou de ligne (FOR UPDATE) + mise à jour du niveau
 * + écriture d'un mouvement (date, utilisateur, référence, motif, solde).
 * Les appels doivent se faire à l'intérieur d'une transaction (DB::transaction).
 *
 * Méthode de valorisation : coût moyen pondéré (CMP) global par article,
 * recalculé à chaque entrée valorisée (réception). Les sorties sont valorisées
 * au CMP courant (ou au coût du lot pour les articles suivis par lot).
 */
final class StockService
{
    public static function allowNegative(): bool
    {
        return setting('allow_negative_stock', '0') === '1';
    }

    public static function level(int $productId, int $warehouseId, bool $lock = false): float
    {
        $sql = 'SELECT quantity FROM stock_levels WHERE product_id = ? AND warehouse_id = ?' . ($lock ? ' FOR UPDATE' : '');
        $q = DB::value($sql, [$productId, $warehouseId]);
        if ($q === null) {
            DB::run('INSERT IGNORE INTO stock_levels (product_id, warehouse_id, quantity) VALUES (?, ?, 0)', [$productId, $warehouseId]);
            $q = $lock ? DB::value($sql, [$productId, $warehouseId]) : 0;
        }
        return (float) $q;
    }

    public static function totalQty(int $productId): float
    {
        return (float) DB::value('SELECT COALESCE(SUM(quantity),0) FROM stock_levels WHERE product_id = ?', [$productId]);
    }

    /**
     * Mouvement élémentaire. $qty signé (+ entrée, - sortie).
     */
    public static function move(int $productId, int $warehouseId, float $qty, string $type, ?string $refType = null, ?int $refId = null, ?string $reason = null, ?float $unitCost = null, ?int $lotId = null): float
    {
        if (abs($qty) < 0.0005) {
            return self::level($productId, $warehouseId);
        }
        $product = DB::one('SELECT id, name, track_stock, track_lots, cost_price FROM products WHERE id = ?', [$productId]);
        if (!$product) {
            throw new BusinessException('Article introuvable.');
        }
        $current = self::level($productId, $warehouseId, true);
        $new = round($current + $qty, 3);
        if ($new < -0.0005 && $qty < 0 && (int) $product['track_stock'] === 1 && (!self::allowNegative() || (int) $product['track_lots'] === 1)) {
            throw new BusinessException(sprintf('Stock insuffisant pour « %s » : disponible %s, demandé %s.', $product['name'], qty($current), qty(-$qty)));
        }
        if ($lotId !== null) {
            $lot = DB::one('SELECT quantity FROM stock_lots WHERE id = ? AND product_id = ? AND warehouse_id = ? FOR UPDATE', [$lotId, $productId, $warehouseId]);
            if (!$lot) {
                throw new BusinessException('Lot introuvable pour cet entrepôt.');
            }
            $newLot = round((float) $lot['quantity'] + $qty, 3);
            if ($newLot < -0.0005) {
                throw new BusinessException(sprintf('Quantité insuffisante dans le lot pour « %s ».', $product['name']));
            }
            DB::run('UPDATE stock_lots SET quantity = ? WHERE id = ?', [$newLot, $lotId]);
        }
        DB::run('UPDATE stock_levels SET quantity = ? WHERE product_id = ? AND warehouse_id = ?', [$new, $productId, $warehouseId]);
        DB::insert('stock_movements', [
            'product_id' => $productId,
            'warehouse_id' => $warehouseId,
            'lot_id' => $lotId,
            'type' => $type,
            'quantity' => $qty,
            'unit_cost' => $unitCost ?? (float) $product['cost_price'],
            'balance_after' => $new,
            'ref_type' => $refType,
            'ref_id' => $refId,
            'reason' => $reason !== null ? mb_substr($reason, 0, 255) : null,
            'user_id' => Auth::id(),
        ]);
        return $new;
    }

    /** Crée (ou retrouve) un lot. */
    public static function lot(int $productId, int $warehouseId, string $lotNumber, ?string $expiry, float $unitCost = 0): int
    {
        $lotNumber = trim($lotNumber);
        if ($lotNumber === '') {
            throw new BusinessException('Numéro de lot obligatoire pour cet article.');
        }
        $id = DB::value('SELECT id FROM stock_lots WHERE product_id = ? AND warehouse_id = ? AND lot_number = ?', [$productId, $warehouseId, $lotNumber]);
        if ($id) {
            if ($expiry) {
                DB::run('UPDATE stock_lots SET expiry_date = COALESCE(expiry_date, ?) WHERE id = ?', [$expiry, $id]);
            }
            return (int) $id;
        }
        return DB::insert('stock_lots', ['product_id' => $productId, 'warehouse_id' => $warehouseId, 'lot_number' => $lotNumber, 'expiry_date' => $expiry ?: null, 'quantity' => 0, 'unit_cost' => $unitCost]);
    }

    public const COST_MODES = ['average' => 'Prix moyen pondéré', 'new' => 'Nouveau prix', 'old' => 'Ancien prix'];

    /**
     * Entrée valorisée : met à jour le coût de l'article puis ajoute le stock.
     * $costMode : 'average' = coût moyen pondéré (ancien stock + entrée) ;
     *             'new'     = tout le stock est revalorisé au nouveau coût ;
     *             'old'     = le coût actuel est conservé (sauf s'il est nul).
     */
    public static function receive(int $productId, int $warehouseId, float $qty, float $unitCost, string $type, ?string $refType, ?int $refId, ?string $reason = null, ?string $lotNumber = null, ?string $expiry = null, string $costMode = 'average'): ?int
    {
        if ($qty <= 0) {
            throw new BusinessException('La quantité reçue doit être positive.');
        }
        $p = DB::one('SELECT id, track_lots, cost_price FROM products WHERE id = ? FOR UPDATE', [$productId]);
        self::updateCmp($productId, $qty, $unitCost, (string) $type, $costMode);
        $lotId = null;
        if ((int) $p['track_lots'] === 1) {
            $lotId = self::lot($productId, $warehouseId, (string) $lotNumber, $expiry, $unitCost);
        }
        self::move($productId, $warehouseId, $qty, $type, $refType, $refId, $reason, $unitCost, $lotId);
        return $lotId;
    }

    public static function updateCmp(int $productId, float $qtyIn, float $unitCost, string $source, string $mode = 'average'): void
    {
        $p = DB::one('SELECT cost_price FROM products WHERE id = ?', [$productId]);
        $old = (float) $p['cost_price'];
        $stock = max(0.0, self::totalQty($productId));
        $new = match ($mode) {
            'new' => round($unitCost, 2),
            'old' => $old > 0 ? $old : round($unitCost, 2),
            default => $stock + $qtyIn > 0 ? round(($stock * $old + $qtyIn * $unitCost) / ($stock + $qtyIn), 2) : $unitCost,
        };
        if (abs($new - $old) >= 0.01) {
            DB::run('UPDATE products SET cost_price = ? WHERE id = ?', [$new, $productId]);
            DB::insert('price_history', ['product_id' => $productId, 'field' => 'cost_price', 'old_value' => $old, 'new_value' => $new, 'source' => ($mode === 'average' ? 'cmp:' : 'cout_' . $mode . ':') . $source, 'user_id' => Auth::id()]);
        }
    }

    /**
     * Sortie de stock. Pour un article suivi par lot : répartition FEFO
     * (le lot qui expire le plus tôt sort en premier ; lots périmés et rappelés exclus).
     * @return array<int, array{lot_id:?int, qty:float, unit_cost:float}>
     */
    public static function issue(int $productId, int $warehouseId, float $qty, string $type, ?string $refType, ?int $refId, ?string $reason = null, ?int $forcedLotId = null): array
    {
        if ($qty <= 0) {
            throw new BusinessException('Quantité de sortie invalide.');
        }
        $p = DB::one('SELECT id, name, track_lots, track_stock, cost_price FROM products WHERE id = ?', [$productId]);
        if ((int) $p['track_lots'] !== 1) {
            self::move($productId, $warehouseId, -$qty, $type, $refType, $refId, $reason, (float) $p['cost_price']);
            return [['lot_id' => null, 'qty' => $qty, 'unit_cost' => (float) $p['cost_price']]];
        }
        if ($forcedLotId) {
            $lot = DB::one('SELECT unit_cost FROM stock_lots WHERE id = ?', [$forcedLotId]);
            self::move($productId, $warehouseId, -$qty, $type, $refType, $refId, $reason, (float) ($lot['unit_cost'] ?? $p['cost_price']), $forcedLotId);
            return [['lot_id' => $forcedLotId, 'qty' => $qty, 'unit_cost' => (float) ($lot['unit_cost'] ?? 0)]];
        }
        $sellable = in_array($type, ['sale', 'transfer_out'], true);
        $lots = DB::all(
            'SELECT id, quantity, unit_cost, expiry_date FROM stock_lots
             WHERE product_id = ? AND warehouse_id = ? AND quantity > 0'
            . ($sellable ? ' AND recalled = 0 AND (expiry_date IS NULL OR expiry_date >= CURDATE())' : '')
            . ' ORDER BY expiry_date IS NULL, expiry_date ASC, id ASC FOR UPDATE',
            [$productId, $warehouseId]
        );
        $remaining = $qty;
        $alloc = [];
        foreach ($lots as $lot) {
            if ($remaining <= 0.0005) {
                break;
            }
            $take = min($remaining, (float) $lot['quantity']);
            self::move($productId, $warehouseId, -$take, $type, $refType, $refId, $reason, (float) $lot['unit_cost'] ?: (float) $p['cost_price'], (int) $lot['id']);
            $alloc[] = ['lot_id' => (int) $lot['id'], 'qty' => $take, 'unit_cost' => (float) $lot['unit_cost'] ?: (float) $p['cost_price']];
            $remaining = round($remaining - $take, 3);
        }
        if ($remaining > 0.0005) {
            throw new BusinessException(sprintf('Stock vendable insuffisant pour « %s » (lots périmés ou rappelés exclus). Manque : %s.', $p['name'], qty($remaining)));
        }
        return $alloc;
    }

    /** Transfert entre entrepôts (les lots sont conservés). */
    public static function transfer(int $fromWh, int $toWh, array $items, ?string $note): int
    {
        if ($fromWh === $toWh) {
            throw new BusinessException('Les entrepôts de départ et d\'arrivée doivent être différents.');
        }
        if (!$items) {
            throw new BusinessException('Aucun article à transférer.');
        }
        return DB::transaction(function () use ($fromWh, $toWh, $items, $note) {
            $number = Sequence::next('transfer');
            $id = DB::insert('stock_transfers', ['number' => $number, 'from_warehouse_id' => $fromWh, 'to_warehouse_id' => $toWh, 'note' => $note, 'user_id' => Auth::id()]);
            foreach ($items as $it) {
                $pid = (int) $it['product_id'];
                $q = (float) $it['quantity'];
                if ($q <= 0) {
                    throw new BusinessException('Quantité de transfert invalide.');
                }
                DB::insert('stock_transfer_items', ['transfer_id' => $id, 'product_id' => $pid, 'quantity' => $q]);
                $alloc = self::issue($pid, $fromWh, $q, 'transfer_out', 'transfer', $id, $number);
                foreach ($alloc as $a) {
                    $lotId = null;
                    if ($a['lot_id']) {
                        $src = DB::one('SELECT lot_number, expiry_date, unit_cost FROM stock_lots WHERE id = ?', [$a['lot_id']]);
                        $lotId = self::lot($pid, $toWh, $src['lot_number'], $src['expiry_date'], (float) $src['unit_cost']);
                    }
                    self::move($pid, $toWh, $a['qty'], 'transfer_in', 'transfer', $id, $number, $a['unit_cost'], $lotId);
                }
            }
            \App\Core\Audit::log('stock.transfer', 'stock_transfer', $id, ['from' => $fromWh, 'to' => $toWh, 'lines' => count($items)]);
            return $id;
        });
    }

    /**
     * Ajustement justifié. $newQtyOrDelta : delta signé.
     * Types autorisés : adjustment, loss, expired.
     */
    public static function adjust(int $productId, int $warehouseId, float $delta, string $type, string $reason, ?int $lotId = null, ?string $lotNumber = null, ?string $expiry = null): void
    {
        if (!in_array($type, ['adjustment', 'loss', 'expired', 'initial'], true)) {
            throw new BusinessException('Type d\'ajustement invalide.');
        }
        if (trim($reason) === '') {
            throw new BusinessException('Le motif de l\'ajustement est obligatoire.');
        }
        DB::transaction(function () use ($productId, $warehouseId, $delta, $type, $reason, $lotId, $lotNumber, $expiry) {
            $p = DB::one('SELECT track_lots, cost_price FROM products WHERE id = ?', [$productId]);
            if (!$p) {
                throw new BusinessException('Article introuvable.');
            }
            if ($delta > 0) {
                if ((int) $p['track_lots'] === 1 && !$lotId) {
                    $lotId = self::lot($productId, $warehouseId, (string) $lotNumber, $expiry, (float) $p['cost_price']);
                }
                self::move($productId, $warehouseId, $delta, $type, 'adjustment', null, $reason, (float) $p['cost_price'], $lotId);
            } else {
                self::issue($productId, $warehouseId, -$delta, $type, 'adjustment', null, $reason, $lotId);
            }
            \App\Core\Audit::log('stock.' . $type, 'product', $productId, ['warehouse' => $warehouseId, 'delta' => $delta, 'reason' => $reason, 'lot' => $lotId]);
        });
    }

    /** Valeur du stock au CMP. */
    public static function valuation(?int $warehouseId = null): float
    {
        $sql = 'SELECT COALESCE(SUM(sl.quantity * p.cost_price),0) FROM stock_levels sl JOIN products p ON p.id = sl.product_id WHERE sl.quantity > 0';
        $params = [];
        if ($warehouseId) {
            $sql .= ' AND sl.warehouse_id = ?';
            $params[] = $warehouseId;
        }
        return (float) DB::value($sql, $params);
    }
}
