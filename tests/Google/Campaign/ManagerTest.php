<?php

declare(strict_types=1);

namespace AdManager\Tests\Google\Campaign;

use AdManager\Google\Campaign\Manager;
use AdManager\Google\Client;
use Google\Ads\GoogleAds\Lib\V20\GoogleAdsClient;
use Google\Ads\GoogleAds\V20\Enums\CampaignStatusEnum\CampaignStatus;
use Google\Ads\GoogleAds\V20\Resources\Campaign;
use Google\Ads\GoogleAds\V20\Resources\CampaignBudget;
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
use Google\Ads\GoogleAds\V20\Services\Client\GoogleAdsServiceClient;
use Google\ApiCore\PagedListResponse;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Campaign\Manager: enable(), pause(), setDailyBudget(), getBudgetId().
 */
class ManagerTest extends TestCase
{
    private const FAKE_CUSTOMER_ID  = '1234567890';
    private const FAKE_CAMPAIGN_ID  = '9876543210';
    private const FAKE_BUDGET_ID    = '5551234567';

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
    // enable() / pause()
    // -------------------------------------------------------------------------

    public function testEnableCallsMutateCampaignsWithEnabledStatus(): void
    {
        [$campaignServiceMock, $capture] = $this->buildCampaignServiceMock();
        $this->injectMockClientWithCampaignService($campaignServiceMock);

        $manager = new Manager();
        $manager->enable(self::FAKE_CAMPAIGN_ID);

        $this->assertNotNull($capture->op, 'Expected a CampaignOperation to be captured');
        $updated = $capture->op->getUpdate();
        $this->assertSame(CampaignStatus::ENABLED, $updated->getStatus());
    }

    public function testPauseCallsMutateCampaignsWithPausedStatus(): void
    {
        [$campaignServiceMock, $capture] = $this->buildCampaignServiceMock();
        $this->injectMockClientWithCampaignService($campaignServiceMock);

        $manager = new Manager();
        $manager->pause(self::FAKE_CAMPAIGN_ID);

        $this->assertNotNull($capture->op, 'Expected a CampaignOperation to be captured');
        $updated = $capture->op->getUpdate();
        $this->assertSame(CampaignStatus::PAUSED, $updated->getStatus());
    }

    public function testEnableSetsCorrectCampaignResourceName(): void
    {
        [$campaignServiceMock, $capture] = $this->buildCampaignServiceMock();
        $this->injectMockClientWithCampaignService($campaignServiceMock);

        $manager = new Manager();
        $manager->enable(self::FAKE_CAMPAIGN_ID);

        $expectedRn = 'customers/' . self::FAKE_CUSTOMER_ID . '/campaigns/' . self::FAKE_CAMPAIGN_ID;
        $updated    = $capture->op->getUpdate();
        $this->assertSame($expectedRn, $updated->getResourceName());
    }

    // -------------------------------------------------------------------------
    // setDailyBudget() — micros conversion
    // -------------------------------------------------------------------------

    public function testSetDailyBudgetConvertsSixPointSevenToMicros(): void
    {
        [$budgetServiceMock, $capture] = $this->buildBudgetServiceMock();
        $this->injectMockClientWithBudgetService($budgetServiceMock);

        $manager = new Manager();
        $manager->setDailyBudget(self::FAKE_BUDGET_ID, 6.70);

        $updatedBudget = $capture->op->getUpdate();
        $this->assertSame(6_700_000, $updatedBudget->getAmountMicros());
    }

    public function testSetDailyBudgetConvertsRoundNumberCorrectly(): void
    {
        [$budgetServiceMock, $capture] = $this->buildBudgetServiceMock();
        $this->injectMockClientWithBudgetService($budgetServiceMock);

        $manager = new Manager();
        $manager->setDailyBudget(self::FAKE_BUDGET_ID, 10.00);

        $updatedBudget = $capture->op->getUpdate();
        $this->assertSame(10_000_000, $updatedBudget->getAmountMicros());
    }

    public function testSetDailyBudgetConvertsSmallAmountCorrectly(): void
    {
        [$budgetServiceMock, $capture] = $this->buildBudgetServiceMock();
        $this->injectMockClientWithBudgetService($budgetServiceMock);

        $manager = new Manager();
        $manager->setDailyBudget(self::FAKE_BUDGET_ID, 0.01);

        $updatedBudget = $capture->op->getUpdate();
        $this->assertSame(10_000, $updatedBudget->getAmountMicros());
    }

