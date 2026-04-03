<?php

namespace AdManager\TikTok;

/**
 * TikTok campaign management.
 *
 * Objectives: REACH, TRAFFIC, VIDEO_VIEWS, LEAD_GENERATION, APP_PROMOTION,
 *             CONVERSIONS, CATALOG_SALES, RF_REACH
 *
 * Budget modes: BUDGET_MODE_DAY (daily), BUDGET_MODE_TOTAL (lifetime)
 *
 * Operation statuses: ENABLE (active), DISABLE (paused)
 */
class Campaign
{
    private Client $client;
    private string $advertiserId;

    public function __construct()
    {
        $this->client       = Client::get();
        $this->advertiserId = $this->client->advertiserId();
    }

    /**
     * Create a new campaign.
     *
     * @param array $config [
     *   'name'             => 'MyBrand — TT — AU',
     *   'objective_type'   => 'TRAFFIC',
     *   'budget_mode'      => 'BUDGET_MODE_DAY',   // default BUDGET_MODE_DAY
     *   'budget'           => 2000,                 // in cents
     *   'operation_status' => 'DISABLE',            // default DISABLE (paused)
     * ]
     * @return string Campaign ID
     */
    public function create(array $config): string
    {
        $data = [
            'advertiser_id'    => $this->advertiserId,
            'campaign_name'    => $config['name'],
            'objective_type'   => $config['objective_type'],
            'budget_mode'      => $config['budget_mode'] ?? 'BUDGET_MODE_DAY',
            'budget'           => $config['budget'],
            'operation_status' => $config['operation_status'] ?? 'DISABLE',
        ];

        $response = $this->client->post('campaign/create/', $data);

        return (string) $response['campaign_id'];
    }

    /**
     * Pause a campaign.
     */
    public function pause(string $campaignId): void
    {
        $this->client->post('campaign/update/status/', [
            'advertiser_id' => $this->advertiserId,
            'campaign_ids'  => [$campaignId],
            'operation_status' => 'DISABLE',
        ]);
    }

    /**
     * Enable (activate) a campaign.
     */
    public function enable(string $campaignId): void
    {
        $this->client->post('campaign/update/status/', [
            'advertiser_id' => $this->advertiserId,
            'campaign_ids'  => [$campaignId],
            'operation_status' => 'ENABLE',
        ]);
    }

    /**
     * List all campaigns for the advertiser.
     *
     * @return array Array of campaign data
     */
    public function list(): array
    {
        $response = $this->client->get_api('campaign/get/', [
            'advertiser_id' => $this->advertiserId,
        ]);

        return $response['list'] ?? [];
    }

    /**
     * Get a single campaign's details.
     */
    public function get(string $campaignId): array
    {
        $response = $this->client->get_api('campaign/get/', [
            'advertiser_id' => $this->advertiserId,
            'filtering'     => json_encode(['campaign_ids' => [$campaignId]]),
        ]);

        $list = $response['list'] ?? [];

        return $list[0] ?? [];
    }
}
