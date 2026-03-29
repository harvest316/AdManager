<?php

declare(strict_types=1);

namespace AdManager\Tests\Google;

use AdManager\Google\GA4;
use PHPUnit\Framework\TestCase;

/**
 * Tests for GA4 report building, token exchange, and response parsing.
 *
 * All HTTP calls are intercepted by overriding the protected helpers via
 * a testable subclass that accepts injected response fixtures.
 */
class GA4Test extends TestCase
{
    // -------------------------------------------------------------------------
    // Helpers — testable subclass
    // -------------------------------------------------------------------------

    /**
     * Returns a GA4 subclass where runReport() is replaced with a fixed fixture.
     * This bypasses all HTTP and OAuth logic.
     */
    private function makeGA4WithFixture(array $rows): GA4
    {
        return new class($rows) extends GA4 {
            private array $fixtureRows;

            public function __construct(array $rows)
            {
                // Bypass parent constructor (which requires env vars + .env file).
                // Initialise all typed parent properties to safe defaults.
                $this->propertyId   = 'test-property';
                $this->clientId     = 'test-client-id';
                $this->clientSecret = 'test-client-secret';
                $this->refreshToken = 'test-refresh-token';
                $this->fixtureRows  = $rows;
            }

            /** Expose parseRows for direct unit testing. */
            public function parseRowsPublic(array $decoded, array $dims, array $metrics): array
            {
                return $this->parseRows($decoded, $dims, $metrics);
            }

            /** Override runReport to return the fixture instead of making HTTP calls. */
            protected function runReport(array $dimensions, array $metrics, string $startDate, string $endDate): array
            {
                return $this->fixtureRows;
            }
        };
    }

    /**
     * Build a GA4 API response shape as returned by analyticsdata.googleapis.com.
     */
    private function buildApiResponse(array $dimNames, array $metricNames, array $rows): array
    {
        $dimensionHeaders = array_map(fn($d) => ['name' => $d], $dimNames);
        $metricHeaders    = array_map(fn($m) => ['name' => $m, 'type' => 'TYPE_INTEGER'], $metricNames);

        $apiRows = [];
        foreach ($rows as $row) {
            $dimValues    = [];
            $metricValues = [];
            foreach ($dimNames as $d) {
                $dimValues[] = ['value' => (string) ($row[$d] ?? '')];
            }
            foreach ($metricNames as $m) {
                $metricValues[] = ['value' => (string) ($row[$m] ?? '0')];
            }
            $apiRows[] = [
                'dimensionValues' => $dimValues,
                'metricValues'    => $metricValues,
            ];
        }

        return [
            'dimensionHeaders' => $dimensionHeaders,
            'metricHeaders'    => $metricHeaders,
            'rows'             => $apiRows,
        ];
    }

    // -------------------------------------------------------------------------
    // parseRows — direct unit tests (no HTTP)
    // -------------------------------------------------------------------------

    public function testParseRowsMapsKeysCorrectly(): void
    {
        $ga4 = $this->makeGA4WithFixture([]);

        $apiResponse = $this->buildApiResponse(
            ['landingPage', 'sessionSource'],
            ['sessions', 'bounceRate'],
            [
                ['landingPage' => '/home', 'sessionSource' => 'google', 'sessions' => '100', 'bounceRate' => '0.45'],
                ['landingPage' => '/about', 'sessionSource' => 'direct', 'sessions' => '50', 'bounceRate' => '0.30'],
            ]
        );

        $rows = $ga4->parseRowsPublic(
            $apiResponse,
            ['landingPage', 'sessionSource'],
            ['sessions', 'bounceRate']
        );

        $this->assertCount(2, $rows);
        $this->assertSame('/home', $rows[0]['landingPage']);
        $this->assertSame('google', $rows[0]['sessionSource']);
        $this->assertSame('100', $rows[0]['sessions']);
        $this->assertSame('0.45', $rows[0]['bounceRate']);
    }

