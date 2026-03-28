<?php

namespace AdManager\Meta;

use Dotenv\Dotenv;
use RuntimeException;

/**
 * Meta Marketing API client — direct HTTP, no SDK.
 *
 * Uses the same curl pattern as Creative\ImageGen (no composer deps).
 * Singleton: Client::get() returns the shared instance.
 */
class Client
{
    private static ?self $instance = null;

    private string $accessToken;
    private string $adAccountId;
    private string $apiVersion;

    private function __construct()
    {
        $dotenv = Dotenv::createImmutable(dirname(__DIR__, 2));
        $dotenv->load();
        $dotenv->required(['META_ACCESS_TOKEN', 'META_AD_ACCOUNT_ID']);

        $this->accessToken = $_ENV['META_ACCESS_TOKEN'];
        $this->adAccountId = $_ENV['META_AD_ACCOUNT_ID']; // format: act_XXXXXXXX
        $this->apiVersion  = $_ENV['META_API_VERSION'] ?? 'v20.0';
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

    public function adAccountId(): string
    {
        return $this->adAccountId;
    }

    // ----------------------------------------------------------------
    // HTTP methods
    // ----------------------------------------------------------------

    /**
     * GET request to Graph API.
     */
    public function get_api(string $endpoint, array $params = []): array
    {
        $params['access_token'] = $this->accessToken;
        $url = $this->baseUrl() . ltrim($endpoint, '/') . '?' . http_build_query($params);

        return $this->request('GET', $url);
    }

    /**
     * POST request to Graph API (JSON body).
     */
    public function post(string $endpoint, array $data = []): array
    {
        $data['access_token'] = $this->accessToken;
        $url = $this->baseUrl() . ltrim($endpoint, '/');

        return $this->request('POST', $url, $data);
    }

    /**
     * POST multipart form data (file uploads).
     */
    public function postMultipart(string $endpoint, array $data = []): array
    {
        $data['access_token'] = $this->accessToken;
        $url = $this->baseUrl() . ltrim($endpoint, '/');

        return $this->requestMultipart($url, $data);
    }

    /**
     * DELETE request to Graph API.
     */
    public function delete(string $endpoint): array
    {
        $url = $this->baseUrl() . ltrim($endpoint, '/') . '?' . http_build_query([
            'access_token' => $this->accessToken,
        ]);

        return $this->request('DELETE', $url);
    }

    // ----------------------------------------------------------------
    // Internal
    // ----------------------------------------------------------------

    private function baseUrl(): string
    {
        return "https://graph.facebook.com/{$this->apiVersion}/";
    }

    /**
     * Execute a curl request and return decoded JSON.
     */
    private function request(string $method, string $url, ?array $postData = null): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        }

        $body     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException("Meta API request failed: {$error}");
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("Failed to parse Meta API response as JSON (HTTP {$httpCode}): {$body}");
        }

        if (isset($decoded['error'])) {
            $msg = $decoded['error']['message'] ?? json_encode($decoded['error']);
            $code = $decoded['error']['code'] ?? 0;
            $detail = $decoded['error']['error_user_msg'] ?? ($decoded['error']['error_user_title'] ?? '');
            $sub = isset($decoded['error']['error_subcode']) ? " [sub:{$decoded['error']['error_subcode']}]" : '';
            throw new RuntimeException("Meta API error ({$code}){$sub}: {$msg}" . ($detail ? " — {$detail}" : ''));
        }

        return $decoded;
    }

    /**
     * Execute a multipart/form-data POST (file uploads).
     */
    private function requestMultipart(string $url, array $data): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 300, // video uploads can be slow
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $data, // curl handles multipart when array
        ]);

        $body     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException("Meta API upload failed: {$error}");
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("Failed to parse Meta API response as JSON (HTTP {$httpCode}): {$body}");
        }

        if (isset($decoded['error'])) {
            $msg = $decoded['error']['message'] ?? json_encode($decoded['error']);
            $code = $decoded['error']['code'] ?? 0;
            throw new RuntimeException("Meta API error ({$code}): {$msg}");
        }

        return $decoded;
    }
}
