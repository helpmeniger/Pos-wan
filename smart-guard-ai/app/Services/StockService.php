<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;

/**
 * Mouvements de stock VALIDÉS. C'est le seul endroit qui modifie products.stock_quantity.
 * Une observation vidéo ne passe jamais ici sans validation par un utilisateur autorisé.
 */
final class StockService
{
    /** Types et signe attendu de la quantité (+1 entrée, -1 sortie, 0 = signe libre). */
    public const TYPES = [
        'reception' => 1, 'vente' => -1, 'retour_client' => 1, 'retour_fournisseur' => -1, 'transfert' => 0,
        'casse' => -1, 'perime' => -1, 'ajustement' => 0, 'sortie_autorisee' => -1, 'mouvement_observe' => -1,
        'mouvement_non_explique' => -1, 'inventaire' => 0,
    ];

    /**
     * @param float $quantity quantité POSITIVE saisie (le signe est déduit du type) ; pour
     *                        « ajustement »/« inventaire », valeur signée acceptée.
     */
    public static function record(int $storeId, int $productId, string $type, float $quantity, array $extra = []): int
    {
        if (!array_key_exists($type, self::TYPES)) {
            throw new \InvalidArgumentException('Type de mouvement inconnu.');
        }
        $sign = self::TYPES[$type];
        $signed = $sign === 0 ? $quantity : $sign * abs($quantity);
        if ($type === 'transfert') {
            $signed = 0.0; // un transfert entre zones ne change pas le stock total du magasin
        }
        if ($signed == 0.0 && $type !== 'transfert') {
            throw new \InvalidArgumentException('La quantité ne peut pas être nulle.');
        }
        $affects = (int) ($extra['affects_stock'] ?? 1);

        return Database::transaction(static function () use ($storeId, $productId, $type, $signed, $extra, $affects) {
            $p = Database::fetch('SELECT id, stock_quantity FROM products WHERE id = :id AND store_id = :s FOR UPDATE', ['id' => $productId, 's' => $storeId]);
            if (!$p) {
                throw new \RuntimeException('Produit introuvable dans ce magasin.');
            }
            $before = (float) $p['stock_quantity'];
            $after = $affects ? $before + $signed : $before;
            $id = Database::insert('inventory_movements', [
                'uuid' => uuid4(), 'store_id' => $storeId, 'product_id' => $productId, 'movement_type' => $type,
                'quantity' => $signed, 'stock_before' => $before, 'stock_after' => $after, 'affects_stock' => $affects,
                'reference' => $extra['reference'] ?? null, 'sale_id' => $extra['sale_id'] ?? null, 'alert_id' => $extra['alert_id'] ?? null,
                'from_zone' => $extra['from_zone'] ?? null, 'to_zone' => $extra['to_zone'] ?? null,
                'comment' => isset($extra['comment']) ? mb_substr((string) $extra['comment'], 0, 500) : null,
                'user_id' => $extra['user_id'] ?? Auth::id(), 'occurred_at' => $extra['occurred_at'] ?? date('Y-m-d H:i:s'),
            ]);
            if ($affects) {
                Database::update('products', ['stock_quantity' => $after], 'id = :id', ['id' => $productId]);
                self::checkThreshold($storeId, $productId, $after);
            }
            Audit::log('stock_mouvement', 'inventory_movement', $id, ['type' => $type, 'qty' => $signed, 'product_id' => $productId]);
            return $id;
        });
    }

    /** Inventaire physique : enregistre l'écart et, si demandé, régularise le stock théorique. */
    public static function physicalCount(int $storeId, int $productId, float $physical, bool $adjust, ?string $comment = null): array
    {
        return Database::transaction(static function () use ($storeId, $productId, $physical, $adjust, $comment) {
            $p = Database::fetch('SELECT stock_quantity FROM products WHERE id = :id AND store_id = :s FOR UPDATE', ['id' => $productId, 's' => $storeId]);
            if (!$p) {
                throw new \RuntimeException('Produit introuvable.');
            }
            $theo = (float) $p['stock_quantity'];
            $variance = $physical - $theo;
            Database::insert('stock_snapshots', [
                'store_id' => $storeId, 'product_id' => $productId, 'snapshot_type' => 'inventaire_physique',
                'theoretical_qty' => $theo, 'physical_qty' => $physical, 'variance' => $variance, 'comment' => $comment,
                'user_id' => Auth::id(), 'taken_at' => date('Y-m-d H:i:s'),
            ]);
            $movementId = null;
            if ($adjust && abs($variance) > 0.0005) {
                $movementId = self::record($storeId, $productId, 'inventaire', $variance, ['comment' => 'Régularisation après inventaire. ' . ($comment ?? '')]);
            }
            return ['theoretical' => $theo, 'physical' => $physical, 'variance' => $variance, 'movement_id' => $movementId];
        });
    }

