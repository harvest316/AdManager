# AdManager Dashboard Architecture

Architecture design for evolving the review page into a full ad management dashboard.

## 1. Where Does the Dashboard Live?

### Decision: Option C -- Build in AdManager with clean API layer, deploy subset to Hostinger later

**Options considered:**

| Option | Pros | Cons |
|--------|------|------|
| A: Extend review/ directly | Simple, fast to start | Monolith grows, no deployment path to Hostinger |
| B: Separate dashboard app | Clean separation | Duplication, two codebases to maintain, overkill for 1-2 users |
| **C: Clean API layer in AdManager, deploy subset later** | Single codebase, works locally today, Hostinger-deployable later | Requires discipline to keep API separate from direct DB calls |

**Rationale:** The review page already runs on PHP's built-in server. The API layer we build now uses the same DB class and Composer autoloader. When we eventually deploy to Hostinger, we FTP the `review/` directory plus a `vendor/` subset -- identical to how the main site deploys today via `scripts/deploy-website.sh`. No architectural gymnastics needed.

**What changes for Hostinger deployment:**
- `DB.php` already supports `ADMANAGER_DB_PATH` env var -- Hostinger would point to a synced or uploaded SQLite file
- Auth (see section 6) works identically on both environments
- The `exec()` sync trigger (see section 5) would be disabled on Hostinger -- replaced by a "last synced" indicator reading from the DB

**Trade-off:** We accept that the Hostinger deployment is read-heavy (view performance, review decisions) and cannot trigger syncs or run optimisation. That is fine -- sync and optimisation are always triggered from the local/VPS environment where API credentials live.

## 2. Breaking Up the Monolith

### Current state: review/index.php is 426 lines (not 3,800)

The file is actually manageable but mixes PHP data loading, HTML templating, CSS, and JavaScript in a single file. The real problem is not size but *adding new views* -- every new tab requires editing the same file, and the CSS/JS is already 200+ lines of inline code.

### Strategy: PHP include-based views with shared layout

```
review/
  index.php              -- Router + shared layout (header, nav, footer, CSS, JS)
  api.php                -- All AJAX endpoints (expand, not replace)
  assets.php             -- Static file proxy (keep as-is)
  views/
    overview.php         -- NEW: project overview with aggregated metrics
    creative.php         -- Extracted from index.php (creative tab)
    copy.php             -- Extracted from index.php (ad copy tab)
    campaigns.php        -- Extracted from index.php (campaigns tab)
    performance.php      -- NEW: performance charts and drilldowns
    changelog.php        -- NEW: decision/change history
    strategies.php       -- NEW: strategy review with section annotations
    settings.php         -- NEW: project settings, budget, goals
```

**How `index.php` works after refactor:**

```php
<?php
// index.php -- router + layout shell
require_once __DIR__ . '/../vendor/autoload.php';
use AdManager\DB;
use AdManager\Dashboard\Auth;

DB::init();
Auth::require(); // Session-based auth (see section 6)

$projectId = isset($_GET['project']) ? (int)$_GET['project'] : null;
$view = $_GET['view'] ?? 'overview';

$allowedViews = ['overview','creative','copy','campaigns','performance','changelog','strategies','settings'];
if (!in_array($view, $allowedViews)) $view = 'overview';

$projects = DB::get()->query('SELECT * FROM projects ORDER BY name')->fetchAll();
if (!$projectId && count($projects) > 0) $projectId = (int)$projects[0]['id'];
?>
<!DOCTYPE html>
<html lang="en">
<head><!-- shared CSS --></head>
<body>
  <!-- shared header with project selector -->
  <!-- shared nav tabs -->
  <?php include __DIR__ . "/views/{$view}.php"; ?>
  <!-- shared modals, toast container -->
  <!-- shared JS -->
</body>
</html>
```

**Why not a front-end framework:** The constraint is "no npm build step, vanilla JS." PHP includes give us view decomposition without any build tooling. Each view file receives `$projectId` and `$projects` from the parent scope and handles its own data loading and HTML. This is the simplest approach that lets multiple developers add views independently.

