<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;

/** Ventes : caisse interne, API externe et import CSV passent tous par ce service. */
final class SaleService
{
    public const PAYMENTS = ['especes', 'mobile_money', 'carte', 'credit', 'autre'];

    /**
     * @param array $items [['product_id'=>int,'quantity'=>float,'unit_price'=>?float], ...]
     * @return array{id:int, receipt:string, total:float, matched_exits:int}
     */
    public static function create(int $storeId, array $items, array $opts = []): array
    {
        if (!$items || count($items) > 200) {
            throw new \InvalidArgumentException('La vente doit contenir entre 1 et 200 lignes.');
        }
        $source = $opts['source'] ?? 'caisse_interne';
        $payment = in_array($opts['payment_method'] ?? 'especes', self::PAYMENTS, true) ? ($opts['payment_method'] ?? 'especes') : 'especes';
        $soldAt = $opts['sold_at'] ?? now_ms();
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(\.\d{1,3})?$/', $soldAt) || strtotime(substr($soldAt, 0, 19)) > time() + 300) {
            throw new \InvalidArgumentException('Date de vente invalide.');
        }
        $external = isset($opts['external_ref']) ? mb_substr((string) $opts['external_ref'], 0, 80) : null;
        if ($external !== null && Database::value('SELECT id FROM sales WHERE store_id = :s AND source = :src AND external_ref = :e', ['s' => $storeId, 'src' => $source, 'e' => $external])) {
            throw new \DomainException('Vente déjà importée (référence ' . $external . ').');
        }

        $result = Database::transaction(static function () use ($storeId, $items, $opts, $source, $payment, $soldAt, $external) {
            $lines = [];
            $total = 0.0;
            foreach ($items as $it) {
                $pid = (int) ($it['product_id'] ?? 0);
                $q = round((float) ($it['quantity'] ?? 0), 3);
                if ($q <= 0 || $q > 100000) {
                    throw new \InvalidArgumentException('Quantité invalide.');
                }
                $p = Database::fetch('SELECT id, name, sale_price FROM products WHERE id = :id AND store_id = :s AND deleted_at IS NULL', ['id' => $pid, 's' => $storeId]);
                if (!$p) {
                    throw new \InvalidArgumentException('Produit inconnu : ' . $pid);
                }
                // le prix est imposé par la fiche produit pour la caisse interne
                $price = $source === 'caisse_interne' || !isset($it['unit_price']) ? (float) $p['sale_price'] : max(0.0, (float) $it['unit_price']);
                $lt = round($price * $q, 2);
                $total += $lt;
                $lines[] = ['product_id' => $pid, 'quantity' => $q, 'unit_price' => $price, 'line_total' => $lt];
            }
            $receipt = 'T' . date('ymd', strtotime(substr($soldAt, 0, 19))) . '-' . strtoupper(bin2hex(random_bytes(3)));
            $paid = isset($opts['amount_paid']) ? max(0.0, (float) $opts['amount_paid']) : $total;
            $saleId = Database::insert('sales', ['uuid' => uuid4(), 'store_id' => $storeId, 'receipt_number' => $receipt, 'source' => $source,
                'external_ref' => $external, 'cashier_id' => $opts['cashier_id'] ?? Auth::id(), 'status' => 'validee', 'total_amount' => $total,
                'payment_method' => $payment, 'amount_paid' => $paid, 'sold_at' => $soldAt, 'is_demo' => (int) ($opts['is_demo'] ?? 0)]);
            foreach ($lines as $l) {
                Database::insert('sale_items', $l + ['sale_id' => $saleId]);
                StockService::record($storeId, $l['product_id'], 'vente', $l['quantity'], ['sale_id' => $saleId, 'reference' => $receipt,
                    'occurred_at' => substr($soldAt, 0, 19), 'user_id' => $opts['cashier_id'] ?? Auth::id()]);
            }
            return ['id' => $saleId, 'receipt' => $receipt, 'total' => $total, 'paid' => $paid];
        });
        $result['matched_exits'] = ReconciliationService::onSale($result['id']);
        Audit::log('vente', 'sale', $result['id'], ['total' => $result['total'], 'source' => $source]);
        return $result;
    }

    public static function cancel(int $storeId, int $saleId, string $reason): void
    {
        Database::transaction(static function () use ($storeId, $saleId, $reason) {
            $sale = Database::fetch('SELECT * FROM sales WHERE id = :id AND store_id = :s FOR UPDATE', ['id' => $saleId, 's' => $storeId]);
            if (!$sale || $sale['status'] !== 'validee') {
                throw new \DomainException('Seule une vente validée sans retour peut être annulée.');
            }
            foreach (Database::fetchAll('SELECT * FROM sale_items WHERE sale_id = :id', ['id' => $saleId]) as $it) {
                StockService::record($storeId, (int) $it['product_id'], 'ajustement', (float) $it['quantity'],
                    ['sale_id' => $saleId, 'reference' => $sale['receipt_number'], 'comment' => 'Annulation de la vente ' . $sale['receipt_number']]);
            }
            Database::update('sales', ['status' => 'annulee', 'cancelled_at' => date('Y-m-d H:i:s'), 'cancel_reason' => mb_substr($reason, 0, 255)], 'id = :id', ['id' => $saleId]);
            ReconciliationService::onSaleCancelled($saleId);
        });
        Audit::log('vente_annulee', 'sale', $saleId, ['motif' => $reason]);
    }

    /** Retour client partiel ou total sur une ligne de vente. */
    public static function refund(int $storeId, int $saleId, int $itemId, float $qty, string $reason): void
    {
        Database::transaction(static function () use ($storeId, $saleId, $itemId, $qty, $reason) {
            $sale = Database::fetch('SELECT * FROM sales WHERE id = :id AND store_id = :s FOR UPDATE', ['id' => $saleId, 's' => $storeId]);
            $it = Database::fetch('SELECT * FROM sale_items WHERE id = :i AND sale_id = :s FOR UPDATE', ['i' => $itemId, 's' => $saleId]);
            if (!$sale || !$it || $sale['status'] === 'annulee') {
                throw new \DomainException('Vente ou ligne introuvable.');
            }
            $remaining = (float) $it['quantity'] - (float) $it['returned_quantity'];
            if ($qty <= 0 || $qty > $remaining + 0.0005) {
                throw new \DomainException('Quantité de retour invalide (maximum ' . qty($remaining) . ').');
            }
            Database::update('sale_items', ['returned_quantity' => (float) $it['returned_quantity'] + $qty], 'id = :id', ['id' => $itemId]);
            StockService::record($storeId, (int) $it['product_id'], 'retour_client', $qty, ['sale_id' => $saleId, 'reference' => $sale['receipt_number'], 'comment' => $reason]);
            $left = (float) Database::value('SELECT SUM(quantity - returned_quantity) FROM sale_items WHERE sale_id = :s', ['s' => $saleId]);
            Database::update('sales', ['status' => $left <= 0.0005 ? 'remboursee' : 'partiellement_remboursee'], 'id = :id', ['id' => $saleId]);
        });
        Audit::log('retour_client', 'sale', $saleId, ['ligne' => $itemId, 'quantite' => $qty, 'motif' => $reason]);
    }
}
