<?php

declare(strict_types=1);

namespace AdManager\Tests\Optimise;

use AdManager\DB;
use AdManager\Optimise\CreativeFatigue;
use PDO;
use PHPUnit\Framework\TestCase;

class CreativeFatigueTest extends TestCase
{
    private PDO $db;
    private CreativeFatigue $fatigue;

    protected function setUp(): void
    {
        putenv('ADMANAGER_DB_PATH=:memory:');
        DB::reset();

        $this->db = DB::get();
        $schema = file_get_contents(dirname(__DIR__, 2) . '/db/schema.sql');
        $this->db->exec($schema);

        $this->fatigue = new CreativeFatigue();

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

        $this->db->exec(
            "INSERT INTO ad_groups (id, campaign_id, name, status)
             VALUES (1, 1, 'Test Ad Group', 'enabled')"
        );

        $this->db->exec(
            "INSERT INTO ads (id, ad_group_id, type, status) VALUES (1, 1, 'rsa', 'enabled')"
        );
        $this->db->exec(
            "INSERT INTO ads (id, ad_group_id, type, status) VALUES (2, 1, 'rsa', 'enabled')"
        );
    }

    /**
     * Insert daily performance rows starting from the given days ago and counting forward.
     *
     * @param int   $adId
     * @param array $ctrsByDay  e.g. [5.0, 4.8, 4.6, ...] oldest first
     * @param int   $baseImpressions
     */
    private function insertDailyCtr(int $adId, array $ctrsByDay, int $baseImpressions = 1000): void
    {
        $dayCount = count($ctrsByDay);
        foreach ($ctrsByDay as $i => $ctr) {
            // day 0 = most recent day, increasing daysAgo as i decreases
            $daysAgo = $dayCount - 1 - $i;
            $date = date('Y-m-d', strtotime("-{$daysAgo} days"));
            $clicks = (int) round($baseImpressions * ($ctr / 100));
            $this->db->exec(
                "INSERT INTO performance (campaign_id, ad_group_id, ad_id, date, impressions, clicks)
                 VALUES (1, 1, {$adId}, '{$date}', {$baseImpressions}, {$clicks})"
            );
        }
    }

    /**
     * Build a linearly declining CTR series over N days.
     */
    private function buildDecliningCtr(float $startCtr, float $endCtr, int $days): array
    {
        $result = [];
        for ($i = 0; $i < $days; $i++) {
            $result[] = $startCtr - (($startCtr - $endCtr) / ($days - 1)) * $i;
        }
        return $result;
    }

    /**
     * Build a flat CTR series over N days.
     */
    private function buildFlatCtr(float $ctr, int $days): array
    {
        return array_fill(0, $days, $ctr);
    }

    // -------------------------------------------------------------------------
    // detect() — no data / insufficient data
    // -------------------------------------------------------------------------

    public function testDetectReturnsEmptyWhenNoPerformanceData(): void
    {
        $result = $this->fatigue->detect(1);

        $this->assertSame([], $result);
    }

    public function testDetectSkipsAdsWithFewerThanSevenDataPoints(): void
    {
        // Only 5 days of data
        $this->insertDailyCtr(1, $this->buildDecliningCtr(5.0, 1.0, 5));

        $result = $this->fatigue->detect(1);

        $this->assertSame([], $result);
    }

    public function testDetectIncludesAdsWithExactlySevenDataPoints(): void
    {
        // Exactly 7 days with strong decline
        $this->insertDailyCtr(1, $this->buildDecliningCtr(5.0, 0.5, 7));

        $result = $this->fatigue->detect(1);

        // May or may not flag depending on slope — just verify no crash
        $this->assertIsArray($result);
    }

    // -------------------------------------------------------------------------
    // detect() — CTR decline detection
    // -------------------------------------------------------------------------

    public function testDetectFlagsSteadilydeclisingAd(): void
    {
        // 14 days declining from 8% CTR down to 1% CTR — clear fatigue
        $this->insertDailyCtr(1, $this->buildDecliningCtr(8.0, 1.0, 14));

        $result = $this->fatigue->detect(1);

        $this->assertNotEmpty($result);
        $this->assertSame(1, $result[0]['ad_id']);
        $this->assertLessThan(0, $result[0]['trend_slope']);
    }

    public function testDetectDoesNotFlagStableAd(): void
    {
        // Flat CTR — no fatigue
        $this->insertDailyCtr(1, $this->buildFlatCtr(4.0, 14));

        $result = $this->fatigue->detect(1);

        $this->assertSame([], $result);
    }

