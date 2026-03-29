-- AdManager schema
-- Multi-project ad platform: Google + Meta campaigns

-- Strategies must be defined before campaigns (campaigns reference strategies)
CREATE TABLE IF NOT EXISTS strategies (
  id INTEGER PRIMARY KEY,
  project_id INTEGER REFERENCES projects(id),
  name TEXT NOT NULL,
  platform TEXT,
  campaign_type TEXT,
  target_audience TEXT,
  value_proposition TEXT,
  tone TEXT,
  full_strategy TEXT,
  model TEXT,
  created_at TEXT DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS projects (
  id INTEGER PRIMARY KEY,
  name TEXT NOT NULL UNIQUE,
  display_name TEXT,
  website_url TEXT,
  description TEXT,
  created_at TEXT DEFAULT (datetime('now')),
  updated_at TEXT DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS budgets (
  id INTEGER PRIMARY KEY,
  project_id INTEGER NOT NULL REFERENCES projects(id),
  platform TEXT NOT NULL,
  daily_budget_aud REAL NOT NULL,
  created_at TEXT DEFAULT (datetime('now')),
  updated_at TEXT DEFAULT (datetime('now')),
  UNIQUE(project_id, platform)
);

CREATE TABLE IF NOT EXISTS campaigns (
  id INTEGER PRIMARY KEY,
  project_id INTEGER NOT NULL REFERENCES projects(id),
  platform TEXT NOT NULL,
  external_id TEXT,
  name TEXT NOT NULL,
  type TEXT NOT NULL,
  status TEXT DEFAULT 'draft',
  daily_budget_aud REAL,
  strategy_id INTEGER REFERENCES strategies(id),
  created_at TEXT DEFAULT (datetime('now')),
  updated_at TEXT DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS ad_groups (
  id INTEGER PRIMARY KEY,
  campaign_id INTEGER NOT NULL REFERENCES campaigns(id),
  external_id TEXT,
  name TEXT NOT NULL,
  status TEXT DEFAULT 'paused',
  cpc_micros INTEGER DEFAULT 0,
  created_at TEXT DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS keywords (
  id INTEGER PRIMARY KEY,
  ad_group_id INTEGER REFERENCES ad_groups(id),
  campaign_id INTEGER REFERENCES campaigns(id),
  keyword TEXT NOT NULL,
  match_type TEXT NOT NULL,
  is_negative INTEGER DEFAULT 0,
  max_cpc_micros INTEGER DEFAULT 0,
  external_id TEXT,
  created_at TEXT DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS assets (
  id INTEGER PRIMARY KEY,
  project_id INTEGER REFERENCES projects(id),
  type TEXT NOT NULL,
  platform TEXT NOT NULL DEFAULT 'local',
  local_path TEXT,
  external_id TEXT,
  url TEXT,
  content TEXT,
  width INTEGER,
  height INTEGER,
  duration_seconds REAL,
  generation_prompt TEXT,
  generation_model TEXT,
  generation_cost_usd REAL,
  status TEXT DEFAULT 'draft',
  feedback TEXT,
  rejected_reason TEXT,
  created_at TEXT DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS ads (
  id INTEGER PRIMARY KEY,
  ad_group_id INTEGER REFERENCES ad_groups(id),
  external_id TEXT,
  type TEXT NOT NULL,
  status TEXT DEFAULT 'draft',
  final_url TEXT,
  display_path TEXT,
  created_at TEXT DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS ad_assets (
  ad_id INTEGER NOT NULL REFERENCES ads(id),
  asset_id INTEGER NOT NULL REFERENCES assets(id),
  role TEXT NOT NULL,
  pin_position INTEGER,
  PRIMARY KEY (ad_id, asset_id, role)
);

CREATE TABLE IF NOT EXISTS performance (
  id INTEGER PRIMARY KEY,
  campaign_id INTEGER REFERENCES campaigns(id),
  ad_group_id INTEGER REFERENCES ad_groups(id),
  ad_id INTEGER REFERENCES ads(id),
  date TEXT NOT NULL,
  impressions INTEGER DEFAULT 0,
  clicks INTEGER DEFAULT 0,
  cost_micros INTEGER DEFAULT 0,
  conversions REAL DEFAULT 0,
  conversion_value REAL DEFAULT 0,
  created_at TEXT DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS split_tests (
  id INTEGER PRIMARY KEY,
  project_id INTEGER REFERENCES projects(id),
  campaign_id INTEGER REFERENCES campaigns(id),
  ad_group_id INTEGER REFERENCES ad_groups(id),
  name TEXT NOT NULL,
  status TEXT DEFAULT 'running',
  winner_ad_id INTEGER REFERENCES ads(id),
  metric TEXT DEFAULT 'ctr',
  min_impressions INTEGER DEFAULT 1000,
  confidence_level REAL DEFAULT 0.95,
  started_at TEXT,
  concluded_at TEXT,
  created_at TEXT DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS goals (
  id INTEGER PRIMARY KEY,
  project_id INTEGER REFERENCES projects(id),
  platform TEXT,
  metric TEXT NOT NULL,
  target_value REAL NOT NULL,
  current_value REAL,
  last_checked TEXT,
  created_at TEXT DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS ad_copy (
  id INTEGER PRIMARY KEY,
  project_id INTEGER NOT NULL REFERENCES projects(id),
  strategy_id INTEGER REFERENCES strategies(id),
  platform TEXT NOT NULL,            -- 'google' | 'meta'
  campaign_name TEXT,
  ad_group_name TEXT,
  copy_type TEXT NOT NULL,           -- 'headline' | 'description' | 'primary_text' | 'sitelink' | 'callout' | 'structured_snippet'
  content TEXT NOT NULL,
  char_limit INTEGER,
  pin_position INTEGER,
  language TEXT DEFAULT 'en',
  target_market TEXT DEFAULT 'all',
  status TEXT DEFAULT 'draft',       -- draft | proofread | approved | rejected | feedback | flagged
  qa_status TEXT,                    -- pass | fail | warning
  qa_issues TEXT,                    -- JSON array
  qa_score INTEGER,                  -- 0-100
  feedback TEXT,
  rejected_reason TEXT,
  created_at TEXT DEFAULT (datetime('now')),
  updated_at TEXT DEFAULT (datetime('now'))
);

-- Indexes on foreign keys and common lookups

CREATE INDEX IF NOT EXISTS idx_budgets_project ON budgets(project_id);
CREATE INDEX IF NOT EXISTS idx_campaigns_project ON campaigns(project_id);
CREATE INDEX IF NOT EXISTS idx_campaigns_platform ON campaigns(platform);
CREATE INDEX IF NOT EXISTS idx_campaigns_status ON campaigns(status);
CREATE INDEX IF NOT EXISTS idx_campaigns_strategy ON campaigns(strategy_id);
CREATE INDEX IF NOT EXISTS idx_ad_groups_campaign ON ad_groups(campaign_id);
CREATE INDEX IF NOT EXISTS idx_keywords_ad_group ON keywords(ad_group_id);
CREATE INDEX IF NOT EXISTS idx_keywords_campaign ON keywords(campaign_id);
CREATE INDEX IF NOT EXISTS idx_keywords_match_type ON keywords(match_type);
CREATE INDEX IF NOT EXISTS idx_assets_project ON assets(project_id);
CREATE INDEX IF NOT EXISTS idx_assets_type ON assets(type);
CREATE INDEX IF NOT EXISTS idx_assets_status ON assets(status);
CREATE INDEX IF NOT EXISTS idx_ads_ad_group ON ads(ad_group_id);
CREATE INDEX IF NOT EXISTS idx_ads_status ON ads(status);
CREATE INDEX IF NOT EXISTS idx_ad_assets_asset ON ad_assets(asset_id);
CREATE INDEX IF NOT EXISTS idx_strategies_project ON strategies(project_id);
CREATE INDEX IF NOT EXISTS idx_performance_campaign ON performance(campaign_id);
CREATE INDEX IF NOT EXISTS idx_performance_ad_group ON performance(ad_group_id);
CREATE INDEX IF NOT EXISTS idx_performance_ad ON performance(ad_id);
CREATE INDEX IF NOT EXISTS idx_performance_date ON performance(date);
CREATE INDEX IF NOT EXISTS idx_split_tests_project ON split_tests(project_id);
CREATE INDEX IF NOT EXISTS idx_split_tests_campaign ON split_tests(campaign_id);
CREATE INDEX IF NOT EXISTS idx_split_tests_status ON split_tests(status);
CREATE INDEX IF NOT EXISTS idx_goals_project ON goals(project_id);
CREATE INDEX IF NOT EXISTS idx_goals_metric ON goals(metric);
CREATE INDEX IF NOT EXISTS idx_ad_copy_project ON ad_copy(project_id);
CREATE INDEX IF NOT EXISTS idx_ad_copy_strategy ON ad_copy(strategy_id);
CREATE INDEX IF NOT EXISTS idx_ad_copy_status ON ad_copy(status);
CREATE INDEX IF NOT EXISTS idx_ad_copy_platform ON ad_copy(platform);
CREATE INDEX IF NOT EXISTS idx_ad_copy_type ON ad_copy(copy_type);
