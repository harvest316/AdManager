<?php

namespace AdManager\Adsterra;

/**
 * Adsterra reporting / statistics.
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
     * @param  string $startDate  Format: YYYY-MM-DD
     * @param  string $endDate    Format: YYYY-MM-DD
     * @return array  Normalised insight rows
     */
    public function campaignInsights(string $startDate, string $endDate): array
    {
        $response = $this->client->get_api('advertising/stats', [
            'date_from' => $startDate,
            'date_to'   => $endDate,
            'group_by'  => 'day',
        ]);

        $rows = $response['data'] ?? $response;

        if (!is_array($rows)) {
            return [];
        }

        return array_map([$this, 'normalise'], $rows);
    }

    // ----------------------------------------------------------------
    // Internal
    // ----------------------------------------------------------------

    /**
     * Normalise an Adsterra stats row to the standard platform-agnostic shape.
     */
    private function normalise(array $row): array
    {
        // Adsterra reports spend as a float; convert to micros
        $spend      = (float) ($row['spent'] ?? $row['spend'] ?? $row['cost'] ?? 0);
        $costMicros = (int) round($spend * 1_000_000);

        return [
            'campaign_id'      => $row['campaign_id'] ?? null,
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
