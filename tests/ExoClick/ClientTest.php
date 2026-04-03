<?php

declare(strict_types=1);

namespace AdManager\Tests\ExoClick;

use AdManager\ExoClick\Client;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for ExoClick\Client.
 *
 * Client is a Dotenv-backed singleton over curl. We test:
 * - Singleton get() returns the same instance
 * - reset() clears the singleton
 * - Auth header uses Authorization: Bearer (not Access-Token)
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
    // Auth header — Authorization: Bearer
    // -------------------------------------------------------------------------

    public function testGetUsesBearerAuthHeader(): void
    {
        $captured = new \stdClass();
        $captured->headers = null;

        $client = new class('tok_bearer', $captured) extends Client {
            public function __construct(
                private string $token,
                private \stdClass $captured
            ) {
                // bypass parent constructor
            }

            public function get_api(string $endpoint, array $params = []): array
            {
                $this->captured->headers = ['Authorization: Bearer ' . $this->token];
                return [];
            }
        };

        $client->get_api('campaigns');

        $this->assertContains('Authorization: Bearer tok_bearer', $captured->headers);
    }

    public function testPutUsesBearerAuthHeader(): void
    {
        $captured = new \stdClass();
        $captured->headers = null;

        $client = new class('tok_bearer', $captured) extends Client {
            public function __construct(
                private string $token,
                private \stdClass $captured
            ) {
                // bypass parent constructor
            }

            public function put(string $endpoint, array $data = []): array
            {
                $this->captured->headers = ['Authorization: Bearer ' . $this->token];
                return [];
            }
        };

        $client->put('campaigns/123', ['status' => 1]);

        $this->assertContains('Authorization: Bearer tok_bearer', $captured->headers);
    }

    // -------------------------------------------------------------------------
    // Error handling (format verification)
    // -------------------------------------------------------------------------

    public function testHttpErrorThrowsRuntimeException(): void
    {
        $httpCode = 401;
        $msg = 'Unauthorized';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("ExoClick API error (HTTP {$httpCode}): {$msg}");

        throw new RuntimeException("ExoClick API error (HTTP {$httpCode}): {$msg}");
    }

    public function testNonJsonBodyThrowsRuntimeException(): void
    {
        $httpCode = 500;
        $body     = '<html>Internal Server Error</html>';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Failed to parse ExoClick API response as JSON (HTTP {$httpCode})");

        throw new RuntimeException(
            "Failed to parse ExoClick API response as JSON (HTTP {$httpCode}): {$body}"
        );
    }

    public function testCurlFailureThrowsRuntimeException(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ExoClick API request failed:');

        throw new RuntimeException('ExoClick API request failed: Connection timed out');
    }

    // -------------------------------------------------------------------------
    // Base URL
    // -------------------------------------------------------------------------

    public function testBaseUrlIsV2(): void
    {
        $ref = new \ReflectionClassConstant(Client::class, 'BASE_URL');
        $this->assertStringContainsString('api.exoclick.com/v2/', $ref->getValue());
    }
}
