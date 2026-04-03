<?php

declare(strict_types=1);

namespace AdManager\Tests\Reports;

use AdManager\DB;
use AdManager\Reports\CrossPlatform;
use PDO;
use PHPUnit\Framework\TestCase;

class CrossPlatformTest extends TestCase
{
    private PDO $db;
    private CrossPlatform $report;

    protected function setUp(): void
    {
        putenv('ADMANAGER_DB_PATH=:memory:');
        DB::reset();

        $this->db = DB::get();
        $schema   = file_get_contents(dirname(__DIR__, 2) . '/db/schema.sql');
        $this->db->exec($schema);

        $this->report = new CrossPlatform();

        $this->seedBase();
    }

    protected function tearDown(): void
    {
        DB::reset();
    }

    // -------------------------------------------------------------------------
    // Fixtures
    // -------------------------------------------------------------------------

    private function seedBase(): void
    {
        $this->db->exec(
            "INSERT INTO projects (id, name, display_name) VALUES (1, 'test-proj', 'Test Project')"
        );
    }

    private function insertCampaign(int $id, string $platform, string $name, float $budget = 100.0): void
    {
        $this->db->exec(
            "INSERT INTO campaigns (id, project_id, platform, name, type, status, daily_budget_aud)
             VALUES ({$id}, 1, '{$platform}', '{$name}', 'search', 'enabled', {$budget})"
        );
    }

    private function insertPerformance(
        int $campaignId,
        string $date,
        int $impressions,
        int $clicks,
        float $conversions,
        float $conversionValue,
        int $costMicros
    ): void {
        $this->db->exec(
            "INSERT INTO performance (campaign_id, date, impressions, clicks, conversions, conversion_value, cost_micros)
             VALUES ({$campaignId}, '{$date}', {$impressions}, {$clicks}, {$conversions}, {$conversionValue}, {$costMicros})"
        );
    }

    private function insertGA4Row(
        int $projectId,
        string $campaignName,
        float $conversions,
        float $revenue,
        int $sessions = 100,
        float $bounceRate = 0.5,
        string $date = '2026-03-28'
    ): void {
        $this->db->exec(
            "INSERT INTO ga4_performance
                 (project_id, campaign_name, sessions, bounce_rate, conversions, revenue, date)
             VALUES
                 ({$projectId}, '{$campaignName}', {$sessions}, {$bounceRate}, {$conversions}, {$revenue}, '{$date}')"
        );
    }

    // -------------------------------------------------------------------------
    // summary() — aggregation
    // -------------------------------------------------------------------------

    public function testSummaryReturnsEmptyWhenNoData(): void
    {
        $result = $this->report->summary(1, '2026-03-01', '2026-03-28');

        $this->assertSame([], $result['rows']);
        $this->assertSame(0.0, $result['totals']['spend']);
        $this->assertSame(0, $result['totals']['clicks']);
        $this->assertSame(0, $result['totals']['impressions']);
        $this->assertSame(0.0, $result['totals']['conversions']);
        $this->assertNull($result['totals']['cpa']);
    }

    public function testSummaryAggregatesSinglePlatform(): void
    {
        $this->insertCampaign(1, 'google', 'Google Campaign');
        // $50 spend (50_000_000 micros), 100 clicks, 1000 impressions, 5 conversions, $250 revenue
        $this->insertPerformance(1, '2026-03-20', 1000, 100, 5, 250.0, 50_000_000);
        $this->insertPerformance(1, '2026-03-21', 500, 50, 3, 150.0, 25_000_000);

        $result = $this->report->summary(1, '2026-03-01', '2026-03-28');

        $this->assertCount(1, $result['rows']);
        $row = $result['rows'][0];

        $this->assertSame('google', $row['platform']);
        $this->assertEqualsWithDelta(75.0, $row['spend'], 0.01);      // 75_000_000 micros
        $this->assertSame(150, $row['clicks']);
        $this->assertSame(1500, $row['impressions']);
        $this->assertEqualsWithDelta(8.0, $row['conversions'], 0.001);
        $this->assertEqualsWithDelta(9.38, $row['cpa'], 0.01);          // 75/8 = 9.375 → rounded to 9.38
        $this->assertEqualsWithDelta(5.3333, $row['roas'], 0.001);     // 400/75
        $this->assertEqualsWithDelta(10.0, $row['ctr'], 0.001);        // 150/1500 * 100
    }

