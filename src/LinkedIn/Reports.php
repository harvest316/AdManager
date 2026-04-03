<?php

namespace AdManager\LinkedIn;

/**
 * LinkedIn Ads reporting.
 *
 * Uses the adAnalytics endpoint:
 *   GET adAnalytics?q=analytics&dateRange.start.year=...&campaigns=List(urn:...)&fields=...
 *
 * Normalised output shape (matches Google/Meta/X convention):
 * [
 *   'campaign_urn' => string,
 *   'impressions'  => int,
 *   'clicks'       => int,
 *   'cost_micros'  => int,   // costInLocalCurrency × 1_000_000
 *   'conversions'  => int,   // externalWebsiteConversions
 * ]
 */
class Reports
{
    private Client $client;
    private string $adAccountUrn;

    public function __construct()
    {
        $this->client       = Client::get();
        $this->adAccountUrn = $this->client->adAccountUrn();
    }

    /**
     * Retrieve campaign-level analytics for a date range.
     *
     * @param  string $campaignUrn Campaign URN (e.g. urn:li:sponsoredCampaign:123456)
     * @param  string $startDate   Format: YYYY-MM-DD
     * @param  string $endDate     Format: YYYY-MM-DD
     * @return array  Normalised metric row(s)
     */
    public function campaignInsights(string $campaignUrn, string $startDate, string $endDate): array
    {
        [$sy, $sm, $sd] = explode('-', $startDate);
        [$ey, $em, $ed] = explode('-', $endDate);

        $response = $this->client->get_api('adAnalytics', [
            'q'                          => 'analytics',
            'dateRange.start.year'       => (int) $sy,
            'dateRange.start.month'      => (int) $sm,
            'dateRange.start.day'        => (int) $sd,
            'dateRange.end.year'         => (int) $ey,
            'dateRange.end.month'        => (int) $em,
            'dateRange.end.day'          => (int) $ed,
            'campaigns'                  => 'List(' . $campaignUrn . ')',
            'fields'                     => 'impressions,clicks,costInLocalCurrency,externalWebsiteConversions',
            'timeGranularity'            => 'ALL',
        ]);

        $elements = $response['elements'] ?? [];

        return array_map(function (array $row) use ($campaignUrn): array {
            return $this->normaliseRow($row, $campaignUrn);
        }, $elements);
    }

    // ----------------------------------------------------------------
    // Internal
    // ----------------------------------------------------------------

    /**
     * Normalise a raw LinkedIn analytics element into the standard shape.
     *
     * LinkedIn returns costInLocalCurrency as a decimal string (e.g. "9.87").
     * We multiply by 1_000_000 to convert to micros, matching Google/Meta/X.
     */
    private function normaliseRow(array $row, string $campaignUrn): array
    {
        $costRaw   = $row['costInLocalCurrency'] ?? '0';
        $costMicros = (int) round((float) $costRaw * 1_000_000);

        return [
            'campaign_urn' => $campaignUrn,
            'impressions'  => (int) ($row['impressions'] ?? 0),
            'clicks'       => (int) ($row['clicks'] ?? 0),
            'cost_micros'  => $costMicros,
            'conversions'  => (int) ($row['externalWebsiteConversions'] ?? 0),
        ];
    }
}
