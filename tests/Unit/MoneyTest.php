<?php

namespace Tests\Unit;

use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_arithmetic_is_exact(): void
    {
        // The classic float trap: 0.1 + 0.2 !== 0.3
        $this->assertSame('0.3', Money::add('0.1', '0.2'));
        $this->assertSame('0.000000001', Money::div('0.001', '1000000'));
        $this->assertSame('0.046125', Money::mul('0.0369', '1.25'));
        $this->assertSame('-0.5', Money::sub('1', '1.5'));
    }

    public function test_normalize_pads_to_storage_scale(): void
    {
        $this->assertSame('0.046125000000', Money::normalize('0.046125'));
        $this->assertSame('12.000000000000', Money::normalize('12'));
        $this->assertSame('0.000000000000', Money::normalize('-0'));
    }

    public function test_normalize_refuses_to_drop_precision(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::normalize('0.0000000000001');
    }

    public function test_non_decimal_input_is_rejected(): void
    {
        foreach (['1e5', '0.1.2', '', 'abc', '1,000'] as $bad) {
            try {
                Money::add($bad);
                $this->fail("Accepted {$bad}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_comparisons(): void
    {
        $this->assertSame(0, Money::compare('1.50', '1.5'));
        $this->assertTrue(Money::isZero('0.000000000000'));
        $this->assertTrue(Money::isNegative('-0.000001'));
        $this->assertSame('2', Money::max('1.999999999999', '2'));
        $this->assertSame('1.999999999999', Money::min('1.999999999999', '2'));
    }

    public function test_display_rounding_is_half_up_and_separate_from_storage(): void
    {
        $this->assertSame('0.01', Money::roundForDisplay('0.005'));
        $this->assertSame('0.00', Money::roundForDisplay('0.004999'));
        $this->assertSame('0.38', Money::roundForDisplay('0.3818875'));
        $this->assertSame('-0.01', Money::roundForDisplay('-0.005'));
    }
}
