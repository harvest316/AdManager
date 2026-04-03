<?php

namespace AdManager\X;

use Dotenv\Dotenv;
use RuntimeException;

/**
 * X (Twitter) Ads API client — direct HTTP, no SDK.
 *
 * Auth: OAuth 1.0a with HMAC-SHA1 signature.
 * API base: https://ads-api.x.com/12/accounts/{account_id}/
 *
 * Singleton: Client::get() returns the shared instance.
 */
class Client
{
    private static ?self $instance = null;

    private string $consumerKey;
    private string $consumerSecret;
    private string $accessToken;
    private string $accessTokenSecret;
    private string $accountId;

    private const API_BASE      = 'https://ads-api.x.com/12/accounts/';
    private const API_ROOT      = 'https://ads-api.x.com/12/';

    private function __construct()
    {
        $dotenv = Dotenv::createImmutable(dirname(__DIR__, 2));
        $dotenv->load();
        $dotenv->required([
            'X_ADS_CONSUMER_KEY',
            'X_ADS_CONSUMER_SECRET',
            'X_ADS_ACCESS_TOKEN',
            'X_ADS_ACCESS_TOKEN_SECRET',
            'X_ADS_ACCOUNT_ID',
        ]);

        $this->consumerKey       = $_ENV['X_ADS_CONSUMER_KEY'];
        $this->consumerSecret    = $_ENV['X_ADS_CONSUMER_SECRET'];
        $this->accessToken       = $_ENV['X_ADS_ACCESS_TOKEN'];
        $this->accessTokenSecret = $_ENV['X_ADS_ACCESS_TOKEN_SECRET'];
        $this->accountId         = $_ENV['X_ADS_ACCOUNT_ID'];
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

    public function accountId(): string
    {
        return $this->accountId;
    }

    // ----------------------------------------------------------------
    // HTTP methods
    // ----------------------------------------------------------------

    /**
     * GET request to X Ads API (account-scoped base URL).
     */
    public function get_api(string $endpoint, array $params = []): array
    {
        $url = $this->baseUrl() . ltrim($endpoint, '/');

        return $this->request('GET', $url, $params);
    }

    /**
     * GET request from the API root (for non-account-scoped endpoints, e.g. stats).
     * URL: https://ads-api.x.com/12/{endpoint}
     */
    public function getRoot(string $endpoint, array $params = []): array
    {
        $url = self::API_ROOT . ltrim($endpoint, '/');

        return $this->request('GET', $url, $params);
    }

    /**
     * POST request to X Ads API (JSON body).
     */
    public function post(string $endpoint, array $data = []): array
    {
        $url = $this->baseUrl() . ltrim($endpoint, '/');

        return $this->request('POST', $url, [], $data);
    }

    /**
     * PUT request to X Ads API — used for updates.
     * X uses PUT for updates (not POST like Meta).
     */
    public function put(string $endpoint, array $data = []): array
    {
        $url = $this->baseUrl() . ltrim($endpoint, '/');

        return $this->request('PUT', $url, [], $data);
    }

    /**
     * DELETE request to X Ads API.
     */
    public function delete(string $endpoint): array
    {
        $url = $this->baseUrl() . ltrim($endpoint, '/');

        return $this->request('DELETE', $url);
    }

    // ----------------------------------------------------------------
    // OAuth 1.0a
    // ----------------------------------------------------------------

    /**
     * Generate an OAuth 1.0a Authorization header for a request.
     *
     * Base string: METHOD&url_encode(url)&url_encode(sorted_params)
     * Signing key:  url_encode(consumer_secret)&url_encode(token_secret)
     *
     * @param  string $method HTTP method (GET, POST, PUT, DELETE)
     * @param  string $url    Full request URL (no query string)
     * @param  array  $params Query params merged with OAuth params for signature
     * @return string Authorization header value (starts with "OAuth ")
     */
    public function buildOAuthHeader(string $method, string $url, array $params = []): string
    {
        $oauthParams = [
            'oauth_consumer_key'     => $this->consumerKey,
            'oauth_nonce'            => $this->generateNonce(),
            'oauth_signature_method' => 'HMAC-SHA1',
            'oauth_timestamp'        => (string) time(),
            'oauth_token'            => $this->accessToken,
            'oauth_version'          => '1.0',
        ];

        // Merge all params for signature base string
        $allParams = array_merge($params, $oauthParams);
        ksort($allParams);

        $paramString = http_build_query($allParams, '', '&', PHP_QUERY_RFC3986);

        $baseString = strtoupper($method)
            . '&' . rawurlencode($url)
            . '&' . rawurlencode($paramString);

        $signingKey = rawurlencode($this->consumerSecret)
            . '&' . rawurlencode($this->accessTokenSecret);

        $signature = base64_encode(hash_hmac('sha1', $baseString, $signingKey, true));

        $oauthParams['oauth_signature'] = $signature;
        ksort($oauthParams);

        $headerParts = [];
        foreach ($oauthParams as $key => $value) {
            $headerParts[] = rawurlencode($key) . '="' . rawurlencode($value) . '"';
        }

        return 'OAuth ' . implode(', ', $headerParts);
    }

    // ----------------------------------------------------------------
    // Internal
    // ----------------------------------------------------------------

    private function baseUrl(): string
    {
        return self::API_BASE . $this->accountId . '/';
    }

    /**
     * Execute a curl request and return decoded JSON.
     */
    private function request(
        string $method,
        string $url,
        array $queryParams = [],
        ?array $bodyData = null
    ): array {
        $authHeader = $this->buildOAuthHeader($method, $url, $queryParams);

        $requestUrl = $queryParams ? $url . '?' . http_build_query($queryParams) : $url;

        $ch = curl_init($requestUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HTTPHEADER     => [
                'Authorization: ' . $authHeader,
                'Content-Type: application/json',
            ],
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($bodyData ?? []));
        } elseif ($method === 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($bodyData ?? []));
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        }

        $body     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException("X Ads API request failed: {$error}");
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException(
                "Failed to parse X Ads API response as JSON (HTTP {$httpCode}): {$body}"
            );
        }

        // X API wraps errors in 'errors' array or top-level 'error'
        if (!empty($decoded['errors'])) {
            $first   = $decoded['errors'][0];
            $msg     = $first['message'] ?? json_encode($first);
            $code    = $first['code'] ?? 0;
            throw new RuntimeException("X Ads API error ({$code}): {$msg}");
        }

        if (isset($decoded['error'])) {
            $msg  = $decoded['error']['message'] ?? json_encode($decoded['error']);
            $code = $decoded['error']['code'] ?? 0;
            throw new RuntimeException("X Ads API error ({$code}): {$msg}");
        }

        return $decoded;
    }

    private function generateNonce(): string
    {
        return bin2hex(random_bytes(16));
    }
}
