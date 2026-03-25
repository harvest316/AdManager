<?php

declare(strict_types=1);

namespace AdManager\Tests\Assets;

use AdManager\Assets;
use AdManager\Client;
use Google\Ads\GoogleAds\Lib\V20\GoogleAdsClient;
use Google\Ads\GoogleAds\V20\Common\SitelinkAsset;
use Google\Ads\GoogleAds\V20\Resources\Asset;
use Google\Ads\GoogleAds\V20\Services\AssetOperation;
use Google\Ads\GoogleAds\V20\Services\MutateAssetsRequest;
use Google\Ads\GoogleAds\V20\Services\MutateAssetsResponse;
use Google\Ads\GoogleAds\V20\Services\MutateAssetResult;
use Google\Ads\GoogleAds\V20\Services\MutateCampaignAssetsResponse;
use Google\Ads\GoogleAds\V20\Services\Client\AssetServiceClient;
use Google\Ads\GoogleAds\V20\Services\Client\CampaignAssetServiceClient;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Assets::addSitelink().
 *
 * We verify that the SitelinkAsset proto is built correctly and that
 * description fields are omitted when empty.  final_urls lives on the
 * Asset wrapper (not on SitelinkAsset) per the Google Ads API schema.
 */
class SitelinkTest extends TestCase
{
    private const FAKE_CUSTOMER_ID   = '1234567890';
    private const FAKE_CAMPAIGN_ID   = '9876543210';
    private const FAKE_ASSET_RN      = 'customers/1234567890/assets/111';

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

    public function testAddSitelinkSetsLinkText(): void
    {
        [$assetServiceMock, $capture] = $this->buildAssetServiceMock();
        $campaignAssetMock            = $this->buildCampaignAssetServiceMock();
        $this->injectMockClient($assetServiceMock, $campaignAssetMock);

        $assets = new Assets();
        $assets->addSitelink(self::FAKE_CAMPAIGN_ID, 'Free Audit', 'https://auditandfix.com/scan');

        // The sitelink_asset sub-message holds the link_text
        $sitelink = $capture->asset->getSitelinkAsset();
        $this->assertInstanceOf(SitelinkAsset::class, $sitelink);
        $this->assertSame('Free Audit', $sitelink->getLinkText());
    }

    public function testAddSitelinkSetsFinalUrlOnAssetWrapper(): void
    {
        // final_urls belongs on Asset, not SitelinkAsset (per the Google Ads schema)
        [$assetServiceMock, $capture] = $this->buildAssetServiceMock();
        $campaignAssetMock            = $this->buildCampaignAssetServiceMock();
        $this->injectMockClient($assetServiceMock, $campaignAssetMock);

        $assets = new Assets();
        $assets->addSitelink(self::FAKE_CAMPAIGN_ID, 'Free Audit', 'https://auditandfix.com/scan');

        $this->assertSame('https://auditandfix.com/scan', $capture->asset->getFinalUrls()[0]);
    }

    public function testAddSitelinkSetsDesc1AndDesc2WhenProvided(): void
    {
        [$assetServiceMock, $capture] = $this->buildAssetServiceMock();
        $campaignAssetMock            = $this->buildCampaignAssetServiceMock();
        $this->injectMockClient($assetServiceMock, $campaignAssetMock);

        $assets = new Assets();
        $assets->addSitelink(
            self::FAKE_CAMPAIGN_ID,
            'Free Audit',
            'https://auditandfix.com/scan',
            'Check your score',
            'Takes 30 seconds'
        );

        $sitelink = $capture->asset->getSitelinkAsset();
        $this->assertSame('Check your score', $sitelink->getDescription1());
        $this->assertSame('Takes 30 seconds', $sitelink->getDescription2());
    }

    public function testAddSitelinkOmitsDesc1AndDesc2WhenBothEmpty(): void
    {
        [$assetServiceMock, $capture] = $this->buildAssetServiceMock();
        $campaignAssetMock            = $this->buildCampaignAssetServiceMock();
        $this->injectMockClient($assetServiceMock, $campaignAssetMock);

        $assets = new Assets();
        $assets->addSitelink(
            self::FAKE_CAMPAIGN_ID,
            'Free Audit',
            'https://auditandfix.com/scan',
            '',   // empty desc1
            ''    // empty desc2
        );

        $sitelink = $capture->asset->getSitelinkAsset();
        // When empty, setDescription1/2 are never called — proto defaults to ''
        $this->assertSame('', $sitelink->getDescription1());
        $this->assertSame('', $sitelink->getDescription2());
    }

