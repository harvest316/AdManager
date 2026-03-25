<?php

declare(strict_types=1);

namespace AdManager\Tests\Campaign;

use AdManager\Campaign\Search;
use AdManager\Client;
use Google\Ads\GoogleAds\Lib\V20\GoogleAdsClient;
use Google\Ads\GoogleAds\V20\Common\ManualCpc;
use Google\Ads\GoogleAds\V20\Common\MaximizeConversions;
use Google\Ads\GoogleAds\V20\Common\TargetCpa;
use Google\Ads\GoogleAds\V20\Enums\CampaignStatusEnum\CampaignStatus;
use Google\Ads\GoogleAds\V20\Enums\AdvertisingChannelTypeEnum\AdvertisingChannelType;
use Google\Ads\GoogleAds\V20\Resources\Campaign;
use Google\Ads\GoogleAds\V20\Services\CampaignOperation;
use Google\Ads\GoogleAds\V20\Services\CampaignBudgetOperation;
use Google\Ads\GoogleAds\V20\Services\MutateCampaignsResponse;
use Google\Ads\GoogleAds\V20\Services\MutateCampaignResult;
use Google\Ads\GoogleAds\V20\Services\MutateCampaignBudgetsResponse;
use Google\Ads\GoogleAds\V20\Services\MutateCampaignBudgetResult;
use Google\Ads\GoogleAds\V20\Services\MutateCampaignsRequest;
use Google\Ads\GoogleAds\V20\Services\MutateCampaignBudgetsRequest;
use Google\Ads\GoogleAds\V20\Services\Client\CampaignServiceClient;
use Google\Ads\GoogleAds\V20\Services\Client\CampaignBudgetServiceClient;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Campaign\Search::create().
 *
 * We verify:
 * - The campaign is created with PAUSED status (start paused by design)
 * - Budget micros conversion is correct
 * - Correct bidding strategy object is set for each bidding value
 * - start_date is formatted correctly (dashes stripped)
 * - Invalid bidding string falls back to maximize_conversions
 */
class SearchTest extends TestCase
{
    private const FAKE_CUSTOMER_ID  = '1234567890';
    private const FAKE_BUDGET_RN    = 'customers/1234567890/campaignBudgets/111';
    private const FAKE_CAMPAIGN_RN  = 'customers/1234567890/campaigns/222';

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

        $search = new Search();
        $search->create($this->baseConfig());

