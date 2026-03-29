<?php

declare(strict_types=1);

namespace AdManager\Tests\Meta;

use AdManager\Meta\Ad;
use AdManager\Meta\Client;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Meta\Ad.
 *
 * Ad manages ads and ad creatives via Client::post() and Client::get_api().
 * We inject a mock Client via the singleton slot and verify:
 * - create() posts to the correct endpoint with adset_id, creative, name, status
 * - creative field is JSON-encoded with creative_id key
 * - status defaults to PAUSED
 * - createCreative() posts to adcreatives endpoint with name and object_story_spec
 * - object_story_spec is JSON-encoded
 * - optional url_tags included only when provided
 * - list() with and without adSetId filter
 * - get() fetches correct ad with tracking_specs field
 * - listCreatives() hits adcreatives endpoint
 */
class AdTest extends TestCase
{
    private const FAKE_AD_ACCOUNT_ID = 'act_111222333';
    private const FAKE_AD_SET_ID     = '222000222';
    private const FAKE_AD_ID         = '333000333';
    private const FAKE_CREATIVE_ID   = '444000444';

    protected function tearDown(): void
    {
        Client::reset();
    }

    // -------------------------------------------------------------------------
    // create()
    // -------------------------------------------------------------------------

    public function testCreatePostsToAdsEndpoint(): void
    {
        $capture = new \stdClass();
        $capture->endpoint = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        $ad = new Ad();
        $ad->create(self::FAKE_AD_SET_ID, self::FAKE_CREATIVE_ID, 'Test Ad');

        $this->assertSame(self::FAKE_AD_ACCOUNT_ID . '/ads', $capture->endpoint);
    }

    public function testCreateIncludesAdSetId(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        $ad = new Ad();
        $ad->create(self::FAKE_AD_SET_ID, self::FAKE_CREATIVE_ID, 'Test Ad');

        $this->assertSame(self::FAKE_AD_SET_ID, $capture->data['adset_id']);
    }

    public function testCreateJsonEncodesCreativeWithCreativeId(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        $ad = new Ad();
        $ad->create(self::FAKE_AD_SET_ID, self::FAKE_CREATIVE_ID, 'Test Ad');

        $this->assertArrayHasKey('creative', $capture->data);
        $expectedCreative = json_encode(['creative_id' => self::FAKE_CREATIVE_ID]);
        $this->assertSame($expectedCreative, $capture->data['creative']);
    }

    public function testCreateIncludesAdName(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        $ad = new Ad();
        $ad->create(self::FAKE_AD_SET_ID, self::FAKE_CREATIVE_ID, 'My Ad Name');

        $this->assertSame('My Ad Name', $capture->data['name']);
    }

    public function testCreateDefaultsStatusToPaused(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        $ad = new Ad();
        $ad->create(self::FAKE_AD_SET_ID, self::FAKE_CREATIVE_ID, 'Test Ad');

        $this->assertSame('PAUSED', $capture->data['status']);
    }

    public function testCreateRespectsExplicitStatus(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        $ad = new Ad();
        $ad->create(self::FAKE_AD_SET_ID, self::FAKE_CREATIVE_ID, 'Test Ad', 'ACTIVE');

        $this->assertSame('ACTIVE', $capture->data['status']);
    }

    public function testCreateReturnsIdFromResponse(): void
    {
        $mock = $this->createMock(Client::class);
        $mock->method('adAccountId')->willReturn(self::FAKE_AD_ACCOUNT_ID);
        $mock->method('post')->willReturn(['id' => self::FAKE_AD_ID]);
        $mock->method('get_api')->willReturn(['data' => []]);
        $this->injectMockClient($mock);

        $ad = new Ad();
        $result = $ad->create(self::FAKE_AD_SET_ID, self::FAKE_CREATIVE_ID, 'Test Ad');

        $this->assertSame(self::FAKE_AD_ID, $result);
    }

    // -------------------------------------------------------------------------
    // createCreative()
    // -------------------------------------------------------------------------

    public function testCreateCreativePostsToAdCreativesEndpoint(): void
    {
        $capture = new \stdClass();
        $capture->endpoint = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        $ad = new Ad();
        $ad->createCreative($this->baseImageCreativeConfig());

        $this->assertSame(self::FAKE_AD_ACCOUNT_ID . '/adcreatives', $capture->endpoint);
    }

    public function testCreateCreativeIncludesName(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        $ad = new Ad();
        $ad->createCreative(['name' => 'Creative v1'] + $this->baseImageCreativeConfig());

        $this->assertSame('Creative v1', $capture->data['name']);
    }

    public function testCreateCreativeJsonEncodesObjectStorySpec(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        $spec = [
            'page_id'   => '123456789',
            'link_data' => ['message' => 'Get your free audit!', 'link' => 'https://auditandfix.com'],
        ];

        $ad = new Ad();
        $ad->createCreative(['object_story_spec' => $spec] + $this->baseImageCreativeConfig());

        $this->assertArrayHasKey('object_story_spec', $capture->data);
        $this->assertSame(json_encode($spec), $capture->data['object_story_spec']);
    }

