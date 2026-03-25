<?php

declare(strict_types=1);

namespace AdManager\Tests\Google\Campaign;

use AdManager\Google\Campaign\Video;
use AdManager\Google\Client;
use Google\Ads\GoogleAds\Lib\V20\GoogleAdsClient;
use Google\Ads\GoogleAds\V20\Common\MaximizeConversions;
use Google\Ads\GoogleAds\V20\Common\TargetCpa;
use Google\Ads\GoogleAds\V20\Enums\AdvertisingChannelTypeEnum\AdvertisingChannelType;
use Google\Ads\GoogleAds\V20\Enums\AdvertisingChannelSubTypeEnum\AdvertisingChannelSubType;
use Google\Ads\GoogleAds\V20\Enums\BudgetDeliveryMethodEnum\BudgetDeliveryMethod;
use Google\Ads\GoogleAds\V20\Enums\CampaignStatusEnum\CampaignStatus;
use Google\Ads\GoogleAds\V20\Resources\Campaign;
use Google\Ads\GoogleAds\V20\Services\CampaignOperation;
use Google\Ads\GoogleAds\V20\Services\CampaignBudgetOperation;
use Google\Ads\GoogleAds\V20\Services\MutateCampaignsRequest;
use Google\Ads\GoogleAds\V20\Services\MutateCampaignBudgetsRequest;
use Google\Ads\GoogleAds\V20\Services\MutateCampaignsResponse;
use Google\Ads\GoogleAds\V20\Services\MutateCampaignResult;
use Google\Ads\GoogleAds\V20\Services\MutateCampaignBudgetsResponse;
use Google\Ads\GoogleAds\V20\Services\MutateCampaignBudgetResult;
use Google\Ads\GoogleAds\V20\Services\Client\CampaignServiceClient;
use Google\Ads\GoogleAds\V20\Services\Client\CampaignBudgetServiceClient;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Campaign\Video::create().
 *
 * We verify:
 * - Campaign is created with PAUSED status
 * - Channel type is VIDEO
 * - Channel sub-type defaults to VIDEO_ACTION
 * - Channel sub-type is VIDEO_OUTSTREAM when subtype=video_reach
 * - Budget is converted to micros correctly
 * - Bidding defaults to maximize_conversions
 * - target_cpa bidding sets correct micros
 * - Return value is the campaign resource name
 */
class VideoTest extends TestCase
{
    private const FAKE_CUSTOMER_ID = '1234567890';
    private const FAKE_BUDGET_RN   = 'customers/1234567890/campaignBudgets/111';
    private const FAKE_CAMPAIGN_RN = 'customers/1234567890/campaigns/222';

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

    public function testCreateStartsCampaignAsPaused(): void
    {
        [$budgetMock, $campaignMock, $campaignCapture] = $this->buildServiceMocks();
        $this->injectMockClient($budgetMock, $campaignMock);

        $video = new Video();
        $video->create($this->baseConfig());

        $campaign = $campaignCapture->op->getCreate();
        $this->assertSame(CampaignStatus::PAUSED, $campaign->getStatus());
    }

    public function testCreateSetsVideoChannelType(): void
    {
        [$budgetMock, $campaignMock, $campaignCapture] = $this->buildServiceMocks();
        $this->injectMockClient($budgetMock, $campaignMock);

        $video = new Video();
        $video->create($this->baseConfig());

        $campaign = $campaignCapture->op->getCreate();
        $this->assertSame(AdvertisingChannelType::VIDEO, $campaign->getAdvertisingChannelType());
    }

    public function testCreateSetsVideoActionSubTypeByDefault(): void
    {
        [$budgetMock, $campaignMock, $campaignCapture] = $this->buildServiceMocks();
        $this->injectMockClient($budgetMock, $campaignMock);

        $video = new Video();
        $video->create($this->baseConfig());

        $campaign = $campaignCapture->op->getCreate();
        $this->assertSame(
            AdvertisingChannelSubType::VIDEO_ACTION,
            $campaign->getAdvertisingChannelSubType()
        );
    }

    public function testCreateSetsVideoActionSubTypeWhenExplicit(): void
    {
        [$budgetMock, $campaignMock, $campaignCapture] = $this->buildServiceMocks();
        $this->injectMockClient($budgetMock, $campaignMock);

        $video = new Video();
        $video->create(['subtype' => 'video_action'] + $this->baseConfig());

        $campaign = $campaignCapture->op->getCreate();
        $this->assertSame(
            AdvertisingChannelSubType::VIDEO_ACTION,
            $campaign->getAdvertisingChannelSubType()
        );
    }

