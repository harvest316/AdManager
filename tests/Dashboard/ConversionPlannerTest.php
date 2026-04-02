<?php

namespace AdManager\Tests\Dashboard;

use AdManager\Dashboard\ConversionPlanner;
use AdManager\DB;
use PHPUnit\Framework\TestCase;

class ConversionPlannerTest extends TestCase
{
    private ConversionPlanner $planner;

    protected function setUp(): void
    {
        putenv('ADMANAGER_DB_PATH=:memory:');
        DB::reset();
        DB::init();

        $db = DB::get();
        $db->exec("INSERT INTO projects (id, name, display_name, website_url, description) VALUES (1, 'shop', 'My Shop', 'https://shop.com', 'An ecommerce store selling products')");
        $db->exec("INSERT INTO projects (id, name, display_name, website_url, description) VALUES (2, 'agency', 'My Agency', 'https://agency.com', 'A service agency for consultations')");
        $db->exec("INSERT INTO projects (id, name, display_name, website_url, description) VALUES (3, 'app', 'My App', 'https://myapp.io', 'A SaaS subscription app trial')");

        $this->planner = new ConversionPlanner();
    }

    protected function tearDown(): void
    {
        DB::reset();
        putenv('ADMANAGER_DB_PATH');
    }

    public function testPlanReturnsActionsForEcommerce(): void
    {
        $result = $this->planner->plan(1);

        $this->assertEquals('ecommerce', $result['business_type']);
        $this->assertNotEmpty($result['actions']);

        $eventNames = array_column($result['actions'], 'event_name');
        $this->assertContains('purchase', $eventNames);
        $this->assertContains('add_to_cart', $eventNames);
    }

    public function testPlanReturnsActionsForService(): void
    {
        $result = $this->planner->plan(2);

        $this->assertEquals('service', $result['business_type']);
        $names = array_column($result['actions'], 'name');
        $this->assertContains('Quote Request', $names);
    }

    public function testPlanInfersSaas(): void
    {
        $result = $this->planner->plan(3);

        $this->assertEquals('saas', $result['business_type']);
        $eventNames = array_column($result['actions'], 'event_name');
        $this->assertContains('sign_up', $eventNames);
    }

    public function testPlanIncludesGa4Platform(): void
    {
        $result = $this->planner->plan(1);

        $platforms = array_unique(array_column($result['actions'], 'platform'));
        $this->assertContains('ga4', $platforms);
    }

    public function testPlanSkipsExistingActions(): void
    {
        // Add an existing conversion action
        $db = DB::get();
        $db->exec("INSERT INTO conversion_actions (project_id, name, event_name, platform, category, is_primary, status) VALUES (1, 'Purchase', 'purchase', 'ga4', 'PURCHASE', 1, 'created')");

        $result = $this->planner->plan(1);

        // purchase:ga4 should be excluded
        $ga4Purchases = array_filter($result['actions'], fn($a) =>
            $a['event_name'] === 'purchase' && $a['platform'] === 'ga4'
        );
        $this->assertEmpty($ga4Purchases);
    }

    public function testPlanForNonexistentProject(): void
    {
        $result = $this->planner->plan(999);
        $this->assertEmpty($result);
    }

    public function testSavePlanInsertsToDb(): void
    {
        $result = $this->planner->plan(1);
        $ids = $this->planner->savePlan(1, $result['actions']);

        $this->assertNotEmpty($ids);
        $this->assertCount(count($result['actions']), $ids);

        $db = DB::get();
        $count = (int) $db->query("SELECT COUNT(*) FROM conversion_actions WHERE project_id = 1")->fetchColumn();
        $this->assertEquals(count($ids), $count);
    }

    public function testSavePlanSetsStatusPlanned(): void
    {
        $result = $this->planner->plan(1);
        $ids = $this->planner->savePlan(1, $result['actions']);

        $db = DB::get();
        $stmt = $db->prepare('SELECT status FROM conversion_actions WHERE id = ?');
        $stmt->execute([$ids[0]]);
        $this->assertEquals('planned', $stmt->fetchColumn());
    }

