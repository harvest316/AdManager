<?php

declare(strict_types=1);

namespace AdManager\Tests\Google;

use AdManager\Google\Client;
use AdManager\Google\Reports;
use Google\Ads\GoogleAds\Lib\V20\GoogleAdsClient;
use Google\Ads\GoogleAds\V20\Services\SearchGoogleAdsRequest;
use Google\Ads\GoogleAds\V20\Services\Client\GoogleAdsServiceClient;
use Google\ApiCore\PagedListResponse;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Reports::campaigns(), adGroups(), keywords(), searchTerms(), ads().
 *
 * We inject a mock GoogleAdsClient whose GoogleAdsServiceClient captures the
 * SearchGoogleAdsRequest passed to search(), allowing us to verify the GAQL
 * query includes the correct table, date range, and ordering.
 */
class ReportsTest extends TestCase
{
    private const FAKE_CUSTOMER_ID = '1234567890';

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
    // campaigns()
    // -------------------------------------------------------------------------

    public function testCampaignsQuerySelectsFromCampaignTable(): void
    {
        [$serviceMock, $capture] = $this->buildGoogleAdsServiceMock();
        $this->injectMockClient($serviceMock);

        $reports = new Reports();
        $reports->campaigns();

        $query = $capture->request->getQuery();
        $this->assertStringContainsString('FROM campaign', $query);
    }

    public function testCampaignsQueryIncludesCampaignFields(): void
    {
        [$serviceMock, $capture] = $this->buildGoogleAdsServiceMock();
        $this->injectMockClient($serviceMock);

        $reports = new Reports();
        $reports->campaigns();

        $query = $capture->request->getQuery();
        $this->assertStringContainsString('campaign.id', $query);
        $this->assertStringContainsString('campaign.name', $query);
        $this->assertStringContainsString('campaign.status', $query);
        $this->assertStringContainsString('metrics.impressions', $query);
        $this->assertStringContainsString('metrics.clicks', $query);
        $this->assertStringContainsString('metrics.cost_micros', $query);
        $this->assertStringContainsString('metrics.conversions', $query);
    }

    public function testCampaignsQueryIncludesImpressionShareFields(): void
    {
        [$serviceMock, $capture] = $this->buildGoogleAdsServiceMock();
        $this->injectMockClient($serviceMock);

        $reports = new Reports();
        $reports->campaigns();

        $query = $capture->request->getQuery();
        $this->assertStringContainsString('metrics.search_impression_share', $query);
        $this->assertStringContainsString('metrics.search_budget_lost_impression_share', $query);
        $this->assertStringContainsString('metrics.search_rank_lost_impression_share', $query);
    }

    public function testCampaignsUsesDefaultDateRange(): void
    {
        [$serviceMock, $capture] = $this->buildGoogleAdsServiceMock();
        $this->injectMockClient($serviceMock);

        $reports = new Reports();
        $reports->campaigns();

        $query = $capture->request->getQuery();
        $this->assertStringContainsString('LAST_7_DAYS', $query);
    }

    public function testCampaignsUsesCustomDateRange(): void
    {
        [$serviceMock, $capture] = $this->buildGoogleAdsServiceMock();
        $this->injectMockClient($serviceMock);

        $reports = new Reports();
        $reports->campaigns('LAST_30_DAYS');

        $query = $capture->request->getQuery();
        $this->assertStringContainsString('LAST_30_DAYS', $query);
    }

    public function testCampaignsOrdersByCostMicrosDesc(): void
    {
        [$serviceMock, $capture] = $this->buildGoogleAdsServiceMock();
        $this->injectMockClient($serviceMock);

        $reports = new Reports();
        $reports->campaigns();

        $query = $capture->request->getQuery();
        $this->assertStringContainsString('ORDER BY metrics.cost_micros DESC', $query);
    }

    public function testCampaignsPassesCorrectCustomerId(): void
    {
        [$serviceMock, $capture] = $this->buildGoogleAdsServiceMock();
        $this->injectMockClient($serviceMock);

        $reports = new Reports();
        $reports->campaigns();

        $this->assertSame(self::FAKE_CUSTOMER_ID, $capture->request->getCustomerId());
    }

    public function testCampaignsReturnsRowsFromStream(): void
    {
        $fakeRow1 = new \stdClass();
        $fakeRow2 = new \stdClass();

        [$serviceMock] = $this->buildGoogleAdsServiceMock([$fakeRow1, $fakeRow2]);
        $this->injectMockClient($serviceMock);

        $reports = new Reports();
        $result  = $reports->campaigns();

        $this->assertCount(2, $result);
        $this->assertSame($fakeRow1, $result[0]);
        $this->assertSame($fakeRow2, $result[1]);
    }

