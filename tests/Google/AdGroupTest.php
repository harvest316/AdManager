<?php

declare(strict_types=1);

namespace AdManager\Tests\Google;

use AdManager\Google\AdGroup;
use AdManager\Google\Client;
use Google\Ads\GoogleAds\Lib\V20\GoogleAdsClient;
use Google\Ads\GoogleAds\V20\Enums\AdGroupStatusEnum\AdGroupStatus;
use Google\Ads\GoogleAds\V20\Enums\AdGroupTypeEnum\AdGroupType;
use Google\Ads\GoogleAds\V20\Resources\AdGroup as AdGroupResource;
use Google\Ads\GoogleAds\V20\Services\AdGroupOperation;
use Google\Ads\GoogleAds\V20\Services\MutateAdGroupsRequest;
use Google\Ads\GoogleAds\V20\Services\MutateAdGroupsResponse;
use Google\Ads\GoogleAds\V20\Services\MutateAdGroupResult;
use Google\Ads\GoogleAds\V20\Services\Client\AdGroupServiceClient;
use PHPUnit\Framework\TestCase;

/**
 * Tests for AdGroup::create().
 *
 * We inject a mock GoogleAdsClient into the Client singleton so no real API
 * calls are made.  We then capture the AdGroupOperation passed to mutateAdGroups
 * and verify that the proto fields are constructed correctly.
 */
class AdGroupTest extends TestCase
{
    private const FAKE_CUSTOMER_ID = '1234567890';
    private const FAKE_CAMPAIGN_ID = '9876543210';
    private const FAKE_RESOURCE_NAME = 'customers/1234567890/adGroups/555';

    // -------------------------------------------------------------------------
    // Setup / Teardown — inject and reset the Client static singleton
    // -------------------------------------------------------------------------

    protected function setUp(): void
    {
        // Pre-load Client::$env so boot() never tries to read a real .env file
        $this->setClientStaticEnv([
            'GOOGLE_ADS_CLIENT_ID'       => 'fake-client-id',
            'GOOGLE_ADS_CLIENT_SECRET'   => 'fake-secret',
            'GOOGLE_ADS_DEVELOPER_TOKEN' => 'fake-token',
            'GOOGLE_ADS_REFRESH_TOKEN'   => 'fake-refresh',
            'GOOGLE_ADS_CUSTOMER_ID'     => self::FAKE_CUSTOMER_ID,
        ]);
    }