    public function testCreateCreativeIncludesUrlTagsWhenProvided(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        $ad = new Ad();
        $ad->createCreative(['url_tags' => 'utm_source=facebook'] + $this->baseImageCreativeConfig());

        $this->assertArrayHasKey('url_tags', $capture->data);
        $this->assertSame('utm_source=facebook', $capture->data['url_tags']);
    }

    public function testCreateCreativeOmitsUrlTagsWhenNotProvided(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        $ad = new Ad();
        $ad->createCreative($this->baseImageCreativeConfig()); // no url_tags

        $this->assertArrayNotHasKey('url_tags', $capture->data);
    }

    public function testCreateCreativeReturnsIdFromResponse(): void
    {
        $mock = $this->createMock(Client::class);
        $mock->method('adAccountId')->willReturn(self::FAKE_AD_ACCOUNT_ID);
        $mock->method('post')->willReturn(['id' => self::FAKE_CREATIVE_ID]);
        $mock->method('get_api')->willReturn(['data' => []]);
        $this->injectMockClient($mock);

        $ad = new Ad();
        $result = $ad->createCreative($this->baseImageCreativeConfig());

        $this->assertSame(self::FAKE_CREATIVE_ID, $result);
    }

    public function testCreateCreativeSupportsVideoCreativeSpec(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        $videoConfig = [
            'name' => 'Creative — Video — v1',
            'object_story_spec' => [
                'page_id'    => '123456789',
                'video_data' => [
                    'video_id' => '987654321',
                    'message'  => 'Watch how we improved this site...',
                    'title'    => 'Free Website Audit',
                ],
            ],
        ];

        $ad = new Ad();
        $ad->createCreative($videoConfig);

        $spec = json_decode($capture->data['object_story_spec'], true);
        $this->assertArrayHasKey('video_data', $spec);
        $this->assertSame('987654321', $spec['video_data']['video_id']);
    }

    public function testCreateCreativeSupportsCarouselSpec(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        $carouselConfig = [
            'name' => 'Creative — Carousel — v1',
            'object_story_spec' => [
                'page_id'   => '123456789',
                'link_data' => [
                    'message'           => 'See our success stories',
                    'child_attachments' => [
                        ['link' => 'https://auditandfix.com/1', 'image_hash' => 'abc', 'name' => 'Card 1'],
                        ['link' => 'https://auditandfix.com/2', 'image_hash' => 'def', 'name' => 'Card 2'],
                    ],
                ],
            ],
        ];

        $ad = new Ad();
        $ad->createCreative($carouselConfig);

        $spec = json_decode($capture->data['object_story_spec'], true);
        $this->assertCount(2, $spec['link_data']['child_attachments']);
    }

    // -------------------------------------------------------------------------
    // list()
    // -------------------------------------------------------------------------

    public function testListCallsAccountAdsEndpoint(): void
    {
        $capture = new \stdClass();
        $capture->endpoint = null;

        $this->injectMockClient($this->buildCapturingGetApiMock($capture));

        $ad = new Ad();
        $ad->list();

        $this->assertSame(self::FAKE_AD_ACCOUNT_ID . '/ads', $capture->endpoint);
    }

    public function testListIncludesFieldsParam(): void
    {
        $capture = new \stdClass();
        $capture->params = null;

        $this->injectMockClient($this->buildCapturingGetApiMock($capture));

        $ad = new Ad();
        $ad->list();

        $this->assertArrayHasKey('fields', $capture->params);
        $this->assertStringContainsString('name', $capture->params['fields']);
        $this->assertStringContainsString('status', $capture->params['fields']);
        $this->assertStringContainsString('creative', $capture->params['fields']);
    }

    public function testListWithAdSetIdAddsFilteringParam(): void
    {
        $capture = new \stdClass();
        $capture->params = null;

        $this->injectMockClient($this->buildCapturingGetApiMock($capture));

        $ad = new Ad();
        $ad->list(self::FAKE_AD_SET_ID);

        $this->assertArrayHasKey('filtering', $capture->params);

        $filtering = json_decode($capture->params['filtering'], true);
        $this->assertSame('adset.id', $filtering[0]['field']);
        $this->assertSame('EQUAL', $filtering[0]['operator']);
        $this->assertSame(self::FAKE_AD_SET_ID, $filtering[0]['value']);
    }

    public function testListWithoutAdSetIdOmitsFilteringParam(): void
    {
        $capture = new \stdClass();
        $capture->params = null;

        $this->injectMockClient($this->buildCapturingGetApiMock($capture));

        $ad = new Ad();
        $ad->list();

        $this->assertArrayNotHasKey('filtering', $capture->params);
    }

