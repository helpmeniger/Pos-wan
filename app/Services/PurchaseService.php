<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\BusinessException;
use App\Core\DB;
use App\Core\Validator;

/**
 * Achats : bons de commande (n'affectent PAS le stock) et réceptions
 * (augmentent le stock, mettent à jour le CMP et la dette fournisseur).
 * Les frais d'approche (transport...) sont répartis au prorata de la valeur.
 */
final class PurchaseService
{
    public static function createOrder(int $supplierId, int $warehouseId, array $items, ?string $expected, ?string $note, string $status = 'ordered'): int
    {
        $items = array_values(array_filter($items, fn($i) => (float) ($i['quantity'] ?? 0) > 0 && (int) ($i['product_id'] ?? 0) > 0));
        if (!$items) {
            throw new BusinessException('Ajoutez au moins un article à la commande.');
        }
        return DB::transaction(function () use ($supplierId, $warehouseId, $items, $expected, $note, $status) {
            $number = Sequence::next('purchase_order');
            $total = 0.0;
            foreach ($items as $i) {
                if ((float) ($i['unit_cost'] ?? 0) < 0) {
                    throw new BusinessException('Coût unitaire invalide.');
                }
                $total += (float) $i['quantity'] * (float) $i['unit_cost'];
            }
            $id = DB::insert('purchase_orders', [
                'number' => $number, 'supplier_id' => $supplierId, 'warehouse_id' => $warehouseId,
                'status' => in_array($status, ['draft', 'ordered'], true) ? $status : 'ordered',
                'expected_date' => $expected && Validator::isDate($expected) ? $expected : null,
                'total' => round($total, 2), 'note' => $note, 'user_id' => Auth::id(),
            ]);
            foreach ($items as $i) {
                DB::insert('purchase_order_items', ['purchase_order_id' => $id, 'product_id' => (int) $i['product_id'], 'quantity' => (float) $i['quantity'], 'unit_cost' => (float) $i['unit_cost']]);
            }
            Audit::log('purchase.order', 'purchase_order', $id, ['number' => $number, 'total' => $total]);
            return $id;
        });
    }

