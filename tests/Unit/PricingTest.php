<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Services\PricingService;
use DateTimeImmutable;
use Tests\TestCase;

final class PricingTest extends TestCase
{
    private array $rule = ['base_fare' => 300, 'per_km' => 150, 'per_min' => 10, 'min_fare' => 700, 'night_multiplier_pct' => 120, 'night_start' => '22:00:00', 'night_end' => '05:00:00'];

    public function testDistanceAndDurationAreCharged(): void
    {
        // 300 + 150×10 + 10×20 = 2000
        $this->assertSame(2000, PricingService::computeFare($this->rule, 10000, 1200, new DateTimeImmutable('2026-01-10 14:00:00'), 50));
    }

    public function testMinimumFareApplies(): void
    {
        $this->assertSame(700, PricingService::computeFare($this->rule, 500, 60, new DateTimeImmutable('2026-01-10 14:00:00'), 50));
    }

    public function testNightMultiplierAcrossMidnight(): void
    {
        $this->assertTrue(PricingService::isNight($this->rule, new DateTimeImmutable('2026-01-10 23:30:00')));
        $this->assertTrue(PricingService::isNight($this->rule, new DateTimeImmutable('2026-01-11 04:59:00')));
        $this->assertFalse(PricingService::isNight($this->rule, new DateTimeImmutable('2026-01-11 05:00:00')));
        // 2000 × 1,20 = 2400
        $this->assertSame(2400, PricingService::computeFare($this->rule, 10000, 1200, new DateTimeImmutable('2026-01-10 23:00:00'), 50));
    }

    public function testRoundingToStep(): void
    {
        $this->assertSame(1050, PricingService::round(1001, 50));
        $this->assertSame(1000, PricingService::round(1000, 50));
        $this->assertSame(1025, PricingService::round(1001, 25));
    }

    public function testRuleLookupPrefersMostSpecific(): void
    {
        $generic = PricingService::findRule('taxi', 'taxi', 1, null);
        \App\Core\Database::insert('pricing_rules', ['name' => 'Niamey taxi spécial', 'service_type' => 'taxi', 'vehicle_category' => 'taxi', 'city_id' => 1, 'base_fare' => 999, 'per_km' => 1, 'per_min' => 0, 'min_fare' => 0]);
        $specific = PricingService::findRule('taxi', 'taxi', 1, null);
        $this->assertSame(999, (int) $specific['base_fare']);
        $this->assertTrue((int) $generic['id'] !== (int) $specific['id']);
        \App\Core\Database::execute("DELETE FROM pricing_rules WHERE name = 'Niamey taxi spécial'");
    }

    public function testEstimateFlagsApproximateRoute(): void
    {
        $e = PricingService::estimate('taxi', 'taxi', 13.5137, 2.1098, 13.4900, 2.1600, new DateTimeImmutable('2026-01-10 12:00:00'));
        $this->assertTrue($e['approximate'], 'Une estimation sans OSRM doit être signalée comme approximative');
        $this->assertTrue($e['distance_m'] > 5000 && $e['distance_m'] < 12000);
        $this->assertTrue($e['min_offer'] <= $e['fare'] && $e['max_offer'] >= $e['fare']);
        $this->assertSame($e['fare'] + $e['booking_fee'] + $e['service_fee'], $e['total']);
    }

    public function testOutsideServedAreaIsRejected(): void
    {
        $this->assertThrows(fn () => PricingService::estimate('taxi', 'taxi', 14.6928, -17.4467, 14.70, -17.45), \App\Core\HttpException::class);
    }
}
