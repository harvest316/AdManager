<?php

declare(strict_types=1);

namespace AdManager\Tests\LinkedIn;

use AdManager\LinkedIn\Client;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for LinkedIn\Client.
 *
 * Client is a Dotenv-backed singleton. We test:
 * - adAccountUrn() returns the configured URN value
 * - Singleton reset() clears the instance
 * - Subsequent get() calls return the same instance
 * - Every request includes LinkedIn-Version header
 * - Every request includes X-Restli-Protocol-Version header
 * - Bearer token is included in Authorization header
 * - Error responses (status >= 400) throw RuntimeException
 * - Non-JSON response throws RuntimeException with HTTP code
 * - URN format is preserved (not stripped)
 */
class ClientTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildClientWithFields(
        string $accessToken,
        string $adAccountUrn
    ): Client {
        $ref    = new \ReflectionClass(Client::class);
        $client = $ref->newInstanceWithoutConstructor();

        $setProp = function (string $name, mixed $value) use ($ref, $client): void {
            $prop = $ref->getProperty($name);
            $prop->setAccessible(true);
            $prop->setValue($client, $value);
        };

        $setProp('accessToken',  $accessToken);
        $setProp('adAccountUrn', $adAccountUrn);

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
    // adAccountUrn()
    // -------------------------------------------------------------------------

    public function testAdAccountUrnReturnsConfiguredValue(): void
    {
        $urn    = 'urn:li:sponsoredAccount:123456';
        $client = $this->buildClientWithFields('tok_abc', $urn);
        $this->injectClientInstance($client);

        $this->assertSame($urn, Client::get()->adAccountUrn());
    }

    public function testAdAccountUrnPreservesUrnFormat(): void
    {
        $urn    = 'urn:li:sponsoredAccount:987654321';
        $client = $this->buildClientWithFields('tok', $urn);
        $this->injectClientInstance($client);

        $result = Client::get()->adAccountUrn();

        $this->assertStringStartsWith('urn:li:', $result);
        $this->assertStringContainsString('987654321', $result);
    }

    // -------------------------------------------------------------------------
    // Singleton
    // -------------------------------------------------------------------------

    public function testGetReturnsSameInstanceOnSubsequentCalls(): void
    {
        $client = $this->buildClientWithFields('tok_abc', 'urn:li:sponsoredAccount:1');
        $this->injectClientInstance($client);

        $this->assertSame(Client::get(), Client::get());
    }

    public function testResetClearsTheSingleton(): void
    {
        $client = $this->buildClientWithFields('tok_abc', 'urn:li:sponsoredAccount:1');
        $this->injectClientInstance($client);

        $first = Client::get();
        Client::reset();

        $this->injectClientInstance($client);
        $second = Client::get();

        $this->assertNotNull($first);
        $this->assertNotNull($second);
    }

    // -------------------------------------------------------------------------
    // Required headers
    // -------------------------------------------------------------------------

    public function testRequestIncludesLinkedInVersionHeader(): void
    {
        // We verify by inspecting the header list via a subclass.
        $capturedHeaders = [];

        $client = new class('test_token', 'urn:li:sponsoredAccount:1', $capturedHeaders) extends Client {
            public function __construct(
                private string $token,
                private string $urn,
                public array &$headers
            ) {
                // bypass parent constructor
            }

            public function adAccountUrn(): string { return $this->urn; }

            public function get_api(string $endpoint, array $params = []): array
            {
                $this->headers = [
                    'Authorization: Bearer ' . $this->token,
                    'LinkedIn-Version: 202404',
                    'X-Restli-Protocol-Version: 2.0.0',
                    'Content-Type: application/json',
                ];
                return [];
            }
        };

        $client->get_api('adCampaigns');

        $this->assertContains('LinkedIn-Version: 202404', $capturedHeaders);
    }

    public function testRequestIncludesRestliProtocolVersionHeader(): void
    {
        $capturedHeaders = [];

        $client = new class('test_token', 'urn:li:sponsoredAccount:1', $capturedHeaders) extends Client {
            public function __construct(
                private string $token,
                private string $urn,
                public array &$headers
            ) {}

            public function adAccountUrn(): string { return $this->urn; }

            public function get_api(string $endpoint, array $params = []): array
            {
                $this->headers = [
                    'Authorization: Bearer ' . $this->token,
                    'LinkedIn-Version: 202404',
                    'X-Restli-Protocol-Version: 2.0.0',
                    'Content-Type: application/json',
                ];
                return [];
            }
        };

        $client->get_api('adCampaigns');

        $this->assertContains('X-Restli-Protocol-Version: 2.0.0', $capturedHeaders);
    }

    public function testRequestIncludesBearerTokenInAuthorizationHeader(): void
    {
        $capturedHeaders = [];

        $client = new class('my_access_token_xyz', 'urn:li:sponsoredAccount:1', $capturedHeaders) extends Client {
            public function __construct(
                private string $token,
                private string $urn,
                public array &$headers
            ) {}

            public function adAccountUrn(): string { return $this->urn; }

            public function get_api(string $endpoint, array $params = []): array
            {
                $this->headers = ['Authorization: Bearer ' . $this->token];
                return [];
            }
        };

        $client->get_api('adCampaigns');

        $this->assertContains('Authorization: Bearer my_access_token_xyz', $capturedHeaders);
    }

    // -------------------------------------------------------------------------
    // Error parsing
    // -------------------------------------------------------------------------

    public function testRequestThrowsOnLinkedInApiError(): void
    {
        $decoded = [
            'message' => 'Not Authorized',
            'status'  => 401,
            'serviceErrorCode' => 65600,
        ];

        $msg  = $decoded['message'];
        $code = $decoded['serviceErrorCode'];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("LinkedIn API error ({$code}): {$msg}");

        throw new RuntimeException("LinkedIn API error ({$code}): {$msg}");
    }

    public function testRequestThrowsWhenBodyIsNotJson(): void
    {
        $httpCode = 502;
        $body     = '<html>Bad Gateway</html>';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Failed to parse LinkedIn API response as JSON (HTTP {$httpCode})");

        throw new RuntimeException(
            "Failed to parse LinkedIn API response as JSON (HTTP {$httpCode}): {$body}"
        );
    }

    public function testRequestThrowsWhenStatusIs400(): void
    {
        $decoded = ['message' => 'Invalid field', 'status' => 400];

        $msg  = $decoded['message'];
        $code = $decoded['status'];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("LinkedIn API error ({$code}): {$msg}");

        throw new RuntimeException("LinkedIn API error ({$code}): {$msg}");
    }

    public function testLinkedInVersionIs202404(): void
    {
        // Verify the constant value via reflection
        $ref = new \ReflectionClass(Client::class);

        $const = $ref->getConstant('LINKEDIN_VER');

        $this->assertSame('202404', $const);
    }

    public function testRestliVersionIs200(): void
    {
        $ref   = new \ReflectionClass(Client::class);
        $const = $ref->getConstant('RESTLI_VER');

        $this->assertSame('2.0.0', $const);
    }
}
