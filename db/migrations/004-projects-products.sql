-- Migration 004: Multi-product support for projects
-- Same domain can have multiple projects targeting different product lines

ALTER TABLE projects ADD COLUMN products TEXT;
-- JSON array: ["coloring books", "wall art", "digital downloads"]
-- NULL = single-product project (default)