**Migration path:** Extract one tab at a time. Start with campaigns (least complex), then copy, then creative. Each extraction is a single commit, testable immediately. The overview and performance views are new code, not extracted.

## 3. API Endpoint Design

All endpoints live in `review/api.php`. They accept POST with `action` parameter (existing pattern) and return JSON. The API computes derived metrics server-side -- callers never see `cost_micros`.

### Existing endpoints (keep as-is)
```
POST action=approve              -- approve asset
POST action=reject               -- reject asset (with reason)
POST action=feedback             -- add feedback to asset
POST action=enable_campaign      -- enable campaign
POST action=approve_copy         -- approve ad copy
POST action=reject_copy          -- reject ad copy
POST action=feedback_copy        -- add feedback to ad copy
POST action=unapprove_copy       -- revert copy to draft
POST action=run_qa               -- run CV quality check
POST action=update_campaign_budget
POST action=update_platform_budget
POST action=update_total_budget
```

### New endpoints

**Performance API -- aggregated, decision-ready metrics:**
```
POST action=performance_summary
  project_id, date_from, date_to
  Returns: {
    totals: { cost: 4.50, clicks: 23, impressions: 1000, ctr: 2.3,
              conversions: 1.5, cpa: 3.00, roas: 3.1, conversion_value: 13.95 },
    by_campaign: [ { id, name, platform, type, status, cost, clicks, impressions,
                     ctr, conversions, cpa, roas, daily_budget, budget_utilisation } ],
    by_date: [ { date, cost, clicks, impressions, ctr, conversions } ],
    goals: [ { metric, target, actual, status, pct_off } ],
    alerts: [ "CTR below 1%..." ]
  }

POST action=performance_drilldown
  project_id, campaign_id, date_from, date_to, level (ad_group|ad)
  Returns: { rows: [ { id, name, cost, clicks, impressions, ctr, conversions, cpa } ] }
```

**Change log:**
```
POST action=changelog_list
  project_id, limit (default 50), offset (default 0), category (optional filter)
  Returns: { entries: [ { id, category, action, summary, detail_json, created_at } ] }

POST action=changelog_add
  project_id, category, action, summary, detail_json
  Returns: { id }
```

**Strategy feedback:**
```
POST action=strategy_list
  project_id
  Returns: { strategies: [ { id, name, platform, campaign_type, model, created_at } ] }

POST action=strategy_detail
  strategy_id
  Returns: { id, name, platform, campaign_type, full_strategy, annotations: [...] }

POST action=strategy_annotate
  strategy_id, section_anchor, comment
  Returns: { annotation_id }

POST action=strategy_annotation_resolve
  annotation_id
  Returns: { ok }
```

**Project CRUD:**
```
POST action=project_create
  name, display_name, website_url, description
  Returns: { id }

POST action=project_update
  project_id, display_name, website_url, description
  Returns: { ok }

POST action=project_goals
  project_id, goals: [ { platform, metric, target_value } ]
  Returns: { ok }
```

**Sync trigger:**
```
POST action=sync_status
  project_id
  Returns: { last_sync_at, is_running, last_result }

POST action=sync_trigger
  project_id, platform (google|meta|all), days (default 7)
  Returns: { job_id, status: "started" }

POST action=sync_poll
  job_id
  Returns: { status: "running"|"complete"|"failed", output, duration_seconds }
```

**Metric computation helper (server-side, shared by all endpoints):**

```php
// src/Dashboard/Metrics.php
class Metrics {
    public static function compute(array $rawRow): array {
        $cost = ($rawRow['cost_micros'] ?? 0) / 1_000_000;
        $impressions = (int)($rawRow['impressions'] ?? 0);
        $clicks = (int)($rawRow['clicks'] ?? 0);
        $conversions = (float)($rawRow['conversions'] ?? 0);
        $conversionValue = (float)($rawRow['conversion_value'] ?? 0);

        return [
            'impressions'      => $impressions,
            'clicks'           => $clicks,
            'cost'             => round($cost, 2),
            'ctr'              => $impressions > 0 ? round(($clicks / $impressions) * 100, 2) : 0,
            'conversions'      => round($conversions, 2),
            'conversion_rate'  => $clicks > 0 ? round(($conversions / $clicks) * 100, 2) : 0,
            'cpa'              => $conversions > 0 ? round($cost / $conversions, 2) : null,
            'roas'             => $cost > 0 ? round($conversionValue / $cost, 2) : null,
            'conversion_value' => round($conversionValue, 2),
        ];
    }
}
```

