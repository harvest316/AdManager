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

    // Campaign-level: Rule 3 RSA count
    public function testRSACountTooFewHeadlines(): void
    {
        $items = [];
        for ($i = 1; $i <= 10; $i++) {
            $items[] = $this->makeItem(['id' => $i, 'content' => "Headline {$i}"]);
        }
        for ($i = 11; $i <= 14; $i++) {
            $items[] = $this->makeItem(['id' => $i, 'copy_type' => 'description', 'char_limit' => 90, 'content' => "Description {$i}"]);
        }

        $results = $this->checker->checkAll($items, 'TestBrand');
        // Should have rsa_count fail on first item (only 10 headlines, need 15)
        $allIssues = [];
        foreach ($results as $r) $allIssues = array_merge($allIssues, $r['issues']);
        $rsaIssues = array_filter($allIssues, fn($i) => $i['category'] === 'rsa_count');
        $this->assertNotEmpty($rsaIssues);
    }

    public function testRSACountCorrect(): void
    {
        $items = [];
        for ($i = 1; $i <= 15; $i++) {
            $items[] = $this->makeItem(['id' => $i, 'content' => "Headline Number {$i}"]);
        }
        for ($i = 16; $i <= 19; $i++) {
            $items[] = $this->makeItem(['id' => $i, 'copy_type' => 'description', 'char_limit' => 90, 'content' => "Description text {$i} here."]);
        }

        $results = $this->checker->checkAll($items, 'TestBrand');
        $allIssues = [];
        foreach ($results as $r) $allIssues = array_merge($allIssues, $r['issues']);
        $rsaIssues = array_filter($allIssues, fn($i) => $i['category'] === 'rsa_count');
        $this->assertEmpty($rsaIssues);
    }

    // Campaign-level: Rule 4 pin positions
    public function testPinPositionWarningNoPinOne(): void
    {
        $items = [];
        for ($i = 1; $i <= 15; $i++) {
            $items[] = $this->makeItem(['id' => $i, 'content' => "Headline Number {$i}"]);
        }
        for ($i = 16; $i <= 19; $i++) {
            $items[] = $this->makeItem(['id' => $i, 'copy_type' => 'description', 'char_limit' => 90, 'content' => "Description text {$i} here."]);
        }

        $results = $this->checker->checkAll($items, 'TestBrand');
        $allIssues = [];
        foreach ($results as $r) $allIssues = array_merge($allIssues, $r['issues']);
        $pinIssues = array_filter($allIssues, fn($i) => $i['category'] === 'pin_position');
        $this->assertNotEmpty($pinIssues, 'Should warn about no pin-1 headline');
    }

    // Campaign-level: Rule 12 brand presence
    public function testBrandPresenceWarning(): void
    {
        $items = [];
        for ($i = 1; $i <= 15; $i++) {
            $items[] = $this->makeItem(['id' => $i, 'content' => "Generic Headline {$i}"]);
        }
        for ($i = 16; $i <= 19; $i++) {
            $items[] = $this->makeItem(['id' => $i, 'copy_type' => 'description', 'char_limit' => 90, 'content' => "Description text {$i} here."]);
        }

        $results = $this->checker->checkAll($items, 'Colormora');
        $allIssues = [];
        foreach ($results as $r) $allIssues = array_merge($allIssues, $r['issues']);
        $brandIssues = array_filter($allIssues, fn($i) => $i['category'] === 'brand_presence');
        $this->assertNotEmpty($brandIssues, 'Should warn about missing brand name');
    }

    public function testBrandPresenceOk(): void
    {
        $items = [];
        $items[] = $this->makeItem(['id' => 1, 'content' => 'Try Colormora Today', 'pin_position' => 1]);
        $items[] = $this->makeItem(['id' => 2, 'content' => 'Colormora AI Pages']);
        for ($i = 3; $i <= 15; $i++) {
            $items[] = $this->makeItem(['id' => $i, 'content' => "Headline Number {$i}"]);
        }
        for ($i = 16; $i <= 19; $i++) {
            $items[] = $this->makeItem(['id' => $i, 'copy_type' => 'description', 'char_limit' => 90, 'content' => "Description text {$i} here."]);
        }

        $results = $this->checker->checkAll($items, 'Colormora');
        $allIssues = [];
        foreach ($results as $r) $allIssues = array_merge($allIssues, $r['issues']);
        $brandIssues = array_filter($allIssues, fn($i) => $i['category'] === 'brand_presence');
        $this->assertEmpty($brandIssues);
    }

    // Campaign-level: Rule 13 CTA presence
    public function testCTAPresenceWarning(): void
    {
        $items = [];
        for ($i = 1; $i <= 15; $i++) {
            $items[] = $this->makeItem(['id' => $i, 'content' => "Some Feature {$i}"]);
        }
        for ($i = 16; $i <= 19; $i++) {
            $items[] = $this->makeItem(['id' => $i, 'copy_type' => 'description', 'char_limit' => 90, 'content' => "Description text {$i} here."]);
        }

        $results = $this->checker->checkAll($items, 'TestBrand');
        $allIssues = [];
        foreach ($results as $r) $allIssues = array_merge($allIssues, $r['issues']);
        $ctaIssues = array_filter($allIssues, fn($i) => $i['category'] === 'cta_presence');
        $this->assertNotEmpty($ctaIssues, 'Should warn about missing CTA');
    }

    public function testCTAPresenceOk(): void
    {
        $items = [];
        $items[] = $this->makeItem(['id' => 1, 'content' => 'Try It Today']);
        for ($i = 2; $i <= 15; $i++) {
            $items[] = $this->makeItem(['id' => $i, 'content' => "Some Feature {$i}"]);
        }
        for ($i = 16; $i <= 19; $i++) {
            $items[] = $this->makeItem(['id' => $i, 'copy_type' => 'description', 'char_limit' => 90, 'content' => "Description text {$i} here."]);
        }

        $results = $this->checker->checkAll($items, 'TestBrand');
        $allIssues = [];
        foreach ($results as $r) $allIssues = array_merge($allIssues, $r['issues']);
        $ctaIssues = array_filter($allIssues, fn($i) => $i['category'] === 'cta_presence');
        $this->assertEmpty($ctaIssues);
    }

    // Rule 7: Locale spelling
    public function testLocaleSpellingUSinAU(): void
    {
        $item = $this->makeItem(['content' => 'Personalized Pages']);
        $results = $this->checker->checkAll([$item], 'TestBrand', 'AU');
        $issues = $results[1]['issues'];
        $locale = array_filter($issues, fn($i) => $i['category'] === 'locale');
        $this->assertNotEmpty($locale, 'US spelling in AU market should warn');
    }

    public function testLocaleSpellingCorrectForMarket(): void
    {
        $item = $this->makeItem(['content' => 'Personalised Pages']);
        $results = $this->checker->checkAll([$item], 'TestBrand', 'AU');
        $issues = $results[1]['issues'];
        $locale = array_filter($issues, fn($i) => $i['category'] === 'locale');
        $this->assertEmpty($locale);
    }

    // Rule 11: Phone in headline
    public function testPhoneInHeadline(): void
    {
        $item = $this->makeItem(['content' => 'Call 1300-555-123']);
        $results = $this->checker->checkAll([$item], 'TestBrand');
        $issues = $results[1]['issues'];
        $phone = array_filter($issues, fn($i) => $i['category'] === 'phone_in_headline');
        $this->assertNotEmpty($phone);
    }

    public function testPhoneInDescriptionIgnored(): void
    {
        $item = $this->makeItem(['copy_type' => 'description', 'char_limit' => 90, 'content' => 'Call us at 1300-555-123 today.']);
        $results = $this->checker->checkAll([$item], 'TestBrand');
        $issues = $results[1]['issues'];
        $phone = array_filter($issues, fn($i) => $i['category'] === 'phone_in_headline');
        $this->assertEmpty($phone, 'Phone numbers in descriptions should not trigger headline rule');
    }

    // Meta copy items should skip campaign-level checks
    public function testMetaCopySkipsCampaignRules(): void
    {
        $item = $this->makeItem([
            'platform' => 'meta',
            'campaign_name' => null,
            'copy_type' => 'primary_text',
            'char_limit' => 2000,
            'content' => 'This is a meta primary text that is longer than a headline.',
        ]);
        $results = $this->checker->checkAll([$item], 'TestBrand');
        $issues = $results[1]['issues'];
        $rsaIssues = array_filter($issues, fn($i) => $i['category'] === 'rsa_count');
        $this->assertEmpty($rsaIssues, 'Meta items should not trigger RSA count rules');
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
