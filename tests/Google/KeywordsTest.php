<?php

declare(strict_types=1);

namespace AdManager\Tests\Google;

use AdManager\Google\Keywords;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Keywords::loadNegativesCsv() and Keywords::loadCsv().
 *
 * Both methods are pure file-parsing logic with no Google Ads API calls.
 * We test against temporary CSV files so no live credentials are needed.
 */
class KeywordsTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Write a string to a temp file and return its path.
     * The file is cleaned up automatically via addToAssertionCount.
     */
    private function writeTempCsv(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'admanager_test_');
        file_put_contents($path, $content);
        // Register cleanup on test teardown
        $this->registerTestCleanup($path);
        return $path;
    }

    /** @var string[] */
    private array $tempFiles = [];

    private function registerTestCleanup(string $path): void
    {
        $this->tempFiles[] = $path;
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $f) {
            if (file_exists($f)) {
                unlink($f);
            }
        }
        $this->tempFiles = [];
    }

    // -------------------------------------------------------------------------
    // loadNegativesCsv() tests
    // -------------------------------------------------------------------------

    public function testLoadNegativesCsvReturnsAllRows(): void
    {
        $csv = <<<CSV
        Level,Campaign,Keyword,Match Type
        Account,,free,Broad
        Account,,template,Broad
        Campaign,Non-Brand -- Website Audit,audit and fix,Phrase
        CSV;

        $rows = Keywords::loadNegativesCsv($this->writeTempCsv($csv));

        $this->assertCount(3, $rows);
    }

    public function testLoadNegativesCsvAccountLevelRowHasEmptyCampaign(): void
    {
        $csv = <<<CSV
        Level,Campaign,Keyword,Match Type
        Account,,free,Broad
        CSV;

        $rows = Keywords::loadNegativesCsv($this->writeTempCsv($csv));

        $this->assertSame('Account', $rows[0]['level']);
        $this->assertSame('', $rows[0]['campaign']);
        $this->assertSame('free', $rows[0]['text']);
        $this->assertSame('Broad', $rows[0]['match_type']);
    }

    public function testLoadNegativesCsvCampaignLevelRowHasCampaignName(): void
    {
        $csv = <<<CSV
        Level,Campaign,Keyword,Match Type
        Campaign,Non-Brand -- Website Audit,audit and fix,Phrase
        CSV;

        $rows = Keywords::loadNegativesCsv($this->writeTempCsv($csv));

        $this->assertSame('Campaign', $rows[0]['level']);
        $this->assertSame('Non-Brand -- Website Audit', $rows[0]['campaign']);
        $this->assertSame('audit and fix', $rows[0]['text']);
        $this->assertSame('Phrase', $rows[0]['match_type']);
    }

    public function testLoadNegativesCsvSeparatesAccountFromCampaignRows(): void
    {
        $csv = <<<CSV
        Level,Campaign,Keyword,Match Type
        Account,,free,Broad
        Account,,template,Broad
        Campaign,Non-Brand -- Website Audit,audit and fix,Phrase
        Campaign,Non-Brand -- Website Problems,auditandfix,Broad
        CSV;

        $rows = Keywords::loadNegativesCsv($this->writeTempCsv($csv));

        $accountRows  = array_filter($rows, fn($r) => $r['level'] === 'Account');
        $campaignRows = array_filter($rows, fn($r) => $r['level'] === 'Campaign');

        $this->assertCount(2, $accountRows);
        $this->assertCount(2, $campaignRows);
    }

    public function testLoadNegativesCsvSkipsRowsWithFewerThanFourColumns(): void
    {
        $csv = <<<CSV
        Level,Campaign,Keyword,Match Type
        Account,,free,Broad
        bad,row
        Campaign,Non-Brand -- Website Audit,audit and fix,Phrase
        CSV;

        $rows = Keywords::loadNegativesCsv($this->writeTempCsv($csv));

        // The malformed "bad,row" line (only 2 columns) should be skipped
        $this->assertCount(2, $rows);
    }

    public function testLoadNegativesCsvEmptyFileReturnsEmptyArray(): void
    {
        $csv  = "Level,Campaign,Keyword,Match Type\n"; // header only
        $rows = Keywords::loadNegativesCsv($this->writeTempCsv($csv));

        $this->assertSame([], $rows);
    }

    public function testLoadNegativesCsvAgainstRealFile(): void
    {
        $realPath = '/home/jason/code/mmo-platform/docs/google-ads/negative-keywords.csv';

        if (!file_exists($realPath)) {
            $this->markTestSkipped('Real negative-keywords.csv not accessible from test env');
        }

        $rows = Keywords::loadNegativesCsv($realPath);

        // File has 81 Account rows + 28 Campaign rows = 109 data rows (excl. header)
        $this->assertGreaterThan(80, count($rows));

        $accountRows  = array_filter($rows, fn($r) => $r['level'] === 'Account');
        $campaignRows = array_filter($rows, fn($r) => $r['level'] === 'Campaign');

        $this->assertNotEmpty($accountRows, 'Expected at least one Account-level row');
        $this->assertNotEmpty($campaignRows, 'Expected at least one Campaign-level row');

        // Account rows must have empty campaign field
        foreach ($accountRows as $row) {
            $this->assertSame('', $row['campaign'], 'Account-level rows must have empty campaign');
        }

        // Campaign rows must have non-empty campaign field
        foreach ($campaignRows as $row) {
            $this->assertNotSame('', $row['campaign'], 'Campaign-level rows must have a campaign name');
        }

        // Every row must have the four required keys
        foreach ($rows as $row) {
            $this->assertArrayHasKey('level', $row);
            $this->assertArrayHasKey('campaign', $row);
            $this->assertArrayHasKey('text', $row);
            $this->assertArrayHasKey('match_type', $row);
        }
    }

    // -------------------------------------------------------------------------
    // loadCsv() tests
    // -------------------------------------------------------------------------

    public function testLoadCsvParsesAllColumns(): void
    {
        $csv = <<<CSV
        Campaign,Ad Group,Keyword,Match Type,Max CPC
        Non-Brand -- Website Audit,Audit Your Site,website audit,EXACT,2.50
        CSV;

        $rows = Keywords::loadCsv($this->writeTempCsv($csv));

        $this->assertCount(1, $rows);
        $this->assertSame('Non-Brand -- Website Audit', $rows[0]['campaign']);
        $this->assertSame('Audit Your Site', $rows[0]['ad_group']);
        $this->assertSame('website audit', $rows[0]['text']);
        $this->assertSame('EXACT', $rows[0]['match_type']);
        $this->assertSame(2_500_000, $rows[0]['cpc_micros']);
    }

    public function testLoadCsvConvertsDollarAmountToMicros(): void
    {
        $csv = <<<CSV
        Campaign,Ad Group,Keyword,Match Type,Max CPC
        Foo,Bar,test keyword,BROAD,1.00
        Foo,Bar,another,PHRASE,0.50
        Foo,Bar,cheap,BROAD,0.01
        CSV;

        $rows = Keywords::loadCsv($this->writeTempCsv($csv));

        $this->assertSame(1_000_000, $rows[0]['cpc_micros']);
        $this->assertSame(500_000, $rows[1]['cpc_micros']);
        $this->assertSame(10_000, $rows[2]['cpc_micros']);
    }

    public function testLoadCsvSkipsRowsWithFewerThanFiveColumns(): void
    {
        $csv = <<<CSV
        Campaign,Ad Group,Keyword,Match Type,Max CPC
        Good,Group,keyword,EXACT,2.50
        bad,row,short
        Good,Group,second,BROAD,1.00
        CSV;

        $rows = Keywords::loadCsv($this->writeTempCsv($csv));

        $this->assertCount(2, $rows);
    }
}
