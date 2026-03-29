<?php

declare(strict_types=1);

namespace AdManager\Tests\Optimise;

use AdManager\DB;
use AdManager\Optimise\BidStrategyManager;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Tests for BidStrategyManager.
 *
 * Uses in-memory SQLite with schema.sql (same pattern as AnalyserTest).
 * Google Ads API calls are guarded by the "no external_id" path so they
 * never fire in unit tests.
 *
 * Coverage:
 * - All 4 conversion thresholds (< 15, 15–30, 30–50, 50+)
 * - evaluate() returns correct recommendation fields
 * - evaluate() handles campaigns with no performance data
 * - evaluate() only returns Google campaigns (not Meta)
 * - apply() skips campaigns where strategy hasn't changed
 * - apply() updates bid_strategy column in DB
 * - apply() returns applied and errors arrays
 * - tCPA multipliers are correct (2x loose, 1.2x tight)
 */
class BidStrategyManagerTest extends TestCase
{
    private PDO $db;
    private BidStrategyManager $manager;

    protected function setUp(): void
    {
        putenv('ADMANAGER_DB_PATH=:memory:');
        DB::reset();

        $this->db = DB::get();
        $schema   = file_get_contents(dirname(__DIR__, 2) . '/db/schema.sql');
        $this->db->exec($schema);

        // Add bid_strategy column if not in schema (migration guard)
        try {
            $this->db->exec("ALTER TABLE campaigns ADD COLUMN bid_strategy TEXT DEFAULT 'manual_cpc'");
        } catch (\PDOException) {
            // Column already exists in schema — that's fine
        }

        $this->manager = new BidStrategyManager();
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
    }

    private function insertCampaign(
        int $id,
        string $platform = 'google',
        string $bidStrategy = 'manual_cpc',
        string $status = 'enabled',
        ?string $externalId = null
    ): void {
        $extVal = $externalId ? "'{$externalId}'" : 'NULL';
        $this->db->exec(
            "INSERT INTO campaigns (id, project_id, platform, name, type, status, bid_strategy, external_id)
             VALUES ({$id}, 1, '{$platform}', 'Campaign {$id}', 'search', '{$status}', '{$bidStrategy}', {$extVal})"
        );
    }

