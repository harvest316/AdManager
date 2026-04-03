<?php

namespace AdManager\Adsterra;

/**
 * Adsterra campaign management.
 *
 * Status values: 'active' = running, 'suspended' = paused
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
     *   'name'         => 'MyBrand — Push — AU',
     *   'daily_budget' => 2000,          // in cents
     *   'status'       => 'suspended',   // default 'suspended' (paused)
     * ]
     * @return string Campaign ID
     */
    public function create(array $config): string
    {
        $data = [
            'name'         => $config['name'],
            'status'       => $config['status'] ?? 'suspended',
            'daily_budget' => $config['daily_budget'],
        ];

        // Pass through any extra config fields (targeting, format, etc.)
        foreach ($config as $key => $value) {
            if (!isset($data[$key])) {
                $data[$key] = $value;
            }
        }

        $response = $this->client->post('advertising/campaigns', $data);

        return (string) $response['id'];
    }

    /**
     * Pause a campaign.
     */
    public function pause(string $campaignId): void
    {
        $this->client->patch("advertising/campaigns/{$campaignId}", ['status' => 'suspended']);
    }

    /**
     * Enable (activate) a campaign.
     */
    public function enable(string $campaignId): void
    {
        $this->client->patch("advertising/campaigns/{$campaignId}", ['status' => 'active']);
    }

    /**
     * List all campaigns.
     *
     * @return array Array of campaign data
     */
    public function list(): array
    {
        $response = $this->client->get_api('advertising/campaigns');

        return $response['data'] ?? $response;
    }

    /**
     * Get a single campaign's details.
     */
    public function get(string $campaignId): array
    {
        return $this->client->get_api("advertising/campaigns/{$campaignId}");
    }
}
