<?php

namespace Tests\Unit;

use App\Support\StockVelocity;
use Tests\TestCase;

class StockVelocityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('stock_classification.fast_moving_min_30_days', 30);
        config()->set('stock_classification.medium_moving_min_30_days', 10);
    }

    public function test_it_classifies_normalized_thirty_day_velocity_at_each_boundary(): void
    {
        $this->assertSame(StockVelocity::FAST, StockVelocity::classify(30, 30));
        $this->assertSame(StockVelocity::MEDIUM, StockVelocity::classify(29, 30));
        $this->assertSame(StockVelocity::MEDIUM, StockVelocity::classify(10, 30));
        $this->assertSame(StockVelocity::SLOW, StockVelocity::classify(9, 30));
        $this->assertSame(StockVelocity::SLOW, StockVelocity::classify(1, 30));
        $this->assertSame(StockVelocity::NON_MOVING, StockVelocity::classify(0, 30));
    }

    public function test_a_longer_report_period_is_normalized_before_classification(): void
    {
        $this->assertEqualsWithDelta(30, StockVelocity::equivalent30Days(60, 60), 0.001);
        $this->assertSame(StockVelocity::FAST, StockVelocity::classify(60, 60));
        $this->assertSame(StockVelocity::MEDIUM, StockVelocity::classify(20, 60));
        $this->assertSame(StockVelocity::SLOW, StockVelocity::classify(18, 60));
    }

    public function test_invalid_configuration_is_constrained_to_ordered_thresholds(): void
    {
        config()->set('stock_classification.fast_moving_min_30_days', 1);
        config()->set('stock_classification.medium_moving_min_30_days', 99);

        $this->assertSame(['fast' => 2, 'medium' => 1], StockVelocity::thresholds());
    }
}
