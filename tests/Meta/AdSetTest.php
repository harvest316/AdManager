<?php

declare(strict_types=1);

namespace AdManager\Tests\Meta;

use AdManager\Meta\AdSet;
use AdManager\Meta\Client;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Meta\AdSet.
 *
 * AdSet wraps Client::post() and Client::get_api() to manage ad sets on the
 * Graph API. We inject a mock Client via the singleton slot and verify:
 * - create() posts to the correct endpoint with required fields
 * - targeting is JSON-encoded
 * - billing_event defaults to IMPRESSIONS
 * - status defaults to PAUSED
 * - optional start_time / end_time / promoted_object included only when set
 * - pause() and enable() POST the right status
 * - list() with and without campaignId filter
 * - get() fetches the correct ad set endpoint with detail fields
 */
class AdSetTest extends TestCase
{
    private const FAKE_AD_ACCOUNT_ID = 'act_111222333';
    private const FAKE_CAMPAIGN_ID   = '111000111';
    private const FAKE_AD_SET_ID     = '222000222';

    protected function tearDown(): void
    {
        Client::reset();
    }

    // -------------------------------------------------------------------------
    // create()
    // -------------------------------------------------------------------------

    public function testCreatePostsToAdSetsEndpoint(): void
    {
        $capture = new \stdClass();
        $capture->endpoint = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        $adSet = new AdSet();
        $adSet->create(self::FAKE_CAMPAIGN_ID, $this->baseCreateConfig());

        $this->assertSame(self::FAKE_AD_ACCOUNT_ID . '/adsets', $capture->endpoint);
    }

    public function testCreateIncludesCampaignId(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        $adSet = new AdSet();
        $adSet->create(self::FAKE_CAMPAIGN_ID, $this->baseCreateConfig());

        $this->assertSame(self::FAKE_CAMPAIGN_ID, $capture->data['campaign_id']);
    }

    public function testCreateIncludesName(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        $adSet = new AdSet();
        $adSet->create(self::FAKE_CAMPAIGN_ID, ['name' => 'AU — 30-60'] + $this->baseCreateConfig());

        $this->assertSame('AU — 30-60', $capture->data['name']);
    }

    public function testCreateIncludesDailyBudget(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        $adSet = new AdSet();
        $adSet->create(self::FAKE_CAMPAIGN_ID, ['daily_budget' => 1000] + $this->baseCreateConfig());

        $this->assertSame(1000, $capture->data['daily_budget']);
    }

    public function testCreateIncludesOptimizationGoal(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        $adSet = new AdSet();
        $adSet->create(self::FAKE_CAMPAIGN_ID, ['optimization_goal' => 'LINK_CLICKS'] + $this->baseCreateConfig());

        $this->assertSame('LINK_CLICKS', $capture->data['optimization_goal']);
    }

    public function testCreateDefaultsBillingEventToImpressions(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        $adSet = new AdSet();
        $adSet->create(self::FAKE_CAMPAIGN_ID, $this->baseCreateConfig()); // no billing_event

        $this->assertSame('IMPRESSIONS', $capture->data['billing_event']);
    }

    public function testCreateRespectsExplicitBillingEvent(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        $adSet = new AdSet();
        $adSet->create(self::FAKE_CAMPAIGN_ID, ['billing_event' => 'LINK_CLICKS'] + $this->baseCreateConfig());

        $this->assertSame('LINK_CLICKS', $capture->data['billing_event']);
    }

    public function testCreateJsonEncodesTargeting(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        $targeting = [
            'geo_locations' => ['countries' => ['AU']],
            'age_min'       => 30,
            'age_max'       => 60,
        ];

        $adSet = new AdSet();
        $adSet->create(self::FAKE_CAMPAIGN_ID, ['targeting' => $targeting] + $this->baseCreateConfig());

        $this->assertSame(json_encode($targeting), $capture->data['targeting']);
    }

    public function testCreateDefaultsStatusToPaused(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        $adSet = new AdSet();
        $adSet->create(self::FAKE_CAMPAIGN_ID, $this->baseCreateConfig()); // no status

        $this->assertSame('PAUSED', $capture->data['status']);
    }

