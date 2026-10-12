<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Settings;

/**
 * Moteur de commissions configurable (pourcentage, fixe, mixte, abonnement)
 * selon service, catégorie de prestataire, commerce, prestataire, entreprise,
 * ville, zone, montant et période de validité. La règle la plus spécifique gagne.
 */
final class CommissionService
{
    private const SCOPE = ['service_type', 'provider_type', 'vehicle_category', 'merchant_id', 'provider_id', 'business_id', 'city_id', 'zone_id'];

    public static function findRule(string $appliesTo, array $ctx): ?array
    {
        $rules = Database::select('SELECT * FROM commission_rules WHERE is_active = 1 AND applies_to = :a', ['a' => $appliesTo]);
        $now = $ctx['at'] ?? date('Y-m-d H:i:s');
        $amount = (int) ($ctx['amount'] ?? 0);
        $best = null;
        $bestScore = PHP_INT_MIN;
        foreach ($rules as $r) {
            if (($r['valid_from'] && $r['valid_from'] > $now) || ($r['valid_to'] && $r['valid_to'] < $now)) {
                continue;
            }
            if (($r['min_order_amount'] !== null && $amount < (int) $r['min_order_amount'])
                || ($r['max_order_amount'] !== null && $amount > (int) $r['max_order_amount'])) {
                continue;
            }
            $score = 0;
            foreach (self::SCOPE as $col) {
                if ($r[$col] === null) {
                    continue;
                }
                if ((string) $r[$col] !== (string) ($ctx[$col] ?? '')) {
                    continue 2;
                }
                // Contrat individuel (commerce/prestataire/entreprise) > zone > ville > service > catégorie
                $score += match ($col) {
                    'merchant_id', 'provider_id', 'business_id' => 1000,
                    'zone_id' => 300,
                    'city_id' => 200,
                    'service_type' => 100,
                    default => 50,
                };
            }
            $score = $score * 100 + (int) $r['priority'];
            if ($score > $bestScore || ($score === $bestScore && (int) $r['id'] > (int) $best['id'])) {
                $best = $r;
                $bestScore = $score;
            }
        }
        return $best;
    }

    /** Calcul pur d'une commission. */
    public static function compute(?array $rule, int $base): array
    {
        $model = $rule['model'] ?? 'none';
        $bp = (int) ($rule['percent_bp'] ?? 0);
        $fixed = (int) ($rule['fixed_amount'] ?? 0);
        $c = match ($model) {
            'percent' => (int) round($base * $bp / 10000),
            'fixed' => $fixed,
            'mixed' => (int) round($base * $bp / 10000) + $fixed,
            default => 0, // subscription / aucune règle : pas de commission à la commande
        };
        if ($rule && $rule['min_commission'] !== null) {
            $c = max($c, (int) $rule['min_commission']);
        }
        if ($rule && $rule['max_commission'] !== null) {
            $c = min($c, (int) $rule['max_commission']);
        }
        $c = max(0, min($c, $base));
        return [
            'rule_id' => $rule ? (int) $rule['id'] : null,
            'model' => $rule ? $model : 'none',
            'percent_bp' => in_array($model, ['percent', 'mixed'], true) ? $bp : 0,
            'fixed_amount' => in_array($model, ['fixed', 'mixed'], true) ? $fixed : 0,
            'base_amount' => $base,
            'commission' => $c,
        ];
    }

    /** Part de TVA incluse dans une commission (taux paramétrable, 0 par défaut). */
    public static function vatPart(int $commission): int
    {
        $bp = Settings::int('finance.vat_rate_bp', 0);
        return $bp > 0 ? (int) round($commission * $bp / (10000 + $bp)) : 0;
    }