    public function testListForProject(): void
    {
        $result = $this->planner->plan(1);
        $this->planner->savePlan(1, $result['actions']);

        $list = ConversionPlanner::listForProject(1);
        $this->assertNotEmpty($list);

        // Primary actions should come first (ORDER BY is_primary DESC)
        $first = $list[0];
        $this->assertEquals(1, $first['is_primary']);
    }

    public function testProvisionMeta(): void
    {
        $actions = [
            [
                'name' => 'Test Lead',
                'event_name' => 'Lead',
                'platform' => 'meta',
                'category' => 'LEAD',
                'is_primary' => 1,
                'trigger_type' => 'url_match',
                'trigger_value' => '/thank-you',
                'default_value' => 0,
            ],
        ];
        $ids = $this->planner->savePlan(1, $actions);

        $result = $this->planner->provision($ids[0]);
        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('meta_standard:Lead', $result['external_id']);

        // Check status updated in DB
        $db = DB::get();
        $stmt = $db->prepare('SELECT status FROM conversion_actions WHERE id = ?');
        $stmt->execute([$ids[0]]);
        $this->assertEquals('created', $stmt->fetchColumn());
    }

    public function testProvisionGA4(): void
    {
        $actions = [
            [
                'name' => 'Sign Up',
                'event_name' => 'sign_up',
                'platform' => 'ga4',
                'category' => 'SIGNUP',
                'is_primary' => 1,
                'trigger_type' => 'url_match',
                'trigger_value' => '/welcome',
                'default_value' => 0,
            ],
        ];
        $ids = $this->planner->savePlan(1, $actions);

        $result = $this->planner->provision($ids[0]);
        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('sign_up', $result['external_id']);
        $this->assertStringContainsString('GTM', $result['note']);
    }

    public function testProvisionAlreadyProvisionedFails(): void
    {
        $actions = [['name' => 'Test', 'event_name' => 'test', 'platform' => 'ga4', 'category' => 'PAGE_VIEW', 'is_primary' => 0, 'default_value' => 0]];
        $ids = $this->planner->savePlan(1, $actions);

        // First provision succeeds
        $this->planner->provision($ids[0]);

        // Second provision fails (already provisioned)
        $result = $this->planner->provision($ids[0]);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('already provisioned', $result['error']);
    }

    public function testProvisionNotFound(): void
    {
        $result = $this->planner->provision(999);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('not found', $result['error']);
    }

    public function testInferBusinessTypeFromStrategy(): void
    {
        $db = DB::get();
        // Add strategy with lead gen language
        $db->exec("INSERT INTO strategies (id, project_id, name, platform, full_strategy, model) VALUES (1, 2, 'Lead Strategy', 'google', 'Drive leads through form submissions and consultation bookings.', 'opus')");

        $result = $this->planner->plan(2);
        $this->assertEquals('lead_gen', $result['business_type']);
    }

    public function testPlanIncludesGooglePlatformWhenCampaignExists(): void
    {
        $db = DB::get();
        $db->exec("INSERT INTO campaigns (id, project_id, name, platform, type, status) VALUES (1, 1, 'Search Campaign', 'google', 'search', 'active')");

        $result = $this->planner->plan(1);

        $platforms = array_unique(array_column($result['actions'], 'platform'));
        $this->assertContains('google', $platforms);
        $this->assertContains('ga4', $platforms);
    }

    public function testInferTriggerValueForPurchase(): void
    {
        $result = $this->planner->plan(1);

        $purchases = array_filter($result['actions'], fn($a) => $a['event_name'] === 'purchase');
        foreach ($purchases as $p) {
            if ($p['trigger_type'] === 'url_match') {
                $this->assertEquals('/order-confirmation', $p['trigger_value']);
            }
        }
    }
}
