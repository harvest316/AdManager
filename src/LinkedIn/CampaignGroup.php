<?php

namespace AdManager\LinkedIn;

/**
 * LinkedIn Ads campaign group management.
 *
 * A campaign group is LinkedIn's top-level container (equivalent to a campaign
 * in Meta/Google). Campaigns (ad groups) sit beneath campaign groups.
 *
 * Statuses: ACTIVE, PAUSED, ARCHIVED, CANCELED, DRAFT
 *
 * LinkedIn uses URNs throughout — all IDs are returned as
 * urn:li:sponsoredCampaignGroup:{id}
 */
class CampaignGroup
{
    private Client $client;
    private string $adAccountUrn;

    public function __construct()
    {
        $this->client       = Client::get();
        $this->adAccountUrn = $this->client->adAccountUrn();
    }

    /**
     * Create a new campaign group.
     *
     * @param array $config [
     *   'name'            => 'MyBrand — Q2 2026',
     *   'status'          => 'PAUSED',     // default PAUSED
     *   'runSchedule'     => [             // optional
     *       'start' => 1743465600000,      // epoch ms
     *       'end'   => 1751241600000,      // epoch ms (optional)
     *   ],
     * ]
     * @return string Campaign group URN
     */
    public function create(array $config): string
    {
        $data = [
            'account'      => $this->adAccountUrn,
            'name'         => $config['name'],
            'status'       => $config['status'] ?? 'PAUSED',
        ];

        if (!empty($config['runSchedule'])) {
            $data['runSchedule'] = $config['runSchedule'];
        }

        $response = $this->client->post('adCampaignGroups', $data);

        // LinkedIn returns URN in 'id' or 'data.id'
        return $response['id'] ?? ($response['data']['id'] ?? '');
    }

    /**
     * Pause a campaign group.
     */
    public function pause(string $campaignGroupUrn): void
    {
        $this->client->post("adCampaignGroups/{$this->urnToId($campaignGroupUrn)}", [
            'patch' => [
                '$set' => ['status' => 'PAUSED'],
            ],
        ]);
    }

    /**
     * Enable (activate) a campaign group.
     */
    public function enable(string $campaignGroupUrn): void
    {
        $this->client->post("adCampaignGroups/{$this->urnToId($campaignGroupUrn)}", [
            'patch' => [
                '$set' => ['status' => 'ACTIVE'],
            ],
        ]);
    }

    /**
     * List campaign groups for the ad account.
     *
     * @return array Array of campaign group data
     */
    public function list(): array
    {
        $response = $this->client->get_api('adCampaignGroups', [
            'q'       => 'search',
            'search'  => '(account:' . $this->adAccountUrn . ')',
            'count'   => 100,
        ]);

        return $response['elements'] ?? [];
    }

    /**
     * Get a single campaign group's details.
     */
    public function get(string $campaignGroupUrn): array
    {
        $response = $this->client->get_api("adCampaignGroups/{$this->urnToId($campaignGroupUrn)}");

        return $response;
    }

    // ----------------------------------------------------------------
    // Internal
    // ----------------------------------------------------------------

    /**
     * Extract numeric ID from a URN or pass through if already numeric.
     * e.g. "urn:li:sponsoredCampaignGroup:123456" → "123456"
     */
    private function urnToId(string $urn): string
    {
        if (str_contains($urn, ':')) {
            return (string) substr($urn, strrpos($urn, ':') + 1);
        }
        return $urn;
    }
}
