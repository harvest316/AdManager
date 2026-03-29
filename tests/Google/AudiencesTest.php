<?php

declare(strict_types=1);

namespace AdManager\Tests\Google;

use AdManager\Google\Audiences;
use AdManager\Google\Client;
use Google\Ads\GoogleAds\Lib\V20\GoogleAdsClient;
use Google\Ads\GoogleAds\V20\Services\Client\CampaignCriterionServiceClient;
use Google\Ads\GoogleAds\V20\Services\Client\GoogleAdsServiceClient;
use Google\Ads\GoogleAds\V20\Services\MutateCampaignCriteriaRequest;
use Google\Ads\GoogleAds\V20\Services\MutateCampaignCriteriaResponse;
use Google\Ads\GoogleAds\V20\Services\MutateCampaignCriterionResult;
use Google\Ads\GoogleAds\V20\Services\SearchGoogleAdsRequest;
use Google\ApiCore\PagedListResponse;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Audiences.
 *
 * All Google Ads API calls are mocked via PHPUnit's createMock().
 * We inject a fake GoogleAdsClient into the Client singleton using
 * reflection (same pattern as Campaign\SearchTest).
 *
 * Test coverage:
 * - attachToCampaign: OBSERVATION mode (no TARGETING), bid modifier passed correctly
 * - attachToCampaign: invalid bid modifier throws InvalidArgumentException
 * - detachFromCampaign: remove operation constructed correctly
 * - updateBidModifier: update operation with correct field mask
 * - updateBidModifier: invalid bid modifier throws InvalidArgumentException
 * - listCampaignAudiences: GAQL contains USER_LIST filter
 * - listUserLists: GAQL contains user_list fields
 */
class AudiencesTest extends TestCase
{
    private const FAKE_CUSTOMER_ID     = '9876543210';
    private const FAKE_CAMPAIGN_RN     = 'customers/9876543210/campaigns/100';
    private const FAKE_USER_LIST_RN    = 'customers/9876543210/userLists/200';
    private const FAKE_CRITERION_RN    = 'customers/9876543210/campaignCriteria/100~200';

    // -------------------------------------------------------------------------
    // Setup / Teardown
    // -------------------------------------------------------------------------

    protected function setUp(): void
    {
        $this->setClientEnv([
            'GOOGLE_ADS_CLIENT_ID'       => 'fake-client-id',
            'GOOGLE_ADS_CLIENT_SECRET'   => 'fake-secret',
            'GOOGLE_ADS_DEVELOPER_TOKEN' => 'fake-token',
            'GOOGLE_ADS_REFRESH_TOKEN'   => 'fake-refresh',
            'GOOGLE_ADS_CUSTOMER_ID'     => self::FAKE_CUSTOMER_ID,
        ]);
    }

    protected function tearDown(): void
    {
        $this->setClientEnv([]);
        $this->setClientInstance(null);
    }

    // -------------------------------------------------------------------------
    // attachToCampaign — bid modifier
    // -------------------------------------------------------------------------

    public function testAttachToCampaignPassesBidModifierThroughToOperation(): void
    {
        $capture = new \stdClass();
        $capture->request = null;

        $fakeResult = new MutateCampaignCriterionResult();
        $fakeResult->setResourceName(self::FAKE_CRITERION_RN);
        $fakeResponse = new MutateCampaignCriteriaResponse();
        $fakeResponse->setResults([$fakeResult]);

        $criterionServiceMock = $this->createMock(CampaignCriterionServiceClient::class);
        $criterionServiceMock->method('mutateCampaignCriteria')
            ->willReturnCallback(function (MutateCampaignCriteriaRequest $req) use ($capture, $fakeResponse) {
                $capture->request = $req;
                return $fakeResponse;
            });

        $this->injectMockClient($criterionServiceMock);

        $audiences = new Audiences();
        $audiences->attachToCampaign(self::FAKE_CAMPAIGN_RN, self::FAKE_USER_LIST_RN, 1.5);

        $ops = iterator_to_array($capture->request->getOperations());
        $this->assertCount(1, $ops);

        $criterion = $ops[0]->getCreate();
        $this->assertEqualsWithDelta(1.5, $criterion->getBidModifier(), 0.0001);
    }

