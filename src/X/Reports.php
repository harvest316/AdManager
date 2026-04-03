<?php

namespace AdManager\X;

/**
 * X (Twitter) Ads reporting.
 *
 * Uses the synchronous stats endpoint:
 *   GET stats/accounts/{account_id}?entity=CAMPAIGN&entity_ids=...
 *
 * Metric groups: BILLING, ENGAGEMENT, VIDEO
 *
 * Normalised output shape (matches Google/Meta convention):
 * [
 *   'impressions'  => int,
 *   'clicks'       => int,
 *   'cost_micros'  => int,   // billed_charge_local_micro
 *   'conversions'  => int,   // from conversion metrics if available
 * ]
 */
class Reports
{
    private Client $client;
    private string $accountId;

    public function __construct()
    {
        $this->client    = Client::get();
        $this->accountId = $this->client->accountId();
    }

    /**
     * Retrieve campaign-level insights for a date range.
     *
     * @param  string $campaignId Campaign ID
     * @param  string $startDate  Format: YYYY-MM-DD
     * @param  string $endDate    Format: YYYY-MM-DD
     * @return array  Normalised metric row(s)
     */
    public function campaignInsights(string $campaignId, string $startDate, string $endDate): array
    {
        $response = $this->client->getRoot(
            "stats/accounts/{$this->accountId}",
            [
                'entity'        => 'CAMPAIGN',
                'entity_ids'    => $campaignId,
                'start_time'    => $startDate . 'T00:00:00Z',
                'end_time'      => $endDate . 'T23:59:59Z',
                'metric_groups' => 'BILLING,ENGAGEMENT',
                'granularity'   => 'TOTAL',
                'placement'     => 'ALL_ON_TWITTER',
            ]
        );

        $rows = $response['data'] ?? [];

        return array_map([$this, 'normaliseRow'], $rows);
    }

    // ----------------------------------------------------------------
    // Internal
    // ----------------------------------------------------------------

    /**
     * Normalise a raw X stats row into the standard shape.
     *
     * @param  array $row Raw API row
     * @return array Normalised row
     */
    private function normaliseRow(array $row): array
    {
        $metrics = $row['id_data'][0]['metrics'] ?? $row['metrics'] ?? [];

        // billed_charge_local_micro comes back as an array of one value per granularity period
        $costMicros = 0;
        if (!empty($metrics['billed_charge_local_micro'])) {
            $costMicros = array_sum($metrics['billed_charge_local_micro']);
        }

        $impressions = 0;
        if (!empty($metrics['impressions'])) {
            $impressions = array_sum($metrics['impressions']);
        }

        // X calls clicks 'url_clicks'
        $clicks = 0;
        if (!empty($metrics['url_clicks'])) {
            $clicks = array_sum($metrics['url_clicks']);
        }

        // Conversions can come from various conversion metric keys
        $conversions = 0;
        foreach ($metrics as $key => $values) {
            if (str_contains($key, 'conversion') && is_array($values)) {
                $conversions += array_sum($values);
            }
        }

        return [
            'campaign_id'  => $row['id'] ?? null,
            'impressions'  => (int) $impressions,
            'clicks'       => (int) $clicks,
            'cost_micros'  => (int) $costMicros,
            'conversions'  => (int) $conversions,
        ];
    }
}
