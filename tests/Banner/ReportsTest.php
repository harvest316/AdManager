<?php

declare(strict_types=1);

namespace AdManager\Tests\Banner;

use AdManager\Banner\Client;
use AdManager\Banner\Reports;
use AdManager\DB;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Banner\Reports.
 *
 * Reports reads/writes the local SQLite performance table.
 * We use an in-memory DB and inject a mock Client.
 *
 * - import() inserts rows and returns the count
 * - import() with empty array returns 0
 * - campaignInsights() returns normalised rows in date range
 * - campaignInsights() excludes rows outside the date range
 * - campaignInsights() returns empty array when no data
 * - cost_micros and other numerics are correctly typed
 */
class ReportsTest extends TestCase
{
    private const CAMPAIGN_ID = 1;
    private const PROJECT_ID  = 1;

    protected function setUp(): void
    {
        putenv('ADMANAGER_DB_PATH=:memory:');
        $_ENV['ADMANAGER_DB_PATH'] = ':memory:';

        DB::reset();
        DB::init();

        // Seed project and campaign rows
        DB::get()->exec(
            "INSERT INTO projects (id, name, display_name) VALUES (1, 'test_project', 'Test Project')"
        );
        DB::get()->exec(
            "INSERT INTO campaigns (id, project_id, platform, name, type, status)
             VALUES (1, 1, 'banner', 'Test Banner Campaign', 'display', 'active')"
        );

        $this->injectMockClient($this->buildClientMock('banner'));
    }

    protected function tearDown(): void
    {
        Client::reset();
        DB::reset();
        putenv('ADMANAGER_DB_PATH');
        unset($_ENV['ADMANAGER_DB_PATH']);
    }

    // -------------------------------------------------------------------------
    // import()
    // -------------------------------------------------------------------------

    public function testImportInsertsRowsAndReturnsCount(): void
    {
        $reports = new Reports();
        $count = $reports->import(self::CAMPAIGN_ID, [
            $this->makeRow('2024-01-01'),
            $this->makeRow('2024-01-02'),
            $this->makeRow('2024-01-03'),
        ]);

        $this->assertSame(3, $count);
    }

    public function testImportWithEmptyArrayReturnsZero(): void
    {
        $reports = new Reports();
        $count = $reports->import(self::CAMPAIGN_ID, []);

        $this->assertSame(0, $count);
    }

    public function testImportedRowsAreQueryableViaCampaignInsights(): void
    {
        $reports = new Reports();
        $reports->import(self::CAMPAIGN_ID, [
            $this->makeRow('2024-01-15', 10000, 200, 5_000_000, 3.0, 45.00),
        ]);

        $result = $reports->campaignInsights(self::CAMPAIGN_ID, '2024-01-01', '2024-01-31');

        $this->assertCount(1, $result);
    }

    // -------------------------------------------------------------------------
    // campaignInsights()
    // -------------------------------------------------------------------------

    public function testCampaignInsightsReturnsRowsInDateRange(): void
    {
        $reports = new Reports();
        $reports->import(self::CAMPAIGN_ID, [
            $this->makeRow('2024-01-10'),
            $this->makeRow('2024-01-20'),
            $this->makeRow('2024-02-05'), // outside range
        ]);

        $result = $reports->campaignInsights(self::CAMPAIGN_ID, '2024-01-01', '2024-01-31');

        $this->assertCount(2, $result);
    }

    public function testCampaignInsightsExcludesRowsOutsideDateRange(): void
    {
        $reports = new Reports();
        $reports->import(self::CAMPAIGN_ID, [
            $this->makeRow('2023-12-31'), // before start
            $this->makeRow('2024-01-15'), // in range
            $this->makeRow('2024-02-01'), // after end
        ]);

        $result = $reports->campaignInsights(self::CAMPAIGN_ID, '2024-01-01', '2024-01-31');

        $this->assertCount(1, $result);
        $this->assertSame('2024-01-15', $result[0]['date']);
    }

