<?php

namespace Tests\Unit\Provider;

use App\AI\Support\BackoffStrategy;
use PHPUnit\Framework\TestCase;

final class BackoffStrategyTest extends TestCase
{
    public function test_delay_grows_exponentially_without_jitter(): void
    {
        $this->assertSame(500, BackoffStrategy::delayMs(1, 500, 30000, 0.0));
        $this->assertSame(1000, BackoffStrategy::delayMs(2, 500, 30000, 0.0));
        $this->assertSame(2000, BackoffStrategy::delayMs(3, 500, 30000, 0.0));
        $this->assertSame(4000, BackoffStrategy::delayMs(4, 500, 30000, 0.0));
    }

    public function test_delay_is_capped_at_the_maximum(): void
    {
        $this->assertSame(30000, BackoffStrategy::delayMs(8, 500, 30000, 0.0));
        $this->assertSame(30000, BackoffStrategy::delayMs(20, 500, 30000, 0.0));
    }

    public function test_retry_after_seconds_override_backoff(): void
    {
        $this->assertSame(7000, BackoffStrategy::delayMs(1, 500, 30000, 0.0, 7));
        $this->assertSame(45000, BackoffStrategy::delayMs(5, 500, 30000, 0.0, 45));
    }

    public function test_zero_or_negative_retry_after_falls_back_to_exponential(): void
    {
        $this->assertSame(500, BackoffStrategy::delayMs(1, 500, 30000, 0.0, 0));
        $this->assertSame(1000, BackoffStrategy::delayMs(2, 500, 30000, 0.0, -5));
    }

    public function test_default_jitter_ratio_stays_in_a_bounded_range(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $delay = BackoffStrategy::delayMs(1, 500, 30000);

            $this->assertGreaterThanOrEqual(500, $delay);
            $this->assertLessThanOrEqual(625, $delay);
        }
    }

    public function test_jitter_stays_within_the_configured_ratio(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $delay = BackoffStrategy::withJitter(2000, 0.25);

            $this->assertGreaterThanOrEqual(2000, $delay);
            $this->assertLessThanOrEqual(2500, $delay);
        }
    }

    public function test_zero_jitter_ratio_returns_the_exact_delay(): void
    {
        $this->assertSame(1250, BackoffStrategy::withJitter(1250, 0.0));
    }
}
