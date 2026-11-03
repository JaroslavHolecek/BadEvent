-- =============================================================
-- Full database setup: BadLogin + application schema + seed data
-- MUST be run via psql (not pgAdmin query tool, not PHP splitter)
-- because function bodies contain dollar-quoted ($$ ... $$) blocks
-- with semicolons inside, which naive ; splitters break.
--
-- Usage (run from the project root):
--   psql -U postgres -d event_manager -f database/setup.sql
-- =============================================================

\i vendor/BadLogin/src/db/bl_create.sql
\i database/create.sql
\i database/seed.sql