This is the "decision-ready abstraction" requirement: callers never see `cost_micros`, never divide by 1,000,000, never compute CTR. The same `compute()` is used by the performance summary, drilldown, and the overview widgets.

## 4. Database Schema Changes

### New table: `changelog`

Captures every optimisation decision, system event, and manual action with enough context to be self-explanatory months later.

```sql
CREATE TABLE IF NOT EXISTS changelog (
  id INTEGER PRIMARY KEY,
  project_id INTEGER NOT NULL REFERENCES projects(id),
  category TEXT NOT NULL,      -- 'split_test' | 'budget' | 'creative' | 'keyword'
                               -- | 'campaign' | 'strategy' | 'system' | 'manual'
  action TEXT NOT NULL,         -- 'concluded' | 'reallocated' | 'fatigue_detected'
                               -- | 'added' | 'removed' | 'paused' | 'enabled'
                               -- | 'approved' | 'rejected' | 'synced'
  summary TEXT NOT NULL,        -- Human-readable: "Split test 'Hero A vs B' concluded:
                               --   variant A won (CTR 3.2% vs 1.8%, p=0.02)"
  detail_json TEXT,             -- Full structured data for programmatic consumption
                               -- e.g. {"test_id":5,"winner_ad_id":12,"metric":"ctr",
                               --   "variants":[{"ad_id":12,"value":3.2},{"ad_id":13,"value":1.8}],
                               --   "p_value":0.02,"confidence":0.98}
  entity_type TEXT,             -- 'campaign' | 'ad_group' | 'ad' | 'keyword' | 'split_test' | 'strategy'
  entity_id INTEGER,            -- FK to the relevant entity
  actor TEXT DEFAULT 'system',  -- 'system' | 'admin' | 'optimiser' | 'proofreader'
  created_at TEXT DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_changelog_project ON changelog(project_id);
CREATE INDEX IF NOT EXISTS idx_changelog_category ON changelog(category);
CREATE INDEX IF NOT EXISTS idx_changelog_entity ON changelog(entity_type, entity_id);
CREATE INDEX IF NOT EXISTS idx_changelog_created ON changelog(created_at);
```

**What gets logged (and where the log call goes):**

| Event | category | action | Logged by |
|-------|----------|--------|-----------|
| Split test concluded | split_test | concluded | `SplitTest::evaluate()` |
| Budget reallocated | budget | reallocated | `api.php` budget endpoints |
| Creative fatigue detected | creative | fatigue_detected | `CreativeFatigue::detect()` |
| Keyword added/removed | keyword | added/removed | `KeywordMiner::apply()` |
| Campaign paused/enabled | campaign | paused/enabled | `api.php` + `manage.php` |
| Strategy approved | strategy | approved | `api.php` strategy endpoints |
| Ad copy approved/rejected | creative | approved/rejected | `api.php` copy endpoints |
| Performance synced | system | synced | `sync-performance.php` |
| Manual notes | manual | note | `api.php` changelog_add |

**Design choice:** `summary` is always a complete English sentence. `detail_json` is the machine-readable version of the same event. This means the changelog view works as a readable timeline without parsing JSON, but programmatic analysis (e.g. "how many budget changes this month") can query the structured data.

### New table: `strategy_annotations`

Section-level comments on strategy text without parsing markdown.

```sql
CREATE TABLE IF NOT EXISTS strategy_annotations (
  id INTEGER PRIMARY KEY,
  strategy_id INTEGER NOT NULL REFERENCES strategies(id),
  section_anchor TEXT NOT NULL,  -- Stable reference: first 60 chars of section header
                                -- e.g. "## Campaign Structure" or "### Audience Targeting"
                                -- NOT line numbers (which shift on edit)
  comment TEXT NOT NULL,
  status TEXT DEFAULT 'open',    -- 'open' | 'resolved' | 'wont_fix'
  created_at TEXT DEFAULT (datetime('now')),
  resolved_at TEXT
);

CREATE INDEX IF NOT EXISTS idx_annotations_strategy ON strategy_annotations(strategy_id);
```

