# AdManager Dashboard — UX Architecture

**Date:** 2026-03-29
**Decision:** DR-109
**Status:** Proposed

---

## 1. Integration Strategy: Restructure, Not Replace

The current `review/index.php` is a 427-line monolith that serves creative review, ad copy review, and campaign management as three tabs in a single page. The dashboard restructures this into a multi-page PHP application under the same `review/` directory, using the same built-in PHP dev server (`php -S`). No framework, no build step, no Node.

**Why restructure instead of extend:**
- The existing page has no place for performance data, change log, strategy viewer, or sync controls. Adding 4+ tabs to a single page creates a horizontal scroll problem and forces every page load to query all datasets.
- The existing AJAX patterns, CSS variables, toast system, modal system, and inline editing patterns all survive unchanged. They move into shared includes.
- The review functionality (creative/copy/campaigns tabs) becomes one page within a larger navigation structure, keeping its internal tab behaviour.

**File structure after restructure:**

```
review/
  index.php              # Dashboard home (performance overview)
  review.php             # Former index.php content (creative/copy/campaigns tabs)
  changelog.php          # Optimization change log
  strategy.php           # Strategy viewer
  projects.php           # Project management (add/edit)
  api.php                # Existing API (extended with new endpoints)
  assets.php             # Existing asset proxy (unchanged)
  includes/
    header.php           # Shared HTML head, CSS variables, nav
    footer.php           # Shared JS (toast, modal, fetch helpers), closing tags
    nav.php              # Left sidebar navigation
    helpers.php          # PHP helpers (truncate, timeAgo, e, formatMoney)
    db-queries.php       # Shared query functions (getProjectKPIs, etc.)
```

**Migration path:** Extract the CSS (lines 111-198 of current index.php) and JS (lines 384-424) into the includes. The PHP data-loading and HTML rendering for creative/copy/campaigns move verbatim into `review.php`. This is a cut-paste refactor, not a rewrite.


## 2. Navigation Structure

### Primary Navigation: Left Sidebar (Persistent)

Sidebar, not top tabs. Reason: the review page already uses horizontal tabs internally (Creative / Ad Copy / Campaigns). Nesting tabs inside tabs creates confusion. A left sidebar also scales better as pages are added, and leaves full width for data-dense performance tables.

```
+------------------+------------------------------------------+
| [A] AdManager    |                                          |
|                  |  (page content area)                     |
| [project picker] |                                          |
|                  |                                          |
| Dashboard        |                                          |
| Review           |                                          |
|   Creative       |                                          |
|   Ad Copy        |                                          |
|   Campaigns      |                                          |
| Change Log       |                                          |
| Strategy         |                                          |
| Projects         |                                          |
|                  |                                          |
| ──────────────── |                                          |
| Sync: 2h ago     |                                          |
| [Sync Now]       |                                          |
+------------------+------------------------------------------+
```

**Sidebar width:** 220px fixed on desktop. Collapses to icon-only (56px) on screens below 1024px. Hidden entirely below 768px with a hamburger toggle.

**Project picker:** Dropdown at the top of the sidebar, same as current header dropdown. Changing project reloads the current page with the new project_id. The selected project persists in a URL parameter (existing pattern).

**Active state:** Current page highlighted with left border accent (var(--blue)), same visual language as the existing `.tab.active` bottom border.

**Sync status:** Bottom of sidebar. Shows "Last sync: Xm ago" with a "Sync Now" button that POST calls `api.php?action=trigger_sync`. The sync status comes from the most recent `performance.created_at` for the active project. On click, the button shows a spinner and the text changes to "Syncing...". On completion, the timestamp updates. This does not block — the sync runs in background via `proc_open` to `bin/sync-performance.php`.

### Review Sub-Navigation

The Review page retains its existing tab system (Creative / Ad Copy / Campaigns). The sidebar shows "Review" as a parent, and the three sub-items as indented children. Clicking any of the three goes to `review.php?project=X&tab=creative|copy|campaigns`. Clicking "Review" itself goes to `review.php?project=X` (defaults to creative tab, matching current behavior).


