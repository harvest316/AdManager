<?php

namespace AdManager\Tests\Copy;

use AdManager\Copy\Generator;
use PHPUnit\Framework\TestCase;

class GeneratorTest extends TestCase
{
    private Generator $generator;

    protected function setUp(): void
    {
        $this->generator = new Generator();
    }

    private function sampleProject(): array
    {
        return [
            'id' => 1,
            'name' => 'colormora',
            'display_name' => 'Colormora',
            'website_url' => 'https://colormora.com',
        ];
    }

    // Test the response parser for Google search copy format
    public function testParseGoogleSearchResponse(): void
    {
        $response = <<<'RESP'
# Ad Copy: Colormora — google search

## Headlines
1. [AI Coloring Pages Fast] (22 chars) [PIN: position 1]
2. [Custom Coloring Books] (21 chars)
3. [Try Colormora Today] (19 chars) [PIN: position 3]
4. [Print-Ready Pages] (17 chars)
5. [Any Theme You Want] (18 chars)

## Descriptions
1. [Create stunning AI coloring books in minutes. Choose any theme your kids love.] (79 chars)
2. [Turn your ideas into printable coloring pages with AI. Download or order prints.] (80 chars)

## Notes
Test headlines 1 and 3 as pinned, monitor CTR.
RESP;

        // Use reflection to test the private parseResponse method
        $method = new \ReflectionMethod(Generator::class, 'parseResponse');
        $method->setAccessible(true);
        $items = $method->invoke($this->generator, $response, 'google', 'search');

        $this->assertNotEmpty($items);

        $headlines = array_filter($items, fn($i) => $i['copy_type'] === 'headline');
        $descriptions = array_filter($items, fn($i) => $i['copy_type'] === 'description');

        $this->assertCount(5, $headlines);
        $this->assertCount(2, $descriptions);

        // Check pin positions
        $pinned = array_filter($items, fn($i) => $i['pin_position'] !== null);
        $this->assertCount(2, $pinned);

        // Check platform
        foreach ($items as $item) {
            $this->assertEquals('google', $item['platform']);
        }

        // Check char limits
        foreach ($headlines as $h) {
            $this->assertEquals(30, $h['char_limit']);
        }
        foreach ($descriptions as $d) {
            $this->assertEquals(90, $d['char_limit']);
        }
    }

    public function testParseMetaCopyResponse(): void
    {
        $response = <<<'RESP'
# Ad Copy: Colormora — meta traffic

## Primary Text Options
1. [My kids used to burn through coloring books. Now AI makes custom pages in seconds.] (83 chars)
2. [Stop buying generic coloring books. Create personalised ones with AI.] (69 chars)
3. [Every kid deserves their own coloring book. Colormora makes it happen.] (70 chars)

## Headline Options
1. [AI Coloring Pages] (18 chars)
2. [Custom Coloring Books] (21 chars)
3. [Made Just for Your Kids] (23 chars)

## Description Options
1. [Create in minutes] (17 chars)
2. [Print at home] (13 chars)
3. [Any theme, any age] (18 chars)

## CTA Recommendation
Learn More
RESP;

        $method = new \ReflectionMethod(Generator::class, 'parseResponse');
        $method->setAccessible(true);
        $items = $method->invoke($this->generator, $response, 'meta', 'traffic');

        $primaryText = array_filter($items, fn($i) => $i['copy_type'] === 'primary_text');
        $headlines = array_filter($items, fn($i) => $i['copy_type'] === 'headline');
        $descriptions = array_filter($items, fn($i) => $i['copy_type'] === 'description');

        $this->assertCount(3, $primaryText);
        $this->assertCount(3, $headlines);
        $this->assertCount(3, $descriptions);

        foreach ($items as $item) {
            $this->assertEquals('meta', $item['platform']);
        }

        // Check char limits
        foreach ($primaryText as $pt) {
            $this->assertEquals(2000, $pt['char_limit']);
        }
        foreach ($headlines as $h) {
            $this->assertEquals(40, $h['char_limit']);
        }
        foreach ($descriptions as $d) {
            $this->assertEquals(30, $d['char_limit']);
        }
    }