    /**
     * Réception de marchandises (avec ou sans bon de commande).
     * $items : [{product_id, quantity, unit_cost, lot_number?, expiry_date?, po_item_id?}]
     */
    public static function receive(int $supplierId, int $warehouseId, array $items, float $extraCosts, ?int $poId, ?string $invoiceRef, ?string $dueDate, ?string $note, bool $updateSalePrices = false): int
    {
        $items = array_values(array_filter($items, fn($i) => (float) ($i['quantity'] ?? 0) > 0));
        if (!$items) {
            throw new BusinessException('Aucune quantité reçue.');
        }
        if ($extraCosts < 0) {
            throw new BusinessException('Frais invalides.');
        }
        return DB::transaction(function () use ($supplierId, $warehouseId, $items, $extraCosts, $poId, $invoiceRef, $dueDate, $note) {
            if ($poId) {
                $po = DB::one('SELECT * FROM purchase_orders WHERE id = ? FOR UPDATE', [$poId]);
                if (!$po || in_array($po['status'], ['received', 'cancelled'], true)) {
                    throw new BusinessException('Ce bon de commande ne peut plus être réceptionné.');
                }
                $supplierId = (int) $po['supplier_id'];
                $warehouseId = (int) $po['warehouse_id'];
            }
            $goods = 0.0;
            foreach ($items as $i) {
                $goods += (float) $i['quantity'] * (float) $i['unit_cost'];
            }
            $number = Sequence::next('reception');
            $recId = DB::insert('receptions', [
                'number' => $number, 'purchase_order_id' => $poId, 'supplier_id' => $supplierId, 'warehouse_id' => $warehouseId,
                'supplier_invoice_ref' => $invoiceRef ?: null, 'goods_total' => round($goods, 2), 'extra_costs' => round($extraCosts, 2),
                'total' => round($goods + $extraCosts, 2), 'due_date' => $dueDate && Validator::isDate($dueDate) ? $dueDate : null,
                'note' => $note, 'user_id' => Auth::id(),
            ]);
            foreach ($items as $i) {
                $pid = (int) $i['product_id'];
                $q = (float) $i['quantity'];
                $cost = (float) $i['unit_cost'];
                $p = DB::one('SELECT id, name, track_lots FROM products WHERE id = ?', [$pid]);
                if (!$p) {
                    throw new BusinessException('Article introuvable.');
                }
                $lotNumber = trim((string) ($i['lot_number'] ?? ''));
                $expiry = (string) ($i['expiry_date'] ?? '');
                if ((int) $p['track_lots'] === 1) {
                    if ($lotNumber === '') {
                        throw new BusinessException('Numéro de lot obligatoire pour « ' . $p['name'] . ' ».');
                    }
                    if (!Validator::isDate($expiry)) {
                        throw new BusinessException('Date de péremption obligatoire pour « ' . $p['name'] . ' ».');
                    }
                }
                // Coût de revient = coût d'achat + quote-part des frais d'approche
                $landed = $goods > 0 ? round($cost + $extraCosts * ($cost * $q / $goods) / $q, 2) : $cost;
                $poItemId = null;
                if ($poId && !empty($i['po_item_id'])) {
                    $poi = DB::one('SELECT * FROM purchase_order_items WHERE id = ? AND purchase_order_id = ? FOR UPDATE', [(int) $i['po_item_id'], $poId]);
                    if (!$poi) {
                        throw new BusinessException('Ligne de commande invalide.');
                    }
                    $poItemId = (int) $poi['id'];
                    DB::run('UPDATE purchase_order_items SET received_qty = received_qty + ? WHERE id = ?', [$q, $poItemId]);
                }
                $lotId = StockService::receive($pid, $warehouseId, $q, $landed, 'reception', 'reception', $recId, $number, $lotNumber ?: null, Validator::isDate($expiry) ? $expiry : null);
                DB::insert('reception_items', [
                    'reception_id' => $recId, 'po_item_id' => $poItemId, 'product_id' => $pid, 'quantity' => $q, 'unit_cost' => $cost,
                    'landed_unit_cost' => $landed, 'lot_number' => $lotNumber ?: null, 'expiry_date' => Validator::isDate($expiry) ? $expiry : null, 'lot_id' => $lotId,
                ]);
            }
            if ($poId) {
                $left = (float) DB::value('SELECT COALESCE(SUM(GREATEST(quantity - received_qty, 0)),0) FROM purchase_order_items WHERE purchase_order_id = ?', [$poId]);
                DB::run('UPDATE purchase_orders SET status = ? WHERE id = ?', [$left <= 0.0005 ? 'received' : 'partial', $poId]);
            }
            SupplierService::ledger($supplierId, 'invoice', round($goods + $extraCosts, 2), null, 'reception', $recId, 'Réception ' . $number . ($invoiceRef ? ' / Fact. ' . $invoiceRef : ''), $dueDate ?: null);
            Audit::log('purchase.reception', 'reception', $recId, ['number' => $number, 'total' => $goods + $extraCosts, 'po' => $poId]);
            return $recId;
        });
    }

    /** Retour fournisseur : sortie de stock + diminution de la dette. */
    public static function supplierReturn(int $supplierId, int $warehouseId, array $items, string $reason): int
    {
        if (trim($reason) === '') {
            throw new BusinessException('Le motif est obligatoire.');
        }
        $items = array_values(array_filter($items, fn($i) => (float) ($i['quantity'] ?? 0) > 0));
        if (!$items) {
            throw new BusinessException('Aucun article à retourner.');
        }
        return DB::transaction(function () use ($supplierId, $warehouseId, $items, $reason) {
            $number = Sequence::next('supplier_return');
            $id = DB::insert('supplier_returns', ['number' => $number, 'supplier_id' => $supplierId, 'warehouse_id' => $warehouseId, 'total' => 0, 'reason' => $reason, 'user_id' => Auth::id()]);
            $total = 0.0;
            foreach ($items as $i) {
                $pid = (int) $i['product_id'];
                $q = (float) $i['quantity'];
                $cost = (float) ($i['unit_cost'] ?? DB::value('SELECT cost_price FROM products WHERE id = ?', [$pid]));
                $lotId = !empty($i['lot_id']) ? (int) $i['lot_id'] : null;
                StockService::issue($pid, $warehouseId, $q, 'supplier_return', 'supplier_return', $id, $number . ' ' . $reason, $lotId);
                DB::insert('supplier_return_items', ['supplier_return_id' => $id, 'product_id' => $pid, 'lot_id' => $lotId, 'quantity' => $q, 'unit_cost' => $cost]);
                $total += $q * $cost;
            }
            DB::run('UPDATE supplier_returns SET total = ? WHERE id = ?', [round($total, 2), $id]);
            SupplierService::ledger($supplierId, 'return', -round($total, 2), null, 'supplier_return', $id, 'Retour ' . $number);
            Audit::log('purchase.supplier_return', 'supplier_return', $id, ['total' => $total]);
            return $id;
        });
    }
}
