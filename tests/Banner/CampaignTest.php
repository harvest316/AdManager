<?php

declare(strict_types=1);

namespace AdManager\Tests\Banner;

use AdManager\Banner\Campaign;
use AdManager\Banner\Client;
use AdManager\DB;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Banner\Campaign.
 *
 * Campaign reads/writes the local SQLite DB. We use an in-memory DB via
 * ADMANAGER_DB_PATH=:memory: and initialise the schema before each test.
 *
 * We inject a mock Client to control networkName(), and verify:
 * - create() inserts a row with the correct platform and returns an ID
 * - status defaults to 'paused' on create
 * - pause() updates status to 'paused'
 * - enable() updates status to 'active'
 * - list() returns only rows matching the network name
 * - get() returns the correct row
 * - get() throws RuntimeException for unknown ID
 */
class CampaignTest extends TestCase
{
    private const NETWORK_NAME = 'banner_test';
    private const PROJECT_ID   = 1;

    protected function setUp(): void
    {
        // Point DB to an in-memory SQLite instance for isolation
        putenv('ADMANAGER_DB_PATH=:memory:');
        $_ENV['ADMANAGER_DB_PATH'] = ':memory:';

        DB::reset();
        DB::init();

        // Seed a project row so foreign key constraint is satisfied
        DB::get()->exec(
            "INSERT INTO projects (id, name, display_name) VALUES (1, 'test_project', 'Test Project')"
        );

        $this->injectMockClient($this->buildClientMock(self::NETWORK_NAME));
    }

    protected function tearDown(): void
    {
        Client::reset();
        DB::reset();
        putenv('ADMANAGER_DB_PATH');
        unset($_ENV['ADMANAGER_DB_PATH']);
    }

    // -------------------------------------------------------------------------
    // create()
    // -------------------------------------------------------------------------

    public function testCreateInsertsRowAndReturnsId(): void
    {
        $campaign = new Campaign();
        $id = $campaign->create([
            'name'       => 'TestBrand — Banner — AU',
            'project_id' => self::PROJECT_ID,
        ]);

        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id);
    }

    public function testCreateSetsPlatformToNetworkName(): void
    {
        $campaign = new Campaign();
        $id = $campaign->create([
            'name'       => 'TestBrand — Banner — AU',
            'project_id' => self::PROJECT_ID,
        ]);

        $row = $campaign->get($id);

        $this->assertSame(self::NETWORK_NAME, $row['platform']);
    }

    public function testCreateDefaultsStatusToPaused(): void
    {
        $campaign = new Campaign();
        $id = $campaign->create([
            'name'       => 'Test',
            'project_id' => self::PROJECT_ID,
        ]);

        $row = $campaign->get($id);

        $this->assertSame('paused', $row['status']);
    }

    public function testCreateRespectsExplicitStatus(): void
    {
        $campaign = new Campaign();
        $id = $campaign->create([
            'name'       => 'Test',
            'project_id' => self::PROJECT_ID,
            'status'     => 'active',
        ]);

        $row = $campaign->get($id);

        $this->assertSame('active', $row['status']);
    }

    public function testCreateStoresDailyBudget(): void
    {
        $campaign = new Campaign();
        $id = $campaign->create([
            'name'         => 'Test',
            'project_id'   => self::PROJECT_ID,
            'daily_budget' => 99.99,
        ]);

        $row = $campaign->get($id);

        $this->assertEqualsWithDelta(99.99, (float) $row['daily_budget_aud'], 0.001);
    }

    public function testCreateDefaultsTypeToDiplay(): void
    {
        $campaign = new Campaign();
        $id = $campaign->create([
            'name'       => 'Test',
            'project_id' => self::PROJECT_ID,
        ]);

        $row = $campaign->get($id);

        $this->assertSame('display', $row['type']);
    }

    // -------------------------------------------------------------------------
    // pause() / enable()
    // -------------------------------------------------------------------------

    public function testPauseSetsCampaignStatusToPaused(): void
    {
        $campaign = new Campaign();
        $id = $campaign->create([
            'name'       => 'Test',
            'project_id' => self::PROJECT_ID,
            'status'     => 'active',
        ]);

        $campaign->pause($id);

        $row = $campaign->get($id);
        $this->assertSame('paused', $row['status']);
    }

    public function testEnableSetsCampaignStatusToActive(): void
    {
        $campaign = new Campaign();
        $id = $campaign->create([
            'name'       => 'Test',
            'project_id' => self::PROJECT_ID,
        ]);

        $campaign->enable($id);

        $row = $campaign->get($id);
        $this->assertSame('active', $row['status']);
    }

    // -------------------------------------------------------------------------
    // list()
    // -------------------------------------------------------------------------

    public function testListReturnsAllCampaignsForThisNetwork(): void
    {
        $campaign = new Campaign();
        $campaign->create(['name' => 'Camp A', 'project_id' => self::PROJECT_ID]);
        $campaign->create(['name' => 'Camp B', 'project_id' => self::PROJECT_ID]);

        $result = $campaign->list();

        $this->assertCount(2, $result);
    }

    public function testListDoesNotReturnOtherPlatformCampaigns(): void
    {
        // Insert a campaign for a different platform directly
        DB::get()->exec(
            "INSERT INTO campaigns (project_id, platform, name, type, status)
             VALUES (1, 'google', 'Google Campaign', 'search', 'paused')"
        );

        $campaign = new Campaign();
        $campaign->create(['name' => 'Banner Camp', 'project_id' => self::PROJECT_ID]);

        $result = $campaign->list();

        $this->assertCount(1, $result);
        $this->assertSame(self::NETWORK_NAME, $result[0]['platform']);
    }

    public function testListReturnsEmptyArrayWhenNoCampaigns(): void
    {
        $campaign = new Campaign();
        $this->assertSame([], $campaign->list());
    }

    // -------------------------------------------------------------------------
    // get()
    // -------------------------------------------------------------------------

    public function testGetReturnsCorrectRow(): void
    {
        $campaign = new Campaign();
        $id = $campaign->create([
            'name'       => 'Specific Campaign',
            'project_id' => self::PROJECT_ID,
        ]);

        $row = $campaign->get($id);

        $this->assertSame('Specific Campaign', $row['name']);
        $this->assertSame($id, (int) $row['id']);
    }

    public function testGetThrowsForUnknownId(): void
    {
        $campaign = new Campaign();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Banner campaign 99999 not found');

        $campaign->get(99999);
    }

    public function testGetThrowsWhenCampaignBelongsToDifferentPlatform(): void
    {
        // Insert a campaign for a different platform directly
        $db = DB::get();
        $db->exec(
            "INSERT INTO campaigns (project_id, platform, name, type, status)
             VALUES (1, 'google', 'Google Campaign', 'search', 'paused')"
        );
        $otherId = (int) $db->lastInsertId();

        $campaign = new Campaign();

        $this->expectException(\RuntimeException::class);

        $campaign->get($otherId);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildClientMock(string $networkName): Client
    {
        $mock = $this->createMock(Client::class);
        $mock->method('networkName')->willReturn($networkName);
        return $mock;
    }

    private function injectMockClient(Client $client): void
    {
        $ref = new \ReflectionProperty(Client::class, 'instance');
        $ref->setAccessible(true);
        $ref->setValue(null, $client);
    }
}
