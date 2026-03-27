<?php

declare(strict_types=1);

namespace AdManager\Tests\Meta;

use AdManager\Meta\Campaign;
use AdManager\Meta\Client;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Meta\Campaign.
 *
 * Campaign calls Client::post() / Client::get_api() to hit the Graph API.
 * We inject a mock Client via the singleton slot and verify:
 * - create() sends the correct endpoint and data shape
 * - status defaults to PAUSED
 * - special_ad_categories is JSON-encoded
 * - optional budget fields are included only when provided
 * - pause() and enable() POST the right status
 * - list() calls the correct endpoint with field list
 * - get() fetches the correct campaign ID endpoint
 */
class CampaignTest extends TestCase
{
    private const FAKE_AD_ACCOUNT_ID = 'act_111222333';
    private const FAKE_CAMPAIGN_ID   = '987654321';

    // -------------------------------------------------------------------------
    // Setup / Teardown
    // -------------------------------------------------------------------------

    protected function setUp(): void
    {
        $this->injectMockClient($this->buildPassthroughClientMock());
    }

    protected function tearDown(): void
    {
        Client::reset();
    }

    // -------------------------------------------------------------------------
    // create()
    // -------------------------------------------------------------------------

    public function testCreatePostsToCorrectEndpoint(): void
    {
        $capture = new \stdClass();
        $capture->endpoint = null;
        $capture->data     = null;

        $clientMock = $this->buildCapturingClientMock($capture);
        $this->injectMockClient($clientMock);

        $campaign = new Campaign();
        $campaign->create($this->baseCreateConfig());

        $this->assertSame(self::FAKE_AD_ACCOUNT_ID . '/campaigns', $capture->endpoint);
    }

    public function testCreateIncludesNameInPayload(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $this->injectMockClient($this->buildCapturingClientMock($capture));

        $campaign = new Campaign();
        $campaign->create(['name' => 'Audit&Fix — FB — AU'] + $this->baseCreateConfig());

        $this->assertSame('Audit&Fix — FB — AU', $capture->data['name']);
    }

    public function testCreateIncludesObjectiveInPayload(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $this->injectMockClient($this->buildCapturingClientMock($capture));

        $campaign = new Campaign();
        $campaign->create(['objective' => 'OUTCOME_SALES'] + $this->baseCreateConfig());

        $this->assertSame('OUTCOME_SALES', $capture->data['objective']);
    }

    public function testCreateDefaultsStatusToPaused(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $this->injectMockClient($this->buildCapturingClientMock($capture));

        $campaign = new Campaign();
        $campaign->create($this->baseCreateConfig()); // no 'status' key

        $this->assertSame('PAUSED', $capture->data['status']);
    }

    public function testCreateRespectsExplicitStatus(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $this->injectMockClient($this->buildCapturingClientMock($capture));

        $campaign = new Campaign();
        $campaign->create(['status' => 'ACTIVE'] + $this->baseCreateConfig());

        $this->assertSame('ACTIVE', $capture->data['status']);
    }

    public function testCreateJsonEncodesSpecialAdCategories(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $this->injectMockClient($this->buildCapturingClientMock($capture));

        $campaign = new Campaign();
        $campaign->create(['special_ad_categories' => ['HOUSING']] + $this->baseCreateConfig());

        $this->assertSame(json_encode(['HOUSING']), $capture->data['special_ad_categories']);
    }

    public function testCreateJsonEncodesEmptySpecialAdCategoriesByDefault(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $this->injectMockClient($this->buildCapturingClientMock($capture));

        $campaign = new Campaign();
        $campaign->create($this->baseCreateConfig()); // no special_ad_categories key

        $this->assertSame(json_encode([]), $capture->data['special_ad_categories']);
    }

