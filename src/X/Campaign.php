<?php

namespace AdManager\X;

/**
 * X (Twitter) Ads campaign management.
 *
 * Campaign statuses: ACTIVE, PAUSED, DELETED
 *
 * Notes:
 * - X uses PUT for updates (not POST like Meta/Facebook).
 * - Budgets are expressed in local micro-currency (e.g. AUD cents × 10000).
 *   $10.00 AUD daily = 10_000_000 micros.
 * - funding_instrument_id is required. Provide via config or X_ADS_FUNDING_INSTRUMENT_ID env.
 */
class Campaign
{
    private Client $client;
    private string $accountId;

    public function __construct()
    {
        $this->client    = Client::get();
        $this->accountId = $this->client->accountId();
    }

    /**
     * Create a new campaign.
     *
     * @param array $config [
     *   'name'                       => 'MyBrand — X — AU',
     *   'funding_instrument_id'      => 'abc123',     // falls back to X_ADS_FUNDING_INSTRUMENT_ID
     *   'daily_budget_amount_local_micro' => 10000000, // $10.00 in micros
     *   'entity_status'              => 'PAUSED',     // default PAUSED
     * ]
     * @return string Campaign ID
     */
    public function create(array $config): string
    {
        $fundingInstrumentId = $config['funding_instrument_id']
            ?? ($_ENV['X_ADS_FUNDING_INSTRUMENT_ID'] ?? null);

        if (!$fundingInstrumentId) {
            throw new \RuntimeException(
                'X campaign requires funding_instrument_id — '
                . 'set in config or X_ADS_FUNDING_INSTRUMENT_ID env var'
            );
        }

        $data = [
            'name'                            => $config['name'],
            'funding_instrument_id'           => $fundingInstrumentId,
            'daily_budget_amount_local_micro' => $config['daily_budget_amount_local_micro'],
            'entity_status'                   => $config['entity_status'] ?? 'PAUSED',
        ];

        $response = $this->client->post('campaigns', $data);

        return $response['data']['id'];
    }

    /**
     * Pause a campaign (PUT entity_status=PAUSED).
     */
    public function pause(string $campaignId): void
    {
        $this->client->put("campaigns/{$campaignId}", ['entity_status' => 'PAUSED']);
    }

    /**
     * Enable (activate) a campaign (PUT entity_status=ACTIVE).
     */
    public function enable(string $campaignId): void
    {
        $this->client->put("campaigns/{$campaignId}", ['entity_status' => 'ACTIVE']);
    }

    /**
     * List all campaigns for the ad account.
     *
     * @return array Array of campaign data
     */
    public function list(): array
    {
        $response = $this->client->get_api('campaigns');

        return $response['data'] ?? [];
    }

    /**
     * Get a single campaign's details.
     */
    public function get(string $campaignId): array
    {
        $response = $this->client->get_api("campaigns/{$campaignId}");

        return $response['data'] ?? [];
    }
}
