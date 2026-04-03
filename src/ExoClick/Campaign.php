<?php

namespace AdManager\ExoClick;

/**
 * ExoClick campaign management.
 *
 * Status values: 0 = paused, 1 = active
 */
class Campaign
{
    private Client $client;

    public function __construct()
    {
        $this->client = Client::get();
    }

    /**
     * Create a new campaign.
     *
     * @param array $config [
     *   'name'           => 'MyBrand — Adult — AU',
     *   'daily_budget'   => 2000,        // in cents
     *   'category_group' => 1,           // ExoClick category group ID
     *   'status'         => 0,           // default 0 (paused)
     * ]
     * @return string Campaign ID
     */
    public function create(array $config): string
    {
        $data = [
            'name'           => $config['name'],
            'status'         => $config['status'] ?? 0,
            'daily_budget'   => $config['daily_budget'],
            'category_group' => $config['category_group'],
        ];

        $response = $this->client->post('campaigns', $data);

        return (string) $response['id'];
    }

    /**
     * Pause a campaign.
     */
    public function pause(string $campaignId): void
    {
        $this->client->put("campaigns/{$campaignId}", ['status' => 0]);
    }

    /**
     * Enable (activate) a campaign.
     */
    public function enable(string $campaignId): void
    {
        $this->client->put("campaigns/{$campaignId}", ['status' => 1]);
    }

    /**
     * List all campaigns.
     *
     * @return array Array of campaign data
     */
    public function list(): array
    {
        $response = $this->client->get_api('campaigns');

        // ExoClick may return a wrapper or direct array
        return $response['data'] ?? $response;
    }

    /**
     * Get a single campaign's details.
     */
    public function get(string $campaignId): array
    {
        return $this->client->get_api("campaigns/{$campaignId}");
    }
}
