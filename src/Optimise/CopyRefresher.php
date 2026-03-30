<?php

namespace AdManager\Optimise;

use AdManager\DB;
use AdManager\Copy\Generator;
use AdManager\Copy\Store as CopyStore;
use AdManager\Copy\ProgrammaticCheck;
use AdManager\Copy\Proofreader;

/**
 * Automated copy refresh cycle for ongoing campaign optimisation.
 *
 * Identifies underperforming ad copy (via Google Ads asset performance labels
 * or QA scores), generates replacements, proofreads, and queues for deployment.
 *
 * Designed to run periodically (weekly or bi-weekly) as part of the
 * optimisation cycle alongside split testing, keyword mining, and budget
 * allocation.
 */
class CopyRefresher
{
    private Generator $generator;
    private CopyStore $store;
    private ProgrammaticCheck $checker;

    public function __construct()
    {
        $this->generator = new Generator();
        $this->store = new CopyStore();
        $this->checker = new ProgrammaticCheck();
    }

    /**
     * Identify weak headlines for a campaign based on performance data.
     *
     * Uses Google Ads asset performance labels (Low/Good/Best) from the
     * performance table. Falls back to QA scores if no performance data.
     *
     * @return array{weak: array, strong: array}
     */
    public function identifyWeakCopy(int $projectId, string $campaignName): array
    {
        $db = DB::get();

        // Get all approved headlines for this campaign
        $allCopy = $this->store->getApprovedForCampaign($projectId, $campaignName, 'headline');

        if (empty($allCopy)) {
            return ['weak' => [], 'strong' => []];
        }

        // Try to get asset performance data from Google Ads
        // (populated by sync-performance.php if campaign is live)
        $perfData = $this->getAssetPerformance($projectId, $campaignName);

        if (!empty($perfData)) {
            return $this->classifyByPerformance($allCopy, $perfData);
        }

        // Fallback: classify by QA score
        return $this->classifyByQAScore($allCopy);
    }

    /**
     * Run the full refresh cycle for a campaign.
     *
     * 1. Identify weak headlines
     * 2. Generate replacements via Opus
     * 3. Proofread + auto-approve
     * 4. Return summary
     *
     * @return array{generated: int, approved: int, review: int, weak_found: int}
     */
    public function refresh(int $projectId, string $campaignName, array $options = []): array
    {
        $db = DB::get();
        $stmt = $db->prepare('SELECT * FROM projects WHERE id = ?');
        $stmt->execute([$projectId]);
        $project = $stmt->fetch();

        if (!$project) {
            return ['generated' => 0, 'approved' => 0, 'review' => 0, 'weak_found' => 0];
        }

        $brandName = $project['display_name'] ?? $project['name'];
        $market = $options['market'] ?? 'all';

        // Step 1: Identify weak headlines
        $classification = $this->identifyWeakCopy($projectId, $campaignName);
        $weak = $classification['weak'];
        $strong = $classification['strong'];

        if (empty($weak)) {
            return ['generated' => 0, 'approved' => 0, 'review' => 0, 'weak_found' => 0];
        }

        $maxReplacements = $options['max_replacements'] ?? count($weak);
        $weak = array_slice($weak, 0, $maxReplacements);

        // Step 2: Generate replacements
        $replacements = $this->generator->generateReplacements(
            $project, $weak, $strong, $campaignName, $options
        );

        if (empty($replacements)) {
            return ['generated' => 0, 'approved' => 0, 'review' => 0, 'weak_found' => count($weak)];
        }

        // Assign campaign name
        foreach ($replacements as &$r) {
            $r['campaign_name'] = $campaignName;
        }
        unset($r);

        // Step 3: Insert into DB
        $strategyId = $options['strategy_id'] ?? null;
        $ids = $this->store->bulkInsert($projectId, $strategyId, $replacements);

        // Step 4: Programmatic checks
        $items = [];
        foreach ($ids as $id) {
            $item = $this->store->getById($id);
            if ($item) $items[] = $item;
        }

        $checkResults = $this->checker->checkAll($items, $brandName, $market);

        foreach ($checkResults as $id => $result) {
            $status = ProgrammaticCheck::overallStatus($result['issues']);
            $this->store->updateQA($id, $status, $result['issues']);
            if ($status === 'fail') {
                $this->store->setStatus($id, 'draft');
            }
        }

        // Step 5: LLM proofreading
        $llmItems = array_filter($items, fn($i) =>
            ProgrammaticCheck::overallStatus($checkResults[$i['id']]['issues'] ?? []) !== 'fail'
        );

        $approved = 0;
        $review = 0;

        if (!empty($llmItems)) {
            $strategy = null;
            if (isset($options['strategy_id'])) {
                $sStmt = $db->prepare('SELECT * FROM strategies WHERE id = ?');
                $sStmt->execute([(int) $options['strategy_id']]);
                $strategy = $sStmt->fetch();
            }
            $mockStrategy = $strategy ?? ['target_audience' => '', 'value_proposition' => ''];

            $proofreader = new Proofreader();
            $llmResult = $proofreader->proofread(array_values($llmItems), $project, $mockStrategy, $market);

            if ($llmResult !== null) {
                foreach ($llmResult['items'] as $ir) {
                    $id = $ir['id'];
                    $allIssues = array_merge($checkResults[$id]['issues'] ?? [], $ir['issues'] ?? []);
                    $this->store->updateQA($id, $ir['verdict'], $allIssues, $ir['score'] ?? null);
                    $score = $ir['score'] ?? 0;
                    $hasFails = !empty(array_filter($allIssues, fn($i) => ($i['severity'] ?? '') === 'fail'));

                    if ($score >= 70 && !$hasFails) {
                        $this->store->approve($id);
                        $approved++;
                    } elseif ($score >= 50) {
                        $this->store->setStatus($id, 'proofread');
                        $review++;
                    }
                }
            } else {
                // LLM failed — leave as proofread for manual review
                foreach ($llmItems as $item) {
                    $this->store->setStatus($item['id'], 'proofread');
                    $review++;
                }
            }
        }

        // Step 6: Mark old weak headlines as rejected
        foreach ($weak as $w) {
            $this->store->reject((int) $w['id'], 'Replaced by CopyRefresher: underperforming');
        }

        $result = [
            'weak_found' => count($weak),
            'generated'  => count($replacements),
            'approved'   => $approved,
            'review'     => $review,
        ];

        // Log to changelog
        \AdManager\Dashboard\Changelog::log(
            $projectId, 'creative', 'refreshed',
            "Copy refresh '{$campaignName}': {$result['weak_found']} weak, {$result['generated']} generated, {$result['approved']} approved, {$result['review']} review",
            array_merge($result, ['campaign_name' => $campaignName]),
            null, null, 'optimiser'
        );

        return $result;
    }

