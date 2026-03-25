<?php

namespace AdManager\Meta;

/**
 * Meta ad set management.
 *
 * Ad sets control budget, schedule, targeting, and optimization.
 *
 * Optimization goals:
 *   REACH, LINK_CLICKS, IMPRESSIONS, LANDING_PAGE_VIEWS,
 *   CONVERSIONS, LEAD_GENERATION, OFFSITE_CONVERSIONS
 */
class AdSet
{
    private Client $client;
    private string $adAccountId;

    public function __construct()
    {
        $this->client      = Client::get();
        $this->adAccountId = $this->client->adAccountId();
    }

    /**
     * Create a new ad set.
     *
     * @param string $campaignId Parent campaign ID
     * @param array  $config [
     *   'name'              => 'AU — 30-60 — Interest Targeting',
     *   'daily_budget'      => 1000,              // in cents
     *   'optimization_goal' => 'LINK_CLICKS',
     *   'billing_event'     => 'IMPRESSIONS',      // default
     *   'targeting'         => [
     *       'geo_locations' => ['countries' => ['AU']],
     *       'age_min'       => 30,
     *       'age_max'       => 60,
     *       'interests'     => [['id' => '6003139266461', 'name' => 'Small business']],
     *   ],
     *   'start_time'        => '2026-04-01T00:00:00+1100', // ISO 8601
     *   'end_time'          => null,                        // optional
     *   'status'            => 'PAUSED',                    // default
     * ]
     * @return string Ad set ID
     */
    public function create(string $campaignId, array $config): string
    {
        $data = [
            'campaign_id'       => $campaignId,
            'name'              => $config['name'],
            'daily_budget'      => $config['daily_budget'],
            'optimization_goal' => $config['optimization_goal'],
            'billing_event'     => $config['billing_event'] ?? 'IMPRESSIONS',
            'targeting'         => json_encode($config['targeting']),
            'status'            => $config['status'] ?? 'PAUSED',
        ];

        if (!empty($config['start_time'])) {
            $data['start_time'] = $config['start_time'];
        }
        if (!empty($config['end_time'])) {
            $data['end_time'] = $config['end_time'];
        }

        // Promoted object (required for some optimization goals)
        if (!empty($config['promoted_object'])) {
            $data['promoted_object'] = json_encode($config['promoted_object']);
        }

        $response = $this->client->post("{$this->adAccountId}/adsets", $data);

        return $response['id'];
    }

    /**
     * Pause an ad set.
     */
    public function pause(string $adSetId): void
    {
        $this->client->post($adSetId, ['status' => 'PAUSED']);
    }

    /**
     * Enable (activate) an ad set.
     */
    public function enable(string $adSetId): void
    {
        $this->client->post($adSetId, ['status' => 'ACTIVE']);
    }

    /**
     * List ad sets, optionally filtered by campaign.
     *
     * @param string|null $campaignId Filter by campaign (null = all)
     * @return array
     */
    public function list(?string $campaignId = null): array
    {
        $fields = 'name,status,daily_budget,lifetime_budget,optimization_goal,'
                . 'billing_event,targeting,start_time,end_time,created_time';

        $params = [
            'fields' => $fields,
            'limit'  => 100,
        ];

        if ($campaignId) {
            $params['filtering'] = json_encode([
                ['field' => 'campaign.id', 'operator' => 'EQUAL', 'value' => $campaignId],
            ]);
        }

        $response = $this->client->get_api("{$this->adAccountId}/adsets", $params);

        return $response['data'] ?? [];
    }

    /**
     * Get a single ad set's details.
     */
    public function get(string $adSetId): array
    {
        $fields = 'name,status,daily_budget,lifetime_budget,optimization_goal,'
                . 'billing_event,targeting,start_time,end_time,created_time,'
                . 'updated_time,campaign_id';

        return $this->client->get_api($adSetId, [
            'fields' => $fields,
        ]);
    }
}
