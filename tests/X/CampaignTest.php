<?php

declare(strict_types=1);

namespace AdManager\Tests\X;

use AdManager\X\Campaign;
use AdManager\X\Client;
use PHPUnit\Framework\TestCase;

/**
 * Tests for X\Campaign.
 *
 * Campaign calls Client::post() / Client::put() / Client::get() to hit the X Ads API.
 * We inject a mock Client via the singleton slot and verify:
 * - create() POSTs to 'campaigns' with required fields
 * - create() defaults entity_status to PAUSED
 * - create() falls back to X_ADS_FUNDING_INSTRUMENT_ID env var
 * - create() throws when funding_instrument_id is missing
 * - create() returns the campaign ID from response['data']['id']
 * - pause() PUTs entity_status=PAUSED to campaigns/{id}
 * - enable() PUTs entity_status=ACTIVE to campaigns/{id}
 * - list() GETs 'campaigns'
 * - get() GETs 'campaigns/{id}'
 * - X uses PUT for updates, not POST
 */
class CampaignTest extends TestCase
{
    private const FAKE_ACCOUNT_ID  = 'acc_111222';
    private const FAKE_CAMPAIGN_ID = 'cmp_987654';

    protected function setUp(): void
    {
        $this->injectMockClient($this->buildPassthroughClientMock());
    }

    protected function tearDown(): void
    {
        Client::reset();
        // Clean up env var if set by tests
        unset($_ENV['X_ADS_FUNDING_INSTRUMENT_ID']);
    }

    // -------------------------------------------------------------------------
    // create()
    // -------------------------------------------------------------------------

    public function testCreatePostsToCampaignsEndpoint(): void
    {
        $capture = new \stdClass();
        $capture->endpoint = null;

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
        $campaign->create(['name' => 'MyBrand — X — AU'] + $this->baseCreateConfig());

        $this->assertSame('MyBrand — X — AU', $capture->data['name']);
    }

    public function testCreateDefaultsEntityStatusToPaused(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        $campaign = new Campaign();
        $campaign->create($this->baseCreateConfig()); // no entity_status

        $this->assertSame('PAUSED', $capture->data['entity_status']);
    }

    public function testCreateRespectsExplicitEntityStatus(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        $campaign = new Campaign();
        $campaign->create(['entity_status' => 'ACTIVE'] + $this->baseCreateConfig());

        $this->assertSame('ACTIVE', $capture->data['entity_status']);
    }

    public function testCreateIncludesFundingInstrumentIdInPayload(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        $campaign = new Campaign();
        $campaign->create(['funding_instrument_id' => 'fi_abc123'] + $this->baseCreateConfig());

        $this->assertSame('fi_abc123', $capture->data['funding_instrument_id']);
    }

