<?php

namespace AdManager\X;

/**
 * X (Twitter) Ads promoted tweet management.
 *
 * A promoted tweet is X's equivalent of an ad — it associates an existing
 * tweet with a line item for promotion.
 */
class PromotedTweet
{
    private Client $client;

    public function __construct()
    {
        $this->client = Client::get();
    }

    /**
     * Promote a tweet under a line item.
     *
     * @param  string $lineItemId Line item (ad group) ID
     * @param  string $tweetId    ID of the tweet to promote
     * @return string Promoted tweet ID
     */
    public function create(string $lineItemId, string $tweetId): string
    {
        $response = $this->client->post('promoted_tweets', [
            'line_item_id' => $lineItemId,
            'tweet_id'     => $tweetId,
        ]);

        return $response['data']['id'];
    }

    /**
     * List promoted tweets, optionally filtered by line item.
     *
     * @param  string|null $lineItemId Filter by line item (null = all)
     * @return array
     */
    public function list(?string $lineItemId = null): array
    {
        $params = [];
        if ($lineItemId) {
            $params['line_item_ids'] = $lineItemId;
        }

        $response = $this->client->get_api('promoted_tweets', $params);

        return $response['data'] ?? [];
    }

    /**
     * Get a single promoted tweet's details.
     */
    public function get(string $promotedTweetId): array
    {
        $response = $this->client->get_api("promoted_tweets/{$promotedTweetId}");

        return $response['data'] ?? [];
    }

    /**
     * Pause a promoted tweet.
     */
    public function pause(string $promotedTweetId): void
    {
        $this->client->put("promoted_tweets/{$promotedTweetId}", [
            'entity_status' => 'PAUSED',
        ]);
    }

    /**
     * Enable (activate) a promoted tweet.
     */
    public function enable(string $promotedTweetId): void
    {
        $this->client->put("promoted_tweets/{$promotedTweetId}", [
            'entity_status' => 'ACTIVE',
        ]);
    }
}