    public function testListReturnsDataArray(): void
    {
        $fakeData = [['id' => '1', 'name' => 'Ad One']];

        $mock = $this->createMock(Client::class);
        $mock->method('adAccountId')->willReturn(self::FAKE_AD_ACCOUNT_ID);
        $mock->method('get_api')->willReturn(['data' => $fakeData]);
        $mock->method('post')->willReturn(['id' => self::FAKE_AD_ID]);
        $this->injectMockClient($mock);

        $ad = new Ad();
        $this->assertSame($fakeData, $ad->list());
    }

    // -------------------------------------------------------------------------
    // get()
    // -------------------------------------------------------------------------

    public function testGetCallsAdIdEndpoint(): void
    {
        $capture = new \stdClass();
        $capture->endpoint = null;

        $this->injectMockClient($this->buildCapturingGetApiMock($capture));

        $ad = new Ad();
        $ad->get(self::FAKE_AD_ID);

        $this->assertSame(self::FAKE_AD_ID, $capture->endpoint);
    }

    public function testGetIncludesTrackingSpecsField(): void
    {
        $capture = new \stdClass();
        $capture->params = null;

        $this->injectMockClient($this->buildCapturingGetApiMock($capture));

        $ad = new Ad();
        $ad->get(self::FAKE_AD_ID);

        $this->assertArrayHasKey('fields', $capture->params);
        $this->assertStringContainsString('tracking_specs', $capture->params['fields']);
    }

    // -------------------------------------------------------------------------
    // listCreatives()
    // -------------------------------------------------------------------------

    public function testListCreativesCallsAdCreativesEndpoint(): void
    {
        $capture = new \stdClass();
        $capture->endpoint = null;

        $this->injectMockClient($this->buildCapturingGetApiMock($capture));

        $ad = new Ad();
        $ad->listCreatives();

        $this->assertSame(self::FAKE_AD_ACCOUNT_ID . '/adcreatives', $capture->endpoint);
    }

    public function testListCreativesIncludesObjectStorySpecField(): void
    {
        $capture = new \stdClass();
        $capture->params = null;

        $this->injectMockClient($this->buildCapturingGetApiMock($capture));

        $ad = new Ad();
        $ad->listCreatives();

        $this->assertArrayHasKey('fields', $capture->params);
        $this->assertStringContainsString('object_story_spec', $capture->params['fields']);
    }

    public function testListCreativesReturnsDataArray(): void
    {
        $fakeData = [['id' => '1', 'name' => 'Creative One']];

        $mock = $this->createMock(Client::class);
        $mock->method('adAccountId')->willReturn(self::FAKE_AD_ACCOUNT_ID);
        $mock->method('get_api')->willReturn(['data' => $fakeData]);
        $mock->method('post')->willReturn(['id' => self::FAKE_AD_ID]);
        $this->injectMockClient($mock);

        $ad = new Ad();
        $this->assertSame($fakeData, $ad->listCreatives());
    }

    // -------------------------------------------------------------------------
    // update()
    // -------------------------------------------------------------------------

    public function testUpdatePostsToAdIdEndpoint(): void
    {
        $capture = new \stdClass();
        $capture->endpoint = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        $ad = new Ad();
        $ad->update(self::FAKE_AD_ID, ['status' => 'ACTIVE']);

        $this->assertSame(self::FAKE_AD_ID, $capture->endpoint);
    }

    public function testUpdatePassesDataFieldsToClient(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        $newCreative = json_encode(['creative_id' => 'new_creative_999']);
        $ad = new Ad();
        $ad->update(self::FAKE_AD_ID, ['creative' => $newCreative, 'status' => 'PAUSED']);

        $this->assertSame($newCreative, $capture->data['creative']);
        $this->assertSame('PAUSED', $capture->data['status']);
    }

    public function testUpdateCanSwapCreative(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        $newCreativeId = 'creative_v2_888';
        $ad = new Ad();
        $ad->update(self::FAKE_AD_ID, [
            'creative' => json_encode(['creative_id' => $newCreativeId]),
        ]);

        $decoded = json_decode($capture->data['creative'], true);
        $this->assertSame($newCreativeId, $decoded['creative_id']);
    }

    public function testUpdateReturnsVoid(): void
    {
        $this->injectMockClient($this->buildCapturingPostMock(new \stdClass()));

        $ad = new Ad();
        $result = $ad->update(self::FAKE_AD_ID, ['status' => 'PAUSED']);

        $this->assertNull($result);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function baseImageCreativeConfig(): array
    {
        return [
            'name' => 'Creative — Image — v1',
            'object_story_spec' => [
                'page_id'   => '123456789',
                'link_data' => [
                    'message'    => 'Get your free website audit!',
                    'link'       => 'https://auditandfix.com',
                    'image_hash' => 'abc123hash',
                ],
            ],
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
                 return ['id' => self::FAKE_AD_ID];
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
        $mock->method('post')->willReturn(['id' => self::FAKE_AD_ID]);
        return $mock;
    }

    private function injectMockClient(Client $client): void
    {
        $ref = new \ReflectionProperty(Client::class, 'instance');
        $ref->setAccessible(true);
        $ref->setValue(null, $client);
    }
}
