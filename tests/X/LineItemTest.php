<?php

declare(strict_types=1);

namespace AdManager\Tests\X;

use AdManager\X\Client;
use AdManager\X\LineItem;
use PHPUnit\Framework\TestCase;

/**
 * Tests for X\LineItem.
 *
 * LineItem calls Client methods to manage X ad groups. We verify:
 * - create() POSTs to 'line_items' with campaign_id and required fields
 * - create() defaults entity_status to PAUSED
 * - create() returns line item ID from response['data']['id']
 * - pause() PUTs entity_status=PAUSED to line_items/{id}
 * - enable() PUTs entity_status=ACTIVE to line_items/{id}
 * - pause()/enable() use PUT, not POST
 * - list() GETs 'line_items'
 * - list() includes campaign_ids param when campaignId provided
 * - get() GETs 'line_items/{id}'
 */
class LineItemTest extends TestCase
{
    private const FAKE_ACCOUNT_ID   = 'acc_111222';
    private const FAKE_CAMPAIGN_ID  = 'cmp_987654';
    private const FAKE_LINE_ITEM_ID = 'li_555666';

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

    public function testCreatePostsToLineItemsEndpoint(): void
    {
        $capture = new \stdClass();
        $capture->endpoint = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        $lineItem = new LineItem();
        $lineItem->create(self::FAKE_CAMPAIGN_ID, $this->baseCreateConfig());

        $this->assertSame('line_items', $capture->endpoint);
    }

    public function testCreateIncludesCampaignId(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        $lineItem = new LineItem();
        $lineItem->create(self::FAKE_CAMPAIGN_ID, $this->baseCreateConfig());

        $this->assertSame(self::FAKE_CAMPAIGN_ID, $capture->data['campaign_id']);
    }

    public function testCreateIncludesAllRequiredFields(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        $lineItem = new LineItem();
        $lineItem->create(self::FAKE_CAMPAIGN_ID, $this->baseCreateConfig());

        $this->assertArrayHasKey('name', $capture->data);
        $this->assertArrayHasKey('product_type', $capture->data);
        $this->assertArrayHasKey('placements', $capture->data);
        $this->assertArrayHasKey('objective', $capture->data);
        $this->assertArrayHasKey('bid_amount_local_micro', $capture->data);
    }

    public function testCreateDefaultsEntityStatusToPaused(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        $config = $this->baseCreateConfig();
        unset($config['entity_status']);

        $lineItem = new LineItem();
        $lineItem->create(self::FAKE_CAMPAIGN_ID, $config);

        $this->assertSame('PAUSED', $capture->data['entity_status']);
    }

    public function testCreateReturnsLineItemId(): void
    {
        $mock = $this->createMock(Client::class);
        $mock->method('accountId')->willReturn(self::FAKE_ACCOUNT_ID);
        $mock->method('post')->willReturn(['data' => ['id' => self::FAKE_LINE_ITEM_ID]]);
        $mock->method('get_api')->willReturn(['data' => []]);
        $mock->method('put')->willReturn([]);
        $this->injectMockClient($mock);

        $lineItem = new LineItem();
        $result   = $lineItem->create(self::FAKE_CAMPAIGN_ID, $this->baseCreateConfig());

        $this->assertSame(self::FAKE_LINE_ITEM_ID, $result);
    }

    // -------------------------------------------------------------------------
    // pause() / enable()
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
        $mock->method('post')->willReturn(['data' => ['id' => self::FAKE_LINE_ITEM_ID]]);
        $mock->method('get_api')->willReturn(['data' => []]);
        $this->injectMockClient($mock);

        $lineItem = new LineItem();
        $lineItem->pause(self::FAKE_LINE_ITEM_ID);

        $this->assertSame('line_items/' . self::FAKE_LINE_ITEM_ID, $capture->endpoint);
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
        $mock->method('post')->willReturn(['data' => ['id' => self::FAKE_LINE_ITEM_ID]]);
        $mock->method('get_api')->willReturn(['data' => []]);
        $this->injectMockClient($mock);