    public function testCreateIncludesStartTimeWhenProvided(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        $adSet = new AdSet();
        $adSet->create(self::FAKE_CAMPAIGN_ID, ['start_time' => '2026-04-01T00:00:00+1100'] + $this->baseCreateConfig());

        $this->assertArrayHasKey('start_time', $capture->data);
        $this->assertSame('2026-04-01T00:00:00+1100', $capture->data['start_time']);
    }

    public function testCreateOmitsStartTimeWhenNotProvided(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        $adSet = new AdSet();
        $adSet->create(self::FAKE_CAMPAIGN_ID, $this->baseCreateConfig());

        $this->assertArrayNotHasKey('start_time', $capture->data);
    }

    public function testCreateIncludesEndTimeWhenProvided(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        $adSet = new AdSet();
        $adSet->create(self::FAKE_CAMPAIGN_ID, ['end_time' => '2026-05-01T00:00:00+1100'] + $this->baseCreateConfig());

        $this->assertArrayHasKey('end_time', $capture->data);
    }

    public function testCreateJsonEncodesPromotedObjectWhenProvided(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        $promotedObject = ['pixel_id' => '12345', 'custom_event_type' => 'PURCHASE'];

        $adSet = new AdSet();
        $adSet->create(self::FAKE_CAMPAIGN_ID, ['promoted_object' => $promotedObject] + $this->baseCreateConfig());

        $this->assertArrayHasKey('promoted_object', $capture->data);
        $this->assertSame(json_encode($promotedObject), $capture->data['promoted_object']);
    }

    public function testCreateOmitsPromotedObjectWhenNotProvided(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        $adSet = new AdSet();
        $adSet->create(self::FAKE_CAMPAIGN_ID, $this->baseCreateConfig());

        $this->assertArrayNotHasKey('promoted_object', $capture->data);
    }

    public function testCreateReturnsIdFromResponse(): void
    {
        $mock = $this->createMock(Client::class);
        $mock->method('adAccountId')->willReturn(self::FAKE_AD_ACCOUNT_ID);
        $mock->method('post')->willReturn(['id' => self::FAKE_AD_SET_ID]);
        $mock->method('get_api')->willReturn(['data' => []]);
        $this->injectMockClient($mock);

        $adSet = new AdSet();
        $result = $adSet->create(self::FAKE_CAMPAIGN_ID, $this->baseCreateConfig());

        $this->assertSame(self::FAKE_AD_SET_ID, $result);
    }

    // -------------------------------------------------------------------------
    // pause() / enable()
    // -------------------------------------------------------------------------

    public function testPausePostsPausedStatusToAdSetId(): void
    {
        $capture = new \stdClass();
        $capture->endpoint = null;
        $capture->data     = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        $adSet = new AdSet();
        $adSet->pause(self::FAKE_AD_SET_ID);

        $this->assertSame(self::FAKE_AD_SET_ID, $capture->endpoint);
        $this->assertSame('PAUSED', $capture->data['status']);
    }

    public function testEnablePostsActiveStatusToAdSetId(): void
    {
        $capture = new \stdClass();
        $capture->endpoint = null;
        $capture->data     = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        $adSet = new AdSet();
        $adSet->enable(self::FAKE_AD_SET_ID);

        $this->assertSame(self::FAKE_AD_SET_ID, $capture->endpoint);
        $this->assertSame('ACTIVE', $capture->data['status']);
    }

    // -------------------------------------------------------------------------
    // list()
    // -------------------------------------------------------------------------

    public function testListCallsAccountAdSetsEndpoint(): void
    {
        $capture = new \stdClass();
        $capture->endpoint = null;

        $this->injectMockClient($this->buildCapturingGetApiMock($capture));

        $adSet = new AdSet();
        $adSet->list();

        $this->assertSame(self::FAKE_AD_ACCOUNT_ID . '/adsets', $capture->endpoint);
    }

    public function testListIncludesFieldsAndLimitParams(): void
    {
        $capture = new \stdClass();
        $capture->params = null;

        $this->injectMockClient($this->buildCapturingGetApiMock($capture));

        $adSet = new AdSet();
        $adSet->list();

        $this->assertArrayHasKey('fields', $capture->params);
        $this->assertArrayHasKey('limit', $capture->params);
        $this->assertStringContainsString('name', $capture->params['fields']);
        $this->assertStringContainsString('status', $capture->params['fields']);
        $this->assertStringContainsString('targeting', $capture->params['fields']);
    }

