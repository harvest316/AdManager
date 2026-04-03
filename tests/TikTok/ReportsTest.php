<?php

declare(strict_types=1);

namespace AdManager\Tests\TikTok;

use AdManager\TikTok\Client;
use AdManager\TikTok\Reports;
use PHPUnit\Framework\TestCase;

/**
 * Tests for TikTok\Reports.
 *
 * We inject a mock Client via the singleton slot and verify:
 * - campaignInsights() posts to report/integrated/get/
 * - report_type is BASIC, data_level is AUCTION_CAMPAIGN
 * - date range is passed correctly
 * - results are normalised to standard shape
 * - cost_micros converts USD float to micros
 * - empty response returns empty array
 */
class ReportsTest extends TestCase
{
    private const FAKE_ADVERTISER_ID = 'adv_111222333';

    protected function setUp(): void
    {
        $mock = $this->createMock(Client::class);
        $mock->method('advertiserId')->willReturn(self::FAKE_ADVERTISER_ID);
        $mock->method('post')->willReturn([]);
        $this->injectMockClient($mock);
    }

    protected function tearDown(): void
    {
        Client::reset();
    }

    // -------------------------------------------------------------------------
    // campaignInsights()
    // -------------------------------------------------------------------------

    public function testCampaignInsightsPostsToCorrectEndpoint(): void
    {
        $capture = new \stdClass();
        $capture->endpoint = null;
        $capture->data     = null;

        $mock = $this->createMock(Client::class);
        $mock->method('advertiserId')->willReturn(self::FAKE_ADVERTISER_ID);
        $mock->method('post')
             ->willReturnCallback(function (string $endpoint, array $data) use ($capture): array {
                 $capture->endpoint = $endpoint;
                 $capture->data     = $data;
                 return [];
             });
        $this->injectMockClient($mock);

        $reports = new Reports();
        $reports->campaignInsights('2024-01-01', '2024-01-31');

        $this->assertSame('report/integrated/get/', $capture->endpoint);
    }

    public function testCampaignInsightsSetsReportTypeBasic(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $mock = $this->createMock(Client::class);
        $mock->method('advertiserId')->willReturn(self::FAKE_ADVERTISER_ID);
        $mock->method('post')
             ->willReturnCallback(function (string $endpoint, array $data) use ($capture): array {
                 $capture->data = $data;
                 return [];
             });
        $this->injectMockClient($mock);

        $reports = new Reports();
        $reports->campaignInsights('2024-01-01', '2024-01-31');

        $this->assertSame('BASIC', $capture->data['report_type']);
    }

    public function testCampaignInsightsSetsDataLevelAuctionCampaign(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $mock = $this->createMock(Client::class);
        $mock->method('advertiserId')->willReturn(self::FAKE_ADVERTISER_ID);
        $mock->method('post')
             ->willReturnCallback(function (string $endpoint, array $data) use ($capture): array {
                 $capture->data = $data;
                 return [];
             });
        $this->injectMockClient($mock);

        $reports = new Reports();
        $reports->campaignInsights('2024-01-01', '2024-01-31');

        $this->assertSame('AUCTION_CAMPAIGN', $capture->data['data_level']);
    }

    public function testCampaignInsightsPassesDateRange(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $mock = $this->createMock(Client::class);
        $mock->method('advertiserId')->willReturn(self::FAKE_ADVERTISER_ID);
        $mock->method('post')
             ->willReturnCallback(function (string $endpoint, array $data) use ($capture): array {
                 $capture->data = $data;
                 return [];
             });
        $this->injectMockClient($mock);

        $reports = new Reports();
        $reports->campaignInsights('2024-02-01', '2024-02-29');

        $this->assertSame('2024-02-01', $capture->data['start_date']);
        $this->assertSame('2024-02-29', $capture->data['end_date']);
    }

    public function testCampaignInsightsNormalisesRows(): void
    {
        $fakeRow = [
            'dimensions' => ['campaign_id' => 'cmp_1', 'stat_time_day' => '2024-01-15'],
            'metrics'    => [
                'spend'       => '1.50',
                'impressions' => '10000',
                'clicks'      => '150',
                'conversion'  => '3',
                'total_complete_payment_rate' => '45.00',
            ],
        ];

        $mock = $this->createMock(Client::class);
        $mock->method('advertiserId')->willReturn(self::FAKE_ADVERTISER_ID);
        $mock->method('post')->willReturn(['list' => [$fakeRow]]);
        $this->injectMockClient($mock);

        $reports = new Reports();
        $result = $reports->campaignInsights('2024-01-01', '2024-01-31');

        $this->assertCount(1, $result);
        $row = $result[0];

        $this->assertSame('cmp_1', $row['campaign_id']);
        $this->assertSame('2024-01-15', $row['date']);
        $this->assertSame(10000, $row['impressions']);
        $this->assertSame(150, $row['clicks']);
        $this->assertSame(1_500_000, $row['cost_micros']); // 1.50 USD = 1,500,000 micros
        $this->assertSame(3.0, $row['conversions']);
    }

    public function testCampaignInsightsCostMicrosConversion(): void
    {
        $fakeRow = [
            'dimensions' => ['campaign_id' => 'cmp_1', 'stat_time_day' => '2024-01-01'],
            'metrics'    => ['spend' => '0.001', 'impressions' => '0', 'clicks' => '0',
                             'conversion' => '0', 'total_complete_payment_rate' => '0'],
        ];

        $mock = $this->createMock(Client::class);
        $mock->method('advertiserId')->willReturn(self::FAKE_ADVERTISER_ID);
        $mock->method('post')->willReturn(['list' => [$fakeRow]]);
        $this->injectMockClient($mock);

        $reports = new Reports();
        $result = $reports->campaignInsights('2024-01-01', '2024-01-01');

        // 0.001 USD = 1000 micros
        $this->assertSame(1000, $result[0]['cost_micros']);
    }

    public function testCampaignInsightsReturnsEmptyArrayWhenNoData(): void
    {
        $mock = $this->createMock(Client::class);
        $mock->method('advertiserId')->willReturn(self::FAKE_ADVERTISER_ID);
        $mock->method('post')->willReturn([]);
        $this->injectMockClient($mock);

        $reports = new Reports();
        $result = $reports->campaignInsights('2024-01-01', '2024-01-31');

        $this->assertSame([], $result);
    }

    public function testCampaignInsightsRawDataIsPreserved(): void
    {
        $fakeRow = [
            'dimensions' => ['campaign_id' => 'cmp_1', 'stat_time_day' => '2024-01-01'],
            'metrics'    => ['spend' => '0', 'impressions' => '0', 'clicks' => '0',
                             'conversion' => '0', 'total_complete_payment_rate' => '0'],
        ];

        $mock = $this->createMock(Client::class);
        $mock->method('advertiserId')->willReturn(self::FAKE_ADVERTISER_ID);
        $mock->method('post')->willReturn(['list' => [$fakeRow]]);
        $this->injectMockClient($mock);

        $reports = new Reports();
        $result = $reports->campaignInsights('2024-01-01', '2024-01-01');

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
