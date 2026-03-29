<?php

namespace AdManager\Tests\Copy;

use AdManager\Copy\Parser;
use PHPUnit\Framework\TestCase;

class ParserTest extends TestCase
{
    private Parser $parser;

    protected function setUp(): void
    {
        $this->parser = new Parser();
    }

    private function sampleStrategy(): string
    {
        return <<<'MD'
## 8. Ad Copy

### Google Ads -- RSA for CM-Search-NonBrand-ColorBook

**Headlines (15):**

1. `AI Coloring Pages in Minutes` (PIN to position 1)
2. `Create Custom Coloring Books`
3. `Your Kids Will Love These`
4. `AI-Generated Coloring Pages`
5. `Any Theme, Any Age, Any Time`
6. `Dinosaurs, Unicorns & More`
7. `Print-Ready Coloring Books`
8. `Made by AI, Loved by Kids`
9. `From Prompt to Page in Seconds`
10. `Custom Themes for Your Child`
11. `Create & Print at Home`
12. `Personalised Coloring Books`
13. `Professional Print Quality`
14. `Credits Start at Just $10`
15. `Try Colormora Today`

**Descriptions (4):**

1. `Create stunning AI-generated coloring books in minutes. Choose any theme your kids love.`
2. `Colormora turns your ideas into beautiful coloring pages. Type a prompt, get a printable page.`
3. `Give your kids a coloring book made just for them. AI creates custom pages from any theme.`
4. `Tired of the same old coloring books? Create personalised pages with AI.`

**Sitelinks:**
1. Browse the Gallery -- See thousands of AI-generated coloring pages
2. View Pricing -- Credit packs from $10 to $90
3. How It Works -- Type a prompt, get a coloring page
4. Order Prints -- Professional print quality, shipped to you

**Callout extensions:**
- AI-Generated in Minutes
- Print & Ship Available
- Custom Themes
- Kid-Safe Designs

**Structured snippet:**
- Type: Features -> Coloring Books, Color Palettes, Print Ordering, Custom Themes

### Google Ads -- RSA for CM-Search-Brand

**Headlines (15):**

1. `Colormora -- AI Coloring Books` (PIN to position 1)
2. `Official Colormora Site`
3. `Create Custom Coloring Pages`
4. `AI-Powered Coloring Books`
5. `Print-Ready Coloring Pages`
6. `Credits from $10`
7. `Any Theme, Any Style`
8. `Try Colormora Free`
9. `Colormora.com`
10. `Custom AI Coloring Books`
11. `Browse Our Gallery`
12. `Professional Print Quality`
13. `Made for Families`
14. `Create in Minutes`
15. `Formerly ColorCraft AI`

**Descriptions (4):**

1. `Colormora creates AI-generated coloring books and color palettes. Browse the gallery.`
2. `Create personalised coloring books with AI. Any theme your kids love, ready in minutes.`
3. `Welcome to Colormora where AI meets creativity. Generate custom coloring pages.`
4. `The AI coloring book generator for families. Type any theme, get a beautiful coloring page.`

### Meta Ads -- Primary Text

**Ad Set 1: Mothers -- Coloring for Kids**

Version A (Direct benefit):
> My kids used to burn through coloring books in a day. Now I just type what they want and Colormora creates custom pages in seconds.

Version B (Curiosity hook):
> I stopped buying coloring books from the store. Instead, I let my kids tell me exactly what they want to colour.

---

## 9. Creative Testing Framework
MD;
    }

    public function testParsesHeadlinesForBothCampaigns(): void
    {
        $items = $this->parser->parse($this->sampleStrategy());
        $headlines = array_filter($items, fn($i) => $i['copy_type'] === 'headline');

        $this->assertCount(30, $headlines, 'Should extract 15 headlines from each of 2 RSA campaigns');
    }

    public function testParsesDescriptionsForBothCampaigns(): void
    {
        $items = $this->parser->parse($this->sampleStrategy());
        $descriptions = array_filter($items, fn($i) => $i['copy_type'] === 'description');

        $this->assertCount(8, $descriptions, 'Should extract 4 descriptions from each of 2 RSA campaigns');
    }

    public function testExtractsPinPositions(): void
    {
        $items = $this->parser->parse($this->sampleStrategy());
        $pinned = array_filter($items, fn($i) => $i['pin_position'] !== null);

        $this->assertNotEmpty($pinned);

        $pin1Items = array_filter($items, fn($i) => $i['pin_position'] === 1);
        $this->assertCount(2, $pin1Items, 'Each campaign should have one PIN to position 1');
    }

    public function testExtractsCampaignNames(): void
    {
        $items = $this->parser->parse($this->sampleStrategy());
        $headlines = array_filter($items, fn($i) => $i['copy_type'] === 'headline');
        $campaignNames = array_unique(array_column($headlines, 'campaign_name'));
        sort($campaignNames);

        $this->assertContains('CM-Search-NonBrand-ColorBook', $campaignNames);
        $this->assertContains('CM-Search-Brand', $campaignNames);
    }

    public function testExtractsCharLimits(): void
    {
        $items = $this->parser->parse($this->sampleStrategy());

        $headline = array_values(array_filter($items, fn($i) => $i['copy_type'] === 'headline'))[0];
        $this->assertEquals(30, $headline['char_limit']);

        $desc = array_values(array_filter($items, fn($i) => $i['copy_type'] === 'description'))[0];
        $this->assertEquals(90, $desc['char_limit']);
    }

    public function testAllHeadlinesAreGooglePlatform(): void
    {
        $items = $this->parser->parse($this->sampleStrategy());
        $headlines = array_filter($items, fn($i) => $i['copy_type'] === 'headline');

        foreach ($headlines as $h) {
            $this->assertEquals('google', $h['platform']);
        }
    }

    public function testParsesSitelinks(): void
    {
        $items = $this->parser->parse($this->sampleStrategy());
        $sitelinks = array_filter($items, fn($i) => $i['copy_type'] === 'sitelink');

        $this->assertCount(4, $sitelinks);
    }

    public function testParsesCallouts(): void
    {
        $items = $this->parser->parse($this->sampleStrategy());
        $callouts = array_filter($items, fn($i) => $i['copy_type'] === 'callout');

        $this->assertCount(4, $callouts);
        $contents = array_column(array_values($callouts), 'content');
        $this->assertContains('AI-Generated in Minutes', $contents);
    }

    public function testParsesStructuredSnippets(): void
    {
        $items = $this->parser->parse($this->sampleStrategy());
        $snippets = array_filter($items, fn($i) => $i['copy_type'] === 'structured_snippet');

        $this->assertNotEmpty($snippets);
    }

    public function testParsesMetaPrimaryText(): void
    {
        $items = $this->parser->parse($this->sampleStrategy());
        $metaText = array_filter($items, fn($i) => $i['copy_type'] === 'primary_text');

        $this->assertNotEmpty($metaText);
        foreach ($metaText as $item) {
            $this->assertEquals('meta', $item['platform']);
            $this->assertEquals(2000, $item['char_limit']);
        }
    }

    public function testHeadlineContentIsClean(): void
    {
        $items = $this->parser->parse($this->sampleStrategy());
        $headlines = array_filter($items, fn($i) => $i['copy_type'] === 'headline');

        foreach ($headlines as $h) {
            // No backticks in content
            $this->assertStringNotContainsString('`', $h['content']);
            // No leading/trailing whitespace
            $this->assertEquals(trim($h['content']), $h['content']);
        }
    }

    public function testEmptyMarkdownReturnsEmpty(): void
    {
        $items = $this->parser->parse('No ad copy here');
        $this->assertEmpty($items);
    }
}
