<?php

declare(strict_types=1);

namespace AdManager\Tests\Optimise;

use AdManager\DB;
use AdManager\Optimise\KeywordMiner;
use PDO;
use PHPUnit\Framework\TestCase;

class KeywordMinerTest extends TestCase
{
    private PDO $db;
    private KeywordMiner $miner;

    protected function setUp(): void
    {
        putenv('ADMANAGER_DB_PATH=:memory:');
        DB::reset();

        $this->db = DB::get();
        $schema = file_get_contents(dirname(__DIR__, 2) . '/db/schema.sql');
        $this->db->exec($schema);

        $this->miner = new KeywordMiner();

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

        // Three ad groups
        $this->db->exec(
            "INSERT INTO ad_groups (id, campaign_id, name, status) VALUES (1, 1, 'AdGroup A', 'enabled')"
        );
        $this->db->exec(
            "INSERT INTO ad_groups (id, campaign_id, name, status) VALUES (2, 1, 'AdGroup B', 'enabled')"
        );
        $this->db->exec(
            "INSERT INTO ad_groups (id, campaign_id, name, status) VALUES (3, 1, 'AdGroup C', 'enabled')"
        );
    }

    private function insertPerformance(int $adGroupId, int $impressions, int $clicks, float $conversions = 0, int $costMicros = 0): void
    {
        $this->db->exec(
            "INSERT INTO performance (campaign_id, ad_group_id, date, impressions, clicks, conversions, cost_micros)
             VALUES (1, {$adGroupId}, date('now'), {$impressions}, {$clicks}, {$conversions}, {$costMicros})"
        );
    }

    private function insertKeyword(int $adGroupId, string $keyword, string $matchType = 'broad', int $isNegative = 0): void
    {
        $this->db->exec(
            "INSERT INTO keywords (ad_group_id, campaign_id, keyword, match_type, is_negative)
             VALUES ({$adGroupId}, 1, '{$keyword}', '{$matchType}', {$isNegative})"
        );
    }

    // -------------------------------------------------------------------------
    // mineSearchTerms() — return structure
    // -------------------------------------------------------------------------

    public function testMineSearchTermsReturnsExpectedKeys(): void
    {
        $result = $this->miner->mineSearchTerms(1);

        $this->assertArrayHasKey('add_keywords', $result);
        $this->assertArrayHasKey('add_negatives', $result);
        $this->assertArrayHasKey('total_terms', $result);
    }

    public function testMineSearchTermsReturnsEmptyWhenNoPerformanceData(): void
    {
        $result = $this->miner->mineSearchTerms(1);

        $this->assertSame([], $result['add_keywords']);
        $this->assertSame([], $result['add_negatives']);
        $this->assertSame(0, $result['total_terms']);
    }

    public function testMineSearchTermsTotalTermsCountsAdGroups(): void
    {
        $this->insertPerformance(1, 100, 5);
        $this->insertPerformance(2, 200, 10);

        $result = $this->miner->mineSearchTerms(1);

        $this->assertSame(2, $result['total_terms']);
    }

    // -------------------------------------------------------------------------
    // mineSearchTerms() — keyword candidates (high CTR + conversions)
    // -------------------------------------------------------------------------

    public function testHighCtrWithConversionsIsAddedAsKeywordCandidate(): void
    {
        // CTR = 5% (above 3% threshold) with 2 conversions
        $this->insertPerformance(1, 100, 5, 2.0);

        $result = $this->miner->mineSearchTerms(1);

        $this->assertCount(1, $result['add_keywords']);
        $candidate = $result['add_keywords'][0];
        $this->assertSame(1, $candidate['ad_group_id']);
        $this->assertSame('High CTR with conversions', $candidate['reason']);
    }

    public function testHighCtrWithoutConversionsIsNotAddedAsKeyword(): void
    {
        // CTR = 10% but zero conversions
        $this->insertPerformance(1, 100, 10, 0.0);

        $result = $this->miner->mineSearchTerms(1);

        $this->assertSame([], $result['add_keywords']);
    }