    public function testCreateSetsVideoReachTargetFrequencySubTypeForVideoReach(): void
    {
        [$budgetMock, $campaignMock, $campaignCapture] = $this->buildServiceMocks();
        $this->injectMockClient($budgetMock, $campaignMock);

        $video = new Video();
        $video->create(['subtype' => 'video_reach'] + $this->baseConfig());

        $campaign = $campaignCapture->op->getCreate();
        $this->assertSame(
            AdvertisingChannelSubType::VIDEO_REACH_TARGET_FREQUENCY,
            $campaign->getAdvertisingChannelSubType()
        );
    }

    public function testCreateSetsVideoActionSubTypeWhenSubtypeOmitted(): void
    {
        [$budgetMock, $campaignMock, $campaignCapture] = $this->buildServiceMocks();
        $this->injectMockClient($budgetMock, $campaignMock);

        $config = $this->baseConfig();
        unset($config['subtype']);

        $video = new Video();
        $video->create($config);

        $campaign = $campaignCapture->op->getCreate();
        $this->assertSame(
            AdvertisingChannelSubType::VIDEO_ACTION,
            $campaign->getAdvertisingChannelSubType()
        );
    }

    public function testCreateSetsCampaignName(): void
    {
        [$budgetMock, $campaignMock, $campaignCapture] = $this->buildServiceMocks();
        $this->injectMockClient($budgetMock, $campaignMock);

        $video = new Video();
        $video->create(['name' => 'Video — YouTube AU'] + $this->baseConfig());

        $campaign = $campaignCapture->op->getCreate();
        $this->assertSame('Video — YouTube AU', $campaign->getName());
    }

    public function testCreateConvertsDailyBudgetToMicros(): void
    {
        [$budgetMock, $campaignMock, , $budgetCapture] = $this->buildServiceMocks();
        $this->injectMockClient($budgetMock, $campaignMock);

        $video = new Video();
        $video->create(['daily_budget_usd' => 5.00] + $this->baseConfig());

        $budget = $budgetCapture->op->getCreate();
        $this->assertSame(5_000_000, $budget->getAmountMicros());
    }

    public function testCreateConvertsFractionalBudgetToMicros(): void
    {
        [$budgetMock, $campaignMock, , $budgetCapture] = $this->buildServiceMocks();
        $this->injectMockClient($budgetMock, $campaignMock);

        $video = new Video();
        $video->create(['daily_budget_usd' => 12.50] + $this->baseConfig());

        $budget = $budgetCapture->op->getCreate();
        $this->assertSame(12_500_000, $budget->getAmountMicros());
    }

    public function testCreateSetsBudgetDeliveryMethodToStandard(): void
    {
        [$budgetMock, $campaignMock, , $budgetCapture] = $this->buildServiceMocks();
        $this->injectMockClient($budgetMock, $campaignMock);

        $video = new Video();
        $video->create($this->baseConfig());

        $budget = $budgetCapture->op->getCreate();
        $this->assertSame(BudgetDeliveryMethod::STANDARD, $budget->getDeliveryMethod());
    }

    public function testCreateSetsBudgetNameFromCampaignName(): void
    {
        [$budgetMock, $campaignMock, , $budgetCapture] = $this->buildServiceMocks();
        $this->injectMockClient($budgetMock, $campaignMock);

        $video = new Video();
        $video->create($this->baseConfig());

        $budget = $budgetCapture->op->getCreate();
        $this->assertSame('Video — Test Budget', $budget->getName());
    }

    public function testCreateLinksBudgetResourceNameToCampaign(): void
    {
        [$budgetMock, $campaignMock, $campaignCapture] = $this->buildServiceMocks();
        $this->injectMockClient($budgetMock, $campaignMock);

        $video = new Video();
        $video->create($this->baseConfig());

        $campaign = $campaignCapture->op->getCreate();
        $this->assertSame(self::FAKE_BUDGET_RN, $campaign->getCampaignBudget());
    }

    public function testCreateUsesMaximizeConversionsByDefault(): void
    {
        [$budgetMock, $campaignMock, $campaignCapture] = $this->buildServiceMocks();
        $this->injectMockClient($budgetMock, $campaignMock);

        $video = new Video();
        $video->create($this->baseConfig());

        $campaign = $campaignCapture->op->getCreate();
        $this->assertNotNull($campaign->getMaximizeConversions());
    }

