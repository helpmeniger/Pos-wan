<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function testReferenceExampleTenPercentCommission(): void
    {
        $price = Money::xof(10000);
        $commission = $price->percentBps(Money::rateToBps('10.00'));
        $this->assertSame(1000, $commission->amount());
        $this->assertSame(9000, $price->subtract($commission)->amount());
    }

    public function testFivePositionsExample(): void
    {
        $total = Money::xof(10000)->multiply(5);
        $commission = $total->percentBps(1000);
        $this->assertSame(50000, $total->amount());
        $this->assertSame(5000, $commission->amount());
        $this->assertSame(45000, $total->subtract($commission)->amount());
    }

    public function testRoundingIsHalfUpToTheFranc(): void
    {
        $this->assertSame(1, Money::xof(5)->percentBps(1000)->amount());    // 0,5 → 1
        $this->assertSame(0, Money::xof(4)->percentBps(1000)->amount());    // 0,4 → 0
        $this->assertSame(1235, Money::xof(12345)->percentBps(1000)->amount()); // 1234,5 → 1235
        $this->assertSame(617, Money::xof(12345)->percentBps(500)->amount());   // 617,25 → 617
    }

    public function testDecimalFactor(): void
    {
        $this->assertSame(25000, Money::xof(10000)->multiply('2.5')->amount());
        $this->assertSame(3333, Money::xof(1000)->multiply('3.333')->amount());
    }

    public function testParseAcceptsDatabaseDecimalsAndUserInput(): void
    {
        $this->assertSame(10000, Money::parse('10000.00')->amount());
        $this->assertSame(10000, Money::parse('10 000')->amount());
        $this->assertSame(-500, Money::parse('-500')->amount());
    }

    public function testParseRejectsCentimesAndGarbage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::parse('10.50');
    }

    public function testParseRejectsText(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::parse('1e3');
    }

    public function testRateToBps(): void
    {
        $this->assertSame(1000, Money::rateToBps('10.00'));
        $this->assertSame(850, Money::rateToBps('8.5'));
        $this->assertSame(0, Money::rateToBps('0.00'));
        $this->assertSame(25, Money::rateToBps('0.25'));
    }

    public function testFormatAndDecimal(): void
    {
        $this->assertSame("10\u{202F}000\u{00A0}FCFA", Money::xof(10000)->format());
        $this->assertSame('10000.00', Money::xof(10000)->toDecimal());
    }

    public function testNegativePercentageRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::xof(100)->percentBps(-1);
    }
}