    public function testAttachToCampaignSetsUserListResourceName(): void
    {
        $capture = new \stdClass();
        $capture->request = null;

        $fakeResult = new MutateCampaignCriterionResult();
        $fakeResult->setResourceName(self::FAKE_CRITERION_RN);
        $fakeResponse = new MutateCampaignCriteriaResponse();
        $fakeResponse->setResults([$fakeResult]);

        $criterionServiceMock = $this->createMock(CampaignCriterionServiceClient::class);
        $criterionServiceMock->method('mutateCampaignCriteria')
            ->willReturnCallback(function (MutateCampaignCriteriaRequest $req) use ($capture, $fakeResponse) {
                $capture->request = $req;
                return $fakeResponse;
            });

        $this->injectMockClient($criterionServiceMock);

        $audiences = new Audiences();
        $audiences->attachToCampaign(self::FAKE_CAMPAIGN_RN, self::FAKE_USER_LIST_RN);

        $ops = iterator_to_array($capture->request->getOperations());
        $criterion = $ops[0]->getCreate();

        // UserListInfo.user_list should be set to the user list resource name
        $this->assertSame(self::FAKE_USER_LIST_RN, $criterion->getUserList()->getUserList());
    }

    public function testAttachToCampaignUsesCreateOperationNotUpdateOrRemove(): void
    {
        $capture = new \stdClass();
        $capture->op = null;

        $fakeResult = new MutateCampaignCriterionResult();
        $fakeResult->setResourceName(self::FAKE_CRITERION_RN);
        $fakeResponse = new MutateCampaignCriteriaResponse();
        $fakeResponse->setResults([$fakeResult]);

        $criterionServiceMock = $this->createMock(CampaignCriterionServiceClient::class);
        $criterionServiceMock->method('mutateCampaignCriteria')
            ->willReturnCallback(function (MutateCampaignCriteriaRequest $req) use ($capture, $fakeResponse) {
                $ops = iterator_to_array($req->getOperations());
                $capture->op = $ops[0];
                return $fakeResponse;
            });

        $this->injectMockClient($criterionServiceMock);

        $audiences = new Audiences();
        $audiences->attachToCampaign(self::FAKE_CAMPAIGN_RN, self::FAKE_USER_LIST_RN);

        // The operation must use setCreate (not setUpdate/setRemove)
        $this->assertNotNull($capture->op->getCreate(), 'Operation must use create, not update/remove');
        // Proto returns '' (empty string) for unset string fields — verify remove is not set
        $this->assertSame('', $capture->op->getRemove(), 'Remove resource name should be empty for attach');
    }

    public function testAttachToCampaignReturnsResourceName(): void
    {
        $fakeResult = new MutateCampaignCriterionResult();
        $fakeResult->setResourceName(self::FAKE_CRITERION_RN);
        $fakeResponse = new MutateCampaignCriteriaResponse();
        $fakeResponse->setResults([$fakeResult]);

        $criterionServiceMock = $this->createMock(CampaignCriterionServiceClient::class);
        $criterionServiceMock->method('mutateCampaignCriteria')->willReturn($fakeResponse);

        $this->injectMockClient($criterionServiceMock);

        $audiences = new Audiences();
        $result = $audiences->attachToCampaign(self::FAKE_CAMPAIGN_RN, self::FAKE_USER_LIST_RN);

        $this->assertSame(self::FAKE_CRITERION_RN, $result);
    }

