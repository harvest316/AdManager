<?php

declare(strict_types=1);

namespace AdManager\Tests\Adsterra;

use AdManager\Adsterra\Campaign;
use AdManager\Adsterra\Client;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Adsterra\Campaign.
 *
 * Campaign calls Client::post(), patch(), get() to hit the Adsterra API.
 * We inject a mock Client via the singleton slot and verify:
 * - create() posts to 'advertising/campaigns' with correct data shape
 * - status defaults to 'suspended' (paused)
 * - pause() PATCHes status=suspended to advertising/campaigns/{id}
 * - enable() PATCHes status=active to advertising/campaigns/{id}
 * - list() calls GET advertising/campaigns
 * - get() calls GET advertising/campaigns/{id}
 */
class CampaignTest extends TestCase
{
    private const FAKE_CAMPAIGN_ID = '99887';

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

        $this->assertSame('advertising/campaigns', $capture->endpoint);
    }

    public function testCreateIncludesName(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        $campaign = new Campaign();
        $campaign->create(['name' => 'MyBrand — Push — AU'] + $this->baseCreateConfig());

        $this->assertSame('MyBrand — Push — AU', $capture->data['name']);
    }

    public function testCreateDefaultsStatusToSuspended(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        $campaign = new Campaign();
        $campaign->create($this->baseCreateConfig()); // no 'status' key

        $this->assertSame('suspended', $capture->data['status']);
    }

    public function testCreateRespectsExplicitStatus(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        $campaign = new Campaign();
        $campaign->create(['status' => 'active'] + $this->baseCreateConfig());

        $this->assertSame('active', $capture->data['status']);
    }

    public function testCreateIncludesDailyBudget(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        $campaign = new Campaign();
        $campaign->create(['daily_budget' => 3000] + $this->baseCreateConfig());

        $this->assertSame(3000, $capture->data['daily_budget']);
    }

    public function testCreateReturnsIdFromResponse(): void
    {
        $mock = $this->createMock(Client::class);
        $mock->method('post')->willReturn(['id' => self::FAKE_CAMPAIGN_ID]);
        $mock->method('get_api')->willReturn([]);
        $mock->method('patch')->willReturn([]);
        $this->injectMockClient($mock);

        $campaign = new Campaign();
        $result = $campaign->create($this->baseCreateConfig());

        $this->assertSame(self::FAKE_CAMPAIGN_ID, $result);
    }

    public function testCreatePassesThroughExtraConfigFields(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        $campaign = new Campaign();
        $campaign->create(['ad_format' => 'push'] + $this->baseCreateConfig());

        $this->assertSame('push', $capture->data['ad_format']);
    }

    // -------------------------------------------------------------------------
    // pause() / enable()
    // -------------------------------------------------------------------------

    public function testPausePatchesSuspendedStatusToCampaignEndpoint(): void
    {
        $capture = new \stdClass();
        $capture->endpoint = null;
        $capture->data     = null;

        $this->injectMockClient($this->buildCapturingPatchMock($capture));

        $campaign = new Campaign();
        $campaign->pause(self::FAKE_CAMPAIGN_ID);

        $this->assertSame('advertising/campaigns/' . self::FAKE_CAMPAIGN_ID, $capture->endpoint);
        $this->assertSame('suspended', $capture->data['status']);
    }

    public function testEnablePatchesActiveStatusToCampaignEndpoint(): void
    {
        $capture = new \stdClass();
        $capture->endpoint = null;
        $capture->data     = null;

        $this->injectMockClient($this->buildCapturingPatchMock($capture));

        $campaign = new Campaign();
        $campaign->enable(self::FAKE_CAMPAIGN_ID);

        $this->assertSame('advertising/campaigns/' . self::FAKE_CAMPAIGN_ID, $capture->endpoint);
        $this->assertSame('active', $capture->data['status']);
    }

    // -------------------------------------------------------------------------
    // list()
    // -------------------------------------------------------------------------

    public function testListCallsAdvertisingCampaignsEndpoint(): void
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
        $mock->method('patch')->willReturn([]);
        $this->injectMockClient($mock);

        $campaign = new Campaign();
        $campaign->list();

        $this->assertSame('advertising/campaigns', $capture->endpoint);
    }

    public function testListReturnsDataArray(): void
    {
        $fakeData = [['id' => '1', 'name' => 'Camp 1'], ['id' => '2', 'name' => 'Camp 2']];

        $mock = $this->createMock(Client::class);
        $mock->method('get_api')->willReturn(['data' => $fakeData]);
        $mock->method('post')->willReturn(['id' => self::FAKE_CAMPAIGN_ID]);
        $mock->method('patch')->willReturn([]);
        $this->injectMockClient($mock);

        $campaign = new Campaign();
        $this->assertSame($fakeData, $campaign->list());
    }

    // -------------------------------------------------------------------------
    // get()
    // -------------------------------------------------------------------------

    public function testGetCallsAdvertisingCampaignIdEndpoint(): void
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
        $mock->method('patch')->willReturn([]);
        $this->injectMockClient($mock);

        $campaign = new Campaign();
        $campaign->get(self::FAKE_CAMPAIGN_ID);

        $this->assertSame('advertising/campaigns/' . self::FAKE_CAMPAIGN_ID, $capture->endpoint);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function baseCreateConfig(): array
    {
        return [
            'name'         => 'Test Campaign',
            'daily_budget' => 2000,
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
        $mock->method('patch')->willReturn([]);
        return $mock;
    }

    private function buildCapturingPatchMock(\stdClass $capture): Client
    {
        $mock = $this->createMock(Client::class);
        $mock->method('patch')
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
        $mock->method('patch')->willReturn([]);
        return $mock;
    }

    private function injectMockClient(Client $client): void
    {
        $ref = new \ReflectionProperty(Client::class, 'instance');
        $ref->setAccessible(true);
        $ref->setValue(null, $client);
    }
}
