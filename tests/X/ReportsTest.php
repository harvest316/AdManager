<?php

declare(strict_types=1);

namespace AdManager\Tests\X;

use AdManager\X\Client;
use AdManager\X\Reports;
use PHPUnit\Framework\TestCase;

/**
 * Tests for X\Reports.
 *
 * Reports calls Client::getRoot() against the X stats endpoint. We verify:
 * - campaignInsights() calls the correct stats endpoint
 * - campaignInsights() passes entity=CAMPAIGN
 * - campaignInsights() passes entity_ids with the campaign ID
 * - campaignInsights() passes BILLING,ENGAGEMENT metric groups
 * - campaignInsights() formats start/end dates with T00:00:00Z / T23:59:59Z
 * - Normalisation: billed_charge_local_micro (array) → cost_micros (summed int)
 * - Normalisation: impressions (array) → impressions (summed int)
 * - Normalisation: url_clicks (array) → clicks (summed int)
 * - Normalisation: conversion metrics → conversions (summed int)
 * - Empty response returns empty array
 * - Multiple rows are all normalised
 */
class ReportsTest extends TestCase
{
    private const FAKE_ACCOUNT_ID  = 'acc_111222';
    private const FAKE_CAMPAIGN_ID = 'cmp_987654';

    protected function tearDown(): void
    {
        Client::reset();
    }

    // -------------------------------------------------------------------------
    // campaignInsights() endpoint and params
    // -------------------------------------------------------------------------

    public function testCampaignInsightsCallsStatsEndpoint(): void
    {
        $capture = new \stdClass();
        $capture->endpoint = null;

        $this->injectMockClient($this->buildCapturingGetMock($capture));

        $reports = new Reports();
        $reports->campaignInsights(self::FAKE_CAMPAIGN_ID, '2026-01-01', '2026-01-31');

        $this->assertStringContainsString('stats/accounts', $capture->endpoint);
        $this->assertStringContainsString(self::FAKE_ACCOUNT_ID, $capture->endpoint);
    }

    public function testCampaignInsightsPassesEntityCampaign(): void
    {
        $capture = new \stdClass();
        $capture->params = null;

        $this->injectMockClient($this->buildCapturingGetMock($capture));

        $reports = new Reports();
        $reports->campaignInsights(self::FAKE_CAMPAIGN_ID, '2026-01-01', '2026-01-31');

        $this->assertSame('CAMPAIGN', $capture->params['entity']);
    }

    public function testCampaignInsightsPassesEntityIds(): void
    {
        $capture = new \stdClass();
        $capture->params = null;

        $this->injectMockClient($this->buildCapturingGetMock($capture));

        $reports = new Reports();
        $reports->campaignInsights(self::FAKE_CAMPAIGN_ID, '2026-01-01', '2026-01-31');

        $this->assertSame(self::FAKE_CAMPAIGN_ID, $capture->params['entity_ids']);
    }

    public function testCampaignInsightsPassesBillingAndEngagementMetricGroups(): void
    {
        $capture = new \stdClass();
        $capture->params = null;

        $this->injectMockClient($this->buildCapturingGetMock($capture));

        $reports = new Reports();
        $reports->campaignInsights(self::FAKE_CAMPAIGN_ID, '2026-01-01', '2026-01-31');

        $this->assertStringContainsString('BILLING', $capture->params['metric_groups']);
        $this->assertStringContainsString('ENGAGEMENT', $capture->params['metric_groups']);
    }

    public function testCampaignInsightsFormatsStartTimeWithT000000Z(): void
    {
        $capture = new \stdClass();
        $capture->params = null;

        $this->injectMockClient($this->buildCapturingGetMock($capture));

        $reports = new Reports();
        $reports->campaignInsights(self::FAKE_CAMPAIGN_ID, '2026-01-01', '2026-01-31');

        $this->assertSame('2026-01-01T00:00:00Z', $capture->params['start_time']);
    }

    public function testCampaignInsightsFormatsEndTimeWithT235959Z(): void
    {
        $capture = new \stdClass();
        $capture->params = null;

        $this->injectMockClient($this->buildCapturingGetMock($capture));

        $reports = new Reports();
        $reports->campaignInsights(self::FAKE_CAMPAIGN_ID, '2026-01-01', '2026-01-31');

        $this->assertSame('2026-01-31T23:59:59Z', $capture->params['end_time']);
    }

    public function testCampaignInsightsReturnsEmptyArrayWhenNoData(): void
    {
        $mock = $this->createMock(Client::class);
        $mock->method('accountId')->willReturn(self::FAKE_ACCOUNT_ID);
        $mock->method('getRoot')->willReturn([]);
        $this->injectMockClient($mock);

        $reports = new Reports();
        $result  = $reports->campaignInsights(self::FAKE_CAMPAIGN_ID, '2026-01-01', '2026-01-31');

        $this->assertSame([], $result);
    }

    // -------------------------------------------------------------------------
    // Metric normalisation
    // -------------------------------------------------------------------------