    public function testListWithCampaignIdAddsFilteringParam(): void
    {
        $capture = new \stdClass();
        $capture->params = null;

        $this->injectMockClient($this->buildCapturingGetApiMock($capture));

        $adSet = new AdSet();
        $adSet->list(self::FAKE_CAMPAIGN_ID);

        $this->assertArrayHasKey('filtering', $capture->params);

        $filtering = json_decode($capture->params['filtering'], true);
        $this->assertSame('campaign.id', $filtering[0]['field']);
        $this->assertSame('EQUAL', $filtering[0]['operator']);
        $this->assertSame(self::FAKE_CAMPAIGN_ID, $filtering[0]['value']);
    }

    public function testListWithoutCampaignIdOmitsFilteringParam(): void
    {
        $capture = new \stdClass();
        $capture->params = null;

        $this->injectMockClient($this->buildCapturingGetApiMock($capture));

        $adSet = new AdSet();
        $adSet->list(); // no campaignId

        $this->assertArrayNotHasKey('filtering', $capture->params);
    }

    public function testListReturnsDataArray(): void
    {
        $fakeData = [['id' => '1', 'name' => 'AdSet One']];

        $mock = $this->createMock(Client::class);
        $mock->method('adAccountId')->willReturn(self::FAKE_AD_ACCOUNT_ID);
        $mock->method('get_api')->willReturn(['data' => $fakeData]);
        $mock->method('post')->willReturn(['id' => self::FAKE_AD_SET_ID]);
        $this->injectMockClient($mock);

        $adSet = new AdSet();
        $this->assertSame($fakeData, $adSet->list());
    }

    // -------------------------------------------------------------------------
    // get()
    // -------------------------------------------------------------------------

    public function testGetCallsAdSetIdEndpoint(): void
    {
        $capture = new \stdClass();
        $capture->endpoint = null;

        $this->injectMockClient($this->buildCapturingGetApiMock($capture));

        $adSet = new AdSet();
        $adSet->get(self::FAKE_AD_SET_ID);

        $this->assertSame(self::FAKE_AD_SET_ID, $capture->endpoint);
    }

    public function testGetIncludesCampaignIdField(): void
    {
        $capture = new \stdClass();
        $capture->params = null;

        $this->injectMockClient($this->buildCapturingGetApiMock($capture));

        $adSet = new AdSet();
        $adSet->get(self::FAKE_AD_SET_ID);

        $this->assertArrayHasKey('fields', $capture->params);
        $this->assertStringContainsString('campaign_id', $capture->params['fields']);
    }

    // -------------------------------------------------------------------------
    // update()
    // -------------------------------------------------------------------------

    public function testUpdatePostsToAdSetIdEndpoint(): void
    {
        $capture = new \stdClass();
        $capture->endpoint = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        $adSet = new AdSet();
        $adSet->update(self::FAKE_AD_SET_ID, ['status' => 'ACTIVE']);

        $this->assertSame(self::FAKE_AD_SET_ID, $capture->endpoint);
    }

    public function testUpdatePassesDataFieldsToClient(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        $adSet = new AdSet();
        $adSet->update(self::FAKE_AD_SET_ID, ['daily_budget' => 2000, 'status' => 'ACTIVE']);

        $this->assertSame(2000, $capture->data['daily_budget']);
        $this->assertSame('ACTIVE', $capture->data['status']);
    }

    public function testUpdateReturnsVoid(): void
    {
        $this->injectMockClient($this->buildCapturingPostMock(new \stdClass()));

        $adSet = new AdSet();
        $result = $adSet->update(self::FAKE_AD_SET_ID, ['status' => 'PAUSED']);

        $this->assertNull($result);
    }

    // -------------------------------------------------------------------------
    // buildPlacements()
    // -------------------------------------------------------------------------

    public function testBuildPlacementsIncludesFacebookAndInstagram(): void
    {
        $placements = AdSet::buildPlacements();

        $this->assertContains('facebook', $placements['publisher_platforms']);
        $this->assertContains('instagram', $placements['publisher_platforms']);
    }

    public function testBuildPlacementsExcludesAudienceNetworkByDefault(): void
    {
        $placements = AdSet::buildPlacements();

        $this->assertNotContains('audience_network', $placements['publisher_platforms']);
    }

    public function testBuildPlacementsIncludesAudienceNetworkWhenRequested(): void
    {
        $placements = AdSet::buildPlacements(['exclude_audience_network' => false]);

        $this->assertContains('audience_network', $placements['publisher_platforms']);
    }