    public function testCreateIncludesDailyBudgetWhenProvided(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $this->injectMockClient($this->buildCapturingClientMock($capture));

        $campaign = new Campaign();
        $campaign->create(['daily_budget' => 2000] + $this->baseCreateConfig());

        $this->assertArrayHasKey('daily_budget', $capture->data);
        $this->assertSame(2000, $capture->data['daily_budget']);
    }

    public function testCreateOmitsDailyBudgetWhenNotProvided(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $this->injectMockClient($this->buildCapturingClientMock($capture));

        $campaign = new Campaign();
        $campaign->create($this->baseCreateConfig()); // no daily_budget

        $this->assertArrayNotHasKey('daily_budget', $capture->data);
    }

    public function testCreateIncludesLifetimeBudgetWhenProvided(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $this->injectMockClient($this->buildCapturingClientMock($capture));

        $campaign = new Campaign();
        $campaign->create(['lifetime_budget' => 50000] + $this->baseCreateConfig());

        $this->assertArrayHasKey('lifetime_budget', $capture->data);
        $this->assertSame(50000, $capture->data['lifetime_budget']);
    }

    public function testCreateReturnsIdFromResponse(): void
    {
        $clientMock = $this->buildClientMockReturning(['id' => self::FAKE_CAMPAIGN_ID]);
        $this->injectMockClient($clientMock);

        $campaign = new Campaign();
        $result = $campaign->create($this->baseCreateConfig());

        $this->assertSame(self::FAKE_CAMPAIGN_ID, $result);
    }

    // -------------------------------------------------------------------------
    // pause() / enable()
    // -------------------------------------------------------------------------

    public function testPausePostsPausedStatusToCampaignId(): void
    {
        $capture = new \stdClass();
        $capture->endpoint = null;
        $capture->data     = null;

        $this->injectMockClient($this->buildCapturingClientMock($capture));

        $campaign = new Campaign();
        $campaign->pause(self::FAKE_CAMPAIGN_ID);

        $this->assertSame(self::FAKE_CAMPAIGN_ID, $capture->endpoint);
        $this->assertSame('PAUSED', $capture->data['status']);
    }

    public function testEnablePostsActiveStatusToCampaignId(): void
    {
        $capture = new \stdClass();
        $capture->endpoint = null;
        $capture->data     = null;

        $this->injectMockClient($this->buildCapturingClientMock($capture));

        $campaign = new Campaign();
        $campaign->enable(self::FAKE_CAMPAIGN_ID);

        $this->assertSame(self::FAKE_CAMPAIGN_ID, $capture->endpoint);
        $this->assertSame('ACTIVE', $capture->data['status']);
    }

    // -------------------------------------------------------------------------
    // list()
    // -------------------------------------------------------------------------

    public function testListCallsAccountCampaignsEndpoint(): void
    {
        $capture = new \stdClass();
        $capture->endpoint = null;
        $capture->params   = null;

        $clientMock = $this->buildCapturingGetApiMock($capture);
        $this->injectMockClient($clientMock);

        $campaign = new Campaign();
        $campaign->list();

        $this->assertSame(self::FAKE_AD_ACCOUNT_ID . '/campaigns', $capture->endpoint);
    }

    public function testListIncludesFieldsParam(): void
    {
        $capture = new \stdClass();
        $capture->params = null;

        $this->injectMockClient($this->buildCapturingGetApiMock($capture));

        $campaign = new Campaign();
        $campaign->list();

        $this->assertArrayHasKey('fields', $capture->params);
        $this->assertStringContainsString('name', $capture->params['fields']);
        $this->assertStringContainsString('status', $capture->params['fields']);
        $this->assertStringContainsString('objective', $capture->params['fields']);
    }

    public function testListReturnsDataArray(): void
    {
        $fakeData = [
            ['id' => '1', 'name' => 'Campaign One'],
            ['id' => '2', 'name' => 'Campaign Two'],
        ];

        $clientMock = $this->buildGetApiMockReturning(['data' => $fakeData]);
        $this->injectMockClient($clientMock);

        $campaign = new Campaign();
        $result = $campaign->list();

        $this->assertSame($fakeData, $result);
    }

