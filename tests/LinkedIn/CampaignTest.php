<?php

declare(strict_types=1);

namespace AdManager\Tests\LinkedIn;

use AdManager\LinkedIn\Campaign;
use AdManager\LinkedIn\Client;
use PHPUnit\Framework\TestCase;

/**
 * Tests for LinkedIn\Campaign.
 *
 * Campaign calls Client::post() / Client::get() to manage campaigns.
 * We inject a mock Client and verify:
 * - create() POSTs to 'adCampaigns'
 * - create() includes account URN and campaignGroup URN in payload
 * - create() defaults type to SPONSORED_UPDATES
 * - create() defaults costType to CPM
 * - create() defaults status to PAUSED
 * - create() includes dailyBudget with amount and currencyCode
 * - dailyBudget defaults currencyCode to AUD
 * - create() omits dailyBudget when not provided
 * - create() returns campaign URN/ID
 * - pause() posts patch status=PAUSED
 * - enable() posts patch status=ACTIVE
 * - list() GETs adCampaigns with search query
 * - list() filters by campaignGroupUrn when provided
 * - get() GETs adCampaigns/{id}
 * - update() sends patch $set with provided fields
 */
class CampaignTest extends TestCase
{
    private const FAKE_ACCOUNT_URN      = 'urn:li:sponsoredAccount:123456';
    private const FAKE_GROUP_URN        = 'urn:li:sponsoredCampaignGroup:111222';
    private const FAKE_CAMPAIGN_URN     = 'urn:li:sponsoredCampaign:987654';
    private const FAKE_CAMPAIGN_ID      = '987654';

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

    public function testCreatePostsToAdCampaignsEndpoint(): void
    {
        $capture = new \stdClass();
        $capture->endpoint = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        $campaign = new Campaign();
        $campaign->create(self::FAKE_GROUP_URN, $this->baseCreateConfig());

        $this->assertSame('adCampaigns', $capture->endpoint);
    }

    public function testCreateIncludesAccountUrnInPayload(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        $campaign = new Campaign();
        $campaign->create(self::FAKE_GROUP_URN, $this->baseCreateConfig());

        $this->assertSame(self::FAKE_ACCOUNT_URN, $capture->data['account']);
    }

    public function testCreateIncludesCampaignGroupUrnInPayload(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        $campaign = new Campaign();
        $campaign->create(self::FAKE_GROUP_URN, $this->baseCreateConfig());

        $this->assertSame(self::FAKE_GROUP_URN, $capture->data['campaignGroup']);
    }

    public function testCreateDefaultsTypeToPSponsoredUpdates(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        $config = $this->baseCreateConfig();
        unset($config['type']);

        $campaign = new Campaign();
        $campaign->create(self::FAKE_GROUP_URN, $config);

        $this->assertSame('SPONSORED_UPDATES', $capture->data['type']);
    }

    public function testCreateDefaultsCostTypeToCpm(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        $config = $this->baseCreateConfig();
        unset($config['costType']);

        $campaign = new Campaign();
        $campaign->create(self::FAKE_GROUP_URN, $config);

        $this->assertSame('CPM', $capture->data['costType']);
    }

    public function testCreateDefaultsStatusToPaused(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        $config = $this->baseCreateConfig();
        unset($config['status']);

        $campaign = new Campaign();
        $campaign->create(self::FAKE_GROUP_URN, $config);

        $this->assertSame('PAUSED', $capture->data['status']);
    }

    public function testCreateIncludesDailyBudgetWithAmountAndCurrency(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        $config = $this->baseCreateConfig();
        $config['dailyBudget'] = ['amount' => '10.00', 'currencyCode' => 'AUD'];

        $campaign = new Campaign();
        $campaign->create(self::FAKE_GROUP_URN, $config);

        $this->assertArrayHasKey('dailyBudget', $capture->data);
        $this->assertSame('10.00', $capture->data['dailyBudget']['amount']);
        $this->assertSame('AUD', $capture->data['dailyBudget']['currencyCode']);
    }

    public function testCreateDailyBudgetDefaultsCurrencyToAud(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        $config = $this->baseCreateConfig();
        $config['dailyBudget'] = ['amount' => '10.00']; // no currencyCode

        $campaign = new Campaign();
        $campaign->create(self::FAKE_GROUP_URN, $config);

        $this->assertSame('AUD', $capture->data['dailyBudget']['currencyCode']);
    }

    public function testCreateOmitsDailyBudgetWhenNotProvided(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        $config = $this->baseCreateConfig();
        unset($config['dailyBudget']);

        $campaign = new Campaign();
        $campaign->create(self::FAKE_GROUP_URN, $config);

        $this->assertArrayNotHasKey('dailyBudget', $capture->data);
    }

