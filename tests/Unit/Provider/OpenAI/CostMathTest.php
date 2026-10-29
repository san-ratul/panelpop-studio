<?php

namespace Tests\Unit\Provider\OpenAI;

use App\AI\Cost\CostMath;
use Tests\TestCase;

class CostMathTest extends TestCase
{
    public function test_per_million_scales_token_counts_by_rate(): void
    {
        $this->assertSame('1.500000000000', CostMath::perMillion(1500000, '1.00'));
        $this->assertSame('0.000042000000', CostMath::perMillion(42, '1.00'));
        $this->assertSame('0.000210000000', CostMath::perMillion(42, '5.00'));
    }

    public function test_to_decimal6_rounds_half_up_to_storage_precision(): void
    {
        $this->assertSame('0.000023', CostMath::toDecimal6('0.0000225'));
        $this->assertSame('0.000022', CostMath::toDecimal6('0.0000224'));
        $this->assertSame('2.500000', CostMath::toDecimal6('2.5'));
        $this->assertSame('0.000000', CostMath::toDecimal6('0'));
    }

    public function test_negative_amounts_are_clamped_to_zero(): void
    {
        $this->assertSame('0.000000', CostMath::toDecimal6('-1.5'));
    }

    public function test_sum_is_exact_across_many_components(): void
    {
        $components = array_fill(0, 10, '0.0000001');

        $this->assertSame('0.000001000000', CostMath::sum($components));
    }

    public function test_float_round_trip_stays_exact_for_typical_amounts(): void
    {
        $this->assertSame('0.012345', CostMath::toDecimal6(0.012345));
        $this->assertSame('12.340000', CostMath::toDecimal6(12.34));
    }

    public function test_is_positive_distinguishes_zero_from_positive(): void
    {
        $this->assertFalse(CostMath::isPositive('0.000000'));
        $this->assertTrue(CostMath::isPositive('0.000001'));
    }
}