    public function testListReturnsEmptyArrayWhenNoData(): void
    {
        $this->injectMockClient($this->buildGetApiMockReturning([]));

        $campaign = new Campaign();
        $result = $campaign->list();

        $this->assertSame([], $result);
    }

    // -------------------------------------------------------------------------
    // get()
    // -------------------------------------------------------------------------

    public function testGetCallsCampaignIdEndpoint(): void
    {
        $capture = new \stdClass();
        $capture->endpoint = null;

        $this->injectMockClient($this->buildCapturingGetApiMock($capture));

        $campaign = new Campaign();
        $campaign->get(self::FAKE_CAMPAIGN_ID);

        $this->assertSame(self::FAKE_CAMPAIGN_ID, $capture->endpoint);
    }

    public function testGetIncludesDetailFields(): void
    {
        $capture = new \stdClass();
        $capture->params = null;

        $this->injectMockClient($this->buildCapturingGetApiMock($capture));

        $campaign = new Campaign();
        $campaign->get(self::FAKE_CAMPAIGN_ID);

        $this->assertArrayHasKey('fields', $capture->params);
        $this->assertStringContainsString('buying_type', $capture->params['fields']);
        $this->assertStringContainsString('special_ad_categories', $capture->params['fields']);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function baseCreateConfig(): array
    {
        return [
            'name'      => 'Test Campaign',
            'objective' => 'OUTCOME_SALES',
        ];
    }

    /**
     * Build a Client mock that captures the endpoint and data passed to post().
     */
    private function buildCapturingClientMock(\stdClass $capture): Client
    {
        $mock = $this->createMock(Client::class);
        $mock->method('adAccountId')->willReturn(self::FAKE_AD_ACCOUNT_ID);
        $mock->method('post')
             ->willReturnCallback(function (string $endpoint, array $data) use ($capture): array {
                 $capture->endpoint = $endpoint;
                 $capture->data     = $data;
                 return ['id' => self::FAKE_CAMPAIGN_ID];
             });
        $mock->method('get_api')->willReturn(['data' => []]);
        return $mock;
    }

    /**
     * Build a Client mock that captures endpoint and params passed to get_api().
     */
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
        $mock->method('post')->willReturn(['id' => self::FAKE_CAMPAIGN_ID]);
        return $mock;
    }

    /**
     * Build a Client mock whose post() returns a fixed array.
     */
    private function buildClientMockReturning(array $postReturn): Client
    {
        $mock = $this->createMock(Client::class);
        $mock->method('adAccountId')->willReturn(self::FAKE_AD_ACCOUNT_ID);
        $mock->method('post')->willReturn($postReturn);
        $mock->method('get_api')->willReturn(['data' => []]);
        return $mock;
    }

    /**
     * Build a Client mock whose get_api() returns a fixed array.
     */
    private function buildGetApiMockReturning(array $getReturn): Client
    {
        $mock = $this->createMock(Client::class);
        $mock->method('adAccountId')->willReturn(self::FAKE_AD_ACCOUNT_ID);
        $mock->method('get_api')->willReturn($getReturn);
        $mock->method('post')->willReturn(['id' => self::FAKE_CAMPAIGN_ID]);
        return $mock;
    }

    /**
     * Build a passthrough mock (used in setUp as safe default).
     */
    private function buildPassthroughClientMock(): Client
    {
        $mock = $this->createMock(Client::class);
        $mock->method('adAccountId')->willReturn(self::FAKE_AD_ACCOUNT_ID);
        $mock->method('post')->willReturn(['id' => self::FAKE_CAMPAIGN_ID]);
        $mock->method('get_api')->willReturn(['data' => []]);
        return $mock;
    }

    private function injectMockClient(Client $client): void
    {
        $ref = new \ReflectionProperty(Client::class, 'instance');
        $ref->setAccessible(true);
        $ref->setValue(null, $client);
    }
}