## 3. Page Layouts

### 3a. Dashboard (index.php) — Performance Overview

This is the default landing page. It answers: "Are my ads working? What should I change?"

**Layout:** Full-width content area. No sidebar within the page — just the global sidebar.

```
+----------------------------------------------------------+
| Project: Example Brand                    Last 7d | 14d | 30d |
+----------------------------------------------------------+
|                                                          |
| [ $47.20 ]  [ 12 ]     [ $3.93 ]  [ 3.2x ]  [ 2.1% ]  |
|   Spend       Conv       CPA        ROAS       CTR      |
|  +12% vs      +3 vs     -8% vs     +0.4x vs   -0.1%    |
|  prior        prior     prior      prior       vs prior  |
|                                                          |
+----------------------------------------------------------+
| Campaign Performance                              [all ▾]|
+----------------------------------------------------------+
| Campaign            | Spend  | Conv | CPA    | ROAS | CTR |
| ─────────────────── | ────── | ──── | ────── | ──── | ─── |
| AU Search - CRO     | $18.40 | 5    | $3.68  | 3.8x | 3.1%|
|   > Ad Group: Audit |        |      |        |      |  [>]|
|   > Ad Group: Fix   |        |      |        |      |  [>]|
| AU PMax - Retarget  | $12.80 | 4    | $3.20  | 4.1x | 1.8%|
| META - Awareness    | $16.00 | 3    | $5.33  | 2.2x | 1.4%|
+----------------------------------------------------------+
| Active Alerts                                            |
+----------------------------------------------------------+
| ! Creative fatigue: "AU Search - CRO > Headlines"        |
|   CTR declining -0.15%/day over 12 days (moderate)       |
|                                                          |
| * Budget recommendation: Shift $2.10/day from META       |
|   Awareness to AU PMax (ROAS 4.1x vs 2.2x)              |
|                                                          |
| ! Split test concluded: Headline B wins (95.2% conf.)    |
|   "Free Website Audit" vs "Get Your CRO Report" — B +18%|
+----------------------------------------------------------+
```

**KPI Row Detail:**

Five KPI cards in a horizontal flex row. Each card shows:
- Large number (the metric value, formatted as dollars/count/percentage)
- Label underneath
- Delta vs prior period (same number of days), colour-coded: green for improvement, red for degradation, grey if no change or no prior data

The period selector (7d / 14d / 30d) is three buttons, top right, styled like the existing filter bar buttons (`.fb` class). Default is 7d. Changing period reloads the page with `?days=X`.

Dollar amounts: `cost_micros / 1_000_000`, formatted as `$XX.XX`. The conversion from micros happens in the PHP query layer, never exposed to the UI.

**Campaign Performance Table:**

Uses the existing `table.ct` class. Columns: Campaign, Spend, Conversions, CPA, ROAS, CTR. Sorted by spend descending by default. Each row is expandable (existing pattern: `.expand-btn` + `.detail-row`) to show ad groups within that campaign.

The ad group expansion shows a nested sub-table with the same columns. Each ad group row can further expand to show individual ads. This three-level drill-down matches the data model: campaign -> ad_group -> ad. But only the first level (campaigns) loads on page render. Ad group and ad data load via AJAX on expansion click, to avoid loading the full performance tree upfront.

Platform badge (Google/Meta) uses existing `.pb-google` / `.pb-meta` classes.

**Active Alerts Section:**

A vertical stack of alert cards. Three types, drawn from existing optimization modules:

1. **Creative Fatigue** (from `CreativeFatigue::detect`): Shows ad name, CTR slope, days declining, severity
2. **Budget Recommendations** (from `BudgetAllocator::recommend`): Shows source/destination campaigns, reason, amounts
3. **Split Test Outcomes** (from `SplitTest::evaluate`): Shows test name, variants, winner, confidence

