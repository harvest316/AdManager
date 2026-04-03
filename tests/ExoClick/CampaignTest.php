<?php

declare(strict_types=1);

namespace AdManager\Tests\ExoClick;

use AdManager\ExoClick\Campaign;
use AdManager\ExoClick\Client;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ExoClick\Campaign.
 *
 * Campaign calls Client::post(), put(), get() to hit the ExoClick API.
 * We inject a mock Client via the singleton slot and verify:
 * - create() posts to 'campaigns' with correct data shape
 * - status defaults to 0 (paused)
 * - pause() PUTs status=0 to campaigns/{id}
 * - enable() PUTs status=1 to campaigns/{id}
 * - list() calls GET campaigns
 * - get() calls GET campaigns/{id}
 */
class CampaignTest extends TestCase
{
    private const FAKE_CAMPAIGN_ID = '12345';

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

        $this->assertSame('campaigns', $capture->endpoint);
    }

    public function testCreateIncludesNameInPayload(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        $campaign = new Campaign();
        $campaign->create(['name' => 'MyBrand — Adult — AU'] + $this->baseCreateConfig());

        $this->assertSame('MyBrand — Adult — AU', $capture->data['name']);
    }

    public function testCreateDefaultsStatusToPaused(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        $campaign = new Campaign();
        $campaign->create($this->baseCreateConfig()); // no 'status' key

        $this->assertSame(0, $capture->data['status']);
    }

    public function testCreateRespectsExplicitStatus(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        $campaign = new Campaign();
        $campaign->create(['status' => 1] + $this->baseCreateConfig());

        $this->assertSame(1, $capture->data['status']);
    }

    public function testCreateIncludesDailyBudget(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        $campaign = new Campaign();
        $campaign->create(['daily_budget' => 5000] + $this->baseCreateConfig());

        $this->assertSame(5000, $capture->data['daily_budget']);
    }

    public function testCreateIncludesCategoryGroup(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        $campaign = new Campaign();
        $campaign->create(['category_group' => 3] + $this->baseCreateConfig());

        $this->assertSame(3, $capture->data['category_group']);
    }

    public function testCreateReturnsIdFromResponse(): void
    {
        $mock = $this->createMock(Client::class);
        $mock->method('post')->willReturn(['id' => self::FAKE_CAMPAIGN_ID]);
        $mock->method('get_api')->willReturn([]);
        $mock->method('put')->willReturn([]);
        $this->injectMockClient($mock);

        $campaign = new Campaign();
        $result = $campaign->create($this->baseCreateConfig());

        $this->assertSame(self::FAKE_CAMPAIGN_ID, $result);
    }

    // -------------------------------------------------------------------------
    // pause() / enable()
    // -------------------------------------------------------------------------

    public function testPausePutsStatus0ToCampaignEndpoint(): void
    {
        $capture = new \stdClass();
        $capture->endpoint = null;
        $capture->data     = null;

        $this->injectMockClient($this->buildCapturingPutMock($capture));

        $campaign = new Campaign();
        $campaign->pause(self::FAKE_CAMPAIGN_ID);

        $this->assertSame('campaigns/' . self::FAKE_CAMPAIGN_ID, $capture->endpoint);
        $this->assertSame(0, $capture->data['status']);
    }

    public function testEnablePutsStatus1ToCampaignEndpoint(): void
    {
        $capture = new \stdClass();
        $capture->endpoint = null;
        $capture->data     = null;

        $this->injectMockClient($this->buildCapturingPutMock($capture));

        $campaign = new Campaign();
        $campaign->enable(self::FAKE_CAMPAIGN_ID);

        $this->assertSame('campaigns/' . self::FAKE_CAMPAIGN_ID, $capture->endpoint);
        $this->assertSame(1, $capture->data['status']);
    }

    // -------------------------------------------------------------------------
    // list()
    // -------------------------------------------------------------------------

    public function testListCallsCampaignsEndpoint(): void
    {
        $capture = new \stdClass();
        $capture->endpoint = null;

        $mock = $this->createMock(Client::class);
        $mock->method('get_api')
             ->willReturnCallback(function (string $endpoint) use ($capture): array {
                 $capture->endpoint = $endpoint;
                 return [];
             });
        $mock->method('post')->willReturn(['id' => self::FAKE_CAMPAIGN_ID]);
        $mock->method('put')->willReturn([]);
        $this->injectMockClient($mock);

        $campaign = new Campaign();
        $campaign->list();

        $this->assertSame('campaigns', $capture->endpoint);
    }

    public function testListReturnsDataArray(): void
    {
        $fakeData = [['id' => '1', 'name' => 'Camp 1'], ['id' => '2', 'name' => 'Camp 2']];

        $mock = $this->createMock(Client::class);
        $mock->method('get_api')->willReturn(['data' => $fakeData]);
        $mock->method('post')->willReturn(['id' => self::FAKE_CAMPAIGN_ID]);
        $mock->method('put')->willReturn([]);
        $this->injectMockClient($mock);

        $campaign = new Campaign();
        $this->assertSame($fakeData, $campaign->list());
    }

    // -------------------------------------------------------------------------
    // get()
    // -------------------------------------------------------------------------

    public function testGetCallsCampaignIdEndpoint(): void
    {
        $capture = new \stdClass();
        $capture->endpoint = null;

        $mock = $this->createMock(Client::class);
        $mock->method('get_api')
             ->willReturnCallback(function (string $endpoint) use ($capture): array {
                 $capture->endpoint = $endpoint;
                 return ['id' => self::FAKE_CAMPAIGN_ID];
             });
        $mock->method('post')->willReturn(['id' => self::FAKE_CAMPAIGN_ID]);
        $mock->method('put')->willReturn([]);
        $this->injectMockClient($mock);

        $campaign = new Campaign();
        $campaign->get(self::FAKE_CAMPAIGN_ID);

        $this->assertSame('campaigns/' . self::FAKE_CAMPAIGN_ID, $capture->endpoint);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function baseCreateConfig(): array
    {
        return [
            'name'           => 'Test Campaign',
            'daily_budget'   => 2000,
            'category_group' => 1,
        ];
    }

    private function buildCapturingPostMock(\stdClass $capture): Client
    {
        $mock = $this->createMock(Client::class);
        $mock->method('post')
             ->willReturnCallback(function (string $endpoint, array $data) use ($capture): array {
                 $capture->endpoint = $endpoint;
                 $capture->data     = $data;
                 return ['id' => self::FAKE_CAMPAIGN_ID];
             });
        $mock->method('get_api')->willReturn([]);
        $mock->method('put')->willReturn([]);
        return $mock;
    }

    private function buildCapturingPutMock(\stdClass $capture): Client
    {
        $mock = $this->createMock(Client::class);
        $mock->method('put')
             ->willReturnCallback(function (string $endpoint, array $data) use ($capture): array {
                 $capture->endpoint = $endpoint;
                 $capture->data     = $data;
                 return [];
             });
        $mock->method('post')->willReturn(['id' => self::FAKE_CAMPAIGN_ID]);
        $mock->method('get_api')->willReturn([]);
        return $mock;
    }

    private function buildPassthroughClientMock(): Client
    {
        $mock = $this->createMock(Client::class);
        $mock->method('post')->willReturn(['id' => self::FAKE_CAMPAIGN_ID]);
        $mock->method('get_api')->willReturn([]);
        $mock->method('put')->willReturn([]);
        return $mock;
    }

    private function injectMockClient(Client $client): void
    {
        $ref = new \ReflectionProperty(Client::class, 'instance');
        $ref->setAccessible(true);
        $ref->setValue(null, $client);
    }
}
