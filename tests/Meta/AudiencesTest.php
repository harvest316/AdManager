<?php

declare(strict_types=1);

namespace AdManager\Tests\Meta;

use AdManager\Meta\Audiences;
use AdManager\Meta\Client;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Meta\Audiences.
 *
 * Audiences wraps Client::post(), Client::get_api(), and Client::delete()
 * to manage custom audiences on the Graph API. We inject a mock Client via
 * the singleton slot and verify:
 * - createWebsiteAudience() posts to correct endpoint with WEBSITE subtype
 * - createWebsiteAudience() passes pixelId, retentionDays, and defaults correctly
 * - createCustomerFileAudience() posts with CUSTOM subtype and SHA256-hashed emails
 * - createCustomerFileAudience() normalises emails (lowercase, trimmed) before hashing
 * - createLookalike() posts with LOOKALIKE subtype and correct spec
 * - createLookalike() converts percent to ratio correctly (1% → 0.01)
 * - list() calls the correct endpoint with fields
 * - get() fetches the correct audience endpoint
 * - delete() calls Client::delete() with the audience ID
 * - getSize() returns approximate_count as int
 * - getSize() returns null when approximate_count is absent
 */
class AudiencesTest extends TestCase
{
    private const FAKE_AD_ACCOUNT_ID = 'act_111222333';
    private const FAKE_AUDIENCE_ID   = '555000555';
    private const FAKE_PIXEL_ID      = 'px_987654321';

    protected function tearDown(): void
    {
        Client::reset();
    }

    // -------------------------------------------------------------------------
    // createWebsiteAudience()
    // -------------------------------------------------------------------------

    public function testCreateWebsiteAudiencePostsToCustomAudiencesEndpoint(): void
    {
        $capture = new \stdClass();
        $capture->endpoint = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        $audiences = new Audiences();
        $audiences->createWebsiteAudience('Retargeting 30d', self::FAKE_PIXEL_ID);

        $this->assertSame(self::FAKE_AD_ACCOUNT_ID . '/customaudiences', $capture->endpoint);
    }

    public function testCreateWebsiteAudienceUsesWebsiteSubtype(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        $audiences = new Audiences();
        $audiences->createWebsiteAudience('Retargeting 30d', self::FAKE_PIXEL_ID);

        $this->assertSame('WEBSITE', $capture->data['subtype']);
    }

    public function testCreateWebsiteAudienceIncludesName(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        $audiences = new Audiences();
        $audiences->createWebsiteAudience('All Visitors 30d', self::FAKE_PIXEL_ID);

        $this->assertSame('All Visitors 30d', $capture->data['name']);
    }

    public function testCreateWebsiteAudienceIncludesPixelId(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        $audiences = new Audiences();
        $audiences->createWebsiteAudience('Retargeting', self::FAKE_PIXEL_ID);

        $this->assertSame(self::FAKE_PIXEL_ID, $capture->data['pixel_id']);
    }

    public function testCreateWebsiteAudienceDefaultsRetentionDaysTo30(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        $audiences = new Audiences();
        $audiences->createWebsiteAudience('Retargeting', self::FAKE_PIXEL_ID);

        $this->assertSame(30, $capture->data['retention_days']);
    }

    public function testCreateWebsiteAudienceRespectsCustomRetentionDays(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        $audiences = new Audiences();
        $audiences->createWebsiteAudience('Retargeting 60d', self::FAKE_PIXEL_ID, 60);

        $this->assertSame(60, $capture->data['retention_days']);
    }

    public function testCreateWebsiteAudiencePassesCustomRule(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        $customRule = '{"inclusions":{"operator":"or","rules":[{"event_sources":[{"id":"px_1","type":"pixel"}]}]}}';

        $audiences = new Audiences();
        $audiences->createWebsiteAudience('Cart Abandoners', self::FAKE_PIXEL_ID, 14, $customRule);

        $this->assertSame($customRule, $capture->data['rule']);
    }

    public function testCreateWebsiteAudienceReturnsId(): void
    {
        $mock = $this->createMock(Client::class);
        $mock->method('adAccountId')->willReturn(self::FAKE_AD_ACCOUNT_ID);
        $mock->method('post')->willReturn(['id' => self::FAKE_AUDIENCE_ID]);
        $mock->method('get_api')->willReturn(['data' => []]);
        $this->injectMockClient($mock);

        $audiences = new Audiences();
        $result = $audiences->createWebsiteAudience('Retargeting', self::FAKE_PIXEL_ID);

        $this->assertSame(self::FAKE_AUDIENCE_ID, $result);
    }

    // -------------------------------------------------------------------------
    // createCustomerFileAudience()
    // -------------------------------------------------------------------------