    public function testCreateUsesMaximizeConversionsWhenBiddingOmitted(): void
    {
        [$budgetMock, $campaignMock, $campaignCapture] = $this->buildServiceMocks();
        $this->injectMockClient($budgetMock, $campaignMock);

        $config = $this->baseConfig();
        unset($config['bidding']);

        $video = new Video();
        $video->create($config);

        $campaign = $campaignCapture->op->getCreate();
        $this->assertNotNull($campaign->getMaximizeConversions());
    }

    public function testCreateUsesTargetCpaWhenSpecified(): void
    {
        [$budgetMock, $campaignMock, $campaignCapture] = $this->buildServiceMocks();
        $this->injectMockClient($budgetMock, $campaignMock);

        $video = new Video();
        $video->create([
            'bidding'        => 'target_cpa',
            'target_cpa_usd' => 150.00,
        ] + $this->baseConfig());

        $campaign = $campaignCapture->op->getCreate();
        $this->assertNotNull($campaign->getTargetCpa());
        $this->assertSame(150_000_000, $campaign->getTargetCpa()->getTargetCpaMicros());
    }

    public function testCreateTargetCpaDefaultsTo150WhenOmitted(): void
    {
        [$budgetMock, $campaignMock, $campaignCapture] = $this->buildServiceMocks();
        $this->injectMockClient($budgetMock, $campaignMock);

        $video = new Video();
        $video->create([
            'bidding' => 'target_cpa',
            // target_cpa_usd omitted — defaults to 150
        ] + $this->baseConfig());

        $campaign = $campaignCapture->op->getCreate();
        $this->assertSame(150_000_000, $campaign->getTargetCpa()->getTargetCpaMicros());
    }

    public function testCreateReturnsResourceName(): void
    {
        [$budgetMock, $campaignMock] = $this->buildServiceMocks();
        $this->injectMockClient($budgetMock, $campaignMock);

        $video  = new Video();
        $result = $video->create($this->baseConfig());

        $this->assertSame(self::FAKE_CAMPAIGN_RN, $result);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function baseConfig(): array
    {
        return [
            'name'             => 'Video — Test',
            'daily_budget_usd' => 5.00,
            'subtype'          => 'video_action',
            'bidding'          => 'maximize_conversions',
        ];
    }

    /**
     * Build budget and campaign service mocks.
     * Returns [$budgetMock, $campaignMock, $campaignCapture, $budgetCapture].
     */
    private function buildServiceMocks(): array
    {
        $budgetCapture   = new \stdClass();
        $budgetCapture->op = null;
        $campaignCapture = new \stdClass();
        $campaignCapture->op = null;

        // Budget mock
        $fakeBudgetResult = new MutateCampaignBudgetResult();
        $fakeBudgetResult->setResourceName(self::FAKE_BUDGET_RN);

        $fakeBudgetResponse = new MutateCampaignBudgetsResponse();
        $fakeBudgetResponse->setResults([$fakeBudgetResult]);

        $budgetMock = $this->createMock(CampaignBudgetServiceClient::class);
        $budgetMock->method('mutateCampaignBudgets')
                   ->willReturnCallback(function (MutateCampaignBudgetsRequest $request) use ($budgetCapture, $fakeBudgetResponse) {
                       $budgetCapture->op = iterator_to_array($request->getOperations())[0];
                       return $fakeBudgetResponse;
                   });

        // Campaign mock
        $fakeCampaignResult = new MutateCampaignResult();
        $fakeCampaignResult->setResourceName(self::FAKE_CAMPAIGN_RN);

        $fakeCampaignResponse = new MutateCampaignsResponse();
        $fakeCampaignResponse->setResults([$fakeCampaignResult]);

        $campaignMock = $this->createMock(CampaignServiceClient::class);
        $campaignMock->method('mutateCampaigns')
                     ->willReturnCallback(function (MutateCampaignsRequest $request) use ($campaignCapture, $fakeCampaignResponse) {
                         $campaignCapture->op = iterator_to_array($request->getOperations())[0];
                         return $fakeCampaignResponse;
                     });

        return [$budgetMock, $campaignMock, $campaignCapture, $budgetCapture];
    }

    private function injectMockClient(
        CampaignBudgetServiceClient $budgetMock,
        CampaignServiceClient $campaignMock
    ): void {
        $googleAdsClientMock = $this->createMock(GoogleAdsClient::class);
        $googleAdsClientMock->method('getCampaignBudgetServiceClient')->willReturn($budgetMock);
        $googleAdsClientMock->method('getCampaignServiceClient')->willReturn($campaignMock);
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
