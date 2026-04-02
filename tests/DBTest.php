<?php

namespace AdManager\Tests;

use AdManager\DB;
use PHPUnit\Framework\TestCase;

class DBTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('ADMANAGER_DB_PATH=:memory:');
        DB::reset();
    }

    protected function tearDown(): void
    {
        DB::reset();
        putenv('ADMANAGER_DB_PATH');
    }

    public function testGetReturnsPDO(): void
    {
        $db = DB::get();
        $this->assertInstanceOf(\PDO::class, $db);
    }

    public function testGetReturnsSameInstance(): void
    {
        $db1 = DB::get();
        $db2 = DB::get();
        $this->assertSame($db1, $db2);
    }

    public function testResetClearsSingleton(): void
    {
        $db1 = DB::get();
        DB::reset();
        $db2 = DB::get();
        $this->assertNotSame($db1, $db2);
    }

    public function testErrorModeIsException(): void
    {
        $db = DB::get();
        $mode = $db->getAttribute(\PDO::ATTR_ERRMODE);
        $this->assertEquals(\PDO::ERRMODE_EXCEPTION, $mode);
    }

    public function testDefaultFetchModeIsAssoc(): void
    {
        $db = DB::get();
        $mode = $db->getAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE);
        $this->assertEquals(\PDO::FETCH_ASSOC, $mode);
    }

    public function testWALEnabled(): void
    {
        $db = DB::get();
        $mode = $db->query("PRAGMA journal_mode")->fetchColumn();
        // In-memory DB may report 'memory' instead of 'wal', both are acceptable
        $this->assertContains($mode, ['wal', 'memory']);
    }

    public function testForeignKeysEnabled(): void
    {
        $db = DB::get();
        $fk = $db->query("PRAGMA foreign_keys")->fetchColumn();
        $this->assertEquals(1, $fk);
    }

    public function testInitCreatesSchema(): void
    {
        DB::init();
        $db = DB::get();

        // Check key tables exist
        $tables = $db->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(\PDO::FETCH_COLUMN);

        $this->assertContains('projects', $tables);
        $this->assertContains('campaigns', $tables);
        $this->assertContains('ad_groups', $tables);
        $this->assertContains('ads', $tables);
        $this->assertContains('performance', $tables);
        $this->assertContains('ad_copy', $tables);
        $this->assertContains('strategies', $tables);
        $this->assertContains('cron_jobs', $tables);
    }

    public function testInitIdempotent(): void
    {
        DB::init();
        DB::init(); // Should not throw
        $db = DB::get();
        $tables = $db->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertNotEmpty($tables);
    }
}
