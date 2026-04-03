<?php

declare(strict_types=1);

namespace AdManager\Tests\LinkedIn;

use AdManager\LinkedIn\Client;
use AdManager\LinkedIn\Reports;
use PHPUnit\Framework\TestCase;

/**
 * Tests for LinkedIn\Reports.
 *
 * Reports calls Client::get() against the adAnalytics endpoint. We verify:
 * - campaignInsights() calls the adAnalytics endpoint
 * - campaignInsights() passes q=analytics
 * - campaignInsights() passes the campaign URN in campaigns param
 * - campaignInsights() splits date parts correctly (year, month, day)
 * - campaignInsights() requests standard field set
 * - Normalisation: costInLocalCurrency decimal → cost_micros (× 1_000_000)
 * - Normalisation: impressions → int
 * - Normalisation: clicks → int
 * - Normalisation: externalWebsiteConversions → conversions int
 * - Normalisation: missing fields default to zero
 * - cost_micros rounds correctly for fractional currency
 * - Empty response returns empty array
 * - Multiple rows are all normalised
 * - campaign_urn is preserved in normalised output
 */
class ReportsTest extends TestCase
{
    private const FAKE_ACCOUNT_URN  = 'urn:li:sponsoredAccount:123456';
    private const FAKE_CAMPAIGN_URN = 'urn:li:sponsoredCampaign:987654';

    protected function tearDown(): void
    {
        Client::reset();
    }

    // -------------------------------------------------------------------------
    // campaignInsights() endpoint and params
    // -------------------------------------------------------------------------

    public function testCampaignInsightsCallsAdAnalyticsEndpoint(): void
    {
        $capture = new \stdClass();
        $capture->endpoint = null;

        $this->injectMockClient($this->buildCapturingGetMock($capture));

        $reports = new Reports();
        $reports->campaignInsights(self::FAKE_CAMPAIGN_URN, '2026-01-01', '2026-01-31');

        $this->assertSame('adAnalytics', $capture->endpoint);
    }

    public function testCampaignInsightsPassesQEqualsAnalytics(): void
    {
        $capture = new \stdClass();
        $capture->params = null;

        $this->injectMockClient($this->buildCapturingGetMock($capture));

        $reports = new Reports();
        $reports->campaignInsights(self::FAKE_CAMPAIGN_URN, '2026-01-01', '2026-01-31');

        $this->assertSame('analytics', $capture->params['q']);
    }

    public function testCampaignInsightsPassesCampaignUrnInListParam(): void
    {
        $capture = new \stdClass();
        $capture->params = null;

        $this->injectMockClient($this->buildCapturingGetMock($capture));

        $reports = new Reports();
        $reports->campaignInsights(self::FAKE_CAMPAIGN_URN, '2026-01-01', '2026-01-31');

        $this->assertStringContainsString(self::FAKE_CAMPAIGN_URN, $capture->params['campaigns']);
    }

    public function testCampaignInsightsSplitsStartDateIntoParts(): void
    {
        $capture = new \stdClass();
        $capture->params = null;

        $this->injectMockClient($this->buildCapturingGetMock($capture));

        $reports = new Reports();
        $reports->campaignInsights(self::FAKE_CAMPAIGN_URN, '2026-03-15', '2026-03-31');

        $this->assertSame(2026, $capture->params['dateRange.start.year']);
        $this->assertSame(3,    $capture->params['dateRange.start.month']);
        $this->assertSame(15,   $capture->params['dateRange.start.day']);
    }

    public function testCampaignInsightsSplitsEndDateIntoParts(): void
    {
        $capture = new \stdClass();
        $capture->params = null;

        $this->injectMockClient($this->buildCapturingGetMock($capture));

        $reports = new Reports();
        $reports->campaignInsights(self::FAKE_CAMPAIGN_URN, '2026-03-01', '2026-03-31');

        $this->assertSame(2026, $capture->params['dateRange.end.year']);
        $this->assertSame(3,    $capture->params['dateRange.end.month']);
        $this->assertSame(31,   $capture->params['dateRange.end.day']);
    }