    public function testParseRowsReturnsEmptyWhenNoRows(): void
    {
        $ga4 = $this->makeGA4WithFixture([]);

        $apiResponse = $this->buildApiResponse(['landingPage'], ['sessions'], []);

        $rows = $ga4->parseRowsPublic($apiResponse, ['landingPage'], ['sessions']);

        $this->assertSame([], $rows);
    }

    public function testParseRowsHandlesMissingOptionalFields(): void
    {
        $ga4 = $this->makeGA4WithFixture([]);

        // Row has no dimensionValues/metricValues arrays at all — should not crash
        $apiResponse = [
            'dimensionHeaders' => [['name' => 'landingPage']],
            'metricHeaders'    => [['name' => 'sessions', 'type' => 'TYPE_INTEGER']],
            'rows'             => [
                ['dimensionValues' => [], 'metricValues' => []],
            ],
        ];

        $rows = $ga4->parseRowsPublic($apiResponse, ['landingPage'], ['sessions']);

        $this->assertCount(1, $rows);
    }

    // -------------------------------------------------------------------------
    // landingPagePerformance — fixture-driven
    // -------------------------------------------------------------------------

    public function testLandingPagePerformanceReturnsRows(): void
    {
        $fixture = [
            [
                'landingPage'           => '/pricing',
                'sessionSource'         => 'google',
                'sessionMedium'         => 'cpc',
                'sessionCampaignName'   => 'Brand',
                'sessions'              => '200',
                'bounceRate'            => '0.55',
                'averageSessionDuration'=> '120',
                'conversions'           => '15',
                'purchaseRevenue'       => '3000',
            ],
        ];

        $ga4  = $this->makeGA4WithFixture($fixture);
        $rows = $ga4->landingPagePerformance('2026-03-01', '2026-03-28');

        $this->assertCount(1, $rows);
        $this->assertSame('/pricing', $rows[0]['landingPage']);
        $this->assertSame('Brand', $rows[0]['sessionCampaignName']);
        $this->assertSame('15', $rows[0]['conversions']);
    }

    public function testLandingPagePerformanceReturnsEmptyWhenNoData(): void
    {
        $ga4  = $this->makeGA4WithFixture([]);
        $rows = $ga4->landingPagePerformance('2026-03-01', '2026-03-07');

        $this->assertSame([], $rows);
    }

    // -------------------------------------------------------------------------
    // sourceConversionRate — fixture-driven
    // -------------------------------------------------------------------------

    public function testSourceConversionRateAddsConversionRateField(): void
    {
        $fixture = [
            [
                'sessionSource'       => 'google',
                'sessionMedium'       => 'cpc',
                'sessionCampaignName' => 'Brand',
                'sessions'            => '100',
                'conversions'         => '10',
                'purchaseRevenue'     => '2000',
            ],
        ];

        $ga4  = $this->makeGA4WithFixture($fixture);
        $rows = $ga4->sourceConversionRate('2026-03-01', '2026-03-28');

        $this->assertCount(1, $rows);
        $this->assertArrayHasKey('conversion_rate', $rows[0]);
        $this->assertEqualsWithDelta(0.1, (float) $rows[0]['conversion_rate'], 0.0001);
    }

    public function testSourceConversionRateIsZeroWhenNoSessions(): void
    {
        $fixture = [
            [
                'sessionSource'       => 'organic',
                'sessionMedium'       => 'organic',
                'sessionCampaignName' => '(not set)',
                'sessions'            => '0',
                'conversions'         => '0',
                'purchaseRevenue'     => '0',
            ],
        ];

        $ga4  = $this->makeGA4WithFixture($fixture);
        $rows = $ga4->sourceConversionRate('2026-03-01', '2026-03-07');

        $this->assertSame(0.0, $rows[0]['conversion_rate']);
    }