    public function testCampaignInsightsReturnsCorrectShape(): void
    {
        $reports = new Reports();
        $reports->import(self::CAMPAIGN_ID, [
            $this->makeRow('2024-01-15', 5000, 100, 2_500_000, 4.0, 60.00),
        ]);

        $result = $reports->campaignInsights(self::CAMPAIGN_ID, '2024-01-01', '2024-01-31');
        $row = $result[0];

        $this->assertSame(self::CAMPAIGN_ID, $row['campaign_id']);
        $this->assertSame('2024-01-15', $row['date']);
        $this->assertSame(5000, $row['impressions']);
        $this->assertSame(100, $row['clicks']);
        $this->assertSame(2_500_000, $row['cost_micros']);
        $this->assertSame(4.0, $row['conversions']);
        $this->assertSame(60.00, $row['conversion_value']);
    }

    public function testCampaignInsightsReturnsEmptyArrayWhenNoData(): void
    {
        $reports = new Reports();
        $result = $reports->campaignInsights(self::CAMPAIGN_ID, '2024-01-01', '2024-01-31');

        $this->assertSame([], $result);
    }

    public function testCampaignInsightsReturnsRowsSortedByDate(): void
    {
        $reports = new Reports();
        $reports->import(self::CAMPAIGN_ID, [
            $this->makeRow('2024-01-25'),
            $this->makeRow('2024-01-10'),
            $this->makeRow('2024-01-18'),
        ]);

        $result = $reports->campaignInsights(self::CAMPAIGN_ID, '2024-01-01', '2024-01-31');

        $this->assertSame('2024-01-10', $result[0]['date']);
        $this->assertSame('2024-01-18', $result[1]['date']);
        $this->assertSame('2024-01-25', $result[2]['date']);
    }

    public function testCampaignInsightsOnlyReturnsCampaignIdRows(): void
    {
        // Insert a campaign for a different ID
        DB::get()->exec(
            "INSERT INTO campaigns (id, project_id, platform, name, type, status)
             VALUES (2, 1, 'banner', 'Other Banner Campaign', 'display', 'active')"
        );

        $reports = new Reports();
        $reports->import(1, [$this->makeRow('2024-01-10')]);
        $reports->import(2, [$this->makeRow('2024-01-10')]);

        $result = $reports->campaignInsights(1, '2024-01-01', '2024-01-31');

        $this->assertCount(1, $result);
        $this->assertSame(1, $result[0]['campaign_id']);
    }

    public function testCampaignInsightsCostMicrosIsInt(): void
    {
        $reports = new Reports();
        $reports->import(self::CAMPAIGN_ID, [
            $this->makeRow('2024-01-15', 0, 0, 9_999_999, 0.0, 0.0),
        ]);

        $result = $reports->campaignInsights(self::CAMPAIGN_ID, '2024-01-01', '2024-01-31');

        $this->assertIsInt($result[0]['cost_micros']);
        $this->assertSame(9_999_999, $result[0]['cost_micros']);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeRow(
        string $date,
        int $impressions = 1000,
        int $clicks = 50,
        int $costMicros = 1_000_000,
        float $conversions = 1.0,
        float $conversionValue = 20.00
    ): array {
        return [
            'date'             => $date,
            'impressions'      => $impressions,
            'clicks'           => $clicks,
            'cost_micros'      => $costMicros,
            'conversions'      => $conversions,
            'conversion_value' => $conversionValue,
        ];
    }

    private function buildClientMock(string $networkName): Client
    {
        $mock = $this->createMock(Client::class);
        $mock->method('networkName')->willReturn($networkName);
        return $mock;
    }

    private function injectMockClient(Client $client): void
    {
        $ref = new \ReflectionProperty(Client::class, 'instance');
        $ref->setAccessible(true);
        $ref->setValue(null, $client);
    }
}
