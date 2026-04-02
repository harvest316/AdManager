<?php

namespace AdManager\Tests\Dashboard;

use AdManager\Dashboard\ConversionVerifier;
use AdManager\DB;
use PHPUnit\Framework\TestCase;

class ConversionVerifierTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('ADMANAGER_DB_PATH=:memory:');
        DB::reset();
        DB::init();

        $db = DB::get();
        $db->exec("INSERT INTO projects (id, name, display_name, website_url) VALUES (1, 'test', 'Test', 'https://test.com')");
        $db->exec("INSERT INTO projects (id, name, display_name, website_url) VALUES (2, 'nourl', 'No URL', '')");
    }

    protected function tearDown(): void
    {
        DB::reset();
        putenv('ADMANAGER_DB_PATH');
    }

    public function testVerifyReturnsErrorForMissingUrl(): void
    {
        $verifier = new ConversionVerifier();
        $result = $verifier->verify(2);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('no website URL', $result['error']);
    }

    public function testVerifyReturnsErrorForMissingProject(): void
    {
        $verifier = new ConversionVerifier();
        $result = $verifier->verify(999);

        $this->assertFalse($result['ok']);
    }

    public function testIsActionVerifiedLogic(): void
    {
        // Test the private isActionVerified via reflection
        $verifier = new ConversionVerifier();
        $method = new \ReflectionMethod($verifier, 'isActionVerified');

        // GA4 found → google/ga4 actions verified
        $report = ['ga4' => ['found' => true, 'measurement_ids' => ['G-TEST']]];
        $action = ['platform' => 'ga4', 'event_name' => 'purchase', 'id' => 1];
        $this->assertTrue($method->invoke($verifier, $action, $report));

        // GA4 not found → not verified
        $report = ['ga4' => ['found' => false]];
        $this->assertFalse($method->invoke($verifier, $action, $report));

        // Meta pixel found → meta actions verified
        $report = ['meta_pixel' => ['found' => true, 'pixel_ids' => ['123']]];
        $action = ['platform' => 'meta', 'event_name' => 'Lead', 'id' => 2];
        $this->assertTrue($method->invoke($verifier, $action, $report));

        // Meta pixel not found → not verified
        $report = ['meta_pixel' => ['found' => false]];
        $this->assertFalse($method->invoke($verifier, $action, $report));
    }

    public function testBuildVerificationNoteGA4(): void
    {
        $verifier = new ConversionVerifier();
        $method = new \ReflectionMethod($verifier, 'buildVerificationNote');

        $report = [
            'ga4' => ['found' => true, 'measurement_ids' => ['G-TEST123'], 'events' => ['page_view']],
            'gtm' => ['found' => true, 'container_ids' => ['GTM-ABC']],
            'google_ads' => ['found' => false],
            'conversion_linker' => false,
        ];
        $action = ['platform' => 'ga4', 'event_name' => 'purchase', 'id' => 1];

        $note = $method->invoke($verifier, $action, $report);
        $this->assertStringContainsString('GA4 found: G-TEST123', $note);
        $this->assertStringContainsString('GTM: GTM-ABC', $note);
        $this->assertStringContainsString('page_view', $note);
    }

    public function testBuildVerificationNoteMeta(): void
    {
        $verifier = new ConversionVerifier();
        $method = new \ReflectionMethod($verifier, 'buildVerificationNote');

        $report = ['meta_pixel' => ['found' => true, 'pixel_ids' => ['456'], 'events' => ['Lead', 'PageView']]];
        $action = ['platform' => 'meta', 'event_name' => 'Lead', 'id' => 2];

        $note = $method->invoke($verifier, $action, $report);
        $this->assertStringContainsString('Meta Pixel found: 456', $note);
        $this->assertStringContainsString('Lead', $note);
    }

    public function testBuildVerificationNoteGA4NotFound(): void
    {
        $verifier = new ConversionVerifier();
        $method = new \ReflectionMethod($verifier, 'buildVerificationNote');

        $report = ['ga4' => ['found' => false]];
        $action = ['platform' => 'ga4', 'event_name' => 'purchase', 'id' => 1];

        $note = $method->invoke($verifier, $action, $report);
        $this->assertStringContainsString('GA4 NOT found', $note);
    }

    public function testBuildVerificationNoteWithTriggerCheck(): void
    {
        $verifier = new ConversionVerifier();
        $method = new \ReflectionMethod($verifier, 'buildVerificationNote');

        $report = [
            'ga4' => ['found' => true, 'measurement_ids' => ['G-X']],
            'trigger_checks' => [
                5 => [
                    'action_name' => 'Purchase',
                    'trigger_url' => '/order-confirmation',
                    'events' => [['type' => 'ga4', 'event' => 'purchase']],
                ],
            ],
        ];
        $action = ['platform' => 'ga4', 'event_name' => 'purchase', 'id' => 5];

        $note = $method->invoke($verifier, $action, $report);
        $this->assertStringContainsString('/order-confirmation', $note);
        $this->assertStringContainsString('ga4:purchase', $note);
    }

    public function testGooglePlatformNeedsConversionLinkerWithoutAdsTag(): void
    {
        $verifier = new ConversionVerifier();
        $method = new \ReflectionMethod($verifier, 'isActionVerified');

        // GA4 found but no Google Ads tag and no conversion linker
        $report = ['ga4' => ['found' => true], 'google_ads' => ['found' => false], 'conversion_linker' => false];
        $action = ['platform' => 'google', 'event_name' => 'purchase', 'id' => 1];
        $this->assertFalse($method->invoke($verifier, $action, $report));

        // GA4 found, no Ads tag but conversion linker present
        $report['conversion_linker'] = true;
        $this->assertTrue($method->invoke($verifier, $action, $report));
    }
}
