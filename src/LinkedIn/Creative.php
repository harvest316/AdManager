<?php

namespace AdManager\LinkedIn;

/**
 * LinkedIn Ads creative management.
 *
 * A creative is LinkedIn's ad unit — it associates content (post URN, image,
 * copy) with a campaign for delivery.
 *
 * Creative types are handled via the `creativeType` field:
 *   SPONSORED_STATUS_UPDATE, SPONSORED_VIDEO, SPONSORED_IMAGE, etc.
 */
class Creative
{
    private Client $client;

    public function __construct()
    {
        $this->client = Client::get();
    }

    /**
     * Create a new creative.
     *
     * @param string $campaignUrn Campaign URN
     * @param array  $config [
     *   'reference'   => 'urn:li:ugcPost:123456',  // or 'urn:li:share:...'
     *   'status'      => 'PAUSED',                  // default PAUSED
     *   'type'        => 'SPONSORED_STATUS_UPDATE', // optional, inferred from reference
     * ]
     * @return string Creative URN
     */
    public function create(string $campaignUrn, array $config): string
    {
        $data = [
            'campaign'  => $campaignUrn,
            'reference' => $config['reference'],
            'status'    => $config['status'] ?? 'PAUSED',
        ];

        if (!empty($config['type'])) {
            $data['type'] = $config['type'];
        }

        if (!empty($config['variables'])) {
            $data['variables'] = $config['variables'];
        }

        $response = $this->client->post('creatives', $data);

        return $response['id'] ?? ($response['data']['id'] ?? '');
    }

    /**
     * List creatives for a campaign.
     *
     * @param  string $campaignUrn Campaign URN
     * @return array
     */
    public function list(string $campaignUrn): array
    {
        $response = $this->client->get_api('creatives', [
            'q'      => 'criteria',
            'search' => '(campaign:' . $campaignUrn . ')',
            'count'  => 100,
        ]);

        return $response['elements'] ?? [];
    }

    /**
     * Get a single creative's details.
     */
    public function get(string $creativeUrn): array
    {
        return $this->client->get_api('creatives/' . $this->urnToId($creativeUrn));
    }

    /**
     * Pause a creative.
     */
    public function pause(string $creativeUrn): void
    {
        $this->client->post('creatives/' . $this->urnToId($creativeUrn), [
            'patch' => ['$set' => ['status' => 'PAUSED']],
        ]);
    }

    /**
     * Enable (activate) a creative.
     */
    public function enable(string $creativeUrn): void
    {
        $this->client->post('creatives/' . $this->urnToId($creativeUrn), [
            'patch' => ['$set' => ['status' => 'ACTIVE']],
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
