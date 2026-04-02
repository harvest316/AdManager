<?php

namespace AdManager\Tests\Dashboard;

use AdManager\Dashboard\Changelog;
use AdManager\DB;
use PHPUnit\Framework\TestCase;

class ChangelogTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('ADMANAGER_DB_PATH=:memory:');
        DB::reset();
        DB::init();

        $db = DB::get();
        $db->exec("INSERT INTO projects (id, name, display_name, website_url) VALUES (1, 'test', 'Test Project', 'https://test.com')");
        $db->exec("INSERT INTO projects (id, name, display_name, website_url) VALUES (2, 'other', 'Other Project', 'https://other.com')");
    }

    protected function tearDown(): void
    {
        DB::reset();
        putenv('ADMANAGER_DB_PATH');
    }

    public function testLogReturnsInsertId(): void
    {
        $id = Changelog::log(1, 'creative', 'created', 'Test entry');
        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id);
    }

    public function testLogPersistsAllFields(): void
    {
        $detail = ['foo' => 'bar', 'count' => 42];
        $id = Changelog::log(1, 'split_test', 'concluded', 'Split test done', $detail, 'split_test', 99, 'optimiser');

        $db = DB::get();
        $stmt = $db->prepare('SELECT * FROM changelog WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        $this->assertEquals(1, $row['project_id']);
        $this->assertEquals('split_test', $row['category']);
        $this->assertEquals('concluded', $row['action']);
        $this->assertEquals('Split test done', $row['summary']);
        $this->assertEquals(json_encode($detail), $row['detail_json']);
        $this->assertEquals('split_test', $row['entity_type']);
        $this->assertEquals(99, $row['entity_id']);
        $this->assertEquals('optimiser', $row['actor']);
        $this->assertNotNull($row['created_at']);
    }

    public function testLogWithNullOptionalFields(): void
    {
        $id = Changelog::log(1, 'system', 'analysed', 'Analysis run');

        $db = DB::get();
        $stmt = $db->prepare('SELECT * FROM changelog WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        $this->assertNull($row['detail_json']);
        $this->assertNull($row['entity_type']);
        $this->assertNull($row['entity_id']);
        $this->assertEquals('system', $row['actor']);
    }

    public function testListReturnsEntriesForProject(): void
    {
        Changelog::log(1, 'creative', 'created', 'Entry 1');
        Changelog::log(1, 'budget', 'updated', 'Entry 2');
        Changelog::log(2, 'creative', 'created', 'Other project entry');

        $entries = Changelog::list(1);
        $this->assertCount(2, $entries);

        // Both entries should be present
        $summaries = array_column($entries, 'summary');
        $this->assertContains('Entry 1', $summaries);
        $this->assertContains('Entry 2', $summaries);
    }

    public function testListFiltersByCategory(): void
    {
        Changelog::log(1, 'creative', 'created', 'Creative entry');
        Changelog::log(1, 'budget', 'updated', 'Budget entry');
        Changelog::log(1, 'creative', 'refreshed', 'Another creative');

        $creative = Changelog::list(1, 'creative');
        $this->assertCount(2, $creative);

        $budget = Changelog::list(1, 'budget');
        $this->assertCount(1, $budget);
    }

    public function testListRespectsLimitAndOffset(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            Changelog::log(1, 'system', 'check', "Entry {$i}");
        }

        $page1 = Changelog::list(1, null, 2, 0);
        $this->assertCount(2, $page1);

        $page2 = Changelog::list(1, null, 2, 2);
        $this->assertCount(2, $page2);

        $page3 = Changelog::list(1, null, 2, 4);
        $this->assertCount(1, $page3);
    }

    public function testCountReturnsCorrectTotal(): void
    {
        Changelog::log(1, 'creative', 'created', 'A');
        Changelog::log(1, 'creative', 'updated', 'B');
        Changelog::log(1, 'budget', 'updated', 'C');

        $this->assertEquals(3, Changelog::count(1));
        $this->assertEquals(2, Changelog::count(1, 'creative'));
        $this->assertEquals(1, Changelog::count(1, 'budget'));
        $this->assertEquals(0, Changelog::count(1, 'keyword'));
    }

    public function testCountReturnsZeroForEmptyProject(): void
    {
        $this->assertEquals(0, Changelog::count(999));
    }

    public function testRecentReturnsAcrossProjects(): void
    {
        Changelog::log(1, 'creative', 'created', 'Project 1 entry');
        Changelog::log(2, 'budget', 'updated', 'Project 2 entry');

        $recent = Changelog::recent(10);
        $this->assertCount(2, $recent);
        // Should include project name from join
        $this->assertArrayHasKey('project_name', $recent[0]);
    }

    public function testRecentRespectsLimit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            Changelog::log(1, 'system', 'check', "Entry {$i}");
        }

        $recent = Changelog::recent(3);
        $this->assertCount(3, $recent);
    }

    public function testCategoriesReturnsExpectedStructure(): void
    {
        $cats = Changelog::categories();

        $this->assertArrayHasKey('split_test', $cats);
        $this->assertArrayHasKey('budget', $cats);
        $this->assertArrayHasKey('creative', $cats);
        $this->assertArrayHasKey('keyword', $cats);
        $this->assertArrayHasKey('system', $cats);

        foreach ($cats as $key => $cat) {
            $this->assertArrayHasKey('label', $cat);
            $this->assertArrayHasKey('color', $cat);
            $this->assertArrayHasKey('icon', $cat);
        }
    }

    public function testMultipleLogsIncrementId(): void
    {
        $id1 = Changelog::log(1, 'system', 'a', 'First');
        $id2 = Changelog::log(1, 'system', 'b', 'Second');
        $id3 = Changelog::log(1, 'system', 'c', 'Third');

        $this->assertEquals($id1 + 1, $id2);
        $this->assertEquals($id2 + 1, $id3);
    }
}