    public function testParseHeadlineList(): void
    {
        $response = <<<'RESP'
1. AI Coloring in Minutes
2. Custom Pages for Kids
3. Print Your Own Books
RESP;

        $method = new \ReflectionMethod(Generator::class, 'parseHeadlineList');
        $method->setAccessible(true);
        $items = $method->invoke($this->generator, $response);

        $this->assertCount(3, $items);
        $this->assertEquals('AI Coloring in Minutes', $items[0]['content']);
        $this->assertEquals('headline', $items[0]['copy_type']);
        $this->assertEquals(30, $items[0]['char_limit']);
    }

    public function testParseHeadlineListFiltersOverLength(): void
    {
        $response = <<<'RESP'
1. Short One
2. This headline is way too long for Google Ads and should be filtered out completely
3. Another Good One
RESP;

        $method = new \ReflectionMethod(Generator::class, 'parseHeadlineList');
        $method->setAccessible(true);
        $items = $method->invoke($this->generator, $response);

        $this->assertCount(2, $items);
        $this->assertEquals('Short One', $items[0]['content']);
        $this->assertEquals('Another Good One', $items[1]['content']);
    }

    public function testParseHeadlineListStripsQuotes(): void
    {
        $response = "1. \"Quoted Headline\"\n2. 'Single Quoted'\n3. `Backtick Headline`";

        $method = new \ReflectionMethod(Generator::class, 'parseHeadlineList');
        $method->setAccessible(true);
        $items = $method->invoke($this->generator, $response);

        $this->assertCount(3, $items);
        $this->assertEquals('Quoted Headline', $items[0]['content']);
        $this->assertEquals('Single Quoted', $items[1]['content']);
        $this->assertEquals('Backtick Headline', $items[2]['content']);
    }

    public function testBuildPromptUsesTemplate(): void
    {
        $method = new \ReflectionMethod(Generator::class, 'buildPrompt');
        $method->setAccessible(true);

        $prompt = $method->invoke(
            $this->generator,
            $this->sampleProject(),
            'google',
            'search',
            ['target_audience' => 'Mums 25-45', 'value_proposition' => 'AI coloring books']
        );

        $this->assertStringContainsString('Colormora', $prompt);
        $this->assertStringContainsString('colormora.com', $prompt);
        $this->assertStringContainsString('google', $prompt);
        $this->assertStringContainsString('Mums 25-45', $prompt);
        $this->assertStringContainsString('AI coloring books', $prompt);
        $this->assertStringContainsString('15 headlines', $prompt);
    }

    public function testBuildPromptMetaPlatform(): void
    {
        $method = new \ReflectionMethod(Generator::class, 'buildPrompt');
        $method->setAccessible(true);

        $prompt = $method->invoke(
            $this->generator,
            $this->sampleProject(),
            'meta',
            'traffic',
            []
        );

        $this->assertStringContainsString('meta', $prompt);
        $this->assertStringContainsString('5 primary text options', $prompt);
    }

    public function testEmptyResponseReturnsEmpty(): void
    {
        $method = new \ReflectionMethod(Generator::class, 'parseResponse');
        $method->setAccessible(true);
        $items = $method->invoke($this->generator, 'No structured output here', 'google', 'search');

        $this->assertEmpty($items);
    }

    public function testParseGoogleDisplayResponse(): void
    {
        $response = <<<'RESP'
## Headlines
1. [AI Coloring Pages] (18 chars)
2. [Custom Books for Kids] (21 chars)
3. [Create in Minutes] (17 chars)
4. [Print at Home] (13 chars)
5. [Any Theme Imaginable] (20 chars)

## Long Headline
1. [Create stunning AI-generated coloring books your kids will love — any theme, any age] (85 chars)

## Descriptions
1. [AI turns your ideas into printable coloring pages. Download or order prints instantly.] (86 chars)
2. [Custom coloring books for every occasion. Birthday parties, road trips, rainy days.] (82 chars)
RESP;

        $method = new \ReflectionMethod(Generator::class, 'parseResponse');
        $method->setAccessible(true);
        $items = $method->invoke($this->generator, $response, 'google', 'display');

        $headlines = array_filter($items, fn($i) => $i['copy_type'] === 'headline');
        $descriptions = array_filter($items, fn($i) => $i['copy_type'] === 'description');

        $this->assertGreaterThanOrEqual(5, count($headlines));
        $this->assertGreaterThanOrEqual(2, count($descriptions));
    }
}
