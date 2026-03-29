<?php

namespace AdManager\Tests\Copy;

use AdManager\Copy\Proofreader;
use PHPUnit\Framework\TestCase;

class ProofreaderTest extends TestCase
{
    private Proofreader $proofreader;

    protected function setUp(): void
    {
        $this->proofreader = new Proofreader();
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

    private function sampleStrategy(): array
    {
        return [
            'id' => 1,
            'target_audience' => 'Mums 25-45 with kids',
            'value_proposition' => 'AI-generated coloring books',
        ];
    }

    public function testBuildPromptContainsContext(): void
    {
        $method = new \ReflectionMethod(Proofreader::class, 'buildPrompt');
        $method->setAccessible(true);

        $items = [
            ['id' => 1, 'platform' => 'google', 'campaign_name' => 'Test', 'copy_type' => 'headline', 'content' => 'AI Coloring Pages', 'char_limit' => 30, 'pin_position' => 1],
        ];

        $prompt = $method->invoke($this->proofreader, $items, $this->sampleProject(), $this->sampleStrategy(), 'AU');

        $this->assertStringContainsString('Colormora', $prompt);
        $this->assertStringContainsString('Mums 25-45', $prompt);
        $this->assertStringContainsString('AI Coloring Pages', $prompt);
        $this->assertStringContainsString('British English', $prompt);
    }

    public function testBuildPromptIncludesGooglePolicies(): void
    {
        $method = new \ReflectionMethod(Proofreader::class, 'buildPrompt');
        $method->setAccessible(true);

        $items = [
            ['id' => 1, 'platform' => 'google', 'campaign_name' => 'Test', 'copy_type' => 'headline', 'content' => 'Test', 'char_limit' => 30, 'pin_position' => null],
        ];

        $prompt = $method->invoke($this->proofreader, $items, $this->sampleProject(), $this->sampleStrategy(), 'US');

        $this->assertStringContainsString('Google Ads', $prompt);
    }

    public function testBuildPromptIncludesMetaPolicies(): void
    {
        $method = new \ReflectionMethod(Proofreader::class, 'buildPrompt');
        $method->setAccessible(true);

        $items = [
            ['id' => 1, 'platform' => 'meta', 'campaign_name' => null, 'copy_type' => 'primary_text', 'content' => 'Test copy', 'char_limit' => 2000, 'pin_position' => null],
        ];

        $prompt = $method->invoke($this->proofreader, $items, $this->sampleProject(), $this->sampleStrategy(), 'US');

        $this->assertStringContainsString('Meta', $prompt);
    }

    public function testBuildPromptIncludesBothPoliciesForMixedPlatforms(): void
    {
        $method = new \ReflectionMethod(Proofreader::class, 'buildPrompt');
        $method->setAccessible(true);

        $items = [
            ['id' => 1, 'platform' => 'google', 'campaign_name' => 'Test', 'copy_type' => 'headline', 'content' => 'Test', 'char_limit' => 30, 'pin_position' => null],
            ['id' => 2, 'platform' => 'meta', 'campaign_name' => null, 'copy_type' => 'primary_text', 'content' => 'Test', 'char_limit' => 2000, 'pin_position' => null],
        ];

        $prompt = $method->invoke($this->proofreader, $items, $this->sampleProject(), $this->sampleStrategy(), 'AU');

        $this->assertStringContainsString('Google Ads', $prompt);
        $this->assertStringContainsString('Meta', $prompt);
    }

    public function testParseResponseValid(): void
    {
        $method = new \ReflectionMethod(Proofreader::class, 'parseResponse');
        $method->setAccessible(true);

        $json = json_encode([
            'overall_score' => 85,
            'items' => [
                [
                    'id' => 1,
                    'content' => 'AI Coloring Pages',
                    'score' => 90,
                    'verdict' => 'pass',
                    'strengths' => ['Clear benefit'],
                    'issues' => [],
                    'suggestion' => null,
                ],
            ],
        ]);

        $result = $method->invoke($this->proofreader, $json);

        $this->assertIsArray($result);
        $this->assertEquals(85, $result['overall_score']);
        $this->assertCount(1, $result['items']);
        $this->assertEquals(90, $result['items'][0]['score']);
    }

    public function testParseResponseStripsMarkdownFencing(): void
    {
        $method = new \ReflectionMethod(Proofreader::class, 'parseResponse');
        $method->setAccessible(true);

        $fenced = "```json\n" . json_encode(['overall_score' => 75, 'items' => []]) . "\n```";
        $result = $method->invoke($this->proofreader, $fenced);

        $this->assertIsArray($result);
        $this->assertEquals(75, $result['overall_score']);
    }

    public function testParseResponseInvalidJson(): void
    {
        $method = new \ReflectionMethod(Proofreader::class, 'parseResponse');
        $method->setAccessible(true);

        ob_start();
        $result = $method->invoke($this->proofreader, 'not valid json at all');
        ob_end_clean();

        $this->assertNull($result);
    }

    public function testParseResponseMissingItems(): void
    {
        $method = new \ReflectionMethod(Proofreader::class, 'parseResponse');
        $method->setAccessible(true);

        ob_start();
        $result = $method->invoke($this->proofreader, json_encode(['overall_score' => 50]));
        ob_end_clean();

        $this->assertNull($result);
    }

    public function testLoadPoliciesGoogle(): void
    {
        $method = new \ReflectionMethod(Proofreader::class, 'loadPolicies');
        $method->setAccessible(true);

        $result = $method->invoke($this->proofreader, ['google']);
        $this->assertStringContainsString('Google', $result);
    }

    public function testLoadPoliciesMeta(): void
    {
        $method = new \ReflectionMethod(Proofreader::class, 'loadPolicies');
        $method->setAccessible(true);

        $result = $method->invoke($this->proofreader, ['meta']);
        $this->assertStringContainsString('Meta', $result);
    }

    public function testLoadPoliciesUnknownPlatform(): void
    {
        $method = new \ReflectionMethod(Proofreader::class, 'loadPolicies');
        $method->setAccessible(true);

        $result = $method->invoke($this->proofreader, ['tiktok']);
        $this->assertStringContainsString('No platform policy', $result);
    }

    public function testBatchSizeConstant(): void
    {
        $ref = new \ReflectionClass(Proofreader::class);
        $constant = $ref->getConstant('BATCH_SIZE');
        $this->assertEquals(10, $constant);
    }
}
