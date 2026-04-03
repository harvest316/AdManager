<?php

namespace AdManager\Adsterra;

use Dotenv\Dotenv;
use RuntimeException;

/**
 * Adsterra API client — direct HTTP, no SDK.
 *
 * Auth: X-API-Key header.
 * Error convention: HTTP status codes + response body error messages.
 *
 * Singleton: Client::get() returns the shared instance.
 */
class Client
{
    private static ?self $instance = null;

    private string $apiToken;

    private const BASE_URL = 'https://api3.adsterra.com/v1/';

    private function __construct()
    {
        $dotenv = Dotenv::createImmutable(dirname(__DIR__, 2));
        $dotenv->load();
        $dotenv->required(['ADSTERRA_API_TOKEN']);

        $this->apiToken = $_ENV['ADSTERRA_API_TOKEN'];
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

    /**
     * PATCH request — data sent as JSON body.
     */
    public function patch(string $endpoint, array $data = []): array
    {
        $url = self::BASE_URL . ltrim($endpoint, '/');

        return $this->request('PATCH', $url, $data);
    }

    // ----------------------------------------------------------------
    // Internal
    // ----------------------------------------------------------------

    /**
     * Execute a curl request and return decoded JSON.
     * Throws RuntimeException on curl failure, non-JSON, or HTTP error status.
     */
    private function request(string $method, string $url, ?array $postData = null): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-API-Key: ' . $this->apiToken,
            ],
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
        } elseif ($method === 'PATCH') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
        }

        $body     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException("Adsterra API request failed: {$error}");
        }

        // Empty body on success (e.g. 204 No Content)
        if ($body === '' && $httpCode >= 200 && $httpCode < 300) {
            return [];
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException(
                "Failed to parse Adsterra API response as JSON (HTTP {$httpCode}): {$body}"
            );
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $msg = $decoded['message'] ?? $decoded['error'] ?? json_encode($decoded);
            throw new RuntimeException("Adsterra API error (HTTP {$httpCode}): {$msg}");
        }

        return $decoded;
    }
}
