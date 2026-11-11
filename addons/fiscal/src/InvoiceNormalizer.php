<?php
declare(strict_types=1);

namespace Addons\Fiscal;

use App\Core\BusinessException;
use App\Core\DB;

/**
 * Construit une facture normalisée, neutre vis-à-vis du dispositif, à partir d'une vente ou d'un avoir.
 * Les pilotes transforment ensuite cette structure au format exigé par leur protocole.
 * Les groupes de taxe officiels viennent uniquement de fiscal_tax_rates (configurés d'après les textes).
 */
final class InvoiceNormalizer
{
    public static function invoiceKey(array $sale): string
    {
        return hash('sha256', 'fiscal|invoice|' . $sale['id'] . '|' . $sale['client_uuid']);
    }

    public static function creditKey(array $ret): string
    {
        return hash('sha256', 'fiscal|credit_note|' . $ret['id'] . '|' . $ret['number']);
    }

    public static function sale(int $saleId): array
    {
        $s = DB::one('SELECT s.*, st.name AS store_name, st.address AS store_address, r.name AS register_name, u.full_name AS cashier,
                c.name AS customer_name, c.nif AS customer_nif, c.phone AS customer_phone, c.address AS customer_address
            FROM sales s JOIN stores st ON st.id = s.store_id JOIN users u ON u.id = s.user_id
            LEFT JOIN cash_registers r ON r.id = s.register_id LEFT JOIN customers c ON c.id = s.customer_id WHERE s.id = ?', [$saleId]);
        if (!$s) {
            throw new BusinessException('Vente introuvable.');
        }
        return $s;
    }

    /** @param array $profile ligne fiscal_profiles */
    public static function fromSale(int $saleId, array $profile): array
    {
        $s = self::sale($saleId);
        $items = DB::all('SELECT si.*, p.reference FROM sale_items si LEFT JOIN products p ON p.id = si.product_id WHERE si.sale_id = ? ORDER BY si.id', [$saleId]);
        $lines = array_map(fn($i) => [
            'ref' => (string) ($i['reference'] ?? ''), 'name' => $i['product_name'], 'qty' => (float) $i['quantity'],
            'unit_price_ttc' => (float) $i['unit_price'], 'discount' => (float) $i['discount'], 'total_ttc' => (float) $i['line_total'],
            'tax_rate' => (float) $i['tax_rate'], 'tax_amount' => (float) $i['tax_amount'], 'total_ht' => round((float) $i['line_total'] - (float) $i['tax_amount'], 2),
        ], $items);
        $payments = array_map(fn($p) => ['method' => $p['method'], 'provider' => $p['provider'], 'amount' => (float) $p['amount']],
            DB::all('SELECT method, provider, amount FROM sale_payments WHERE sale_id = ? ORDER BY id', [$saleId]));
        return self::assemble('invoice', self::invoiceKey($s), $s['number'], $s, $lines, $payments, $profile, null);
    }

    /** @param array $original ligne fiscal_certifications certifiée de la facture d'origine */
    public static function fromReturn(int $returnId, array $profile, array $original): array
    {
        $r = DB::one('SELECT * FROM sale_returns WHERE id = ?', [$returnId]);
        if (!$r) {
            throw new BusinessException('Retour introuvable.');
        }
        $s = self::sale((int) $r['sale_id']);
        $items = DB::all('SELECT ri.quantity, ri.amount, si.product_name, si.tax_rate, si.quantity AS sold_qty, si.tax_amount AS sold_tax, p.reference
            FROM sale_return_items ri JOIN sale_items si ON si.id = ri.sale_item_id LEFT JOIN products p ON p.id = ri.product_id WHERE ri.return_id = ? ORDER BY ri.id', [$returnId]);
        $lines = array_map(function ($i) {
            $rate = (float) $i['tax_rate'];
            $ttc = (float) $i['amount'];
            $tax = $rate > 0 ? round($ttc - $ttc / (1 + $rate / 100), 2) : 0.0;
            return ['ref' => (string) ($i['reference'] ?? ''), 'name' => $i['product_name'], 'qty' => (float) $i['quantity'],
                'unit_price_ttc' => (float) $i['quantity'] > 0 ? round($ttc / (float) $i['quantity'], 2) : $ttc, 'discount' => 0.0,
                'total_ttc' => $ttc, 'tax_rate' => $rate, 'tax_amount' => $tax, 'total_ht' => round($ttc - $tax, 2)];
        }, $items);
        $s['created_at'] = $r['created_at'];
        return self::assemble('credit_note', self::creditKey($r), $r['number'], $s, $lines, [['method' => $r['refund_method'], 'provider' => null, 'amount' => (float) $r['total']]], $profile,
            ['local_number' => $original['local_number'], 'fiscal_number' => $original['fiscal_number'], 'device_id' => $original['device_id'], 'reason' => $r['reason']]);
    }

    private static function assemble(string $type, string $key, string $number, array $s, array $lines, array $payments, array $profile, ?array $original): array
    {
        $groups = [];
        foreach (DB::all('SELECT group_code, rate, local_rate FROM fiscal_tax_rates WHERE country_id = ? AND active = 1', [$profile['country_id']]) as $g) {
            $groups[number_format((float) ($g['local_rate'] ?? $g['rate']), 3, '.', '')] = $g['group_code'];
        }
        $unmapped = [];
        $taxes = [];
        foreach ($lines as &$l) {
            $k = number_format($l['tax_rate'], 3, '.', '');
            $l['tax_group'] = $groups[$k] ?? null;
            if ($groups && $l['tax_group'] === null) {
                $unmapped[$k] = $l['tax_rate'];
            }
            $tk = $l['tax_group'] ?? $k;
            $taxes[$tk] ??= ['group' => $l['tax_group'], 'rate' => $l['tax_rate'], 'base_ht' => 0.0, 'tax' => 0.0];
            $taxes[$tk]['base_ht'] = round($taxes[$tk]['base_ht'] + $l['total_ht'], 2);
            $taxes[$tk]['tax'] = round($taxes[$tk]['tax'] + $l['tax_amount'], 2);
        }
        unset($l);
        $ttc = round(array_sum(array_column($lines, 'total_ttc')), 2);
        $tva = round(array_sum(array_column($lines, 'tax_amount')), 2);
        $country = DB::one('SELECT code, currency FROM fiscal_countries WHERE id = ?', [$profile['country_id']]);
        return [
            'type' => $type, 'idempotency_key' => $key, 'local_number' => $number, 'issued_at' => date('c', strtotime((string) $s['created_at'])),
            'environment' => $profile['environment'], 'country' => $country['code'] ?? null, 'currency' => $country['currency'] ?? (string) setting('currency_code', 'XOF'),
            'seller' => ['name' => (string) setting('company_name', ''), 'nif' => (string) ($profile['seller_nif'] ?: setting('company_nif', '')), 'rccm' => (string) setting('company_rccm', ''),
                'regime' => $profile['seller_regime'] ?? null, 'address' => (string) ($s['store_address'] ?: setting('company_address', '')), 'phone' => (string) setting('company_phone', ''),
                'store' => $s['store_name'], 'register' => $s['register_name'], 'operator' => $s['cashier']],
            'buyer' => empty($s['customer_name']) ? null : ['name' => $s['customer_name'], 'nif' => $s['customer_nif'] ?: null, 'phone' => $s['customer_phone'] ?: null, 'address' => $s['customer_address'] ?: null],
            'lines' => $lines, 'taxes' => array_values($taxes),
            'totals' => ['ht' => round($ttc - $tva, 2), 'tva' => $tva, 'ttc' => $ttc, 'discount' => $type === 'invoice' ? (float) $s['discount_total'] : 0.0],
            'payments' => $payments, 'original' => $original, 'unmapped_rates' => array_values($unmapped),
        ];
    }

    /** Empreinte stable du contenu fiscal (hors clé), conservée comme preuve. */
    public static function hash(array $invoice): string
    {
        unset($invoice['unmapped_rates']);
        return hash('sha256', (string) json_encode($invoice, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }
}
