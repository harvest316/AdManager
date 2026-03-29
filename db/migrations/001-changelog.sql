-- Migration 001: Changelog table
-- Captures all optimisation decisions, system events, and manual actions

CREATE TABLE IF NOT EXISTS changelog (
  id INTEGER PRIMARY KEY,
  project_id INTEGER NOT NULL REFERENCES projects(id),
  category TEXT NOT NULL,        -- split_test | budget | creative | keyword | campaign | strategy | system | manual
  action TEXT NOT NULL,          -- concluded | reallocated | fatigue_detected | added | removed | paused | enabled | approved | rejected | synced | note
  summary TEXT NOT NULL,         -- Human-readable sentence, self-explanatory months later
  detail_json TEXT,              -- Structured data for programmatic consumption (JSON)
  entity_type TEXT,              -- campaign | ad_group | ad | keyword | split_test | strategy | NULL
  entity_id INTEGER,             -- FK to the relevant entity (or NULL for project-wide events)
  actor TEXT DEFAULT 'system',   -- system | admin | optimiser | proofreader
  created_at TEXT DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_changelog_project ON changelog(project_id);
CREATE INDEX IF NOT EXISTS idx_changelog_category ON changelog(category);
CREATE INDEX IF NOT EXISTS idx_changelog_entity ON changelog(entity_type, entity_id);
CREATE INDEX IF NOT EXISTS idx_changelog_created ON changelog(created_at);
