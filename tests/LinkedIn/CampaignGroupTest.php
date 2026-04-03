<?php

declare(strict_types=1);

namespace AdManager\Tests\LinkedIn;

use AdManager\LinkedIn\CampaignGroup;
use AdManager\LinkedIn\Client;
use PHPUnit\Framework\TestCase;

/**
 * Tests for LinkedIn\CampaignGroup.
 *
 * CampaignGroup calls Client::post() / Client::get() to manage campaign groups.
 * We inject a mock Client via the singleton slot and verify:
 * - create() POSTs to 'adCampaignGroups'
 * - create() includes account URN in payload
 * - create() defaults status to PAUSED
 * - create() includes runSchedule when provided
 * - create() returns the group URN/ID from response
 * - pause() posts status=PAUSED via patch
 * - enable() posts status=ACTIVE via patch
 * - list() GETs adCampaignGroups with account search query
 * - list() returns elements array
 * - get() GETs adCampaignGroups/{id}
 * - URN-to-ID extraction works for urn:li:sponsoredCampaignGroup:{id}
 */
class CampaignGroupTest extends TestCase
{
    private const FAKE_ACCOUNT_URN = 'urn:li:sponsoredAccount:123456';
    private const FAKE_GROUP_URN   = 'urn:li:sponsoredCampaignGroup:987654';
    private const FAKE_GROUP_ID    = '987654';

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

    public function testCreatePostsToAdCampaignGroupsEndpoint(): void
    {
        $capture = new \stdClass();
        $capture->endpoint = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        $group = new CampaignGroup();
        $group->create($this->baseCreateConfig());

        $this->assertSame('adCampaignGroups', $capture->endpoint);
    }

    public function testCreateIncludesAccountUrnInPayload(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        $group = new CampaignGroup();
        $group->create($this->baseCreateConfig());

        $this->assertSame(self::FAKE_ACCOUNT_URN, $capture->data['account']);
    }

    public function testCreateIncludesNameInPayload(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        $group = new CampaignGroup();
        $group->create(['name' => 'Q2 2026 Brand'] + $this->baseCreateConfig());

        $this->assertSame('Q2 2026 Brand', $capture->data['name']);
    }

    public function testCreateDefaultsStatusToPaused(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        $config = $this->baseCreateConfig();
        unset($config['status']);

        $group = new CampaignGroup();
        $group->create($config);

        $this->assertSame('PAUSED', $capture->data['status']);
    }

    public function testCreateIncludesRunScheduleWhenProvided(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        $runSchedule = ['start' => 1743465600000, 'end' => 1751241600000];

        $group = new CampaignGroup();
        $group->create(['runSchedule' => $runSchedule] + $this->baseCreateConfig());

        $this->assertArrayHasKey('runSchedule', $capture->data);
        $this->assertSame($runSchedule, $capture->data['runSchedule']);
    }

    public function testCreateOmitsRunScheduleWhenNotProvided(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        $group = new CampaignGroup();
        $group->create($this->baseCreateConfig());

        $this->assertArrayNotHasKey('runSchedule', $capture->data);
    }

    public function testCreateReturnsIdFromResponse(): void
    {
        $mock = $this->createMock(Client::class);
        $mock->method('adAccountUrn')->willReturn(self::FAKE_ACCOUNT_URN);
        $mock->method('post')->willReturn(['id' => self::FAKE_GROUP_URN]);
        $mock->method('get_api')->willReturn(['elements' => []]);
        $this->injectMockClient($mock);

        $group  = new CampaignGroup();
        $result = $group->create($this->baseCreateConfig());

        $this->assertSame(self::FAKE_GROUP_URN, $result);
    }

    // -------------------------------------------------------------------------
    // pause() / enable()
    // -------------------------------------------------------------------------

    public function testPausePostsPausedStatusForGroup(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $mock = $this->createMock(Client::class);
        $mock->method('adAccountUrn')->willReturn(self::FAKE_ACCOUNT_URN);
        $mock->method('post')
             ->willReturnCallback(function (string $endpoint, array $data) use ($capture): array {
                 $capture->data = $data;
                 return [];
             });
        $mock->method('get_api')->willReturn(['elements' => []]);
        $this->injectMockClient($mock);

        $group = new CampaignGroup();
        $group->pause(self::FAKE_GROUP_URN);

        $this->assertSame('PAUSED', $capture->data['patch']['$set']['status']);
    }

    public function testEnablePostsActiveStatusForGroup(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $mock = $this->createMock(Client::class);
        $mock->method('adAccountUrn')->willReturn(self::FAKE_ACCOUNT_URN);
        $mock->method('post')
             ->willReturnCallback(function (string $endpoint, array $data) use ($capture): array {
                 $capture->data = $data;
                 return [];
             });
        $mock->method('get_api')->willReturn(['elements' => []]);
        $this->injectMockClient($mock);

        $group = new CampaignGroup();
        $group->enable(self::FAKE_GROUP_URN);

        $this->assertSame('ACTIVE', $capture->data['patch']['$set']['status']);
    }