    public function testSummaryAggregatesMultiplePlatforms(): void
    {
        $this->insertCampaign(1, 'google', 'G Campaign');
        $this->insertCampaign(2, 'meta', 'M Campaign');

        $this->insertPerformance(1, '2026-03-20', 1000, 100, 5, 500.0, 100_000_000); // $100 spend
        $this->insertPerformance(2, '2026-03-20', 2000, 80, 10, 1000.0, 80_000_000); // $80 spend

        $result = $this->report->summary(1, '2026-03-01', '2026-03-28');

        $this->assertCount(2, $result['rows']);

        $platforms = array_column($result['rows'], 'platform');
        $this->assertContains('google', $platforms);
        $this->assertContains('meta', $platforms);

        // Totals
        $this->assertEqualsWithDelta(180.0, $result['totals']['spend'], 0.01);
        $this->assertSame(180, $result['totals']['clicks']);
        $this->assertSame(3000, $result['totals']['impressions']);
        $this->assertEqualsWithDelta(15.0, $result['totals']['conversions'], 0.001);
        $this->assertEqualsWithDelta(12.0, $result['totals']['cpa'], 0.001);           // 180/15
    }

    public function testSummaryTotalsIncludeAllPlatforms(): void
    {
        $this->insertCampaign(1, 'google', 'G Campaign');
        $this->insertCampaign(2, 'meta', 'M Campaign');

        $this->insertPerformance(1, '2026-03-20', 0, 0, 5, 500.0, 50_000_000);
        $this->insertPerformance(2, '2026-03-20', 0, 0, 5, 500.0, 50_000_000);

        $result = $this->report->summary(1, '2026-03-01', '2026-03-28');

        $this->assertEqualsWithDelta(100.0, $result['totals']['spend'], 0.01);
        $this->assertEqualsWithDelta(10.0, $result['totals']['conversions'], 0.001);
    }

    public function testSummaryReturnsNullCpaWhenZeroConversions(): void
    {
        $this->insertCampaign(1, 'google', 'G Campaign');
        $this->insertPerformance(1, '2026-03-20', 1000, 50, 0, 0.0, 50_000_000);

        $result = $this->report->summary(1, '2026-03-01', '2026-03-28');

        $this->assertNull($result['rows'][0]['cpa']);
        $this->assertNull($result['totals']['cpa']);
    }

    public function testSummaryExcludesDataOutsideDateRange(): void
    {
        $this->insertCampaign(1, 'google', 'G Campaign');
        $this->insertPerformance(1, '2026-02-01', 9999, 9999, 999, 99000.0, 999_000_000);

        $result = $this->report->summary(1, '2026-03-01', '2026-03-28');

        $this->assertSame([], $result['rows']);
    }

    // -------------------------------------------------------------------------
    // conversionReconciliation() — math
    // -------------------------------------------------------------------------

    public function testReconciliationFlagsLargeDiscrepancy(): void
    {
        $this->insertCampaign(1, 'google', 'G Campaign');
        $this->insertPerformance(1, '2026-03-20', 1000, 100, 100, 10000.0, 100_000_000);

        // GA4 reports only 50 conversions — 50% discrepancy
        $this->insertGA4Row(1, 'G Campaign', 50, 5000, 1000, 0.5, '2026-03-20');

        $result = $this->report->conversionReconciliation(1, '2026-03-01', '2026-03-28');

        $this->assertCount(1, $result);
        $row = $result[0];

        $this->assertTrue($row['flagged'], 'Should be flagged when discrepancy > 15%');
        // |100 - 50| / 50 * 100 = 100%
        $this->assertEqualsWithDelta(100.0, $row['discrepancy_pct'], 0.5);
    }

