<?php

namespace AdManager\Meta;

use RuntimeException;

/**
 * Meta Conversions API (CAPI) — server-side event sending.
 *
 * Sends conversion events directly from the server to Meta, bypassing
 * browser-side pixel limitations (ad blockers, iOS restrictions).
 * Required for reliable conversion tracking in 2026+.
 *
 * Usage:
 *   $capi = new ConversionsAPI();
 *   $capi->sendEvent('Purchase', [
 *       'value' => 99.00,
 *       'currency' => 'AUD',
 *       'content_name' => 'CRO Audit',
 *   ], [
 *       'email' => 'buyer@example.com',
 *       'client_ip' => $_SERVER['REMOTE_ADDR'],
 *       'client_user_agent' => $_SERVER['HTTP_USER_AGENT'],
 *       'fbc' => $_COOKIE['_fbc'] ?? null,
 *       'fbp' => $_COOKIE['_fbp'] ?? null,
 *   ]);
 */
class ConversionsAPI
{
    private string $pixelId;
    private string $accessToken;
    private string $apiVersion;
    private ?string $testEventCode;

    public function __construct(?string $pixelId = null)
    {
        $client = Client::get();
        $this->accessToken = $_ENV['META_ACCESS_TOKEN'] ?? getenv('META_ACCESS_TOKEN') ?: '';
        $this->pixelId = $pixelId ?: $_ENV['META_PIXEL_ID'] ?? getenv('META_PIXEL_ID') ?: '';
        $this->apiVersion = $_ENV['META_API_VERSION'] ?? 'v20.0';
        $this->testEventCode = $_ENV['META_TEST_EVENT_CODE'] ?? getenv('META_TEST_EVENT_CODE') ?: null;

        if (!$this->pixelId) {
            throw new RuntimeException('META_PIXEL_ID not set');
        }
    }

    /**
     * Send a conversion event via CAPI.
     *
     * @param string $eventName  Meta standard event: Purchase, Lead, CompleteRegistration, AddToCart, etc.
     * @param array  $customData Event-specific data (value, currency, content_name, content_ids, etc.)
     * @param array  $userData   User matching parameters (email, phone, client_ip, client_user_agent, fbc, fbp)
     * @param string $eventId    Dedup ID (to prevent double-counting with browser pixel). Use same ID in both.
     * @param string $sourceUrl  The URL where the conversion happened.
     *
     * @return array Graph API response
     */
    public function sendEvent(
        string $eventName,
        array $customData = [],
        array $userData = [],
        ?string $eventId = null,
        ?string $sourceUrl = null
    ): array {
        $event = [
            'event_name'  => $eventName,
            'event_time'  => time(),
            'action_source' => 'website',
            'user_data'   => $this->hashUserData($userData),
        ];

        if ($eventId) {
            $event['event_id'] = $eventId;
        }

        if ($sourceUrl) {
            $event['event_source_url'] = $sourceUrl;
        }

        if (!empty($customData)) {
            $event['custom_data'] = $customData;
        }

        return $this->send([$event]);
    }

    /**
     * Send multiple events in a batch.
     *
     * @param array $events Array of event arrays (each with event_name, custom_data, user_data)
     * @return array Graph API response
     */
    public function sendBatch(array $events): array
    {
        $formattedEvents = [];
        foreach ($events as $event) {
            $formatted = [
                'event_name'    => $event['event_name'],
                'event_time'    => $event['event_time'] ?? time(),
                'action_source' => 'website',
                'user_data'     => $this->hashUserData($event['user_data'] ?? []),
            ];
            if (!empty($event['event_id'])) $formatted['event_id'] = $event['event_id'];
            if (!empty($event['event_source_url'])) $formatted['event_source_url'] = $event['event_source_url'];
            if (!empty($event['custom_data'])) $formatted['custom_data'] = $event['custom_data'];
            $formattedEvents[] = $formatted;
        }

        return $this->send($formattedEvents);
    }

    /**
     * Test the CAPI connection by sending a test event.
     */
    public function testConnection(): array
    {
        return $this->sendEvent('PageView', [], [
            'client_ip' => '127.0.0.1',
            'client_user_agent' => 'AdManager/ConversionsAPI Test',
        ], 'test_' . time());
    }

    /**
     * Get pixel details and verify it's active.
     */
    public function getPixelInfo(): array
    {
        $url = "https://graph.facebook.com/{$this->apiVersion}/{$this->pixelId}?"
            . http_build_query([
                'fields' => 'name,is_unavailable,last_fired_time,creation_time',
                'access_token' => $this->accessToken,
            ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new RuntimeException("Meta API returned HTTP {$httpCode}: {$response}");
        }

        return json_decode($response, true) ?: [];
    }

    // ── Private ─────────────────────────────────────────────────

    /**
     * Hash PII fields per Meta's requirements (SHA-256, lowercase, trimmed).
     */
    private function hashUserData(array $userData): array
    {
        $hashed = [];

        // Fields that must be hashed
        $hashFields = ['em' => 'email', 'ph' => 'phone', 'fn' => 'first_name', 'ln' => 'last_name',
                       'ct' => 'city', 'st' => 'state', 'zp' => 'zip', 'country' => 'country',
                       'db' => 'date_of_birth', 'ge' => 'gender'];

        foreach ($hashFields as $apiKey => $inputKey) {
            $value = $userData[$inputKey] ?? null;
            if ($value) {
                $hashed[$apiKey] = hash('sha256', strtolower(trim($value)));
            }
        }

        // Fields sent as-is (not PII)
        if (!empty($userData['client_ip'])) $hashed['client_ip_address'] = $userData['client_ip'];
        if (!empty($userData['client_user_agent'])) $hashed['client_user_agent'] = $userData['client_user_agent'];
        if (!empty($userData['fbc'])) $hashed['fbc'] = $userData['fbc'];
        if (!empty($userData['fbp'])) $hashed['fbp'] = $userData['fbp'];
        if (!empty($userData['external_id'])) $hashed['external_id'] = hash('sha256', $userData['external_id']);

        return $hashed;
    }

    /**
     * Send events to the Conversions API endpoint.
     */
    private function send(array $events): array
    {
        $url = "https://graph.facebook.com/{$this->apiVersion}/{$this->pixelId}/events";

        $payload = [
            'data' => $events,
            'access_token' => $this->accessToken,
        ];

        if ($this->testEventCode) {
            $payload['test_event_code'] = $this->testEventCode;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new RuntimeException("CAPI curl error: {$error}");
        }

        $parsed = json_decode($response, true) ?: [];

        if ($httpCode >= 400) {
            $errMsg = $parsed['error']['message'] ?? $response;
            throw new RuntimeException("CAPI error (HTTP {$httpCode}): {$errMsg}");
        }

        return $parsed;
    }
}
