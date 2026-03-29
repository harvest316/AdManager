<?php

namespace AdManager\Google;

use Dotenv\Dotenv;
use RuntimeException;

/**
 * GA4 Data API client — direct REST calls, no SDK.
 *
 * Endpoint: https://analyticsdata.googleapis.com/v1beta/properties/{propertyId}:runReport
 * Auth: OAuth 2.0 — exchanges the existing Google Ads refresh token for an access token.
 */
class GA4
{
    protected string $propertyId;
    protected string $clientId;
    protected string $clientSecret;
    protected string $refreshToken;

    /** Cached access token + expiry. */
    protected ?string $accessToken = null;
    protected int $tokenExpiry = 0;

    public function __construct()
    {
        $dotenv = Dotenv::createImmutable(dirname(__DIR__, 2));
        $dotenv->load();
        $dotenv->required([
            'GA4_PROPERTY_ID',
            'GOOGLE_ADS_CLIENT_ID',
            'GOOGLE_ADS_CLIENT_SECRET',
            'GOOGLE_ADS_REFRESH_TOKEN',
        ]);

        $this->propertyId   = $_ENV['GA4_PROPERTY_ID'];
        $this->clientId     = $_ENV['GOOGLE_ADS_CLIENT_ID'];
        $this->clientSecret = $_ENV['GOOGLE_ADS_CLIENT_SECRET'];
        $this->refreshToken = $_ENV['GOOGLE_ADS_REFRESH_TOKEN'];
    }

    // ----------------------------------------------------------------
    // Public API
    // ----------------------------------------------------------------

    /**
     * Landing page performance report.
     *
     * Dimensions: landingPage, sessionSource, sessionMedium, sessionCampaignName
     * Metrics: sessions, bounceRate, averageSessionDuration, conversions, purchaseRevenue
     *
     * @return array  Array of rows, each with dimension/metric keys.
     */
    public function landingPagePerformance(string $startDate, string $endDate): array
    {
        $rows = $this->runReport(
            ['landingPage', 'sessionSource', 'sessionMedium', 'sessionCampaignName'],
            ['sessions', 'bounceRate', 'averageSessionDuration', 'conversions', 'purchaseRevenue'],
            $startDate,
            $endDate
        );

        return $rows;
    }

    /**
     * Source/medium/campaign conversion rate report.
     *
     * Returns sessions, conversions, purchaseRevenue plus a derived conversion_rate.
     */
    public function sourceConversionRate(string $startDate, string $endDate): array
    {
        $rows = $this->runReport(
            ['sessionSource', 'sessionMedium', 'sessionCampaignName'],
            ['sessions', 'conversions', 'purchaseRevenue'],
            $startDate,
            $endDate
        );

        // Derive conversion_rate per row
        foreach ($rows as &$row) {
            $sessions    = (float) ($row['sessions'] ?? 0);
            $conversions = (float) ($row['conversions'] ?? 0);
            $row['conversion_rate'] = $sessions > 0 ? round($conversions / $sessions, 6) : 0.0;
        }
        unset($row);

        return $rows;
    }

    /**
     * Total conversions across the period — used to validate against ad platform data.
     *
     * Returns a summary array: ['total_conversions', 'total_revenue', 'start_date', 'end_date']
     */
    public function conversionValidation(string $startDate, string $endDate): array
    {
        $rows = $this->runReport(
            [],                                    // No dimensions — account-level totals
            ['conversions', 'purchaseRevenue'],
            $startDate,
            $endDate
        );

        $totalConversions = 0.0;
        $totalRevenue     = 0.0;
        foreach ($rows as $row) {
            $totalConversions += (float) ($row['conversions'] ?? 0);
            $totalRevenue     += (float) ($row['purchaseRevenue'] ?? 0);
        }

        return [
            'total_conversions' => $totalConversions,
            'total_revenue'     => round($totalRevenue, 2),
            'start_date'        => $startDate,
            'end_date'          => $endDate,
        ];
    }