    public function testReconciliationDoesNotFlagSmallDiscrepancy(): void
    {
        $this->insertCampaign(1, 'google', 'G Campaign');
        $this->insertPerformance(1, '2026-03-20', 1000, 100, 100, 10000.0, 100_000_000);

        // GA4 reports 95 — 5% discrepancy, within tolerance
        $this->insertGA4Row(1, 'G Campaign', 95, 9500, 1000, 0.4, '2026-03-20');

        $result = $this->report->conversionReconciliation(1, '2026-03-01', '2026-03-28');

        $row = $result[0];
        $this->assertFalse($row['flagged']);
        $this->assertEqualsWithDelta(5.0, $row['discrepancy_pct'], 1.0);
    }

    public function testReconciliationAdjustmentFactorCalculation(): void
    {
        $this->insertCampaign(1, 'google', 'G Campaign');
        $this->insertPerformance(1, '2026-03-20', 1000, 100, 200, 20000.0, 100_000_000);

        // GA4 = 160, platform = 200 → factor = 160/200 = 0.8
        $this->insertGA4Row(1, 'G Campaign', 160, 16000, 1000, 0.4, '2026-03-20');

        $result = $this->report->conversionReconciliation(1, '2026-03-01', '2026-03-28');

        $this->assertEqualsWithDelta(0.8, $result[0]['adjustment_factor'], 0.001);
    }

    public function testReconciliationNullAdjustmentWhenNoPlatformConversions(): void
    {
        $this->insertCampaign(1, 'google', 'G Campaign');
        $this->insertPerformance(1, '2026-03-20', 1000, 100, 0, 0.0, 100_000_000);

        $this->insertGA4Row(1, 'G Campaign', 20, 2000, 1000, 0.4, '2026-03-20');

        $result = $this->report->conversionReconciliation(1, '2026-03-01', '2026-03-28');

        $this->assertNull($result[0]['adjustment_factor']);
    }

    public function testReconciliationContainsRequiredFields(): void
    {
        $this->insertCampaign(1, 'google', 'G Campaign');
        $this->insertPerformance(1, '2026-03-20', 1000, 100, 50, 5000.0, 100_000_000);
        $this->insertGA4Row(1, 'G Campaign', 50, 5000, 1000, 0.4, '2026-03-20');

        $result = $this->report->conversionReconciliation(1, '2026-03-01', '2026-03-28');

        $this->assertNotEmpty($result);
        foreach (['platform', 'platform_conversions', 'ga4_conversions', 'adjustment_factor', 'discrepancy_pct', 'flagged'] as $field) {
            $this->assertArrayHasKey($field, $result[0], "Missing field: {$field}");
        }
    }

    // -------------------------------------------------------------------------
    // platformComparison() — winner annotations
    // -------------------------------------------------------------------------

    public function testPlatformComparisonIdentifiesBestCpa(): void
    {
        $this->insertCampaign(1, 'google', 'G Campaign');
        $this->insertCampaign(2, 'meta', 'M Campaign');

        // Google: $50 spend, 10 conv → CPA $5
        $this->insertPerformance(1, '2026-03-20', 1000, 100, 10, 1000.0, 50_000_000);
        // Meta: $80 spend, 10 conv → CPA $8
        $this->insertPerformance(2, '2026-03-20', 1000, 80, 10, 1000.0, 80_000_000);

        $result = $this->report->platformComparison(1, '2026-03-01', '2026-03-28');

        $google = array_values(array_filter($result, fn($r) => $r['platform'] === 'google'))[0];
        $meta   = array_values(array_filter($result, fn($r) => $r['platform'] === 'meta'))[0];

        $this->assertTrue($google['best_cpa'], 'Google should be best CPA');
        $this->assertFalse($meta['best_cpa'], 'Meta should NOT be best CPA');
        $this->assertNull($google['cpa_vs_best'], 'Best CPA platform has null cpa_vs_best');
        $this->assertGreaterThan(0, $meta['cpa_vs_best'], 'Meta CPA is worse than best');
    }

