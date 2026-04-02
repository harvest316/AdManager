<?php

namespace AdManager\Tests;

use AdManager\Locale;
use PHPUnit\Framework\TestCase;

class LocaleTest extends TestCase
{
    public function testLocaliseGBConvertsSpelling(): void
    {
        $this->assertStringContainsString('customise', Locale::localise('customize your experience', 'GB'));
        $this->assertStringContainsString('colour', Locale::localise('color your world', 'GB'));
        $this->assertStringContainsString('centre', Locale::localise('center of attention', 'GB'));
    }

    public function testLocaliseUSKeepsOriginal(): void
    {
        $text = 'customize your color scheme';
        $this->assertEquals($text, Locale::localise($text, 'US'));
    }

    public function testLocaliseCAKeepsUSSpelling(): void
    {
        $text = 'organize your favorites';
        $this->assertEquals($text, Locale::localise($text, 'CA'));
    }

    public function testLocaliseAUUsesGBSpelling(): void
    {
        $result = Locale::localise('personalized colors', 'AU');
        $this->assertStringContainsString('personalised', $result);
        $this->assertStringContainsString('colours', $result);
    }

    public function testLocaliseNZUsesGBSpelling(): void
    {
        $result = Locale::localise('optimize your catalog', 'NZ');
        $this->assertStringContainsString('optimise', $result);
        $this->assertStringContainsString('catalogue', $result);
    }

    public function testLocaliseIEUsesGBSpelling(): void
    {
        $result = Locale::localise('favorite flavors', 'IE');
        $this->assertStringContainsString('favourite', $result);
        $this->assertStringContainsString('flavours', $result);
    }

    public function testLocaliseIsCaseInsensitiveForMarket(): void
    {
        $lower = Locale::localise('customize', 'gb');
        $upper = Locale::localise('customize', 'GB');
        $this->assertEquals($lower, $upper);
    }

    public function testUsToGbConvertsCaseCorrectly(): void
    {
        $this->assertStringContainsString('Customise', Locale::usToGb('Customize'));
        $this->assertStringContainsString('customise', Locale::usToGb('customize'));
    }

    public function testGbToUsReverses(): void
    {
        $this->assertStringContainsString('customize', Locale::gbToUs('customise'));
        $this->assertStringContainsString('center', Locale::gbToUs('centre'));
    }

    public function testSpellingVariantReturnsCorrectVariant(): void
    {
        $this->assertEquals('gb', Locale::spellingVariant('GB'));
        $this->assertEquals('gb', Locale::spellingVariant('AU'));
        $this->assertEquals('gb', Locale::spellingVariant('NZ'));
        $this->assertEquals('gb', Locale::spellingVariant('IE'));
        $this->assertEquals('gb', Locale::spellingVariant('ZA'));
        $this->assertEquals('gb', Locale::spellingVariant('IN'));
        $this->assertEquals('us', Locale::spellingVariant('US'));
        $this->assertEquals('us', Locale::spellingVariant('CA'));
        $this->assertEquals('us', Locale::spellingVariant('XX')); // unknown defaults to US
    }

    public function testPromptInstructionGB(): void
    {
        $instruction = Locale::promptInstruction('GB');
        $this->assertStringContainsString('British English', $instruction);
        $this->assertStringContainsString('colour', $instruction);
    }

    public function testPromptInstructionUS(): void
    {
        $instruction = Locale::promptInstruction('US');
        $this->assertStringContainsString('American English', $instruction);
        $this->assertStringContainsString('color', $instruction);
    }

    public function testDoubledConsonants(): void
    {
        $result = Locale::usToGb('traveling modeled canceled');
        $this->assertStringContainsString('travelling', $result);
        $this->assertStringContainsString('modelled', $result);
        $this->assertStringContainsString('cancelled', $result);
    }

    public function testCulturalTerms(): void
    {
        $result = Locale::usToGb('Mom loves her mommy group');
        $this->assertStringContainsString('Mum', $result);
        $this->assertStringContainsString('mummy', $result);
    }

    public function testNoPartialMatchOnColor(): void
    {
        // "color " has a trailing space to avoid matching "Colorado"
        $result = Locale::usToGb('Colorado is beautiful');
        $this->assertStringContainsString('Colorado', $result);
        $this->assertStringNotContainsString('Colourado', $result);
    }

    public function testDefenseAndLicense(): void
    {
        $result = Locale::usToGb('defense license offense');
        $this->assertStringContainsString('defence', $result);
        $this->assertStringContainsString('licence', $result);
        $this->assertStringContainsString('offence', $result);
    }

    public function testLocaliseAllReturnsOriginal(): void
    {
        $text = 'customize your colors';
        // 'all' is not in GB or US lists, defaults to US (no change)
        $this->assertEquals($text, Locale::localise($text, 'all'));
    }
}