    public function testSourceConversionRateRoundsToSixDecimals(): void
    {
        $fixture = [
            [
                'sessionSource'       => 'google',
                'sessionMedium'       => 'cpc',
                'sessionCampaignName' => 'Exact',
                'sessions'            => '3',
                'conversions'         => '1',
                'purchaseRevenue'     => '50',
            ],
        ];

        $ga4  = $this->makeGA4WithFixture($fixture);
        $rows = $ga4->sourceConversionRate('2026-03-01', '2026-03-07');

        // 1/3 = 0.333333
        $this->assertEqualsWithDelta(0.333333, $rows[0]['conversion_rate'], 0.000001);
    }

    // -------------------------------------------------------------------------
    // conversionValidation — fixture-driven
    // -------------------------------------------------------------------------

    public function testConversionValidationReturnsTotals(): void
    {
        $fixture = [
            ['conversions' => '40', 'purchaseRevenue' => '8000'],
            ['conversions' => '10', 'purchaseRevenue' => '2000'],
        ];

        $ga4    = $this->makeGA4WithFixture($fixture);
        $result = $ga4->conversionValidation('2026-03-01', '2026-03-28');

        $this->assertArrayHasKey('total_conversions', $result);
        $this->assertArrayHasKey('total_revenue', $result);
        $this->assertArrayHasKey('start_date', $result);
        $this->assertArrayHasKey('end_date', $result);

        $this->assertEqualsWithDelta(50.0, $result['total_conversions'], 0.001);
        $this->assertEqualsWithDelta(10000.0, $result['total_revenue'], 0.01);
    }

    public function testConversionValidationReturnsZerosWhenNoData(): void
    {
        $ga4    = $this->makeGA4WithFixture([]);
        $result = $ga4->conversionValidation('2026-03-01', '2026-03-07');

        $this->assertSame(0.0, $result['total_conversions']);
        $this->assertSame(0.0, $result['total_revenue']);
    }

    public function testConversionValidationPreservesDateRange(): void
    {
        $ga4    = $this->makeGA4WithFixture([]);
        $result = $ga4->conversionValidation('2026-02-01', '2026-02-28');

        $this->assertSame('2026-02-01', $result['start_date']);
        $this->assertSame('2026-02-28', $result['end_date']);
    }

    // -------------------------------------------------------------------------
    // getAccessToken — error handling (requires a subclass that exposes it)
    // -------------------------------------------------------------------------

    public function testGetAccessTokenReturnsCachedTokenBeforeExpiry(): void
    {
        // Subclass that pre-seeds a cached token so getAccessToken returns it without HTTP
        $ga4 = new class extends GA4 {
            public function __construct()
            {
                $this->propertyId   = 'test-prop';
                $this->clientId     = 'fake-id';
                $this->clientSecret = 'fake-secret';
                $this->refreshToken = 'fake-refresh';
                // Pre-seed a valid cached token
                $this->accessToken  = 'cached-access-token';
                $this->tokenExpiry  = time() + 3600;
            }

            public function getAccessTokenPublic(): string
            {
                return $this->getAccessToken();
            }
        };

        $token = $ga4->getAccessTokenPublic();
        $this->assertSame('cached-access-token', $token);
    }

    public function testGetAccessTokenMethodIsCallable(): void
    {
        // Verify the method exists and can be overridden by subclasses
        $ga4 = new class extends GA4 {
            public function __construct()
            {
                $this->propertyId   = 'test-prop';
                $this->clientId     = 'fake-id';
                $this->clientSecret = 'fake-secret';
                $this->refreshToken = 'fake-refresh';
                $this->accessToken  = 'my-token';
                $this->tokenExpiry  = time() + 3600;
            }

            public function getAccessTokenPublic(): string
            {
                return $this->getAccessToken();
            }
        };

        $this->assertTrue(method_exists($ga4, 'getAccessTokenPublic'));
        $this->assertSame('my-token', $ga4->getAccessTokenPublic());
    }
}