    public function testPlatformComparisonIdentifiesBestRoas(): void
    {
        $this->insertCampaign(1, 'google', 'G Campaign');
        $this->insertCampaign(2, 'meta', 'M Campaign');

        // Google: $100 spend, $500 revenue → ROAS 5
        $this->insertPerformance(1, '2026-03-20', 1000, 100, 5, 500.0, 100_000_000);
        // Meta: $100 spend, $300 revenue → ROAS 3
        $this->insertPerformance(2, '2026-03-20', 1000, 80, 3, 300.0, 100_000_000);

        $result = $this->report->platformComparison(1, '2026-03-01', '2026-03-28');

        $google = array_values(array_filter($result, fn($r) => $r['platform'] === 'google'))[0];
        $meta   = array_values(array_filter($result, fn($r) => $r['platform'] === 'meta'))[0];

        $this->assertTrue($google['best_roas']);
        $this->assertFalse($meta['best_roas']);
        $this->assertNull($google['roas_vs_best']);
        $this->assertGreaterThan(0, $meta['roas_vs_best']);
    }

    public function testPlatformComparisonIdentifiesBestCtr(): void
    {
        $this->insertCampaign(1, 'google', 'G Campaign');
        $this->insertCampaign(2, 'meta', 'M Campaign');

        // Google: 100 clicks / 1000 impressions = 10% CTR
        $this->insertPerformance(1, '2026-03-20', 1000, 100, 5, 500.0, 50_000_000);
        // Meta: 40 clicks / 2000 impressions = 2% CTR
        $this->insertPerformance(2, '2026-03-20', 2000, 40, 5, 500.0, 50_000_000);

        $result = $this->report->platformComparison(1, '2026-03-01', '2026-03-28');

        $google = array_values(array_filter($result, fn($r) => $r['platform'] === 'google'))[0];
        $meta   = array_values(array_filter($result, fn($r) => $r['platform'] === 'meta'))[0];

        $this->assertTrue($google['best_ctr']);
        $this->assertFalse($meta['best_ctr']);
    }

    public function testPlatformComparisonReturnsEmptyWhenNoData(): void
    {
        $result = $this->report->platformComparison(1, '2026-03-01', '2026-03-28');

        $this->assertSame([], $result);
    }

    public function testPlatformComparisonContainsAllRequiredFields(): void
    {
        $this->insertCampaign(1, 'google', 'G Campaign');
        $this->insertCampaign(2, 'meta', 'M Campaign');

        $this->insertPerformance(1, '2026-03-20', 1000, 100, 10, 1000.0, 100_000_000);
        $this->insertPerformance(2, '2026-03-20', 2000, 80, 5, 500.0, 80_000_000);

        $result = $this->report->platformComparison(1, '2026-03-01', '2026-03-28');

        $this->assertNotEmpty($result);
        foreach (['platform', 'spend', 'clicks', 'impressions', 'conversions', 'cpa', 'roas', 'ctr',
                  'best_cpa', 'best_roas', 'best_ctr', 'cpa_vs_best', 'roas_vs_best', 'ctr_vs_best'] as $field) {
            $this->assertArrayHasKey($field, $result[0], "Missing field: {$field}");
        }
    }

    public function testPlatformComparisonSinglePlatformIsBestInAllCategories(): void
    {
        $this->insertCampaign(1, 'google', 'G Campaign');
        $this->insertPerformance(1, '2026-03-20', 1000, 100, 10, 1000.0, 100_000_000);

        $result = $this->report->platformComparison(1, '2026-03-01', '2026-03-28');

        $this->assertCount(1, $result);
        $this->assertTrue($result[0]['best_cpa']);
        $this->assertTrue($result[0]['best_roas']);
        $this->assertTrue($result[0]['best_ctr']);
        $this->assertNull($result[0]['cpa_vs_best']);
        $this->assertNull($result[0]['roas_vs_best']);
        $this->assertNull($result[0]['ctr_vs_best']);
    }

