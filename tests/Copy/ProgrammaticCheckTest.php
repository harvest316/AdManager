<?php

namespace AdManager\Tests\Copy;

use AdManager\Copy\ProgrammaticCheck;
use PHPUnit\Framework\TestCase;

class ProgrammaticCheckTest extends TestCase
{
    private ProgrammaticCheck $checker;

    protected function setUp(): void
    {
        $this->checker = new ProgrammaticCheck();
    }

    private function makeItem(array $overrides = []): array
    {
        return array_merge([
            'id'            => 1,
            'platform'      => 'google',
            'campaign_name' => 'Test-Campaign',
            'ad_group_name' => null,
            'copy_type'     => 'headline',
            'content'       => 'Test Headline Here',
            'char_limit'    => 30,
            'pin_position'  => null,
            'target_market' => 'all',
        ], $overrides);
    }

    // Rule 1: Character limit
    public function testCharLimitPass(): void
    {
        $item = $this->makeItem(['content' => 'Short Headline']); // 14 chars
        $results = $this->checker->checkAll([$item], 'TestBrand');
        $issues = $results[1]['issues'];
        $charIssues = array_filter($issues, fn($i) => $i['category'] === 'char_limit');
        $this->assertEmpty($charIssues);
    }

    public function testCharLimitFail(): void
    {
        $item = $this->makeItem(['content' => 'This Headline Is Way Too Long For Google Ads']); // >30
        $results = $this->checker->checkAll([$item], 'TestBrand');
        $issues = $results[1]['issues'];
        $charIssues = array_filter($issues, fn($i) => $i['category'] === 'char_limit');
        $this->assertNotEmpty($charIssues);
        $this->assertEquals('fail', reset($charIssues)['severity']);
    }

    // Rule 2: Empty content
    public function testEmptyContentFails(): void
    {
        $item = $this->makeItem(['content' => '   ']);
        $results = $this->checker->checkAll([$item], 'TestBrand');
        $issues = $results[1]['issues'];
        $empty = array_filter($issues, fn($i) => $i['category'] === 'empty_content');
        $this->assertNotEmpty($empty);
    }

    // Rule 6: Standalone readability
    public function testStandaloneConjunction(): void
    {
        $item = $this->makeItem(['content' => 'And More Features']);
        $results = $this->checker->checkAll([$item], 'TestBrand');
        $issues = $results[1]['issues'];
        $standalone = array_filter($issues, fn($i) => $i['category'] === 'standalone');
        $this->assertNotEmpty($standalone);
    }

    public function testStandaloneTrailingComma(): void
    {
        $item = $this->makeItem(['content' => 'Great Products,']);
        $results = $this->checker->checkAll([$item], 'TestBrand');
        $issues = $results[1]['issues'];
        $standalone = array_filter($issues, fn($i) => $i['category'] === 'standalone');
        $this->assertNotEmpty($standalone);
    }

    // Rule 8: Superlatives
    public function testUnsubstantiatedSuperlative(): void
    {
        $item = $this->makeItem(['content' => 'The Best AI Coloring']);
        $results = $this->checker->checkAll([$item], 'TestBrand');
        $issues = $results[1]['issues'];
        $sup = array_filter($issues, fn($i) => $i['category'] === 'superlative');
        $this->assertNotEmpty($sup);
    }

    public function testQualifiedSuperlativeOk(): void
    {
        $item = $this->makeItem(['content' => 'Rated Best by Parents']);
        $results = $this->checker->checkAll([$item], 'TestBrand');
        $issues = $results[1]['issues'];
        $sup = array_filter($issues, fn($i) => $i['category'] === 'superlative');
        $this->assertEmpty($sup);
    }

    // Rule 9: Punctuation
    public function testExclamationInHeadline(): void
    {
        $item = $this->makeItem(['content' => 'Try It Now!']);
        $results = $this->checker->checkAll([$item], 'TestBrand');
        $issues = $results[1]['issues'];
        $punct = array_filter($issues, fn($i) => $i['category'] === 'punctuation');
        $this->assertNotEmpty($punct);
        $this->assertEquals('fail', reset($punct)['severity']);
    }