    public function testCreateFallsBackToEnvVarForFundingInstrumentId(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));
        $_ENV['X_ADS_FUNDING_INSTRUMENT_ID'] = 'fi_from_env';

        $config = $this->baseCreateConfig();
        unset($config['funding_instrument_id']);

        $campaign = new Campaign();
        $campaign->create($config);

        $this->assertSame('fi_from_env', $capture->data['funding_instrument_id']);
    }

    public function testCreateThrowsWhenFundingInstrumentIdMissing(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('funding_instrument_id');

        unset($_ENV['X_ADS_FUNDING_INSTRUMENT_ID']);

        $config = $this->baseCreateConfig();
        unset($config['funding_instrument_id']);

        $campaign = new Campaign();
        $campaign->create($config);
    }

    public function testCreateIncludesDailyBudgetInPayload(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        $campaign = new Campaign();
        $campaign->create(['daily_budget_amount_local_micro' => 10_000_000] + $this->baseCreateConfig());

        $this->assertSame(10_000_000, $capture->data['daily_budget_amount_local_micro']);
    }

    public function testCreateReturnsCampaignId(): void
    {
        $mock = $this->createMock(Client::class);
        $mock->method('accountId')->willReturn(self::FAKE_ACCOUNT_ID);
        $mock->method('post')->willReturn(['data' => ['id' => self::FAKE_CAMPAIGN_ID]]);
        $mock->method('get_api')->willReturn(['data' => []]);
        $mock->method('put')->willReturn([]);
        $this->injectMockClient($mock);

        $campaign = new Campaign();
        $result = $campaign->create($this->baseCreateConfig());

        $this->assertSame(self::FAKE_CAMPAIGN_ID, $result);
    }

    // -------------------------------------------------------------------------
    // pause() / enable() — must use PUT, not POST
    // -------------------------------------------------------------------------

    public function testPausePutsEntityStatusPaused(): void
    {
        $capture = new \stdClass();
        $capture->endpoint = null;
        $capture->data     = null;

        $mock = $this->createMock(Client::class);
        $mock->method('accountId')->willReturn(self::FAKE_ACCOUNT_ID);
        $mock->method('put')
             ->willReturnCallback(function (string $endpoint, array $data) use ($capture): array {
                 $capture->endpoint = $endpoint;
                 $capture->data     = $data;
                 return [];
             });
        $mock->method('post')->willReturn(['data' => ['id' => self::FAKE_CAMPAIGN_ID]]);
        $mock->method('get_api')->willReturn(['data' => []]);
        $this->injectMockClient($mock);

        $campaign = new Campaign();
        $campaign->pause(self::FAKE_CAMPAIGN_ID);

        $this->assertSame('campaigns/' . self::FAKE_CAMPAIGN_ID, $capture->endpoint);
        $this->assertSame('PAUSED', $capture->data['entity_status']);
    }

    public function testEnablePutsEntityStatusActive(): void
    {
        $capture = new \stdClass();
        $capture->endpoint = null;
        $capture->data     = null;

        $mock = $this->createMock(Client::class);
        $mock->method('accountId')->willReturn(self::FAKE_ACCOUNT_ID);
        $mock->method('put')
             ->willReturnCallback(function (string $endpoint, array $data) use ($capture): array {
                 $capture->endpoint = $endpoint;
                 $capture->data     = $data;
                 return [];
             });
        $mock->method('post')->willReturn(['data' => ['id' => self::FAKE_CAMPAIGN_ID]]);
        $mock->method('get_api')->willReturn(['data' => []]);
        $this->injectMockClient($mock);

        $campaign = new Campaign();
        $campaign->enable(self::FAKE_CAMPAIGN_ID);

        $this->assertSame('campaigns/' . self::FAKE_CAMPAIGN_ID, $capture->endpoint);
        $this->assertSame('ACTIVE', $capture->data['entity_status']);
    }

    public function testPauseUsesPutNotPost(): void
    {
        $putCalled  = false;
        $postCalled = false;

        $mock = $this->createMock(Client::class);
        $mock->method('accountId')->willReturn(self::FAKE_ACCOUNT_ID);
        $mock->method('put')->willReturnCallback(function () use (&$putCalled): array {
            $putCalled = true;
            return [];
        });
        $mock->method('post')->willReturnCallback(function () use (&$postCalled): array {
            $postCalled = true;
            return ['data' => ['id' => 'x']];
        });
        $mock->method('get_api')->willReturn(['data' => []]);
        $this->injectMockClient($mock);

        $campaign = new Campaign();
        $campaign->pause(self::FAKE_CAMPAIGN_ID);

        $this->assertTrue($putCalled, 'pause() should call put()');
        $this->assertFalse($postCalled, 'pause() must not call post()');
    }

    // -------------------------------------------------------------------------
    // list()
    // -------------------------------------------------------------------------

    public function testListGetsCampaignsEndpoint(): void
    {
        $capture = new \stdClass();
        $capture->endpoint = null;

        $mock = $this->createMock(Client::class);
        $mock->method('accountId')->willReturn(self::FAKE_ACCOUNT_ID);
        $mock->method('get_api')
             ->willReturnCallback(function (string $endpoint) use ($capture): array {
                 $capture->endpoint = $endpoint;
                 return ['data' => []];
             });
        $mock->method('post')->willReturn(['data' => ['id' => self::FAKE_CAMPAIGN_ID]]);
        $mock->method('put')->willReturn([]);
        $this->injectMockClient($mock);

        $campaign = new Campaign();
        $campaign->list();

        $this->assertSame('campaigns', $capture->endpoint);
    }

    public function testListReturnsDataArray(): void
    {
        $fakeData = [
            ['id' => 'cmp_1', 'name' => 'Campaign One'],
            ['id' => 'cmp_2', 'name' => 'Campaign Two'],
        ];

        $mock = $this->createMock(Client::class);
        $mock->method('accountId')->willReturn(self::FAKE_ACCOUNT_ID);
        $mock->method('get_api')->willReturn(['data' => $fakeData]);
        $mock->method('post')->willReturn(['data' => ['id' => self::FAKE_CAMPAIGN_ID]]);
        $mock->method('put')->willReturn([]);
        $this->injectMockClient($mock);

        $campaign = new Campaign();
        $result   = $campaign->list();

        $this->assertSame($fakeData, $result);
    }

    public function testListReturnsEmptyArrayWhenNoData(): void
    {
        $mock = $this->createMock(Client::class);
        $mock->method('accountId')->willReturn(self::FAKE_ACCOUNT_ID);
        $mock->method('get_api')->willReturn([]);
        $mock->method('post')->willReturn(['data' => ['id' => self::FAKE_CAMPAIGN_ID]]);
        $mock->method('put')->willReturn([]);
        $this->injectMockClient($mock);

        $campaign = new Campaign();
        $result   = $campaign->list();

        $this->assertSame([], $result);
    }

    // -------------------------------------------------------------------------
    // get()
    // -------------------------------------------------------------------------

    public function testGetCallsCampaignIdEndpoint(): void
    {
        $capture = new \stdClass();
        $capture->endpoint = null;

        $mock = $this->createMock(Client::class);
        $mock->method('accountId')->willReturn(self::FAKE_ACCOUNT_ID);
        $mock->method('get_api')
             ->willReturnCallback(function (string $endpoint) use ($capture): array {
                 $capture->endpoint = $endpoint;
                 return ['data' => []];
             });
        $mock->method('post')->willReturn(['data' => ['id' => self::FAKE_CAMPAIGN_ID]]);
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
            'name'                            => 'Test X Campaign',
            'funding_instrument_id'           => 'fi_abc123',
            'daily_budget_amount_local_micro' => 10_000_000,
        ];
    }

    private function buildCapturingPostMock(\stdClass $capture): Client
    {
        $mock = $this->createMock(Client::class);
        $mock->method('accountId')->willReturn(self::FAKE_ACCOUNT_ID);
        $mock->method('post')
             ->willReturnCallback(function (string $endpoint, array $data) use ($capture): array {
                 $capture->endpoint = $endpoint;
                 $capture->data     = $data;
                 return ['data' => ['id' => self::FAKE_CAMPAIGN_ID]];
             });
        $mock->method('get_api')->willReturn(['data' => []]);
        $mock->method('put')->willReturn([]);
        return $mock;
    }

    private function buildPassthroughClientMock(): Client
    {
        $mock = $this->createMock(Client::class);
        $mock->method('accountId')->willReturn(self::FAKE_ACCOUNT_ID);
        $mock->method('post')->willReturn(['data' => ['id' => self::FAKE_CAMPAIGN_ID]]);
        $mock->method('get_api')->willReturn(['data' => []]);
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