    public function testBelowThresholdCtrWithConversionsIsNotAddedAsKeyword(): void
    {
        // CTR = 1% (below 3% threshold) with conversions
        $this->insertPerformance(1, 1000, 10, 5.0);

        $result = $this->miner->mineSearchTerms(1);

        $this->assertSame([], $result['add_keywords']);
    }

    public function testKeywordCandidateContainsExpectedFields(): void
    {
        // CTR = 5% (above 3% threshold) with 2 conversions
        $this->insertPerformance(1, 100, 5, 2.0, 500_000);

        $result = $this->miner->mineSearchTerms(1);

        $this->assertNotEmpty($result['add_keywords'], 'Expected at least one keyword candidate');
        $candidate = $result['add_keywords'][0];
        foreach (['ad_group_id', 'campaign_id', 'impressions', 'clicks', 'ctr', 'conversions', 'cost', 'reason'] as $field) {
            $this->assertArrayHasKey($field, $candidate, "Missing field: {$field}");
        }
    }

    // -------------------------------------------------------------------------
    // mineSearchTerms() — negative keyword candidates (low CTR + high spend + no conv)
    // -------------------------------------------------------------------------

    public function testLowCtrHighSpendNoConversionsIsNegativeCandidate(): void
    {
        // Ad group 1: avg spend reference (low spend)
        $this->insertPerformance(1, 1000, 3, 0.0, 1_000_000);    // CTR 0.3%, $1 spend
        // Ad group 2: CTR 0.1%, very high spend ($30 = 30x group1, > 2x avg of $15.50), zero conversions
        // Average = ($1 + $30) / 2 = $15.50; group 2 cost $30 > $15.50 * 2 = $31? No...
        // Average = $1 + $100 / 2 = $50.50; group 2 at $100 > 2x = need cost > 2 * avg
        // Let's use: group1 = $1, group2 = $100
        // avg = $50.50, 2x avg = $101 — still not enough
        // Better: group1=$1, group2=$200, avg=$100.50, 2x=$201 — nope
        // The condition is: cost > avgCostPerGroup * 2
        // avgCostPerGroup = totalCost / count = (cost1 + cost2) / 2
        // We need cost2 > 2 * (cost1 + cost2) / 2 → cost2 > cost1 + cost2 → 0 > cost1 — impossible with 2 groups!
        // With 3 groups: avg = (c1+c2+c3)/3; need c3 > 2*(c1+c2+c3)/3 → 3c3 > 2c1+2c2+2c3 → c3 > 2c1+2c2
        // Use 3 groups: c1=$1, c2=$1, c3=$10 → avg=$4, 2x=$8, c3=$10 > $8 ✓
        $this->db->exec("DELETE FROM performance");
        $this->insertPerformance(1, 1000, 2, 0.0, 1_000_000);    // CTR 0.2%, $1
        $this->insertPerformance(2, 1000, 2, 0.0, 1_000_000);    // CTR 0.2%, $1
        $this->insertPerformance(3, 1000, 1, 0.0, 10_000_000);   // CTR 0.1%, $10

        $result = $this->miner->mineSearchTerms(1);

        // Ad group 3 should be a negative candidate
        $negativeGroupIds = array_column($result['add_negatives'], 'ad_group_id');
        $this->assertContains(3, $negativeGroupIds);
    }

    public function testHighSpendWithConversionsIsNotNegativeCandidate(): void
    {
        // Three groups: groups 1 and 2 are low spend references, group 3 has high spend but HAS conversions
        $this->insertPerformance(1, 1000, 2, 0.0, 1_000_000);   // $1, no conv
        $this->insertPerformance(2, 1000, 2, 0.0, 1_000_000);   // $1, no conv
        $this->insertPerformance(3, 1000, 1, 5.0, 10_000_000);  // $10, HAS conversions

        $result = $this->miner->mineSearchTerms(1);

        $negativeGroupIds = array_column($result['add_negatives'], 'ad_group_id');
        $this->assertNotContains(3, $negativeGroupIds);
    }