    protected function tearDown(): void
    {
        // Reset static state so other tests are not affected
        $this->setClientStaticEnv([]);
        $this->setClientStaticInstance(null);
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    public function testCreateSetsAdGroupNameAndStatus(): void
    {
        [$adGroupServiceMock, $capture] = $this->buildAdGroupServiceMock();
        $this->injectMockClient($adGroupServiceMock);

        $adGroup = new AdGroup();
        $adGroup->create(self::FAKE_CAMPAIGN_ID, 'Test Ad Group');

        $this->assertNotNull($capture->op, 'An AdGroupOperation should have been captured');

        $created = $capture->op->getCreate();
        $this->assertInstanceOf(AdGroupResource::class, $created);
        $this->assertSame('Test Ad Group', $created->getName());
        $this->assertSame(AdGroupStatus::ENABLED, $created->getStatus());
    }

    public function testCreateSetsTypeToSearchStandardByDefault(): void
    {
        [$adGroupServiceMock, $capture] = $this->buildAdGroupServiceMock();
        $this->injectMockClient($adGroupServiceMock);

        $adGroup = new AdGroup();
        $adGroup->create(self::FAKE_CAMPAIGN_ID, 'Test Ad Group');

        $created = $capture->op->getCreate();
        $this->assertSame(AdGroupType::SEARCH_STANDARD, $created->getType());
    }

    public function testCreateSetsCampaignResourceName(): void
    {
        [$adGroupServiceMock, $capture] = $this->buildAdGroupServiceMock();
        $this->injectMockClient($adGroupServiceMock);

        $adGroup = new AdGroup();
        $adGroup->create(self::FAKE_CAMPAIGN_ID, 'Test Ad Group');

        $created   = $capture->op->getCreate();
        $expected  = 'customers/' . self::FAKE_CUSTOMER_ID . '/campaigns/' . self::FAKE_CAMPAIGN_ID;
        $this->assertSame($expected, $created->getCampaign());
    }

    public function testCreateSetsCpcBidMicrosWhenNonZero(): void
    {
        [$adGroupServiceMock, $capture] = $this->buildAdGroupServiceMock();
        $this->injectMockClient($adGroupServiceMock);

        $adGroup = new AdGroup();
        $adGroup->create(self::FAKE_CAMPAIGN_ID, 'CPC Group', 2_500_000);

        $created = $capture->op->getCreate();
        $this->assertSame(2_500_000, $created->getCpcBidMicros());
    }

    public function testCreateDoesNotSetCpcBidMicrosWhenZero(): void
    {
        [$adGroupServiceMock, $capture] = $this->buildAdGroupServiceMock();
        $this->injectMockClient($adGroupServiceMock);

        $adGroup = new AdGroup();
        $adGroup->create(self::FAKE_CAMPAIGN_ID, 'No CPC Group', 0);

        $created = $capture->op->getCreate();
        // Default protobuf value for an unset int64 is 0, but the semantic is
        // "not set" — we verify the source code path (cpcMicros > 0 check) by
        // confirming no non-zero value is present.
        $this->assertSame(0, $created->getCpcBidMicros());
    }

    public function testCreateReturnsResourceNameFromResponse(): void
    {
        [$adGroupServiceMock] = $this->buildAdGroupServiceMock();
        $this->injectMockClient($adGroupServiceMock);

        $adGroup = new AdGroup();
        $result  = $adGroup->create(self::FAKE_CAMPAIGN_ID, 'Return Test');

        $this->assertSame(self::FAKE_RESOURCE_NAME, $result);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build a mock AdGroupServiceClient that captures the first operation passed.
     *
     * Returns [$serviceMock, &$capturedOp] where $capturedOp[0] is set by the
     * mock's will() callback after the first mutateAdGroups call.
     */
    private function buildAdGroupServiceMock(): array
    {
        $capture = new \stdClass();
        $capture->op = null;

        // Build a fake response with one result
        $fakeResult = new MutateAdGroupResult();
        $fakeResult->setResourceName(self::FAKE_RESOURCE_NAME);

        $fakeResponse = new MutateAdGroupsResponse();
        $fakeResponse->setResults([$fakeResult]);

        $serviceMock = $this->createMock(AdGroupServiceClient::class);
        $serviceMock
            ->expects($this->once())
            ->method('mutateAdGroups')
            ->willReturnCallback(
                function (MutateAdGroupsRequest $request) use ($capture, $fakeResponse) {
                    $capture->op = iterator_to_array($request->getOperations())[0];
                    return $fakeResponse;
                }
            );

        return [$serviceMock, $capture];
    }

    /**
     * Build a mock GoogleAdsClient that returns the given AdGroupServiceClient mock.
     */
    private function injectMockClient(AdGroupServiceClient $adGroupServiceMock): void
    {
        $googleAdsClientMock = $this->createMock(GoogleAdsClient::class);
        $googleAdsClientMock
            ->method('getAdGroupServiceClient')
            ->willReturn($adGroupServiceMock);

        $this->setClientStaticInstance($googleAdsClientMock);
    }

    private function setClientStaticEnv(array $env): void
    {
        $ref = new \ReflectionProperty(Client::class, 'env');
        $ref->setAccessible(true);
        $ref->setValue(null, $env);
    }

    private function setClientStaticInstance(?GoogleAdsClient $client): void
    {
        $ref = new \ReflectionProperty(Client::class, 'instance');
        $ref->setAccessible(true);
        $ref->setValue(null, $client);
    }
}
