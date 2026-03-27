<?php

declare(strict_types=1);

namespace AdManager\Tests\Creative;

use AdManager\DB;
use AdManager\Creative\ReviewStore;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Creative\ReviewStore CRUD operations.
 *
 * Uses in-memory SQLite with the real schema so no file I/O occurs.
 */
class ReviewStoreTest extends TestCase
{
    private ReviewStore $store;
    private PDO $db;

    protected function setUp(): void
    {
        DB::reset();
        putenv('ADMANAGER_DB_PATH=:memory:');

        $this->db = DB::get();
        $schema = file_get_contents(dirname(__DIR__, 2) . '/db/schema.sql');
        $this->db->exec($schema);

        // Seed project
        $this->db->exec(
            "INSERT INTO projects (id, name, display_name) VALUES (1, 'test-proj', 'Test Proj')"
        );

        $this->store = new ReviewStore();
    }

    protected function tearDown(): void
    {
        DB::reset();
        putenv('ADMANAGER_DB_PATH');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Insert a minimal asset row and return its ID.
     */
    private function insertAsset(int $projectId = 1, string $type = 'image', string $status = 'draft'): int
    {
        $this->db->prepare(
            'INSERT INTO assets (project_id, type, platform, status) VALUES (?, ?, ?, ?)'
        )->execute([$projectId, $type, 'local', $status]);

        return (int) $this->db->lastInsertId();
    }

    // -------------------------------------------------------------------------
    // listByProject()
    // -------------------------------------------------------------------------

    public function testListByProjectReturnsEmptyArrayWhenNoAssets(): void
    {
        $this->assertSame([], $this->store->listByProject(1));
    }

    public function testListByProjectReturnsAllAssetsForProject(): void
    {
        $this->insertAsset(1, 'image', 'draft');
        $this->insertAsset(1, 'video', 'approved');
        $this->insertAsset(1, 'image', 'rejected');

        $results = $this->store->listByProject(1);
        $this->assertCount(3, $results);
    }

    public function testListByProjectFiltersOtherProjects(): void
    {
        $this->db->exec("INSERT INTO projects (id, name) VALUES (2, 'other-proj')");

        $this->insertAsset(1);
        $this->insertAsset(2);

        $results = $this->store->listByProject(1);
        $this->assertCount(1, $results);
        $this->assertSame(1, (int) $results[0]['project_id']);
    }

    public function testListByProjectFiltersOnStatus(): void
    {
        $this->insertAsset(1, 'image', 'draft');
        $this->insertAsset(1, 'image', 'approved');
        $this->insertAsset(1, 'image', 'approved');

        $approved = $this->store->listByProject(1, 'approved');
        $this->assertCount(2, $approved);
        foreach ($approved as $row) {
            $this->assertSame('approved', $row['status']);
        }
    }

    public function testListByProjectWithNullStatusReturnsAll(): void
    {
        $this->insertAsset(1, 'image', 'draft');
        $this->insertAsset(1, 'image', 'approved');

        $all = $this->store->listByProject(1, null);
        $this->assertCount(2, $all);
    }

    public function testListByProjectWithEmptyStringStatusReturnsAll(): void
    {
        $this->insertAsset(1, 'image', 'draft');
        $this->insertAsset(1, 'image', 'approved');

        $all = $this->store->listByProject(1, '');
        $this->assertCount(2, $all);
    }

    // -------------------------------------------------------------------------
    // getById()
    // -------------------------------------------------------------------------

    public function testGetByIdReturnsCorrectAsset(): void
    {
        $id = $this->insertAsset(1, 'video', 'draft');

        $row = $this->store->getById($id);
        $this->assertNotNull($row);
        $this->assertSame($id, (int) $row['id']);
        $this->assertSame('video', $row['type']);
    }

    public function testGetByIdReturnsNullForMissingId(): void
    {
        $this->assertNull($this->store->getById(9999));
    }

    // -------------------------------------------------------------------------
    // approve()
    // -------------------------------------------------------------------------

    public function testApproveSetStatusToApproved(): void
    {
        $id = $this->insertAsset(1, 'image', 'draft');

        $this->store->approve($id);

        $row = $this->store->getById($id);
        $this->assertSame('approved', $row['status']);
    }

    public function testApproveCanBeCalledOnAlreadyApprovedAsset(): void
    {
        $id = $this->insertAsset(1, 'image', 'approved');
        $this->store->approve($id);
        $this->assertSame('approved', $this->store->getById($id)['status']);
    }

    // -------------------------------------------------------------------------
    // reject()
    // -------------------------------------------------------------------------

    public function testRejectSetsStatusAndReason(): void
    {
        $id = $this->insertAsset(1, 'image', 'draft');

        $this->store->reject($id, 'Too much text');

        $row = $this->store->getById($id);
        $this->assertSame('rejected', $row['status']);
        $this->assertSame('Too much text', $row['rejected_reason']);
    }

    public function testRejectPreservesReasonString(): void
    {
        $id     = $this->insertAsset();
        $reason = 'Brand colour is wrong — use #FF5500 not #FF0000';

        $this->store->reject($id, $reason);
        $this->assertSame($reason, $this->store->getById($id)['rejected_reason']);
    }

    // -------------------------------------------------------------------------
    // addFeedback()
    // -------------------------------------------------------------------------

    public function testAddFeedbackSetsStatusToFeedback(): void
    {
        $id = $this->insertAsset();

        $this->store->addFeedback($id, 'Make the CTA bigger');

        $row = $this->store->getById($id);
        $this->assertSame('feedback', $row['status']);
        $this->assertSame('Make the CTA bigger', $row['feedback']);
    }

    public function testAddFeedbackOverwritesPreviousFeedback(): void
    {
        $id = $this->insertAsset();
        $this->store->addFeedback($id, 'First note');
        $this->store->addFeedback($id, 'Updated note');

        $this->assertSame('Updated note', $this->store->getById($id)['feedback']);
    }

    // -------------------------------------------------------------------------
    // markOverlaid()
    // -------------------------------------------------------------------------

    public function testMarkOverlaidSetsStatusToOverlaid(): void
    {
        $id = $this->insertAsset(1, 'image', 'approved');

        $this->store->markOverlaid($id);

        $this->assertSame('overlaid', $this->store->getById($id)['status']);
    }

    // -------------------------------------------------------------------------
    // markUploaded()
    // -------------------------------------------------------------------------

    public function testMarkUploadedSetsStatusToUploaded(): void
    {
        $id = $this->insertAsset(1, 'image', 'overlaid');

        $this->store->markUploaded($id);

        $this->assertSame('uploaded', $this->store->getById($id)['status']);
    }

    // -------------------------------------------------------------------------
    // getPendingCampaigns()
    // -------------------------------------------------------------------------

    public function testGetPendingCampaignsReturnsPausedCampaigns(): void
    {
        $this->db->prepare(
            "INSERT INTO campaigns (project_id, platform, name, type, status) VALUES (1, 'google', 'Camp A', 'search', 'paused')"
        )->execute();

        $results = $this->store->getPendingCampaigns(1);
        $this->assertCount(1, $results);
        $this->assertSame('Camp A', $results[0]['name']);
    }

    public function testGetPendingCampaignsExcludesActiveCampaigns(): void
    {
        $this->db->prepare(
            "INSERT INTO campaigns (project_id, platform, name, type, status) VALUES (1, 'google', 'Active Camp', 'search', 'active')"
        )->execute();
        $this->db->prepare(
            "INSERT INTO campaigns (project_id, platform, name, type, status) VALUES (1, 'google', 'Paused Camp', 'search', 'paused')"
        )->execute();

        $results = $this->store->getPendingCampaigns(1);
        $this->assertCount(1, $results);
        $this->assertSame('Paused Camp', $results[0]['name']);
    }

    public function testGetPendingCampaignsReturnsEmptyForNoMatch(): void
    {
        $this->assertSame([], $this->store->getPendingCampaigns(1));
    }

    // -------------------------------------------------------------------------
    // enableCampaign()
    // -------------------------------------------------------------------------

    public function testEnableCampaignSetsStatusToActive(): void
    {
        $this->db->prepare(
            "INSERT INTO campaigns (id, project_id, platform, name, type, status) VALUES (1, 1, 'google', 'My Camp', 'search', 'paused')"
        )->execute();

        $this->store->enableCampaign(1);

        $row = $this->db->query("SELECT status FROM campaigns WHERE id = 1")->fetch();
        $this->assertSame('active', $row['status']);
    }

    public function testEnableCampaignUpdatesUpdatedAt(): void
    {
        $this->db->prepare(
            "INSERT INTO campaigns (id, project_id, platform, name, type, status, updated_at)
             VALUES (1, 1, 'google', 'My Camp', 'search', 'paused', '2020-01-01 00:00:00')"
        )->execute();

        $this->store->enableCampaign(1);

        $row = $this->db->query("SELECT updated_at FROM campaigns WHERE id = 1")->fetch();
        $this->assertNotSame('2020-01-01 00:00:00', $row['updated_at']);
    }

    // -------------------------------------------------------------------------
    // listByStrategy()
    // -------------------------------------------------------------------------

    public function testListByStrategyReturnsAssetsLinkedViaFullHierarchy(): void
    {
        // Insert the full chain: strategy -> campaign -> ad_group -> ad -> ad_asset -> asset
        $this->db->exec(
            "INSERT INTO strategies (id, project_id, name, platform, campaign_type)
             VALUES (10, 1, 'Strat', 'google', 'search')"
        );
        $this->db->exec(
            "INSERT INTO campaigns (id, project_id, platform, name, type, strategy_id)
             VALUES (20, 1, 'google', 'Camp', 'search', 10)"
        );
        $this->db->exec(
            "INSERT INTO ad_groups (id, campaign_id, name) VALUES (30, 20, 'AG')"
        );
        $this->db->exec(
            "INSERT INTO ads (id, ad_group_id, type) VALUES (40, 30, 'responsive_search')"
        );

        $assetId = $this->insertAsset(1, 'image', 'approved');

        $this->db->prepare(
            "INSERT INTO ad_assets (ad_id, asset_id, role) VALUES (40, ?, 'headline')"
        )->execute([$assetId]);

        $results = $this->store->listByStrategy(10);
        $this->assertCount(1, $results);
        $this->assertSame($assetId, (int) $results[0]['id']);
    }

    public function testListByStrategyReturnsEmptyForUnlinkedStrategy(): void
    {
        $this->db->exec(
            "INSERT INTO strategies (id, project_id, name, platform, campaign_type)
             VALUES (99, 1, 'Orphan Strat', 'google', 'search')"
        );

        $this->assertSame([], $this->store->listByStrategy(99));
    }
}