    public function testCreateCustomerFileAudiencePostsToCustomAudiencesEndpoint(): void
    {
        $capture = new \stdClass();
        $capture->endpoint = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        $audiences = new Audiences();
        $audiences->createCustomerFileAudience('Customers', ['user@example.com']);

        $this->assertSame(self::FAKE_AD_ACCOUNT_ID . '/customaudiences', $capture->endpoint);
    }

    public function testCreateCustomerFileAudienceUsesCustomSubtype(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        $audiences = new Audiences();
        $audiences->createCustomerFileAudience('Customers', ['user@example.com']);

        $this->assertSame('CUSTOM', $capture->data['subtype']);
    }

    public function testCreateCustomerFileAudienceHashesEmailsWithSha256(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        $email = 'user@example.com';
        $expectedHash = hash('sha256', $email);

        $audiences = new Audiences();
        $audiences->createCustomerFileAudience('Customers', [$email]);

        $hashes = json_decode($capture->data['data'], true);
        $this->assertContains($expectedHash, $hashes);
    }

    public function testCreateCustomerFileAudienceNormalisesEmailBeforeHashing(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        // Mixed-case with leading/trailing whitespace
        $dirtyEmail = '  User@Example.COM  ';
        $expectedHash = hash('sha256', 'user@example.com');

        $audiences = new Audiences();
        $audiences->createCustomerFileAudience('Customers', [$dirtyEmail]);

        $hashes = json_decode($capture->data['data'], true);
        $this->assertContains($expectedHash, $hashes);
    }

    public function testCreateCustomerFileAudienceHashesMultipleEmails(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        $emails = ['alice@example.com', 'bob@example.com', 'carol@example.com'];

        $audiences = new Audiences();
        $audiences->createCustomerFileAudience('Customers', $emails);

        $hashes = json_decode($capture->data['data'], true);
        $this->assertCount(3, $hashes);

        foreach ($emails as $email) {
            $this->assertContains(hash('sha256', $email), $hashes);
        }
    }

    public function testCreateCustomerFileAudienceReturnsId(): void
    {
        $mock = $this->createMock(Client::class);
        $mock->method('adAccountId')->willReturn(self::FAKE_AD_ACCOUNT_ID);
        $mock->method('post')->willReturn(['id' => self::FAKE_AUDIENCE_ID]);
        $mock->method('get_api')->willReturn(['data' => []]);
        $this->injectMockClient($mock);

        $audiences = new Audiences();
        $result = $audiences->createCustomerFileAudience('Customers', ['user@example.com']);

        $this->assertSame(self::FAKE_AUDIENCE_ID, $result);
    }

    // -------------------------------------------------------------------------
    // createLookalike()
    // -------------------------------------------------------------------------

    public function testCreateLookalikePostsToCustomAudiencesEndpoint(): void
    {
        $capture = new \stdClass();
        $capture->endpoint = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        $audiences = new Audiences();
        $audiences->createLookalike('Lookalike 1% AU', self::FAKE_AUDIENCE_ID, 'AU');

        $this->assertSame(self::FAKE_AD_ACCOUNT_ID . '/customaudiences', $capture->endpoint);
    }

    public function testCreateLookalikeUsesLookalikeSubtype(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        $audiences = new Audiences();
        $audiences->createLookalike('Lookalike 1% AU', self::FAKE_AUDIENCE_ID, 'AU');

        $this->assertSame('LOOKALIKE', $capture->data['subtype']);
    }

    public function testCreateLookalikeIncludesSourceAudienceIdInSpec(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        $audiences = new Audiences();
        $audiences->createLookalike('Lookalike', self::FAKE_AUDIENCE_ID, 'AU');

        $spec = json_decode($capture->data['lookalike_spec'], true);
        $this->assertSame(self::FAKE_AUDIENCE_ID, $spec['origin_audience_id']);
    }

    public function testCreateLookalikeIncludesCountryInSpec(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        $audiences = new Audiences();
        $audiences->createLookalike('Lookalike', self::FAKE_AUDIENCE_ID, 'GB');

        $spec = json_decode($capture->data['lookalike_spec'], true);
        $this->assertSame('GB', $spec['country']);
    }

    public function testCreateLookalikeConvertsPercentToRatio(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        $audiences = new Audiences();
        $audiences->createLookalike('Lookalike 1%', self::FAKE_AUDIENCE_ID, 'AU', 1);

        $spec = json_decode($capture->data['lookalike_spec'], true);
        $this->assertEqualsWithDelta(0.01, $spec['ratio'], 0.0001);
    }

    public function testCreateLookalikeDefaultsToOnePercent(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        $audiences = new Audiences();
        $audiences->createLookalike('Lookalike', self::FAKE_AUDIENCE_ID, 'AU'); // no percent

        $spec = json_decode($capture->data['lookalike_spec'], true);
        $this->assertEqualsWithDelta(0.01, $spec['ratio'], 0.0001);
    }

