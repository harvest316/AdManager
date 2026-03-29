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

    /**
     * Insert a search term row into the search_terms table.
     */
    private function insertSearchTerm(
        int    $adGroupId,
        string $term,
        int    $impressions,
        int    $clicks,
        float  $conversions = 0.0,
        int    $costMicros  = 0,
        string $date        = null
    ): void {
        $date = $date ?? date('Y-m-d');
        $this->db->exec(
            "INSERT OR REPLACE INTO search_terms
                (project_id, campaign_id, ad_group_id, search_term, impressions, clicks, conversions, cost_micros, date)
             VALUES
                (1, 1, {$adGroupId}, '{$term}', {$impressions}, {$clicks}, {$conversions}, {$costMicros}, '{$date}')"
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
        $this->assertArrayHasKey('expansion', $result);
        $this->assertArrayHasKey('total_terms', $result);
    }

    public function testMineSearchTermsReturnsEmptyWhenNoData(): void
    {
        $result = $this->miner->mineSearchTerms(1);

        $this->assertSame([], $result['add_keywords']);
        $this->assertSame([], $result['add_negatives']);
        $this->assertSame([], $result['expansion']);
        $this->assertSame(0, $result['total_terms']);
    }

    public function testMineSearchTermsTotalTermsCountsDistinctSearchTerms(): void
    {
        $this->insertSearchTerm(1, 'red shoes', 100, 5);
        $this->insertSearchTerm(2, 'blue shoes', 200, 10);

        $result = $this->miner->mineSearchTerms(1);

        $this->assertSame(2, $result['total_terms']);
    }

    // -------------------------------------------------------------------------
    // mineSearchTerms() — keyword candidates (high CTR >= 3%)
    // -------------------------------------------------------------------------

    public function testHighCtrTermIsAddedAsKeywordCandidate(): void
    {
        // CTR = 5% (above 3% threshold)
        $this->insertSearchTerm(1, 'buy running shoes', 100, 5);

        $result = $this->miner->mineSearchTerms(1);

        $this->assertCount(1, $result['add_keywords']);
        $candidate = $result['add_keywords'][0];
        $this->assertSame('buy running shoes', $candidate['search_term']);
        $this->assertSame('High CTR with conversions', $candidate['reason']);
        $this->assertSame('add_keyword', $candidate['recommendation']);
    }

    public function testBelowThresholdCtrTermIsNotKeywordCandidate(): void
    {
        // CTR = 1% (below 3% threshold)
        $this->insertSearchTerm(1, 'shoes', 1000, 10);

        $result = $this->miner->mineSearchTerms(1);

        $this->assertSame([], $result['add_keywords']);
    }

    public function testExactThresholdCtrIsKeywordCandidate(): void
    {
        // CTR = exactly 3%
        $this->insertSearchTerm(1, 'cheap shoes online', 100, 3);

        $result = $this->miner->mineSearchTerms(1);

        $this->assertCount(1, $result['add_keywords']);
    }

    public function testKeywordCandidateContainsExpectedFields(): void
    {
        $this->insertSearchTerm(1, 'best running shoes', 100, 5, 2.0, 500_000);

        $result = $this->miner->mineSearchTerms(1);

        $this->assertNotEmpty($result['add_keywords'], 'Expected at least one keyword candidate');
        $candidate = $result['add_keywords'][0];
        foreach (['search_term', 'ad_group_id', 'campaign_id', 'impressions', 'clicks', 'ctr', 'conversions', 'cost', 'reason', 'recommendation'] as $field) {
            $this->assertArrayHasKey($field, $candidate, "Missing field: {$field}");
        }
        $this->assertSame(1, $candidate['ad_group_id']);
        $this->assertSame(5.0, $candidate['ctr']);
        $this->assertSame(0.5, $candidate['cost']);
    }

    public function testMultipleHighCtrTermsAreAllSurfaced(): void
    {
        $this->insertSearchTerm(1, 'buy shoes', 100, 5);    // CTR 5%
        $this->insertSearchTerm(2, 'order shoes', 100, 4);  // CTR 4%
        $this->insertSearchTerm(3, 'shoes online', 100, 10); // CTR 10%

        $result = $this->miner->mineSearchTerms(1);

        $this->assertCount(3, $result['add_keywords']);
    }

    // -------------------------------------------------------------------------
    // mineSearchTerms() — negative keyword candidates (low CTR < 1%)
    // -------------------------------------------------------------------------

    public function testLowCtrTermIsNegativeCandidate(): void
    {
        // CTR = 0.5% (below 1% threshold)
        $this->insertSearchTerm(1, 'free shoes giveaway', 200, 1);

        $result = $this->miner->mineSearchTerms(1);

        $this->assertCount(1, $result['add_negatives']);
        $candidate = $result['add_negatives'][0];
        $this->assertSame('free shoes giveaway', $candidate['search_term']);
        $this->assertSame('Low CTR — likely irrelevant traffic', $candidate['reason']);
        $this->assertSame('add_negative', $candidate['recommendation']);
    }

    public function testAboveNegativeThresholdCtrIsNotNegativeCandidate(): void
    {
        // CTR = 2% (above 1% negative threshold)
        $this->insertSearchTerm(1, 'running shoes', 100, 2);

        $result = $this->miner->mineSearchTerms(1);

        $this->assertSame([], $result['add_negatives']);
    }

    public function testNegativeCandidateContainsExpectedFields(): void
    {
        $this->insertSearchTerm(1, 'shoe repair diy', 500, 2, 0.0, 1_000_000);

        $result = $this->miner->mineSearchTerms(1);

        $this->assertNotEmpty($result['add_negatives'], 'Expected at least one negative candidate');
        $negative = $result['add_negatives'][0];
        foreach (['search_term', 'ad_group_id', 'campaign_id', 'impressions', 'clicks', 'ctr', 'cost', 'reason', 'recommendation'] as $field) {
            $this->assertArrayHasKey($field, $negative, "Missing field: {$field}");
        }
    }

    // -------------------------------------------------------------------------
    // mineSearchTerms() — expansion candidates (conversions + not in keywords)
    // -------------------------------------------------------------------------

    public function testConvertingTermNotInKeywordsIsExpansionCandidate(): void
    {
        $this->insertSearchTerm(1, 'waterproof trail shoes', 50, 3, 2.0);

        $result = $this->miner->mineSearchTerms(1);

        $this->assertCount(1, $result['expansion']);
        $candidate = $result['expansion'][0];
        $this->assertSame('waterproof trail shoes', $candidate['search_term']);
        $this->assertSame('expand_keyword', $candidate['recommendation']);
    }

    public function testConvertingTermAlreadyInKeywordsIsNotExpansionCandidate(): void
    {
        $this->insertSearchTerm(1, 'trail running shoes', 50, 3, 2.0);
        $this->insertKeyword(1, 'trail running shoes');

        $result = $this->miner->mineSearchTerms(1);

        $this->assertSame([], $result['expansion']);
    }

    public function testZeroConversionTermIsNotExpansionCandidate(): void
    {
        $this->insertSearchTerm(1, 'cheap knockoff shoes', 200, 6, 0.0);

        $result = $this->miner->mineSearchTerms(1);

        $this->assertSame([], $result['expansion']);
    }

    // -------------------------------------------------------------------------
    // mineSearchTerms() — minimum impressions filter
    // -------------------------------------------------------------------------

    public function testTermBelowMinImpressionsIsExcluded(): void
    {
        // Only 5 impressions — below the 10-impression minimum
        $this->insertSearchTerm(1, 'rare query', 5, 1, 1.0);

        $result = $this->miner->mineSearchTerms(1);

        $this->assertSame(0, $result['total_terms']);
    }

    public function testTermAtExactMinImpressionsIsIncluded(): void
    {
        // Exactly 10 impressions — at the boundary
        $this->insertSearchTerm(1, 'boundary query', 10, 1, 1.0);

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
        // Insert search term for other project directly (different project_id)
        $this->db->exec(
            "INSERT INTO search_terms (project_id, campaign_id, ad_group_id, search_term, impressions, clicks, conversions, date)
             VALUES (2, 2, 10, 'other project term', 100, 10, 5, date('now'))"
        );

        // Project 1 has no data
        $result = $this->miner->mineSearchTerms(1);

        $this->assertSame(0, $result['total_terms']);
    }

    // -------------------------------------------------------------------------
    // mineSearchTerms() — aggregation across multiple dates
    // -------------------------------------------------------------------------

    public function testSearchTermAggregatesAcrossMultipleDates(): void
    {
        // Same term on two different dates — should be aggregated
        $this->insertSearchTerm(1, 'running shoes', 50, 2, 0.0, 0, '2026-03-27');
        $this->insertSearchTerm(1, 'running shoes', 50, 2, 0.0, 0, '2026-03-28');

        $result = $this->miner->mineSearchTerms(1);

        // Aggregated: 100 impressions, 4 clicks → CTR 4% → keyword candidate
        $this->assertSame(1, $result['total_terms']);
        $this->assertCount(1, $result['add_keywords']);
        $candidate = $result['add_keywords'][0];
        $this->assertSame(100, $candidate['impressions']);
        $this->assertSame(4, $candidate['clicks']);
        $this->assertSame(4.0, $candidate['ctr']);
    }

    // -------------------------------------------------------------------------
    // suggestNegatives() — return structure and basic logic
    // -------------------------------------------------------------------------

    public function testSuggestNegativesReturnsArrayOfCandidates(): void
    {
        $result = $this->miner->suggestNegatives(1);
        $this->assertIsArray($result);
    }

    public function testSuggestNegativesReturnsEmptyWhenNoData(): void
    {
        $result = $this->miner->suggestNegatives(1);
        $this->assertSame([], $result);
    }

    public function testHighSpendZeroConversionsIsNegativeSuggestion(): void
    {
        // $10 spend, zero conversions — exceeds $5 threshold
        $this->insertSearchTerm(1, 'shoe museum tour', 50, 2, 0.0, 10_000_000);

        $result = $this->miner->suggestNegatives(1);

        $this->assertCount(1, $result);
        $candidate = $result[0];
        $this->assertSame('shoe museum tour', $candidate['search_term']);
        $this->assertSame('add_negative', $candidate['recommendation']);
        $this->assertStringContainsString('High spend', $candidate['reasons'][0]);
    }

    public function testHighSpendWithConversionsIsNotNegativeSuggestion(): void
    {
        // $10 spend but HAS conversions — should not be suggested
        $this->insertSearchTerm(1, 'buy hiking boots', 50, 2, 3.0, 10_000_000);

        $result = $this->miner->suggestNegatives(1);

        $this->assertSame([], $result);
    }

    public function testVeryLowCtrAtScaleIsNegativeSuggestion(): void
    {
        // CTR = 0.2%, 200 impressions — below 0.5% cap with 100+ impressions
        $this->insertSearchTerm(1, 'shoe history documentary', 200, 0, 0.0, 0);

        $result = $this->miner->suggestNegatives(1);

        $terms = array_column($result, 'search_term');
        $this->assertContains('shoe history documentary', $terms);

        $candidate = $result[array_search('shoe history documentary', $terms)];
        $this->assertStringContainsString('Very low CTR', $candidate['reasons'][0]);
    }

    public function testLowCtrBelowMinImpressionsThresholdIsNotSuggested(): void
    {
        // CTR = 0% but only 50 impressions (below 100 minimum for suggestNegatives)
        $this->insertSearchTerm(1, 'obscure query', 50, 0, 0.0, 0);

        $result = $this->miner->suggestNegatives(1);

        $this->assertSame([], $result);
    }

    public function testSuggestNegativesCandidateContainsExpectedFields(): void
    {
        $this->insertSearchTerm(1, 'shoe repair kit diy', 200, 0, 0.0, 8_000_000);

        $result = $this->miner->suggestNegatives(1);

        $this->assertNotEmpty($result, 'Expected at least one negative suggestion');
        $candidate = $result[0];
        foreach (['search_term', 'ad_group_id', 'campaign_id', 'impressions', 'clicks', 'ctr', 'cost', 'conversions', 'reasons', 'recommendation'] as $field) {
            $this->assertArrayHasKey($field, $candidate, "Missing field: {$field}");
        }
        $this->assertIsArray($candidate['reasons']);
    }

    public function testTermMeetingBothNegativeCriteriaHasTwoReasons(): void
    {
        // $10 spend (>$5), zero conversions, AND CTR=0% with 200 impressions (>=100)
        $this->insertSearchTerm(1, 'old shoe polishing', 200, 0, 0.0, 10_000_000);

        $result = $this->miner->suggestNegatives(1);

        $this->assertCount(1, $result);
        $this->assertCount(2, $result[0]['reasons']);
    }

    public function testSuggestNegativesIgnoresOtherProjects(): void
    {
        $this->db->exec(
            "INSERT INTO projects (id, name, display_name) VALUES (2, 'other-proj', 'Other')"
        );
        // Insert a high-spend term for project 2
        $this->db->exec(
            "INSERT INTO search_terms (project_id, campaign_id, ad_group_id, search_term, impressions, clicks, conversions, cost_micros, date)
             VALUES (2, 1, 1, 'other project wasteful term', 50, 1, 0, 20000000, date('now'))"
        );

        $result = $this->miner->suggestNegatives(1);

        $this->assertSame([], $result);
    }
}
