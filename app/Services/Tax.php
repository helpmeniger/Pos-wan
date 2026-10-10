<?php
declare(strict_types=1);

namespace App\Services;

/**
 * TVA. Les prix de vente sont saisis TTC (TVA comprise) ; la TVA de chaque ligne est
 * extraite du montant : TVA = TTC × taux / (100 + taux).
 * Modes (Paramètres → TVA) :
 *  - none    : pas de TVA (commerce non assujetti) ;
 *  - general : un taux général pour tous les articles, sauf ceux marqués « exonéré » ;
 *  - product : le taux saisi sur chaque article (« Taxe incluse (%) »).
 */
final class Tax
{
    public const MODES = ['none' => 'Pas de TVA (non assujetti)', 'general' => 'TVA générale sur tous les articles (sauf exonérés)', 'product' => 'Taux défini article par article'];

    /** Taux usuels proposés (le taux reste libre). */
    public const COMMON_RATES = [5, 10, 15, 18, 19, 20];

    public static function mode(): string
    {
        $m = (string) setting('vat_mode', 'product');
        return isset(self::MODES[$m]) ? $m : 'product';
    }

    public static function label(): string
    {
        return (string) (setting('vat_label', 'TVA') ?: 'TVA');
    }

    public static function generalRate(): float
    {
        return max(0.0, min(100.0, (float) setting('vat_rate', '0')));
    }

    /** Taux applicable à un article (tableau avec tax_rate et vat_exempt). */
    public static function rate(array $product): float
    {
        return match (self::mode()) {
            'none' => 0.0,
            'general' => (int) ($product['vat_exempt'] ?? 0) === 1 ? 0.0 : self::generalRate(),
            default => max(0.0, (float) ($product['tax_rate'] ?? 0)),
        };
    }

    /** TVA contenue dans un montant TTC. */
    public static function included(float $amountTtc, float $rate): float
    {
        return $rate > 0 ? round($amountTtc * $rate / (100 + $rate), 2) : 0.0;
    }

    public static function rateLabel(float $rate): string
    {
        return self::label() . ' ' . rtrim(rtrim(number_format($rate, 2, ',', ''), '0'), ',') . ' %';
    }
}
