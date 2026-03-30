-- Migration 005: Conversion actions tracking
-- Tracks what conversion events are configured per project across Google Ads, Meta, and GA4

CREATE TABLE IF NOT EXISTS conversion_actions (
  id INTEGER PRIMARY KEY,
  project_id INTEGER NOT NULL REFERENCES projects(id),
  name TEXT NOT NULL,                -- Human name: "Purchase", "Lead Form Submit"
  event_name TEXT NOT NULL,          -- Technical event: "purchase", "generate_lead", "sign_up"
  platform TEXT NOT NULL,            -- google | meta | ga4
  category TEXT NOT NULL,            -- PURCHASE | LEAD | SIGNUP | PAGE_VIEW | ADD_TO_CART | BEGIN_CHECKOUT | CONTACT
  is_primary INTEGER DEFAULT 1,     -- 1 = counts in Conversions column; 0 = secondary/micro
  trigger_type TEXT,                 -- url_match | click | dataLayer | custom_event
  trigger_value TEXT,                -- URL pattern, selector, or event name
  default_value REAL DEFAULT 0,     -- Default conversion value in AUD
  external_id TEXT,                  -- Platform resource name/ID once created
  status TEXT DEFAULT 'planned',    -- planned | created | verified | failed
  verification_note TEXT,           -- Last verification result
  verified_at TEXT,
  created_at TEXT DEFAULT (datetime('now')),
  updated_at TEXT DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_conv_actions_project ON conversion_actions(project_id);
CREATE INDEX IF NOT EXISTS idx_conv_actions_platform ON conversion_actions(platform);
CREATE INDEX IF NOT EXISTS idx_conv_actions_status ON conversion_actions(status);