    // -------------------------------------------------------------------------
    // effectivenessScore() — normalisation
    // -------------------------------------------------------------------------

    public function testEffectivenessScoreReturnsEmptyWhenNoData(): void
    {
        $result = $this->report->effectivenessScore(1, '2026-03-01', '2026-03-28');

        $this->assertSame([], $result);
    }

    public function testEffectivenessScoreReturnsExpectedFields(): void
    {
        $this->insertCampaign(1, 'google', 'G Campaign');
        $this->insertCampaign(2, 'meta',   'M Campaign');

        $this->insertPerformance(1, '2026-03-20', 1000, 100, 10, 1000.0, 100_000_000);
        $this->insertPerformance(2, '2026-03-20', 2000, 80,  5,  250.0,  80_000_000);

        $result = $this->report->effectivenessScore(1, '2026-03-01', '2026-03-28');

        $this->assertCount(2, $result);

        foreach ($result as $row) {
            $this->assertArrayHasKey('platform',   $row);
            $this->assertArrayHasKey('score',      $row);
            $this->assertArrayHasKey('components', $row);

            foreach (['roas_score', 'cpa_score', 'ctr_score', 'volume_score'] as $comp) {
                $this->assertArrayHasKey($comp, $row['components'], "Missing component: {$comp}");
            }
        }
    }

    public function testEffectivenessScoreIsIn0To100Range(): void
    {
        $this->insertCampaign(1, 'google', 'G Campaign');
        $this->insertCampaign(2, 'meta',   'M Campaign');
        $this->insertCampaign(3, 'tiktok', 'T Campaign');

        $this->insertPerformance(1, '2026-03-20', 5000, 500, 50, 5000.0,  100_000_000);
        $this->insertPerformance(2, '2026-03-20', 3000, 200, 20, 1000.0,  80_000_000);
        $this->insertPerformance(3, '2026-03-20', 1000, 80,  5,  100.0,   60_000_000);

        $result = $this->report->effectivenessScore(1, '2026-03-01', '2026-03-28');

        foreach ($result as $row) {
            $this->assertGreaterThanOrEqual(0.0,   $row['score'], "Score must be >= 0");
            $this->assertLessThanOrEqual(100.0,    $row['score'], "Score must be <= 100");
        }
    }

    public function testEffectivenessScoreHigherRoasRanksBetter(): void
    {
        $this->insertCampaign(1, 'google', 'G Campaign');
        $this->insertCampaign(2, 'meta',   'M Campaign');

        // Google: ROAS 10 (high), same conversions and CTR.
        $this->insertPerformance(1, '2026-03-20', 1000, 100, 10, 1000.0, 100_000_000);
        // Meta: ROAS 2 (low), same conversions and CTR.
        $this->insertPerformance(2, '2026-03-20', 1000, 100, 10, 200.0, 100_000_000);

        $result = $this->report->effectivenessScore(1, '2026-03-01', '2026-03-28');

        $google = array_values(array_filter($result, fn($r) => $r['platform'] === 'google'))[0];
        $meta   = array_values(array_filter($result, fn($r) => $r['platform'] === 'meta'))[0];

        $this->assertGreaterThan($meta['score'], $google['score'],
            'Google (ROAS 10) should score higher than Meta (ROAS 2)');
    }

    public function testEffectivenessScoreIsSortedDescending(): void
    {
        $this->insertCampaign(1, 'google', 'G Campaign');
        $this->insertCampaign(2, 'meta',   'M Campaign');
        $this->insertCampaign(3, 'tiktok', 'T Campaign');

        $this->insertPerformance(1, '2026-03-20', 5000, 500, 50, 5000.0,  100_000_000);
        $this->insertPerformance(2, '2026-03-20', 3000, 200, 20, 1000.0,  80_000_000);
        $this->insertPerformance(3, '2026-03-20', 1000, 50,  5,  50.0,    60_000_000);

        $result = $this->report->effectivenessScore(1, '2026-03-01', '2026-03-28');

        for ($i = 0; $i < count($result) - 1; $i++) {
            $this->assertGreaterThanOrEqual(
                $result[$i + 1]['score'],
                $result[$i]['score'],
                'effectivenessScore must be sorted descending'
            );
        }
    }

