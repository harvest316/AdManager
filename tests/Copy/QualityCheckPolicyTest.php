<?php

namespace AdManager\Tests\Copy;

use AdManager\Creative\QualityCheck;
use PHPUnit\Framework\TestCase;

/**
 * Tests for QualityCheck policy-aware prompt building.
 * Does NOT test the Claude CLI call (that requires the binary).
 */
class QualityCheckPolicyTest extends TestCase
{
    private QualityCheck $qa;

    protected function setUp(): void
    {
        $this->qa = new QualityCheck();
    }

    public function testBuildPromptDefaultPlatform(): void
    {
        $method = new \ReflectionMethod(QualityCheck::class, 'buildPrompt');
        $method->setAccessible(true);

        $prompt = $method->invoke($this->qa, 'all');

        // Should contain both Google and Meta visual rules
        $this->assertStringContainsString('Google Ads Visual Policy', $prompt);
        $this->assertStringContainsString('Meta Visual Policy', $prompt);
        $this->assertStringContainsString('TEXT LEGIBILITY', $prompt);
        $this->assertStringContainsString('AI ARTEFACTS', $prompt);
    }

    public function testBuildPromptGoogleOnly(): void
    {
        $method = new \ReflectionMethod(QualityCheck::class, 'buildPrompt');
        $method->setAccessible(true);

        $prompt = $method->invoke($this->qa, 'google');

        $this->assertStringContainsString('Google Ads Visual Policy', $prompt);
        $this->assertStringNotContainsString('Meta Visual Policy', $prompt);
        $this->assertStringContainsString('TEXT COVERAGE', $prompt);
    }

    public function testBuildPromptMetaOnly(): void
    {
        $method = new \ReflectionMethod(QualityCheck::class, 'buildPrompt');
        $method->setAccessible(true);

        $prompt = $method->invoke($this->qa, 'meta');

        $this->assertStringContainsString('Meta Visual Policy', $prompt);
        $this->assertStringNotContainsString('Google Ads Visual Policy', $prompt);
        $this->assertStringContainsString('PERSONAL ATTRIBUTES', $prompt);
        $this->assertStringContainsString('FAKE UI ELEMENTS', $prompt);
    }

    public function testBuildPromptContainsCrossplatformRules(): void
    {
        $method = new \ReflectionMethod(QualityCheck::class, 'buildPrompt');
        $method->setAccessible(true);

        $prompt = $method->invoke($this->qa, 'all');

        // Cross-platform rules should always be present
        $this->assertStringContainsString('WEAPONS/VIOLENCE', $prompt);
        $this->assertStringContainsString('ADULT CONTENT', $prompt);
        $this->assertStringContainsString('TOBACCO/DRUGS', $prompt);
        $this->assertStringContainsString('COUNTERFEIT', $prompt);
        $this->assertStringContainsString('SHOCKING CONTENT', $prompt);
    }

    public function testBuildPromptReturnsJsonFormat(): void
    {
        $method = new \ReflectionMethod(QualityCheck::class, 'buildPrompt');
        $method->setAccessible(true);

        $prompt = $method->invoke($this->qa, 'all');

        $this->assertStringContainsString('"status"', $prompt);
        $this->assertStringContainsString('"issues"', $prompt);
        $this->assertStringContainsString('"policy_rule"', $prompt);
    }

    public function testFallbackPromptWhenTemplatesMissing(): void
    {
        // Create a QA instance with a bogus prompts dir
        $qa = new QualityCheck();
        $ref = new \ReflectionProperty(QualityCheck::class, 'promptsDir');
        $ref->setAccessible(true);
        $ref->setValue($qa, '/nonexistent/path');

        $method = new \ReflectionMethod(QualityCheck::class, 'buildPrompt');
        $method->setAccessible(true);

        $prompt = $method->invoke($qa, 'google');

        // Should return fallback prompt
        $this->assertStringContainsString('creative QA specialist', $prompt);
    }

    public function testGooglePoliciesHaveSpecificRules(): void
    {
        $ref = new \ReflectionClass(QualityCheck::class);
        $constant = $ref->getConstant('GOOGLE_VISUAL_POLICIES');

        $this->assertStringContainsString('MISLEADING UI', $constant);
        $this->assertStringContainsString('BEFORE/AFTER', $constant);
        $this->assertStringContainsString('TEXT COVERAGE', $constant);
        $this->assertStringContainsString('GIMMICKY FORMATTING', $constant);
    }

    public function testMetaPoliciesHaveSpecificRules(): void
    {
        $ref = new \ReflectionClass(QualityCheck::class);
        $constant = $ref->getConstant('META_VISUAL_POLICIES');

        $this->assertStringContainsString('BEFORE/AFTER', $constant);
        $this->assertStringContainsString('PERSONAL ATTRIBUTES', $constant);
        $this->assertStringContainsString('PLATFORM ENDORSEMENT', $constant);
        $this->assertStringContainsString('FAKE UI ELEMENTS', $constant);
        $this->assertStringContainsString('SENSATIONAL IMAGERY', $constant);
    }

    public function testCheckMethodAcceptsPlatformParam(): void
    {
        // Verify the method signature accepts a platform parameter
        $method = new \ReflectionMethod(QualityCheck::class, 'check');
        $params = $method->getParameters();
        $this->assertCount(2, $params);
        $this->assertEquals('assetId', $params[0]->getName());
        $this->assertEquals('platform', $params[1]->getName());
        $this->assertEquals('all', $params[1]->getDefaultValue());
    }

    public function testCheckAllDraftsAcceptsPlatformParam(): void
    {
        $method = new \ReflectionMethod(QualityCheck::class, 'checkAllDrafts');
        $params = $method->getParameters();
        $this->assertCount(2, $params);
        $this->assertEquals('projectId', $params[0]->getName());
        $this->assertEquals('platform', $params[1]->getName());
        $this->assertEquals('all', $params[1]->getDefaultValue());
    }
}