    public function testPauseExtractsNumericIdFromUrn(): void
    {
        $capture = new \stdClass();
        $capture->endpoint = null;

        $mock = $this->createMock(Client::class);
        $mock->method('adAccountUrn')->willReturn(self::FAKE_ACCOUNT_URN);
        $mock->method('post')
             ->willReturnCallback(function (string $endpoint) use ($capture): array {
                 $capture->endpoint = $endpoint;
                 return [];
             });
        $mock->method('get_api')->willReturn(['elements' => []]);
        $this->injectMockClient($mock);

        $group = new CampaignGroup();
        $group->pause(self::FAKE_GROUP_URN);

        $this->assertStringContainsString(self::FAKE_GROUP_ID, $capture->endpoint);
        $this->assertStringNotContainsString('urn:li:', $capture->endpoint);
    }

    // -------------------------------------------------------------------------
    // list()
    // -------------------------------------------------------------------------

    public function testListGetsAdCampaignGroupsEndpoint(): void
    {
        $capture = new \stdClass();
        $capture->endpoint = null;

        $mock = $this->createMock(Client::class);
        $mock->method('adAccountUrn')->willReturn(self::FAKE_ACCOUNT_URN);
        $mock->method('get_api')
             ->willReturnCallback(function (string $endpoint) use ($capture): array {
                 $capture->endpoint = $endpoint;
                 return ['elements' => []];
             });
        $mock->method('post')->willReturn(['id' => self::FAKE_GROUP_URN]);
        $this->injectMockClient($mock);

        $group = new CampaignGroup();
        $group->list();

        $this->assertSame('adCampaignGroups', $capture->endpoint);
    }

    public function testListIncludesAccountInSearchQuery(): void
    {
        $capture = new \stdClass();
        $capture->params = null;

        $mock = $this->createMock(Client::class);
        $mock->method('adAccountUrn')->willReturn(self::FAKE_ACCOUNT_URN);
        $mock->method('get_api')
             ->willReturnCallback(function (string $endpoint, array $params) use ($capture): array {
                 $capture->params = $params;
                 return ['elements' => []];
             });
        $mock->method('post')->willReturn(['id' => self::FAKE_GROUP_URN]);
        $this->injectMockClient($mock);

        $group = new CampaignGroup();
        $group->list();

        $this->assertStringContainsString(self::FAKE_ACCOUNT_URN, $capture->params['search']);
    }

    public function testListReturnsElementsArray(): void
    {
        $fakeElements = [
            ['id' => self::FAKE_GROUP_URN, 'name' => 'Group One'],
        ];

        $mock = $this->createMock(Client::class);
        $mock->method('adAccountUrn')->willReturn(self::FAKE_ACCOUNT_URN);
        $mock->method('get_api')->willReturn(['elements' => $fakeElements]);
        $mock->method('post')->willReturn(['id' => self::FAKE_GROUP_URN]);
        $this->injectMockClient($mock);

        $group  = new CampaignGroup();
        $result = $group->list();

        $this->assertSame($fakeElements, $result);
    }

    // -------------------------------------------------------------------------
    // get()
    // -------------------------------------------------------------------------

    public function testGetCallsGroupIdEndpoint(): void
    {
        $capture = new \stdClass();
        $capture->endpoint = null;

        $mock = $this->createMock(Client::class);
        $mock->method('adAccountUrn')->willReturn(self::FAKE_ACCOUNT_URN);
        $mock->method('get_api')
             ->willReturnCallback(function (string $endpoint) use ($capture): array {
                 $capture->endpoint = $endpoint;
                 return [];
             });
        $mock->method('post')->willReturn(['id' => self::FAKE_GROUP_URN]);
        $this->injectMockClient($mock);

        $group = new CampaignGroup();
        $group->get(self::FAKE_GROUP_URN);

        $this->assertStringContainsString(self::FAKE_GROUP_ID, $capture->endpoint);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function baseCreateConfig(): array
    {
        return [
            'name'   => 'Test Campaign Group',
            'status' => 'PAUSED',
        ];
    }

    private function buildCapturingPostMock(\stdClass $capture): Client
    {
        $mock = $this->createMock(Client::class);
        $mock->method('adAccountUrn')->willReturn(self::FAKE_ACCOUNT_URN);
        $mock->method('post')
             ->willReturnCallback(function (string $endpoint, array $data) use ($capture): array {
                 $capture->endpoint = $endpoint;
                 $capture->data     = $data;
                 return ['id' => self::FAKE_GROUP_URN];
             });
        $mock->method('get_api')->willReturn(['elements' => []]);
        return $mock;
    }

    private function buildPassthroughClientMock(): Client
    {
        $mock = $this->createMock(Client::class);
        $mock->method('adAccountUrn')->willReturn(self::FAKE_ACCOUNT_URN);
        $mock->method('post')->willReturn(['id' => self::FAKE_GROUP_URN]);
        $mock->method('get_api')->willReturn(['elements' => []]);
        return $mock;
    }

    private function injectMockClient(Client $client): void
    {
        $ref = new \ReflectionProperty(Client::class, 'instance');
        $ref->setAccessible(true);
        $ref->setValue(null, $client);
    }
}