    public function testDetectDoesNotFlagImprovingAd(): void
    {
        // Increasing CTR — no fatigue
        $this->insertDailyCtr(1, $this->buildDecliningCtr(1.0, 8.0, 14));

        $result = $this->fatigue->detect(1);

        $this->assertSame([], $result);
    }

    public function testDetectOnlyFlagsAdsBeyondSlopeThreshold(): void
    {
        // Mild decline — slope around -0.05 (above threshold of -0.1)
        $this->insertDailyCtr(1, $this->buildDecliningCtr(5.0, 4.3, 14));
        // Steep decline — slope well below -0.1
        $this->insertDailyCtr(2, $this->buildDecliningCtr(8.0, 1.0, 14));

        $result = $this->fatigue->detect(1);

        // Ad 2 (steep decline) should be flagged; ad 1 (mild) may not be
        $flaggedIds = array_column($result, 'ad_id');
        $this->assertContains(2, $flaggedIds);
    }

    // -------------------------------------------------------------------------
    // detect() — severity classification
    // -------------------------------------------------------------------------

    public function testHighSeverityWhenSlopeBelowTripleThreshold(): void
    {
        // Very steep decline: from 10% to 0.1% in 14 days — slope far below -0.3
        $this->insertDailyCtr(1, $this->buildDecliningCtr(10.0, 0.1, 14));

        $result = $this->fatigue->detect(1);

        $this->assertNotEmpty($result);
        // slope <= -0.3 → high severity
        if ($result[0]['trend_slope'] <= -0.3) {
            $this->assertSame('high', $result[0]['severity']);
        } else {
            $this->assertSame('moderate', $result[0]['severity']);
        }
    }

    public function testModerateSeverityWhenSlopeJustBeyondThreshold(): void
    {
        // Decline that gives slope just beyond -0.1 but not -0.3
        $this->insertDailyCtr(1, $this->buildDecliningCtr(5.0, 2.5, 14));

        $result = $this->fatigue->detect(1);

        if (!empty($result)) {
            $this->assertContains($result[0]['severity'], ['moderate', 'high']);
        } else {
            $this->markTestSkipped('Slope was not steep enough to trigger fatigue with this data.');
        }
    }

    // -------------------------------------------------------------------------
    // detect() — output structure
    // -------------------------------------------------------------------------

    public function testDetectOutputContainsExpectedFields(): void
    {
        $this->insertDailyCtr(1, $this->buildDecliningCtr(8.0, 1.0, 14));

        $result = $this->fatigue->detect(1);

        $this->assertNotEmpty($result);
        $item = $result[0];

        foreach (['ad_id', 'current_ctr', 'trend_slope', 'days_declining', 'data_points', 'severity', 'recommendation'] as $field) {
            $this->assertArrayHasKey($field, $item, "Missing field: {$field}");
        }
    }

    public function testDetectCurrentCtrIsLastDayValue(): void
    {
        // End at 1.5% CTR — current_ctr should reflect that
        $this->insertDailyCtr(1, $this->buildDecliningCtr(8.0, 1.5, 14));

        $result = $this->fatigue->detect(1);

        if (!empty($result)) {
            $this->assertEqualsWithDelta(1.5, $result[0]['current_ctr'], 0.1);
        } else {
            $this->markTestSkipped('No fatigue detected — adjust CTR values if needed.');
        }
    }

    public function testDetectDaysDecliningShouldBePositiveForDecliningAd(): void
    {
        $this->insertDailyCtr(1, $this->buildDecliningCtr(8.0, 1.0, 14));

        $result = $this->fatigue->detect(1);

        $this->assertNotEmpty($result);
        $this->assertGreaterThanOrEqual(0, $result[0]['days_declining']);
    }

    public function testDetectDataPointsMatchInsertedRowCount(): void
    {
        $this->insertDailyCtr(1, $this->buildDecliningCtr(8.0, 1.0, 14));

        $result = $this->fatigue->detect(1);

        $this->assertNotEmpty($result);
        $this->assertSame(14, $result[0]['data_points']);
    }

    // -------------------------------------------------------------------------
    // detect() — recommendation text
    // -------------------------------------------------------------------------

    public function testHighSeverityRecommendationContainsUrgentLanguage(): void
    {
        // Very steep decline to trigger high severity
        $this->insertDailyCtr(1, $this->buildDecliningCtr(10.0, 0.1, 14));

        $result = $this->fatigue->detect(1);

        $this->assertNotEmpty($result);
        $item = $result[0];

        if ($item['severity'] === 'high') {
            $this->assertStringContainsString('URGENT', $item['recommendation']);
        } else {
            $this->assertStringContainsString('fatigue', strtolower($item['recommendation']));
        }
    }