    /**
     * Insert 30 days of equal daily performance rows that sum to the given totals.
     */
    private function insertPerformance(
        int $campaignId,
        float $totalConversions,
        int $totalCostMicros = 0,
        int $daysBack = 30
    ): void {
        $dailyConversions = $totalConversions / $daysBack;
        $dailyCost        = (int) ($totalCostMicros / $daysBack);

        for ($i = 1; $i <= $daysBack; $i++) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $this->db->exec(
                "INSERT INTO performance (campaign_id, date, conversions, cost_micros)
                 VALUES ({$campaignId}, '{$date}', {$dailyConversions}, {$dailyCost})"
            );
        }
    }

    // -------------------------------------------------------------------------
    // evaluate() — conversion thresholds
    // -------------------------------------------------------------------------

    public function testEvaluateRecommendsStayOnManualCpcBelowFifteenConversions(): void
    {
        $this->insertCampaign(1, 'google', 'manual_cpc');
        $this->insertPerformance(1, 10); // 10 conversions — below threshold

        $results = $this->manager->evaluate(1);

        $this->assertCount(1, $results);
        $rec = $results[0];
        $this->assertSame('manual_cpc', $rec['recommended_strategy']);
        $this->assertNull($rec['target_cpa']);
        $this->assertStringContainsString('15', $rec['reason']);
    }

    public function testEvaluateRecommendsMaximizeConversionsBetweenFifteenAndThirty(): void
    {
        $this->insertCampaign(1, 'google', 'manual_cpc');
        $this->insertPerformance(1, 20); // 20 conversions — 15–30 range

        $results = $this->manager->evaluate(1);

        $this->assertCount(1, $results);
        $rec = $results[0];
        $this->assertSame('maximize_conversions', $rec['recommended_strategy']);
        $this->assertNull($rec['target_cpa']);
    }

    public function testEvaluateRecommendsLooseTcpaBetweenThirtyAndFifty(): void
    {
        $this->insertCampaign(1, 'google', 'maximize_conversions');
        // 40 conversions, $200 total cost = $5 actual CPA
        $this->insertPerformance(1, 40, 200_000_000);

        $results = $this->manager->evaluate(1);

        $this->assertCount(1, $results);
        $rec = $results[0];
        $this->assertSame('maximize_conversions_with_tcpa', $rec['recommended_strategy']);
        // tCPA = 2x actual CPA = 2 * 5 = $10
        $this->assertNotNull($rec['target_cpa']);
        $this->assertEqualsWithDelta(10.0, $rec['target_cpa'], 0.01);
    }

    public function testEvaluateRecommendsTighterTcpaAboveFiftyConversions(): void
    {
        $this->insertCampaign(1, 'google', 'maximize_conversions_with_tcpa');
        // 60 conversions, $300 total cost = $5 actual CPA
        $this->insertPerformance(1, 60, 300_000_000);

        $results = $this->manager->evaluate(1);

        $this->assertCount(1, $results);
        $rec = $results[0];
        $this->assertSame('maximize_conversions_tcpa_tightened', $rec['recommended_strategy']);
        // tCPA = 1.2x actual CPA = 1.2 * 5 = $6
        $this->assertNotNull($rec['target_cpa']);
        $this->assertEqualsWithDelta(6.0, $rec['target_cpa'], 0.01);
    }

    // -------------------------------------------------------------------------
    // evaluate() — exact boundary values
    // -------------------------------------------------------------------------

    public function testEvaluateThresholdBoundaryExactlyFifteen(): void
    {
        $this->insertCampaign(1, 'google', 'manual_cpc');
        $this->insertPerformance(1, 15.0);

        $results = $this->manager->evaluate(1);

        $this->assertSame('maximize_conversions', $results[0]['recommended_strategy']);
    }

    public function testEvaluateThresholdBoundaryExactlyThirty(): void
    {
        $this->insertCampaign(1, 'google', 'manual_cpc');
        $this->insertPerformance(1, 30.0, 150_000_000);

        $results = $this->manager->evaluate(1);

        // 30 is within 30–50 range → loose tCPA
        $this->assertSame('maximize_conversions_with_tcpa', $results[0]['recommended_strategy']);
    }

    public function testEvaluateThresholdBoundaryExactlyFiftyOne(): void
    {
        $this->insertCampaign(1, 'google', 'maximize_conversions_with_tcpa');
        $this->insertPerformance(1, 51.0, 255_000_000);

        $results = $this->manager->evaluate(1);

        $this->assertSame('maximize_conversions_tcpa_tightened', $results[0]['recommended_strategy']);
    }

    // -------------------------------------------------------------------------
    // evaluate() — no performance data
    // -------------------------------------------------------------------------

    public function testEvaluateHandlesCampaignWithNoPerformanceData(): void
    {
        $this->insertCampaign(1, 'google', 'manual_cpc');
        // No performance rows inserted

        $results = $this->manager->evaluate(1);

        $this->assertCount(1, $results);
        $rec = $results[0];
        $this->assertSame(0.0, $rec['conversion_count']);
        $this->assertNull($rec['actual_cpa']);
        $this->assertSame('manual_cpc', $rec['recommended_strategy']); // stay on current
    }

    public function testEvaluateReturnsEmptyArrayWhenNoGoogleCampaigns(): void
    {
        // No campaigns inserted at all
        $results = $this->manager->evaluate(1);

        $this->assertSame([], $results);
    }

    // -------------------------------------------------------------------------
    // evaluate() — platform filtering
    // -------------------------------------------------------------------------

    public function testEvaluateOnlyReturnsGoogleCampaigns(): void
    {
        $this->insertCampaign(1, 'google', 'manual_cpc');
        $this->insertCampaign(2, 'meta', 'manual_cpc');

        $results = $this->manager->evaluate(1);

        // Only the Google campaign should be returned
        $this->assertCount(1, $results);
        $this->assertSame(1, $results[0]['campaign_id']);
    }

    public function testEvaluateSkipsRemovedAndDraftCampaigns(): void
    {
        $this->insertCampaign(1, 'google', 'manual_cpc', 'removed');
        $this->insertCampaign(2, 'google', 'manual_cpc', 'draft');
        $this->insertCampaign(3, 'google', 'manual_cpc', 'enabled');

        $results = $this->manager->evaluate(1);

        $this->assertCount(1, $results);
        $this->assertSame(3, $results[0]['campaign_id']);
    }

    // -------------------------------------------------------------------------
    // evaluate() — output structure
    // -------------------------------------------------------------------------

    public function testEvaluateReturnsAllExpectedFields(): void
    {
        $this->insertCampaign(1, 'google', 'manual_cpc');
        $this->insertPerformance(1, 20);

        $results = $this->manager->evaluate(1);

        $this->assertCount(1, $results);
        $rec = $results[0];

        foreach ([
            'campaign_id',
            'campaign_name',
            'external_id',
            'current_strategy',
            'recommended_strategy',
            'target_cpa',
            'conversion_count',
            'actual_cpa',
            'reason',
        ] as $field) {
            $this->assertArrayHasKey($field, $rec, "Missing field: {$field}");
        }
    }

    public function testEvaluateIncludesCurrentStrategyInResult(): void
    {
        $this->insertCampaign(1, 'google', 'maximize_conversions');
        $this->insertPerformance(1, 20);

        $results = $this->manager->evaluate(1);

        $this->assertSame('maximize_conversions', $results[0]['current_strategy']);
    }

    public function testEvaluateConversionCountMatchesInsertedData(): void
    {
        $this->insertCampaign(1, 'google', 'manual_cpc');
        $this->insertPerformance(1, 22.0);

        $results = $this->manager->evaluate(1);

        $this->assertEqualsWithDelta(22.0, $results[0]['conversion_count'], 0.1);
    }

    // -------------------------------------------------------------------------
    // evaluate() — tCPA multipliers
    // -------------------------------------------------------------------------

    public function testLooseTcpaIsDoubleActualCpa(): void
    {
        $this->insertCampaign(1, 'google', 'maximize_conversions');
        // 40 conversions at $100 total = $2.50 CPA
        $this->insertPerformance(1, 40, 100_000_000);

        $results = $this->manager->evaluate(1);

        $rec = $results[0];
        $this->assertSame('maximize_conversions_with_tcpa', $rec['recommended_strategy']);
        // Actual CPA = $2.50, loose tCPA = $5.00
        $this->assertEqualsWithDelta(5.0, $rec['target_cpa'], 0.01);
    }

    public function testTightTcpaIs1Point2xActualCpa(): void
    {
        $this->insertCampaign(1, 'google', 'maximize_conversions_with_tcpa');
        // 60 conversions at $120 total = $2.00 CPA
        $this->insertPerformance(1, 60, 120_000_000);

        $results = $this->manager->evaluate(1);

        $rec = $results[0];
        $this->assertSame('maximize_conversions_tcpa_tightened', $rec['recommended_strategy']);
        // Actual CPA = $2.00, tight tCPA = $2.40
        $this->assertEqualsWithDelta(2.40, $rec['target_cpa'], 0.01);
    }

    // -------------------------------------------------------------------------
    // apply() — strategy stays same
    // -------------------------------------------------------------------------

    public function testApplySkipsRecommendationWhenStrategyUnchanged(): void
    {
        $this->insertCampaign(1, 'google', 'manual_cpc');

        $recommendations = [[
            'campaign_id'          => 1,
            'campaign_name'        => 'Campaign 1',
            'external_id'          => null,
            'current_strategy'     => 'manual_cpc',
            'recommended_strategy' => 'manual_cpc', // same — skip
            'target_cpa'           => null,
        ]];

        $result = $this->manager->apply($recommendations);

        $this->assertSame([], $result['applied']);
        $this->assertSame([], $result['errors']);
    }

    // -------------------------------------------------------------------------
    // apply() — DB update
    // -------------------------------------------------------------------------

    public function testApplyUpdatesBidStrategyInDatabase(): void
    {
        $this->insertCampaign(1, 'google', 'manual_cpc');

        $recommendations = [[
            'campaign_id'          => 1,
            'campaign_name'        => 'Campaign 1',
            'external_id'          => null, // no API call
            'current_strategy'     => 'manual_cpc',
            'recommended_strategy' => 'maximize_conversions',
            'target_cpa'           => null,
        ]];

        $this->manager->apply($recommendations);

        $row = $this->db->query("SELECT bid_strategy FROM campaigns WHERE id = 1")->fetch();
        $this->assertSame('maximize_conversions', $row['bid_strategy']);
    }

    public function testApplyReturnsAppliedEntryWithCorrectFields(): void
    {
        $this->insertCampaign(1, 'google', 'manual_cpc');

        $recommendations = [[
            'campaign_id'          => 1,
            'campaign_name'        => 'Campaign 1',
            'external_id'          => null,
            'current_strategy'     => 'manual_cpc',
            'recommended_strategy' => 'maximize_conversions',
            'target_cpa'           => null,
        ]];

        $result = $this->manager->apply($recommendations);

        $this->assertCount(1, $result['applied']);
        $applied = $result['applied'][0];

        $this->assertSame(1, $applied['campaign_id']);
        $this->assertSame('manual_cpc', $applied['old_strategy']);
        $this->assertSame('maximize_conversions', $applied['new_strategy']);
    }

    public function testApplyReturnsEmptyResultsForEmptyInput(): void
    {
        $result = $this->manager->apply([]);

        $this->assertSame([], $result['applied']);
        $this->assertSame([], $result['errors']);
    }

    // -------------------------------------------------------------------------
    // apply() — multiple campaigns
    // -------------------------------------------------------------------------

    public function testApplyHandlesMultipleCampaigns(): void
    {
        $this->insertCampaign(1, 'google', 'manual_cpc');
        $this->insertCampaign(2, 'google', 'manual_cpc');

        $recommendations = [
            [
                'campaign_id'          => 1,
                'campaign_name'        => 'Campaign 1',
                'external_id'          => null,
                'current_strategy'     => 'manual_cpc',
                'recommended_strategy' => 'maximize_conversions',
                'target_cpa'           => null,
            ],
            [
                'campaign_id'          => 2,
                'campaign_name'        => 'Campaign 2',
                'external_id'          => null,
                'current_strategy'     => 'manual_cpc',
                'recommended_strategy' => 'maximize_conversions_with_tcpa',
                'target_cpa'           => 15.0,
            ],
        ];

        $result = $this->manager->apply($recommendations);

        $this->assertCount(2, $result['applied']);
        $this->assertSame([], $result['errors']);

        // Both campaigns updated in DB
        $row1 = $this->db->query("SELECT bid_strategy FROM campaigns WHERE id = 1")->fetch();
        $row2 = $this->db->query("SELECT bid_strategy FROM campaigns WHERE id = 2")->fetch();
        $this->assertSame('maximize_conversions', $row1['bid_strategy']);
        $this->assertSame('maximize_conversions_with_tcpa', $row2['bid_strategy']);
    }

    // -------------------------------------------------------------------------
    // apply() — platform API calls (no external_id path)
    // -------------------------------------------------------------------------

    public function testApplyDoesNotErrorWhenExternalIdIsNull(): void
    {
        $this->insertCampaign(1, 'google', 'manual_cpc');

        $recommendations = [[
            'campaign_id'          => 1,
            'campaign_name'        => 'Campaign 1',
            'external_id'          => null, // no API call
            'current_strategy'     => 'manual_cpc',
            'recommended_strategy' => 'maximize_conversions',
            'target_cpa'           => null,
        ]];

        $result = $this->manager->apply($recommendations);

        $this->assertSame([], $result['errors']);
    }

    // -------------------------------------------------------------------------
    // reason text quality
    // -------------------------------------------------------------------------

    public function testReasonMentionsConversionCountForLowVolumeThreshold(): void
    {
        $this->insertCampaign(1, 'google', 'manual_cpc');
        $this->insertPerformance(1, 8);

        $results = $this->manager->evaluate(1);

        $this->assertStringContainsString('8', $results[0]['reason']);
    }

    public function testReasonMentionsThresholdRangeForMaximizeConversions(): void
    {
        $this->insertCampaign(1, 'google', 'manual_cpc');
        $this->insertPerformance(1, 25);

        $results = $this->manager->evaluate(1);

        $reason = $results[0]['reason'];
        // Should mention the 15–30 threshold band
        $this->assertMatchesRegularExpression('/15.{0,5}30/i', $reason);
    }
}
