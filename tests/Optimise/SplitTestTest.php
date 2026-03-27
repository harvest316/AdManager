<?php

declare(strict_types=1);

namespace AdManager\Tests\Optimise;

use AdManager\DB;
use AdManager\Optimise\SplitTest;
use PDO;
use PHPUnit\Framework\TestCase;

class SplitTestTest extends TestCase
{
    private PDO $db;
    private SplitTest $splitTest;

    protected function setUp(): void
    {
        // Use in-memory SQLite for every test
        putenv('ADMANAGER_DB_PATH=:memory:');
        DB::reset();

        $this->db = DB::get();
        $schema = file_get_contents(dirname(__DIR__, 2) . '/db/schema.sql');
        $this->db->exec($schema);

        $this->splitTest = new SplitTest();

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
        // Project
        $this->db->exec(
            "INSERT INTO projects (id, name, display_name) VALUES (1, 'test-proj', 'Test Project')"
        );

        // Campaign
        $this->db->exec(
            "INSERT INTO campaigns (id, project_id, platform, name, type, status)
             VALUES (1, 1, 'google', 'Test Campaign', 'search', 'enabled')"
        );

        // Ad group
        $this->db->exec(
            "INSERT INTO ad_groups (id, campaign_id, name, status)
             VALUES (1, 1, 'Test Ad Group', 'enabled')"
        );

        // Ads
        $this->db->exec(
            "INSERT INTO ads (id, ad_group_id, type, status) VALUES (10, 1, 'rsa', 'enabled')"
        );
        $this->db->exec(
            "INSERT INTO ads (id, ad_group_id, type, status) VALUES (11, 1, 'rsa', 'enabled')"
        );
    }

    private function insertPerformance(int $adId, int $impressions, int $clicks, float $conversions = 0, float $conversionValue = 0, int $costMicros = 0): void
    {
        $this->db->exec(
            "INSERT INTO performance (campaign_id, ad_group_id, ad_id, date, impressions, clicks, conversions, conversion_value, cost_micros)
             VALUES (1, 1, {$adId}, date('now'), {$impressions}, {$clicks}, {$conversions}, {$conversionValue}, {$costMicros})"
        );
    }

    // -------------------------------------------------------------------------
    // create()
    // -------------------------------------------------------------------------

