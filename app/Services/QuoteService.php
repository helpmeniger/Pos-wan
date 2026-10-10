<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\BusinessException;
use App\Core\DB;

/**
 * Devis (pro forma) : n'affecte ni le stock ni la caisse. Un devis accepté peut être
 * converti en vente ; c'est alors le moteur de vente commun qui contrôle le stock,
 * encaisse et produit le ticket / la facture.
 */
final class QuoteService
{
    public const STATUSES = ['draft' => 'Brouillon', 'sent' => 'Envoyé', 'accepted' => 'Accepté', 'refused' => 'Refusé', 'converted' => 'Facturé', 'cancelled' => 'Annulé'];

    /** Transitions manuelles autorisées (la conversion passe par convert()). */
    public const TRANSITIONS = [
        'draft' => ['sent', 'accepted', 'refused', 'cancelled'],
        'sent' => ['accepted', 'refused', 'cancelled', 'draft'],
        'accepted' => ['sent', 'refused', 'cancelled'],
        'refused' => ['draft'],
        'cancelled' => ['draft'],
    ];

    /**
     * Contrôle et calcule les lignes. Le prix catalogue s'applique par défaut ; un prix
     * différent exige pos.price_override, une remise exige pos.discount.
     */
    public static function lines(array $rows): array
    {
        $lines = [];
        foreach ($rows as $r) {
            $pid = (int) ($r['product_id'] ?? 0);
            $q = (float) ($r['quantity'] ?? 0);
            if ($pid <= 0) {
                continue;
            }
            if ($q <= 0 || $q > 100000) {
                throw new BusinessException('Quantité invalide sur une ligne du devis.');
            }
            $p = DB::one("SELECT id, name, sale_price, sellable FROM products WHERE id = ? AND status = 'active'", [$pid]);
            if (!$p) {
                throw new BusinessException('Un article du devis est introuvable ou archivé.');
            }
            if ((int) $p['sellable'] === 0) {
                throw new BusinessException(sprintf('« %s » n\'est pas un article vendable.', $p['name']));
            }
            $price = isset($r['unit_price']) && $r['unit_price'] !== '' ? round((float) $r['unit_price'], 2) : (float) $p['sale_price'];
            if ($price < 0) {
                throw new BusinessException('Prix négatif interdit.');
            }
            if (abs($price - (float) $p['sale_price']) >= 0.01 && !Auth::can('pos.price_override')) {
                throw new BusinessException(sprintf('Vous n\'êtes pas autorisé à modifier le prix de « %s ».', $p['name']));
            }
            $gross = round($price * $q, 2);
            $disc = round(max(0.0, (float) ($r['discount'] ?? 0)), 2);
            if ($disc > $gross) {
                throw new BusinessException('Remise supérieure au montant de la ligne.');
            }
            if ($disc > 0 && !Auth::can('pos.discount')) {
                throw new BusinessException('Vous n\'êtes pas autorisé à accorder des remises.');
            }
            $lines[] = ['product_id' => $pid, 'name' => $p['name'], 'quantity' => $q, 'unit_price' => $price, 'discount' => $disc, 'line_total' => round($gross - $disc, 2)];
        }
        if (!$lines) {
            throw new BusinessException('Ajoutez au moins un article au devis.');
        }
        if (count($lines) > 300) {
            throw new BusinessException('Trop de lignes dans le devis.');
        }
        return $lines;
    }

