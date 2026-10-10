<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

/**
 * Préparation des données des documents imprimés (tickets, factures, devis, bons de
 * livraison) indépendamment du modèle graphique choisi dans les paramètres.
 */
final class Documents
{
    public const TICKET_TEMPLATES = ['1' => 'Classique', '2' => 'Compact', '3' => 'Moderne'];
    public const A4_TEMPLATES = ['1' => 'Classique', '2' => 'Moderne (bandeau)', '3' => 'Épuré'];

    public static function ticketTemplate(?string $n = null): string
    {
        $n ??= (string) setting('ticket_template', '1');
        return 'documents/ticket_' . (isset(self::TICKET_TEMPLATES[$n]) ? $n : '1');
    }

    public static function a4Template(?string $n = null): string
    {
        $n ??= (string) setting('invoice_template', '1');
        return 'documents/a4_' . (isset(self::A4_TEMPLATES[$n]) ? $n : '1');
    }

    /** Vente fictive pour l'aperçu des modèles dans les paramètres (rien n'est enregistré). */
    public static function sample(): array
    {
        $s = ['id' => 0, 'number' => 'V' . date('Y') . '-000123', 'created_at' => date('Y-m-d H:i:s'), 'status' => 'completed', 'store_name' => (string) DB::value('SELECT name FROM stores ORDER BY id LIMIT 1'),
            'register_name' => 'Caisse 1', 'cashier' => 'Aïcha', 'customer_name' => 'Client exemple', 'customer_phone' => '+227 90 00 00 00', 'customer_address' => 'Niamey', 'customer_nif' => null,
            'subtotal' => 7850, 'discount_total' => 350, 'total' => 7500, 'tax_total' => 0, 'tendered' => 10000, 'change_due' => 2500, 'credit_amount' => 0, 'note' => null];
        $items = [
            ['reference' => 'RIZ25', 'product_name' => 'Riz parfumé 5 kg', 'quantity' => 1, 'unit' => 'sac', 'unit_price' => 4500, 'discount' => 350, 'line_total' => 4150],
            ['reference' => 'HUI1', 'product_name' => 'Huile végétale 1 L', 'quantity' => 2, 'unit' => 'btl', 'unit_price' => 1200, 'discount' => 0, 'line_total' => 2400],
            ['reference' => 'SUC1', 'product_name' => 'Sucre en morceaux', 'quantity' => 1, 'unit' => 'pqt', 'unit_price' => 950, 'discount' => 0, 'line_total' => 950],
        ];
        return [$s, $items, [['method' => 'cash', 'provider' => null, 'reference' => null, 'amount' => 7500]]];
    }

    private static function qr(string $code): ?string
    {
        return setting('receipt_qr', '1') === '1' ? $code : null;
    }

    private static function paymentLabel(array $p): string
    {
        return t(method_label($p['method'])) . (!empty($p['provider']) ? ' ' . $p['provider'] : '') . (!empty($p['reference']) ? ' (' . $p['reference'] . ')' : '');
    }

    /**
     * Détail de la TVA par taux à partir des lignes (tax_rate, tax_amount ou line_total TTC).
     * @return array<int, array{rate:float, base:float, tax:float, ttc:float}>
     */
    public static function vatBreakdown(array $items): array
    {
        $by = [];
        foreach ($items as $i) {
            $rate = (float) ($i['tax_rate'] ?? 0);
            if ($rate <= 0) {
                continue;
            }
            $ttc = (float) $i['line_total'];
            $tax = isset($i['tax_amount']) ? (float) $i['tax_amount'] : Tax::included($ttc, $rate);
            $k = (string) $rate;
            $by[$k] ??= ['rate' => $rate, 'base' => 0.0, 'tax' => 0.0, 'ttc' => 0.0];
            $by[$k]['tax'] += $tax;
            $by[$k]['ttc'] += $ttc;
            $by[$k]['base'] += $ttc - $tax;
        }
        ksort($by, SORT_NUMERIC);
        return array_values($by);
    }