    public function testAttachToCampaignDefaultBidModifierIsOne(): void
    {
        $capture = new \stdClass();
        $capture->request = null;

        $fakeResult = new MutateCampaignCriterionResult();
        $fakeResult->setResourceName(self::FAKE_CRITERION_RN);
        $fakeResponse = new MutateCampaignCriteriaResponse();
        $fakeResponse->setResults([$fakeResult]);

        $criterionServiceMock = $this->createMock(CampaignCriterionServiceClient::class);
        $criterionServiceMock->method('mutateCampaignCriteria')
            ->willReturnCallback(function (MutateCampaignCriteriaRequest $req) use ($capture, $fakeResponse) {
                $capture->request = $req;
                return $fakeResponse;
            });

        $this->injectMockClient($criterionServiceMock);

        $audiences = new Audiences();
        $audiences->attachToCampaign(self::FAKE_CAMPAIGN_RN, self::FAKE_USER_LIST_RN);

        $ops = iterator_to_array($capture->request->getOperations());
        $criterion = $ops[0]->getCreate();
        $this->assertEqualsWithDelta(1.0, $criterion->getBidModifier(), 0.0001);
    }

    // -------------------------------------------------------------------------
    // attachToCampaign — bid modifier validation
    // -------------------------------------------------------------------------

    public function testAttachToCampaignThrowsOnBidModifierBelowMinimum(): void
    {
        $this->injectMockClient($this->createMock(CampaignCriterionServiceClient::class));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/bid_modifier/i');

        $audiences = new Audiences();
        $audiences->attachToCampaign(self::FAKE_CAMPAIGN_RN, self::FAKE_USER_LIST_RN, 0.05);
    }

    public function testAttachToCampaignThrowsOnBidModifierAboveMaximum(): void
    {
        $this->injectMockClient($this->createMock(CampaignCriterionServiceClient::class));

        $this->expectException(\InvalidArgumentException::class);

        $audiences = new Audiences();
        $audiences->attachToCampaign(self::FAKE_CAMPAIGN_RN, self::FAKE_USER_LIST_RN, 11.0);
    }

    public function testAttachToCampaignAllowsZeroBidModifierToExcludeAudience(): void
    {
        $fakeResult = new MutateCampaignCriterionResult();
        $fakeResult->setResourceName(self::FAKE_CRITERION_RN);
        $fakeResponse = new MutateCampaignCriteriaResponse();
        $fakeResponse->setResults([$fakeResult]);

        $criterionServiceMock = $this->createMock(CampaignCriterionServiceClient::class);
        $criterionServiceMock->method('mutateCampaignCriteria')->willReturn($fakeResponse);

        $this->injectMockClient($criterionServiceMock);

        $audiences = new Audiences();
        // 0.0 = exclude audience — should not throw
        $result = $audiences->attachToCampaign(self::FAKE_CAMPAIGN_RN, self::FAKE_USER_LIST_RN, 0.0);
        $this->assertSame(self::FAKE_CRITERION_RN, $result);
    }

    public function testAttachToCampaignAllowsMaximumBidModifier(): void
    {
        $fakeResult = new MutateCampaignCriterionResult();
        $fakeResult->setResourceName(self::FAKE_CRITERION_RN);
        $fakeResponse = new MutateCampaignCriteriaResponse();
        $fakeResponse->setResults([$fakeResult]);

        $criterionServiceMock = $this->createMock(CampaignCriterionServiceClient::class);
        $criterionServiceMock->method('mutateCampaignCriteria')->willReturn($fakeResponse);

        $this->injectMockClient($criterionServiceMock);

        $audiences = new Audiences();
        $result = $audiences->attachToCampaign(self::FAKE_CAMPAIGN_RN, self::FAKE_USER_LIST_RN, 10.0);
        $this->assertSame(self::FAKE_CRITERION_RN, $result);
    }

    // -------------------------------------------------------------------------
    // detachFromCampaign
    // -------------------------------------------------------------------------

    public function testDetachFromCampaignSendsRemoveOperation(): void
    {
        $capture = new \stdClass();
        $capture->op = null;

        $fakeResponse = new MutateCampaignCriteriaResponse();

        $criterionServiceMock = $this->createMock(CampaignCriterionServiceClient::class);
        $criterionServiceMock->method('mutateCampaignCriteria')
            ->willReturnCallback(function (MutateCampaignCriteriaRequest $req) use ($capture, $fakeResponse) {
                $ops = iterator_to_array($req->getOperations());
                $capture->op = $ops[0];
                return $fakeResponse;
            });

        $this->injectMockClient($criterionServiceMock);

        $audiences = new Audiences();
        $audiences->detachFromCampaign(self::FAKE_CAMPAIGN_RN, self::FAKE_CRITERION_RN);

        $this->assertSame(self::FAKE_CRITERION_RN, $capture->op->getRemove());
        $this->assertNull($capture->op->getCreate(), 'Create should not be set for detach');
    }

