<?php

namespace AdManager\ExoClick;

/**
 * ExoClick reporting / statistics.
 *
 * Results are normalised to a standard shape consistent with other platforms:
 *   impressions, clicks, cost_micros, conversions, conversion_value
 */
class Reports
{
    private Client $client;

    public function __construct()
    {
        $this->client = Client::get();
    }

    /**
     * Campaign-level performance insights.
     *
     * @param  string $campaignId Campaign ID to filter by
     * @param  string $startDate  Format: YYYY-MM-DD
     * @param  string $endDate    Format: YYYY-MM-DD
     * @return array  Normalised insight rows
     */
    public function campaignInsights(string $campaignId, string $startDate, string $endDate): array
    {
        $response = $this->client->get_api('statistics', [
            'campaign_id' => $campaignId,
            'date_from'   => $startDate,
            'date_to'     => $endDate,
            'group_by'    => 'date',
        ]);

        $rows = $response['data'] ?? $response;

        if (!is_array($rows)) {
            return [];
        }

        return array_map(fn(array $row) => $this->normalise($row, $campaignId), $rows);
    }

    // ----------------------------------------------------------------
    // Internal
    // ----------------------------------------------------------------

    /**
     * Normalise an ExoClick stats row to the standard platform-agnostic shape.
     */
    private function normalise(array $row, string $campaignId): array
    {
        // ExoClick reports spend in EUR/USD as a float; convert to micros
        $spend      = (float) ($row['revenue'] ?? $row['spend'] ?? $row['cost'] ?? 0);
        $costMicros = (int) round($spend * 1_000_000);

        return [
            'campaign_id'      => $campaignId,
            'date'             => $row['date'] ?? null,
            'impressions'      => (int) ($row['impressions'] ?? 0),
            'clicks'           => (int) ($row['clicks'] ?? 0),
            'cost_micros'      => $costMicros,
            'conversions'      => (float) ($row['conversions'] ?? 0),
            'conversion_value' => (float) ($row['conversion_value'] ?? 0),
            '_raw'             => $row,
        ];
    }
}