    // ----------------------------------------------------------------
    // Internal
    // ----------------------------------------------------------------

    /**
     * Execute a runReport call against the GA4 Data API.
     *
     * @param  string[] $dimensions  GA4 dimension names
     * @param  string[] $metrics     GA4 metric names
     * @return array    Decoded rows — each row is an assoc array keyed by dimension/metric name.
     */
    protected function runReport(array $dimensions, array $metrics, string $startDate, string $endDate): array
    {
        $token = $this->getAccessToken();
        $url   = "https://analyticsdata.googleapis.com/v1beta/properties/{$this->propertyId}:runReport";

        $body = [
            'dateRanges' => [
                ['startDate' => $startDate, 'endDate' => $endDate],
            ],
            'dimensions' => array_map(fn($d) => ['name' => $d], $dimensions),
            'metrics'    => array_map(fn($m) => ['name' => $m], $metrics),
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                "Authorization: Bearer {$token}",
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException("GA4 API request failed: {$error}");
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("Failed to parse GA4 API response (HTTP {$httpCode}): {$response}");
        }

        if (isset($decoded['error'])) {
            $msg  = $decoded['error']['message'] ?? json_encode($decoded['error']);
            $code = $decoded['error']['code'] ?? 0;
            throw new RuntimeException("GA4 API error ({$code}): {$msg}");
        }

        return $this->parseRows($decoded, $dimensions, $metrics);
    }

    /**
     * Parse the GA4 runReport response into flat assoc arrays.
     *
     * GA4 response shape:
     *   dimensionHeaders: [{name: ...}]
     *   metricHeaders:    [{name: ..., type: ...}]
     *   rows:             [{dimensionValues: [{value: ...}], metricValues: [{value: ...}]}]
     */
    protected function parseRows(array $decoded, array $dimensions, array $metrics): array
    {
        $rows = $decoded['rows'] ?? [];
        if (empty($rows)) {
            return [];
        }

        // Build header name arrays from response headers (preserves API ordering)
        $dimHeaders    = array_column($decoded['dimensionHeaders'] ?? [], 'name');
        $metricHeaders = array_column($decoded['metricHeaders'] ?? [], 'name');

        $result = [];
        foreach ($rows as $row) {
            $parsed = [];

            foreach (($row['dimensionValues'] ?? []) as $i => $dv) {
                $key          = $dimHeaders[$i] ?? $dimensions[$i] ?? "dim_{$i}";
                $parsed[$key] = $dv['value'] ?? '';
            }

            foreach (($row['metricValues'] ?? []) as $i => $mv) {
                $key          = $metricHeaders[$i] ?? $metrics[$i] ?? "metric_{$i}";
                $parsed[$key] = $mv['value'] ?? '0';
            }

            $result[] = $parsed;
        }

        return $result;
    }

    /**
     * Exchange the refresh token for an access token.
     *
     * Caches the result until 60 seconds before expiry.
     */
    protected function getAccessToken(): string
    {
        if ($this->accessToken !== null && time() < $this->tokenExpiry) {
            return $this->accessToken;
        }

        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'client_id'     => $this->clientId,
                'client_secret' => $this->clientSecret,
                'refresh_token' => $this->refreshToken,
                'grant_type'    => 'refresh_token',
            ]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        ]);

        $response = curl_exec($ch);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException("OAuth token exchange failed: {$error}");
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded) || empty($decoded['access_token'])) {
            $msg = $decoded['error_description'] ?? ($decoded['error'] ?? $response);
            throw new RuntimeException("OAuth token exchange returned no access_token: {$msg}");
        }

        $this->accessToken = $decoded['access_token'];
        // Cache with 60-second safety buffer
        $this->tokenExpiry = time() + (int) ($decoded['expires_in'] ?? 3600) - 60;

        return $this->accessToken;
    }
}