    public function testMultipleExclamationsInDescription(): void
    {
        $item = $this->makeItem([
            'copy_type'  => 'description',
            'char_limit' => 90,
            'content'    => 'Amazing results! Try it now! You will love it!',
        ]);
        $results = $this->checker->checkAll([$item], 'TestBrand');
        $issues = $results[1]['issues'];
        $punct = array_filter($issues, fn($i) => $i['category'] === 'punctuation');
        $this->assertNotEmpty($punct);
    }

    // Rule 10: ALL CAPS
    public function testAllCapsWord(): void
    {
        $item = $this->makeItem(['content' => 'AMAZING Results Today']);
        $results = $this->checker->checkAll([$item], 'TestBrand');
        $issues = $results[1]['issues'];
        $caps = array_filter($issues, fn($i) => $i['category'] === 'capitalisation');
        $this->assertNotEmpty($caps);
    }

    public function testAcronymsAllowed(): void
    {
        $item = $this->makeItem(['content' => 'AI Coloring Pages']);
        $results = $this->checker->checkAll([$item], 'TestBrand');
        $issues = $results[1]['issues'];
        $caps = array_filter($issues, fn($i) => $i['category'] === 'capitalisation');
        $this->assertEmpty($caps);
    }

    // Rule 15: Special characters
    public function testEmojiDetected(): void
    {
        $item = $this->makeItem(['content' => 'Try It Today 🎨']);
        $results = $this->checker->checkAll([$item], 'TestBrand');
        $issues = $results[1]['issues'];
        $special = array_filter($issues, fn($i) => $i['category'] === 'special_chars');
        $this->assertNotEmpty($special);
    }

    public function testTrademarkDetected(): void
    {
        $item = $this->makeItem(['content' => 'Colormora™ Today']);
        $results = $this->checker->checkAll([$item], 'TestBrand');
        $issues = $results[1]['issues'];
        $special = array_filter($issues, fn($i) => $i['category'] === 'special_chars');
        $this->assertNotEmpty($special);
    }

    // Rule 14: Whitespace auto-fix
    public function testWhitespaceAutoFixed(): void
    {
        $item = $this->makeItem(['content' => '  Leading Space ']);
        $results = $this->checker->checkAll([$item], 'TestBrand');
        $this->assertContains('Leading Space', $results[1]['auto_fixed']);
    }

    // Overall status
    public function testOverallStatusFail(): void
    {
        $issues = [
            ['severity' => 'warning'],
            ['severity' => 'fail'],
        ];
        $this->assertEquals('fail', ProgrammaticCheck::overallStatus($issues));
    }

    public function testOverallStatusWarning(): void
    {
        $issues = [['severity' => 'warning']];
        $this->assertEquals('warning', ProgrammaticCheck::overallStatus($issues));
    }

    public function testOverallStatusPass(): void
    {
        $this->assertEquals('pass', ProgrammaticCheck::overallStatus([]));
    }

    // Campaign-level: Rule 5 duplicates
    public function testDuplicateHeadlines(): void
    {
        $items = [
            $this->makeItem(['id' => 1, 'content' => 'Try Colormora Today']),
            $this->makeItem(['id' => 2, 'content' => 'Try Colormora Today']),
        ];

        // Need 15 headlines to avoid RSA count failure affecting results
        for ($i = 3; $i <= 15; $i++) {
            $items[] = $this->makeItem(['id' => $i, 'content' => "Headline Number {$i}"]);
        }
        // Add 4 descriptions
        for ($i = 16; $i <= 19; $i++) {
            $items[] = $this->makeItem(['id' => $i, 'copy_type' => 'description', 'char_limit' => 90, 'content' => "Description text for item {$i} here."]);
        }

        $results = $this->checker->checkAll($items, 'Colormora');
        $dupIssues = array_filter($results[2]['issues'] ?? [], fn($i) => $i['category'] === 'duplicate');
        $this->assertNotEmpty($dupIssues);
    }
}