    public function testLowSpendLowCtrNotNegativeCandidate(): void
    {
        // All groups have identical low spend — none exceeds 2x average
        $this->insertPerformance(1, 100, 0, 0.0, 100_000);   // $0.10
        $this->insertPerformance(2, 100, 0, 0.0, 100_000);   // $0.10
        $this->insertPerformance(3, 100, 0, 0.0, 100_000);   // $0.10

        $result = $this->miner->mineSearchTerms(1);

        // All equal spend — none exceeds 2x average (avg=$0.10, 2x=$0.20, max=$0.10)
        $this->assertSame([], $result['add_negatives']);
    }

    public function testNegativeCandidateContainsExpectedFields(): void
    {
        // Three groups to make group 3 exceed 2x average spend threshold
        $this->insertPerformance(1, 1000, 2, 0.0, 1_000_000);
        $this->insertPerformance(2, 1000, 2, 0.0, 1_000_000);
        $this->insertPerformance(3, 1000, 1, 0.0, 10_000_000);

        $result = $this->miner->mineSearchTerms(1);

        $this->assertNotEmpty($result['add_negatives'], 'Expected at least one negative candidate');

        $negative = $result['add_negatives'][0];
        foreach (['ad_group_id', 'campaign_id', 'impressions', 'clicks', 'ctr', 'cost', 'reason'] as $field) {
            $this->assertArrayHasKey($field, $negative, "Missing field: {$field}");
        }
    }

    // -------------------------------------------------------------------------
    // mineSearchTerms() — minimum impressions filter
    // -------------------------------------------------------------------------

    public function testAdGroupBelowMinImpressionsIsExcluded(): void
    {
        // Only 5 impressions — below the 10-impression minimum
        $this->insertPerformance(1, 5, 1, 1.0);

        $result = $this->miner->mineSearchTerms(1);

        // Should be excluded from analysis
        $this->assertSame(0, $result['total_terms']);
    }

    public function testAdGroupAtExactMinImpressionsIsIncluded(): void
    {
        // Exactly 10 impressions — at the boundary
        $this->insertPerformance(1, 10, 1, 1.0);

        $result = $this->miner->mineSearchTerms(1);

        $this->assertSame(1, $result['total_terms']);
    }

    // -------------------------------------------------------------------------
    // mineSearchTerms() — project isolation
    // -------------------------------------------------------------------------

    public function testMineSearchTermsIgnoresOtherProjects(): void
    {
        $this->db->exec(
            "INSERT INTO projects (id, name, display_name) VALUES (2, 'other-proj', 'Other')"
        );
        $this->db->exec(
            "INSERT INTO campaigns (id, project_id, platform, name, type, status)
             VALUES (2, 2, 'google', 'Other Campaign', 'search', 'enabled')"
        );
        $this->db->exec(
            "INSERT INTO ad_groups (id, campaign_id, name, status) VALUES (10, 2, 'Other Group', 'enabled')"
        );
        $this->db->exec(
            "INSERT INTO performance (campaign_id, ad_group_id, date, impressions, clicks, conversions)
             VALUES (2, 10, date('now'), 100, 10, 5)"
        );

        // Project 1 has no data
        $result = $this->miner->mineSearchTerms(1);

        $this->assertSame(0, $result['total_terms']);
    }

    // -------------------------------------------------------------------------
    // mineSearchTerms() — multiple qualifying ad groups
    // -------------------------------------------------------------------------

    public function testMultipleHighCtrAdGroupsAreAllSurfaced(): void
    {
        $this->insertPerformance(1, 100, 5, 2.0);   // CTR 5%, conv
        $this->insertPerformance(2, 100, 4, 1.0);   // CTR 4%, conv
        $this->insertPerformance(3, 100, 10, 3.0);  // CTR 10%, conv

        $result = $this->miner->mineSearchTerms(1);

        $this->assertCount(3, $result['add_keywords']);
    }
}
