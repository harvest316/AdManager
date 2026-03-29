#!/usr/bin/env php
<?php
/**
 * Policy update detection.
 *
 * Fetches canonical Google Ads and Meta advertising policy pages, hashes content,
 * and compares to stored checksums. Alerts when policies change.
 *
 * Usage:
 *   php bin/check-policy-updates.php [--update] [--recheck]
 *
 * Options:
 *   --update   Save new checksums after human review
 *   --recheck  Re-run proofreading on all approved copy for affected platform(s)
 *
 * Exit codes:
 *   0 = no changes detected (or --update completed)
 *   1 = changes detected (human review needed)
 *   2 = error (network, file system, etc.)
 */

require_once __DIR__ . '/../vendor/autoload.php';

use AdManager\DB;
use AdManager\Copy\Store;
use AdManager\Copy\ProgrammaticCheck;
use AdManager\Copy\Proofreader;

$policiesDir = dirname(__DIR__) . '/policies';
$checksumsFile = $policiesDir . '/.checksums.json';

$policyUrls = [
    'google_content'   => 'https://support.google.com/adspolicy/answer/6008942',
    'google_editorial' => 'https://support.google.com/adspolicy/answer/6021546',
    'meta_standards'   => 'https://transparency.meta.com/policies/ad-standards',
];

// ── Arg parsing ──────────────────────────────────────────────────────────────

$doUpdate = in_array('--update', $argv);
$doRecheck = in_array('--recheck', $argv);

// ── Load existing checksums ──────────────────────────────────────────────────

$checksums = [];
if (file_exists($checksumsFile)) {
    $checksums = json_decode(file_get_contents($checksumsFile), true) ?: [];
}

// ── Fetch and compare ────────────────────────────────────────────────────────

$changes = [];
$errors = [];

foreach ($policyUrls as $key => $url) {
    echo "Checking {$key}... ";

    $content = @file_get_contents($url);
    if ($content === false) {
        echo "FETCH ERROR\n";
        $errors[] = "{$key}: failed to fetch {$url}";
        continue;
    }

    // Strip volatile elements (timestamps, session tokens, etc.)
    $content = preg_replace('/<script[^>]*>.*?<\/script>/s', '', $content);
    $content = preg_replace('/<style[^>]*>.*?<\/style>/s', '', $content);
    $content = strip_tags($content);
    $content = preg_replace('/\s+/', ' ', $content);
    $content = trim($content);

    $hash = hash('sha256', $content);
    $oldHash = $checksums[$key]['hash'] ?? null;

    if ($oldHash === null) {
        echo "NEW (first check)\n";
        $checksums[$key] = [
            'url'        => $url,
            'hash'       => $hash,
            'checked_at' => date('c'),
        ];
    } elseif ($hash !== $oldHash) {
        echo "CHANGED!\n";
        $changes[] = $key;
        if ($doUpdate) {
            $checksums[$key]['hash'] = $hash;
            $checksums[$key]['checked_at'] = date('c');
        }
    } else {
        echo "unchanged\n";
        $checksums[$key]['checked_at'] = date('c');
    }
}

// ── Save checksums ───────────────────────────────────────────────────────────

if ($doUpdate || empty(array_filter($checksums, fn($c) => isset($c['hash'])))) {
    file_put_contents($checksumsFile, json_encode($checksums, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    echo "\nChecksums saved to {$checksumsFile}\n";
}

// ── Report ───────────────────────────────────────────────────────────────────

if (!empty($errors)) {
    echo "\n=== Errors ===\n";
    foreach ($errors as $err) {
        echo "  {$err}\n";
    }
}

if (!empty($changes)) {
    echo "\n=== Policy Changes Detected ===\n";
    foreach ($changes as $key) {
        $url = $policyUrls[$key];
        echo "  {$key}: {$url}\n";
    }
    echo "\nAction required: review the changed policies and update the curated docs in {$policiesDir}/\n";

    if ($doUpdate) {
        echo "Checksums updated (--update flag).\n";
    } else {
        echo "Run with --update after reviewing to save new checksums.\n";
    }

    // ── Re-check approved copy if requested ──────────────────────────────
    if ($doRecheck) {
        echo "\n=== Re-checking approved copy ===\n";

        DB::init();
        $store = new Store();

        // Determine affected platforms
        $affectedPlatforms = [];
        foreach ($changes as $key) {
            if (str_starts_with($key, 'google')) $affectedPlatforms[] = 'google';
            if (str_starts_with($key, 'meta')) $affectedPlatforms[] = 'meta';
        }
        $affectedPlatforms = array_unique($affectedPlatforms);

        foreach ($affectedPlatforms as $platform) {
            $approvedItems = $store->getApprovedByPlatform($platform);
            if (empty($approvedItems)) {
                echo "  No approved {$platform} copy to re-check.\n";
                continue;
            }

            echo "  Re-checking " . count($approvedItems) . " approved {$platform} items...\n";

            // Run programmatic checks
            $checker = new ProgrammaticCheck();
            // Group by project for brand name lookup
            $byProject = [];
            foreach ($approvedItems as $item) {
                $byProject[$item['project_id']][] = $item;
            }

            $flagged = 0;
            foreach ($byProject as $projectId => $projectItems) {
                $db = DB::get();
                $stmt = $db->prepare('SELECT display_name, name FROM projects WHERE id = ?');
                $stmt->execute([$projectId]);
                $proj = $stmt->fetch();
                $brandName = $proj['display_name'] ?? $proj['name'] ?? '';

                $results = $checker->checkAll($projectItems, $brandName);

                foreach ($results as $id => $result) {
                    $status = ProgrammaticCheck::overallStatus($result['issues']);
                    if ($status === 'fail') {
                        $store->flag($id, json_encode($result['issues']));
                        $flagged++;
                    }
                }
            }

            echo "  Flagged: {$flagged} items\n";
        }
    }

    exit(1); // Changes detected
}

echo "\nNo policy changes detected.\n";
exit(0);