    public function testCampaignInsightsRequestsStandardFields(): void
    {
        $capture = new \stdClass();
        $capture->params = null;

        $this->injectMockClient($this->buildCapturingGetMock($capture));

        $reports = new Reports();
        $reports->campaignInsights(self::FAKE_CAMPAIGN_URN, '2026-01-01', '2026-01-31');

        $this->assertStringContainsString('impressions', $capture->params['fields']);
        $this->assertStringContainsString('clicks', $capture->params['fields']);
        $this->assertStringContainsString('costInLocalCurrency', $capture->params['fields']);
        $this->assertStringContainsString('externalWebsiteConversions', $capture->params['fields']);
    }

    public function testCampaignInsightsReturnsEmptyArrayWhenNoElements(): void
    {
        $mock = $this->createMock(Client::class);
        $mock->method('adAccountUrn')->willReturn(self::FAKE_ACCOUNT_URN);
        $mock->method('get_api')->willReturn([]);
        $this->injectMockClient($mock);

        $reports = new Reports();
        $result  = $reports->campaignInsights(self::FAKE_CAMPAIGN_URN, '2026-01-01', '2026-01-31');

        $this->assertSame([], $result);
    }

    // -------------------------------------------------------------------------
    // Cost normalisation
    // -------------------------------------------------------------------------

    public function testNormalisationConvertsDecimalCostToMicros(): void
    {
        $row = ['costInLocalCurrency' => '9.87', 'impressions' => 0, 'clicks' => 0];

        $this->injectMockClient($this->buildGetMockReturning(['elements' => [$row]]));

        $reports = new Reports();
        $result  = $reports->campaignInsights(self::FAKE_CAMPAIGN_URN, '2026-01-01', '2026-01-31');

        $this->assertSame(9_870_000, $result[0]['cost_micros']);
    }

    public function testNormalisationConvertsExactDollarCostToMicros(): void
    {
        $row = ['costInLocalCurrency' => '10.00', 'impressions' => 0, 'clicks' => 0];

        $this->injectMockClient($this->buildGetMockReturning(['elements' => [$row]]));

        $reports = new Reports();
        $result  = $reports->campaignInsights(self::FAKE_CAMPAIGN_URN, '2026-01-01', '2026-01-31');

        $this->assertSame(10_000_000, $result[0]['cost_micros']);
    }

    public function testNormalisationRoundsFractionalMicrosCorrectly(): void
    {
        // $0.0001234 → 123 micros (rounded)
        $row = ['costInLocalCurrency' => '0.0001234'];

        $this->injectMockClient($this->buildGetMockReturning(['elements' => [$row]]));

        $reports = new Reports();
        $result  = $reports->campaignInsights(self::FAKE_CAMPAIGN_URN, '2026-01-01', '2026-01-31');

        $this->assertSame(123, $result[0]['cost_micros']);
    }

    public function testNormalisationDefaultsCostToZeroWhenMissing(): void
    {
        $row = ['impressions' => 1000, 'clicks' => 5];

        $this->injectMockClient($this->buildGetMockReturning(['elements' => [$row]]));

        $reports = new Reports();
        $result  = $reports->campaignInsights(self::FAKE_CAMPAIGN_URN, '2026-01-01', '2026-01-31');

        $this->assertSame(0, $result[0]['cost_micros']);
    }

    // -------------------------------------------------------------------------
    // Metric normalisation
    // -------------------------------------------------------------------------

    public function testNormalisationCastsImpressionsToInt(): void
    {
        $row = ['impressions' => 1234, 'costInLocalCurrency' => '0'];

        $this->injectMockClient($this->buildGetMockReturning(['elements' => [$row]]));

        $reports = new Reports();
        $result  = $reports->campaignInsights(self::FAKE_CAMPAIGN_URN, '2026-01-01', '2026-01-31');

        $this->assertSame(1234, $result[0]['impressions']);
        $this->assertIsInt($result[0]['impressions']);
    }