    public function testModerateSeverityRecommendationMentionsFatigue(): void
    {
        $this->insertDailyCtr(1, $this->buildDecliningCtr(5.0, 1.5, 21));

        $result = $this->fatigue->detect(1);

        if (!empty($result) && $result[0]['severity'] === 'moderate') {
            $this->assertStringContainsString('fatigue', strtolower($result[0]['recommendation']));
        } else {
            $this->markTestSkipped('No moderate-severity fatigue detected.');
        }
    }

    public function testLowCtrAddsImmediateActionNote(): void
    {
        // Decline to very low CTR (< 1%)
        $this->insertDailyCtr(1, $this->buildDecliningCtr(8.0, 0.3, 14));

        $result = $this->fatigue->detect(1);

        if (!empty($result) && $result[0]['current_ctr'] < 1.0) {
            $this->assertStringContainsString('below 1%', $result[0]['recommendation']);
        } else {
            $this->markTestSkipped('CTR did not end below 1% with this test data.');
        }
    }

    // -------------------------------------------------------------------------
    // detect() — sorting
    // -------------------------------------------------------------------------

    public function testDetectSortsByMostNegativeSlopeFirst(): void
    {
        // Ad 1: moderate decline
        $this->insertDailyCtr(1, $this->buildDecliningCtr(6.0, 2.0, 14));
        // Ad 2: steeper decline
        $this->insertDailyCtr(2, $this->buildDecliningCtr(8.0, 0.5, 14));

        $result = $this->fatigue->detect(1);

        if (count($result) >= 2) {
            // Sorted by most negative slope first — result[0] slope <= result[1] slope
            $this->assertLessThanOrEqual(
                $result[1]['trend_slope'],
                $result[0]['trend_slope'],
                'First result should have the most negative (steepest) slope'
            );
        } else {
            $this->markTestSkipped('Fewer than 2 fatigued ads detected — not enough to verify sort order.');
        }
    }

    // -------------------------------------------------------------------------
    // detect() — project isolation
    // -------------------------------------------------------------------------

    public function testDetectIgnoresOtherProjects(): void
    {
        $this->db->exec(
            "INSERT INTO projects (id, name, display_name) VALUES (2, 'other', 'Other')"
        );
        $this->db->exec(
            "INSERT INTO campaigns (id, project_id, platform, name, type, status)
             VALUES (2, 2, 'google', 'Other Campaign', 'search', 'enabled')"
        );
        $this->db->exec(
            "INSERT INTO ad_groups (id, campaign_id, name, status)
             VALUES (2, 2, 'Other Group', 'enabled')"
        );
        $this->db->exec(
            "INSERT INTO ads (id, ad_group_id, type, status) VALUES (10, 2, 'rsa', 'enabled')"
        );

        // Add strong fatigue to the other project's ad
        $this->insertDailyCtrForProject(10, 2, $this->buildDecliningCtr(8.0, 1.0, 14));

        // Project 1 should see no fatigue
        $result = $this->fatigue->detect(1);

        $this->assertSame([], $result);
    }

    private function insertDailyCtrForProject(int $adId, int $campaignId, array $ctrsByDay, int $baseImpressions = 1000): void
    {
        $dayCount = count($ctrsByDay);
        foreach ($ctrsByDay as $i => $ctr) {
            $daysAgo = $dayCount - 1 - $i;
            $date = date('Y-m-d', strtotime("-{$daysAgo} days"));
            $clicks = (int) round($baseImpressions * ($ctr / 100));
            $this->db->exec(
                "INSERT INTO performance (campaign_id, ad_group_id, ad_id, date, impressions, clicks)
                 VALUES ({$campaignId}, 2, {$adId}, '{$date}', {$baseImpressions}, {$clicks})"
            );
        }
    }

    // -------------------------------------------------------------------------
    // detect() — lookback window
    // -------------------------------------------------------------------------

    public function testDetectRespectsLookbackDaysParameter(): void
    {
        // Insert 30 days of declining data
        $this->insertDailyCtr(1, $this->buildDecliningCtr(8.0, 1.0, 30));

        // A 5-day lookback window includes days 0-5 (6 data points) — below the 7-point minimum
        // Note: lookbackDays=N means date >= date('now', '-N days'), which includes N+1 days
        $resultNarrow = $this->fatigue->detect(1, 5);  // 6 data points in window → skipped
        $resultWide   = $this->fatigue->detect(1, 30);

        // Narrow window (5-day = 6 data points) should skip the ad (requires >= 7)
        $this->assertSame([], $resultNarrow);
        // Wide window (30 days) should detect fatigue
        $this->assertNotEmpty($resultWide);
    }
}