    public static function saleTicket(array $s, array $items, array $payments, bool $dup): array
    {
        $totals = [];
        if ((float) $s['discount_total'] > 0) {
            $totals[] = [t('Sous-total'), money($s['subtotal'])];
            $totals[] = [t('Remise'), '-' . money($s['discount_total'])];
        }
        $totals[] = ['TOTAL', money($s['total']), true];
        foreach (self::vatBreakdown($items) as $v) {
            $totals[] = [t('dont') . ' ' . Tax::rateLabel($v['rate']), money($v['tax'])];
        }
        $pay = array_map(fn($p) => [self::paymentLabel($p), money($p['amount'])], $payments);
        $pay[] = [t('Montant remis'), money($s['tendered'])];
        $pay[] = [t('Monnaie rendue'), money($s['change_due']), true];
        if ((float) $s['credit_amount'] > 0) {
            $pay[] = [t('Reste dû (crédit)'), money($s['credit_amount']), true];
        }
        $meta = [[t('Date'), fdate($s['created_at'])], [t('Caisse'), $s['register_name'] ?? ''], [t('Caissier'), $s['cashier']]];
        if (!empty($s['customer_name'])) {
            $meta[] = [t('Client'), $s['customer_name']];
        }
        return [
            'title' => t('Ticket'), 'number' => $s['number'], 'date' => fdate($s['created_at']), 'store' => $s['store_name'] ?? '', 'meta' => $meta, 'dup' => $dup,
            'banner' => $s['status'] === 'cancelled' ? t('VENTE ANNULÉE') : null,
            'items' => array_map(fn($i) => ['name' => $i['product_name'], 'qty' => (float) $i['quantity'], 'price' => (float) $i['unit_price'], 'discount' => (float) $i['discount'], 'total' => (float) $i['line_total']], $items),
            'totals' => $totals, 'payments' => $pay, 'footer' => (string) setting('ticket_footer', 'Merci de votre visite !'), 'qr' => self::qr(DocumentRef::saleQr($s)),
            'count' => array_sum(array_column($items, 'quantity')),
        ];
    }

    private static function customerParty(array $s, string $label): array
    {
        return ['label' => $label, 'lines' => [$s['customer_name'] ?? t('Client comptoir'), $s['customer_phone'] ?? null, $s['customer_address'] ?? null, !empty($s['customer_nif']) ? 'NIF : ' . $s['customer_nif'] : null]];
    }

    public static function saleA4(array $s, array $items, array $payments, string $kind = 'invoice', bool $withPrices = true): array
    {
        $isInvoice = $kind === 'invoice';
        $totals = [];
        if ($withPrices) {
            $totals[] = [t('Sous-total'), money($s['subtotal'])];
            if ((float) $s['discount_total'] > 0) {
                $totals[] = [t('Remises'), '− ' . money($s['discount_total'])];
            }
            if ((float) $s['tax_total'] > 0) {
                $totals[] = [t('Total HT'), money((float) $s['total'] - (float) $s['tax_total'])];
                foreach (self::vatBreakdown($items) as $v) {
                    $totals[] = [Tax::rateLabel($v['rate']) . ' (' . t('base') . ' ' . money($v['base']) . ')', money($v['tax'])];
                }
            }
            $totals[] = [t('TOTAL TTC'), money($s['total']), true];
            if ($isInvoice) {
                foreach ($payments as $p) {
                    $totals[] = [self::paymentLabel($p), money($p['amount'])];
                }
            }
        }
        return [
            'title' => $isInvoice ? ($s['status'] === 'cancelled' ? t('FACTURE ANNULÉE') : t('FACTURE')) : t('BON DE LIVRAISON'),
            'number' => $isInvoice ? $s['number'] : 'BL-' . $s['number'], 'date' => $s['created_at'],
            'banner' => $s['status'] === 'cancelled' ? t('VENTE ANNULÉE') : null,
            'party' => self::customerParty($s, $isInvoice ? t('Client') : t('Livré à')),
            'info' => array_values(array_filter([[t('Boutique'), $s['store_name']], [t('Vendeur'), $s['cashier']], [t('Caisse'), $s['register_name'] ?? ''], $isInvoice ? null : [t('Facture'), $s['number']]])),
            'prices' => $withPrices,
            'lines' => array_map(fn($i) => ['ref' => $i['reference'] ?? '', 'name' => $i['product_name'], 'qty' => (float) $i['quantity'], 'unit' => $i['unit'] ?? '', 'price' => (float) $i['unit_price'], 'discount' => (float) $i['discount'], 'total' => (float) $i['line_total']], $items),
            'totals' => $totals,
            'words' => $isInvoice ? t('Arrêtée la présente facture à la somme de :') . ' ' . ucfirst(amount_words((float) $s['total'])) . ' ' . currency()['name'] . '.' : null,
            'notes' => $s['note'] ?? null,
            'signatures' => $isInvoice ? [t('Le client'), t('Pour') . ' ' . setting('company_name')] : [t('Livré par'), t('Reçu par (nom, date, signature)')],
            'footer' => (string) setting('invoice_footer', setting('ticket_footer', '')), 'qr' => self::qr(DocumentRef::saleQr($s)),
        ];
    }

