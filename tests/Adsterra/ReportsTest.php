<?php

declare(strict_types=1);

namespace AdManager\Tests\Adsterra;

use AdManager\Adsterra\Client;
use AdManager\Adsterra\Reports;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Adsterra\Reports.
 *
 * We inject a mock Client via the singleton slot and verify:
 * - campaignInsights() calls GET advertising/stats with correct params
 * - date range is passed correctly
 * - results are normalised to standard shape
 * - cost_micros converts float to micros
 * - empty response returns empty array
 */
class ReportsTest extends TestCase
{
    protected function setUp(): void
    {
        $mock = $this->createMock(Client::class);
        $mock->method('get_api')->willReturn([]);
        $this->injectMockClient($mock);
    }

    protected function tearDown(): void
    {
        Client::reset();
    }

    // -------------------------------------------------------------------------
    // campaignInsights()
    // -------------------------------------------------------------------------

    public function testCampaignInsightsCallsAdvertisingStatsEndpoint(): void
    {
        $capture = new \stdClass();
        $capture->endpoint = null;

        $mock = $this->createMock(Client::class);
        $mock->method('get_api')
             ->willReturnCallback(function (string $endpoint) use ($capture): array {
                 $capture->endpoint = $endpoint;
                 return [];
             });
        $this->injectMockClient($mock);

        $reports = new Reports();
        $reports->campaignInsights('2024-01-01', '2024-01-31');

        $this->assertSame('advertising/stats', $capture->endpoint);
    }

    public function testCampaignInsightsPassesDateRange(): void
    {
        $capture = new \stdClass();
        $capture->params = null;

        $mock = $this->createMock(Client::class);
        $mock->method('get_api')
             ->willReturnCallback(function (string $endpoint, array $params) use ($capture): array {
                 $capture->params = $params;
                 return [];
             });
        $this->injectMockClient($mock);

        $reports = new Reports();
        $reports->campaignInsights('2024-03-01', '2024-03-31');

        $this->assertSame('2024-03-01', $capture->params['date_from']);
        $this->assertSame('2024-03-31', $capture->params['date_to']);
    }

    public function testCampaignInsightsNormalisesRows(): void
    {
        $fakeRow = [
            'campaign_id'      => '99887',
            'date'             => '2024-01-15',
            'impressions'      => 20000,
            'clicks'           => 300,
            'spent'            => 3.75,
            'conversions'      => 8,
            'conversion_value' => 120.00,
        ];

        $mock = $this->createMock(Client::class);
        $mock->method('get_api')->willReturn(['data' => [$fakeRow]]);
        $this->injectMockClient($mock);

        $reports = new Reports();
        $result = $reports->campaignInsights('2024-01-01', '2024-01-31');

        $this->assertCount(1, $result);
        $row = $result[0];

        $this->assertSame('99887', $row['campaign_id']);
        $this->assertSame('2024-01-15', $row['date']);
        $this->assertSame(20000, $row['impressions']);
        $this->assertSame(300, $row['clicks']);
        $this->assertSame(3_750_000, $row['cost_micros']); // 3.75 = 3,750,000 micros
        $this->assertSame(8.0, $row['conversions']);
    }

    public function testCampaignInsightsCostMicrosConversion(): void
    {
        $fakeRow = ['campaign_id' => '1', 'date' => '2024-01-01',
                    'impressions' => 0, 'clicks' => 0,
                    'spent' => 0.001, 'conversions' => 0, 'conversion_value' => 0];

        $mock = $this->createMock(Client::class);
        $mock->method('get_api')->willReturn(['data' => [$fakeRow]]);
        $this->injectMockClient($mock);

        $reports = new Reports();
        $result = $reports->campaignInsights('2024-01-01', '2024-01-01');

        // 0.001 = 1000 micros
        $this->assertSame(1000, $result[0]['cost_micros']);
    }

    public function testCampaignInsightsReturnsEmptyArrayWhenNoData(): void
    {
        $mock = $this->createMock(Client::class);
        $mock->method('get_api')->willReturn([]);
        $this->injectMockClient($mock);

        $reports = new Reports();
        $result = $reports->campaignInsights('2024-01-01', '2024-01-31');

        $this->assertSame([], $result);
    }

    public function testCampaignInsightsHandlesDirectArrayResponse(): void
    {
        $fakeRow = ['campaign_id' => '1', 'date' => '2024-01-01',
                    'impressions' => 0, 'clicks' => 0,
                    'spent' => 0.0, 'conversions' => 0, 'conversion_value' => 0];

        // Response without 'data' wrapper — direct array
        $mock = $this->createMock(Client::class);
        $mock->method('get_api')->willReturn([$fakeRow]);
        $this->injectMockClient($mock);

        $reports = new Reports();
        $result = $reports->campaignInsights('2024-01-01', '2024-01-01');

        $this->assertCount(1, $result);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function injectMockClient(Client $client): void
    {
        $ref = new \ReflectionProperty(Client::class, 'instance');
        $ref->setAccessible(true);
        $ref->setValue(null, $client);
    }
}
