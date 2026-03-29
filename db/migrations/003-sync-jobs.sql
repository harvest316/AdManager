-- Migration 003: Sync jobs
-- Tracks async sync executions triggered from the web UI

CREATE TABLE IF NOT EXISTS sync_jobs (
  id INTEGER PRIMARY KEY,
  project_id INTEGER NOT NULL REFERENCES projects(id),
  platform TEXT NOT NULL,          -- google | meta | all
  days INTEGER DEFAULT 7,
  status TEXT DEFAULT 'pending',   -- pending | running | complete | failed
  output TEXT,                     -- Captured stdout/stderr from sync-performance.php
  started_at TEXT,
  completed_at TEXT,
  created_at TEXT DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_sync_jobs_project ON sync_jobs(project_id);
CREATE INDEX IF NOT EXISTS idx_sync_jobs_status ON sync_jobs(status);