    public function testCampaignsReturnsEmptyArrayWhenNoRows(): void
    {
        [$serviceMock] = $this->buildGoogleAdsServiceMock([]);
        $this->injectMockClient($serviceMock);

        $reports = new Reports();
        $result  = $reports->campaigns();

        $this->assertSame([], $result);
    }

    // -------------------------------------------------------------------------
    // adGroups()
    // -------------------------------------------------------------------------

    public function testAdGroupsQuerySelectsFromAdGroupTable(): void
    {
        [$serviceMock, $capture] = $this->buildGoogleAdsServiceMock();
        $this->injectMockClient($serviceMock);

        $reports = new Reports();
        $reports->adGroups();

        $query = $capture->request->getQuery();
        $this->assertStringContainsString('FROM ad_group', $query);
    }

    public function testAdGroupsQueryIncludesAdGroupFields(): void
    {
        [$serviceMock, $capture] = $this->buildGoogleAdsServiceMock();
        $this->injectMockClient($serviceMock);

        $reports = new Reports();
        $reports->adGroups();

        $query = $capture->request->getQuery();
        $this->assertStringContainsString('ad_group.id', $query);
        $this->assertStringContainsString('ad_group.name', $query);
        $this->assertStringContainsString('ad_group.status', $query);
        $this->assertStringContainsString('campaign.name', $query);
    }

    public function testAdGroupsUsesCustomDateRange(): void
    {
        [$serviceMock, $capture] = $this->buildGoogleAdsServiceMock();
        $this->injectMockClient($serviceMock);

        $reports = new Reports();
        $reports->adGroups('THIS_MONTH');

        $query = $capture->request->getQuery();
        $this->assertStringContainsString('THIS_MONTH', $query);
    }

    public function testAdGroupsOrdersByCostMicrosDesc(): void
    {
        [$serviceMock, $capture] = $this->buildGoogleAdsServiceMock();
        $this->injectMockClient($serviceMock);

        $reports = new Reports();
        $reports->adGroups();

        $query = $capture->request->getQuery();
        $this->assertStringContainsString('ORDER BY metrics.cost_micros DESC', $query);
    }

    // -------------------------------------------------------------------------
    // keywords()
    // -------------------------------------------------------------------------

    public function testKeywordsQuerySelectsFromKeywordView(): void
    {
        [$serviceMock, $capture] = $this->buildGoogleAdsServiceMock();
        $this->injectMockClient($serviceMock);

        $reports = new Reports();
        $reports->keywords();

        $query = $capture->request->getQuery();
        $this->assertStringContainsString('FROM keyword_view', $query);
    }

    public function testKeywordsQueryIncludesKeywordFields(): void
    {
        [$serviceMock, $capture] = $this->buildGoogleAdsServiceMock();
        $this->injectMockClient($serviceMock);

        $reports = new Reports();
        $reports->keywords();

        $query = $capture->request->getQuery();
        $this->assertStringContainsString('ad_group_criterion.keyword.text', $query);
        $this->assertStringContainsString('ad_group_criterion.keyword.match_type', $query);
    }

    public function testKeywordsQueryIncludesQualityScoreFields(): void
    {
        [$serviceMock, $capture] = $this->buildGoogleAdsServiceMock();
        $this->injectMockClient($serviceMock);

        $reports = new Reports();
        $reports->keywords();

        $query = $capture->request->getQuery();
        $this->assertStringContainsString('ad_group_criterion.quality_info.quality_score', $query);
        $this->assertStringContainsString('ad_group_criterion.quality_info.post_click_quality_score', $query);
        $this->assertStringContainsString('ad_group_criterion.quality_info.creative_quality_score', $query);
    }

    public function testKeywordsQueryExcludesRemovedCriteria(): void
    {
        [$serviceMock, $capture] = $this->buildGoogleAdsServiceMock();
        $this->injectMockClient($serviceMock);

        $reports = new Reports();
        $reports->keywords();

        $query = $capture->request->getQuery();
        $this->assertStringContainsString("ad_group_criterion.status != 'REMOVED'", $query);
    }

    public function testKeywordsUsesCustomDateRange(): void
    {
        [$serviceMock, $capture] = $this->buildGoogleAdsServiceMock();
        $this->injectMockClient($serviceMock);

        $reports = new Reports();
        $reports->keywords('LAST_30_DAYS');

        $query = $capture->request->getQuery();
        $this->assertStringContainsString('LAST_30_DAYS', $query);
    }

    // -------------------------------------------------------------------------
    // searchTerms()
    // -------------------------------------------------------------------------

    public function testSearchTermsQuerySelectsFromSearchTermView(): void
    {
        [$serviceMock, $capture] = $this->buildGoogleAdsServiceMock();
        $this->injectMockClient($serviceMock);

        $reports = new Reports();
        $reports->searchTerms();

        $query = $capture->request->getQuery();
        $this->assertStringContainsString('FROM search_term_view', $query);
    }

