<?php

declare(strict_types=1);

namespace AdManager\Tests\TikTok;

use AdManager\TikTok\Campaign;
use AdManager\TikTok\Client;
use PHPUnit\Framework\TestCase;

/**
 * Tests for TikTok\Campaign.
 *
 * Campaign calls Client::post() / Client::get() to hit the TikTok API.
 * We inject a mock Client via the singleton slot and verify:
 * - create() sends the correct endpoint and data shape
 * - advertiser_id is always included
 * - budget_mode defaults to BUDGET_MODE_DAY
 * - operation_status defaults to DISABLE (paused)
 * - pause() posts DISABLE to the status endpoint
 * - enable() posts ENABLE to the status endpoint
 * - list() calls campaign/get/ with advertiser_id
 * - get() passes filtering with the campaign ID
 */
class CampaignTest extends TestCase
{
    private const FAKE_ADVERTISER_ID = 'adv_111222333';
    private const FAKE_CAMPAIGN_ID   = '987654321';

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

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        $campaign = new Campaign();
        $campaign->create($this->baseCreateConfig());

        $this->assertSame('campaign/create/', $capture->endpoint);
    }

    public function testCreateIncludesAdvertiserId(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        $campaign = new Campaign();
        $campaign->create($this->baseCreateConfig());

        $this->assertSame(self::FAKE_ADVERTISER_ID, $capture->data['advertiser_id']);
    }

    public function testCreateIncludesCampaignName(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        $campaign = new Campaign();
        $campaign->create(['name' => 'MyBrand — TT — AU'] + $this->baseCreateConfig());

        $this->assertSame('MyBrand — TT — AU', $capture->data['campaign_name']);
    }

    public function testCreateIncludesObjectiveType(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        $campaign = new Campaign();
        $campaign->create(['objective_type' => 'TRAFFIC'] + $this->baseCreateConfig());

        $this->assertSame('TRAFFIC', $capture->data['objective_type']);
    }

    public function testCreateDefaultsBudgetModeToDayBudget(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        $campaign = new Campaign();
        $campaign->create($this->baseCreateConfig()); // no budget_mode key

        $this->assertSame('BUDGET_MODE_DAY', $capture->data['budget_mode']);
    }

    public function testCreateRespectsExplicitBudgetMode(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        $campaign = new Campaign();
        $campaign->create(['budget_mode' => 'BUDGET_MODE_TOTAL'] + $this->baseCreateConfig());

        $this->assertSame('BUDGET_MODE_TOTAL', $capture->data['budget_mode']);
    }

    public function testCreateDefaultsOperationStatusToDisable(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        $campaign = new Campaign();
        $campaign->create($this->baseCreateConfig()); // no operation_status key

        $this->assertSame('DISABLE', $capture->data['operation_status']);
    }

    public function testCreateReturnsIdFromResponse(): void
    {
        $mock = $this->createMock(Client::class);
        $mock->method('advertiserId')->willReturn(self::FAKE_ADVERTISER_ID);
        $mock->method('post')->willReturn(['campaign_id' => self::FAKE_CAMPAIGN_ID]);
        $mock->method('get_api')->willReturn([]);
        $this->injectMockClient($mock);

        $campaign = new Campaign();
        $result = $campaign->create($this->baseCreateConfig());

        $this->assertSame(self::FAKE_CAMPAIGN_ID, $result);
    }

    // -------------------------------------------------------------------------
    // pause() / enable()
    // -------------------------------------------------------------------------

    public function testPausePostsToStatusEndpointWithDisable(): void
    {
        $capture = new \stdClass();
        $capture->endpoint = null;
        $capture->data     = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        $campaign = new Campaign();
        $campaign->pause(self::FAKE_CAMPAIGN_ID);

        $this->assertSame('campaign/update/status/', $capture->endpoint);
        $this->assertSame('DISABLE', $capture->data['operation_status']);
        $this->assertContains(self::FAKE_CAMPAIGN_ID, $capture->data['campaign_ids']);
    }

    public function testEnablePostsToStatusEndpointWithEnable(): void
    {
        $capture = new \stdClass();
        $capture->endpoint = null;
        $capture->data     = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        $campaign = new Campaign();
        $campaign->enable(self::FAKE_CAMPAIGN_ID);

        $this->assertSame('campaign/update/status/', $capture->endpoint);
        $this->assertSame('ENABLE', $capture->data['operation_status']);
    }

    // -------------------------------------------------------------------------
    // list()
    // -------------------------------------------------------------------------

    public function testListCallsCampaignGetEndpoint(): void
    {
        $capture = new \stdClass();
        $capture->endpoint = null;
        $capture->params   = null;

        $this->injectMockClient($this->buildCapturingGetMock($capture));

        $campaign = new Campaign();
        $campaign->list();

        $this->assertSame('campaign/get/', $capture->endpoint);
    }

    public function testListIncludesAdvertiserId(): void
    {
        $capture = new \stdClass();
        $capture->params = null;

        $this->injectMockClient($this->buildCapturingGetMock($capture));

        $campaign = new Campaign();
        $campaign->list();

        $this->assertSame(self::FAKE_ADVERTISER_ID, $capture->params['advertiser_id']);
    }

    public function testListReturnsListArray(): void
    {
        $fakeData = [
            ['campaign_id' => '1', 'campaign_name' => 'Campaign One'],
            ['campaign_id' => '2', 'campaign_name' => 'Campaign Two'],
        ];

        $mock = $this->createMock(Client::class);
        $mock->method('advertiserId')->willReturn(self::FAKE_ADVERTISER_ID);
        $mock->method('get_api')->willReturn(['list' => $fakeData]);
        $mock->method('post')->willReturn([]);
        $this->injectMockClient($mock);

        $campaign = new Campaign();
        $result = $campaign->list();

        $this->assertSame($fakeData, $result);
    }

    public function testListReturnsEmptyArrayWhenNoList(): void
    {
        $mock = $this->createMock(Client::class);
        $mock->method('advertiserId')->willReturn(self::FAKE_ADVERTISER_ID);
        $mock->method('get_api')->willReturn([]);
        $mock->method('post')->willReturn([]);
        $this->injectMockClient($mock);

        $campaign = new Campaign();
        $result = $campaign->list();

        $this->assertSame([], $result);
    }

    // -------------------------------------------------------------------------
    // get()
    // -------------------------------------------------------------------------

    public function testGetCallsCampaignGetWithFiltering(): void
    {
        $capture = new \stdClass();
        $capture->endpoint = null;
        $capture->params   = null;

        $this->injectMockClient($this->buildCapturingGetMock($capture));

        $campaign = new Campaign();
        $campaign->get(self::FAKE_CAMPAIGN_ID);

        $this->assertSame('campaign/get/', $capture->endpoint);
        $this->assertArrayHasKey('filtering', $capture->params);
        $this->assertStringContainsString(self::FAKE_CAMPAIGN_ID, $capture->params['filtering']);
    }

    public function testGetReturnsFirstListElement(): void
    {
        $fakeRow = ['campaign_id' => self::FAKE_CAMPAIGN_ID, 'campaign_name' => 'Test'];

        $mock = $this->createMock(Client::class);
        $mock->method('advertiserId')->willReturn(self::FAKE_ADVERTISER_ID);
        $mock->method('get_api')->willReturn(['list' => [$fakeRow]]);
        $mock->method('post')->willReturn([]);
        $this->injectMockClient($mock);

        $campaign = new Campaign();
        $result = $campaign->get(self::FAKE_CAMPAIGN_ID);

        $this->assertSame($fakeRow, $result);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function baseCreateConfig(): array
    {
        return [
            'name'           => 'Test Campaign',
            'objective_type' => 'TRAFFIC',
            'budget'         => 2000,
        ];
    }

    private function buildCapturingPostMock(\stdClass $capture): Client
    {
        $mock = $this->createMock(Client::class);
        $mock->method('advertiserId')->willReturn(self::FAKE_ADVERTISER_ID);
        $mock->method('post')
             ->willReturnCallback(function (string $endpoint, array $data) use ($capture): array {
                 $capture->endpoint = $endpoint;
                 $capture->data     = $data;
                 return ['campaign_id' => self::FAKE_CAMPAIGN_ID];
             });
        $mock->method('get_api')->willReturn([]);
        return $mock;
    }

    private function buildCapturingGetMock(\stdClass $capture): Client
    {
        $mock = $this->createMock(Client::class);
        $mock->method('advertiserId')->willReturn(self::FAKE_ADVERTISER_ID);
        $mock->method('get_api')
             ->willReturnCallback(function (string $endpoint, array $params) use ($capture): array {
                 $capture->endpoint = $endpoint;
                 $capture->params   = $params;
                 return [];
             });
        $mock->method('post')->willReturn(['campaign_id' => self::FAKE_CAMPAIGN_ID]);
        return $mock;
    }

    private function buildPassthroughClientMock(): Client
    {
        $mock = $this->createMock(Client::class);
        $mock->method('advertiserId')->willReturn(self::FAKE_ADVERTISER_ID);
        $mock->method('post')->willReturn(['campaign_id' => self::FAKE_CAMPAIGN_ID]);
        $mock->method('get_api')->willReturn([]);
        return $mock;
    }

    private function injectMockClient(Client $client): void
    {
        $ref = new \ReflectionProperty(Client::class, 'instance');
        $ref->setAccessible(true);
        $ref->setValue(null, $client);
    }
}