**Why `section_anchor` instead of line numbers or character offsets:**
- Strategies are regenerated (new row in `strategies` table), so line numbers are meaningless across versions
- The strategy generator already uses `##` and `###` markdown headers consistently (confirmed in `Strategy/Generator.php`)
- The anchor is the first 60 chars of the nearest preceding header. The frontend finds it by scanning the markdown text and highlighting the section
- If the strategy is regenerated, old annotations remain attached to the old strategy ID. No orphaning

**Alternative rejected:** Storing a JSON path or using a rich-text editor. Both add complexity for a feature that is fundamentally "leave a comment on a section of text."

### New table: `sync_jobs`

Tracks async sync executions triggered from the web UI.

```sql
CREATE TABLE IF NOT EXISTS sync_jobs (
  id INTEGER PRIMARY KEY,
  project_id INTEGER NOT NULL REFERENCES projects(id),
  platform TEXT NOT NULL,        -- 'google' | 'meta' | 'all'
  days INTEGER DEFAULT 7,
  status TEXT DEFAULT 'pending', -- 'pending' | 'running' | 'complete' | 'failed'
  output TEXT,                   -- Captured stdout/stderr
  started_at TEXT,
  completed_at TEXT,
  created_at TEXT DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_sync_jobs_project ON sync_jobs(project_id);
CREATE INDEX IF NOT EXISTS idx_sync_jobs_status ON sync_jobs(status);
```

### Column addition: `projects.products`

Support multi-product domains (same website_url, different projects).

```sql
ALTER TABLE projects ADD COLUMN products TEXT;
-- JSON array: ["coloring books", "wall art", "digital downloads"]
-- NULL = single-product project
```

## 5. Sync Triggering Mechanism

### Decision: Background process via `proc_open()` with polling

**Options considered:**

| Option | Pros | Cons |
|--------|------|------|
| `exec()` blocking | Simple | 5-30s request timeout, browser hangs |
| `proc_open()` background + file poll | No timeout, works on all PHP | PID management, output capture |
| Job queue (Redis/Beanstalkd) | Clean, scalable | Adds dependency, overkill for 1-2 users |
| Cron-based | No web trigger complexity | Not on-demand, user must wait |

**Implementation:**

