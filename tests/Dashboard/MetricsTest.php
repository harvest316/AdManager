<?php

namespace AdManager\Tests\Dashboard;

use AdManager\Dashboard\Metrics;
use PHPUnit\Framework\TestCase;

class MetricsTest extends TestCase
{
    public function testComputeBasicMetrics(): void
    {
        $result = Metrics::compute([
            'cost_micros' => 10_000_000, // $10
            'impressions' => 1000,
            'clicks'      => 50,
            'conversions' => 5,
            'conversion_value' => 100,
        ]);

        $this->assertEquals(1000, $result['impressions']);
        $this->assertEquals(50, $result['clicks']);
        $this->assertEquals(10.00, $result['cost']);
        $this->assertEquals(5.00, $result['ctr']); // 50/1000 * 100
        $this->assertEquals(5.00, $result['conversions']);
        $this->assertEquals(10.00, $result['conversion_rate']); // 5/50 * 100
        $this->assertEquals(2.00, $result['cpa']); // 10/5
        $this->assertEquals(10.00, $result['roas']); // 100/10
        $this->assertEquals(100.00, $result['conversion_value']);
    }

    public function testComputeZeroImpressions(): void
    {
        $result = Metrics::compute([
            'cost_micros' => 0,
            'impressions' => 0,
            'clicks'      => 0,
            'conversions' => 0,
            'conversion_value' => 0,
        ]);

        $this->assertEquals(0, $result['impressions']);
        $this->assertNull($result['ctr']); // < 50 impressions
        $this->assertNull($result['conversion_rate']); // 0 clicks
        $this->assertNull($result['cpa']); // 0 conversions
        $this->assertNull($result['roas']); // 0 cost
    }

    public function testComputeCtrNullBelow50Impressions(): void
    {
        $result = Metrics::compute([
            'impressions' => 49,
            'clicks'      => 10,
        ]);

        $this->assertNull($result['ctr']);
    }

    public function testComputeCtrPresentAt50Impressions(): void
    {
        $result = Metrics::compute([
            'impressions' => 50,
            'clicks'      => 5,
        ]);

        $this->assertEquals(10.00, $result['ctr']);
    }

    public function testComputeWithMissingKeys(): void
    {
        $result = Metrics::compute([]);

        $this->assertEquals(0, $result['impressions']);
        $this->assertEquals(0, $result['clicks']);
        $this->assertEquals(0.00, $result['cost']);
    }

    public function testMoneyFormat(): void
    {
        $this->assertEquals('$1,234.56', Metrics::money(1234.56));
        $this->assertEquals('$0.00', Metrics::money(0));
        $this->assertEquals('$0.50', Metrics::money(0.5));
    }

    public function testPctFormat(): void
    {
        $this->assertEquals('5.0%', Metrics::pct(5.0));
        $this->assertEquals('12.5%', Metrics::pct(12.5));
        $this->assertEquals('—', Metrics::pct(null));
    }

    public function testRoasFormat(): void
    {
        $this->assertEquals('3.2x', Metrics::roas(3.2));
        $this->assertEquals('0.5x', Metrics::roas(0.5));
        $this->assertEquals('—', Metrics::roas(null));
    }

    public function testDeltaUp(): void
    {
        $result = Metrics::delta(120.0, 100.0);
        $this->assertEquals(20.0, $result['value']);
        $this->assertEquals('up', $result['direction']);
    }

    public function testDeltaDown(): void
    {
        $result = Metrics::delta(80.0, 100.0);
        $this->assertEquals(-20.0, $result['value']);
        $this->assertEquals('down', $result['direction']);
    }

    public function testDeltaFlat(): void
    {
        $result = Metrics::delta(100.5, 100.0);
        $this->assertEquals('flat', $result['direction']);
    }

    public function testDeltaWithNull(): void
    {
        $result = Metrics::delta(null, 100.0);
        $this->assertNull($result['value']);
        $this->assertNull($result['direction']);

        $result = Metrics::delta(100.0, null);
        $this->assertNull($result['value']);
    }

    public function testDeltaWithZeroPrior(): void
    {
        $result = Metrics::delta(100.0, 0.0);
        $this->assertNull($result['value']);
        $this->assertNull($result['direction']);
    }

    public function testComputeCostMicrosConversion(): void
    {
        $result = Metrics::compute(['cost_micros' => 1_500_000]);
        $this->assertEquals(1.50, $result['cost']);
    }
}
