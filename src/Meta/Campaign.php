<?php

namespace AdManager\Meta;

/**
 * Meta (Facebook/Instagram) campaign management.
 *
 * Objectives (API v20.0+):
 *   OUTCOME_AWARENESS, OUTCOME_TRAFFIC, OUTCOME_ENGAGEMENT,
 *   OUTCOME_LEADS, OUTCOME_SALES, OUTCOME_APP_PROMOTION
 */
class Campaign
{
    private Client $client;
    private string $adAccountId;

    public function __construct()
    {
        $this->client      = Client::get();
        $this->adAccountId = $this->client->adAccountId();
    }

    /**
     * Create a new campaign.
     *
     * @param array $config [
     *   'name'                 => 'Audit&Fix — FB — AU',
     *   'objective'            => 'OUTCOME_SALES',
     *   'status'               => 'PAUSED',           // default PAUSED
     *   'special_ad_categories' => [],                 // e.g. ['HOUSING']
     *   'daily_budget'         => 2000,                // in cents (optional, can set on ad set)
     *   'lifetime_budget'      => null,                // in cents (optional)
     * ]
     * @return string Campaign ID
     */
    public function create(array $config): string
    {
        $data = [
            'name'                  => $config['name'],
            'objective'             => $config['objective'],
            'status'                => $config['status'] ?? 'PAUSED',
            'special_ad_categories' => json_encode($config['special_ad_categories'] ?? []),
        ];

        if (!empty($config['daily_budget'])) {
            $data['daily_budget'] = $config['daily_budget'];
        }
        if (!empty($config['lifetime_budget'])) {
            $data['lifetime_budget'] = $config['lifetime_budget'];
        }

        $response = $this->client->post("{$this->adAccountId}/campaigns", $data);

        return $response['id'];
    }

    /**
     * Pause a campaign.
     */
    public function pause(string $campaignId): void
    {
        $this->client->post($campaignId, ['status' => 'PAUSED']);
    }

    /**
     * Enable (activate) a campaign.
     */
    public function enable(string $campaignId): void
    {
        $this->client->post($campaignId, ['status' => 'ACTIVE']);
    }

    /**
     * List all campaigns for the ad account.
     *
     * @return array Array of campaign data
     */
    public function list(): array
    {
        $fields = 'name,status,objective,daily_budget,lifetime_budget,created_time';

        $response = $this->client->get_api("{$this->adAccountId}/campaigns", [
            'fields' => $fields,
            'limit'  => 100,
        ]);

        return $response['data'] ?? [];
    }

    /**
     * Get a single campaign's details.
     */
    public function get(string $campaignId): array
    {
        $fields = 'name,status,objective,daily_budget,lifetime_budget,'
                . 'created_time,updated_time,start_time,stop_time,'
                . 'special_ad_categories,buying_type';

        return $this->client->get_api($campaignId, [
            'fields' => $fields,
        ]);
    }
}
