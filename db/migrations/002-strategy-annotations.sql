-- Migration 002: Strategy annotations
-- Section-level comments on strategy text without parsing markdown

CREATE TABLE IF NOT EXISTS strategy_annotations (
  id INTEGER PRIMARY KEY,
  strategy_id INTEGER NOT NULL REFERENCES strategies(id),
  section_anchor TEXT NOT NULL,   -- First 60 chars of nearest ## or ### header
  comment TEXT NOT NULL,
  status TEXT DEFAULT 'open',     -- open | resolved | wont_fix
  created_at TEXT DEFAULT (datetime('now')),
  resolved_at TEXT
);

CREATE INDEX IF NOT EXISTS idx_annotations_strategy ON strategy_annotations(strategy_id);
CREATE INDEX IF NOT EXISTS idx_annotations_status ON strategy_annotations(status);