    /**
     * Répartition complète d'une commande terminée.
     * Invariant : collected + promo_expense = Σ net partenaires + commissions + frais MARWA.
     */
    public static function breakdown(array $order, ?array $merchant = null): array
    {
        $parties = [];
        $ctxBase = [
            'service_type' => $order['service_type'],
            'business_id' => $order['business_id'],
            'city_id' => $order['city_id'],
            'zone_id' => $order['zone_id'],
            'vehicle_category' => $order['vehicle_category'],
        ];
        $discount = (int) $order['discount'];
        $promoFundedBy = 'marwa';
        if ($order['promotion_id']) {
            $promoFundedBy = (string) Database::value('SELECT funded_by FROM promotions WHERE id = :id', ['id' => $order['promotion_id']]) ?: 'marwa';
        }

        if ($order['flow'] === 'merchant') {
            $merchant ??= Database::first('SELECT * FROM merchants WHERE id = :id', ['id' => $order['merchant_id']]);
            $base = (int) $order['items_subtotal'];
            $rule = self::findRule('merchant', $ctxBase + ['merchant_id' => $merchant['id'], 'amount' => $base]);
            $c = self::compute($rule, $base);
            $gross = $base;
            $ownDelivery = $merchant['delivery_mode'] === 'own' || !$order['provider_id'];
            if ($ownDelivery) {
                $gross += (int) $order['delivery_fee'];
            }
            $net = $gross - $c['commission'] - ($promoFundedBy === 'merchant' ? $discount : 0);
            $parties[] = $c + ['party' => 'merchant', 'owner_id' => (int) $merchant['id'], 'user_id' => (int) $merchant['owner_user_id'],
                'gross' => $gross, 'vat_amount' => self::vatPart($c['commission']), 'partner_net' => $net];
            if (!$ownDelivery) {
                $pbase = (int) $order['delivery_fee'];
                $prule = self::findRule('provider', $ctxBase + ['provider_type' => 'courier', 'provider_id' => $order['provider_id'], 'amount' => $pbase]);
                $pc = self::compute($prule, $pbase);
                $parties[] = $pc + ['party' => 'provider', 'owner_id' => (int) $order['provider_id'], 'user_id' => (int) $order['provider_id'],
                    'gross' => $pbase, 'vat_amount' => self::vatPart($pc['commission']), 'partner_net' => $pbase - $pc['commission']];
            }
        } else {
            $base = (int) ($order['final_price'] ?? $order['estimated_price']);
            $ptype = $order['flow'] === 'ride' ? 'driver' : 'courier';
            $rule = self::findRule('provider', $ctxBase + ['provider_type' => $ptype, 'provider_id' => $order['provider_id'], 'amount' => $base]);
            $c = self::compute($rule, $base);
            $parties[] = $c + ['party' => 'provider', 'owner_id' => (int) $order['provider_id'], 'user_id' => (int) $order['provider_id'],
                'gross' => $base, 'vat_amount' => self::vatPart($c['commission']), 'partner_net' => $base - $c['commission']];
        }

        $commission = array_sum(array_column($parties, 'commission'));
        $vat = array_sum(array_column($parties, 'vat_amount'));
        $fees = (int) $order['booking_fee'] + (int) $order['service_fee'];
        $promoExpense = $promoFundedBy === 'marwa' ? $discount : 0;
        $collected = (int) $order['total'];
        $check = $collected + $promoExpense - (array_sum(array_column($parties, 'partner_net')) + $commission + $fees);
        if ($check !== 0) {
            throw new \LogicException("Répartition déséquilibrée pour la commande {$order['id']} (écart $check).");
        }
        return [
            'collected' => $collected,
            'parties' => $parties,
            'commission' => $commission,
            'vat' => $vat,
            'fees' => $fees,
            'promo_expense' => $promoExpense,
            'discount' => $discount,
            'promo_funded_by' => $promoFundedBy,
            'marwa_revenue' => $commission - $vat + $fees,
        ];
    }

    /** Aperçu de commission pour l'affichage avant acceptation (prix d'une course). */
    public static function preview(string $serviceType, string $providerType, int $amount, ?int $providerId = null, ?int $cityId = null): array
    {
        $rule = self::findRule('provider', ['service_type' => $serviceType, 'provider_type' => $providerType, 'provider_id' => $providerId, 'city_id' => $cityId, 'amount' => $amount]);
        $c = self::compute($rule, $amount);
        return ['amount' => $amount, 'commission' => $c['commission'], 'partner_net' => $amount - $c['commission'], 'percent_bp' => $c['percent_bp'], 'model' => $c['model']];
    }
}