    /**
     * Get asset performance labels from the database.
     *
     * Google Ads API returns asset performance labels (Low/Good/Best)
     * which are synced by sync-performance.php into a join of
     * ad_copy + performance data.
     */
    private function getAssetPerformance(int $projectId, string $campaignName): array
    {
        $db = DB::get();

        // Check if we have any performance data for this campaign's ads
        $stmt = $db->prepare(
            "SELECT p.ad_id, SUM(p.impressions) AS impressions, SUM(p.clicks) AS clicks
             FROM performance p
             JOIN ads a ON a.id = p.ad_id
             JOIN ad_groups ag ON ag.id = a.ad_group_id
             JOIN campaigns c ON c.id = ag.campaign_id
             WHERE c.project_id = ? AND c.name = ?
             GROUP BY p.ad_id
             HAVING SUM(p.impressions) > 0"
        );
        $stmt->execute([$projectId, $campaignName]);
        $rows = $stmt->fetchAll();

        $data = [];
        foreach ($rows as $row) {
            $impressions = (int) $row['impressions'];
            $clicks = (int) $row['clicks'];
            $ctr = $impressions > 0 ? ($clicks / $impressions) * 100 : 0;

            $data[$row['ad_id']] = [
                'impressions' => $impressions,
                'clicks'      => $clicks,
                'ctr'         => round($ctr, 2),
            ];
        }

        return $data;
    }

    /**
     * Classify headlines by performance data (CTR-based).
     *
     * Bottom 30% by CTR = weak, top 30% = strong.
     */
    private function classifyByPerformance(array $allCopy, array $perfData): array
    {
        // For now, since we don't have per-headline performance (Google reports
        // at the ad level, not per-headline), classify by QA score as fallback.
        // When asset-level reporting is available, this will use CTR per headline.
        return $this->classifyByQAScore($allCopy);
    }

    /**
     * Classify headlines by QA score.
     *
     * Score < 70 = weak, >= 70 = strong.
     */
    private function classifyByQAScore(array $allCopy): array
    {
        $weak = [];
        $strong = [];

        foreach ($allCopy as $item) {
            $score = $item['qa_score'] ?? null;
            if ($score !== null && $score < 70) {
                $item['label'] = 'Low';
                $item['ctr'] = 'unknown';
                $weak[] = $item;
            } else {
                $item['label'] = $score >= 85 ? 'Best' : 'Good';
                $item['ctr'] = 'unknown';
                $strong[] = $item;
            }
        }

        return ['weak' => $weak, 'strong' => $strong];
    }

    /**
     * Run refresh across all campaigns for a project.
     *
     * @return array Campaign name → refresh result
     */
    public function refreshAll(int $projectId, array $options = []): array
    {
        $db = DB::get();
        $stmt = $db->prepare(
            "SELECT DISTINCT campaign_name FROM ad_copy
             WHERE project_id = ? AND campaign_name IS NOT NULL AND status = 'approved'"
        );
        $stmt->execute([$projectId]);
        $campaigns = array_column($stmt->fetchAll(), 'campaign_name');

        $results = [];
        foreach ($campaigns as $campaignName) {
            $results[$campaignName] = $this->refresh($projectId, $campaignName, $options);
        }

        return $results;
    }
}