    public function testNormalisationCastsClicksToInt(): void
    {
        $row = ['clicks' => 42, 'costInLocalCurrency' => '0'];

        $this->injectMockClient($this->buildGetMockReturning(['elements' => [$row]]));

        $reports = new Reports();
        $result  = $reports->campaignInsights(self::FAKE_CAMPAIGN_URN, '2026-01-01', '2026-01-31');

        $this->assertSame(42, $result[0]['clicks']);
        $this->assertIsInt($result[0]['clicks']);
    }

    public function testNormalisationMapsExternalConversionsToConversions(): void
    {
        $row = [
            'costInLocalCurrency'          => '0',
            'externalWebsiteConversions'   => 7,
        ];

        $this->injectMockClient($this->buildGetMockReturning(['elements' => [$row]]));

        $reports = new Reports();
        $result  = $reports->campaignInsights(self::FAKE_CAMPAIGN_URN, '2026-01-01', '2026-01-31');

        $this->assertSame(7, $result[0]['conversions']);
    }

    public function testNormalisationDefaultsMissingFieldsToZero(): void
    {
        $row = [];

        $this->injectMockClient($this->buildGetMockReturning(['elements' => [$row]]));

        $reports = new Reports();
        $result  = $reports->campaignInsights(self::FAKE_CAMPAIGN_URN, '2026-01-01', '2026-01-31');

        $this->assertSame(0, $result[0]['impressions']);
        $this->assertSame(0, $result[0]['clicks']);
        $this->assertSame(0, $result[0]['cost_micros']);
        $this->assertSame(0, $result[0]['conversions']);
    }

    public function testNormalisedRowPreservesCampaignUrn(): void
    {
        $row = ['costInLocalCurrency' => '1.00'];

        $this->injectMockClient($this->buildGetMockReturning(['elements' => [$row]]));

        $reports = new Reports();
        $result  = $reports->campaignInsights(self::FAKE_CAMPAIGN_URN, '2026-01-01', '2026-01-31');

        $this->assertSame(self::FAKE_CAMPAIGN_URN, $result[0]['campaign_urn']);
    }

    public function testNormalisationHandlesMultipleRows(): void
    {
        $rows = [
            ['costInLocalCurrency' => '5.00', 'impressions' => 1000],
            ['costInLocalCurrency' => '3.50', 'impressions' => 500],
        ];

        $this->injectMockClient($this->buildGetMockReturning(['elements' => $rows]));

        $reports = new Reports();
        $result  = $reports->campaignInsights(self::FAKE_CAMPAIGN_URN, '2026-01-01', '2026-01-31');

        $this->assertCount(2, $result);
        $this->assertSame(5_000_000, $result[0]['cost_micros']);
        $this->assertSame(3_500_000, $result[1]['cost_micros']);
        $this->assertSame(1000, $result[0]['impressions']);
        $this->assertSame(500, $result[1]['impressions']);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildCapturingGetMock(\stdClass $capture): Client
    {
        $mock = $this->createMock(Client::class);
        $mock->method('adAccountUrn')->willReturn(self::FAKE_ACCOUNT_URN);
        $mock->method('get_api')
             ->willReturnCallback(function (string $endpoint, array $params) use ($capture): array {
                 $capture->endpoint = $endpoint;
                 $capture->params   = $params;
                 return ['elements' => []];
             });
        return $mock;
    }

    private function buildGetMockReturning(array $response): Client
    {
        $mock = $this->createMock(Client::class);
        $mock->method('adAccountUrn')->willReturn(self::FAKE_ACCOUNT_URN);
        $mock->method('get_api')->willReturn($response);
        return $mock;
    }

    private function injectMockClient(Client $client): void
    {
        $ref = new \ReflectionProperty(Client::class, 'instance');
        $ref->setAccessible(true);
        $ref->setValue(null, $client);
    }
}