    public function testBuildPlacementsExcludesMessengerByDefault(): void
    {
        $placements = AdSet::buildPlacements();

        $this->assertNotContains('messenger', $placements['publisher_platforms']);
        $this->assertArrayNotHasKey('messenger_positions', $placements);
    }

    public function testBuildPlacementsIncludesMessengerWhenRequested(): void
    {
        $placements = AdSet::buildPlacements(['include_messenger' => true]);

        $this->assertContains('messenger', $placements['publisher_platforms']);
        $this->assertArrayHasKey('messenger_positions', $placements);
    }

    public function testBuildPlacementsIncludesFacebookFeedPosition(): void
    {
        $placements = AdSet::buildPlacements();

        $this->assertContains('feed', $placements['facebook_positions']);
    }

    public function testBuildPlacementsIncludesInstagramPositions(): void
    {
        $placements = AdSet::buildPlacements();

        $this->assertContains('stream', $placements['instagram_positions']);
        $this->assertContains('story', $placements['instagram_positions']);
        $this->assertContains('reels', $placements['instagram_positions']);
    }

    // -------------------------------------------------------------------------
    // excludeAudienceNetwork()
    // -------------------------------------------------------------------------

    public function testExcludeAudienceNetworkRemovesAudienceNetworkFromPlatforms(): void
    {
        $targeting = [
            'publisher_platforms' => ['facebook', 'instagram', 'audience_network'],
        ];

        $result = AdSet::excludeAudienceNetwork($targeting);

        $this->assertNotContains('audience_network', $result['publisher_platforms']);
    }

    public function testExcludeAudienceNetworkPreservesFacebookAndInstagram(): void
    {
        $targeting = [
            'publisher_platforms' => ['facebook', 'instagram', 'audience_network'],
        ];

        $result = AdSet::excludeAudienceNetwork($targeting);

        $this->assertContains('facebook', $result['publisher_platforms']);
        $this->assertContains('instagram', $result['publisher_platforms']);
    }

    public function testExcludeAudienceNetworkIsIdempotentWhenAlreadyExcluded(): void
    {
        $targeting = [
            'publisher_platforms' => ['facebook', 'instagram'],
        ];

        $result = AdSet::excludeAudienceNetwork($targeting);

        $this->assertSame(['facebook', 'instagram'], $result['publisher_platforms']);
    }

    public function testExcludeAudienceNetworkReturnsUnchangedWhenNoPlatformsKey(): void
    {
        $targeting = [
            'geo_locations' => ['countries' => ['AU']],
        ];

        $result = AdSet::excludeAudienceNetwork($targeting);

        $this->assertArrayNotHasKey('publisher_platforms', $result);
        $this->assertSame($targeting, $result);
    }

    public function testExcludeAudienceNetworkReindexesArray(): void
    {
        $targeting = [
            'publisher_platforms' => ['facebook', 'audience_network', 'instagram'],
        ];

        $result = AdSet::excludeAudienceNetwork($targeting);

        // Array should be re-indexed (no gaps)
        $this->assertSame([0, 1], array_keys($result['publisher_platforms']));
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function baseCreateConfig(): array
    {
        return [
            'name'              => 'Test Ad Set',
            'daily_budget'      => 1000,
            'optimization_goal' => 'LINK_CLICKS',
            'targeting'         => ['geo_locations' => ['countries' => ['AU']]],
        ];
    }

    private function buildCapturingPostMock(\stdClass $capture): Client
    {
        $mock = $this->createMock(Client::class);
        $mock->method('adAccountId')->willReturn(self::FAKE_AD_ACCOUNT_ID);
        $mock->method('post')
             ->willReturnCallback(function (string $endpoint, array $data) use ($capture): array {
                 $capture->endpoint = $endpoint;
                 $capture->data     = $data;
                 return ['id' => self::FAKE_AD_SET_ID];
             });
        $mock->method('get_api')->willReturn(['data' => []]);
        return $mock;
    }

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
        $mock->method('post')->willReturn(['id' => self::FAKE_AD_SET_ID]);
        return $mock;
    }

    private function injectMockClient(Client $client): void
    {
        $ref = new \ReflectionProperty(Client::class, 'instance');
        $ref->setAccessible(true);
        $ref->setValue(null, $client);
    }
}