    public function testSearchTermsQueryIncludesSearchTermFields(): void
    {
        [$serviceMock, $capture] = $this->buildGoogleAdsServiceMock();
        $this->injectMockClient($serviceMock);

        $reports = new Reports();
        $reports->searchTerms();

        $query = $capture->request->getQuery();
        $this->assertStringContainsString('search_term_view.search_term', $query);
        $this->assertStringContainsString('search_term_view.status', $query);
    }

    public function testSearchTermsUsesCustomDateRange(): void
    {
        [$serviceMock, $capture] = $this->buildGoogleAdsServiceMock();
        $this->injectMockClient($serviceMock);

        $reports = new Reports();
        $reports->searchTerms('THIS_MONTH');

        $query = $capture->request->getQuery();
        $this->assertStringContainsString('THIS_MONTH', $query);
    }

    public function testSearchTermsOrdersByCostMicrosDesc(): void
    {
        [$serviceMock, $capture] = $this->buildGoogleAdsServiceMock();
        $this->injectMockClient($serviceMock);

        $reports = new Reports();
        $reports->searchTerms();

        $query = $capture->request->getQuery();
        $this->assertStringContainsString('ORDER BY metrics.cost_micros DESC', $query);
    }

    // -------------------------------------------------------------------------
    // ads()
    // -------------------------------------------------------------------------

    public function testAdsQuerySelectsFromAdGroupAd(): void
    {
        [$serviceMock, $capture] = $this->buildGoogleAdsServiceMock();
        $this->injectMockClient($serviceMock);

        $reports = new Reports();
        $reports->ads();

        $query = $capture->request->getQuery();
        $this->assertStringContainsString('FROM ad_group_ad', $query);
    }

    public function testAdsQueryIncludesAdFields(): void
    {
        [$serviceMock, $capture] = $this->buildGoogleAdsServiceMock();
        $this->injectMockClient($serviceMock);

        $reports = new Reports();
        $reports->ads();

        $query = $capture->request->getQuery();
        $this->assertStringContainsString('ad_group_ad.ad.id', $query);
        $this->assertStringContainsString('ad_group_ad.ad.type', $query);
        $this->assertStringContainsString('ad_group_ad.status', $query);
        $this->assertStringContainsString('ad_group_ad.ad_strength', $query);
    }

    public function testAdsQueryExcludesRemovedAds(): void
    {
        [$serviceMock, $capture] = $this->buildGoogleAdsServiceMock();
        $this->injectMockClient($serviceMock);

        $reports = new Reports();
        $reports->ads();

        $query = $capture->request->getQuery();
        $this->assertStringContainsString("ad_group_ad.status != 'REMOVED'", $query);
    }

    public function testAdsUsesCustomDateRange(): void
    {
        [$serviceMock, $capture] = $this->buildGoogleAdsServiceMock();
        $this->injectMockClient($serviceMock);

        $reports = new Reports();
        $reports->ads('LAST_30_DAYS');

        $query = $capture->request->getQuery();
        $this->assertStringContainsString('LAST_30_DAYS', $query);
    }

    public function testAdsOrdersByCostMicrosDesc(): void
    {
        [$serviceMock, $capture] = $this->buildGoogleAdsServiceMock();
        $this->injectMockClient($serviceMock);

        $reports = new Reports();
        $reports->ads();

        $query = $capture->request->getQuery();
        $this->assertStringContainsString('ORDER BY metrics.cost_micros DESC', $query);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build a mock GoogleAdsServiceClient that captures the SearchGoogleAdsRequest.
     *
     * @param array $fakeRows Rows to return from iterateAllElements.
     * Returns [$serviceMock, &$capturedRequest].
     */
    private function buildGoogleAdsServiceMock(array $fakeRows = []): array
    {
        $capture = new \stdClass();
        $capture->request = null;

        $pagedResponse = $this->createMock(PagedListResponse::class);
        $pagedResponse
            ->method('iterateAllElements')
            ->willReturn(new \ArrayIterator($fakeRows));

        $serviceMock = $this->createMock(GoogleAdsServiceClient::class);
        $serviceMock
            ->expects($this->once())
            ->method('search')
            ->willReturnCallback(
                function (SearchGoogleAdsRequest $request) use ($capture, $pagedResponse) {
                    $capture->request = $request;
                    return $pagedResponse;
                }
            );

        return [$serviceMock, $capture];
    }

    private function injectMockClient(GoogleAdsServiceClient $googleAdsServiceMock): void
    {
        $googleAdsClientMock = $this->createMock(GoogleAdsClient::class);
        $googleAdsClientMock
            ->method('getGoogleAdsServiceClient')
            ->willReturn($googleAdsServiceMock);

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
