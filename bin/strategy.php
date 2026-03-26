#!/usr/bin/env php
<?php

/**
 * Strategy CLI — generate, list, show, delete advertising strategies.
 *
 * Usage:
 *   php bin/strategy.php generate colormora.com                    # Just a domain — auto-crawls
 *   php bin/strategy.php generate --project <name>                 # Use existing project
 *   php bin/strategy.php generate colormora.com --budget 30        # Override daily budget
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
  {$script} generate <domain>                       Generate strategy from just a domain (auto-crawls)
  {$script} generate --project <name>               Generate for existing project
  {$script} generate <domain> --budget <daily_aud>  Override daily budget
  {$script} list --project <name>                   List strategies for a project
  {$script} show <id>                               Show a strategy
  {$script} delete <id>                             Delete a strategy

The generate command will:
  1. Crawl the sitemap and key pages to understand the business
  2. Auto-detect pricing model, target audience, and conversion events
  3. Generate a comprehensive paid media strategy using Claude Opus

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
    $generator = new Generator();
    $context = [];

    // Pass through any named context overrides
    foreach (['account_maturity', 'pricing_model', 'primary_conversion',
              'secondary_conversions', 'target_markets'] as $key) {
        if (isset($named[$key])) {
            $context[$key] = $named[$key];
        }
    }

    // Budget override
    if (isset($named['budget'])) {
        $context['budget_override'] = $named['budget'];
    }

    // Determine: domain or --project
    if (!empty($pos[0]) && str_contains($pos[0], '.')) {
        // Domain mode
        $domain = $pos[0];
        echo "Generating strategy for {$domain}...\n";
        echo "(Crawling site, then generating with Claude Opus — may take 3-5 minutes)\n\n";

        try {
            $id = $generator->generateFromDomain($domain);
            echo "\nStrategy saved with ID: {$id}\n";
            echo "Run 'php bin/strategy.php show {$id}' to view.\n";
        } catch (\Exception $e) {
            echo "Error: {$e->getMessage()}\n";
            exit(1);
        }
    } elseif (isset($named['project'])) {
        // Project mode
        $project = resolveProject($named['project']);
        echo "Generating strategy for {$project['display_name']}...\n";
        echo "(Crawling site, then generating with Claude Opus — may take 3-5 minutes)\n\n";

        try {
            $id = $generator->generate($project['id'], $context);
            echo "\nStrategy saved with ID: {$id}\n";
            echo "Run 'php bin/strategy.php show {$id}' to view.\n";
        } catch (\Exception $e) {
            echo "Error: {$e->getMessage()}\n";
            exit(1);
        }
    } else {
        echo "Error: provide a domain or --project name.\n";
        usage();
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

    printf("%-4s %-50s %-8s %s\n", 'ID', 'Name', 'Model', 'Created');
    echo str_repeat('-', 90) . "\n";

    foreach ($strategies as $s) {
        printf(
            "%-4d %-50s %-8s %s\n",
            $s['id'],
            mb_substr($s['name'], 0, 50),
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
