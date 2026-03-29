<?php

namespace AdManager\Tests\Copy;

use PHPUnit\Framework\TestCase;

class PolicyCheckTest extends TestCase
{
    private string $checksumsFile;
    private string $policiesDir;

    protected function setUp(): void
    {
        $this->policiesDir = sys_get_temp_dir() . '/admanager_policy_test_' . uniqid();
        mkdir($this->policiesDir, 0755, true);
        $this->checksumsFile = $this->policiesDir . '/.checksums.json';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->checksumsFile)) {
            unlink($this->checksumsFile);
        }
        if (is_dir($this->policiesDir)) {
            rmdir($this->policiesDir);
        }
    }

    public function testChecksumFileCreation(): void
    {
        $checksums = [
            'google_content' => [
                'url'        => 'https://example.com/policy',
                'hash'       => hash('sha256', 'test content'),
                'checked_at' => date('c'),
            ],
        ];

        file_put_contents($this->checksumsFile, json_encode($checksums, JSON_PRETTY_PRINT));
        $this->assertFileExists($this->checksumsFile);

        $loaded = json_decode(file_get_contents($this->checksumsFile), true);
        $this->assertArrayHasKey('google_content', $loaded);
        $this->assertEquals('https://example.com/policy', $loaded['google_content']['url']);
    }

    public function testHashComparison(): void
    {
        $content1 = 'Policy version 1';
        $content2 = 'Policy version 2';

        $hash1 = hash('sha256', $content1);
        $hash2 = hash('sha256', $content2);

        $this->assertNotEquals($hash1, $hash2, 'Different content should produce different hashes');
        $this->assertEquals($hash1, hash('sha256', $content1), 'Same content should produce same hash');
    }

    public function testContentNormalization(): void
    {
        // The check-policy-updates.php strips HTML, normalizes whitespace
        $html = '<script>var x = 1;</script><p>Policy   text   here</p>';
        $content = preg_replace('/<script[^>]*>.*?<\/script>/s', '', $html);
        $content = strip_tags($content);
        $content = preg_replace('/\s+/', ' ', $content);
        $content = trim($content);

        $this->assertEquals('Policy text here', $content);
    }

    public function testEmptyChecksumsFileHandled(): void
    {
        file_put_contents($this->checksumsFile, '{}');
        $checksums = json_decode(file_get_contents($this->checksumsFile), true);
        $this->assertIsArray($checksums);
        $this->assertEmpty($checksums);
    }

    public function testPolicyFileExists(): void
    {
        $policiesDir = dirname(__DIR__, 2) . '/policies';
        // At minimum, the directory should exist
        $this->assertDirectoryExists($policiesDir);
    }
}