    public function testCreateReturnsSplitTestId(): void
    {
        $id = $this->splitTest->create(1, 1, 1, 'CTR Test');

        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id);
    }

    public function testCreatePersistsCorrectFields(): void
    {
        $id = $this->splitTest->create(1, 1, 1, 'My A/B Test', 'conversion_rate', 2000);

        $row = $this->db->query("SELECT * FROM split_tests WHERE id = {$id}")->fetch();

        $this->assertSame(1, (int) $row['project_id']);
        $this->assertSame(1, (int) $row['campaign_id']);
        $this->assertSame(1, (int) $row['ad_group_id']);
        $this->assertSame('My A/B Test', $row['name']);
        $this->assertSame('conversion_rate', $row['metric']);
        $this->assertSame(2000, (int) $row['min_impressions']);
        $this->assertSame('running', $row['status']);
    }

    public function testCreateDefaultMetricIsCtr(): void
    {
        $id = $this->splitTest->create(1, 1, 1, 'Default Metric Test');

        $row = $this->db->query("SELECT metric FROM split_tests WHERE id = {$id}")->fetch();

        $this->assertSame('ctr', $row['metric']);
    }

    public function testCreateMultipleTestsReturnUniqueIds(): void
    {
        $id1 = $this->splitTest->create(1, 1, 1, 'Test One');
        $id2 = $this->splitTest->create(1, 1, 1, 'Test Two');

        $this->assertNotSame($id1, $id2);
    }

    // -------------------------------------------------------------------------
    // evaluate() — not_found / concluded / insufficient_variants
    // -------------------------------------------------------------------------

    public function testEvaluateReturnsNotFoundForMissingTest(): void
    {
        $result = $this->splitTest->evaluate(9999);

        $this->assertSame('not_found', $result['status']);
        $this->assertSame(0.0, $result['confidence']);
        $this->assertNull($result['winner']);
    }

    public function testEvaluateConcludedTestReturnsWinnerDirectly(): void
    {
        $id = $this->splitTest->create(1, 1, 1, 'Done Test');
        $this->db->exec(
            "UPDATE split_tests SET status = 'concluded', winner_ad_id = 10, confidence_level = 0.97 WHERE id = {$id}"
        );

        $result = $this->splitTest->evaluate($id);

        $this->assertSame('concluded', $result['status']);
        $this->assertSame(10, $result['winner']);
        $this->assertSame(0.97, $result['confidence']);
    }

    public function testEvaluateReturnsSufficientVariantsStatusWhenOnlyOneAd(): void
    {
        // Remove one of the two ads so only one remains
        $this->db->exec("UPDATE ads SET status = 'removed' WHERE id = 11");

        $id = $this->splitTest->create(1, 1, 1, 'Single Variant Test');
        $result = $this->splitTest->evaluate($id);

        $this->assertSame('insufficient_variants', $result['status']);
        $this->assertNull($result['winner']);
    }

    public function testEvaluateReturnsInsufficientDataWhenBelowMinImpressions(): void
    {
        // Both ads have far fewer impressions than the default 1000 minimum
        $this->insertPerformance(10, 50, 2);
        $this->insertPerformance(11, 40, 1);

        $id = $this->splitTest->create(1, 1, 1, 'Low Impressions Test', 'ctr', 1000);
        $result = $this->splitTest->evaluate($id);

        $this->assertSame('insufficient_data', $result['status']);
        $this->assertNull($result['winner']);
        $this->assertCount(2, $result['variants']);
    }

    // -------------------------------------------------------------------------
    // evaluate() — statistical significance / winner detection
    // -------------------------------------------------------------------------

    public function testEvaluateDetectsWinnerWithHighlySignificantData(): void
    {
        // Ad 10: CTR 10% over 5000 impressions — very clear winner
        $this->insertPerformance(10, 5000, 500);  // 10% CTR
        $this->insertPerformance(11, 5000, 50);   // 1% CTR

        $id = $this->splitTest->create(1, 1, 1, 'Clear Winner Test', 'ctr', 1000);
        $result = $this->splitTest->evaluate($id);

        $this->assertSame('winner', $result['status']);
        $this->assertSame(10, $result['winner']);
        $this->assertGreaterThan(0.95, $result['confidence']);
    }

    public function testEvaluateReturnsRunningWhenNoDifferenceExists(): void
    {
        // Both ads have identical performance
        $this->insertPerformance(10, 2000, 40);
        $this->insertPerformance(11, 2000, 40);

        $id = $this->splitTest->create(1, 1, 1, 'Tied Test', 'ctr', 1000);
        $result = $this->splitTest->evaluate($id);

        // No significant difference — no winner
        $this->assertSame('running', $result['status']);
        $this->assertNull($result['winner']);
    }

    public function testEvaluateVariantsContainExpectedFields(): void
    {
        $this->insertPerformance(10, 2000, 80, 5, 500.0, 1_000_000);
        $this->insertPerformance(11, 2000, 40, 2, 200.0, 800_000);

        $id = $this->splitTest->create(1, 1, 1, 'Variant Fields Test', 'ctr', 1000);
        $result = $this->splitTest->evaluate($id);

        $this->assertNotEmpty($result['variants']);
        $variant = $result['variants'][0];

        foreach (['ad_id', 'impressions', 'clicks', 'conversions', 'conversion_value', 'cost', 'metric', 'metric_value'] as $field) {
            $this->assertArrayHasKey($field, $variant, "Missing field: {$field}");
        }
    }

    public function testEvaluateConversionRateMetric(): void
    {
        // Ad 10 converts 20% of clicks; Ad 11 converts 2%
        $this->insertPerformance(10, 2000, 200, 40, 4000.0);  // 20% conv rate
        $this->insertPerformance(11, 2000, 200, 4, 400.0);    // 2% conv rate

        $id = $this->splitTest->create(1, 1, 1, 'Conv Rate Test', 'conversion_rate', 1000);
        $result = $this->splitTest->evaluate($id);

        // Best variant should be ad 10
        $this->assertSame(10, $result['variants'][0]['ad_id']);
    }

    public function testEvaluateRoasMetric(): void
    {
        // Ad 10: high ROAS. Ad 11: low ROAS.
        $this->insertPerformance(10, 2000, 100, 10, 2000.0, 1_000_000);  // ROAS=2
        $this->insertPerformance(11, 2000, 100, 10, 200.0,  1_000_000);  // ROAS=0.2

        $id = $this->splitTest->create(1, 1, 1, 'ROAS Test', 'roas', 1000);
        $result = $this->splitTest->evaluate($id);

        // Variants are sorted best-first; best variant = ad 10
        $this->assertSame(10, $result['variants'][0]['ad_id']);
    }

    // -------------------------------------------------------------------------
    // conclude()
    // -------------------------------------------------------------------------

    public function testConcludeUpdatesStatusAndWinner(): void
    {
        $id = $this->splitTest->create(1, 1, 1, 'To Conclude');

        $this->splitTest->conclude($id, 10);

        $row = $this->db->query("SELECT * FROM split_tests WHERE id = {$id}")->fetch();

        $this->assertSame('concluded', $row['status']);
        $this->assertSame(10, (int) $row['winner_ad_id']);
        $this->assertNotNull($row['concluded_at']);
    }

    public function testConcludeDoesNotThrowForNonExistentTest(): void
    {
        // Should silently no-op (UPDATE affects 0 rows)
        $this->splitTest->conclude(9999, 10);
        $this->assertTrue(true); // No exception
    }

    // -------------------------------------------------------------------------
    // listActive() / listAll()
    // -------------------------------------------------------------------------

    public function testListActiveReturnsOnlyRunningTests(): void
    {
        $id1 = $this->splitTest->create(1, 1, 1, 'Running Test');
        $id2 = $this->splitTest->create(1, 1, 1, 'Concluded Test');
        $this->splitTest->conclude($id2, 10);

        $active = $this->splitTest->listActive(1);

        $ids = array_column($active, 'id');
        $this->assertContains($id1, array_map('intval', $ids));
        $this->assertNotContains($id2, array_map('intval', $ids));
    }

    public function testListAllReturnsAllStatuses(): void
    {
        $id1 = $this->splitTest->create(1, 1, 1, 'Running');
        $id2 = $this->splitTest->create(1, 1, 1, 'Concluded');
        $this->splitTest->conclude($id2, 10);

        $all = $this->splitTest->listAll(1);

        $ids = array_map(fn($r) => (int) $r['id'], $all);
        $this->assertContains($id1, $ids);
        $this->assertContains($id2, $ids);
    }

    public function testListActiveReturnsEmptyArrayWhenNoneRunning(): void
    {
        $active = $this->splitTest->listActive(1);

        $this->assertSame([], $active);
    }

    public function testListActiveIncludesCampaignAndAdGroupName(): void
    {
        $this->splitTest->create(1, 1, 1, 'Named Test');

        $active = $this->splitTest->listActive(1);

        $this->assertArrayHasKey('campaign_name', $active[0]);
        $this->assertArrayHasKey('ad_group_name', $active[0]);
        $this->assertSame('Test Campaign', $active[0]['campaign_name']);
        $this->assertSame('Test Ad Group', $active[0]['ad_group_name']);
    }

    public function testListActiveIgnoresOtherProjects(): void
    {
        $this->db->exec(
            "INSERT INTO projects (id, name, display_name) VALUES (2, 'other-proj', 'Other')"
        );
        $this->db->exec(
            "INSERT INTO campaigns (id, project_id, platform, name, type, status)
             VALUES (2, 2, 'google', 'Other Campaign', 'search', 'enabled')"
        );
        $this->db->exec(
            "INSERT INTO ad_groups (id, campaign_id, name, status)
             VALUES (2, 2, 'Other Ad Group', 'enabled')"
        );

        // Test for project 1
        $this->splitTest->create(1, 1, 1, 'Project 1 Test');
        // Test for project 2
        $this->splitTest->create(2, 2, 2, 'Project 2 Test');

        $active = $this->splitTest->listActive(1);

        $this->assertCount(1, $active);
        $this->assertSame('Project 1 Test', $active[0]['name']);
    }
}