    public function testEffectivenessScoreSinglePlatformScores100(): void
    {
        $this->insertCampaign(1, 'google', 'G Campaign');
        $this->insertPerformance(1, '2026-03-20', 1000, 100, 10, 1000.0, 100_000_000);

        $result = $this->report->effectivenessScore(1, '2026-03-01', '2026-03-28');

        $this->assertCount(1, $result);
        // Single platform: all min-max normalised values = 1.0 → score = 100.
        $this->assertEqualsWithDelta(100.0, $result[0]['score'], 0.1);
    }

    public function testEffectivenessScoreComponentsAdd(): void
    {
        $this->insertCampaign(1, 'google', 'G Campaign');
        $this->insertCampaign(2, 'meta',   'M Campaign');

        $this->insertPerformance(1, '2026-03-20', 2000, 200, 20, 2000.0, 100_000_000);
        $this->insertPerformance(2, '2026-03-20', 1000, 50,  5,  200.0,  80_000_000);

        $result = $this->report->effectivenessScore(1, '2026-03-01', '2026-03-28');

        foreach ($result as $row) {
            $components = $row['components'];
            // Weighted sum (each component is 0-100).
            $weighted = $components['roas_score']   * 0.40
                      + $components['cpa_score']    * 0.30
                      + $components['ctr_score']    * 0.15
                      + $components['volume_score'] * 0.15;
            $this->assertEqualsWithDelta($row['score'], $weighted, 0.5,
                "Weighted component sum should equal overall score");
        }
    }

    // -------------------------------------------------------------------------
    // timeSeries() — date grouping
    // -------------------------------------------------------------------------

    public function testTimeSeriesReturnsEmptyWhenNoData(): void
    {
        $result = $this->report->timeSeries(1, '2026-03-01', '2026-03-28');

        $this->assertSame([], $result['dates']);
        $this->assertSame([], $result['platforms']);
    }

    public function testTimeSeriesGroupsByDay(): void
    {
        $this->insertCampaign(1, 'google', 'G Campaign');
        $this->insertPerformance(1, '2026-03-20', 1000, 100, 5, 500.0, 50_000_000);
        $this->insertPerformance(1, '2026-03-21', 2000, 200, 8, 800.0, 60_000_000);

        $result = $this->report->timeSeries(1, '2026-03-01', '2026-03-28', 'day');

        $this->assertContains('2026-03-20', $result['dates']);
        $this->assertContains('2026-03-21', $result['dates']);
        $this->assertCount(2, $result['platforms']['google']);
    }

    public function testTimeSeriesGroupsByWeek(): void
    {
        $this->insertCampaign(1, 'google', 'G Campaign');
        // Two days in the same calendar week.
        $this->insertPerformance(1, '2026-03-20', 1000, 100, 5, 500.0,  50_000_000);
        $this->insertPerformance(1, '2026-03-21', 2000, 200, 8, 800.0,  60_000_000);
        // A day in a different week.
        $this->insertPerformance(1, '2026-03-28', 500, 50, 2, 200.0,  20_000_000);

        $result = $this->report->timeSeries(1, '2026-03-01', '2026-03-31', 'week');

        // Rows for the first week (2026-W11/W12 depending on locale) and the last week.
        $googleRows = $result['platforms']['google'] ?? [];
        $this->assertGreaterThanOrEqual(2, count($googleRows), 'Should have at least 2 week buckets');
    }

    public function testTimeSeriesSeparatesMultiplePlatforms(): void
    {
        $this->insertCampaign(1, 'google', 'G Campaign');
        $this->insertCampaign(2, 'meta',   'M Campaign');

        $this->insertPerformance(1, '2026-03-20', 1000, 100, 5, 500.0,  50_000_000);
        $this->insertPerformance(2, '2026-03-20', 2000, 80,  3, 300.0,  40_000_000);

        $result = $this->report->timeSeries(1, '2026-03-01', '2026-03-28', 'day');

        $this->assertArrayHasKey('google', $result['platforms']);
        $this->assertArrayHasKey('meta',   $result['platforms']);
    }

