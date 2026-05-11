-- Migration 0005 — performance indexes for retention / unread / lookups.
--
-- These indexes target two classes of queries that grow with traffic:
--   1. unread-count: `WHERE read_at IS NULL` on `submissions`. A partial
--      index keeps the index size proportional to the unread bucket
--      (typically a tiny fraction of total submissions).
--   2. retention sweep: `WHERE attempted_at < datetime('now', '-N days')`
--      on `login_attempts`, run by `scripts/cleanup.php` on a cron.
--
-- All `IF NOT EXISTS` so re-running the migration is a no-op even on
-- DBs that already created these indexes via the platform overlay
-- (which had its own perf migration earlier).

-- Unread submissions: partial index drastically reduces size, almost
-- every submission ends up `read` over time.
CREATE INDEX IF NOT EXISTS idx_submissions_unread
    ON submissions(read_at)
    WHERE read_at IS NULL;

-- Rate-limit retention sweep + brute-force window check.
CREATE INDEX IF NOT EXISTS idx_login_attempts_attempted_at
    ON login_attempts(attempted_at);

-- Audit-log retention sweep.
CREATE INDEX IF NOT EXISTS idx_audit_log_created_at
    ON audit_log(created_at);

-- Visits retention sweep (`PageController::trackVisit` inserts on every
-- public page view, so this table grows fastest).
CREATE INDEX IF NOT EXISTS idx_visits_created_at
    ON visits(created_at);
