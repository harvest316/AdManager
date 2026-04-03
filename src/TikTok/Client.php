<?php

namespace AdManager\TikTok;

use Dotenv\Dotenv;
use RuntimeException;

/**
 * TikTok Business API client — direct HTTP, no SDK.
 *
 * Auth: Access-Token header (not Authorization Bearer).
 * Error convention: TikTok returns {"code": 0, "message": "OK", "data": {...}}.
 * Any non-zero code is treated as an error.
 *
 * Singleton: Client::get() returns the shared instance.
 */
class Client
{
    private static ?self $instance = null;

    private string $accessToken;
    private string $advertiserId;

    private const BASE_URL = 'https://business-api.tiktok.com/open_api/v1.3/';

    private function __construct()
    {
        $dotenv = Dotenv::createImmutable(dirname(__DIR__, 2));
        $dotenv->load();
        $dotenv->required(['TIKTOK_ACCESS_TOKEN', 'TIKTOK_ADVERTISER_ID']);

        $this->accessToken  = $_ENV['TIKTOK_ACCESS_TOKEN'];
        $this->advertiserId = $_ENV['TIKTOK_ADVERTISER_ID'];
    }

    public static function get(): self
    {
        if (self::$instance) return self::$instance;
        self::$instance = new self();
        return self::$instance;
    }

    /**
     * Reset singleton (useful for testing).
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    public function advertiserId(): string
    {
        return $this->advertiserId;
    }

    // ----------------------------------------------------------------
    // HTTP methods
    // ----------------------------------------------------------------

    /**
     * GET request — params sent as query string.
     */
    public function get_api(string $endpoint, array $params = []): array
    {
        $url = self::BASE_URL . ltrim($endpoint, '/');
        if ($params) {
            $url .= '?' . http_build_query($params);
        }

        return $this->request('GET', $url);
    }

    /**
     * POST request — data sent as JSON body.
     */
    public function post(string $endpoint, array $data = []): array
    {
        $url = self::BASE_URL . ltrim($endpoint, '/');

        return $this->request('POST', $url, $data);
    }

    // ----------------------------------------------------------------
    // Internal
    // ----------------------------------------------------------------

    /**
     * Execute a curl request and return the decoded data payload.
     * Throws RuntimeException on curl failure, non-JSON, or non-zero TikTok code.
     */
    private function request(string $method, string $url, ?array $postData = null): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Access-Token: ' . $this->accessToken,
            ],
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
        }

        $body     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException("TikTok API request failed: {$error}");
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException(
                "Failed to parse TikTok API response as JSON (HTTP {$httpCode}): {$body}"
            );
        }

        $code = $decoded['code'] ?? -1;
        if ($code !== 0) {
            $msg = $decoded['message'] ?? 'Unknown error';
            throw new RuntimeException("TikTok API error ({$code}): {$msg}");
        }

        return $decoded['data'] ?? [];
    }
}
