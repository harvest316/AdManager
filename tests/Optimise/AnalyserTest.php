<?php

declare(strict_types=1);

namespace AdManager\Tests\Optimise;

use AdManager\DB;
use AdManager\Optimise\Analyser;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class AnalyserTest extends TestCase
{
    private PDO $db;
    private Analyser $analyser;

    protected function setUp(): void
    {
        putenv('ADMANAGER_DB_PATH=:memory:');
        DB::reset();

        $this->db = DB::get();
        $schema = file_get_contents(dirname(__DIR__, 2) . '/db/schema.sql');
        $this->db->exec($schema);

        $this->analyser = new Analyser();

        $this->seedFixtures();
    }

    protected function tearDown(): void
    {
        DB::reset();
    }

    // -------------------------------------------------------------------------
    // Fixtures
    // -------------------------------------------------------------------------

    private function seedFixtures(): void
    {
        $this->db->exec(
            "INSERT INTO projects (id, name, display_name) VALUES (1, 'test-proj', 'Test Project')"
        );

        $this->db->exec(
            "INSERT INTO campaigns (id, project_id, platform, name, type, status)
             VALUES (1, 1, 'google', 'Test Campaign', 'search', 'enabled')"
        );
    }

    private function insertGoal(int $projectId, string $metric, float $target): int
    {
        $this->db->exec(
            "INSERT INTO goals (project_id, metric, target_value)
             VALUES ({$projectId}, '{$metric}', {$target})"
        );
        return (int) $this->db->lastInsertId();
    }

    /**
     * Insert a performance row for the last N days ago.
     */
    private function insertPerformance(
        int $daysAgo,
        int $impressions,
        int $clicks,
        float $conversions = 0,
        float $conversionValue = 0,
        int $costMicros = 0
    ): void {
        $date = date('Y-m-d', strtotime("-{$daysAgo} days"));
        $this->db->exec(
            "INSERT INTO performance (campaign_id, date, impressions, clicks, conversions, conversion_value, cost_micros)
             VALUES (1, '{$date}', {$impressions}, {$clicks}, {$conversions}, {$conversionValue}, {$costMicros})"
        );
    }

    // -------------------------------------------------------------------------
    // analyse() — error handling
    // -------------------------------------------------------------------------

    public function testAnalyseThrowsForUnknownProject(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/not found/i');

        $this->analyser->analyse(9999);
    }

    // -------------------------------------------------------------------------
    // analyse() — return structure
    // -------------------------------------------------------------------------

    public function testAnalyseReturnsExpectedTopLevelKeys(): void
    {
        $result = $this->analyser->analyse(1);

        foreach (['project', 'period_days', 'performance', 'goals', 'recommendations', 'alerts'] as $key) {
            $this->assertArrayHasKey($key, $result, "Missing key: {$key}");
        }
    }

    public function testAnalyseReturnsCorrectProjectName(): void
    {
        $result = $this->analyser->analyse(1);

        // Analyser returns project['name'] (slug), not display_name
        $this->assertSame('test-proj', $result['project']);
    }

    public function testAnalyseReturnsCorrectPeriodDays(): void
    {
        $result = $this->analyser->analyse(1, 14);

        $this->assertSame(14, $result['period_days']);
    }

    // -------------------------------------------------------------------------
    // analyse() — performance calculations
    // -------------------------------------------------------------------------

    public function testAnalyseCalculatesCtrCorrectly(): void
    {
        $this->insertPerformance(1, 1000, 50);   // CTR = 5%

        $result = $this->analyser->analyse(1);

        $this->assertEqualsWithDelta(5.0, $result['performance']['ctr'], 0.001);
    }

    public function testAnalyseCalculatesConversionRateCorrectly(): void
    {
        $this->insertPerformance(1, 1000, 200, 20);  // conv rate = 10%

        $result = $this->analyser->analyse(1);

        $this->assertEqualsWithDelta(10.0, $result['performance']['conversion_rate'], 0.001);
    }

    public function testAnalyseCalculatesCpaCorrectly(): void
    {
        // 10 conversions at $100 total spend = $10 CPA
        $this->insertPerformance(1, 1000, 100, 10, 500.0, 100_000_000);

        $result = $this->analyser->analyse(1);

        $this->assertEqualsWithDelta(10.0, $result['performance']['cpa'], 0.001);
    }

    public function testAnalyseCalculatesRoasCorrectly(): void
    {
        // $500 revenue / $100 spend = ROAS 5
        $this->insertPerformance(1, 1000, 100, 10, 500.0, 100_000_000);

        $result = $this->analyser->analyse(1);

        $this->assertEqualsWithDelta(5.0, $result['performance']['roas'], 0.001);
    }

    public function testAnalyseHandlesZeroImpressions(): void
    {
        $result = $this->analyser->analyse(1);

        $this->assertSame(0, $result['performance']['impressions']);
        $this->assertSame(0, $result['performance']['clicks']);
        $this->assertSame(0.0, (float) $result['performance']['ctr']);
    }

    public function testAnalyseAggregatesMultiplePeriodRows(): void
    {
        $this->insertPerformance(1, 500, 25);
        $this->insertPerformance(2, 500, 25);

        $result = $this->analyser->analyse(1);

        $this->assertSame(1000, $result['performance']['impressions']);
        $this->assertSame(50, $result['performance']['clicks']);
    }

    public function testAnalyseExcludesDataOutsidePeriod(): void
    {
        // Older than the default 7-day window
        $this->insertPerformance(10, 9999, 9999);

        $result = $this->analyser->analyse(1, 7);

        $this->assertSame(0, $result['performance']['impressions']);
    }

    // -------------------------------------------------------------------------
    // analyse() — goal comparison
    // -------------------------------------------------------------------------

    public function testAnalyseGoalOnTrackWhenMetricMeetsTarget(): void
    {
        $this->insertPerformance(1, 1000, 50);  // CTR = 5%
        $this->insertGoal(1, 'ctr', 4.0);       // target = 4%

        $result = $this->analyser->analyse(1);

        $goalStatus = $result['goals'][0];
        $this->assertSame('on_track', $goalStatus['status']);
    }

    public function testAnalyseGoalBehindWhenMetricMissesTargetSlightly(): void
    {
        // CTR = 3%, target = 3.6% → 16.7% off — below the 25% critical threshold → "behind"
        $this->insertPerformance(1, 1000, 30);  // CTR = 3%
        $this->insertGoal(1, 'ctr', 3.6);

        $result = $this->analyser->analyse(1);

        $goalStatus = $result['goals'][0];
        $this->assertSame('behind', $goalStatus['status']);
    }

    public function testAnalyseGoalCriticalWhenMoreThan25PctOff(): void
    {
        $this->insertPerformance(1, 1000, 5);   // CTR = 0.5%
        $this->insertGoal(1, 'ctr', 5.0);       // 90% below target = critical

        $result = $this->analyser->analyse(1);

        $goalStatus = $result['goals'][0];
        $this->assertSame('critical', $goalStatus['status']);
    }

    public function testAnalyseGoalCpaOnTrackWhenActualLowerThanTarget(): void
    {
        // CPA $10 vs target $20 — lower is better for CPA
        $this->insertPerformance(1, 1000, 100, 10, 500.0, 100_000_000);
        $this->insertGoal(1, 'cpa', 20.0);

        $result = $this->analyser->analyse(1);

        $goalStatus = $result['goals'][0];
        $this->assertSame('on_track', $goalStatus['status']);
    }

    public function testAnalyseGoalCpaBehindWhenActualHigherThanTarget(): void
    {
        // CPA $40 vs target $20 — too expensive
        $this->insertPerformance(1, 1000, 100, 5, 500.0, 200_000_000);
        $this->insertGoal(1, 'cpa', 20.0);

        $result = $this->analyser->analyse(1);

        $goalStatus = $result['goals'][0];
        $this->assertNotSame('on_track', $goalStatus['status']);
    }

    public function testAnalyseGoalStatusContainsExpectedFields(): void
    {
        $this->insertPerformance(1, 1000, 50);
        $this->insertGoal(1, 'ctr', 4.0);

        $result = $this->analyser->analyse(1);

        $goalStatus = $result['goals'][0];
        foreach (['metric', 'target', 'actual', 'status', 'delta', 'pct_off'] as $field) {
            $this->assertArrayHasKey($field, $goalStatus, "Missing field: {$field}");
        }
    }

    public function testAnalyseGoalDeltaIsCorrect(): void
    {
        $this->insertPerformance(1, 1000, 50);  // CTR = 5%
        $this->insertGoal(1, 'ctr', 3.0);       // delta = +2

        $result = $this->analyser->analyse(1);

        $this->assertEqualsWithDelta(2.0, $result['goals'][0]['delta'], 0.01);
    }

    public function testAnalyseReturnsEmptyGoalsWhenNoneDefined(): void
    {
        $result = $this->analyser->analyse(1);

        $this->assertSame([], $result['goals']);
    }

    // -------------------------------------------------------------------------
    // analyse() — alerts
    // -------------------------------------------------------------------------

    public function testAnalyseGeneratesAlertForZeroImpressions(): void
    {
        $result = $this->analyser->analyse(1);

        $alertText = implode(' ', $result['alerts']);
        $this->assertStringContainsString('Zero impressions', $alertText);
    }

    public function testAnalyseGeneratesAlertForZeroClicksWithImpressions(): void
    {
        $this->insertPerformance(1, 5000, 0);

        $result = $this->analyser->analyse(1);

        $alertText = implode(' ', $result['alerts']);
        $this->assertStringContainsString('Zero clicks', $alertText);
    }

    public function testAnalyseGeneratesCriticalAlertWhenGoalIsCritical(): void
    {
        $this->insertPerformance(1, 1000, 5);   // CTR = 0.5% — well below target
        $this->insertGoal(1, 'ctr', 5.0);

        $result = $this->analyser->analyse(1);

        $alertText = implode(' ', $result['alerts']);
        $this->assertStringContainsString('CRITICAL', $alertText);
    }

    // -------------------------------------------------------------------------
    // analyse() — recommendations
    // -------------------------------------------------------------------------

    public function testAnalyseGeneratesLowCtrRecommendation(): void
    {
        $this->insertPerformance(1, 5000, 25);  // CTR = 0.5% — below 1%

        $result = $this->analyser->analyse(1);

        $recText = implode(' ', $result['recommendations']);
        $this->assertStringContainsString('CTR is below 1%', $recText);
    }

    public function testAnalyseGeneratesGoalRecommendationWhenBehind(): void
    {
        // CTR = 3%, target = 3.6% → 16.7% off → "behind" (not critical)
        $this->insertPerformance(1, 1000, 30);
        $this->insertGoal(1, 'ctr', 3.6);

        $result = $this->analyser->analyse(1);

        $recText = implode(' ', $result['recommendations']);
        $this->assertStringContainsString('ctr', $recText);
    }

    public function testAnalyseUpdatesGoalCurrentValue(): void
    {
        $this->insertPerformance(1, 1000, 50);  // CTR = 5%
        $goalId = $this->insertGoal(1, 'ctr', 4.0);

        $this->analyser->analyse(1);

        $row = $this->db->query("SELECT current_value FROM goals WHERE id = {$goalId}")->fetch();
        $this->assertEqualsWithDelta(5.0, (float) $row['current_value'], 0.01);
    }

    public function testAnalyseNoRecommendationsWhenPerformingWell(): void
    {
        $this->insertPerformance(1, 1000, 50);  // CTR = 5% — above 1%, no goals to miss
        // No goals defined, CTR > 1% → no CTR recommendation, no goal recommendations

        $result = $this->analyser->analyse(1);

        // Only the zero-impression alert could appear — it won't because we have impressions
        $this->assertSame([], $result['alerts']);
        $this->assertSame([], $result['recommendations']);
    }

    // -------------------------------------------------------------------------
    // enrichWithGA4() — GA4 bounce rate integration
    // -------------------------------------------------------------------------

    /**
     * Helper: insert a ga4_performance row for campaign_name + date.
     */
    private function insertGA4Row(
        int $projectId,
        string $campaignName,
        int $sessions,
        float $bounceRate,
        float $conversions = 0,
        float $revenue = 0.0,
        string $date = ''
    ): void {
        if ($date === '') {
            $date = date('Y-m-d', strtotime('-1 days'));
        }
        $this->db->exec(
            "INSERT INTO ga4_performance
                 (project_id, campaign_name, sessions, bounce_rate, conversions, revenue, date)
             VALUES
                 ({$projectId}, '{$campaignName}', {$sessions}, {$bounceRate}, {$conversions}, {$revenue}, '{$date}')"
        );
    }

    public function testEnrichWithGA4ReturnsEmptyWhenNoCampaignPerformance(): void
    {
        $result = $this->analyser->enrichWithGA4(1);

        $this->assertSame([], $result);
    }

    public function testEnrichWithGA4ReturnsCampaignRows(): void
    {
        $this->insertPerformance(1, 1000, 100, 5, 500.0, 100_000_000); // CPA $20

        $result = $this->analyser->enrichWithGA4(1);

        $this->assertNotEmpty($result);
        $this->assertSame(1, $result[0]['campaign_id']);
        $this->assertSame('Test Campaign', $result[0]['campaign_name']);
        $this->assertArrayHasKey('ga4_bounce_rate', $result[0]);
        $this->assertArrayHasKey('recommendation', $result[0]);
    }

    public function testEnrichWithGA4ReturnsNullBounceRateWhenNoGA4Data(): void
    {
        $this->insertPerformance(1, 1000, 100, 5, 500.0, 100_000_000);

        $result = $this->analyser->enrichWithGA4(1);

        $this->assertNull($result[0]['ga4_bounce_rate']);
    }

    public function testEnrichWithGA4RecommendsFixLandingPageWhenHighCpaAndHighBounceRate(): void
    {
        // Set CPA target: $10
        $this->db->exec("INSERT INTO goals (project_id, metric, target_value) VALUES (1, 'cpa', 10.0)");

        // Campaign: 5 conversions on $200 spend = CPA $40 (> 2× $10 target = $20)
        $this->insertPerformance(1, 1000, 100, 5, 500.0, 200_000_000);

        // GA4: 80% bounce rate (> 70%)
        $this->insertGA4Row(1, 'Test Campaign', 1000, 80.0);

        $result = $this->analyser->enrichWithGA4(1);

        $this->assertNotEmpty($result);
        $this->assertSame('fix landing page', $result[0]['recommendation']);
    }

    public function testEnrichWithGA4RecommendsAddNegativesWhenHighCpaButLowBounceRate(): void
    {
        // CPA target: $10
        $this->db->exec("INSERT INTO goals (project_id, metric, target_value) VALUES (1, 'cpa', 10.0)");

        // CPA = $40 (> 2× $10 target)
        $this->insertPerformance(1, 1000, 100, 5, 500.0, 200_000_000);

        // GA4: 50% bounce rate (< 70%)
        $this->insertGA4Row(1, 'Test Campaign', 1000, 50.0);

        $result = $this->analyser->enrichWithGA4(1);

        $this->assertSame('add negatives', $result[0]['recommendation']);
    }

    public function testEnrichWithGA4NullRecommendationWhenCpaWithinTarget(): void
    {
        // CPA target: $50
        $this->db->exec("INSERT INTO goals (project_id, metric, target_value) VALUES (1, 'cpa', 50.0)");

        // CPA = $20 (well under target)
        $this->insertPerformance(1, 1000, 100, 5, 500.0, 100_000_000);
        $this->insertGA4Row(1, 'Test Campaign', 1000, 80.0);

        $result = $this->analyser->enrichWithGA4(1);

        // CPA $20 is not > 2× $50 = $100, so no recommendation
        $this->assertNull($result[0]['recommendation']);
    }

    public function testEnrichWithGA4NullRecommendationWhenNoCpaGoalDefined(): void
    {
        // No goal defined — target = 0 → enrichment skips the CPA check
        $this->insertPerformance(1, 1000, 100, 5, 500.0, 200_000_000);
        $this->insertGA4Row(1, 'Test Campaign', 1000, 90.0);

        $result = $this->analyser->enrichWithGA4(1);

        $this->assertNull($result[0]['recommendation']);
    }

    public function testEnrichWithGA4AttachesBounceRateToCorrectCampaign(): void
    {
        // Add second campaign
        $this->db->exec(
            "INSERT INTO campaigns (id, project_id, platform, name, type, status)
             VALUES (2, 1, 'meta', 'Meta Campaign', 'display', 'enabled')"
        );

        $this->db->exec("INSERT INTO goals (project_id, metric, target_value) VALUES (1, 'cpa', 10.0)");

        // Campaign 1: high CPA
        $this->insertPerformance(1, 1000, 100, 5, 500.0, 200_000_000);
        // Campaign 2: also high CPA
        $date = date('Y-m-d', strtotime('-1 days'));
        $this->db->exec(
            "INSERT INTO performance (campaign_id, date, impressions, clicks, conversions, conversion_value, cost_micros)
             VALUES (2, '{$date}', 2000, 200, 5, 500.0, 300000000)"
        );

        // Only campaign 1 has GA4 bounce rate data
        $this->insertGA4Row(1, 'Test Campaign', 1000, 85.0);
        // 'Meta Campaign' has no GA4 data

        $result = $this->analyser->enrichWithGA4(1);

        $c1 = array_values(array_filter($result, fn($r) => $r['campaign_id'] === 1))[0] ?? null;
        $c2 = array_values(array_filter($result, fn($r) => $r['campaign_id'] === 2))[0] ?? null;

        $this->assertNotNull($c1);
        $this->assertNotNull($c2);

        $this->assertEqualsWithDelta(85.0, $c1['ga4_bounce_rate'], 0.01);
        $this->assertNull($c2['ga4_bounce_rate']);

        // Campaign 1: high CPA + high bounce → fix landing page
        $this->assertSame('fix landing page', $c1['recommendation']);
        // Campaign 2: high CPA but no bounce data → add negatives (fallback)
        $this->assertSame('add negatives', $c2['recommendation']);
    }

    public function testEnrichWithGA4ContainsExpectedOutputFields(): void
    {
        $this->insertPerformance(1, 1000, 100, 5, 500.0, 100_000_000);

        $result = $this->analyser->enrichWithGA4(1);

        $this->assertNotEmpty($result);
        foreach (['campaign_id', 'campaign_name', 'platform', 'cost', 'conversions', 'cpa', 'target_cpa', 'ga4_bounce_rate', 'recommendation'] as $field) {
            $this->assertArrayHasKey($field, $result[0], "Missing field: {$field}");
        }
    }
}
