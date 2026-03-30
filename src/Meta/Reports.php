<?php

namespace AdManager\Meta;

/**
 * Meta Ads reporting / insights.
 *
 * Date presets: last_7d, last_14d, last_30d, this_month, last_month,
 *               this_quarter, last_quarter, this_year, last_year, maximum
 */
class Reports
{
    private Client $client;
    private string $adAccountId;

    /** Default insight fields for all report types. */
    private const DEFAULT_FIELDS = [
        'impressions',
        'clicks',
        'ctr',
        'cpc',
        'cpm',
        'spend',
        'actions',
        'action_values',
        'cost_per_action_type',
        'frequency',
        'reach',
        'unique_clicks',
        'unique_ctr',
        'cost_per_unique_click',
    ];

    public function __construct()
    {
        $this->client      = Client::get();
        $this->adAccountId = $this->client->adAccountId();
    }

    /**
     * Campaign-level performance insights.
     *
     * @param  string $campaignId Campaign ID
     * @param  string $dateRange  Date preset (default 'last_7d')
     * @param  array  $fields     Override default fields
     * @return array  Insight rows
     */
    public function campaignInsights(string $campaignId, string $dateRange = 'last_7d', array $fields = []): array
    {
        return $this->insights("{$campaignId}/insights", $dateRange, $fields);
    }

    /**
     * Ad set-level performance insights.
     */
    public function adSetInsights(string $adSetId, string $dateRange = 'last_7d', array $fields = []): array
    {
        return $this->insights("{$adSetId}/insights", $dateRange, $fields);
    }

    /**
     * Ad-level performance insights.
     */
    public function adInsights(string $adId, string $dateRange = 'last_7d', array $fields = []): array
    {
        return $this->insights("{$adId}/insights", $dateRange, $fields);
    }

    /**
     * Account-level performance insights (all campaigns combined).
     */
    public function accountInsights(string $dateRange = 'last_7d', array $fields = []): array
    {
        return $this->insights("{$this->adAccountId}/insights", $dateRange, $fields);
    }

    /**
     * Campaign insights aggregated by campaign (one row per campaign).
     * Useful for the report CLI — lists all campaigns with their metrics.
     */
    public function allCampaignInsights(string $dateRange = 'last_7d', array $fields = []): array
    {
        $useFields = $fields ?: self::DEFAULT_FIELDS;
        // Add campaign_name to identify each campaign
        $useFields = array_unique(array_merge(['campaign_id', 'campaign_name'], $useFields));

        $response = $this->client->get_api("{$this->adAccountId}/insights", [
            'fields'      => implode(',', $useFields),
            'date_preset' => $dateRange,
            'level'       => 'campaign',
            'limit'       => 100,
        ]);

        return $response['data'] ?? [];
    }

    // ----------------------------------------------------------------
    // Internal
    // ----------------------------------------------------------------

    private function insights(string $endpoint, string $dateRange, array $fields): array
    {
        $useFields = $fields ?: self::DEFAULT_FIELDS;

        $response = $this->client->get_api($endpoint, [
            'fields'      => implode(',', $useFields),
            'date_preset' => $dateRange,
        ]);

        return $response['data'] ?? [];
    }
}
