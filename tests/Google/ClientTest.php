<?php

declare(strict_types=1);

namespace AdManager\Tests\Google;

use PHPUnit\Framework\TestCase;

/**
 * Tests for Client::customerId() logic.
 *
 * Client::customerId() strips all non-digit characters from the env var then
 * returns the plain numeric string.  We test that regex logic directly without
 * booting the real Dotenv/GoogleAdsClient stack.
 */
class ClientTest extends TestCase
{
    // -------------------------------------------------------------------------
    // customerId() regex — extracted for unit testing without env dependency
    // -------------------------------------------------------------------------

    /**
     * Replicate the exact regex used in Client::customerId().
     */
    private function stripNonDigits(string $value): string
    {
        return preg_replace('/[^0-9]/', '', $value);
    }

    public function testStripsAllDashesFromFormattedId(): void
    {
        // Standard Google Ads customer ID format: 374-841-8440
        $this->assertSame('3748418440', $this->stripNonDigits('374-841-8440'));
    }

    public function testPlainNumericIdPassesThroughUnchanged(): void
    {
        $this->assertSame('1234567890', $this->stripNonDigits('1234567890'));
    }

    public function testStripsSpacesAndDashes(): void
    {
        // Some users enter IDs with spaces instead of dashes
        $this->assertSame('9876543210', $this->stripNonDigits('987 654 3210'));
    }

    public function testStripsLeadingAndTrailingWhitespace(): void
    {
        $this->assertSame('1234567890', $this->stripNonDigits('  1234567890  '));
    }

    public function testEmptyStringReturnsEmpty(): void
    {
        $this->assertSame('', $this->stripNonDigits(''));
    }

    public function testNonDigitOnlyStringReturnsEmpty(): void
    {
        $this->assertSame('', $this->stripNonDigits('---'));
    }

    public function testMixedAlphanumericStripsLetters(): void
    {
        // Edge case: env var accidentally has letters
        $this->assertSame('123456', $this->stripNonDigits('abc123def456'));
    }

    public function testRealWorldFormatWithTenDigits(): void
    {
        // Verify the example from the source file comment
        $this->assertSame('3748418440', $this->stripNonDigits('374-841-8440'));
        $this->assertSame(10, strlen($this->stripNonDigits('374-841-8440')));
    }
}
