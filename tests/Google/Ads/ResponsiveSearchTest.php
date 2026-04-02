<?php

declare(strict_types=1);

namespace AdManager\Tests\Google\Ads;

use AdManager\Google\Ads\ResponsiveSearch;
use AdManager\Google\Client;
use Google\Ads\GoogleAds\Lib\V20\GoogleAdsClient;
use Google\Ads\GoogleAds\V20\Common\AdTextAsset;
use Google\Ads\GoogleAds\V20\Enums\AdGroupAdStatusEnum\AdGroupAdStatus;
use Google\Ads\GoogleAds\V20\Enums\ServedAssetFieldTypeEnum\ServedAssetFieldType;
use Google\Ads\GoogleAds\V20\Resources\Ad;
use Google\Ads\GoogleAds\V20\Resources\AdGroupAd;
use Google\Ads\GoogleAds\V20\Services\AdGroupAdOperation;
use Google\Ads\GoogleAds\V20\Services\MutateAdGroupAdsRequest;
use Google\Ads\GoogleAds\V20\Services\MutateAdGroupAdsResponse;
use Google\Ads\GoogleAds\V20\Services\MutateAdGroupAdResult;
use Google\Ads\GoogleAds\V20\Services\Client\AdGroupAdServiceClient;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Ads\ResponsiveSearch::create().
 *
 * We verify:
 * - AdGroup resource name is built correctly
 * - Headlines are set with correct text and pinning
 * - Descriptions are set with correct text and pinning
 * - Display paths are set from config
 * - Final URL is set
 * - Status is ENABLED
 * - Return value is the resource name from the response
 */
class ResponsiveSearchTest extends TestCase
{
    private const FAKE_CUSTOMER_ID   = '1234567890';
    private const FAKE_AD_GROUP_ID   = '5556667778';
    private const FAKE_RESOURCE_NAME = 'customers/1234567890/adGroupAds/999~888';

    // -------------------------------------------------------------------------
    // Setup / Teardown
    // -------------------------------------------------------------------------

