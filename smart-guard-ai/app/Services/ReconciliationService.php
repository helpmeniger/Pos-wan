<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Settings;

/**
 * Rapprochement entre sorties observées par caméra et ventes enregistrées en caisse.
 *
 * Règle : une sortie d'un produit identifié est rapprochée d'une ligne de vente du même produit
 * dont l'heure est comprise dans [sortie - fenêtre_avant ; sortie + fenêtre_après] et dont la
 * quantité n'est pas encore entièrement couverte par d'autres sorties. La vente la plus proche
 * (précédant la sortie de préférence) est retenue. Ce rapprochement est une HYPOTHÈSE de
 * travail : il peut se tromper (achats groupés, horloges décalées, article non visible).
 */
final class ReconciliationService
{
    public static function enabled(int $storeId): bool
    {
        return (bool) Settings::get('recon.enabled', true, $storeId);
    }

    /** @return array{status:string, sale_item_id:?int, note:string, sales_nearby:int} */
    public static function matchExit(array $exit): array
    {
        $storeId = (int) $exit['store_id'];
        if (!self::enabled($storeId)) {
            return ['status' => 'non_applicable', 'sale_item_id' => null, 'note' => 'Rapprochement avec la caisse désactivé.', 'sales_nearby' => 0];
        }
        if ($exit['direction'] !== 'sortie') {
            return ['status' => 'non_applicable', 'sale_item_id' => null, 'note' => 'Événement non concerné par le rapprochement.', 'sales_nearby' => 0];
        }
        if (empty($exit['product_id'])) {
            return ['status' => 'incertain', 'sale_item_id' => null, 'note' => 'Produit non identifié : rapprochement impossible.', 'sales_nearby' => 0];
        }
        $before = (int) Settings::get('recon.window_before_sec', 900, $storeId);
        $after = (int) Settings::get('recon.window_after_sec', 120, $storeId);
        $ts = strtotime((string) $exit['occurred_at']);
        $from = date('Y-m-d H:i:s', $ts - $before);
        $to = date('Y-m-d H:i:s', $ts + $after);

        return Database::transaction(static function () use ($exit, $storeId, $from, $to, $ts, $before) {
            $candidates = Database::fetchAll(
                "SELECT si.id, si.quantity, si.returned_quantity, si.matched_exit_count, s.sold_at
                 FROM sale_items si JOIN sales s ON s.id = si.sale_id
                 WHERE s.store_id = :s AND si.product_id = :p AND s.status IN ('validee','partiellement_remboursee')
                   AND s.sold_at BETWEEN :f AND :t
                   AND si.matched_exit_count < FLOOR(si.quantity - si.returned_quantity)
                 ORDER BY ABS(TIMESTAMPDIFF(SECOND, s.sold_at, :ts)) ASC, (s.sold_at <= :ts2) DESC
                 FOR UPDATE",
                ['s' => $storeId, 'p' => (int) $exit['product_id'], 'f' => $from, 't' => $to,
                 'ts' => date('Y-m-d H:i:s', $ts), 'ts2' => date('Y-m-d H:i:s', $ts)]);
            $nearby = (int) Database::value(
                "SELECT COUNT(*) FROM sale_items si JOIN sales s ON s.id = si.sale_id
                 WHERE s.store_id = :s AND si.product_id = :p AND s.status <> 'annulee' AND s.sold_at BETWEEN :f AND :t",
                ['s' => $storeId, 'p' => (int) $exit['product_id'], 'f' => date('Y-m-d H:i:s', $ts - 2 * $before), 't' => date('Y-m-d H:i:s', $ts + 2 * $before)]);
            if (!$candidates) {
                return ['status' => 'aucune_vente', 'sale_item_id' => null, 'sales_nearby' => $nearby,
                    'note' => 'Aucune vente non rapprochée de ce produit dans la fenêtre de temps configurée.'];
            }
            $c = $candidates[0];
            Database::execute('UPDATE sale_items SET matched_exit_count = matched_exit_count + 1 WHERE id = :id', ['id' => $c['id']]);
            $delta = $ts - strtotime((string) $c['sold_at']);
            return ['status' => 'vente_trouvee', 'sale_item_id' => (int) $c['id'], 'sales_nearby' => $nearby,
                'note' => sprintf('Vente enregistrée %s la sortie (écart %d s).', $delta >= 0 ? 'avant' : 'après', abs($delta))];
        });
    }