    private static function checkThreshold(int $storeId, int $productId, float $qty): void
    {
        $p = Database::fetch('SELECT name, stock_alert_threshold FROM products WHERE id = :id', ['id' => $productId]);
        if ($p && (float) $p['stock_alert_threshold'] > 0 && $qty <= (float) $p['stock_alert_threshold']) {
            NotificationService::notify($storeId, 'stock_bas', 'Stock bas : ' . $p['name'], 'warning',
                'Stock théorique : ' . qty($qty) . ' (seuil ' . qty($p['stock_alert_threshold']) . ')', 'stock', 'stock_low_' . $productId, 720);
        }
    }

    /**
     * Tableau de rapprochement stock / ventes / observations vidéo pour une période.
     * Les observations vidéo sont affichées à côté des chiffres comptables, jamais soustraites.
     */
    public static function reconciliation(int $storeId, string $from, string $to, ?int $productId = null): array
    {
        $params = ['s' => $storeId, 'f' => $from . ' 00:00:00', 't' => $to . ' 23:59:59'];
        $where = $productId ? ' AND p.id = :pid' : '';
        if ($productId) {
            $params['pid'] = $productId;
        }
        $rows = Database::fetchAll("SELECT p.id, p.name, p.unit, p.stock_quantity AS current_stock FROM products p
            WHERE p.store_id = :s AND p.deleted_at IS NULL {$where} ORDER BY p.name", array_diff_key($params, ['f' => 1, 't' => 1]));
        $out = [];
        foreach ($rows as $r) {
            $pid = (int) $r['id'];
            $mv = Database::fetchAll('SELECT movement_type, SUM(quantity) q FROM inventory_movements
                WHERE product_id = :p AND affects_stock = 1 AND occurred_at BETWEEN :f AND :t GROUP BY movement_type',
                ['p' => $pid, 'f' => $params['f'], 't' => $params['t']]);
            $byType = array_column($mv, 'q', 'movement_type');
            $after = (float) Database::value('SELECT COALESCE(SUM(quantity),0) FROM inventory_movements WHERE product_id = :p AND affects_stock = 1 AND occurred_at > :t',
                ['p' => $pid, 't' => $params['t']]);
            $periodSum = array_sum(array_map('floatval', $byType));
            $end = (float) $r['current_stock'] - $after;
            $initial = $end - $periodSum;
            $entries = (float) ($byType['reception'] ?? 0) + (float) ($byType['retour_client'] ?? 0);
            $sales = -(float) ($byType['vente'] ?? 0);
            $authorized = -((float) ($byType['sortie_autorisee'] ?? 0) + (float) ($byType['casse'] ?? 0) + (float) ($byType['perime'] ?? 0) + (float) ($byType['retour_fournisseur'] ?? 0));
            $snap = Database::fetch('SELECT physical_qty, variance, taken_at FROM stock_snapshots WHERE product_id = :p AND snapshot_type = "inventaire_physique"
                AND taken_at BETWEEN :f AND :t ORDER BY taken_at DESC LIMIT 1', ['p' => $pid, 'f' => $params['f'], 't' => $params['t']]);
            $exits = Database::fetch("SELECT SUM(direction='sortie') exits, SUM(direction='sortie' AND reconciliation_status IN ('aucune_vente','incertain','en_attente')) unmatched
                FROM exit_events WHERE product_id = :p AND occurred_at BETWEEN :f AND :t", ['p' => $pid, 'f' => $params['f'], 't' => $params['t']]);
            $out[] = [
                'product_id' => $pid, 'name' => $r['name'], 'unit' => $r['unit'], 'initial' => $initial, 'entries' => $entries,
                'sales' => $sales, 'authorized_exits' => $authorized, 'other' => $periodSum - $entries + $sales + $authorized,
                'theoretical' => $end, 'physical' => $snap['physical_qty'] ?? null, 'variance' => $snap['variance'] ?? null,
                'inventory_at' => $snap['taken_at'] ?? null, 'observed_exits' => (int) ($exits['exits'] ?? 0),
                'unmatched_exits' => (int) ($exits['unmatched'] ?? 0),
            ];
        }
        return $out;
    }
}
