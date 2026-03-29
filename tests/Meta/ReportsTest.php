<?php

declare(strict_types=1);

namespace AdManager\Tests\Meta;

use AdManager\Meta\Client;
use AdManager\Meta\Reports;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Meta\Reports.
 *
 * Reports wraps Client::get_api() to hit the Graph API insights endpoint.
 * We inject a mock Client via the singleton slot and verify:
 * - campaignInsights() uses correct endpoint path: {campaignId}/insights
 * - adSetInsights() uses correct endpoint path: {adSetId}/insights
 * - adInsights() uses correct endpoint path: {adId}/insights
 * - accountInsights() uses account-level insights endpoint
 * - allCampaignInsights() uses account-level insights with level=campaign
 * - Default date range is 'last_7d'
 * - Custom date range is passed as date_preset
 * - Default fields include standard metrics (impressions, clicks, spend, etc.)
 * - Custom fields override the defaults
 * - allCampaignInsights() always merges campaign_id and campaign_name into fields
 * - Return value is the 'data' key from response
 * - Empty data key returns []
 */
class ReportsTest extends TestCase
{
    private const FAKE_AD_ACCOUNT_ID = 'act_111222333';
    private const FAKE_CAMPAIGN_ID   = '111000111';
    private const FAKE_AD_SET_ID     = '222000222';
    private const FAKE_AD_ID         = '333000333';

    protected function tearDown(): void
    {
        Client::reset();
    }

    // -------------------------------------------------------------------------
    // campaignInsights()
    // -------------------------------------------------------------------------

    public function testCampaignInsightsUsesCorrectEndpoint(): void
    {
        $capture = new \stdClass();
        $capture->endpoint = null;

        $this->injectMockClient($this->buildCapturingGetApiMock($capture));

        $reports = new Reports();
        $reports->campaignInsights(self::FAKE_CAMPAIGN_ID);

        $this->assertSame(self::FAKE_CAMPAIGN_ID . '/insights', $capture->endpoint);
    }

    public function testCampaignInsightsUsesDefaultDateRange(): void
    {
        $capture = new \stdClass();
        $capture->params = null;

        $this->injectMockClient($this->buildCapturingGetApiMock($capture));

        $reports = new Reports();
        $reports->campaignInsights(self::FAKE_CAMPAIGN_ID);

        $this->assertSame('last_7d', $capture->params['date_preset']);
    }

    public function testCampaignInsightsUsesCustomDateRange(): void
    {
        $capture = new \stdClass();
        $capture->params = null;

        $this->injectMockClient($this->buildCapturingGetApiMock($capture));

        $reports = new Reports();
        $reports->campaignInsights(self::FAKE_CAMPAIGN_ID, 'last_30d');

        $this->assertSame('last_30d', $capture->params['date_preset']);
    }

    public function testCampaignInsightsDefaultFieldsIncludeStandardMetrics(): void
    {
        $capture = new \stdClass();
        $capture->params = null;

        $this->injectMockClient($this->buildCapturingGetApiMock($capture));

        $reports = new Reports();
        $reports->campaignInsights(self::FAKE_CAMPAIGN_ID);

        $fields = $capture->params['fields'];
        $this->assertStringContainsString('impressions', $fields);
        $this->assertStringContainsString('clicks', $fields);
        $this->assertStringContainsString('spend', $fields);
        $this->assertStringContainsString('ctr', $fields);
        $this->assertStringContainsString('cpc', $fields);
        $this->assertStringContainsString('cpm', $fields);
        $this->assertStringContainsString('actions', $fields);
        $this->assertStringContainsString('cost_per_action_type', $fields);
    }

    public function testCampaignInsightsDefaultFieldsIncludeFrequencyAndReach(): void
    {
        $capture = new \stdClass();
        $capture->params = null;

        $this->injectMockClient($this->buildCapturingGetApiMock($capture));

        $reports = new Reports();
        $reports->campaignInsights(self::FAKE_CAMPAIGN_ID);

        $fields = $capture->params['fields'];
        $this->assertStringContainsString('frequency', $fields);
        $this->assertStringContainsString('reach', $fields);
        $this->assertStringContainsString('unique_clicks', $fields);
        $this->assertStringContainsString('unique_ctr', $fields);
        $this->assertStringContainsString('cost_per_unique_click', $fields);
    }

    public function testCampaignInsightsCustomFieldsOverrideDefaults(): void
    {
        $capture = new \stdClass();
        $capture->params = null;

        $this->injectMockClient($this->buildCapturingGetApiMock($capture));

        $reports = new Reports();
        $reports->campaignInsights(self::FAKE_CAMPAIGN_ID, 'last_7d', ['impressions', 'spend']);

        $this->assertSame('impressions,spend', $capture->params['fields']);
    }

    public function testCampaignInsightsReturnsDataArray(): void
    {
        $fakeData = [['impressions' => '1000', 'clicks' => '50']];

        $this->injectMockClient($this->buildGetApiMockReturning(['data' => $fakeData]));

        $reports = new Reports();
        $result = $reports->campaignInsights(self::FAKE_CAMPAIGN_ID);

        $this->assertSame($fakeData, $result);
    }

