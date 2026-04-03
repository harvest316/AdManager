<?php

namespace AdManager\LinkedIn;

/**
 * LinkedIn Ads campaign management.
 *
 * A LinkedIn campaign is equivalent to an ad group in Meta/Google — it sits
 * beneath a campaign group and controls budget, targeting, and bidding.
 *
 * Campaign types: SPONSORED_UPDATES, TEXT_AD, SPONSORED_INMAILS, DYNAMIC
 * Cost types:     CPM, CPC, CPV, CPF
 * Objective types: WEBSITE_VISITS, LEAD_GENERATION, BRAND_AWARENESS, etc.
 */
class Campaign
{
    private Client $client;
    private string $adAccountUrn;

    public function __construct()
    {
        $this->client       = Client::get();
        $this->adAccountUrn = $this->client->adAccountUrn();
    }

    /**
     * Create a new campaign.
     *
     * @param string $campaignGroupUrn Parent campaign group URN
     * @param array  $config [
     *   'name'          => 'AU — Retargeting — CPM',
     *   'objectiveType' => 'WEBSITE_VISITS',
     *   'type'          => 'SPONSORED_UPDATES',  // default
     *   'costType'      => 'CPM',                // default
     *   'dailyBudget'   => [
     *       'amount'       => '10.00',
     *       'currencyCode' => 'AUD',
     *   ],
     *   'status'        => 'PAUSED',             // default PAUSED
     * ]
     * @return string Campaign URN
     */
    public function create(string $campaignGroupUrn, array $config): string
    {
        $data = [
            'account'       => $this->adAccountUrn,
            'campaignGroup' => $campaignGroupUrn,
            'name'          => $config['name'],
            'objectiveType' => $config['objectiveType'],
            'type'          => $config['type'] ?? 'SPONSORED_UPDATES',
            'costType'      => $config['costType'] ?? 'CPM',
            'status'        => $config['status'] ?? 'PAUSED',
        ];

        if (!empty($config['dailyBudget'])) {
            $data['dailyBudget'] = [
                'amount'       => $config['dailyBudget']['amount'],
                'currencyCode' => $config['dailyBudget']['currencyCode'] ?? 'AUD',
            ];
        }

        if (!empty($config['unitCost'])) {
            $data['unitCost'] = $config['unitCost'];
        }

        if (!empty($config['targeting'])) {
            $data['targetingCriteria'] = $config['targeting'];
        }

        $response = $this->client->post('adCampaigns', $data);

        return $response['id'] ?? ($response['data']['id'] ?? '');
    }

    /**
     * Pause a campaign.
     */
    public function pause(string $campaignUrn): void
    {
        $this->update($campaignUrn, ['status' => 'PAUSED']);
    }

    /**
     * Enable (activate) a campaign.
     */
    public function enable(string $campaignUrn): void
    {
        $this->update($campaignUrn, ['status' => 'ACTIVE']);
    }

    /**
     * List campaigns, optionally filtered by campaign group.
     *
     * @param  string|null $campaignGroupUrn Filter by group (null = all for account)
     * @return array
     */
    public function list(?string $campaignGroupUrn = null): array
    {
        $params = [
            'q'     => 'search',
            'count' => 100,
        ];

        if ($campaignGroupUrn) {
            $params['search'] = '(campaignGroup:' . $campaignGroupUrn . ')';
        } else {
            $params['search'] = '(account:' . $this->adAccountUrn . ')';
        }

        $response = $this->client->get_api('adCampaigns', $params);

        return $response['elements'] ?? [];
    }

    /**
     * Get a single campaign's details.
     */
    public function get(string $campaignUrn): array
    {
        $response = $this->client->get_api('adCampaigns/' . $this->urnToId($campaignUrn));

        return $response;
    }

    /**
     * Update a campaign's fields.
     *
     * @param string $campaignUrn Campaign URN or ID
     * @param array  $data        Fields to update (e.g. ['status' => 'ACTIVE', 'dailyBudget' => [...]])
     */
    public function update(string $campaignUrn, array $data): void
    {
        $this->client->post('adCampaigns/' . $this->urnToId($campaignUrn), [
            'patch' => [
                '$set' => $data,
            ],
        ]);
    }

    // ----------------------------------------------------------------
    // Internal
    // ----------------------------------------------------------------

    private function urnToId(string $urn): string
    {
        if (str_contains($urn, ':')) {
            return (string) substr($urn, strrpos($urn, ':') + 1);
        }
        return $urn;
    }
}
