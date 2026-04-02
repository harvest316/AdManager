<?php

namespace AdManager\Tests\Dashboard;

use AdManager\Dashboard\SyncRunner;
use AdManager\DB;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class SyncRunnerTest extends TestCase
{
    private SyncRunner $runner;

    protected function setUp(): void
    {
        putenv('ADMANAGER_DB_PATH=:memory:');
        DB::reset();
        DB::init();

        $db = DB::get();
        $db->exec("INSERT INTO projects (id, name, display_name, website_url) VALUES (1, 'test', 'Test', 'https://test.com')");

        $this->runner = new SyncRunner();
    }

    protected function tearDown(): void
    {
        DB::reset();
        putenv('ADMANAGER_DB_PATH');
    }

    public function testPollNotFoundThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Job not found');
        $this->runner->poll(999);
    }

    public function testPollReturnsCompletedJob(): void
    {
        $db = DB::get();
        $db->exec("INSERT INTO sync_jobs (id, project_id, platform, days, status, output, started_at, completed_at) VALUES (1, 1, 'google', 7, 'complete', 'Synced 42 rows', datetime('now', '-60 seconds'), datetime('now'))");

        $result = $this->runner->poll(1);

        $this->assertEquals('complete', $result['status']);
        $this->assertEquals('Synced 42 rows', $result['output']);
        $this->assertNotNull($result['duration']);
    }

    public function testPollReturnsFailedJob(): void
    {
        $db = DB::get();
        $db->exec("INSERT INTO sync_jobs (id, project_id, platform, days, status, output, started_at, completed_at) VALUES (2, 1, 'meta', 14, 'failed', 'Error: API timeout', datetime('now'), datetime('now'))");

        $result = $this->runner->poll(2);

        $this->assertEquals('failed', $result['status']);
        $this->assertStringContainsString('Error', $result['output']);
    }

    public function testStartThrowsWhenConcurrentSync(): void
    {
        $db = DB::get();
        $db->exec("INSERT INTO sync_jobs (id, project_id, platform, days, status, started_at) VALUES (1, 1, 'all', 7, 'running', datetime('now'))");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Sync already in progress');
        $this->runner->start(1);
    }

    public function testStartThrowsForMissingProject(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not found');
        $this->runner->start(999);
    }

    public function testPollDurationCalculation(): void
    {
        $db = DB::get();
        $db->exec("INSERT INTO sync_jobs (id, project_id, platform, days, status, output, started_at, completed_at) VALUES (3, 1, 'all', 7, 'complete', 'OK', datetime('now', '-120 seconds'), datetime('now'))");

        $result = $this->runner->poll(3);
        // Duration should be ~120 seconds
        $this->assertGreaterThanOrEqual(119, $result['duration']);
        $this->assertLessThanOrEqual(121, $result['duration']);
    }
}
