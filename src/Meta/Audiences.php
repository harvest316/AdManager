<?php

namespace AdManager\Meta;

/**
 * Meta Custom Audiences management.
 *
 * Supports website retargeting, customer file, and lookalike audiences.
 */
class Audiences
{
    private Client $client;
    private string $adAccountId;

    public function __construct()
    {
        $this->client      = Client::get();
        $this->adAccountId = $this->client->adAccountId();
    }

    /**
     * Create a website custom audience (retargeting via Pixel).
     *
     * @param string      $name          Audience name
     * @param string      $pixelId       Meta Pixel ID
     * @param int         $retentionDays How many days back to include visitors (default 30)
     * @param string|null $rule          JSON rule for URL matching (null = all visitors)
     * @return string Audience ID
     */
    public function createWebsiteAudience(
        string $name,
        string $pixelId,
        int $retentionDays = 30,
        ?string $rule = null
    ): string {
        if ($rule === null) {
            // Default rule: all visitors tracked by this pixel
            $rule = json_encode([
                'inclusions' => [
                    'operator' => 'or',
                    'rules'    => [
                        [
                            'event_sources'    => [['id' => $pixelId, 'type' => 'pixel']],
                            'retention_seconds' => $retentionDays * 86400,
                            'filter'           => [
                                'operator' => 'and',
                                'filters'  => [
                                    ['field' => 'url', 'operator' => 'i_contains', 'value' => ''],
                                ],
                            ],
                        ],
                    ],
                ],
            ]);
        }

        $data = [
            'name'           => $name,
            'subtype'        => 'WEBSITE',
            'retention_days' => $retentionDays,
            'rule'           => $rule,
            'pixel_id'       => $pixelId,
        ];

        $response = $this->client->post("{$this->adAccountId}/customaudiences", $data);

        return $response['id'];
    }

    /**
     * Create a customer file audience by uploading hashed emails.
     *
     * Emails are SHA256-hashed (lowercase, trimmed) before upload as required
     * by the Meta API.
     *
     * @param string   $name   Audience name
     * @param string[] $emails Plain-text email addresses
     * @return string Audience ID
     */
    public function createCustomerFileAudience(string $name, array $emails): string
    {
        $hashedEmails = array_map(
            fn(string $email): string => hash('sha256', strtolower(trim($email))),
            $emails
        );

        $data = [
            'name'    => $name,
            'subtype' => 'CUSTOM',
            'schema'  => 'EMAIL_SHA256',
            'data'    => json_encode($hashedEmails),
        ];

        $response = $this->client->post("{$this->adAccountId}/customaudiences", $data);

        return $response['id'];
    }

    /**
     * Create a lookalike audience from an existing source audience.
     *
     * @param string $name              Audience name
     * @param string $sourceAudienceId  Source custom audience ID
     * @param string $country           ISO 3166-1 alpha-2 country code (e.g. 'AU')
     * @param int    $percent           Lookalike ratio in percent, 1–10 (default 1)
     * @return string Audience ID
     */
    public function createLookalike(
        string $name,
        string $sourceAudienceId,
        string $country,
        int $percent = 1
    ): string {
        $ratio = $percent / 100;

        $data = [
            'name'              => $name,
            'subtype'           => 'LOOKALIKE',
            'lookalike_spec'    => json_encode([
                'origin_audience_id' => $sourceAudienceId,
                'country'            => $country,
                'ratio'              => $ratio,
            ]),
        ];

        $response = $this->client->post("{$this->adAccountId}/customaudiences", $data);

        return $response['id'];
    }

    /**
     * List all custom audiences for the ad account.
     *
     * @return array
     */
    public function list(): array
    {
        $fields = 'name,subtype,approximate_count,data_source,delivery_status,'
                . 'lookalike_spec,retention_days,rule,time_created,time_updated';

        $response = $this->client->get_api("{$this->adAccountId}/customaudiences", [
            'fields' => $fields,
            'limit'  => 100,
        ]);

        return $response['data'] ?? [];
    }

    /**
     * Get a single custom audience's details.
     *
     * @param string $audienceId Audience ID
     * @return array
     */
    public function get(string $audienceId): array
    {
        $fields = 'name,subtype,approximate_count,data_source,delivery_status,'
                . 'lookalike_spec,retention_days,rule,time_created,time_updated';

        return $this->client->get_api($audienceId, [
            'fields' => $fields,
        ]);
    }

    /**
     * Delete a custom audience.
     *
     * @param string $audienceId Audience ID
     */
    public function delete(string $audienceId): void
    {
        $this->client->delete($audienceId);
    }

    /**
     * Get the approximate size (reach) of an audience.
     *
     * @param  string   $audienceId Audience ID
     * @return int|null Approximate count, or null if not available
     */
    public function getSize(string $audienceId): ?int
    {
        $result = $this->client->get_api($audienceId, [
            'fields' => 'approximate_count',
        ]);

        if (!isset($result['approximate_count'])) {
            return null;
        }

        return (int) $result['approximate_count'];
    }
}