    public function testCreateLookalikeFivePercentConvertsToPointZeroFive(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $this->injectMockClient($this->buildCapturingPostMock($capture));

        $audiences = new Audiences();
        $audiences->createLookalike('Lookalike 5%', self::FAKE_AUDIENCE_ID, 'AU', 5);

        $spec = json_decode($capture->data['lookalike_spec'], true);
        $this->assertEqualsWithDelta(0.05, $spec['ratio'], 0.0001);
    }

    public function testCreateLookalikeReturnsId(): void
    {
        $mock = $this->createMock(Client::class);
        $mock->method('adAccountId')->willReturn(self::FAKE_AD_ACCOUNT_ID);
        $mock->method('post')->willReturn(['id' => self::FAKE_AUDIENCE_ID]);
        $mock->method('get_api')->willReturn(['data' => []]);
        $this->injectMockClient($mock);

        $audiences = new Audiences();
        $result = $audiences->createLookalike('Lookalike', self::FAKE_AUDIENCE_ID, 'AU');

        $this->assertSame(self::FAKE_AUDIENCE_ID, $result);
    }

    // -------------------------------------------------------------------------
    // list()
    // -------------------------------------------------------------------------

    public function testListCallsCustomAudiencesEndpoint(): void
    {
        $capture = new \stdClass();
        $capture->endpoint = null;

        $this->injectMockClient($this->buildCapturingGetApiMock($capture));

        $audiences = new Audiences();
        $audiences->list();

        $this->assertSame(self::FAKE_AD_ACCOUNT_ID . '/customaudiences', $capture->endpoint);
    }

    public function testListIncludesFieldsParam(): void
    {
        $capture = new \stdClass();
        $capture->params = null;

        $this->injectMockClient($this->buildCapturingGetApiMock($capture));

        $audiences = new Audiences();
        $audiences->list();

        $this->assertArrayHasKey('fields', $capture->params);
        $this->assertStringContainsString('name', $capture->params['fields']);
        $this->assertStringContainsString('subtype', $capture->params['fields']);
        $this->assertStringContainsString('approximate_count', $capture->params['fields']);
    }

    public function testListReturnsDataArray(): void
    {
        $fakeData = [
            ['id' => '1', 'name' => 'Audience One', 'subtype' => 'WEBSITE'],
        ];

        $mock = $this->createMock(Client::class);
        $mock->method('adAccountId')->willReturn(self::FAKE_AD_ACCOUNT_ID);
        $mock->method('get_api')->willReturn(['data' => $fakeData]);
        $mock->method('post')->willReturn(['id' => self::FAKE_AUDIENCE_ID]);
        $this->injectMockClient($mock);

        $audiences = new Audiences();
        $this->assertSame($fakeData, $audiences->list());
    }

    public function testListReturnsEmptyArrayWhenNoData(): void
    {
        $mock = $this->createMock(Client::class);
        $mock->method('adAccountId')->willReturn(self::FAKE_AD_ACCOUNT_ID);
        $mock->method('get_api')->willReturn([]);
        $mock->method('post')->willReturn(['id' => self::FAKE_AUDIENCE_ID]);
        $this->injectMockClient($mock);

        $audiences = new Audiences();
        $this->assertSame([], $audiences->list());
    }

    // -------------------------------------------------------------------------
    // get()
    // -------------------------------------------------------------------------

    public function testGetCallsAudienceIdEndpoint(): void
    {
        $capture = new \stdClass();
        $capture->endpoint = null;

        $this->injectMockClient($this->buildCapturingGetApiMock($capture));

        $audiences = new Audiences();
        $audiences->get(self::FAKE_AUDIENCE_ID);

        $this->assertSame(self::FAKE_AUDIENCE_ID, $capture->endpoint);
    }

    public function testGetIncludesFieldsParam(): void
    {
        $capture = new \stdClass();
        $capture->params = null;

        $this->injectMockClient($this->buildCapturingGetApiMock($capture));

        $audiences = new Audiences();
        $audiences->get(self::FAKE_AUDIENCE_ID);

        $this->assertArrayHasKey('fields', $capture->params);
        $this->assertStringContainsString('approximate_count', $capture->params['fields']);
    }

    // -------------------------------------------------------------------------
    // delete()
    // -------------------------------------------------------------------------

    public function testDeleteCallsClientDeleteWithAudienceId(): void
    {
        $capture = new \stdClass();
        $capture->endpoint = null;

        $mock = $this->createMock(Client::class);
        $mock->method('adAccountId')->willReturn(self::FAKE_AD_ACCOUNT_ID);
        $mock->method('delete')
             ->willReturnCallback(function (string $endpoint) use ($capture): array {
                 $capture->endpoint = $endpoint;
                 return ['success' => true];
             });
        $mock->method('post')->willReturn(['id' => self::FAKE_AUDIENCE_ID]);
        $mock->method('get_api')->willReturn(['data' => []]);
        $this->injectMockClient($mock);

        $audiences = new Audiences();
        $audiences->delete(self::FAKE_AUDIENCE_ID);

        $this->assertSame(self::FAKE_AUDIENCE_ID, $capture->endpoint);
    }

