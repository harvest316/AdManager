<?php

declare(strict_types=1);

namespace AdManager\Tests\Strategy;

use AdManager\DB;
use AdManager\Strategy\Store;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Strategy\Store CRUD operations.
 *
 * Each test gets a fresh in-memory SQLite database via DB::reset() +
 * ADMANAGER_DB_PATH=:memory: so nothing touches the real admanager.db.
 */
class StoreTest extends TestCase
{
    private Store $store;
    private PDO $db;

    protected function setUp(): void
    {
        // Point DB singleton at a fresh in-memory database
        DB::reset();
        putenv('ADMANAGER_DB_PATH=:memory:');

        $this->db = DB::get();
        $schema = file_get_contents(dirname(__DIR__, 2) . '/db/schema.sql');
        $this->db->exec($schema);

        // Seed a project to satisfy FK constraints
        $this->db->exec(
            "INSERT INTO projects (id, name, display_name, website_url)
             VALUES (1, 'test-project', 'Test Project', 'https://example.com')"
        );

        $this->store = new Store();
    }

    protected function tearDown(): void
    {
        DB::reset();
        putenv('ADMANAGER_DB_PATH');
    }

    // -------------------------------------------------------------------------
    // save()
    // -------------------------------------------------------------------------

    public function testSaveReturnsPositiveIntegerId(): void
    {
        $id = $this->store->save(1, 'My Strategy', 'google', 'search', 'Full strategy text');
        $this->assertGreaterThan(0, $id);
    }