    public function testSetDailyBudgetSetsCorrectBudgetResourceName(): void
    {
        [$budgetServiceMock, $capture] = $this->buildBudgetServiceMock();
        $this->injectMockClientWithBudgetService($budgetServiceMock);

        $manager = new Manager();
        $manager->setDailyBudget(self::FAKE_BUDGET_ID, 5.00);

        $expectedRn    = 'customers/' . self::FAKE_CUSTOMER_ID . '/campaignBudgets/' . self::FAKE_BUDGET_ID;
        $updatedBudget = $capture->op->getUpdate();
        $this->assertSame($expectedRn, $updatedBudget->getResourceName());
    }

    // -------------------------------------------------------------------------
    // getBudgetId() — returns null when no rows
    // -------------------------------------------------------------------------

    public function testGetBudgetIdReturnsNullWhenNoRowsReturned(): void
    {
        $googleAdsServiceMock = $this->createMock(GoogleAdsServiceClient::class);
        $pagedResponse        = $this->createMock(PagedListResponse::class);

        // iterateAllElements returns an empty iterator
        $pagedResponse
            ->method('iterateAllElements')
            ->willReturn(new \ArrayIterator([]));

        $googleAdsServiceMock
            ->method('search')
            ->willReturn($pagedResponse);

        $googleAdsClientMock = $this->createMock(GoogleAdsClient::class);
        $googleAdsClientMock->method('getGoogleAdsServiceClient')->willReturn($googleAdsServiceMock);
        $this->setClientStaticInstance($googleAdsClientMock);

        $manager  = new Manager();
        $budgetId = $manager->getBudgetId(self::FAKE_CAMPAIGN_ID);

        $this->assertNull($budgetId);
    }

    public function testGetBudgetIdReturnsIdFromFirstRow(): void
    {
        $googleAdsServiceMock = $this->createMock(GoogleAdsServiceClient::class);
        $pagedResponse        = $this->createMock(PagedListResponse::class);

        // Build a fake row with a CampaignBudget that has an ID
        $fakeBudget = new CampaignBudget();
        $fakeBudget->setId(99887766);

        $fakeRow = $this->createMock(\Google\Ads\GoogleAds\V20\Services\GoogleAdsRow::class);
        $fakeRow->method('getCampaignBudget')->willReturn($fakeBudget);

        $pagedResponse
            ->method('iterateAllElements')
            ->willReturn(new \ArrayIterator([$fakeRow]));

        $googleAdsServiceMock
            ->method('search')
            ->willReturn($pagedResponse);

        $googleAdsClientMock = $this->createMock(GoogleAdsClient::class);
        $googleAdsClientMock->method('getGoogleAdsServiceClient')->willReturn($googleAdsServiceMock);
        $this->setClientStaticInstance($googleAdsClientMock);

        $manager  = new Manager();
        $budgetId = $manager->getBudgetId(self::FAKE_CAMPAIGN_ID);

        $this->assertSame('99887766', $budgetId);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build a mock CampaignServiceClient that captures the first operation.
     */
    private function buildCampaignServiceMock(): array
    {
        $capture = new \stdClass();
        $capture->op = null;
        $fakeResponse = new MutateCampaignsResponse();

        $mock = $this->createMock(CampaignServiceClient::class);
        $mock->expects($this->once())
             ->method('mutateCampaigns')
             ->willReturnCallback(function (MutateCampaignsRequest $request) use ($capture, $fakeResponse) {
                 $capture->op = iterator_to_array($request->getOperations())[0];
                 return $fakeResponse;
             });

        return [$mock, $capture];
    }

    /**
     * Build a mock CampaignBudgetServiceClient that captures the first operation.
     */
    private function buildBudgetServiceMock(): array
    {
        $capture = new \stdClass();
        $capture->op = null;
        $fakeResponse = new MutateCampaignBudgetsResponse();

        $mock = $this->createMock(CampaignBudgetServiceClient::class);
        $mock->expects($this->once())
             ->method('mutateCampaignBudgets')
             ->willReturnCallback(function (MutateCampaignBudgetsRequest $request) use ($capture, $fakeResponse) {
                 $capture->op = iterator_to_array($request->getOperations())[0];
                 return $fakeResponse;
             });

        return [$mock, $capture];
    }

    private function injectMockClientWithCampaignService(CampaignServiceClient $mock): void
    {
        $googleAdsClientMock = $this->createMock(GoogleAdsClient::class);
        $googleAdsClientMock->method('getCampaignServiceClient')->willReturn($mock);
        $this->setClientStaticInstance($googleAdsClientMock);
    }

    private function injectMockClientWithBudgetService(CampaignBudgetServiceClient $mock): void
    {
        $googleAdsClientMock = $this->createMock(GoogleAdsClient::class);
        $googleAdsClientMock->method('getCampaignBudgetServiceClient')->willReturn($mock);
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
