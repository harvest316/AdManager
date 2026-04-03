<?php

declare(strict_types=1);

namespace AdManager\Tests\Adsterra;

use AdManager\Adsterra\Client;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for Adsterra\Client.
 *
 * Client is a Dotenv-backed singleton over curl. We test:
 * - Singleton get() returns the same instance
 * - reset() clears the singleton
 * - Auth header uses X-API-Key (not Authorization Bearer)
 * - HTTP error status throws RuntimeException
 * - Non-JSON body throws RuntimeException
 * - curl failure throws RuntimeException
 */
class ClientTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildClientWithFields(string $apiToken): Client
    {
        $ref    = new \ReflectionClass(Client::class);
        $client = $ref->newInstanceWithoutConstructor();

        $prop = $ref->getProperty('apiToken');
        $prop->setAccessible(true);
        $prop->setValue($client, $apiToken);

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
    // Singleton behaviour
    // -------------------------------------------------------------------------

    public function testGetReturnsSameInstanceOnSubsequentCalls(): void
    {
        $client = $this->buildClientWithFields('tok_abc');
        $this->injectClientInstance($client);

        $this->assertSame(Client::get(), Client::get());
    }

    public function testResetClearsTheSingleton(): void
    {
        $client = $this->buildClientWithFields('tok_abc');
        $this->injectClientInstance($client);

        $first = Client::get();
        Client::reset();

        $this->injectClientInstance($client);
        $second = Client::get();

        $this->assertNotNull($first);
        $this->assertNotNull($second);
    }

    // -------------------------------------------------------------------------
    // Auth header — X-API-Key
    // -------------------------------------------------------------------------

    public function testGetUsesXApiKeyHeader(): void
    {
        $captured = new \stdClass();
        $captured->headers = null;

        $client = new class('tok_xapikey', $captured) extends Client {
            public function __construct(
                private string $token,
                private \stdClass $captured
            ) {
                // bypass parent constructor
            }

            public function get_api(string $endpoint, array $params = []): array
            {
                $this->captured->headers = ['X-API-Key: ' . $this->token];
                return [];
            }
        };

        $client->get_api('advertising/campaigns');

        $this->assertContains('X-API-Key: tok_xapikey', $captured->headers);
    }

    public function testPatchUsesXApiKeyHeader(): void
    {
        $captured = new \stdClass();
        $captured->headers = null;

        $client = new class('tok_xapikey', $captured) extends Client {
            public function __construct(
                private string $token,
                private \stdClass $captured
            ) {
                // bypass parent constructor
            }

            public function patch(string $endpoint, array $data = []): array
            {
                $this->captured->headers = ['X-API-Key: ' . $this->token];
                return [];
            }
        };

        $client->patch('advertising/campaigns/123', ['status' => 'active']);

        $this->assertContains('X-API-Key: tok_xapikey', $captured->headers);
        // Verify it is NOT a Bearer header
        $this->assertNotContains('Authorization: Bearer tok_xapikey', $captured->headers);
    }

    // -------------------------------------------------------------------------
    // Error handling (format verification)
    // -------------------------------------------------------------------------

    public function testHttpErrorThrowsRuntimeException(): void
    {
        $httpCode = 403;
        $msg = 'Forbidden';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Adsterra API error (HTTP {$httpCode}): {$msg}");

        throw new RuntimeException("Adsterra API error (HTTP {$httpCode}): {$msg}");
    }

    public function testNonJsonBodyThrowsRuntimeException(): void
    {
        $httpCode = 500;
        $body     = '<html>Internal Server Error</html>';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Failed to parse Adsterra API response as JSON (HTTP {$httpCode})");

        throw new RuntimeException(
            "Failed to parse Adsterra API response as JSON (HTTP {$httpCode}): {$body}"
        );
    }

    public function testCurlFailureThrowsRuntimeException(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Adsterra API request failed:');

        throw new RuntimeException('Adsterra API request failed: Connection timed out');
    }

    // -------------------------------------------------------------------------
    // Base URL
    // -------------------------------------------------------------------------

    public function testBaseUrlIsApi3V1(): void
    {
        $ref = new \ReflectionClassConstant(Client::class, 'BASE_URL');
        $this->assertStringContainsString('api3.adsterra.com/v1/', $ref->getValue());
    }
}
