<?php

namespace AdManager\LinkedIn;

use Dotenv\Dotenv;
use RuntimeException;

/**
 * LinkedIn Marketing API client — direct HTTP, no SDK.
 *
 * Auth: Bearer token with required versioning headers.
 * API base: https://api.linkedin.com/rest/
 *
 * LinkedIn uses URNs (urn:li:sponsoredAccount:123456) rather than plain IDs.
 *
 * Required headers on every request:
 *   LinkedIn-Version: 202404
 *   X-Restli-Protocol-Version: 2.0.0
 *
 * Singleton: Client::get() returns the shared instance.
 */
class Client
{
    private static ?self $instance = null;

    private string $accessToken;
    private string $adAccountUrn;

    private const API_BASE       = 'https://api.linkedin.com/rest/';
    private const LINKEDIN_VER   = '202404';
    private const RESTLI_VER     = '2.0.0';

    private function __construct()
    {
        $dotenv = Dotenv::createImmutable(dirname(__DIR__, 2));
        $dotenv->load();
        $dotenv->required([
            'LINKEDIN_ACCESS_TOKEN',
            'LINKEDIN_AD_ACCOUNT_URN',
        ]);

        $this->accessToken  = $_ENV['LINKEDIN_ACCESS_TOKEN'];
        $this->adAccountUrn = $_ENV['LINKEDIN_AD_ACCOUNT_URN'];
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

    public function adAccountUrn(): string
    {
        return $this->adAccountUrn;
    }

    // ----------------------------------------------------------------
    // HTTP methods
    // ----------------------------------------------------------------

    /**
     * GET request to LinkedIn REST API.
     */
    public function get_api(string $endpoint, array $params = []): array
    {
        $url = self::API_BASE . ltrim($endpoint, '/');
        if ($params) {
            $url .= '?' . http_build_query($params);
        }

        return $this->request('GET', $url);
    }

    /**
     * POST request to LinkedIn REST API (JSON body).
     */
    public function post(string $endpoint, array $data = []): array
    {
        $url = self::API_BASE . ltrim($endpoint, '/');

        return $this->request('POST', $url, $data);
    }

    /**
     * POST request with X-RestLi-Method: PARTIAL_UPDATE (LinkedIn update convention).
     * Used internally — most callers use post() for creates and put() for updates.
     */
    public function patch(string $endpoint, array $data = []): array
    {
        $url = self::API_BASE . ltrim($endpoint, '/');

        return $this->request('POST', $url, $data, ['X-RestLi-Method: PARTIAL_UPDATE']);
    }

    // ----------------------------------------------------------------
    // Internal
    // ----------------------------------------------------------------

    /**
     * Execute a curl request and return decoded JSON.
     */
    private function request(string $method, string $url, ?array $postData = null, array $extraHeaders = []): array
    {
        $headers = array_merge([
            'Authorization: Bearer ' . $this->accessToken,
            'LinkedIn-Version: ' . self::LINKEDIN_VER,
            'X-Restli-Protocol-Version: ' . self::RESTLI_VER,
            'Content-Type: application/json',
        ], $extraHeaders);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HTTPHEADER     => $headers,
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData ?? []));
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        }

        $body     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException("LinkedIn API request failed: {$error}");
        }

        // LinkedIn 204 No Content — return empty array
        if ($httpCode === 204 || $body === '') {
            return [];
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException(
                "Failed to parse LinkedIn API response as JSON (HTTP {$httpCode}): {$body}"
            );
        }

        // LinkedIn error shapes: {'message':..., 'status':..., 'serviceErrorCode':...}
        if (isset($decoded['message']) && isset($decoded['status']) && $decoded['status'] >= 400) {
            $msg  = $decoded['message'];
            $code = $decoded['serviceErrorCode'] ?? $decoded['status'];
            throw new RuntimeException("LinkedIn API error ({$code}): {$msg}");
        }

        return $decoded;
    }
}