    public function testNormalisationSumsBilledChargeToCostMicros(): void
    {
        $raw = $this->buildRawRow(['billed_charge_local_micro' => [5_000_000, 3_000_000]]);

        $this->injectMockClient($this->buildGetMockReturning(['data' => [$raw]]));

        $reports = new Reports();
        $result  = $reports->campaignInsights(self::FAKE_CAMPAIGN_ID, '2026-01-01', '2026-01-31');

        $this->assertSame(8_000_000, $result[0]['cost_micros']);
    }

    public function testNormalisationSumsImpressionsArray(): void
    {
        $raw = $this->buildRawRow(['impressions' => [1000, 500, 250]]);

        $this->injectMockClient($this->buildGetMockReturning(['data' => [$raw]]));

        $reports = new Reports();
        $result  = $reports->campaignInsights(self::FAKE_CAMPAIGN_ID, '2026-01-01', '2026-01-31');

        $this->assertSame(1750, $result[0]['impressions']);
    }

    public function testNormalisationMapsUrlClicksToClicks(): void
    {
        $raw = $this->buildRawRow(['url_clicks' => [30, 20]]);

        $this->injectMockClient($this->buildGetMockReturning(['data' => [$raw]]));

        $reports = new Reports();
        $result  = $reports->campaignInsights(self::FAKE_CAMPAIGN_ID, '2026-01-01', '2026-01-31');

        $this->assertSame(50, $result[0]['clicks']);
    }

    public function testNormalisationSumsConversionMetrics(): void
    {
        $raw = $this->buildRawRow([
            'url_clicks'                   => [10],
            'conversion_purchases'         => [3, 2],
            'conversion_sign_ups'          => [1],
        ]);

        $this->injectMockClient($this->buildGetMockReturning(['data' => [$raw]]));

        $reports = new Reports();
        $result  = $reports->campaignInsights(self::FAKE_CAMPAIGN_ID, '2026-01-01', '2026-01-31');

        $this->assertSame(6, $result[0]['conversions']);
    }

    public function testNormalisationHandlesZeroMetrics(): void
    {
        $raw = $this->buildRawRow([]);

        $this->injectMockClient($this->buildGetMockReturning(['data' => [$raw]]));

        $reports = new Reports();
        $result  = $reports->campaignInsights(self::FAKE_CAMPAIGN_ID, '2026-01-01', '2026-01-31');

        $this->assertSame(0, $result[0]['impressions']);
        $this->assertSame(0, $result[0]['clicks']);
        $this->assertSame(0, $result[0]['cost_micros']);
        $this->assertSame(0, $result[0]['conversions']);
    }

    public function testNormalisationPreservesMultipleRows(): void
    {
        $rows = [
            $this->buildRawRow(['impressions' => [100]], 'cmp_1'),
            $this->buildRawRow(['impressions' => [200]], 'cmp_2'),
        ];

        $this->injectMockClient($this->buildGetMockReturning(['data' => $rows]));

        $reports = new Reports();
        $result  = $reports->campaignInsights(self::FAKE_CAMPAIGN_ID, '2026-01-01', '2026-01-31');

        $this->assertCount(2, $result);
        $this->assertSame(100, $result[0]['impressions']);
        $this->assertSame(200, $result[1]['impressions']);
    }

    public function testNormalisedRowContainsCampaignId(): void
    {
        $raw = $this->buildRawRow(['impressions' => [500]], self::FAKE_CAMPAIGN_ID);

        $this->injectMockClient($this->buildGetMockReturning(['data' => [$raw]]));

        $reports = new Reports();
        $result  = $reports->campaignInsights(self::FAKE_CAMPAIGN_ID, '2026-01-01', '2026-01-31');

        $this->assertSame(self::FAKE_CAMPAIGN_ID, $result[0]['campaign_id']);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build a raw X stats row in the id_data metrics shape.
     */
    private function buildRawRow(array $metrics, string $id = null): array
    {
        return [
            'id'      => $id ?? self::FAKE_CAMPAIGN_ID,
            'id_data' => [
                ['metrics' => $metrics],
            ],
        ];
    }

    private function buildCapturingGetMock(\stdClass $capture): Client
    {
        $mock = $this->createMock(Client::class);
        $mock->method('accountId')->willReturn(self::FAKE_ACCOUNT_ID);
        $mock->method('getRoot')
             ->willReturnCallback(function (string $endpoint, array $params) use ($capture): array {
                 $capture->endpoint = $endpoint;
                 $capture->params   = $params;
                 return ['data' => []];
             });
        return $mock;
    }

    private function buildGetMockReturning(array $response): Client
    {
        $mock = $this->createMock(Client::class);
        $mock->method('accountId')->willReturn(self::FAKE_ACCOUNT_ID);
        $mock->method('getRoot')->willReturn($response);
        return $mock;
    }

    private function injectMockClient(Client $client): void
    {
        $ref = new \ReflectionProperty(Client::class, 'instance');
        $ref->setAccessible(true);
        $ref->setValue(null, $client);
    }
}