    protected function setUp(): void
    {
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
        $this->setClientStaticEnv([]);
        $this->setClientStaticInstance(null);
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    public function testCreateSetsAdGroupResourceName(): void
    {
        [$serviceMock, $capture] = $this->buildAdGroupAdServiceMock();
        $this->injectMockClient($serviceMock);

        $rsa = new ResponsiveSearch();
        $rsa->create(self::FAKE_AD_GROUP_ID, $this->baseConfig());

        $adGroupAd  = $capture->op->getCreate();
        $expectedRn = 'customers/' . self::FAKE_CUSTOMER_ID . '/adGroups/' . self::FAKE_AD_GROUP_ID;
        $this->assertSame($expectedRn, $adGroupAd->getAdGroup());
    }

    public function testCreateSetsStatusToEnabled(): void
    {
        [$serviceMock, $capture] = $this->buildAdGroupAdServiceMock();
        $this->injectMockClient($serviceMock);

        $rsa = new ResponsiveSearch();
        $rsa->create(self::FAKE_AD_GROUP_ID, $this->baseConfig());

        $adGroupAd = $capture->op->getCreate();
        $this->assertSame(AdGroupAdStatus::ENABLED, $adGroupAd->getStatus());
    }

    public function testCreateSetsFinalUrl(): void
    {
        [$serviceMock, $capture] = $this->buildAdGroupAdServiceMock();
        $this->injectMockClient($serviceMock);

        $rsa = new ResponsiveSearch();
        $rsa->create(self::FAKE_AD_GROUP_ID, $this->baseConfig());

        $ad = $capture->op->getCreate()->getAd();
        $this->assertInstanceOf(Ad::class, $ad);
        $finalUrls = [];
        foreach ($ad->getFinalUrls() as $url) {
            $finalUrls[] = $url;
        }
        $this->assertSame(['https://example.com/scan'], $finalUrls);
    }

    public function testCreateSetsHeadlineTexts(): void
    {
        [$serviceMock, $capture] = $this->buildAdGroupAdServiceMock();
        $this->injectMockClient($serviceMock);

        $rsa = new ResponsiveSearch();
        $rsa->create(self::FAKE_AD_GROUP_ID, $this->baseConfig());

        $rsaInfo   = $capture->op->getCreate()->getAd()->getResponsiveSearchAd();
        $headlines = [];
        foreach ($rsaInfo->getHeadlines() as $h) {
            $headlines[] = $h->getText();
        }
        $this->assertSame([
            'Free Website Audit Tool',
            'Score Your Site in 30 Secs',
            '10-Point Conversion Check',
        ], $headlines);
    }

    public function testCreatePinsHeadline1ToPosition1(): void
    {
        [$serviceMock, $capture] = $this->buildAdGroupAdServiceMock();
        $this->injectMockClient($serviceMock);

        $rsa = new ResponsiveSearch();
        $rsa->create(self::FAKE_AD_GROUP_ID, $this->baseConfig());

        $rsaInfo   = $capture->op->getCreate()->getAd()->getResponsiveSearchAd();
        $headlines = iterator_to_array($rsaInfo->getHeadlines());

        // First headline has pin=1 -> HEADLINE_1
        $this->assertSame(ServedAssetFieldType::HEADLINE_1, $headlines[0]->getPinnedField());
    }

    public function testCreatePinsHeadline2ToPosition2(): void
    {
        [$serviceMock, $capture] = $this->buildAdGroupAdServiceMock();
        $this->injectMockClient($serviceMock);

        $rsa = new ResponsiveSearch();
        $rsa->create(self::FAKE_AD_GROUP_ID, $this->baseConfig());

        $rsaInfo   = $capture->op->getCreate()->getAd()->getResponsiveSearchAd();
        $headlines = iterator_to_array($rsaInfo->getHeadlines());

        // Second headline has pin=2 -> HEADLINE_2
        $this->assertSame(ServedAssetFieldType::HEADLINE_2, $headlines[1]->getPinnedField());
    }

    public function testCreateDoesNotPinUnpinnedHeadline(): void
    {
        [$serviceMock, $capture] = $this->buildAdGroupAdServiceMock();
        $this->injectMockClient($serviceMock);

        $rsa = new ResponsiveSearch();
        $rsa->create(self::FAKE_AD_GROUP_ID, $this->baseConfig());

        $rsaInfo   = $capture->op->getCreate()->getAd()->getResponsiveSearchAd();
        $headlines = iterator_to_array($rsaInfo->getHeadlines());

        // Third headline has no pin -> UNSPECIFIED (proto default 0)
        $this->assertSame(ServedAssetFieldType::UNSPECIFIED, $headlines[2]->getPinnedField());
    }

    public function testCreateSetsDescriptionTexts(): void
    {
        [$serviceMock, $capture] = $this->buildAdGroupAdServiceMock();
        $this->injectMockClient($serviceMock);

        $rsa = new ResponsiveSearch();
        $rsa->create(self::FAKE_AD_GROUP_ID, $this->baseConfig());

        $rsaInfo      = $capture->op->getCreate()->getAd()->getResponsiveSearchAd();
        $descriptions = [];
        foreach ($rsaInfo->getDescriptions() as $d) {
            $descriptions[] = $d->getText();
        }
        $this->assertSame([
            'Check your conversion score now.',
            'Most websites lose 60%+ of visitors.',
        ], $descriptions);
    }

    public function testCreatePinsDescription1ToPosition1(): void
    {
        [$serviceMock, $capture] = $this->buildAdGroupAdServiceMock();
        $this->injectMockClient($serviceMock);

        $rsa = new ResponsiveSearch();
        $rsa->create(self::FAKE_AD_GROUP_ID, $this->baseConfig());

        $rsaInfo      = $capture->op->getCreate()->getAd()->getResponsiveSearchAd();
        $descriptions = iterator_to_array($rsaInfo->getDescriptions());

        // First description has pin=1 -> DESCRIPTION_1
        $this->assertSame(ServedAssetFieldType::DESCRIPTION_1, $descriptions[0]->getPinnedField());
    }

    public function testCreateDoesNotPinUnpinnedDescription(): void
    {
        [$serviceMock, $capture] = $this->buildAdGroupAdServiceMock();
        $this->injectMockClient($serviceMock);

        $rsa = new ResponsiveSearch();
        $rsa->create(self::FAKE_AD_GROUP_ID, $this->baseConfig());

        $rsaInfo      = $capture->op->getCreate()->getAd()->getResponsiveSearchAd();
        $descriptions = iterator_to_array($rsaInfo->getDescriptions());

        // Second description has no pin -> UNSPECIFIED (proto default 0)
        $this->assertSame(ServedAssetFieldType::UNSPECIFIED, $descriptions[1]->getPinnedField());
    }

    public function testCreateSetsDisplayPaths(): void
    {
        [$serviceMock, $capture] = $this->buildAdGroupAdServiceMock();
        $this->injectMockClient($serviceMock);

        $rsa = new ResponsiveSearch();
        $rsa->create(self::FAKE_AD_GROUP_ID, $this->baseConfig());

        $rsaInfo = $capture->op->getCreate()->getAd()->getResponsiveSearchAd();
        $this->assertSame('Free-Audit', $rsaInfo->getPath1());
        $this->assertSame('Scan', $rsaInfo->getPath2());
    }

    public function testCreateHandlesMissingDisplayPath(): void
    {
        [$serviceMock, $capture] = $this->buildAdGroupAdServiceMock();
        $this->injectMockClient($serviceMock);

        $config = $this->baseConfig();
        unset($config['display_path']);

        $rsa = new ResponsiveSearch();
        $rsa->create(self::FAKE_AD_GROUP_ID, $config);

        $rsaInfo = $capture->op->getCreate()->getAd()->getResponsiveSearchAd();
        $this->assertSame('', $rsaInfo->getPath1());
        $this->assertSame('', $rsaInfo->getPath2());
    }

    public function testCreateCallsMutateWithCorrectCustomerId(): void
    {
        $captureCustomerId = new \stdClass();
        $captureCustomerId->value = null;

        $fakeResult = new MutateAdGroupAdResult();
        $fakeResult->setResourceName(self::FAKE_RESOURCE_NAME);

        $fakeResponse = new MutateAdGroupAdsResponse();
        $fakeResponse->setResults([$fakeResult]);

        $serviceMock = $this->createMock(AdGroupAdServiceClient::class);
        $serviceMock
            ->expects($this->once())
            ->method('mutateAdGroupAds')
            ->willReturnCallback(
                function (MutateAdGroupAdsRequest $request) use ($captureCustomerId, $fakeResponse) {
                    $captureCustomerId->value = $request->getCustomerId();
                    return $fakeResponse;
                }
            );

        $this->injectMockClient($serviceMock);

        $rsa = new ResponsiveSearch();
        $rsa->create(self::FAKE_AD_GROUP_ID, $this->baseConfig());

        $this->assertSame(self::FAKE_CUSTOMER_ID, $captureCustomerId->value);
    }

    public function testCreateReturnsResourceName(): void
    {
        [$serviceMock] = $this->buildAdGroupAdServiceMock();
        $this->injectMockClient($serviceMock);

        $rsa    = new ResponsiveSearch();
        $result = $rsa->create(self::FAKE_AD_GROUP_ID, $this->baseConfig());

        $this->assertSame(self::FAKE_RESOURCE_NAME, $result);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function baseConfig(): array
    {
        return [
            'final_url'    => 'https://example.com/scan',
            'display_path' => ['Free-Audit', 'Scan'],
            'headlines'    => [
                ['text' => 'Free Website Audit Tool', 'pin' => 1],
                ['text' => 'Score Your Site in 30 Secs', 'pin' => 2],
                ['text' => '10-Point Conversion Check'],
            ],
            'descriptions' => [
                ['text' => 'Check your conversion score now.', 'pin' => 1],
                ['text' => 'Most websites lose 60%+ of visitors.'],
            ],
        ];
    }

    /**
     * Build a mock AdGroupAdServiceClient that captures the first operation.
     * Returns [$serviceMock, $capture] where $capture->op is the AdGroupAdOperation.
     */
    private function buildAdGroupAdServiceMock(): array
    {
        $capture = new \stdClass();
        $capture->op = null;

        $fakeResult = new MutateAdGroupAdResult();
        $fakeResult->setResourceName(self::FAKE_RESOURCE_NAME);

        $fakeResponse = new MutateAdGroupAdsResponse();
        $fakeResponse->setResults([$fakeResult]);

        $serviceMock = $this->createMock(AdGroupAdServiceClient::class);
        $serviceMock
            ->expects($this->once())
            ->method('mutateAdGroupAds')
            ->willReturnCallback(
                function (MutateAdGroupAdsRequest $request) use ($capture, $fakeResponse) {
                    $capture->op = iterator_to_array($request->getOperations())[0];
                    return $fakeResponse;
                }
            );

        return [$serviceMock, $capture];
    }

    private function injectMockClient(AdGroupAdServiceClient $adGroupAdServiceMock): void
    {
        $googleAdsClientMock = $this->createMock(GoogleAdsClient::class);
        $googleAdsClientMock
            ->method('getAdGroupAdServiceClient')
            ->willReturn($adGroupAdServiceMock);

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