    public function testTimeSeriesRowContainsExpectedFields(): void
    {
        $this->insertCampaign(1, 'google', 'G Campaign');
        $this->insertPerformance(1, '2026-03-20', 1000, 100, 5, 500.0, 50_000_000);

        $result = $this->report->timeSeries(1, '2026-03-01', '2026-03-28', 'day');

        $row = $result['platforms']['google'][0];

        foreach (['date', 'spend', 'clicks', 'impressions', 'conversions', 'cpa', 'roas'] as $field) {
            $this->assertArrayHasKey($field, $row, "Missing field: {$field}");
        }
    }

    public function testTimeSeriesDatesSortedAscending(): void
    {
        $this->insertCampaign(1, 'google', 'G Campaign');
        $this->insertPerformance(1, '2026-03-22', 1000, 100, 5, 500.0, 50_000_000);
        $this->insertPerformance(1, '2026-03-20', 2000, 200, 8, 800.0, 60_000_000);
        $this->insertPerformance(1, '2026-03-21', 500,  50,  2, 100.0, 20_000_000);

        $result = $this->report->timeSeries(1, '2026-03-01', '2026-03-28', 'day');

        $dates = $result['dates'];
        $sorted = $dates;
        sort($sorted);

        $this->assertSame($sorted, $dates, 'Dates must be sorted ascending');
    }

    // -------------------------------------------------------------------------
    // rebalanceRecommendations() — gap threshold
    // -------------------------------------------------------------------------

    public function testRebalanceReturnsEmptyWhenFewerThanTwoPlatforms(): void
    {
        $this->insertCampaign(1, 'google', 'G Campaign');
        $this->insertPerformance(1, '2026-03-20', 1000, 100, 10, 1000.0, 100_000_000);
        $this->db->exec(
            "INSERT INTO budgets (project_id, platform, daily_budget_aud) VALUES (1, 'google', 100.0)"
        );

        $result = $this->report->rebalanceRecommendations(1, '2026-03-01', '2026-03-28');

        $this->assertSame([], $result);
    }

    public function testRebalanceReturnsEmptyWhenNoBudgetConfigured(): void
    {
        $this->insertCampaign(1, 'google', 'G Campaign');
        $this->insertCampaign(2, 'meta',   'M Campaign');

        $this->insertPerformance(1, '2026-03-20', 1000, 100, 10, 1000.0, 100_000_000);
        $this->insertPerformance(2, '2026-03-20', 2000, 80,  5,  200.0,  80_000_000);

        // No budgets table entries — no rebalancing possible.
        $result = $this->report->rebalanceRecommendations(1, '2026-03-01', '2026-03-28');

        $this->assertSame([], $result);
    }

    public function testRebalanceIgnoresPairsWithGapUnder10Pct(): void
    {
        $this->insertCampaign(1, 'google', 'G Campaign');
        $this->insertCampaign(2, 'meta',   'M Campaign');

        // Very similar performance — gap should be < 10%.
        $this->insertPerformance(1, '2026-03-20', 1000, 100, 10, 1000.0, 100_000_000);
        $this->insertPerformance(2, '2026-03-20', 1000, 100, 10, 1000.0, 100_000_000);

        $this->db->exec(
            "INSERT INTO budgets (project_id, platform, daily_budget_aud) VALUES (1, 'google', 100.0)"
        );
        $this->db->exec(
            "INSERT INTO budgets (project_id, platform, daily_budget_aud) VALUES (1, 'meta', 100.0)"
        );

        $result = $this->report->rebalanceRecommendations(1, '2026-03-01', '2026-03-28');

        $this->assertSame([], $result);
    }