    /**
     * Après l'enregistrement d'une vente : rapproche les sorties encore non rapprochées
     * (cas d'une caisse enregistrée après le passage de la porte, ou d'une horloge décalée).
     */
    public static function onSale(int $saleId): int
    {
        $sale = Database::fetch('SELECT * FROM sales WHERE id = :id', ['id' => $saleId]);
        if (!$sale || !self::enabled((int) $sale['store_id'])) {
            return 0;
        }
        $storeId = (int) $sale['store_id'];
        $before = (int) Settings::get('recon.window_before_sec', 900, $storeId);
        $after = (int) Settings::get('recon.window_after_sec', 120, $storeId);
        $ts = strtotime((string) $sale['sold_at']);
        $matched = 0;
        foreach (Database::fetchAll('SELECT * FROM sale_items WHERE sale_id = :id', ['id' => $saleId]) as $item) {
            $free = (int) floor((float) $item['quantity'] - (float) $item['returned_quantity']) - (int) $item['matched_exit_count'];
            if ($free <= 0) {
                continue;
            }
            $exits = Database::fetchAll(
                "SELECT id FROM exit_events WHERE store_id = :s AND product_id = :p AND direction = 'sortie'
                   AND reconciliation_status = 'aucune_vente' AND occurred_at BETWEEN :f AND :t
                 ORDER BY ABS(TIMESTAMPDIFF(SECOND, occurred_at, :ts)) LIMIT " . $free,
                ['s' => $storeId, 'p' => $item['product_id'], 'f' => date('Y-m-d H:i:s', $ts - $after), 't' => date('Y-m-d H:i:s', $ts + $before),
                 'ts' => date('Y-m-d H:i:s', $ts)]);
            foreach ($exits as $ex) {
                Database::transaction(static function () use ($ex, $item) {
                    Database::update('exit_events', ['reconciliation_status' => 'vente_trouvee', 'matched_sale_item_id' => $item['id'],
                        'reconciliation_note' => 'Vente enregistrée après la sortie observée (rapprochement différé).'], 'id = :id', ['id' => $ex['id']]);
                    Database::execute('UPDATE sale_items SET matched_exit_count = matched_exit_count + 1 WHERE id = :id', ['id' => $item['id']]);
                    AlertService::onSaleFound((int) $ex['id']);
                });
                $matched++;
            }
        }
        return $matched;
    }

    /** Annulation/remboursement : les sorties rapprochées de cette vente redeviennent « à vérifier ». */
    public static function onSaleCancelled(int $saleId): void
    {
        $items = Database::fetchAll('SELECT id FROM sale_items WHERE sale_id = :id', ['id' => $saleId]);
        foreach ($items as $it) {
            $exits = Database::fetchAll('SELECT id FROM exit_events WHERE matched_sale_item_id = :i', ['i' => $it['id']]);
            foreach ($exits as $ex) {
                Database::update('exit_events', ['reconciliation_status' => 'aucune_vente', 'matched_sale_item_id' => null,
                    'reconciliation_note' => 'La vente rapprochée a été annulée.'], 'id = :id', ['id' => $ex['id']]);
                $event = Database::fetch('SELECT * FROM exit_events WHERE id = :id', ['id' => $ex['id']]);
                AlertService::createForExit($event, ['status' => 'aucune_vente', 'note' => 'La vente rapprochée a été annulée.', 'sales_nearby' => 0]);
            }
            Database::update('sale_items', ['matched_exit_count' => 0], 'id = :id', ['id' => $it['id']]);
        }
    }
}
