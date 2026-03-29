<?php

namespace AdManager\Meta;

/**
 * Meta ad and ad creative management.
 *
 * Supports image, video, and carousel creative types.
 */
class Ad
{
    private Client $client;
    private string $adAccountId;

    public function __construct()
    {
        $this->client      = Client::get();
        $this->adAccountId = $this->client->adAccountId();
    }

    /**
     * Create an ad linking an ad set to a creative.
     *
     * @param string $adSetId    Parent ad set ID
     * @param string $creativeId Creative ID
     * @param string $name       Ad name
     * @param string $status     PAUSED or ACTIVE (default PAUSED)
     * @return string Ad ID
     */
    public function create(string $adSetId, string $creativeId, string $name, string $status = 'PAUSED'): string
    {
        $response = $this->client->post("{$this->adAccountId}/ads", [
            'adset_id' => $adSetId,
            'creative' => json_encode(['creative_id' => $creativeId]),
            'name'     => $name,
            'status'   => $status,
        ]);

        return $response['id'];
    }

    /**
     * Create an ad creative.
     *
     * Image creative example:
     *   $config = [
     *       'name' => 'Creative — Image — v1',
     *       'object_story_spec' => [
     *           'page_id'   => '123456789',
     *           'link_data' => [
     *               'message'        => 'Get your free website audit!',
     *               'link'           => 'https://auditandfix.com',
     *               'image_hash'     => 'abc123...',
     *               'call_to_action' => ['type' => 'LEARN_MORE', 'value' => ['link' => 'https://auditandfix.com']],
     *           ],
     *       ],
     *   ];
     *
     * Video creative example:
     *   $config = [
     *       'name' => 'Creative — Video — v1',
     *       'object_story_spec' => [
     *           'page_id'    => '123456789',
     *           'video_data' => [
     *               'video_id'       => '987654321',
     *               'message'        => 'Watch how we improved this site...',
     *               'title'          => 'Free Website Audit',
     *               'call_to_action' => ['type' => 'LEARN_MORE', 'value' => ['link' => 'https://auditandfix.com']],
     *           ],
     *       ],
     *   ];
     *
     * Carousel creative example:
     *   $config = [
     *       'name' => 'Creative — Carousel — v1',
     *       'object_story_spec' => [
     *           'page_id'   => '123456789',
     *           'link_data' => [
     *               'message'           => 'See our success stories',
     *               'child_attachments' => [
     *                   ['link' => 'https://...', 'image_hash' => '...', 'name' => 'Card 1', 'description' => '...'],
     *                   ['link' => 'https://...', 'image_hash' => '...', 'name' => 'Card 2', 'description' => '...'],
     *               ],
     *           ],
     *       ],
     *   ];
     *
     * @return string Creative ID
     */
    public function createCreative(array $config): string
    {
        $data = [
            'name' => $config['name'],
        ];

        if (isset($config['object_story_spec'])) {
            $data['object_story_spec'] = json_encode($config['object_story_spec']);
        }

        // Optional fields
        if (isset($config['url_tags'])) {
            $data['url_tags'] = $config['url_tags'];
        }

        $response = $this->client->post("{$this->adAccountId}/adcreatives", $data);

        return $response['id'];
    }

    /**
     * List ads, optionally filtered by ad set.
     *
     * @param string|null $adSetId Filter by ad set (null = all)
     * @return array
     */
    public function list(?string $adSetId = null): array
    {
        $fields = 'name,status,adset_id,creative,created_time,updated_time';

        $params = [
            'fields' => $fields,
            'limit'  => 100,
        ];

        if ($adSetId) {
            $params['filtering'] = json_encode([
                ['field' => 'adset.id', 'operator' => 'EQUAL', 'value' => $adSetId],
            ]);
        }

        $response = $this->client->get_api("{$this->adAccountId}/ads", $params);

        return $response['data'] ?? [];
    }

    /**
     * Get a single ad's details.
     */
    public function get(string $adId): array
    {
        return $this->client->get_api($adId, [
            'fields' => 'name,status,adset_id,creative,created_time,updated_time,tracking_specs',
        ]);
    }

    /**
     * Pause a live ad.
     *
     * @param string $adId Meta ad ID
     */
    public function pause(string $adId): void
    {
        $this->client->post($adId, ['status' => 'PAUSED']);
    }

    /**
     * Enable (un-pause) an ad.
     *
     * @param string $adId Meta ad ID
     */
    public function enable(string $adId): void
    {
        $this->client->post($adId, ['status' => 'ACTIVE']);
    }

    /**
     * List ad creatives for the account.
     */
    public function listCreatives(): array
    {
        $response = $this->client->get_api("{$this->adAccountId}/adcreatives", [
            'fields' => 'name,status,object_story_spec,url_tags,created_time',
            'limit'  => 100,
        ]);

        return $response['data'] ?? [];
    }
}
