<?php

namespace AdManager\TikTok;

/**
 * TikTok Ads reporting / insights.
 *
 * Uses the integrated reporting endpoint (report/integrated/get/).
 * Results are normalised to a standard shape consistent with other platforms:
 *   impressions, clicks, cost_micros, conversions, conversion_value
 */
class Reports
{
    private Client $client;
    private string $advertiserId;

    private const DEFAULT_METRICS = [
        'spend',
        'impressions',
        'clicks',
        'conversion',
        'total_complete_payment_rate',
    ];

    public function __construct()
    {
        $this->client       = Client::get();
        $this->advertiserId = $this->client->advertiserId();
    }

    /**
     * Campaign-level performance insights.
     *
     * @param  string $startDate  Format: YYYY-MM-DD
     * @param  string $endDate    Format: YYYY-MM-DD
     * @param  array  $metrics    Override default metrics
     * @return array  Normalised insight rows
     */
    public function campaignInsights(string $startDate, string $endDate, array $metrics = []): array
    {
        $useMetrics = $metrics ?: self::DEFAULT_METRICS;

        $response = $this->client->post('report/integrated/get/', [
            'advertiser_id' => $this->advertiserId,
            'report_type'   => 'BASIC',
            'data_level'    => 'AUCTION_CAMPAIGN',
            'dimensions'    => ['campaign_id', 'stat_time_day'],
            'metrics'       => $useMetrics,
            'start_date'    => $startDate,
            'end_date'      => $endDate,
            'page_size'     => 1000,
        ]);

        $rows = $response['list'] ?? [];

        return array_map([$this, 'normalise'], $rows);
    }

    // ----------------------------------------------------------------
    // Internal
    // ----------------------------------------------------------------

    /**
     * Normalise a TikTok report row to the standard platform-agnostic shape.
     */
    private function normalise(array $row): array
    {
        $metrics = $row['metrics'] ?? [];
        $dims    = $row['dimensions'] ?? [];

        // TikTok reports spend in USD as a float string; convert to micros
        $spendUsd  = (float) ($metrics['spend'] ?? 0);
        $costMicros = (int) round($spendUsd * 1_000_000);

        return [
            'campaign_id'      => $dims['campaign_id'] ?? null,
            'date'             => $dims['stat_time_day'] ?? null,
            'impressions'      => (int) ($metrics['impressions'] ?? 0),
            'clicks'           => (int) ($metrics['clicks'] ?? 0),
            'cost_micros'      => $costMicros,
            'conversions'      => (float) ($metrics['conversion'] ?? 0),
            'conversion_value' => (float) ($metrics['total_complete_payment_rate'] ?? 0),
            '_raw'             => $row,
        ];
    }
}
