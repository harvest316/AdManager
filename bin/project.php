#!/usr/bin/env php
<?php

require_once __DIR__ . '/../vendor/autoload.php';

use AdManager\DB;

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
    $script = 'php bin/project.php';
    echo <<<USAGE
AdManager project CLI

Usage:
  {$script} create <name> --url <url> --display "<Display Name>" [--description "..."]
  {$script} budget <name> <platform> <daily_aud>
  {$script} goals <name> --<metric> <value> [--platform <platform>]
  {$script} list
  {$script} show <name>

USAGE;
    exit(1);
}

// ── Commands ─────────────────────────────────────────────────────────────────

function cmdCreate(array $pos, array $named): void
{
    if (empty($pos[0])) {
        echo "Error: project name is required.\n";
        usage();
    }

    $name = $pos[0];
    $url = $named['url'] ?? null;
    $display = $named['display'] ?? null;
    $description = $named['description'] ?? null;

    $db = DB::get();
    $stmt = $db->prepare(
        'INSERT INTO projects (name, display_name, website_url, description) VALUES (?, ?, ?, ?)'
    );

    try {
        $stmt->execute([$name, $display, $url, $description]);
        echo "Created project: {$name}";
        if ($display) echo " ({$display})";
        if ($url) echo " - {$url}";
        echo "\n";
    } catch (\PDOException $e) {
        if (str_contains($e->getMessage(), 'UNIQUE')) {
            echo "Error: project '{$name}' already exists.\n";
            exit(1);
        }
        throw $e;
    }
}

function cmdBudget(array $pos, array $named): void
{
    if (count($pos) < 3) {
        echo "Error: budget requires <name> <platform> <daily_aud>.\n";
        usage();
    }

    [$name, $platform, $dailyAud] = $pos;
    $platform = strtolower($platform);
    $dailyAud = (float) $dailyAud;

    $db = DB::get();

    $project = $db->prepare('SELECT id FROM projects WHERE name = ?');
    $project->execute([$name]);
    $row = $project->fetch();

    if (!$row) {
        echo "Error: project '{$name}' not found.\n";
        exit(1);
    }

    $stmt = $db->prepare(
        'INSERT OR REPLACE INTO budgets (project_id, platform, daily_budget_aud, updated_at)
         VALUES (?, ?, ?, datetime(\'now\'))'
    );
    $stmt->execute([$row['id'], $platform, $dailyAud]);

    echo "Budget set: {$name} / {$platform} = \${$dailyAud}/day AUD\n";
}

function cmdGoals(array $pos, array $named): void
{
    if (empty($pos[0])) {
        echo "Error: project name is required.\n";
        usage();
    }

    $name = $pos[0];
    $platform = $named['platform'] ?? null;

    // Remove 'platform' from named so remaining keys are metric=value pairs
    unset($named['platform']);

    if (empty($named)) {
        echo "Error: at least one --<metric> <value> is required.\n";
        usage();
    }

    $db = DB::get();

    $project = $db->prepare('SELECT id FROM projects WHERE name = ?');
    $project->execute([$name]);
    $row = $project->fetch();

    if (!$row) {
        echo "Error: project '{$name}' not found.\n";
        exit(1);
    }

    $stmt = $db->prepare(
        'INSERT INTO goals (project_id, platform, metric, target_value) VALUES (?, ?, ?, ?)'
    );

    foreach ($named as $metric => $value) {
        $stmt->execute([$row['id'], $platform, $metric, (float) $value]);
        echo "Goal set: {$name} / {$metric} = {$value}";
        if ($platform) echo " ({$platform})";
        echo "\n";
    }
}

function cmdList(): void
{
    $db = DB::get();
    $projects = $db->query(
        'SELECT p.id, p.name, p.display_name, p.website_url,
                GROUP_CONCAT(b.platform || \'=$\' || b.daily_budget_aud, \', \') AS budgets
         FROM projects p
         LEFT JOIN budgets b ON b.project_id = p.id
         GROUP BY p.id
         ORDER BY p.name'
    )->fetchAll();

    if (empty($projects)) {
        echo "No projects found.\n";
        return;
    }

    // Table header
    printf("%-4s %-20s %-25s %-30s %s\n", 'ID', 'Name', 'Display', 'URL', 'Budgets');
    echo str_repeat('-', 110) . "\n";

    foreach ($projects as $p) {
        printf(
            "%-4d %-20s %-25s %-30s %s\n",
            $p['id'],
            $p['name'],
            $p['display_name'] ?? '-',
            $p['website_url'] ?? '-',
            $p['budgets'] ?? '-'
        );
    }
}

function cmdShow(array $pos): void
{
    if (empty($pos[0])) {
        echo "Error: project name is required.\n";
        usage();
    }

    $name = $pos[0];
    $db = DB::get();

    // Project
    $stmt = $db->prepare('SELECT * FROM projects WHERE name = ?');
    $stmt->execute([$name]);
    $project = $stmt->fetch();

    if (!$project) {
        echo "Error: project '{$name}' not found.\n";
        exit(1);
    }

    echo "Project: {$project['name']}";
    if ($project['display_name']) echo " ({$project['display_name']})";
    echo "\n";
    if ($project['website_url']) echo "  URL:         {$project['website_url']}\n";
    if ($project['description']) echo "  Description: {$project['description']}\n";
    echo "  Created:     {$project['created_at']}\n";
    echo "\n";

    // Budgets
    $budgets = $db->prepare('SELECT platform, daily_budget_aud FROM budgets WHERE project_id = ?');
    $budgets->execute([$project['id']]);
    $budgetRows = $budgets->fetchAll();

    echo "  Budgets:\n";
    if (empty($budgetRows)) {
        echo "    (none)\n";
    } else {
        foreach ($budgetRows as $b) {
            printf("    %-10s \$%.2f/day AUD\n", $b['platform'], $b['daily_budget_aud']);
        }
    }
    echo "\n";

    // Goals
    $goals = $db->prepare('SELECT platform, metric, target_value, current_value FROM goals WHERE project_id = ?');
    $goals->execute([$project['id']]);
    $goalRows = $goals->fetchAll();

    echo "  Goals:\n";
    if (empty($goalRows)) {
        echo "    (none)\n";
    } else {
        foreach ($goalRows as $g) {
            $plat = $g['platform'] ? " ({$g['platform']})" : '';
            $current = $g['current_value'] !== null ? " (current: {$g['current_value']})" : '';
            echo "    {$g['metric']}: {$g['target_value']}{$plat}{$current}\n";
        }
    }
    echo "\n";

    // Campaigns
    $campaigns = $db->prepare(
        'SELECT COUNT(*) AS cnt FROM campaigns WHERE project_id = ?'
    );
    $campaigns->execute([$project['id']]);
    $cnt = $campaigns->fetch()['cnt'];
    echo "  Campaigns: {$cnt}\n";

    // Assets
    $assets = $db->prepare(
        'SELECT COUNT(*) AS cnt FROM assets WHERE project_id = ?'
    );
    $assets->execute([$project['id']]);
    $cnt = $assets->fetch()['cnt'];
    echo "  Assets:    {$cnt}\n";
}

// ── Main ─────────────────────────────────────────────────────────────────────

DB::init();

[$positional, $named] = parseArgs($argv);

if (empty($positional)) {
    usage();
}

$command = array_shift($positional);

match ($command) {
    'create' => cmdCreate($positional, $named),
    'budget' => cmdBudget($positional, $named),
    'goals'  => cmdGoals($positional, $named),
    'list'   => cmdList(),
    'show'   => cmdShow($positional),
    default  => usage(),
};