    // -------------------------------------------------------------------------
    // getSize()
    // -------------------------------------------------------------------------

    public function testGetSizeReturnsApproximateCountAsInt(): void
    {
        $mock = $this->createMock(Client::class);
        $mock->method('adAccountId')->willReturn(self::FAKE_AD_ACCOUNT_ID);
        $mock->method('get_api')->willReturn(['approximate_count' => '15000']);
        $mock->method('post')->willReturn(['id' => self::FAKE_AUDIENCE_ID]);
        $this->injectMockClient($mock);

        $audiences = new Audiences();
        $result = $audiences->getSize(self::FAKE_AUDIENCE_ID);

        $this->assertSame(15000, $result);
    }

    public function testGetSizeReturnsNullWhenApproximateCountAbsent(): void
    {
        $mock = $this->createMock(Client::class);
        $mock->method('adAccountId')->willReturn(self::FAKE_AD_ACCOUNT_ID);
        $mock->method('get_api')->willReturn(['id' => self::FAKE_AUDIENCE_ID]);
        $mock->method('post')->willReturn(['id' => self::FAKE_AUDIENCE_ID]);
        $this->injectMockClient($mock);

        $audiences = new Audiences();
        $result = $audiences->getSize(self::FAKE_AUDIENCE_ID);

        $this->assertNull($result);
    }

    public function testGetSizeQueriesCorrectEndpoint(): void
    {
        $capture = new \stdClass();
        $capture->endpoint = null;

        $mock = $this->createMock(Client::class);
        $mock->method('adAccountId')->willReturn(self::FAKE_AD_ACCOUNT_ID);
        $mock->method('get_api')
             ->willReturnCallback(function (string $endpoint, array $params) use ($capture): array {
                 $capture->endpoint = $endpoint;
                 return ['approximate_count' => 5000];
             });
        $mock->method('post')->willReturn(['id' => self::FAKE_AUDIENCE_ID]);
        $this->injectMockClient($mock);

        $audiences = new Audiences();
        $audiences->getSize(self::FAKE_AUDIENCE_ID);

        $this->assertSame(self::FAKE_AUDIENCE_ID, $capture->endpoint);
    }

    public function testGetSizeRequestsApproximateCountField(): void
    {
        $capture = new \stdClass();
        $capture->params = null;

        $mock = $this->createMock(Client::class);
        $mock->method('adAccountId')->willReturn(self::FAKE_AD_ACCOUNT_ID);
        $mock->method('get_api')
             ->willReturnCallback(function (string $endpoint, array $params) use ($capture): array {
                 $capture->params = $params;
                 return ['approximate_count' => 5000];
             });
        $mock->method('post')->willReturn(['id' => self::FAKE_AUDIENCE_ID]);
        $this->injectMockClient($mock);

        $audiences = new Audiences();
        $audiences->getSize(self::FAKE_AUDIENCE_ID);

        $this->assertArrayHasKey('fields', $capture->params);
        $this->assertStringContainsString('approximate_count', $capture->params['fields']);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildCapturingPostMock(\stdClass $capture): Client
    {
        $mock = $this->createMock(Client::class);
        $mock->method('adAccountId')->willReturn(self::FAKE_AD_ACCOUNT_ID);
        $mock->method('post')
             ->willReturnCallback(function (string $endpoint, array $data) use ($capture): array {
                 $capture->endpoint = $endpoint;
                 $capture->data     = $data;
                 return ['id' => self::FAKE_AUDIENCE_ID];
             });
        $mock->method('get_api')->willReturn(['data' => []]);
        return $mock;
    }

    private function buildCapturingGetApiMock(\stdClass $capture): Client
    {
        $mock = $this->createMock(Client::class);
        $mock->method('adAccountId')->willReturn(self::FAKE_AD_ACCOUNT_ID);
        $mock->method('get_api')
             ->willReturnCallback(function (string $endpoint, array $params) use ($capture): array {
                 $capture->endpoint = $endpoint;
                 $capture->params   = $params;
                 return ['data' => []];
             });
        $mock->method('post')->willReturn(['id' => self::FAKE_AUDIENCE_ID]);
        return $mock;
    }

    private function injectMockClient(Client $client): void
    {
        $ref = new \ReflectionProperty(Client::class, 'instance');
        $ref->setAccessible(true);
        $ref->setValue(null, $client);
    }
}