    public function testCampaignInsightsReturnsEmptyArrayWhenNoData(): void
    {
        $this->injectMockClient($this->buildGetApiMockReturning([]));

        $reports = new Reports();
        $result = $reports->campaignInsights(self::FAKE_CAMPAIGN_ID);

        $this->assertSame([], $result);
    }

    // -------------------------------------------------------------------------
    // adSetInsights()
    // -------------------------------------------------------------------------

    public function testAdSetInsightsUsesCorrectEndpoint(): void
    {
        $capture = new \stdClass();
        $capture->endpoint = null;

        $this->injectMockClient($this->buildCapturingGetApiMock($capture));

        $reports = new Reports();
        $reports->adSetInsights(self::FAKE_AD_SET_ID);

        $this->assertSame(self::FAKE_AD_SET_ID . '/insights', $capture->endpoint);
    }

    public function testAdSetInsightsUsesDefaultDateRange(): void
    {
        $capture = new \stdClass();
        $capture->params = null;

        $this->injectMockClient($this->buildCapturingGetApiMock($capture));

        $reports = new Reports();
        $reports->adSetInsights(self::FAKE_AD_SET_ID);

        $this->assertSame('last_7d', $capture->params['date_preset']);
    }

    public function testAdSetInsightsCustomDateRange(): void
    {
        $capture = new \stdClass();
        $capture->params = null;

        $this->injectMockClient($this->buildCapturingGetApiMock($capture));

        $reports = new Reports();
        $reports->adSetInsights(self::FAKE_AD_SET_ID, 'this_month');

        $this->assertSame('this_month', $capture->params['date_preset']);
    }

    public function testAdSetInsightsReturnsDataArray(): void
    {
        $fakeData = [['impressions' => '500']];

        $this->injectMockClient($this->buildGetApiMockReturning(['data' => $fakeData]));

        $reports = new Reports();
        $result = $reports->adSetInsights(self::FAKE_AD_SET_ID);

        $this->assertSame($fakeData, $result);
    }

    // -------------------------------------------------------------------------
    // adInsights()
    // -------------------------------------------------------------------------

    public function testAdInsightsUsesCorrectEndpoint(): void
    {
        $capture = new \stdClass();
        $capture->endpoint = null;

        $this->injectMockClient($this->buildCapturingGetApiMock($capture));

        $reports = new Reports();
        $reports->adInsights(self::FAKE_AD_ID);

        $this->assertSame(self::FAKE_AD_ID . '/insights', $capture->endpoint);
    }

    public function testAdInsightsUsesDefaultDateRange(): void
    {
        $capture = new \stdClass();
        $capture->params = null;

        $this->injectMockClient($this->buildCapturingGetApiMock($capture));

        $reports = new Reports();
        $reports->adInsights(self::FAKE_AD_ID);

        $this->assertSame('last_7d', $capture->params['date_preset']);
    }

    public function testAdInsightsCustomDateRange(): void
    {
        $capture = new \stdClass();
        $capture->params = null;

        $this->injectMockClient($this->buildCapturingGetApiMock($capture));

        $reports = new Reports();
        $reports->adInsights(self::FAKE_AD_ID, 'last_14d');

        $this->assertSame('last_14d', $capture->params['date_preset']);
    }

    public function testAdInsightsCustomFieldsAreUsed(): void
    {
        $capture = new \stdClass();
        $capture->params = null;

        $this->injectMockClient($this->buildCapturingGetApiMock($capture));

        $reports = new Reports();
        $reports->adInsights(self::FAKE_AD_ID, 'last_7d', ['spend', 'clicks']);

        $this->assertSame('spend,clicks', $capture->params['fields']);
    }

    // -------------------------------------------------------------------------
    // accountInsights()
    // -------------------------------------------------------------------------

    public function testAccountInsightsUsesAccountLevelEndpoint(): void
    {
        $capture = new \stdClass();
        $capture->endpoint = null;

        $this->injectMockClient($this->buildCapturingGetApiMock($capture));

        $reports = new Reports();
        $reports->accountInsights();

        $this->assertSame(self::FAKE_AD_ACCOUNT_ID . '/insights', $capture->endpoint);
    }

    public function testAccountInsightsUsesDefaultDateRange(): void
    {
        $capture = new \stdClass();
        $capture->params = null;

        $this->injectMockClient($this->buildCapturingGetApiMock($capture));

        $reports = new Reports();
        $reports->accountInsights();

        $this->assertSame('last_7d', $capture->params['date_preset']);
    }

    public function testAccountInsightsCustomDateRange(): void
    {
        $capture = new \stdClass();
        $capture->params = null;

        $this->injectMockClient($this->buildCapturingGetApiMock($capture));

        $reports = new Reports();
        $reports->accountInsights('last_month');

        $this->assertSame('last_month', $capture->params['date_preset']);
    }