    public function testCreateReturnsIdFromResponse(): void
    {
        $mock = $this->createMock(Client::class);
        $mock->method('adAccountUrn')->willReturn(self::FAKE_ACCOUNT_URN);
        $mock->method('post')->willReturn(['id' => self::FAKE_CAMPAIGN_URN]);
        $mock->method('get_api')->willReturn(['elements' => []]);
        $this->injectMockClient($mock);

        $campaign = new Campaign();
        $result   = $campaign->create(self::FAKE_GROUP_URN, $this->baseCreateConfig());

        $this->assertSame(self::FAKE_CAMPAIGN_URN, $result);
    }

    // -------------------------------------------------------------------------
    // pause() / enable()
    // -------------------------------------------------------------------------

    public function testPausePostsPausedStatus(): void
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

        $campaign = new Campaign();
        $campaign->pause(self::FAKE_CAMPAIGN_URN);

        $this->assertSame('PAUSED', $capture->data['patch']['$set']['status']);
    }

    public function testEnablePostsActiveStatus(): void
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

        $campaign = new Campaign();
        $campaign->enable(self::FAKE_CAMPAIGN_URN);

        $this->assertSame('ACTIVE', $capture->data['patch']['$set']['status']);
    }

    public function testPauseCallsEndpointWithExtractedId(): void
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

        $campaign = new Campaign();
        $campaign->pause(self::FAKE_CAMPAIGN_URN);

        $this->assertStringContainsString(self::FAKE_CAMPAIGN_ID, $capture->endpoint);
        $this->assertStringNotContainsString('urn:li:', $capture->endpoint);
    }

    // -------------------------------------------------------------------------
    // list()
    // -------------------------------------------------------------------------

    public function testListGetsAdCampaignsEndpoint(): void
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
        $mock->method('post')->willReturn(['id' => self::FAKE_CAMPAIGN_URN]);
        $this->injectMockClient($mock);

        $campaign = new Campaign();
        $campaign->list();

        $this->assertSame('adCampaigns', $capture->endpoint);
    }

    public function testListFiltersByCampaignGroupWhenProvided(): void
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
        $mock->method('post')->willReturn(['id' => self::FAKE_CAMPAIGN_URN]);
        $this->injectMockClient($mock);

        $campaign = new Campaign();
        $campaign->list(self::FAKE_GROUP_URN);

        $this->assertStringContainsString(self::FAKE_GROUP_URN, $capture->params['search']);
    }

    public function testListUsesAccountSearchWhenNoCampaignGroupProvided(): void
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
        $mock->method('post')->willReturn(['id' => self::FAKE_CAMPAIGN_URN]);
        $this->injectMockClient($mock);

        $campaign = new Campaign();
        $campaign->list();

        $this->assertStringContainsString(self::FAKE_ACCOUNT_URN, $capture->params['search']);
    }

    public function testListReturnsElementsArray(): void
    {
        $fakeElements = [
            ['id' => self::FAKE_CAMPAIGN_URN, 'name' => 'Campaign One'],
        ];

        $mock = $this->createMock(Client::class);
        $mock->method('adAccountUrn')->willReturn(self::FAKE_ACCOUNT_URN);
        $mock->method('get_api')->willReturn(['elements' => $fakeElements]);
        $mock->method('post')->willReturn(['id' => self::FAKE_CAMPAIGN_URN]);
        $this->injectMockClient($mock);

        $campaign = new Campaign();
        $result   = $campaign->list();

        $this->assertSame($fakeElements, $result);
    }

    // -------------------------------------------------------------------------
    // get()
    // -------------------------------------------------------------------------

    public function testGetCallsCampaignIdEndpoint(): void
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
        $mock->method('post')->willReturn(['id' => self::FAKE_CAMPAIGN_URN]);
        $this->injectMockClient($mock);

        $campaign = new Campaign();
        $campaign->get(self::FAKE_CAMPAIGN_URN);

        $this->assertStringContainsString(self::FAKE_CAMPAIGN_ID, $capture->endpoint);
    }

    // -------------------------------------------------------------------------
    // update()
    // -------------------------------------------------------------------------

    public function testUpdateSendsPatchSetWithFields(): void
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

        $campaign = new Campaign();
        $campaign->update(self::FAKE_CAMPAIGN_URN, ['status' => 'PAUSED', 'name' => 'Renamed']);

        $this->assertArrayHasKey('patch', $capture->data);
        $this->assertSame('PAUSED', $capture->data['patch']['$set']['status']);
        $this->assertSame('Renamed', $capture->data['patch']['$set']['name']);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function baseCreateConfig(): array
    {
        return [
            'name'          => 'Test LinkedIn Campaign',
            'objectiveType' => 'WEBSITE_VISITS',
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
                 return ['id' => self::FAKE_CAMPAIGN_URN];
             });
        $mock->method('get_api')->willReturn(['elements' => []]);
        return $mock;
    }

    private function buildPassthroughClientMock(): Client
    {
        $mock = $this->createMock(Client::class);
        $mock->method('adAccountUrn')->willReturn(self::FAKE_ACCOUNT_URN);
        $mock->method('post')->willReturn(['id' => self::FAKE_CAMPAIGN_URN]);
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
