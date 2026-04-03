<?php

declare(strict_types=1);

namespace AdManager\Tests\ExoClick;

use AdManager\ExoClick\Client;
use AdManager\ExoClick\Reports;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ExoClick\Reports.
 *
 * We inject a mock Client via the singleton slot and verify:
 * - campaignInsights() calls GET statistics with correct params
 * - campaign_id filter is included
 * - date range is passed correctly
 * - results are normalised to standard shape
 * - cost_micros converts float to micros
 * - empty response returns empty array
 */
class ReportsTest extends TestCase
{
    private const FAKE_CAMPAIGN_ID = '12345';

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

    public function testCampaignInsightsCallsStatisticsEndpoint(): void
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
        $reports->campaignInsights(self::FAKE_CAMPAIGN_ID, '2024-01-01', '2024-01-31');

        $this->assertSame('statistics', $capture->endpoint);
    }

    public function testCampaignInsightsPassesCampaignId(): void
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
        $reports->campaignInsights(self::FAKE_CAMPAIGN_ID, '2024-01-01', '2024-01-31');

        $this->assertSame(self::FAKE_CAMPAIGN_ID, $capture->params['campaign_id']);
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
        $reports->campaignInsights(self::FAKE_CAMPAIGN_ID, '2024-02-01', '2024-02-29');

        $this->assertSame('2024-02-01', $capture->params['date_from']);
        $this->assertSame('2024-02-29', $capture->params['date_to']);
    }

    public function testCampaignInsightsNormalisesRows(): void
    {
        $fakeRow = [
            'date'             => '2024-01-15',
            'impressions'      => 10000,
            'clicks'           => 150,
            'revenue'          => 2.50,
            'conversions'      => 5,
            'conversion_value' => 75.00,
        ];

        $mock = $this->createMock(Client::class);
        $mock->method('get_api')->willReturn(['data' => [$fakeRow]]);
        $this->injectMockClient($mock);

        $reports = new Reports();
        $result = $reports->campaignInsights(self::FAKE_CAMPAIGN_ID, '2024-01-01', '2024-01-31');

        $this->assertCount(1, $result);
        $row = $result[0];

        $this->assertSame(self::FAKE_CAMPAIGN_ID, $row['campaign_id']);
        $this->assertSame('2024-01-15', $row['date']);
        $this->assertSame(10000, $row['impressions']);
        $this->assertSame(150, $row['clicks']);
        $this->assertSame(2_500_000, $row['cost_micros']); // 2.50 = 2,500,000 micros
        $this->assertSame(5.0, $row['conversions']);
    }

    public function testCampaignInsightsCostMicrosConversion(): void
    {
        $fakeRow = ['date' => '2024-01-01', 'impressions' => 0, 'clicks' => 0,
                    'revenue' => 0.001, 'conversions' => 0, 'conversion_value' => 0];

        $mock = $this->createMock(Client::class);
        $mock->method('get_api')->willReturn(['data' => [$fakeRow]]);
        $this->injectMockClient($mock);

        $reports = new Reports();
        $result = $reports->campaignInsights(self::FAKE_CAMPAIGN_ID, '2024-01-01', '2024-01-01');

        // 0.001 = 1000 micros
        $this->assertSame(1000, $result[0]['cost_micros']);
    }

    public function testCampaignInsightsReturnsEmptyArrayWhenNoData(): void
    {
        $mock = $this->createMock(Client::class);
        $mock->method('get_api')->willReturn([]);
        $this->injectMockClient($mock);

        $reports = new Reports();
        $result = $reports->campaignInsights(self::FAKE_CAMPAIGN_ID, '2024-01-01', '2024-01-31');

        $this->assertSame([], $result);
    }

    public function testCampaignInsightsRawDataIsPreserved(): void
    {
        $fakeRow = ['date' => '2024-01-01', 'impressions' => 0, 'clicks' => 0,
                    'revenue' => 0.0, 'conversions' => 0, 'conversion_value' => 0];

        $mock = $this->createMock(Client::class);
        $mock->method('get_api')->willReturn(['data' => [$fakeRow]]);
        $this->injectMockClient($mock);

        $reports = new Reports();
        $result = $reports->campaignInsights(self::FAKE_CAMPAIGN_ID, '2024-01-01', '2024-01-01');

        $this->assertArrayHasKey('_raw', $result[0]);
        $this->assertSame($fakeRow, $result[0]['_raw']);
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
