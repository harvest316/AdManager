<?php

namespace AdManager\X;

/**
 * X (Twitter) Ads line item management.
 *
 * A line item is X's equivalent of an ad group — it sits beneath a campaign
 * and controls targeting, bidding, and placements.
 *
 * Product types: PROMOTED_TWEETS, PROMOTED_ACCOUNT, PROMOTED_TREND
 * Objectives:    TWEET_ENGAGEMENTS, WEBSITE_CLICKS, APP_INSTALLS, FOLLOWERS, etc.
 * Placements:    ALL_ON_TWITTER, PUBLISHER_NETWORK
 */
class LineItem
{
    private Client $client;

    public function __construct()
    {
        $this->client = Client::get();
    }

    /**
     * Create a new line item (ad group).
     *
     * @param string $campaignId Parent campaign ID
     * @param array  $config [
     *   'name'                      => 'AU — Retargeting',
     *   'product_type'              => 'PROMOTED_TWEETS',
     *   'placements'                => ['ALL_ON_TWITTER'],
     *   'objective'                 => 'WEBSITE_CLICKS',
     *   'bid_amount_local_micro'    => 1500000,   // $1.50 in micros
     *   'entity_status'             => 'PAUSED',  // default PAUSED
     * ]
     * @return string Line item ID
     */
    public function create(string $campaignId, array $config): string
    {
        $data = [
            'campaign_id'            => $campaignId,
            'name'                   => $config['name'],
            'product_type'           => $config['product_type'],
            'placements'             => $config['placements'],
            'objective'              => $config['objective'],
            'bid_amount_local_micro' => $config['bid_amount_local_micro'],
            'entity_status'          => $config['entity_status'] ?? 'PAUSED',
        ];

        $response = $this->client->post('line_items', $data);

        return $response['data']['id'];
    }

    /**
     * Pause a line item.
     */
    public function pause(string $lineItemId): void
    {
        $this->client->put("line_items/{$lineItemId}", ['entity_status' => 'PAUSED']);
    }

    /**
     * Enable (activate) a line item.
     */
    public function enable(string $lineItemId): void
    {
        $this->client->put("line_items/{$lineItemId}", ['entity_status' => 'ACTIVE']);
    }

    /**
     * List all line items, optionally filtered by campaign.
     *
     * @param  string|null $campaignId Filter by campaign (null = all)
     * @return array
     */
    public function list(?string $campaignId = null): array
    {
        $params = [];
        if ($campaignId) {
            $params['campaign_ids'] = $campaignId;
        }

        $response = $this->client->get_api('line_items', $params);

        return $response['data'] ?? [];
    }

    /**
     * Get a single line item's details.
     */
    public function get(string $lineItemId): array
    {
        $response = $this->client->get_api("line_items/{$lineItemId}");

        return $response['data'] ?? [];
    }
}
