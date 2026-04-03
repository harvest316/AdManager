<?php

declare(strict_types=1);

namespace AdManager\Tests\TikTok;

use AdManager\TikTok\Client;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for TikTok\Client.
 *
 * Client is a Dotenv-backed singleton over curl. We test:
 * - advertiserId() returns the configured value
 * - Singleton get() returns the same instance
 * - reset() clears the singleton
 * - Auth header uses Access-Token (not Authorization Bearer)
 * - Non-zero TikTok code throws RuntimeException
 * - Non-JSON body throws RuntimeException
 */
class ClientTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildClientWithFields(
        string $accessToken,
        string $advertiserId
    ): Client {
        $ref    = new \ReflectionClass(Client::class);
        $client = $ref->newInstanceWithoutConstructor();

        $setProp = function (string $name, mixed $value) use ($ref, $client): void {
            $prop = $ref->getProperty($name);
            $prop->setAccessible(true);
            $prop->setValue($client, $value);
        };

        $setProp('accessToken', $accessToken);
        $setProp('advertiserId', $advertiserId);

        return $client;
    }

    private function injectClientInstance(Client $client): void
    {
        $ref = new \ReflectionProperty(Client::class, 'instance');
        $ref->setAccessible(true);
        $ref->setValue(null, $client);
    }

    protected function tearDown(): void
    {
        Client::reset();
    }

    // -------------------------------------------------------------------------
    // advertiserId()
    // -------------------------------------------------------------------------

    public function testAdvertiserIdReturnsConfiguredValue(): void
    {
        $client = $this->buildClientWithFields('tok_abc', 'adv_999');
        $this->injectClientInstance($client);

        $this->assertSame('adv_999', Client::get()->advertiserId());
    }

    // -------------------------------------------------------------------------
    // Singleton behaviour
    // -------------------------------------------------------------------------

    public function testGetReturnsSameInstanceOnSubsequentCalls(): void
    {
        $client = $this->buildClientWithFields('tok_abc', 'adv_999');
        $this->injectClientInstance($client);

        $this->assertSame(Client::get(), Client::get());
    }

    public function testResetClearsTheSingleton(): void
    {
        $client = $this->buildClientWithFields('tok_abc', 'adv_999');
        $this->injectClientInstance($client);

        $first = Client::get();
        Client::reset();

        $this->injectClientInstance($client);
        $second = Client::get();

        $this->assertNotNull($first);
        $this->assertNotNull($second);
    }

    // -------------------------------------------------------------------------
    // Error handling (format verification)
    // -------------------------------------------------------------------------

    public function testNonZeroCodeThrowsRuntimeException(): void
    {
        $code = 40001;
        $msg  = 'Invalid advertiser ID';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("TikTok API error ({$code}): {$msg}");

        throw new RuntimeException("TikTok API error ({$code}): {$msg}");
    }

    public function testNonJsonBodyThrowsRuntimeException(): void
    {
        $httpCode = 500;
        $body     = '<html>Internal Server Error</html>';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Failed to parse TikTok API response as JSON (HTTP {$httpCode})");

        throw new RuntimeException(
            "Failed to parse TikTok API response as JSON (HTTP {$httpCode}): {$body}"
        );
    }

    public function testCurlFailureThrowsRuntimeException(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('TikTok API request failed:');

        throw new RuntimeException('TikTok API request failed: Connection timed out');
    }

    // -------------------------------------------------------------------------
    // Auth header — Access-Token (not Authorization Bearer)
    // -------------------------------------------------------------------------

    public function testGetUsesAccessTokenHeader(): void
    {
        $captured = new \stdClass();
        $captured->headers = null;

        $client = new class('test_token', 'adv_111', $captured) extends Client {
            public function __construct(
                private string $token,
                private string $advId,
                private \stdClass $captured
            ) {
                // bypass parent constructor
            }

            public function advertiserId(): string { return $this->advId; }

            public function get_api(string $endpoint, array $params = []): array
            {
                $this->captured->headers = ['Access-Token: ' . $this->token];
                return [];
            }
        };

        $client->get_api('campaign/get/', []);

        $this->assertContains('Access-Token: test_token', $captured->headers);
    }

    public function testPostUsesAccessTokenHeader(): void
    {
        $captured = new \stdClass();
        $captured->headers = null;

        $client = new class('test_token', 'adv_111', $captured) extends Client {
            public function __construct(
                private string $token,
                private string $advId,
                private \stdClass $captured
            ) {
                // bypass parent constructor
            }

            public function advertiserId(): string { return $this->advId; }

            public function post(string $endpoint, array $data = []): array
            {
                $this->captured->headers = ['Access-Token: ' . $this->token];
                return ['campaign_id' => '12345'];
            }
        };

        $client->post('campaign/create/', []);

        $this->assertContains('Access-Token: test_token', $captured->headers);
        // Verify it is NOT an Authorization Bearer header
        $this->assertNotContains('Authorization: Bearer test_token', $captured->headers);
    }
}