```php
// src/Dashboard/SyncRunner.php
class SyncRunner {
    private string $jobDir;

    public function __construct() {
        $this->jobDir = dirname(__DIR__, 2) . '/tmp/sync-jobs';
        if (!is_dir($this->jobDir)) mkdir($this->jobDir, 0755, true);
    }

    public function start(int $projectId, string $platform, int $days): int {
        $db = DB::get();

        // Prevent concurrent syncs for same project
        $running = $db->prepare(
            "SELECT id FROM sync_jobs WHERE project_id = ? AND status IN ('pending','running')"
        );
        $running->execute([$projectId]);
        if ($running->fetch()) {
            throw new \RuntimeException('Sync already in progress for this project.');
        }

        // Create job record
        $stmt = $db->prepare(
            "INSERT INTO sync_jobs (project_id, platform, days, status, started_at)
             VALUES (?, ?, ?, 'running', datetime('now'))"
        );
        $stmt->execute([$projectId, $platform, $days]);
        $jobId = (int)$db->lastInsertId();

        // Resolve project name
        $proj = $db->prepare('SELECT name FROM projects WHERE id = ?');
        $proj->execute([$projectId]);
        $projectName = $proj->fetchColumn();

        // Launch background process
        $binPath = dirname(__DIR__, 2) . '/bin/sync-performance.php';
        $outFile = "{$this->jobDir}/{$jobId}.log";
        $pidFile = "{$this->jobDir}/{$jobId}.pid";

        $cmd = sprintf(
            'php %s --project %s --platform %s --days %d > %s 2>&1 & echo $!',
            escapeshellarg($binPath),
            escapeshellarg($projectName),
            escapeshellarg($platform),
            $days,
            escapeshellarg($outFile)
        );

        $pid = trim(shell_exec($cmd));
        file_put_contents($pidFile, $pid);

        return $jobId;
    }

    public function poll(int $jobId): array {
        $db = DB::get();
        $stmt = $db->prepare('SELECT * FROM sync_jobs WHERE id = ?');
        $stmt->execute([$jobId]);
        $job = $stmt->fetch();

        if (!$job) throw new \RuntimeException('Job not found.');
        if ($job['status'] === 'complete' || $job['status'] === 'failed') {
            return ['status' => $job['status'], 'output' => $job['output']];
        }

        // Check if process is still running
        $pidFile = "{$this->jobDir}/{$jobId}.pid";
        $outFile = "{$this->jobDir}/{$jobId}.log";
        $pid = file_exists($pidFile) ? trim(file_get_contents($pidFile)) : null;

        $isRunning = $pid && file_exists("/proc/{$pid}");
        $output = file_exists($outFile) ? file_get_contents($outFile) : '';

        if (!$isRunning) {
            $status = str_contains($output, 'Error:') ? 'failed' : 'complete';
            $db->prepare(
                "UPDATE sync_jobs SET status = ?, output = ?, completed_at = datetime('now') WHERE id = ?"
            )->execute([$status, $output, $jobId]);

            // Log to changelog
            $db->prepare(
                "INSERT INTO changelog (project_id, category, action, summary, detail_json, actor)
                 VALUES (?, 'system', 'synced', ?, ?, 'system')"
            )->execute([
                $job['project_id'],
                "Performance sync completed ({$job['platform']}, {$job['days']} days)",
                json_encode(['job_id' => $jobId, 'platform' => $job['platform'],
                             'days' => $job['days'], 'status' => $status])
            ]);

            // Clean up temp files
            @unlink($pidFile);
            // Keep log for debugging, auto-clean after 7 days via cron

            return ['status' => $status, 'output' => $output];
        }

        return ['status' => 'running', 'output' => $output];
    }
}
```

**Frontend polling pattern:**

```javascript
function triggerSync(projectId, platform) {
    act('sync_trigger', { project_id: projectId, platform: platform, days: 7 })
    .then(function(j) {
        if (!j.ok) { toast(j.error, 'e'); return; }
        var jobId = j.job_id;
        var interval = setInterval(function() {
            act('sync_poll', { job_id: jobId }).then(function(p) {
                if (p.status === 'complete') { clearInterval(interval); toast('Sync complete', 's'); location.reload(); }
                else if (p.status === 'failed') { clearInterval(interval); toast('Sync failed', 'e'); }
                // else still running, keep polling
            });
        }, 2000); // Poll every 2 seconds
    });
}
```

**Hostinger note:** On shared hosting, `shell_exec()` may be disabled. The sync trigger endpoint returns `{"ok": false, "error": "Sync not available on this host"}` when `shell_exec` is not callable. The last-synced timestamp from the `sync_jobs` table still displays correctly.

## 6. Authentication

### Decision: Session-based auth with bcrypt password, no framework

**Options considered:**

| Option | Pros | Cons |
|--------|------|------|
| HTTP Basic Auth | Zero code | No logout, browser caches credentials, ugly |
| Session + bcrypt | Standard, logout works, rate-limitable | 30 lines of code |
| OAuth/SSO | Enterprise-grade | Absurd for 1-2 admin users |
| `.htpasswd` via Apache/nginx | Zero PHP code | Does not work with PHP built-in server |

**Implementation:**