    public function testRebalanceRecommendsMoveFromLowToHighEffectiveness(): void
    {
        $this->insertCampaign(1, 'google', 'G Campaign');
        $this->insertCampaign(2, 'meta',   'M Campaign');

        // Google: very strong; Meta: very weak.
        $this->insertPerformance(1, '2026-03-20', 5000, 500, 50, 5000.0, 100_000_000);
        $this->insertPerformance(2, '2026-03-20', 1000, 50,  2,  20.0,   100_000_000);

        // Equal budget split.
        $this->db->exec(
            "INSERT INTO budgets (project_id, platform, daily_budget_aud) VALUES (1, 'google', 100.0)"
        );
        $this->db->exec(
            "INSERT INTO budgets (project_id, platform, daily_budget_aud) VALUES (1, 'meta', 100.0)"
        );

        $result = $this->report->rebalanceRecommendations(1, '2026-03-01', '2026-03-28');

        $this->assertNotEmpty($result);
        $rec = $result[0];

        $this->assertArrayHasKey('from_platform',    $rec);
        $this->assertArrayHasKey('to_platform',      $rec);
        $this->assertArrayHasKey('shift_amount_aud', $rec);
        $this->assertArrayHasKey('reason',           $rec);

        // Move from Meta (under-performer) to Google (over-performer).
        $this->assertSame('meta',   $rec['from_platform']);
        $this->assertSame('google', $rec['to_platform']);
        $this->assertGreaterThan(0, $rec['shift_amount_aud']);
    }

    public function testRebalanceShiftIsConservativelyHalfOfGap(): void
    {
        $this->insertCampaign(1, 'google', 'G Campaign');
        $this->insertCampaign(2, 'meta',   'M Campaign');

        // Large performance gap.
        $this->insertPerformance(1, '2026-03-20', 5000, 500, 100, 10000.0, 100_000_000);
        $this->insertPerformance(2, '2026-03-20', 1000, 50,   2,    10.0,  100_000_000);

        $this->db->exec(
            "INSERT INTO budgets (project_id, platform, daily_budget_aud) VALUES (1, 'google', 100.0)"
        );
        $this->db->exec(
            "INSERT INTO budgets (project_id, platform, daily_budget_aud) VALUES (1, 'meta', 100.0)"
        );

        $result = $this->report->rebalanceRecommendations(1, '2026-03-01', '2026-03-28');

        $this->assertNotEmpty($result);
        // Shift should be <= 50% of total budget (conservative guard).
        $totalBudget = 200.0;
        $this->assertLessThanOrEqual($totalBudget * 0.5, $result[0]['shift_amount_aud']);
    }

    public function testRebalanceSortedByShiftAmountDescending(): void
    {
        $this->insertCampaign(1, 'google', 'G Campaign');
        $this->insertCampaign(2, 'meta',   'M Campaign');
        $this->insertCampaign(3, 'tiktok', 'T Campaign');

        $this->insertPerformance(1, '2026-03-20', 5000, 500, 100, 10000.0, 100_000_000);
        $this->insertPerformance(2, '2026-03-20', 1000, 50,   2,    10.0,   80_000_000);
        $this->insertPerformance(3, '2026-03-20', 2000, 100,  10,  200.0,   60_000_000);

        $this->db->exec("INSERT INTO budgets (project_id, platform, daily_budget_aud) VALUES (1, 'google', 100.0)");
        $this->db->exec("INSERT INTO budgets (project_id, platform, daily_budget_aud) VALUES (1, 'meta', 100.0)");
        $this->db->exec("INSERT INTO budgets (project_id, platform, daily_budget_aud) VALUES (1, 'tiktok', 100.0)");

        $result = $this->report->rebalanceRecommendations(1, '2026-03-01', '2026-03-28');

        for ($i = 0; $i < count($result) - 1; $i++) {
            $this->assertGreaterThanOrEqual(
                $result[$i + 1]['shift_amount_aud'],
                $result[$i]['shift_amount_aud'],
                'rebalanceRecommendations should be sorted by shift amount descending'
            );
        }
    }
}
