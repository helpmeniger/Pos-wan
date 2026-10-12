<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Database;
use App\Services\CommissionService;
use Tests\TestCase;

final class CommissionTest extends TestCase
{
    public function testModels(): void
    {
        $base = ['min_commission' => null, 'max_commission' => null, 'id' => 1];
        $this->assertSame(300, CommissionService::compute($base + ['model' => 'percent', 'percent_bp' => 1000, 'fixed_amount' => 0], 3000)['commission']);
        $this->assertSame(250, CommissionService::compute($base + ['model' => 'fixed', 'percent_bp' => 0, 'fixed_amount' => 250], 3000)['commission']);
        $this->assertSame(250, CommissionService::compute($base + ['model' => 'mixed', 'percent_bp' => 500, 'fixed_amount' => 100], 3000)['commission']);
        $this->assertSame(0, CommissionService::compute($base + ['model' => 'subscription', 'percent_bp' => 0, 'fixed_amount' => 0], 3000)['commission']);
        $this->assertSame(0, CommissionService::compute(null, 3000)['commission']);
    }

    public function testMinMaxAndCapToBase(): void
    {
        $r = ['id' => 1, 'model' => 'percent', 'percent_bp' => 1000, 'fixed_amount' => 0, 'min_commission' => 200, 'max_commission' => 500];
        $this->assertSame(200, CommissionService::compute($r, 1000)['commission']);
        $this->assertSame(500, CommissionService::compute($r, 10000)['commission']);
        $this->assertSame(100, CommissionService::compute(['id' => 1, 'model' => 'fixed', 'percent_bp' => 0, 'fixed_amount' => 900, 'min_commission' => null, 'max_commission' => null], 100)['commission'], 'Jamais plus que la base');
    }

    public function testDefaultTaxiCommissionIsTenPercent(): void
    {
        // Exemple du cahier des charges : 3 000 FCFA → 300 FCFA de commission, 2 700 FCFA au chauffeur
        $p = CommissionService::preview('taxi', 'driver', 3000);
        $this->assertSame(300, $p['commission']);
        $this->assertSame(2700, $p['partner_net']);
    }

    public function testIndividualContractBeatsGeneralRule(): void
    {
        $m = $this->merchant();
        $id = Database::insert('commission_rules', ['name' => 'Contrat négocié', 'applies_to' => 'merchant', 'merchant_id' => $m['id'], 'model' => 'percent', 'percent_bp' => 800]);
        $rule = CommissionService::findRule('merchant', ['merchant_id' => $m['id'], 'service_type' => 'food', 'amount' => 5000]);
        $this->assertSame($id, (int) $rule['id']);
        $other = CommissionService::findRule('merchant', ['merchant_id' => 999999, 'service_type' => 'food', 'amount' => 5000]);
        $this->assertSame(1500, (int) $other['percent_bp']);
    }

    public function testExpiredOrFutureRulesIgnored(): void
    {
        Database::insert('commission_rules', ['name' => 'Promo passée', 'applies_to' => 'provider', 'provider_type' => 'driver', 'service_type' => 'taxi', 'model' => 'percent', 'percent_bp' => 100,
            'priority' => 50, 'valid_from' => '2020-01-01 00:00:00', 'valid_to' => '2020-12-31 23:59:59']);
        $this->assertSame(300, CommissionService::preview('taxi', 'driver', 3000)['commission']);
    }

    public function testBreakdownIsBalancedWithMerchantFundedPromo(): void
    {
        $m = $this->merchant();
        $c = $this->user('client');
        $promo = Database::insert('promotions', ['code' => 'MF' . $m['id'], 'description' => 't', 'discount_type' => 'fixed', 'value' => 500, 'funded_by' => 'merchant', 'merchant_id' => $m['id'],
            'starts_at' => '2020-01-01 00:00:00', 'ends_at' => '2099-01-01 00:00:00']);
        $order = ['id' => 0, 'flow' => 'merchant', 'service_type' => 'food', 'merchant_id' => $m['id'], 'provider_id' => $this->provider('courier', 'moto', 13.6, 2.2, false)['id'],
            'business_id' => null, 'city_id' => 1, 'zone_id' => null, 'vehicle_category' => null, 'items_subtotal' => 5000, 'delivery_fee' => 1000, 'booking_fee' => 0,
            'service_fee' => 200, 'discount' => 500, 'promotion_id' => $promo, 'total' => 5700, 'final_price' => 1000, 'estimated_price' => 1000, 'customer_id' => $c['id']];
        $b = CommissionService::breakdown($order, $m);
        $merchant = array_values(array_filter($b['parties'], fn ($p) => $p['party'] === 'merchant'))[0];
        $courier = array_values(array_filter($b['parties'], fn ($p) => $p['party'] === 'provider'))[0];
        $this->assertSame(750, $merchant['commission'], '15 % de 5 000');
        $this->assertSame(3750, $merchant['partner_net'], '5 000 − 750 − 500 (promo financée par le commerce)');
        $this->assertSame(900, $courier['partner_net'], '1 000 − 10 %');
        $this->assertSame(0, $b['promo_expense']);
        $this->assertSame(5700, $merchant['partner_net'] + $courier['partner_net'] + $b['commission'] + $b['fees']);
    }

    public function testVatPartIsExtractedWhenConfigured(): void
    {
        \App\Core\Settings::set('finance.vat_rate_bp', 1900);
        $this->assertSame(48, CommissionService::vatPart(300), '300 × 19/119 ≈ 48');
        \App\Core\Settings::set('finance.vat_rate_bp', 0);
        $this->assertSame(0, CommissionService::vatPart(300));
    }
}