        $campaign = $campaignCapture->op->getCreate();
        $this->assertSame(CampaignStatus::PAUSED, $campaign->getStatus());
    }

    public function testCreateSetsSearchChannelType(): void
    {
        [$budgetMock, $campaignMock, $campaignCapture] = $this->buildServiceMocks();
        $this->injectMockClient($budgetMock, $campaignMock);

        $search = new Search();
        $search->create($this->baseConfig());

        $campaign = $campaignCapture->op->getCreate();
        $this->assertSame(AdvertisingChannelType::SEARCH, $campaign->getAdvertisingChannelType());
    }

    public function testCreateSetsCampaignName(): void
    {
        [$budgetMock, $campaignMock, $campaignCapture] = $this->buildServiceMocks();
        $this->injectMockClient($budgetMock, $campaignMock);

        $search = new Search();
        $search->create(['name' => 'My Search Campaign'] + $this->baseConfig());

        $campaign = $campaignCapture->op->getCreate();
        $this->assertSame('My Search Campaign', $campaign->getName());
    }

    public function testCreateConvertsDailyBudgetToMicros(): void
    {
        [$budgetMock, $campaignMock, , $budgetCapture] = $this->buildServiceMocks();
        $this->injectMockClient($budgetMock, $campaignMock);

        $search = new Search();
        $search->create(['daily_budget_usd' => 6.70] + $this->baseConfig());

        $budget = $budgetCapture->op->getCreate();
        $this->assertSame(6_700_000, $budget->getAmountMicros());
    }

    public function testCreateUsesMaximizeConversionsByDefault(): void
    {
        [$budgetMock, $campaignMock, $campaignCapture] = $this->buildServiceMocks();
        $this->injectMockClient($budgetMock, $campaignMock);

        $search = new Search();
        $search->create(['bidding' => 'maximize_conversions'] + $this->baseConfig());

        $campaign = $campaignCapture->op->getCreate();
        $this->assertNotNull($campaign->getMaximizeConversions());
        $this->assertNull($campaign->getManualCpc());
        $this->assertNull($campaign->getTargetCpa());
    }

    public function testCreateUnknownBiddingStringFallsBackToMaximizeConversions(): void
    {
        [$budgetMock, $campaignMock, $campaignCapture] = $this->buildServiceMocks();
        $this->injectMockClient($budgetMock, $campaignMock);

        $search = new Search();
        $search->create(['bidding' => 'some_unknown_strategy'] + $this->baseConfig());

        $campaign = $campaignCapture->op->getCreate();
        // The match() default branch sets maximize_conversions
        $this->assertNotNull($campaign->getMaximizeConversions());
    }

    public function testCreateUsesManualCpcWhenSpecified(): void
    {
        [$budgetMock, $campaignMock, $campaignCapture] = $this->buildServiceMocks();
        $this->injectMockClient($budgetMock, $campaignMock);

        $search = new Search();
        $search->create(['bidding' => 'manual_cpc'] + $this->baseConfig());

        $campaign = $campaignCapture->op->getCreate();
        $this->assertNotNull($campaign->getManualCpc());
        $this->assertNull($campaign->getMaximizeConversions());
    }

    public function testCreateUsesTargetCpaWhenSpecified(): void
    {
        [$budgetMock, $campaignMock, $campaignCapture] = $this->buildServiceMocks();
        $this->injectMockClient($budgetMock, $campaignMock);

        $search = new Search();
        $search->create([
            'bidding'        => 'target_cpa',
            'target_cpa_usd' => 150.00,
        ] + $this->baseConfig());

        $campaign = $campaignCapture->op->getCreate();
        $this->assertNotNull($campaign->getTargetCpa());
        $this->assertSame(150_000_000, $campaign->getTargetCpa()->getTargetCpaMicros());
    }

    public function testCreateStripsStartDateDashes(): void
    {
        [$budgetMock, $campaignMock, $campaignCapture] = $this->buildServiceMocks();
        $this->injectMockClient($budgetMock, $campaignMock);

        $search = new Search();
        $search->create(['start_date' => '2026-04-01'] + $this->baseConfig());

        $campaign = $campaignCapture->op->getCreate();
        $this->assertSame('20260401', $campaign->getStartDate());
    }

    public function testCreateDoesNotSetStartDateWhenOmitted(): void
    {
        [$budgetMock, $campaignMock, $campaignCapture] = $this->buildServiceMocks();
        $this->injectMockClient($budgetMock, $campaignMock);

        $search = new Search();
        $search->create($this->baseConfig()); // no start_date key

        $campaign = $campaignCapture->op->getCreate();
        // Proto default for unset string is ''
        $this->assertSame('', $campaign->getStartDate());
    }

    public function testCreateReturnsResourceName(): void
    {
        [$budgetMock, $campaignMock] = $this->buildServiceMocks();
        $this->injectMockClient($budgetMock, $campaignMock);

        $search = new Search();
        $result = $search->create($this->baseConfig());

        $this->assertSame(self::FAKE_CAMPAIGN_RN, $result);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function baseConfig(): array
    {
        return [
            'name'             => 'Test Search Campaign',
            'daily_budget_usd' => 5.00,
            'bidding'          => 'maximize_conversions',
            'search_partners'  => false,
            'display_network'  => false,
        ];
    }

    /**
     * Build budget and campaign service mocks.
     * Returns [$budgetMock, $campaignMock, &$capturedCampaignOp, &$capturedBudgetOp].
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