    public function testAddSitelinkSetsDesc1ButOmitsDesc2WhenOnlyDesc1Provided(): void
    {
        [$assetServiceMock, $capture] = $this->buildAssetServiceMock();
        $campaignAssetMock            = $this->buildCampaignAssetServiceMock();
        $this->injectMockClient($assetServiceMock, $campaignAssetMock);

        $assets = new Assets();
        $assets->addSitelink(
            self::FAKE_CAMPAIGN_ID,
            'Pricing',
            'https://auditandfix.com/pricing',
            'See our rates',
            ''  // empty desc2
        );

        $sitelink = $capture->asset->getSitelinkAsset();
        $this->assertSame('See our rates', $sitelink->getDescription1());
        $this->assertSame('', $sitelink->getDescription2());
    }

    public function testAddSitelinkReturnsAssetResourceName(): void
    {
        [$assetServiceMock]  = $this->buildAssetServiceMock();
        $campaignAssetMock   = $this->buildCampaignAssetServiceMock();
        $this->injectMockClient($assetServiceMock, $campaignAssetMock);

        $assets = new Assets();
        $result = $assets->addSitelink(self::FAKE_CAMPAIGN_ID, 'Home', 'https://auditandfix.com');

        $this->assertSame(self::FAKE_ASSET_RN, $result);
    }

    public function testAddSitelinkCallsCampaignAssetService(): void
    {
        [$assetServiceMock]  = $this->buildAssetServiceMock();
        $campaignAssetMock   = $this->buildCampaignAssetServiceMock(expectsCalled: true);
        $this->injectMockClient($assetServiceMock, $campaignAssetMock);

        $assets = new Assets();
        $assets->addSitelink(self::FAKE_CAMPAIGN_ID, 'Home', 'https://auditandfix.com');
        // Expectation asserted automatically by PHPUnit mock teardown
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build a mock AssetServiceClient that captures the Asset from the request.
     * Returns [$serviceMock, &$capturedAsset] where $capturedAsset[0] is the Asset proto.
     */
    private function buildAssetServiceMock(): array
    {
        $capture = new \stdClass();
        $capture->asset = null;

        $fakeResult = new MutateAssetResult();
        $fakeResult->setResourceName(self::FAKE_ASSET_RN);

        $fakeResponse = new MutateAssetsResponse();
        $fakeResponse->setResults([$fakeResult]);

        $serviceMock = $this->createMock(AssetServiceClient::class);
        $serviceMock
            ->expects($this->once())
            ->method('mutateAssets')
            ->willReturnCallback(
                function (MutateAssetsRequest $request) use ($capture, $fakeResponse) {
                    $op = iterator_to_array($request->getOperations())[0];
                    $capture->asset = $op->getCreate();
                    return $fakeResponse;
                }
            );

        return [$serviceMock, $capture];
    }

    /**
     * Build a minimal CampaignAssetServiceClient mock.
     */
    private function buildCampaignAssetServiceMock(bool $expectsCalled = false): CampaignAssetServiceClient
    {
        $mock = $this->createMock(CampaignAssetServiceClient::class);
        $fakeResponse = new MutateCampaignAssetsResponse();

        if ($expectsCalled) {
            $mock->expects($this->once())
                 ->method('mutateCampaignAssets')
                 ->willReturn($fakeResponse);
        } else {
            $mock->method('mutateCampaignAssets')->willReturn($fakeResponse);
        }

        return $mock;
    }

    private function injectMockClient(
        AssetServiceClient $assetServiceMock,
        CampaignAssetServiceClient $campaignAssetServiceMock
    ): void {
        $googleAdsClientMock = $this->createMock(GoogleAdsClient::class);
        $googleAdsClientMock->method('getAssetServiceClient')->willReturn($assetServiceMock);
        $googleAdsClientMock->method('getCampaignAssetServiceClient')->willReturn($campaignAssetServiceMock);
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