    // -------------------------------------------------------------------------
    // updateBidModifier
    // -------------------------------------------------------------------------

    public function testUpdateBidModifierSendsUpdateOperation(): void
    {
        $capture = new \stdClass();
        $capture->op = null;

        $fakeResponse = new MutateCampaignCriteriaResponse();

        $criterionServiceMock = $this->createMock(CampaignCriterionServiceClient::class);
        $criterionServiceMock->method('mutateCampaignCriteria')
            ->willReturnCallback(function (MutateCampaignCriteriaRequest $req) use ($capture, $fakeResponse) {
                $ops = iterator_to_array($req->getOperations());
                $capture->op = $ops[0];
                return $fakeResponse;
            });

        $this->injectMockClient($criterionServiceMock);

        $audiences = new Audiences();
        $audiences->updateBidModifier(self::FAKE_CRITERION_RN, 2.0);

        $criterion = $capture->op->getUpdate();
        $this->assertNotNull($criterion, 'Operation must use update');
        $this->assertSame(self::FAKE_CRITERION_RN, $criterion->getResourceName());
        $this->assertEqualsWithDelta(2.0, $criterion->getBidModifier(), 0.0001);
    }

    public function testUpdateBidModifierThrowsOnInvalidValue(): void
    {
        $this->injectMockClient($this->createMock(CampaignCriterionServiceClient::class));

        $this->expectException(\InvalidArgumentException::class);

        $audiences = new Audiences();
        $audiences->updateBidModifier(self::FAKE_CRITERION_RN, -1.0);
    }

    public function testUpdateBidModifierSetsUpdateMask(): void
    {
        $capture = new \stdClass();
        $capture->op = null;

        $fakeResponse = new MutateCampaignCriteriaResponse();

        $criterionServiceMock = $this->createMock(CampaignCriterionServiceClient::class);
        $criterionServiceMock->method('mutateCampaignCriteria')
            ->willReturnCallback(function (MutateCampaignCriteriaRequest $req) use ($capture, $fakeResponse) {
                $ops = iterator_to_array($req->getOperations());
                $capture->op = $ops[0];
                return $fakeResponse;
            });

        $this->injectMockClient($criterionServiceMock);

        $audiences = new Audiences();
        $audiences->updateBidModifier(self::FAKE_CRITERION_RN, 1.5);

        $this->assertNotNull($capture->op->getUpdateMask(), 'UpdateMask should be set for update operations');
    }

    // -------------------------------------------------------------------------
    // listCampaignAudiences — GAQL query construction
    // -------------------------------------------------------------------------

    public function testListCampaignAudiencesGaqlFiltersOnUserListType(): void
    {
        $capture = new \stdClass();
        $capture->query = null;

        [$serviceMock] = $this->buildGoogleAdsServiceMock([], $capture);
        $this->injectGoogleAdsServiceMock($serviceMock);

        $audiences = new Audiences();
        $audiences->listCampaignAudiences(self::FAKE_CAMPAIGN_RN);

        $this->assertNotNull($capture->query);
        $this->assertStringContainsString("'USER_LIST'", $capture->query);
    }

    public function testListCampaignAudiencesGaqlFiltersByCampaignResourceName(): void
    {
        $capture = new \stdClass();
        $capture->query = null;

        [$serviceMock] = $this->buildGoogleAdsServiceMock([], $capture);
        $this->injectGoogleAdsServiceMock($serviceMock);

        $audiences = new Audiences();
        $audiences->listCampaignAudiences(self::FAKE_CAMPAIGN_RN);

        $this->assertStringContainsString(self::FAKE_CAMPAIGN_RN, $capture->query);
    }