    public function testAccountInsightsReturnsDataArray(): void
    {
        $fakeData = [['spend' => '150.00', 'impressions' => '10000']];

        $this->injectMockClient($this->buildGetApiMockReturning(['data' => $fakeData]));

        $reports = new Reports();
        $result = $reports->accountInsights();

        $this->assertSame($fakeData, $result);
    }

    // -------------------------------------------------------------------------
    // allCampaignInsights()
    // -------------------------------------------------------------------------

    public function testAllCampaignInsightsUsesAccountInsightsEndpoint(): void
    {
        $capture = new \stdClass();
        $capture->endpoint = null;

        $this->injectMockClient($this->buildCapturingGetApiMock($capture));

        $reports = new Reports();
        $reports->allCampaignInsights();

        $this->assertSame(self::FAKE_AD_ACCOUNT_ID . '/insights', $capture->endpoint);
    }

    public function testAllCampaignInsightsSetsLevelToCampaign(): void
    {
        $capture = new \stdClass();
        $capture->params = null;

        $this->injectMockClient($this->buildCapturingGetApiMock($capture));

        $reports = new Reports();
        $reports->allCampaignInsights();

        $this->assertArrayHasKey('level', $capture->params);
        $this->assertSame('campaign', $capture->params['level']);
    }

    public function testAllCampaignInsightsAlwaysIncludesCampaignIdAndName(): void
    {
        $capture = new \stdClass();
        $capture->params = null;

        $this->injectMockClient($this->buildCapturingGetApiMock($capture));

        $reports = new Reports();
        $reports->allCampaignInsights();

        $fields = $capture->params['fields'];
        $this->assertStringContainsString('campaign_id', $fields);
        $this->assertStringContainsString('campaign_name', $fields);
    }

    public function testAllCampaignInsightsMergesCampaignFieldsWithCustomFields(): void
    {
        $capture = new \stdClass();
        $capture->params = null;

        $this->injectMockClient($this->buildCapturingGetApiMock($capture));

        $reports = new Reports();
        $reports->allCampaignInsights('last_7d', ['spend']);

        $fieldsArray = explode(',', $capture->params['fields']);
        $this->assertContains('campaign_id', $fieldsArray);
        $this->assertContains('campaign_name', $fieldsArray);
        $this->assertContains('spend', $fieldsArray);
    }

    public function testAllCampaignInsightsDeduplicatesFields(): void
    {
        $capture = new \stdClass();
        $capture->params = null;

        $this->injectMockClient($this->buildCapturingGetApiMock($capture));

        $reports = new Reports();
        // Pass campaign_id explicitly to trigger dedup logic
        $reports->allCampaignInsights('last_7d', ['campaign_id', 'spend']);

        $fieldsArray = explode(',', $capture->params['fields']);
        $campaignIdCount = count(array_filter($fieldsArray, fn($f) => $f === 'campaign_id'));
        $this->assertSame(1, $campaignIdCount);
    }

    public function testAllCampaignInsightsUsesDefaultDateRange(): void
    {
        $capture = new \stdClass();
        $capture->params = null;

        $this->injectMockClient($this->buildCapturingGetApiMock($capture));

        $reports = new Reports();
        $reports->allCampaignInsights();

        $this->assertSame('last_7d', $capture->params['date_preset']);
    }

    public function testAllCampaignInsightsCustomDateRange(): void
    {
        $capture = new \stdClass();
        $capture->params = null;

        $this->injectMockClient($this->buildCapturingGetApiMock($capture));

        $reports = new Reports();
        $reports->allCampaignInsights('last_quarter');

        $this->assertSame('last_quarter', $capture->params['date_preset']);
    }

    public function testAllCampaignInsightsReturnsDataArray(): void
    {
        $fakeData = [
            ['campaign_id' => '1', 'campaign_name' => 'Campaign One', 'spend' => '50'],
            ['campaign_id' => '2', 'campaign_name' => 'Campaign Two', 'spend' => '100'],
        ];

        $this->injectMockClient($this->buildGetApiMockReturning(['data' => $fakeData]));

        $reports = new Reports();
        $result = $reports->allCampaignInsights();

        $this->assertSame($fakeData, $result);
    }

    public function testAllCampaignInsightsReturnsEmptyArrayWhenNoData(): void
    {
        $this->injectMockClient($this->buildGetApiMockReturning([]));

        $reports = new Reports();
        $result = $reports->allCampaignInsights();

        $this->assertSame([], $result);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildCapturingGetApiMock(\stdClass $capture): Client
    {
        $mock = $this->createMock(Client::class);
        $mock->method('adAccountId')->willReturn(self::FAKE_AD_ACCOUNT_ID);
        $mock->method('get_api')
             ->willReturnCallback(function (string $endpoint, array $params) use ($capture): array {
                 $capture->endpoint = $endpoint;
                 $capture->params   = $params;
                 return ['data' => []];
             });
        return $mock;
    }

    private function buildGetApiMockReturning(array $response): Client
    {
        $mock = $this->createMock(Client::class);
        $mock->method('adAccountId')->willReturn(self::FAKE_AD_ACCOUNT_ID);
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
