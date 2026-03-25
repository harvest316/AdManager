<?php

declare(strict_types=1);

namespace AdManager\Tests\Google\Campaign;

use AdManager\Google\Campaign\Display;
use AdManager\Google\Client;
use Google\Ads\GoogleAds\Lib\V20\GoogleAdsClient;
use Google\Ads\GoogleAds\V20\Common\MaximizeConversions;
use Google\Ads\GoogleAds\V20\Common\TargetCpa;
use Google\Ads\GoogleAds\V20\Enums\AdvertisingChannelTypeEnum\AdvertisingChannelType;
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
 * Tests for Campaign\Display::create().
 *
 * We verify:
 * - Campaign is created with PAUSED status
 * - Channel type is DISPLAY
 * - Budget is converted to micros correctly
 * - Budget delivery method is STANDARD
 * - Budget name is derived from campaign name
 * - Bidding defaults to maximize_conversions
 * - target_cpa bidding sets correct micros
 * - Return value is the campaign resource name
 */
class DisplayTest extends TestCase
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

        $display = new Display();
        $display->create($this->baseConfig());

        $campaign = $campaignCapture->op->getCreate();
        $this->assertSame(CampaignStatus::PAUSED, $campaign->getStatus());
    }

    public function testCreateSetsDisplayChannelType(): void
    {
        [$budgetMock, $campaignMock, $campaignCapture] = $this->buildServiceMocks();
        $this->injectMockClient($budgetMock, $campaignMock);

        $display = new Display();
        $display->create($this->baseConfig());

        $campaign = $campaignCapture->op->getCreate();
        $this->assertSame(AdvertisingChannelType::DISPLAY, $campaign->getAdvertisingChannelType());
    }

    public function testCreateSetsCampaignName(): void
    {
        [$budgetMock, $campaignMock, $campaignCapture] = $this->buildServiceMocks();
        $this->injectMockClient($budgetMock, $campaignMock);

        $display = new Display();
        $display->create(['name' => 'My Display Campaign'] + $this->baseConfig());

        $campaign = $campaignCapture->op->getCreate();
        $this->assertSame('My Display Campaign', $campaign->getName());
    }

    public function testCreateConvertsDailyBudgetToMicros(): void
    {
        [$budgetMock, $campaignMock, , $budgetCapture] = $this->buildServiceMocks();
        $this->injectMockClient($budgetMock, $campaignMock);

        $display = new Display();
        $display->create(['daily_budget_usd' => 5.00] + $this->baseConfig());

        $budget = $budgetCapture->op->getCreate();
        $this->assertSame(5_000_000, $budget->getAmountMicros());
    }

    public function testCreateConvertsFractionalBudgetToMicros(): void
    {
        [$budgetMock, $campaignMock, , $budgetCapture] = $this->buildServiceMocks();
        $this->injectMockClient($budgetMock, $campaignMock);

        $display = new Display();
        $display->create(['daily_budget_usd' => 7.50] + $this->baseConfig());

        $budget = $budgetCapture->op->getCreate();
        $this->assertSame(7_500_000, $budget->getAmountMicros());
    }

    public function testCreateSetsBudgetDeliveryMethodToStandard(): void
    {
        [$budgetMock, $campaignMock, , $budgetCapture] = $this->buildServiceMocks();
        $this->injectMockClient($budgetMock, $campaignMock);

        $display = new Display();
        $display->create($this->baseConfig());

        $budget = $budgetCapture->op->getCreate();
        $this->assertSame(BudgetDeliveryMethod::STANDARD, $budget->getDeliveryMethod());
    }

    public function testCreateSetsBudgetNameFromCampaignName(): void
    {
        [$budgetMock, $campaignMock, , $budgetCapture] = $this->buildServiceMocks();
        $this->injectMockClient($budgetMock, $campaignMock);

        $display = new Display();
        $display->create($this->baseConfig());

        $budget = $budgetCapture->op->getCreate();
        $this->assertSame('Display — Remarketing Budget', $budget->getName());
    }

    public function testCreateLinksBudgetResourceNameToCampaign(): void
    {
        [$budgetMock, $campaignMock, $campaignCapture] = $this->buildServiceMocks();
        $this->injectMockClient($budgetMock, $campaignMock);

        $display = new Display();
        $display->create($this->baseConfig());

        $campaign = $campaignCapture->op->getCreate();
        $this->assertSame(self::FAKE_BUDGET_RN, $campaign->getCampaignBudget());
    }

    public function testCreateUsesMaximizeConversionsByDefault(): void
    {
        [$budgetMock, $campaignMock, $campaignCapture] = $this->buildServiceMocks();
        $this->injectMockClient($budgetMock, $campaignMock);

        $display = new Display();
        $display->create($this->baseConfig());

        $campaign = $campaignCapture->op->getCreate();
        $this->assertNotNull($campaign->getMaximizeConversions());
    }

    public function testCreateUsesMaximizeConversionsWhenBiddingOmitted(): void
    {
        [$budgetMock, $campaignMock, $campaignCapture] = $this->buildServiceMocks();
        $this->injectMockClient($budgetMock, $campaignMock);

        $config = $this->baseConfig();
        unset($config['bidding']);

        $display = new Display();
        $display->create($config);

        $campaign = $campaignCapture->op->getCreate();
        $this->assertNotNull($campaign->getMaximizeConversions());
    }

    public function testCreateUsesTargetCpaWhenSpecified(): void
    {
        [$budgetMock, $campaignMock, $campaignCapture] = $this->buildServiceMocks();
        $this->injectMockClient($budgetMock, $campaignMock);

        $display = new Display();
        $display->create([
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

        $display = new Display();
        $display->create([
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

        $display = new Display();
        $result  = $display->create($this->baseConfig());

        $this->assertSame(self::FAKE_CAMPAIGN_RN, $result);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function baseConfig(): array
    {
        return [
            'name'             => 'Display — Remarketing',
            'daily_budget_usd' => 5.00,
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