```php
// src/Dashboard/Auth.php
class Auth {
    private const SESSION_LIFETIME = 86400 * 7; // 7 days

    public static function require(): void {
        session_start();
        if (!empty($_SESSION['admin_authenticated'])
            && (time() - ($_SESSION['admin_auth_time'] ?? 0)) < self::SESSION_LIFETIME) {
            return; // Already authenticated
        }
        // Check if this is a login submission
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
            self::handleLogin();
            return;
        }
        // Show login form
        self::renderLoginForm();
        exit;
    }

    private static function handleLogin(): void {
        $password = $_POST['password'] ?? '';
        $hash = getenv('ADMANAGER_ADMIN_HASH');
        if (!$hash) {
            // Fallback: check .env for ADMIN_PASSWORD_HASH
            $envFile = dirname(__DIR__, 2) . '/.env';
            if (file_exists($envFile)) {
                $env = parse_ini_file($envFile);
                $hash = $env['ADMIN_PASSWORD_HASH'] ?? '';
            }
        }
        if ($hash && password_verify($password, $hash)) {
            $_SESSION['admin_authenticated'] = true;
            $_SESSION['admin_auth_time'] = time();
            header('Location: ' . $_SERVER['REQUEST_URI']);
            exit;
        }
        self::renderLoginForm('Invalid password.');
        exit;
    }

    // ... renderLoginForm() outputs a minimal login page
}
```

**Setup:** `php -r "echo password_hash('your-password', PASSWORD_BCRYPT);"` and add result to `.env` as `ADMIN_PASSWORD_HASH=`. No username field -- there is one admin.

**Security properties:**
- bcrypt with default cost (10) -- 100ms verify time, resistant to brute force
- Session cookie is HTTP-only by default in PHP
- 7-day session lifetime, re-authenticate after
- No rate limiting needed at 1-2 users (add if exposed to internet)

**For Hostinger deployment**, add HTTPS enforcement:

```php
if (!empty($_SERVER['HTTPS']) || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
    ini_set('session.cookie_secure', '1');
}
```

## 7. File and Module Structure

### After refactor

```
AdManager/
  src/
    Dashboard/
      Auth.php              -- Session-based authentication
      Metrics.php           -- cost_micros -> decision-ready metric computation
      SyncRunner.php        -- Background sync job management
      Changelog.php         -- Changelog read/write operations
      PerformanceQuery.php  -- Aggregated performance queries with date ranges
    Creative/               -- (existing, unchanged)
    Copy/                   -- (existing, unchanged)
    Google/                 -- (existing, unchanged)
    Meta/                   -- (existing, unchanged)
    Optimise/               -- (existing, unchanged)
    Strategy/               -- (existing, unchanged)
    DB.php                  -- (existing, unchanged)

  review/
    index.php               -- Router + layout shell (refactored from monolith)
    api.php                 -- All AJAX endpoints (expanded)
    assets.php              -- Static file proxy (unchanged)
    views/
      overview.php          -- Project dashboard: KPI cards, sparklines, alerts
      creative.php          -- Creative asset review (extracted from index.php)
      copy.php              -- Ad copy review (extracted from index.php)
      campaigns.php         -- Campaign management + budget editing (extracted)
      performance.php       -- Performance charts, drilldowns, date range picker
      changelog.php         -- Timeline of all changes and decisions
      strategies.php        -- Strategy text with section annotations
      settings.php          -- Project CRUD, goals, budget management

  db/
    schema.sql              -- Updated with new tables
    migrations/
      001-changelog.sql     -- changelog table
      002-strategy-annotations.sql
      003-sync-jobs.sql
      004-projects-products.sql

  tmp/
    sync-jobs/              -- Temp files for background sync processes
```

### Namespace mapping (PSR-4, already configured)

```json
{
    "autoload": {
        "psr-4": {
            "AdManager\\": "src/"
        }
    }
}
```

New classes: `AdManager\Dashboard\Auth`, `AdManager\Dashboard\Metrics`, `AdManager\Dashboard\SyncRunner`, `AdManager\Dashboard\Changelog`, `AdManager\Dashboard\PerformanceQuery`.

## 8. Overview View Design

The overview is the new default landing page. It shows decision-ready information at a glance.