    public static function quoteA4(array $q, array $items): array
    {
        $totals = [[t('Sous-total'), money($q['subtotal'])]];
        if ((float) $q['discount_total'] > 0) {
            $totals[] = [t('Remises'), '− ' . money($q['discount_total'])];
        }
        $qItems = array_map(fn($i) => ['line_total' => $i['line_total'], 'tax_rate' => Tax::rate($i)], $items);
        $vat = self::vatBreakdown($qItems);
        if ($vat) {
            $totals[] = [t('Total HT'), money((float) $q['total'] - array_sum(array_column($vat, 'tax')))];
            foreach ($vat as $v) {
                $totals[] = [Tax::rateLabel($v['rate']), money($v['tax'])];
            }
        }
        $totals[] = [t('TOTAL TTC'), money($q['total']), true];
        $banner = match ($q['status']) {
            'cancelled' => t('DEVIS ANNULÉ'), 'refused' => t('DEVIS REFUSÉ'), 'converted' => t('DEVIS FACTURÉ'), default => ($q['valid_until'] && $q['valid_until'] < date('Y-m-d') ? t('DEVIS EXPIRÉ') : null),
        };
        return [
            'title' => t('DEVIS'), 'number' => $q['number'], 'date' => $q['created_at'], 'banner' => $banner,
            'party' => ['label' => t('Client'), 'lines' => [$q['customer'] ?? $q['customer_name'] ?? '', $q['customer_phone'] ?? null, $q['customer_address'] ?? null]],
            'info' => [[t('Valable jusqu\'au'), fdate($q['valid_until'], false)], [t('Établi par'), $q['user_name'] ?? '']],
            'prices' => true,
            'lines' => array_map(fn($i) => ['ref' => $i['reference'] ?? '', 'name' => $i['name'], 'qty' => (float) $i['quantity'], 'price' => (float) $i['unit_price'], 'discount' => (float) $i['discount'], 'total' => (float) $i['line_total']], $items),
            'totals' => $totals,
            'words' => t('Arrêté le présent devis à la somme de :') . ' ' . ucfirst(amount_words((float) $q['total'])) . ' ' . currency()['name'] . '.',
            'notes' => $q['note'] ?: t('Prix valables jusqu\'à la date indiquée, sous réserve de disponibilité du stock.'),
            'signatures' => [t('Bon pour accord (client)'), t('Pour') . ' ' . setting('company_name')],
            'footer' => (string) setting('invoice_footer', ''), 'qr' => self::qr(DocumentRef::quoteQr($q)),
        ];
    }
}
