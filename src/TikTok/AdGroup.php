<?php

namespace AdManager\TikTok;

/**
 * TikTok ad group management.
 *
 * Ad groups sit between campaigns and ads. Key config:
 *   - placement_type: PLACEMENT_TYPE_AUTOMATIC | PLACEMENT_TYPE_NORMAL
 *   - budget_mode: BUDGET_MODE_DAY | BUDGET_MODE_TOTAL
 *   - billing_event: CPC | CPM | CPV | OCPM
 *   - pacing: PACING_MODE_SMOOTH | PACING_MODE_FAST
 */
class AdGroup
{
    private Client $client;
    private string $advertiserId;

    public function __construct()
    {
        $this->client       = Client::get();
        $this->advertiserId = $this->client->advertiserId();
    }

    /**
     * Create a new ad group.
     *
     * @param string $campaignId Parent campaign ID
     * @param array  $config [
     *   'name'             => 'MyBrand — Broad — AU',
     *   'placement_type'   => 'PLACEMENT_TYPE_AUTOMATIC',
     *   'budget_mode'      => 'BUDGET_MODE_DAY',
     *   'budget'           => 1000,
     *   'schedule_type'    => 'SCHEDULE_FROM_NOW',
     *   'schedule_start_time' => '2024-01-01 00:00:00',
     *   'billing_event'    => 'CPC',
     *   'pacing'           => 'PACING_MODE_SMOOTH',
     *   'operation_status' => 'DISABLE',
     * ]
     * @return string Ad group ID
     */
    public function create(string $campaignId, array $config): string
    {
        $data = array_merge([
            'advertiser_id'    => $this->advertiserId,
            'campaign_id'      => $campaignId,
            'adgroup_name'     => $config['name'],
            'placement_type'   => $config['placement_type'] ?? 'PLACEMENT_TYPE_AUTOMATIC',
            'budget_mode'      => $config['budget_mode'] ?? 'BUDGET_MODE_DAY',
            'budget'           => $config['budget'],
            'schedule_type'    => $config['schedule_type'] ?? 'SCHEDULE_FROM_NOW',
            'billing_event'    => $config['billing_event'] ?? 'CPC',
            'pacing'           => $config['pacing'] ?? 'PACING_MODE_SMOOTH',
            'operation_status' => $config['operation_status'] ?? 'DISABLE',
        ], array_filter($config, fn($k) => !in_array($k, [
            'name', 'placement_type', 'budget_mode', 'budget',
            'schedule_type', 'billing_event', 'pacing', 'operation_status',
        ]), ARRAY_FILTER_USE_KEY));

        $response = $this->client->post('adgroup/create/', $data);

        return (string) $response['adgroup_id'];
    }

    /**
     * Pause an ad group.
     */
    public function pause(string $adGroupId): void
    {
        $this->client->post('adgroup/update/status/', [
            'advertiser_id'    => $this->advertiserId,
            'adgroup_ids'      => [$adGroupId],
            'operation_status' => 'DISABLE',
        ]);
    }

    /**
     * Enable (activate) an ad group.
     */
    public function enable(string $adGroupId): void
    {
        $this->client->post('adgroup/update/status/', [
            'advertiser_id'    => $this->advertiserId,
            'adgroup_ids'      => [$adGroupId],
            'operation_status' => 'ENABLE',
        ]);
    }

    /**
     * List all ad groups (optionally filtered by campaign).
     *
     * @return array Array of ad group data
     */
    public function list(?string $campaignId = null): array
    {
        $params = ['advertiser_id' => $this->advertiserId];
        if ($campaignId !== null) {
            $params['filtering'] = json_encode(['campaign_ids' => [$campaignId]]);
        }

        $response = $this->client->get_api('adgroup/get/', $params);

        return $response['list'] ?? [];
    }

    /**
     * Get a single ad group's details.
     */
    public function get(string $adGroupId): array
    {
        $response = $this->client->get_api('adgroup/get/', [
            'advertiser_id' => $this->advertiserId,
            'filtering'     => json_encode(['adgroup_ids' => [$adGroupId]]),
        ]);

        $list = $response['list'] ?? [];

        return $list[0] ?? [];
    }
}