```
+--------------------------------------------------+
| AdManager  -- Overview    [Project: Colormora v]  |
|--------------------------------------------------|
| Overview | Creative | Copy | Campaigns | Perf    |
| Changelog | Strategies | Settings                |
+--------------------------------------------------+

+----------+  +----------+  +----------+  +--------+
| Spend    |  | CTR      |  | ROAS     |  | CPA    |
| $45.20   |  | 2.3%     |  | 3.1x     |  | $3.00  |
| 7d total |  | +0.4 vs  |  | -0.2 vs  |  | target |
|          |  | last 7d  |  | last 7d  |  | $4.00  |
+----------+  +----------+  +----------+  +--------+

+--- Spend by date (7d sparkline) ----+
|  ...*....                           |
|  ..*.*...                           |
|  .*...*..                           |
+-------------------------------------+

+--- Recent changes ----+  +--- Alerts -----+
| Budget reallocated... |  | CTR below 1%   |
| Split test concluded  |  | Zero clicks on |
| Copy approved (batch) |  | CM-PMax        |
+-----------------------+  +-----------------+

+--- Sync status --------+
| Last sync: 2h ago      |
| [Sync Now]             |
+-------------------------+
```

## 9. Implementation Sequence

### Phase 1: Foundation (estimated 2-3 hours)
1. Create `db/migrations/` with the four new tables
2. Create `src/Dashboard/Metrics.php` -- the metric computation kernel
3. Create `src/Dashboard/Auth.php` -- session auth
4. Create `src/Dashboard/Changelog.php` -- read/write for changelog table
5. Add `ADMIN_PASSWORD_HASH` to `.env.example`

### Phase 2: API expansion (estimated 2-3 hours)
1. Add performance endpoints to `api.php` (using `PerformanceQuery.php`)
2. Add changelog endpoints
3. Add strategy annotation endpoints
4. Add project CRUD endpoints
5. Add sync trigger/poll endpoints (using `SyncRunner.php`)

### Phase 3: Monolith breakup (estimated 2-3 hours)
1. Extract shared CSS/JS into `index.php` layout shell
2. Extract campaigns tab into `views/campaigns.php`
3. Extract copy tab into `views/copy.php`
4. Extract creative tab into `views/creative.php`
5. Verify all existing functionality works identically

### Phase 4: New views (estimated 3-4 hours)
1. Build `views/overview.php` -- KPI cards, sparklines, alerts
2. Build `views/performance.php` -- date range picker, charts, drilldowns
3. Build `views/changelog.php` -- timeline view with category filters
4. Build `views/strategies.php` -- strategy text with inline annotation UI
5. Build `views/settings.php` -- project CRUD, goals, budget

### Phase 5: Integration (estimated 1-2 hours)
1. Wire changelog logging into existing Optimise classes (SplitTest, BudgetAllocator, etc.)
2. Wire changelog logging into api.php mutation endpoints
3. Add changelog entry on sync completion
4. Test full flow: sync -> view performance -> annotate strategy -> review changelog

**Total estimate: 10-15 hours of implementation work.**

## 10. Trade-offs and Risks

### What we are giving up

1. **No real-time updates.** Polling at 2-second intervals during sync, no WebSockets. Acceptable for 1-2 users.

2. **No client-side charting library.** Sparklines are CSS/inline SVG. Full charts in the performance view will use `<canvas>` with a lightweight vanilla JS charting function (no Chart.js dependency). If this becomes painful, adding Chart.js via CDN (`<script src="https://cdn.jsdelivr.net/npm/chart.js">`) is a one-line change.

3. **SQLite on Hostinger.** Shared hosting may have SQLite issues with concurrent writes. Mitigated: the dashboard is read-heavy, writes are rare (annotations, manual changelog entries), and SQLite WAL mode handles read-read concurrency well.

4. **`shell_exec()` for sync.** Disabled on some shared hosts. The sync trigger gracefully degrades to "not available" and the last-synced indicator still works.

5. **Strategy annotations use text anchors, not precise positions.** If two sections have identical first-60-character headers, annotations could be ambiguous. Mitigated: strategy headers are generated by the strategy engine and are always unique within a strategy document.

### What becomes easier

1. **Adding new views** -- drop a PHP file in `views/`, add it to the `$allowedViews` array. Done.
2. **Deploying to Hostinger** -- FTP the `review/` directory. Same pattern as the main site.
3. **Understanding system history** -- the changelog captures every automated and manual decision with full context.
4. **Performance analysis** -- metrics are always computed the same way (via `Metrics::compute()`), no ad-hoc division-by-million scattered across views.
5. **Multi-project management** -- project selector in the header, all views are project-scoped.
