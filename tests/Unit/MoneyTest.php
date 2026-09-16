<?php

namespace Tests\Unit;

use App\Domain\Finance\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_money_uses_exact_minor_units(): void
    {
        $revenue = Money::fromMinor(12_345, 'eur');
        $fee = Money::fromMinor(2_345, 'EUR');

        $this->assertSame(10_000, $revenue->subtract($fee)->minor);
        $this->assertSame('EUR', $revenue->currency);
    }

    public function test_different_currencies_cannot_be_combined(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::fromMinor(100, 'EUR')->add(Money::fromMinor(100, 'USD'));
    }
}