Each alert has an icon prefix: `!` for action-needed (red/orange border-left), `*` for informational (blue border-left). This matches the existing `.fb-text` / `.rj-text` pattern of coloured left borders.

Alerts are computed at page load time. If the optimization modules are slow (they query the `performance` table), cache results in a `dashboard_cache` table with a 1-hour TTL.

**What is NOT on this page:**
- Individual keyword performance (too granular for overview)
- Raw performance rows or date-by-date breakdowns (the KPI cards aggregate)
- Creative assets or ad copy (that is the Review page's job)
- Strategy documents (separate page)
- Generation prompts, model names, or cost_micros internals


### 3b. Review Page (review.php) — Existing Functionality

The current `index.php` content, unchanged in behavior, wrapped in the new layout (sidebar + header include). The three internal tabs (Creative / Ad Copy / Campaigns) remain as-is.

One addition: the campaign tab gains a "Performance" column showing 7-day spend and conversion count inline, so you can see campaign health without switching to the dashboard. This is a single aggregated query, not the full drill-down.


### 3c. Change Log (changelog.php)

Answers: "What changed recently?" A chronological, reverse-time list of all optimization decisions and actions.

```
+----------------------------------------------------------+
| Change Log                              [filter: all ▾]  |
+----------------------------------------------------------+
| Mar 29, 11:42 AEDT                                       |
| SPLIT TEST CONCLUDED                                     |
| "Headline A vs B" in AU Search - CRO > Audit Headlines   |
| Winner: Variant B ("Free Website Audit") — 95.2% conf    |
| CTR: 3.8% vs 2.9%  |  Impressions: 2,140 vs 2,087       |
+----------------------------------------------------------+
| Mar 28, 09:15 AEDT                                       |
| BUDGET MOVED                                             |
| Shifted $1.50/day from META Awareness to AU PMax         |
| Reason: ROAS 4.1x vs 2.2x over 14 days                  |
+----------------------------------------------------------+
| Mar 27, 16:30 AEDT                                       |
| CREATIVE FATIGUE FLAGGED                                  |
| Ad #42 in AU Search - CRO showing CTR decline            |
| Slope: -0.15%/day over 12 days (moderate severity)       |
+----------------------------------------------------------+
| Mar 27, 14:00 AEDT                                       |
| KEYWORD ADDED                                            |
| +[cro audit free] (phrase match) to AU Search - CRO      |
| Source: KeywordMiner recommendation                       |
+----------------------------------------------------------+
| Mar 26, 10:20 AEDT                                       |
| MANUAL: Campaign enabled                                  |
| Enabled "AU PMax - Retarget" (was paused)                |
| By: Jason (review dashboard)                              |
+----------------------------------------------------------+
```

**Data source:** This requires a new `change_log` table:

```sql
CREATE TABLE IF NOT EXISTS change_log (
  id INTEGER PRIMARY KEY,
  project_id INTEGER NOT NULL REFERENCES projects(id),
  category TEXT NOT NULL,    -- 'split_test' | 'budget' | 'fatigue' | 'keyword' | 'manual' | 'copy' | 'creative' | 'sync'
  title TEXT NOT NULL,
  detail TEXT,               -- JSON blob with structured data
  source TEXT,               -- 'optimiser' | 'review_dashboard' | 'cli' | 'auto'
  created_at TEXT DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_change_log_project ON change_log(project_id);
CREATE INDEX IF NOT EXISTS idx_change_log_category ON change_log(category);
CREATE INDEX IF NOT EXISTS idx_change_log_created ON change_log(created_at);
```

Log entries are written by: the `bin/optimise.php` script (split tests, budget, fatigue, keywords), the review dashboard API (campaign enables, approvals), and sync operations. Each entry has a `category` for filtering and a `detail` JSON field for structured data (variant IDs, dollar amounts, etc.) that the template renders appropriately per category.

**Filter bar:** Uses the existing `.fbar` / `.fb` pattern. Categories: All, Split Tests, Budget, Fatigue, Keywords, Manual, Sync.

**Pagination:** 50 entries per page with "Load more" button (AJAX append, not full page reload). For 3-5 projects at $200-400/month, the change volume is low — maybe 5-15 entries per day across all projects. Pagination is forward-looking, not an immediate need.


### 3d. Strategy Viewer (strategy.php)

Displays generated strategy documents with the ability to leave notes.

```
+----------------------------------------------------------+
| Strategy                                                 |
+----------------------------------------------------------+
| Latest Strategies                                        |
+----------------------------------------------------------+
| [Google Search]  [Google PMax]  [Meta Conversions]       |
|  Mar 25           Mar 25         Mar 23                  |
+----------------------------------------------------------+
|                                                          |
| Google Search Strategy — AU CRO Audits                   |
| Generated: 2026-03-25 by Claude                          |
|                                                          |
| ## Campaign Structure                                    |
| ...rendered markdown...                                  |
|                                                          |
| [+ Add note on this section]                             |
|                                                          |
| ## Keywords & Targeting                                  |
| ...rendered markdown...                                  |
|                                                          |
| ## Ad Copy Direction                                     |
| ...rendered markdown...                                  |
|                                                          |
| ## Budget Allocation                                     |
| ...rendered markdown...                                  |
|                                                          |
+----------------------------------------------------------+
| All Strategies (5)                                       |
+----------------------------------------------------------+
| | Name              | Platform | Type    | Date         |
| | Google Search AU  | google   | search  | 2026-03-25   |
| | Google PMax AU    | google   | pmax    | 2026-03-25   |
| | Meta Conversions  | meta     | conv.   | 2026-03-23   |
| | Google Search AU  | google   | search  | 2026-03-15   |
| | Meta Traffic      | meta     | traffic | 2026-03-10   |
+----------------------------------------------------------+
```

**Strategy rendering:** The `full_strategy` field contains markdown. Render it server-side with a simple PHP markdown parser (use `Parsedown` — single file, no dependencies beyond what composer already provides, or just render `<pre>` blocks with basic formatting). No need for full GFM.

**Notes/feedback:** A new `strategy_notes` table:

```sql
CREATE TABLE IF NOT EXISTS strategy_notes (
  id INTEGER PRIMARY KEY,
  strategy_id INTEGER NOT NULL REFERENCES strategies(id),
  section_anchor TEXT,       -- which section heading the note is attached to
  note TEXT NOT NULL,
  created_at TEXT DEFAULT (datetime('now'))
);
```

Each markdown `## Heading` gets an anchor. A small "Add note" link appears below each section. Clicking it opens the existing modal pattern (reuse `.mo` / `.mdl` classes) with a textarea. Notes appear below their section with the `.fb-text` yellow left-border style, timestamped.

**Strategy list:** The bottom table shows all strategies for the project, newest first. Clicking a row loads that strategy into the viewer above. The latest per platform+type is shown by default when the page loads.


### 3e. Projects Page (projects.php)

Answers: "What am I managing?"

```
+----------------------------------------------------------+
| Projects                                    [+ New Project]|
+----------------------------------------------------------+
| Example Brand                                              |
|   example.com                                            |
|   Google: $6.70/day | Meta: $5.00/day | Total: $11.70/day|
|   Campaigns: 3 active, 1 paused                         |
|   Goals: CPA < $30 (Google)                              |
|   [Edit] [View Dashboard]                                |
+----------------------------------------------------------+
| 2Step                                                    |
|   2step.com.au                                           |
|   Google: $4.00/day | Meta: $3.00/day | Total: $7.00/day |
|   Campaigns: 2 active                                    |
|   Goals: CPA < $25 (Google)                              |
|   [Edit] [View Dashboard]                                |
+----------------------------------------------------------+
```

**Add new project:** "New Project" button opens a modal (reuse existing modal pattern) with fields: Name (slug), Display Name, Website URL, Description. Submits to `api.php?action=create_project`. After creation, redirects to the new project's dashboard.

**Edit:** Inline editing for display name, URL, description (same click-to-edit pattern as existing budget editing). Budget editing stays on the review page's campaigns tab (where it already works well).

**Multi-landing-page note:** The description field or a simple text note can explain "This project covers the CRO audit landing page" vs "This project covers the video review landing page" for domains with multiple projects. No special UI needed — it is just metadata.


## 4. Drill-Down Mechanics

The performance drill-down follows a consistent pattern across all pages:

```
Project (KPI cards + campaign table)
  > Campaign (click row to expand)
    > Ad Group (nested sub-table, loaded via AJAX)
      > Individual Ads (nested sub-table, loaded via AJAX)
        > (No further drill-down — ad is the leaf)
```

**Implementation:**

1. **Campaign rows** are rendered server-side on initial page load. Each row has the existing `.expand-btn` that toggles a `.detail-row` containing a placeholder `<div id="ag-{campaign_id}">`.

2. **First expansion** triggers `fetch('api.php?action=get_ad_groups&campaign_id=X&days=Y')` which returns HTML (not JSON) for the ad group sub-table. The response is injected into the placeholder div. A `data-loaded="1"` attribute prevents re-fetching on subsequent toggles.

3. **Ad group rows** have the same expand pattern. Expanding an ad group fetches `api.php?action=get_ads&ad_group_id=X&days=Y` and injects the ad-level sub-table.

4. **Keyword performance** is available only within the ad group drill-down — a small "Keywords" link at the ad group level loads keyword metrics for that ad group. This is intentionally deep; most decisions happen at campaign and ad group level.

**Why HTML responses instead of JSON:**
- Matches the existing pattern where `api.php` returns JSON for actions but the main page renders HTML
- Avoids building a client-side template system for something that is only used in one place
- PHP can format money, percentages, and dates consistently server-side
- For 1-2 users, server-side rendering is simpler and the performance difference is irrelevant

**Each drill-down level shows the same five metrics:** Spend, Conversions, CPA, ROAS, CTR. These are aggregated from the `performance` table at each level. The aggregation queries use the same `SUM(cost_micros)`, `SUM(conversions)` pattern already established in `Analyser.php` and `BudgetAllocator.php`.


## 5. Component Patterns to Reuse

The existing review page has a well-established set of CSS/JS patterns. The dashboard reuses all of them:

| Pattern | Existing Class/ID | Reuse Location |
|---------|-------------------|----------------|
| Card layout | `.card`, `.body`, `.meta` | KPI cards, alert cards |
| Data table | `table.ct`, `th`, `td` | Campaign table, changelog, strategy list |
| Expandable rows | `.expand-btn`, `.detail-row` | Performance drill-down |
| Filter bar | `.fbar`, `.fb`, `.fc` | Period selector, changelog filter, status filters |
| Status badges | `.pb-google`, `.pb-meta` | Platform badges everywhere |
| Toast notifications | `.tc`, `.toast`, `.ts`, `.te` | All AJAX actions |
| Modal dialogs | `.mo`, `.mdl`, `.ma`, `.mc` | Notes, feedback, new project |
| Inline editing | `.editable-budget`, `.budget-input` | Budget editing (unchanged) |
| Action buttons | `.btn`, `.btn-a`, `.btn-r`, `.btn-f` | Review actions (unchanged) |
| Empty states | `.empty`, `.ic` | No data states for each page |
| Alert left-border | `.fb-text`, `.rj-text`, `.qa-text` | Changelog entries, dashboard alerts |
| Section grouping | `.sec`, `.sec-t`, `.bg` | All pages |

**New patterns needed:**

1. **KPI Card** — large number + label + delta. New CSS class `.kpi-card` extending `.bcard` (the existing budget card pattern) with larger font size and conditional delta colouring.

2. **Sidebar Navigation** — new CSS for `.sidebar`, `.sidebar-item`, `.sidebar-item.active`. Uses the same colour variables (`--bg2`, `--border`, `--blue` for active).

3. **Timeline Entry** — for the changelog. A vertical left-border with category-coloured dot, timestamp, title, and detail text. Essentially a vertical version of the existing `.fb-text` pattern.

4. **Markdown Rendered Section** — for strategy viewer. Minimal styling for rendered markdown inside a `.strategy-content` container. Headings, paragraphs, lists, code blocks using the existing typography scale.


## 6. Responsive Behavior

**Desktop-first.** The primary use case is a developer/marketer at a desk checking ad performance. But tablet-usable for quick checks.

| Breakpoint | Sidebar | Campaign Table | KPI Cards | Grid |
|------------|---------|----------------|-----------|------|
| > 1280px | 220px fixed | Full columns | 5 across | Existing 3-col |
| 1024-1280 | 220px fixed | Full columns | 5 across (smaller) | 2-col |
| 768-1024 | 56px icon-only | Scroll horizontal | 3 across + scroll | 2-col |
| < 768px | Hidden + hamburger | Scroll horizontal | 2 across + scroll | 1-col |

**Table horizontal scrolling:** On tablet/mobile, the campaign performance table gets `overflow-x: auto` on a wrapper div. The Campaign name column is `position: sticky; left: 0` so it stays visible while scrolling metrics. This is a CSS-only solution.

**KPI cards:** Use `display: flex; flex-wrap: wrap; gap: var(--space-4)`. Each card has `min-width: 140px; flex: 1`. At narrow widths, they wrap to two rows.

**Sidebar collapse:** At 768-1024px, the sidebar collapses to icon-only mode. Each nav item shows a tooltip on hover. The project picker becomes a small icon that opens a dropdown. Below 768px, the sidebar is a slide-out overlay triggered by a hamburger button in a sticky top bar.

The existing review page is already responsive (`.grid` has media queries at 1100px and 700px). That behavior is preserved inside `review.php`.


## 7. Data Abstraction Layer

The dashboard never exposes raw database internals to the UI.

**Abstracted in PHP query functions (`includes/db-queries.php`):**

```php
function getProjectKPIs(int $projectId, int $days): array
// Returns: ['spend' => float, 'conversions' => float, 'cpa' => float,
//           'roas' => float, 'ctr' => float, 'impressions' => int,
//           'clicks' => int, 'prior_spend' => float, ...]
// Internally: SUM(cost_micros)/1000000, comparison to prior period

function getCampaignPerformance(int $projectId, int $days): array
// Returns: [['name' => str, 'platform' => str, 'spend' => float, ...], ...]
// Internally: JOINs campaigns + performance, groups by campaign

function getAdGroupPerformance(int $campaignId, int $days): array
// Returns: same shape, at ad group level

function getAdPerformance(int $adGroupId, int $days): array
// Returns: same shape, at ad level

function getDashboardAlerts(int $projectId): array
// Returns: [['type' => 'fatigue'|'budget'|'split_test', 'severity' => str, 'title' => str, 'detail' => str], ...]
// Internally: calls CreativeFatigue::detect, BudgetAllocator::recommend, SplitTest::evaluate

function getChangeLog(int $projectId, ?string $category, int $limit, int $offset): array
// Returns: change_log rows with formatted timestamps

function getSyncStatus(int $projectId): array
// Returns: ['last_synced' => string, 'seconds_ago' => int]
// Internally: MAX(performance.created_at) for project
```

**What gets abstracted away:**
- `cost_micros` is always presented as dollars (divide by 1,000,000)
- `external_id` never appears in any UI (only in campaign detail expansion, behind a "show details" click, for debugging)
- `generation_prompt`, `generation_model`, `generation_cost_usd` stay on the review page only (where they are relevant for creative decisions)
- `pin_position` stays on the ad copy review cards only
- `ad_assets` join table is never directly surfaced
- Performance rows are always aggregated into period totals, never shown as individual daily rows
- Strategy `model` field shown as a small badge ("Claude"), not the raw model string


## 8. New API Endpoints

Extensions to the existing `review/api.php`:

| Action | Method | Parameters | Returns |
|--------|--------|------------|---------|
| `get_ad_groups` | GET | campaign_id, days | HTML sub-table |
| `get_ads` | GET | ad_group_id, days | HTML sub-table |
| `get_keywords` | GET | ad_group_id, days | HTML sub-table |
| `trigger_sync` | POST | project_id | JSON {ok, message} |
| `create_project` | POST | name, display_name, website_url, description | JSON {ok, project_id} |
| `update_project` | POST | project_id, field, value | JSON {ok} |
| `add_strategy_note` | POST | strategy_id, section_anchor, note | JSON {ok, note_id} |

The existing action handlers (approve, reject, feedback, budget updates, etc.) remain unchanged.


## 9. Schema Changes Required

```sql
-- Change log for optimization decisions
CREATE TABLE IF NOT EXISTS change_log (
  id INTEGER PRIMARY KEY,
  project_id INTEGER NOT NULL REFERENCES projects(id),
  category TEXT NOT NULL,
  title TEXT NOT NULL,
  detail TEXT,
  source TEXT,
  created_at TEXT DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_change_log_project ON change_log(project_id);
CREATE INDEX IF NOT EXISTS idx_change_log_category ON change_log(category);
CREATE INDEX IF NOT EXISTS idx_change_log_created ON change_log(created_at);

-- Strategy section notes
CREATE TABLE IF NOT EXISTS strategy_notes (
  id INTEGER PRIMARY KEY,
  strategy_id INTEGER NOT NULL REFERENCES strategies(id),
  section_anchor TEXT,
  note TEXT NOT NULL,
  created_at TEXT DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_strategy_notes_strategy ON strategy_notes(strategy_id);

-- Optional: dashboard alert cache (avoids re-running optimization queries on every page load)
CREATE TABLE IF NOT EXISTS dashboard_cache (
  id INTEGER PRIMARY KEY,
  project_id INTEGER NOT NULL,
  cache_key TEXT NOT NULL,
  data TEXT NOT NULL,
  expires_at TEXT NOT NULL,
  UNIQUE(project_id, cache_key)
);
```


## 10. Implementation Priority

Ordered by user value and dependency chain:

1. **Shared layout extraction** (includes/header.php, footer.php, nav.php, helpers.php) — prerequisite for everything. Move CSS and JS from current index.php into includes. Current index.php becomes review.php wrapped in includes.

2. **Dashboard page** (index.php) — the primary new value. KPI cards + campaign performance table + alerts. Depends on `db-queries.php` abstraction layer.

3. **Change log** (changelog.php) — requires schema migration for `change_log` table, plus instrumenting `bin/optimise.php` and `api.php` to write log entries.

4. **Strategy viewer** (strategy.php) — straightforward read-only view of existing `strategies` table, plus notes feature.

5. **Projects page** (projects.php) — lowest priority, since project creation currently works via CLI and there are only 3-5 projects.

Steps 1 and 2 together provide the "Are my ads working?" answer. Step 3 provides the "What changed?" answer. Steps 4 and 5 are refinements.


## 11. What This Architecture Does NOT Include

- **No dark/light theme toggle.** The existing dark theme (GitHub-inspired) is the only theme. This is an internal tool for 1-2 users. Adding theme switching adds complexity with zero ROI.

- **No charts or graphs.** Time-series visualizations would be nice but are not needed for initial delivery. The KPI cards with delta-vs-prior provide trend information. Charts can be added later using a lightweight library (Chart.js or similar) without architectural changes.

- **No real-time data.** Performance data syncs on-demand or on schedule. The dashboard shows the last-synced state. No WebSocket, no polling, no live updates.

- **No authentication.** This runs on localhost:8080. If it ever needs to be exposed (unlikely for 1-2 users), add basic auth to the PHP server or reverse-proxy with nginx.

- **No SPA behavior.** Full page navigations for page changes. AJAX only for drill-down expansion, action buttons, and the sync trigger. This keeps the architecture simple and debuggable.