        $lineItem = new LineItem();
        $lineItem->enable(self::FAKE_LINE_ITEM_ID);

        $this->assertSame('line_items/' . self::FAKE_LINE_ITEM_ID, $capture->endpoint);
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

        $lineItem = new LineItem();
        $lineItem->pause(self::FAKE_LINE_ITEM_ID);

        $this->assertTrue($putCalled);
        $this->assertFalse($postCalled);
    }

    // -------------------------------------------------------------------------
    // list()
    // -------------------------------------------------------------------------

    public function testListGetsLineItemsEndpoint(): void
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
        $mock->method('post')->willReturn(['data' => ['id' => self::FAKE_LINE_ITEM_ID]]);
        $mock->method('put')->willReturn([]);
        $this->injectMockClient($mock);

        $lineItem = new LineItem();
        $lineItem->list();

        $this->assertSame('line_items', $capture->endpoint);
    }

    public function testListIncludesCampaignIdsWhenProvided(): void
    {
        $capture = new \stdClass();
        $capture->params = null;

        $mock = $this->createMock(Client::class);
        $mock->method('accountId')->willReturn(self::FAKE_ACCOUNT_ID);
        $mock->method('get_api')
             ->willReturnCallback(function (string $endpoint, array $params) use ($capture): array {
                 $capture->params = $params;
                 return ['data' => []];
             });
        $mock->method('post')->willReturn(['data' => ['id' => self::FAKE_LINE_ITEM_ID]]);
        $mock->method('put')->willReturn([]);
        $this->injectMockClient($mock);

        $lineItem = new LineItem();
        $lineItem->list(self::FAKE_CAMPAIGN_ID);

        $this->assertArrayHasKey('campaign_ids', $capture->params);
        $this->assertSame(self::FAKE_CAMPAIGN_ID, $capture->params['campaign_ids']);
    }

    public function testListReturnsDataArray(): void
    {
        $fakeData = [
            ['id' => 'li_1', 'name' => 'Line Item One'],
        ];

        $mock = $this->createMock(Client::class);
        $mock->method('accountId')->willReturn(self::FAKE_ACCOUNT_ID);
        $mock->method('get_api')->willReturn(['data' => $fakeData]);
        $mock->method('post')->willReturn(['data' => ['id' => self::FAKE_LINE_ITEM_ID]]);
        $mock->method('put')->willReturn([]);
        $this->injectMockClient($mock);

        $lineItem = new LineItem();
        $result   = $lineItem->list();

        $this->assertSame($fakeData, $result);
    }

    // -------------------------------------------------------------------------
    // get()
    // -------------------------------------------------------------------------

    public function testGetCallsLineItemEndpoint(): void
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
        $mock->method('post')->willReturn(['data' => ['id' => self::FAKE_LINE_ITEM_ID]]);
        $mock->method('put')->willReturn([]);
        $this->injectMockClient($mock);

        $lineItem = new LineItem();
        $lineItem->get(self::FAKE_LINE_ITEM_ID);

        $this->assertSame('line_items/' . self::FAKE_LINE_ITEM_ID, $capture->endpoint);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function baseCreateConfig(): array
    {
        return [
            'name'                   => 'Test Line Item',
            'product_type'           => 'PROMOTED_TWEETS',
            'placements'             => ['ALL_ON_TWITTER'],
            'objective'              => 'WEBSITE_CLICKS',
            'bid_amount_local_micro' => 1_500_000,
            'entity_status'          => 'PAUSED',
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
                 return ['data' => ['id' => self::FAKE_LINE_ITEM_ID]];
             });
        $mock->method('get_api')->willReturn(['data' => []]);
        $mock->method('put')->willReturn([]);
        return $mock;
    }

    private function buildPassthroughClientMock(): Client
    {
        $mock = $this->createMock(Client::class);
        $mock->method('accountId')->willReturn(self::FAKE_ACCOUNT_ID);
        $mock->method('post')->willReturn(['data' => ['id' => self::FAKE_LINE_ITEM_ID]]);
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