    public function testListCampaignAudiencesReturnsEmptyArrayWhenNoResults(): void
    {
        [$serviceMock] = $this->buildGoogleAdsServiceMock([]);
        $this->injectGoogleAdsServiceMock($serviceMock);

        $audiences = new Audiences();
        $result = $audiences->listCampaignAudiences(self::FAKE_CAMPAIGN_RN);

        $this->assertSame([], $result);
    }

    // -------------------------------------------------------------------------
    // listUserLists — GAQL query construction
    // -------------------------------------------------------------------------

    public function testListUserListsGaqlSelectsRequiredFields(): void
    {
        $capture = new \stdClass();
        $capture->query = null;

        [$serviceMock] = $this->buildGoogleAdsServiceMock([], $capture);
        $this->injectGoogleAdsServiceMock($serviceMock);

        $audiences = new Audiences();
        $audiences->listUserLists();

        $this->assertNotNull($capture->query);
        // Must select the four required fields
        $this->assertStringContainsString('user_list.id', $capture->query);
        $this->assertStringContainsString('user_list.name', $capture->query);
        $this->assertStringContainsString('user_list.size_for_search', $capture->query);
        $this->assertStringContainsString('user_list.membership_status', $capture->query);
    }

    public function testListUserListsReturnsEmptyArrayWhenNoResults(): void
    {
        [$serviceMock] = $this->buildGoogleAdsServiceMock([]);
        $this->injectGoogleAdsServiceMock($serviceMock);

        $audiences = new Audiences();
        $result = $audiences->listUserLists();

        $this->assertSame([], $result);
    }

    // -------------------------------------------------------------------------
    // Helpers — mock injection
    // -------------------------------------------------------------------------

    /**
     * Inject a mock CampaignCriterionServiceClient into a fake GoogleAdsClient.
     */
    private function injectMockClient(CampaignCriterionServiceClient $criterionMock): void
    {
        $googleAdsClientMock = $this->createMock(GoogleAdsClient::class);
        $googleAdsClientMock->method('getCampaignCriterionServiceClient')->willReturn($criterionMock);
        $this->setClientInstance($googleAdsClientMock);
    }

    /**
     * Inject a mock GoogleAdsServiceClient (used for GAQL queries) into a fake GoogleAdsClient.
     */
    private function injectGoogleAdsServiceMock(GoogleAdsServiceClient $serviceClient): void
    {
        $googleAdsClientMock = $this->createMock(GoogleAdsClient::class);
        $googleAdsClientMock->method('getGoogleAdsServiceClient')->willReturn($serviceClient);
        $this->setClientInstance($googleAdsClientMock);
    }

    /**
     * Build a mock GoogleAdsServiceClient that captures the GAQL query.
     * Returns [$serviceMock, $capture] where $capture->query is set on call.
     */
    private function buildGoogleAdsServiceMock(array $fakeRows = [], ?\stdClass $capture = null): array
    {
        if ($capture === null) {
            $capture = new \stdClass();
            $capture->query = null;
        }

        $pagedResponse = $this->createMock(PagedListResponse::class);
        $pagedResponse->method('iterateAllElements')
            ->willReturn(new \ArrayIterator($fakeRows));

        $serviceMock = $this->createMock(GoogleAdsServiceClient::class);
        $serviceMock->method('search')
            ->willReturnCallback(function (SearchGoogleAdsRequest $request) use ($capture, $pagedResponse) {
                $capture->query = $request->getQuery();
                return $pagedResponse;
            });

        return [$serviceMock, $capture];
    }

    private function setClientEnv(array $env): void
    {
        $ref = new \ReflectionProperty(Client::class, 'env');
        $ref->setAccessible(true);
        $ref->setValue(null, $env);
    }

    private function setClientInstance(?GoogleAdsClient $client): void
    {
        $ref = new \ReflectionProperty(Client::class, 'instance');
        $ref->setAccessible(true);
        $ref->setValue(null, $client);
    }
}
