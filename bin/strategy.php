#!/usr/bin/env php
<?php

/**
 * Strategy CLI — generate, list, show, delete advertising strategies.
 *
 * Usage:
 *   php bin/strategy.php generate --project <name> --platform google|meta --type search|display|video|pmax [--context "additional context"]
 *   php bin/strategy.php list --project <name>
 *   php bin/strategy.php show <id>
 *   php bin/strategy.php delete <id>
 */

require_once __DIR__ . '/../vendor/autoload.php';

use AdManager\DB;
use AdManager\Strategy\Generator;
use AdManager\Strategy\Store;

// ── Arg parsing ──────────────────────────────────────────────────────────────

function parseArgs(array $argv): array
{
    $positional = [];
    $named = [];

    $i = 1; // skip script name
    while ($i < count($argv)) {
        $arg = $argv[$i];

        if (str_starts_with($arg, '--')) {
            if (str_contains($arg, '=')) {
                [$key, $val] = explode('=', substr($arg, 2), 2);
                $named[$key] = $val;
            } elseif (isset($argv[$i + 1]) && !str_starts_with($argv[$i + 1], '--')) {
                $named[substr($arg, 2)] = $argv[$i + 1];
                $i++;
            } else {
                $named[substr($arg, 2)] = true;
            }
        } else {
            $positional[] = $arg;
        }
        $i++;
    }

    return [$positional, $named];
}

function usage(): void
{
    $script = 'php bin/strategy.php';
    echo <<<USAGE
AdManager Strategy CLI

Usage:
  {$script} generate --project <name> --platform google|meta --type search|display|video|pmax [--context "additional context"]
  {$script} list --project <name>
  {$script} show <id>
  {$script} delete <id>

Platforms: google, meta
Campaign types: search, display, video, pmax, shopping, feed, stories, reels

USAGE;
    exit(1);
}

function resolveProject(string $name): array
{
    $db = DB::get();
    $stmt = $db->prepare('SELECT * FROM projects WHERE name = ?');
    $stmt->execute([$name]);
    $project = $stmt->fetch();

    if (!$project) {
        echo "Error: project '{$name}' not found.\n";
        exit(1);
    }

    return $project;
}

// ── Commands ─────────────────────────────────────────────────────────────────

function cmdGenerate(array $pos, array $named): void
{
    $projectName = $named['project'] ?? null;
    $platform = $named['platform'] ?? null;
    $type = $named['type'] ?? null;
    $contextStr = $named['context'] ?? null;

    if (!$projectName || !$platform || !$type) {
        echo "Error: --project, --platform, and --type are all required.\n";
        usage();
    }

    $validPlatforms = ['google', 'meta'];
    if (!in_array($platform, $validPlatforms)) {
        echo "Error: platform must be one of: " . implode(', ', $validPlatforms) . "\n";
        exit(1);
    }

    $validTypes = ['search', 'display', 'video', 'pmax', 'shopping', 'feed', 'stories', 'reels'];
    if (!in_array($type, $validTypes)) {
        echo "Error: type must be one of: " . implode(', ', $validTypes) . "\n";
        exit(1);
    }

    $project = resolveProject($projectName);
    $context = $contextStr ? [$contextStr] : [];

    echo "Generating strategy for {$project['display_name']} — {$platform} {$type}...\n";
    echo "(This may take up to 2 minutes)\n\n";

    try {
        $generator = new Generator();
        $id = $generator->generate($project['id'], $platform, $type, $context);
        echo "Strategy saved with ID: {$id}\n";
        echo "Run 'php bin/strategy.php show {$id}' to view.\n";
    } catch (\Exception $e) {
        echo "Error: {$e->getMessage()}\n";
        exit(1);
    }
}

function cmdList(array $pos, array $named): void
{
    $projectName = $named['project'] ?? null;
    if (!$projectName) {
        echo "Error: --project is required.\n";
        usage();
    }

    $project = resolveProject($projectName);
    $store = new Store();
    $strategies = $store->listByProject($project['id']);

    if (empty($strategies)) {
        echo "No strategies found for project '{$projectName}'.\n";
        return;
    }

    printf("%-4s %-40s %-10s %-12s %-8s %s\n", 'ID', 'Name', 'Platform', 'Type', 'Model', 'Created');
    echo str_repeat('-', 100) . "\n";

    foreach ($strategies as $s) {
        printf(
            "%-4d %-40s %-10s %-12s %-8s %s\n",
            $s['id'],
            mb_substr($s['name'], 0, 40),
            $s['platform'] ?? '-',
            $s['campaign_type'] ?? '-',
            $s['model'] ?? '-',
            $s['created_at']
        );
    }

    echo "\nTotal: " . count($strategies) . " strategies\n";
}

function cmdShow(array $pos): void
{
    $id = (int) ($pos[0] ?? 0);
    if ($id <= 0) {
        echo "Error: strategy ID is required.\n";
        usage();
    }

    $store = new Store();
    $strategy = $store->get($id);

    if (!$strategy) {
        echo "Error: strategy #{$id} not found.\n";
        exit(1);
    }

    echo "Strategy #{$strategy['id']}: {$strategy['name']}\n";
    echo "Platform: {$strategy['platform']} | Type: {$strategy['campaign_type']} | Model: {$strategy['model']}\n";
    echo "Created: {$strategy['created_at']}\n";
    echo str_repeat('=', 80) . "\n\n";
    echo $strategy['full_strategy'] . "\n";
}

function cmdDelete(array $pos): void
{
    $id = (int) ($pos[0] ?? 0);
    if ($id <= 0) {
        echo "Error: strategy ID is required.\n";
        usage();
    }

    $store = new Store();
    $strategy = $store->get($id);

    if (!$strategy) {
        echo "Error: strategy #{$id} not found.\n";
        exit(1);
    }

    $store->delete($id);
    echo "Deleted strategy #{$id}: {$strategy['name']}\n";
}

// ── Main ─────────────────────────────────────────────────────────────────────

DB::init();

[$positional, $named] = parseArgs($argv);

if (empty($positional)) {
    usage();
}

$command = array_shift($positional);

match ($command) {
    'generate' => cmdGenerate($positional, $named),
    'list'     => cmdList($positional, $named),
    'show'     => cmdShow($positional),
    'delete'   => cmdDelete($positional),
    default    => usage(),
};