    public function testSaveStoresAllFields(): void
    {
        $id = $this->store->save(1, 'Full Strategy', 'all', 'full', 'Strategy body', 'claude');

        $row = $this->store->get($id);
        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row['project_id']);
        $this->assertSame('Full Strategy', $row['name']);
        $this->assertSame('all', $row['platform']);
        $this->assertSame('full', $row['campaign_type']);
        $this->assertSame('Strategy body', $row['full_strategy']);
        $this->assertSame('claude', $row['model']);
    }

    public function testSaveDefaultsModelToClaude(): void
    {
        // model param defaults to 'claude'
        $id = $this->store->save(1, 'My Strategy', 'google', 'search', 'Body text');
        $row = $this->store->get($id);
        $this->assertSame('claude', $row['model']);
    }

    public function testSaveIncrementsIdForEachInsert(): void
    {
        $id1 = $this->store->save(1, 'Strategy A', 'google', 'search', 'Body A');
        $id2 = $this->store->save(1, 'Strategy B', 'meta', 'pmax', 'Body B');

        $this->assertGreaterThan($id1, $id2);
    }

    // -------------------------------------------------------------------------
    // get()
    // -------------------------------------------------------------------------

    public function testGetReturnsNullForMissingId(): void
    {
        $this->assertNull($this->store->get(9999));
    }

    public function testGetReturnsCorrectRow(): void
    {
        $id = $this->store->save(1, 'Named Strategy', 'google', 'search', 'Content here');
        $row = $this->store->get($id);

        $this->assertIsArray($row);
        $this->assertSame($id, (int) $row['id']);
        $this->assertSame('Named Strategy', $row['name']);
    }

    // -------------------------------------------------------------------------
    // listByProject()
    // -------------------------------------------------------------------------

    public function testListByProjectReturnsEmptyArrayForNewProject(): void
    {
        // Insert a second project with no strategies
        $this->db->exec(
            "INSERT INTO projects (id, name) VALUES (2, 'empty-project')"
        );
        $this->assertSame([], $this->store->listByProject(2));
    }

    public function testListByProjectReturnsAllStrategiesForProject(): void
    {
        $this->store->save(1, 'Strategy A', 'google', 'search', 'Body A');
        $this->store->save(1, 'Strategy B', 'meta', 'pmax', 'Body B');
        $this->store->save(1, 'Strategy C', 'all', 'full', 'Body C');

        $list = $this->store->listByProject(1);
        $this->assertCount(3, $list);
    }

    public function testListByProjectDoesNotReturnStrategiesFromOtherProjects(): void
    {
        $this->db->exec(
            "INSERT INTO projects (id, name) VALUES (2, 'other-project')"
        );

        $this->store->save(1, 'Project 1 Strategy', 'google', 'search', 'Body');
        $this->store->save(2, 'Project 2 Strategy', 'meta', 'pmax', 'Body');

        $list = $this->store->listByProject(1);
        $this->assertCount(1, $list);
        $this->assertSame('Project 1 Strategy', $list[0]['name']);
    }

    public function testListByProjectReturnsOnlySelectedColumns(): void
    {
        $this->store->save(1, 'My Strategy', 'google', 'search', 'Body text');
        $list = $this->store->listByProject(1);

        // listByProject selects specific columns — full_strategy is excluded
        $row = $list[0];
        $this->assertArrayHasKey('id', $row);
        $this->assertArrayHasKey('name', $row);
        $this->assertArrayHasKey('platform', $row);
        $this->assertArrayHasKey('campaign_type', $row);
        $this->assertArrayHasKey('model', $row);
        $this->assertArrayNotHasKey('full_strategy', $row);
    }

    public function testListByProjectOrdersByCreatedAtDesc(): void
    {
        $id1 = $this->store->save(1, 'First', 'google', 'search', 'Body');

        // Force the second row to have a strictly later created_at
        $this->db->exec("UPDATE strategies SET created_at = datetime('now', '-1 second') WHERE id = {$id1}");

        $id2 = $this->store->save(1, 'Second', 'meta', 'pmax', 'Body');

        $list = $this->store->listByProject(1);
        // Most recent (id2) should be listed first
        $this->assertSame($id2, (int) $list[0]['id']);
        $this->assertSame($id1, (int) $list[1]['id']);
    }

    // -------------------------------------------------------------------------
    // getLatest()
    // -------------------------------------------------------------------------

    public function testGetLatestReturnsNullWhenNoneExist(): void
    {
        $this->assertNull($this->store->getLatest(1, 'google'));
    }

    public function testGetLatestReturnsMostRecentForPlatform(): void
    {
        $id1 = $this->store->save(1, 'Google Strategy v1', 'google', 'search', 'Body v1');

        // Make v1 older so the ordering is unambiguous
        $this->db->exec("UPDATE strategies SET created_at = datetime('now', '-1 second') WHERE id = {$id1}");

        $id2 = $this->store->save(1, 'Google Strategy v2', 'google', 'pmax', 'Body v2');

        $latest = $this->store->getLatest(1, 'google');
        $this->assertNotNull($latest);
        // v2 is more recent, so it should be returned
        $this->assertSame($id2, (int) $latest['id']);
    }

    public function testGetLatestFiltersCorrectlyByPlatform(): void
    {
        $this->store->save(1, 'Google Strategy', 'google', 'search', 'Body');
        $idMeta = $this->store->save(1, 'Meta Strategy', 'meta', 'pmax', 'Body');

        $latest = $this->store->getLatest(1, 'meta');
        $this->assertSame($idMeta, (int) $latest['id']);

        // Should return null for a platform with no strategies
        $this->assertNull($this->store->getLatest(1, 'microsoft'));
    }

    // -------------------------------------------------------------------------
    // delete()
    // -------------------------------------------------------------------------

    public function testDeleteRemovesStrategy(): void
    {
        $id = $this->store->save(1, 'To Delete', 'google', 'search', 'Body');
        $this->assertNotNull($this->store->get($id));

        $this->store->delete($id);
        $this->assertNull($this->store->get($id));
    }

    public function testDeleteNullsOutCampaignStrategyId(): void
    {
        $strategyId = $this->store->save(1, 'Strategy', 'google', 'search', 'Body');

        // Insert a campaign linked to this strategy
        $this->db->prepare(
            'INSERT INTO campaigns (project_id, platform, name, type, strategy_id)
             VALUES (1, :platform, :name, :type, :sid)'
        )->execute([
            ':platform' => 'google',
            ':name'     => 'Test Campaign',
            ':type'     => 'search',
            ':sid'      => $strategyId,
        ]);

        $this->store->delete($strategyId);

        $campaign = $this->db->query(
            "SELECT strategy_id FROM campaigns WHERE name = 'Test Campaign'"
        )->fetch();

        $this->assertNull($campaign['strategy_id']);
    }

    public function testDeleteIsIdempotentForMissingId(): void
    {
        // Deleting a non-existent strategy should not throw
        $this->store->delete(9999);
        $this->assertNull($this->store->get(9999)); // still null — no exception
    }
}