    private static function header(array $d): array
    {
        $customerId = !empty($d['customer_id']) ? (int) $d['customer_id'] : null;
        $c = $customerId ? DB::one('SELECT id, name, phone, address FROM customers WHERE id = ?', [$customerId]) : null;
        if ($customerId && !$c) {
            throw new BusinessException('Client introuvable.');
        }
        $name = trim((string) ($d['customer_name'] ?? '')) ?: ($c['name'] ?? '');
        if ($name === '') {
            throw new BusinessException('Indiquez le client (fiche client ou nom).');
        }
        $valid = (string) ($d['valid_until'] ?? '');
        if ($valid !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $valid)) {
            throw new BusinessException('Date de validité invalide.');
        }
        return [
            'customer_id' => $c['id'] ?? null,
            'customer_name' => mb_substr($name, 0, 150),
            'customer_phone' => mb_substr(trim((string) ($d['customer_phone'] ?? '')) ?: ($c['phone'] ?? ''), 0, 50) ?: null,
            'customer_address' => mb_substr(trim((string) ($d['customer_address'] ?? '')) ?: ($c['address'] ?? ''), 0, 255) ?: null,
            'valid_until' => $valid ?: date('Y-m-d', strtotime('+' . max(1, (int) setting('quote_validity_days', '15')) . ' days')),
            'note' => mb_substr(trim((string) ($d['note'] ?? '')), 0, 2000) ?: null,
        ];
    }

    private static function totals(array $lines): array
    {
        $sub = round(array_sum(array_map(fn($l) => $l['unit_price'] * $l['quantity'], $lines)), 2);
        $disc = round(array_sum(array_column($lines, 'discount')), 2);
        return ['subtotal' => $sub, 'discount_total' => $disc, 'total' => round($sub - $disc, 2)];
    }

    public static function create(array $d, array $rows): int
    {
        $user = Auth::user();
        $head = self::header($d);
        $lines = self::lines($rows);
        $storeId = (int) (DB::value('SELECT store_id FROM cash_registers WHERE id = ?', [$user['register_id'] ?? 0]) ?: DB::value('SELECT id FROM stores ORDER BY id LIMIT 1'));
        return DB::transaction(function () use ($head, $lines, $user, $storeId) {
            $id = DB::insert('quotes', $head + self::totals($lines) + ['number' => Sequence::next('quote'), 'store_id' => $storeId, 'status' => 'draft', 'user_id' => $user['id']]);
            self::saveLines($id, $lines);
            Audit::log('quote.create', 'quote', $id);
            return $id;
        });
    }

    public static function update(int $id, array $d, array $rows): void
    {
        $head = self::header($d);
        $lines = self::lines($rows);
        DB::transaction(function () use ($id, $head, $lines) {
            $q = DB::one('SELECT status FROM quotes WHERE id = ? FOR UPDATE', [$id]);
            if (!$q || !in_array($q['status'], ['draft', 'sent', 'accepted'], true)) {
                throw new BusinessException('Ce devis ne peut plus être modifié.');
            }
            DB::update('quotes', $head + self::totals($lines), 'id = ?', [$id]);
            DB::run('DELETE FROM quote_items WHERE quote_id = ?', [$id]);
            self::saveLines($id, $lines);
            Audit::log('quote.update', 'quote', $id);
        });
    }

    private static function saveLines(int $id, array $lines): void
    {
        foreach ($lines as $l) {
            DB::insert('quote_items', $l + ['quote_id' => $id]);
        }
    }

    public static function setStatus(int $id, string $to): void
    {
        DB::transaction(function () use ($id, $to) {
            $q = DB::one('SELECT status FROM quotes WHERE id = ? FOR UPDATE', [$id]);
            if (!$q || !in_array($to, self::TRANSITIONS[$q['status']] ?? [], true)) {
                throw new BusinessException('Changement de statut non autorisé.');
            }
            DB::update('quotes', ['status' => $to], 'id = ?', [$id]);
            Audit::log('quote.status', 'quote', $id, ['from' => $q['status'], 'to' => $to]);
        });
    }

    /**
     * Transforme le devis en vente (prix du devis conservés, stock et caisse contrôlés
     * par SaleService). Idempotent grâce au client_uuid du formulaire.
     */
    public static function convert(int $id, array $payments, string $uuid, ?array $supervisor = null): array
    {
        return DB::transaction(function () use ($id, $payments, $uuid, $supervisor) {
            $q = DB::one('SELECT * FROM quotes WHERE id = ? FOR UPDATE', [$id]);
            if (!$q) {
                throw new BusinessException('Devis introuvable.');
            }
            if ($q['status'] === 'converted' && $q['sale_id']) {
                $s = DB::one('SELECT id, number, change_due, total FROM sales WHERE id = ?', [$q['sale_id']]);
                return ['sale_id' => (int) $s['id'], 'number' => $s['number'], 'duplicate' => true, 'change' => (float) $s['change_due'], 'total' => (float) $s['total']];
            }
            if (in_array($q['status'], ['refused', 'cancelled'], true)) {
                throw new BusinessException('Un devis refusé ou annulé ne peut pas être facturé.');
            }
            $items = DB::all('SELECT product_id, quantity, unit_price, discount FROM quote_items WHERE quote_id = ? ORDER BY id', [$id]);
            $r = SaleService::create([
                'client_uuid' => $uuid, 'items' => $items, 'customer_id' => $q['customer_id'], 'payments' => $payments,
                'note' => 'Devis ' . $q['number'], 'supervisor' => $supervisor,
            ], ['trusted_prices' => true]);
            DB::update('quotes', ['status' => 'converted', 'sale_id' => $r['sale_id']], 'id = ?', [$id]);
            Audit::log('quote.convert', 'quote', $id, ['sale' => $r['number']]);
            return $r;
        });
    }
}
